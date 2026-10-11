<?php

namespace App\Service;

use App\Entity\Factures;
use App\Entity\MouvementTresorerie;
use App\Entity\Paiements;
use App\Entity\User;
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
    ) {
    }

    /**
     * @return list<array{cle: string, date: ?\DateTimeImmutable, type: string, objet: string, detail: ?string, montant: int, controles: list<array{libelle: string, etat: string}>, justificatif: bool, auteur: ?string, lien: array{route: string, id: int}}>
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

        foreach ($paiements as $paiement) {
            $commande = $paiement->getCommande();
            $reste = null === $commande ? null : max(0, (int) $commande->getTotalTtc() - $commande->getTotalPaye());
            $reference = self::normaliserReference($paiement->getReference());
            $lignes[] = [
                'cle' => 'paiement-'.$paiement->getId(),
                'date' => $paiement->getDate(),
                'type' => 'Encaissement '.mb_strtolower($paiement->getModeLabel()),
                'objet' => trim(($commande?->getClients()?->getNomComplet() ?? '').' · '.($commande?->getNumero() ?? ''), ' ·'),
                'detail' => $paiement->getReference(),
                'montant' => $paiement->getMontant(),
                'controles' => [
                    ...self::controlesPaiement($paiement->getMontant(), $reste, $paiement->getMode(), $reference, null !== $reference && ($references[$reference] ?? 0) > 0),
                    ...self::controleDelai($paiement->getDate(), $maintenant),
                ],
                'justificatif' => null !== $paiement->getJustificatif() && '' !== $paiement->getJustificatif(),
                'auteur' => $paiement->getEncaissePar()?->getUserIdentifier(),
                'lien' => ['route' => 'app_paiements_show', 'id' => (int) $paiement->getId()],
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

        foreach ($mouvements as $mouvement) {
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
                'controles' => [...$controles, ...self::controleDelai($mouvement->getDateOperation(), $maintenant)],
                'justificatif' => false,
                'auteur' => $mouvement->getAgent()?->getUserIdentifier(),
                'lien' => ['route' => 'app_mouvement_tresorerie_show', 'id' => (int) $mouvement->getId()],
            ];
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
     * (« paiement-12 », « mouvement-5 »).
     */
    public function traiter(string $cle, bool $valider, string $motif, User $responsable): void
    {
        if (!preg_match('/^(paiement|mouvement)-(\d+)$/', $cle, $m)) {
            throw new \InvalidArgumentException('Opération inconnue.');
        }
        if (!$valider && '' === trim($motif)) {
            throw new \InvalidArgumentException('Le motif du rejet est obligatoire.');
        }

        if ('paiement' === $m[1]) {
            $paiement = $this->em->find(Paiements::class, (int) $m[2]) ?? throw new \InvalidArgumentException('Paiement introuvable.');
            if ($valider) {
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
