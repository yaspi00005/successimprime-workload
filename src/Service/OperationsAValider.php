<?php

namespace App\Service;

use App\Entity\CompteTresorerie;
use App\Entity\Depense;
use App\Entity\DepotSoldeClient;
use App\Entity\Factures;
use App\Entity\MouvementTresorerie;
use App\Entity\Paiements;
use App\Entity\User;
use App\Service\Paiement\ComptesOrangeMoney;
use App\Service\Paiement\SoldeClient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Finances › À valider (maquette 29) : les paiements et mouvements de
 * trésorerie encore provisoires, avec des contrôles automatiques pour
 * aider le responsable, puis la validation ou le rejet par lot.
 *
 * Une opération provisoire ne compte ni dans la caisse ni dans les
 * soldes tant qu'elle n'est pas validée ici.
 */
final class OperationsAValider
{
    public const OK = 'ok';
    public const ATTENTION = 'attention';
    public const ERREUR = 'erreur';

    /** Au-delà, l'opération est signalée comme en retard. */
    public const DELAI_HEURES = 24;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaiementService $paiements,
        private readonly MouvementTresorerieService $mouvements,
        private readonly NotificationService $notifications,
        private readonly ?ComptesOrangeMoney $comptesOrangeMoney = null,
        private readonly ?SoldeClient $soldeClient = null,
    ) {
    }

    /**
     * « frais » : fonds de soutien et frais de retrait payés en plus du
     * montant (le compte Orange Money a reçu montant + frais).
     * « compte_impose » : payé en ligne avec l'API Orange Money, le compte
     * « Orange Money en ligne » est imposé (la liste ne le propose que lui).
     *
     * @return list<array{cle: string, date: ?\DateTimeImmutable, type: string, objet: string, detail: ?string, montant: int, frais: int, controles: list<array{libelle: string, etat: string}>, justificatif: bool, auteur: ?string, lien: array{route: string, id: int, parametres?: array<string, string>}, comptes: ?array<int, string>, compte_impose: bool}>
     */
    public function enAttente(\DateTimeImmutable $maintenant): array
    {
        $lignes = [];

        /** @var list<Paiements> $paiements */
        $paiements = $this->em->createQueryBuilder()
            ->select('paiement', 'commande', 'client')
            ->from(Paiements::class, 'paiement')
            ->leftJoin('paiement.commande', 'commande')
            ->leftJoin('commande.clients', 'client')
            ->andWhere('paiement.statut = :attente')
            ->setParameter('attente', Paiements::STATUT_EN_ATTENTE)
            ->orderBy('paiement.date', 'ASC')
            ->getQuery()
            ->getResult();
        $references = $this->referencesDejaUtilisees($paiements);
        $comptes = null;

        foreach ($paiements as $paiement) {
            $commande = $paiement->getCommande();
            $reste = null === $commande ? null : max(0, (int) $commande->getTotalTtc() - $commande->getTotalPaye());
            $reference = self::normaliserReference($paiement->getReference());
            // Saisi en ligne par le client : le compte qui a reçu l'argent se choisit ici,
            // sauf l'API Orange Money, qui va toujours sur « Orange Money en ligne ».
            $choixCompte = null;
            $compteImpose = $paiement->estOrangeMoneyEnLigne();
            if ($compteImpose) {
                $enLigne = $this->comptesOrangeMoney?->trouverCompteEnLigne();
                $choixCompte = [(int) $enLigne?->getId() => $enLigne?->getNom() ?? ComptesOrangeMoney::NOM_EN_LIGNE];
            } elseif (null === $paiement->getCompteTresorerie() && [] !== Paiements::typesComptesPour($paiement->getMode())) {
                $comptes ??= $this->em->getRepository(CompteTresorerie::class)->findBy(['actif' => true], ['nom' => 'ASC']);
                $choixCompte = [];
                foreach ($comptes as $compte) {
                    // Le compte des paiements en ligne ne reçoit que l'API Orange Money.
                    if (\in_array($compte->getType(), Paiements::typesComptesPour($paiement->getMode()), true) && !$compte->isOrangeMoneyEnLigne()) {
                        $choixCompte[(int) $compte->getId()] = $compte->getNom();
                    }
                }
            }
            $lignes[] = [
                'cle' => 'paiement-'.$paiement->getId(),
                'date' => $paiement->getDate(),
                'type' => 'Encaissement '.mb_strtolower($paiement->getModeLabel()),
                'objet' => trim(($commande?->getClients()?->getNomComplet() ?? '').' · '.($commande?->getNumero() ?? ''), ' ·'),
                'detail' => $paiement->getReference(),
                'montant' => $paiement->getMontant(),
                'frais' => $paiement->getMontantTotalEncaisse() - $paiement->getMontant(),
                'controles' => [
                    ...self::controlesPaiement($paiement->getMontant(), $reste, $paiement->getMode(), $reference, null !== $reference && ($references[$reference] ?? 0) > 0),
                    ...(Paiements::MODE_SOLDE_CLIENT === $paiement->getMode() ? self::controleAvoir($paiement->getMontant(), $commande?->getClients()?->getSoldeCredit() ?? 0) : []),
                    ...self::controleDelai($paiement->getDate(), $maintenant),
                ],
                'justificatif' => null !== $paiement->getJustificatif() && '' !== $paiement->getJustificatif(),
                'auteur' => $paiement->getEncaissePar()?->getUserIdentifier() ?? 'Client, en ligne',
                'lien' => ['route' => 'app_paiements_show', 'id' => (int) $paiement->getId()],
                'comptes' => $choixCompte,
                'compte_impose' => $compteImpose,
            ];
        }

        /** @var list<MouvementTresorerie> $mouvements */
        $mouvements = $this->em->createQueryBuilder()
            ->select('mouvement')
            ->from(MouvementTresorerie::class, 'mouvement')
            ->andWhere('mouvement.statut = :attente')
            ->setParameter('attente', MouvementTresorerie::STATUT_EN_ATTENTE)
            ->orderBy('mouvement.dateOperation', 'ASC')
            ->getQuery()
            ->getResult();

        // Décaissements préparés par Finances › Dépenses : reçu joint, lien vers la dépense.
        $depenses = [];
        if ([] !== $mouvements) {
            foreach ($this->em->getRepository(Depense::class)->findBy(['mouvement' => $mouvements]) as $depense) {
                $depenses[(int) $depense->getMouvement()?->getId()] = $depense;
            }
        }

        foreach ($mouvements as $mouvement) {
            $depense = $depenses[(int) $mouvement->getId()] ?? null;
            $controles = [];
            if (null !== $mouvement->getModePaiement() && Paiements::MODE_ESPECES !== $mouvement->getModePaiement()) {
                $controles[] = '' === trim((string) $mouvement->getReferenceExterne())
                    ? ['libelle' => 'Référence manquante', 'etat' => self::ATTENTION]
                    : ['libelle' => 'Référence saisie', 'etat' => self::OK];
            }
            $lignes[] = [
                'cle' => 'mouvement-'.$mouvement->getId(),
                'date' => $mouvement->getDateOperation(),
                'type' => $mouvement->getTypeLabel(),
                'objet' => $mouvement->getLibelle(),
                'detail' => $mouvement->getCategorieLabel(),
                'montant' => (int) $mouvement->getMontant(),
                'frais' => 0,
                'controles' => [...$controles, ...self::controleDelai($mouvement->getDateOperation(), $maintenant)],
                'justificatif' => null !== $depense,
                'auteur' => $mouvement->getAgent()?->getUserIdentifier(),
                'lien' => null !== $depense
                    ? ['route' => 'app_finances_depenses_voir', 'id' => (int) $depense->getId()]
                    : ['route' => 'app_mouvement_tresorerie_show', 'id' => (int) $mouvement->getId()],
                'comptes' => null,
                'compte_impose' => false,
            ];
        }

        // Dépôts Orange Money faits depuis l'espace client sur le compte du client : ils vont à son solde.
        if (null !== $this->soldeClient) {
            $enLigne = null;
            foreach ($this->soldeClient->depotsEnAttente() as $depot) {
                $enLigne ??= $this->comptesOrangeMoney?->trouverCompteEnLigne();
                $client = $depot->getClient();
                $lignes[] = [
                    'cle' => 'depot-'.$depot->getId(),
                    'date' => $depot->getRecuLe() ?? $depot->getCreeLe(),
                    'type' => 'Dépôt sur le compte client',
                    'objet' => (string) $client?->getNomComplet(),
                    'detail' => 'OM '.($depot->getTxnid() ?? $depot->getReference()),
                    'montant' => $depot->getMontant(),
                    'frais' => $depot->getFondsSoutien() + $depot->getFraisRetrait(),
                    'controles' => [['libelle' => 'Va au solde du client', 'etat' => self::OK], ...self::controleDelai($depot->getRecuLe() ?? $depot->getCreeLe(), $maintenant)],
                    'justificatif' => false,
                    'auteur' => $depot->getAuteur() ?? 'Client, en ligne',
                    'lien' => ['route' => 'app_clients_show', 'id' => (int) $client?->getId(), 'parametres' => ['publicId' => (string) $client?->getPublicId(), '_fragment' => 'compte']],
                    'comptes' => [(int) $enLigne?->getId() => $enLigne?->getNom() ?? ComptesOrangeMoney::NOM_EN_LIGNE],
                    'compte_impose' => true,
                ];
            }
        }

        usort($lignes, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $lignes;
    }

    /**
     * Ce qui a été validé ces derniers jours, le plus récent d'abord.
     *
     * @return list<array{date: ?\DateTimeImmutable, operation: string, montant: int, par: ?string, ecriture: string}>
     */
    public function valideesRecemment(int $nombre = 8): array
    {
        $lignes = [];
        /** @var list<Paiements> $paiements */
        $paiements = $this->em->createQueryBuilder()
            ->select('paiement', 'commande', 'client', 'mouvement')
            ->from(Paiements::class, 'paiement')
            ->leftJoin('paiement.commande', 'commande')
            ->leftJoin('commande.clients', 'client')
            ->leftJoin('paiement.mouvementTresorerie', 'mouvement')
            ->andWhere('paiement.statut = :valide')
            ->andWhere('paiement.validePar IS NOT NULL')
            ->andWhere('paiement.dateValidation IS NOT NULL')
            // Les espèces et le mobile money sont validés à la saisie : seuls les modes vérifiés comptent ici.
            ->andWhere('paiement.mode IN (:modes)')
            ->setParameter('valide', Paiements::STATUT_VALIDE)
            ->setParameter('modes', [Paiements::MODE_VIREMENT, Paiements::MODE_CHEQUE, Paiements::MODE_CARTE])
            ->orderBy('paiement.dateValidation', 'DESC')
            ->setMaxResults($nombre)
            ->getQuery()
            ->getResult();
        foreach ($paiements as $paiement) {
            $lignes[] = [
                'date' => $paiement->getDateValidation(),
                'operation' => 'Encaissement '.mb_strtolower($paiement->getModeLabel()).' · '.($paiement->getCommande()?->getClients()?->getNomComplet() ?? $paiement->getCommande()?->getNumero()),
                'montant' => $paiement->getMontant(),
                'par' => $paiement->getValidePar()?->getUserIdentifier(),
                'ecriture' => $paiement->getMouvementTresorerie()?->getReference() ?? '—',
            ];
        }

        /** @var list<MouvementTresorerie> $mouvements */
        $mouvements = $this->em->createQueryBuilder()
            ->select('mouvement')
            ->from(MouvementTresorerie::class, 'mouvement')
            ->andWhere('mouvement.statut = :valide')
            ->andWhere('mouvement.dateValidation IS NOT NULL')
            ->setParameter('valide', MouvementTresorerie::STATUT_VALIDE)
            ->orderBy('mouvement.dateValidation', 'DESC')
            ->setMaxResults($nombre * 3)
            ->getQuery()
            ->getResult();
        foreach ($mouvements as $mouvement) {
            // Seuls ceux qui ont attendu une validation manuelle, hors encaissements déjà listés.
            if (null !== $mouvement->getPaiement() || !$mouvement->necessiteValidationManuelle()) {
                continue;
            }
            $lignes[] = [
                'date' => $mouvement->getDateValidation(),
                'operation' => $mouvement->getTypeLabel().' · '.$mouvement->getLibelle(),
                'montant' => (int) $mouvement->getMontant(),
                'par' => null,
                'ecriture' => $mouvement->getReference(),
            ];
        }

        usort($lignes, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return \array_slice($lignes, 0, $nombre);
    }

    /**
     * Valide ou rejette une opération désignée par sa clé
     * (« paiement-12 », « mouvement-5 », « depot-3 »). $compteId : le compte choisi pour
     * un paiement saisi en ligne, qui n'en a pas.
     */
    public function traiter(string $cle, bool $valider, string $motif, User $responsable, ?int $compteId = null): void
    {
        if (!preg_match('/^(paiement|mouvement|depot)-(\d+)$/', $cle, $m)) {
            throw new \InvalidArgumentException('Opération inconnue.');
        }
        if (!$valider && '' === trim($motif)) {
            throw new \InvalidArgumentException('Le motif du rejet est obligatoire.');
        }

        if ('depot' === $m[1]) {
            // Dépôt sur le compte du client : son solde monte à la validation, jamais avant.
            $depot = $this->em->find(DepotSoldeClient::class, (int) $m[2]) ?? throw new \InvalidArgumentException('Dépôt introuvable.');
            if (null === $this->soldeClient) {
                throw new \LogicException('Compte du client indisponible.');
            }
            $valider ? $this->soldeClient->validerDepot($depot, $responsable) : $this->soldeClient->rejeterDepot($depot, $motif, $responsable);

            return;
        }

        if ('paiement' === $m[1]) {
            $paiement = $this->em->find(Paiements::class, (int) $m[2]) ?? throw new \InvalidArgumentException('Paiement introuvable.');
            if ($valider && Paiements::MODE_SOLDE_CLIENT === $paiement->getMode()) {
                // Avoir utilisé en ligne : pas de compte de trésorerie, le solde du client baisse.
                $this->paiements->validerAvoir($paiement, $responsable);
                $this->actualiserCommande($paiement);

                return;
            }
            if ($valider) {
                // API Orange Money : le compte « Orange Money en ligne » est posé par PaiementService.
                if (null === $paiement->getCompteTresorerie() && !$paiement->estOrangeMoneyEnLigne() && [] !== Paiements::typesComptesPour($paiement->getMode())) {
                    $compte = null === $compteId ? null : $this->em->find(CompteTresorerie::class, $compteId);
                    if (null === $compte || !$compte->isActif() || $compte->isOrangeMoneyEnLigne()) {
                        throw new \InvalidArgumentException(\sprintf('Paiement de %s F saisi en ligne : choisissez le compte qui a reçu l\'argent.', number_format($paiement->getMontant(), 0, ',', ' ')));
                    }
                    $paiement->setCompteTresorerie($compte); // le type est contrôlé à la validation
                    $this->em->flush(); // valider() relit le paiement en base
                }
                $mouvement = $this->paiements->valider($paiement, $responsable);
                // Sur un compte bancaire, le mouvement attend lui aussi : il est vérifié ici en même temps.
                if ($mouvement->isEnAttente()) {
                    $this->mouvements->valider($mouvement);
                }
                $this->actualiserCommande($paiement);
            } else {
                $this->paiements->rejeter($paiement, $motif, $responsable);
                $this->prevenir($paiement->getEncaissePar(), $responsable, sprintf(
                    'Paiement de %s F sur %s rejeté : %s',
                    number_format($paiement->getMontant(), 0, ',', ' '),
                    $paiement->getCommande()?->getNumero() ?? 'la commande',
                    $motif,
                ), 'app_paiements_show', (int) $paiement->getId());
            }

            return;
        }

        $mouvement = $this->em->find(MouvementTresorerie::class, (int) $m[2]) ?? throw new \InvalidArgumentException('Mouvement introuvable.');
        if ($valider) {
            $this->mouvements->valider($mouvement);
        } else {
            $this->mouvements->annulerEnAttente($mouvement, $motif);
            $this->prevenir($mouvement->getAgent(), $responsable, sprintf(
                '%s de %s F rejeté : %s',
                $mouvement->getLibelle(),
                number_format((int) $mouvement->getMontant(), 0, ',', ' '),
                $motif,
            ), 'app_mouvement_tresorerie_show', (int) $mouvement->getId());
        }
    }

    /**
     * Contrôles d'un encaissement : montant face au reste dû, référence
     * pour les modes autres que les espèces, référence déjà utilisée.
     *
     * @return list<array{libelle: string, etat: string}>
     */
    public static function controlesPaiement(int $montant, ?int $resteDu, string $mode, ?string $reference, bool $referenceDejaUtilisee): array
    {
        $controles = [];
        if (null !== $resteDu) {
            $controles[] = match (true) {
                $montant === $resteDu => ['libelle' => 'Montant = reste dû', 'etat' => self::OK],
                $montant < $resteDu => ['libelle' => 'Acompte sur le reste dû', 'etat' => self::OK],
                default => ['libelle' => 'Dépasse le reste dû', 'etat' => self::ERREUR],
            };
        }
        if (Paiements::MODE_ESPECES !== $mode) {
            $controles[] = match (true) {
                null === $reference => ['libelle' => 'Référence manquante', 'etat' => self::ATTENTION],
                $referenceDejaUtilisee => ['libelle' => 'Référence déjà utilisée', 'etat' => self::ERREUR],
                default => ['libelle' => 'Référence unique', 'etat' => self::OK],
            };
        }

        return $controles;
    }

    /**
     * Avoir utilisé en ligne : le solde du client doit encore le couvrir.
     *
     * @return list<array{libelle: string, etat: string}>
     */
    public static function controleAvoir(int $montant, int $soldeClient): array
    {
        return [$soldeClient >= $montant
            ? ['libelle' => 'Couvert par le solde du client', 'etat' => self::OK]
            : ['libelle' => 'Solde du client insuffisant', 'etat' => self::ERREUR]];
    }

    /**
     * @return list<array{libelle: string, etat: string}>
     */
    public static function controleDelai(?\DateTimeImmutable $date, \DateTimeImmutable $maintenant): array
    {
        if (null === $date) {
            return [];
        }
        $heures = intdiv($maintenant->getTimestamp() - $date->getTimestamp(), 3600);
        if ($heures < self::DELAI_HEURES) {
            return [];
        }

        return [['libelle' => sprintf('Attend depuis %s', $heures < 48 ? $heures.' h' : intdiv($heures, 24).' j'), 'etat' => self::ATTENTION]];
    }

    public static function normaliserReference(?string $reference): ?string
    {
        $reference = mb_strtoupper(preg_replace('/\s+/', '', (string) $reference) ?? '');

        return '' === $reference ? null : $reference;
    }

    /**
     * Combien d'autres paiements (validés ou en attente) portent chaque référence.
     *
     * @param list<Paiements> $enAttente
     *
     * @return array<string, int>
     */
    private function referencesDejaUtilisees(array $enAttente): array
    {
        $references = array_values(array_filter(array_map(static fn (Paiements $p): ?string => $p->getReference(), $enAttente)));
        if ([] === $references) {
            return [];
        }
        $comptes = [];
        foreach ($this->em->createQueryBuilder()
            ->select('paiement.reference')
            ->from(Paiements::class, 'paiement')
            ->andWhere('paiement.reference IS NOT NULL')
            ->andWhere('paiement.statut IN (:statuts)')
            ->setParameter('statuts', [Paiements::STATUT_VALIDE, Paiements::STATUT_EN_ATTENTE])
            ->getQuery()
            ->getSingleColumnResult() as $reference) {
            $cle = self::normaliserReference($reference);
            if (null !== $cle) {
                $comptes[$cle] = ($comptes[$cle] ?? 0) + 1;
            }
        }

        // Le paiement lui-même compte une fois.
        return array_map(static fn (int $n): int => $n - 1, $comptes);
    }

    private function actualiserCommande(Paiements $paiement): void
    {
        $commande = $paiement->getCommande();
        if (null === $commande) {
            return;
        }
        $this->em->refresh($commande);
        $paye = $commande->getTotalPaye();
        $commande->setTotalPaye($paye);
        $commande->setResteAPayer(max(0, (int) $commande->getTotalTtc() - $paye));
        $commande->actualiserStatutPaiement();

        $facture = $this->em->getRepository(Factures::class)->findOneBy(['commande' => $commande, 'comptabilisee' => true]);
        if (null !== $facture) {
            $facture->synchroniserPaiementsDepuisCommande();
            if (null === $paiement->getFacture()) {
                $paiement->setFacture($facture);
            }
        }
        $this->em->flush();
    }

    private function prevenir(?User $auteur, User $responsable, string $message, string $route, int $id): void
    {
        if (null === $auteur || $auteur === $responsable) {
            return;
        }
        $this->notifications->notifierUtilisateur($auteur, $message, $route, ['id' => $id]);
        $this->em->flush();
    }
}
