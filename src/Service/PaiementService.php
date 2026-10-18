<?php

namespace App\Service;

use App\Entity\CompteTresorerie;
use App\Entity\ModificationClient;
use App\Entity\MouvementTresorerie;
use App\Entity\Paiements;
use App\Entity\User;
use App\Service\Import\Valeurs;
use App\Service\Paiement\ComptesOrangeMoney;
use App\Service\Paiement\SoldeClient;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

final class PaiementService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MouvementTresorerieService $mouvementTresorerieService,
        private readonly ?ComptesOrangeMoney $comptesOrangeMoney = null,
        private readonly ?SoldeClient $soldeClient = null,
    ) {
    }

    /**
     * Valide un paiement, crédite le compte de trésorerie
     * et crée son mouvement dans une transaction unique.
     */
    public function valider(
        Paiements $paiement,
        User $utilisateur
    ): MouvementTresorerie {
        $connexion = $this->entityManager->getConnection();

        $connexion->beginTransaction();

        try {
            /*
             * Si le paiement existe déjà, on le verrouille afin
             * d’empêcher deux validations simultanées.
             */
            if ($paiement->getId() !== null) {
                $this->entityManager->lock(
                    $paiement,
                    LockMode::PESSIMISTIC_WRITE
                );

                /*
                 * Recharge les relations depuis la base, notamment
                 * mouvementTresorerie, avant le contrôle anti-doublon.
                 */
                $this->entityManager->refresh($paiement);
            } else {
                /*
                 * Permet également de valider directement
                 * un nouveau paiement.
                 */
                $this->entityManager->persist($paiement);
                $this->entityManager->flush();
            }

            if (!$paiement->estEnAttente()) {
                throw new \LogicException(
                    'Seul un paiement en attente peut être validé.'
                );
            }

            if ($paiement->getMouvementTresorerie() !== null) {
                throw new \LogicException(
                    'Ce paiement possède déjà un mouvement de trésorerie.'
                );
            }

            /*
             * Payé avec l'API Orange Money depuis l'espace client : il va
             * toujours sur le compte « Orange Money en ligne », jamais sur
             * un numéro de l'imprimerie (docs/finances.md).
             */
            if ($paiement->estOrangeMoneyEnLigne() && $this->comptesOrangeMoney !== null) {
                $paiement->setCompteTresorerie(
                    $this->comptesOrangeMoney->compteEnLigne()
                );
            }

            $compte = $paiement->getCompteTresorerie();

            if ($compte === null) {
                throw new \LogicException(
                    'Le compte de trésorerie du paiement est obligatoire.'
                );
            }

            /*
             * Le verrouillage du compte protège le solde contre
             * plusieurs encaissements exécutés simultanément.
             */
            if ($compte->getId() !== null) {
                $this->entityManager->lock(
                    $compte,
                    LockMode::PESSIMISTIC_WRITE
                );

                $this->entityManager->refresh($compte);
            }

            $paiement->verifierCompatibiliteCompte();
            $paiement->validerPar($utilisateur);

            $mouvement = new MouvementTresorerie();

            $mouvement
                ->setType(MouvementTresorerie::TYPE_ENCAISSEMENT)
                ->setCategorie(MouvementTresorerie::CATEGORIE_VENTE)
                ->setCompteDestination($compte)
                ->setMontant($paiement->getMontant())
                ->setReferenceExterne(
                    $this->construireReferencePaiement($paiement)
                )
                ->setLibelle(
                    $this->construireLibellePaiement($paiement)
                )
                ->setDescription($paiement->getObservation());

            $paiement->setMouvementTresorerie($mouvement);

            /*
             * enregistrer() crédite immédiatement le compte, sauf
             * s’il s’agit d’un compte bancaire : le mouvement reste
             * alors en attente d’une validation manuelle distincte
             * (rapprochement bancaire).
             */
            $this->mouvementTresorerieService->enregistrer($mouvement);

            /*
             * Frais payés par le client (paiement Orange Money de
             * l'espace client) : le compte reçoit aussi les frais, dans
             * un mouvement à part, comme à la caisse. Sur un compte
             * Orange Money ils sont « à prélever » : Orange les débite
             * quand l'argent sort du compte ; ni vente ni produit.
             */
            $frais = self::mouvementFrais($paiement, $compte, $utilisateur);
            if ($frais !== null) {
                $this->mouvementTresorerieService->enregistrer($frais);
            }

            $connexion->commit();

            return $mouvement;
        } catch (\Throwable $exception) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Valide l'avoir (solde du client) qu'il a utilisé en ligne depuis
     * « Factures et paiements » : aucun compte de trésorerie (l'argent est
     * déjà à l'imprimerie), le solde du client baisse du montant, et la fiche
     * le note dans son journal. Refusé si le solde ne suffit plus ou si le
     * montant dépasse ce qui reste dû sur la commande.
     *
     * Le compte du client (SoldeClient) écrit aussi la ligne de son relevé.
     */
    public function validerAvoir(
        Paiements $paiement,
        User $utilisateur
    ): void {
        if ($this->soldeClient !== null) {
            $this->soldeClient->validerAvoir($paiement, $utilisateur);

            return;
        }

        if ($paiement->getMode() !== Paiements::MODE_SOLDE_CLIENT) {
            throw new \LogicException('Ce paiement n’utilise pas le solde du client.');
        }

        $connexion = $this->entityManager->getConnection();
        $connexion->beginTransaction();

        try {
            if ($paiement->getId() !== null) {
                $this->entityManager->lock($paiement, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($paiement);
            }

            if (!$paiement->estEnAttente()) {
                throw new \LogicException('Seul un paiement en attente peut être validé.');
            }

            $commande = $paiement->getCommande();
            $client = $commande?->getClients();

            if ($commande === null || $client === null) {
                throw new \LogicException('Ce paiement n’est rattaché à aucun client.');
            }

            if ($client->getId() !== null) {
                $this->entityManager->lock($client, LockMode::PESSIMISTIC_WRITE);
                $this->entityManager->refresh($client);
            }

            $montant = $paiement->getMontant();
            $avant = $client->getSoldeCredit();

            if ($avant < $montant) {
                throw new \LogicException(sprintf(
                    'Solde du client insuffisant : %s disponibles pour %s. Rejetez ce paiement.',
                    Valeurs::francs($avant),
                    Valeurs::francs($montant)
                ));
            }

            if ($montant > $commande->getResteAPayer()) {
                throw new \LogicException('Le montant dépasse le reste à payer de la commande. Rejetez ce paiement.');
            }

            $paiement->validerPar($utilisateur);
            $client->setSoldeCredit($avant - $montant);

            $this->entityManager->persist(new ModificationClient(
                $client,
                'Solde client',
                'conditions',
                Valeurs::francs($avant),
                sprintf('%s (avoir utilisé en ligne, %s)', Valeurs::francs($client->getSoldeCredit()), $paiement->getReference() ?? 'commande '.$commande->getNumero()),
                new \DateTimeImmutable(),
                $utilisateur
            ));

            $this->entityManager->flush();
            $connexion->commit();
        } catch (\Throwable $exception) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Annule un paiement validé et restaure le solde
     * du compte de trésorerie.
     */
    public function annuler(
        Paiements $paiement,
        string $motif,
        User $utilisateur
    ): void {
        $motif = trim($motif);

        if ($motif === '') {
            throw new \InvalidArgumentException(
                'Le motif de l’annulation est obligatoire.'
            );
        }

        if ($paiement->getId() === null) {
            throw new \LogicException(
                'Un paiement non enregistré ne peut pas être annulé.'
            );
        }

        /*
         * Payé avec le solde du client : aucun compte de trésorerie,
         * le montant revient à son compte (ligne « retour » du relevé).
         */
        if ($paiement->getMode() === Paiements::MODE_SOLDE_CLIENT && $this->soldeClient !== null) {
            $this->soldeClient->annulerUtilisation($paiement, $motif, $utilisateur);

            return;
        }

        $connexion = $this->entityManager->getConnection();

        $connexion->beginTransaction();

        try {
            $this->entityManager->lock(
                $paiement,
                LockMode::PESSIMISTIC_WRITE
            );

            $this->entityManager->refresh($paiement);

            if (!$paiement->estValide()) {
                throw new \LogicException(
                    'Seul un paiement validé peut être annulé.'
                );
            }

            $mouvement = $paiement->getMouvementTresorerie();

            if ($mouvement === null) {
                throw new \LogicException(
                    'Aucun mouvement de trésorerie n’est associé à ce paiement.'
                );
            }

            $this->entityManager->lock(
                $mouvement,
                LockMode::PESSIMISTIC_WRITE
            );

            if (!$mouvement->isValide()) {
                throw new \LogicException(
                    'Le mouvement de ce paiement n’est pas dans un état annulable.'
                );
            }

            $compte = $paiement->getCompteTresorerie();

            if ($compte === null) {
                throw new \LogicException(
                    'Aucun compte de trésorerie n’est associé au paiement.'
                );
            }

            /*
             * annulerValide() verrouille les comptes concernés,
             * contrepasse le crédit initial (devient un débit) et
             * marque le mouvement comme annulé.
             */
            $this->mouvementTresorerieService->annulerValide($mouvement, $motif);

            // Les frais crédités à la validation repartent avec le paiement.
            $frais = $this->entityManager->getRepository(MouvementTresorerie::class)
                ->findOneBy(['reference' => self::referenceFrais($paiement)]);
            if ($frais !== null && $frais->isValide()) {
                $this->mouvementTresorerieService->annulerValide($frais, $motif);
            }

            $paiement->annuler($motif, $utilisateur);

            $this->entityManager->flush();

            $connexion->commit();
        } catch (\Throwable $exception) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $exception;
        }
    }

    /**
     * Rejette un paiement encore en attente.
     * Aucun mouvement de trésorerie n’est créé.
     */
    public function rejeter(
        Paiements $paiement,
        string $motif,
        User $utilisateur
    ): void {
        $motif = trim($motif);

        if ($motif === '') {
            throw new \InvalidArgumentException(
                'Le motif du rejet est obligatoire.'
            );
        }

        $connexion = $this->entityManager->getConnection();

        $connexion->beginTransaction();

        try {
            if ($paiement->getId() !== null) {
                $this->entityManager->lock(
                    $paiement,
                    LockMode::PESSIMISTIC_WRITE
                );

                $this->entityManager->refresh($paiement);
            }

            if ($paiement->getMouvementTresorerie() !== null) {
                throw new \LogicException(
                    'Un paiement possédant un mouvement ne peut pas être rejeté.'
                );
            }

            $paiement->rejeter($motif, $utilisateur);

            $this->entityManager->persist($paiement);
            $this->entityManager->flush();

            $connexion->commit();
        } catch (\Throwable $exception) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $exception;
        }
    }

    /** Référence du mouvement des frais d'un paiement validé ici. */
    public static function referenceFrais(Paiements $paiement): string
    {
        return sprintf('MVT-FRAIS-P%d', $paiement->getId());
    }

    /**
     * Mouvement des frais mobile money d'un paiement (fonds de soutien,
     * frais de retrait), ou null s'il n'y en a pas.
     */
    public static function mouvementFrais(
        Paiements $paiement,
        CompteTresorerie $compte,
        ?User $utilisateur
    ): ?MouvementTresorerie {
        $montant = $paiement->getMontantFondsSoutien() + $paiement->getMontantFraisRetrait();
        if ($montant <= 0) {
            return null;
        }

        $detail = array_filter([
            $paiement->getMontantFondsSoutien() > 0 ? sprintf('fonds de soutien : %s FCFA', number_format($paiement->getMontantFondsSoutien(), 0, ',', ' ')) : null,
            $paiement->getMontantFraisRetrait() > 0 ? sprintf('frais de retrait : %s FCFA', number_format($paiement->getMontantFraisRetrait(), 0, ',', ' ')) : null,
        ]);
        $commande = $paiement->getCommande();
        $categorie = ComptesOrangeMoney::categorieFrais($compte);
        $orange = MouvementTresorerie::CATEGORIE_FRAIS_ORANGE === $categorie;

        return (new MouvementTresorerie())
            ->setReference(self::referenceFrais($paiement))
            ->setType(MouvementTresorerie::TYPE_ENCAISSEMENT)
            ->setCategorie($categorie)
            ->setConfidentiel(false)
            ->setCompteDestination($compte)
            ->setMontant($montant)
            ->setModePaiement($paiement->getMode())
            ->setReferenceExterne($paiement->getReference())
            ->setLibelle(mb_substr(sprintf(
                '%s - commande %s — %s',
                $orange ? 'Frais Orange Money à prélever' : 'Frais mobile money',
                $commande?->getNumero() ?? '#'.$commande?->getId(),
                $commande?->getClients()?->getNomComplet() ?? 'Client inconnu'
            ), 0, 255))
            ->setDescription(sprintf(
                'Frais mobile money à la charge du client sur le paiement de %s FCFA (%s).%s',
                number_format((int) $paiement->getMontant(), 0, ',', ' '),
                implode(', ', $detail),
                $orange ? ' Orange les débite quand l\'argent sort du compte.' : ''
            ))
            ->setAgent($utilisateur)
            ->setDateOperation($paiement->getDate() ?? new \DateTimeImmutable());
    }

    private function construireReferencePaiement(
        Paiements $paiement
    ): string {
        if (
            $paiement->getReference() !== null
            && trim($paiement->getReference()) !== ''
        ) {
            return trim($paiement->getReference());
        }

        return sprintf(
            'PAIEMENT-%d',
            $paiement->getId()
        );
    }

    private function construireLibellePaiement(
        Paiements $paiement
    ): string {
        $numeroCommande = $paiement->getCommande()?->getId();

        return sprintf(
            'Paiement de la commande #%s par %s',
            $numeroCommande ?? 'N/A',
            $paiement->getModeLabel()
        );
    }
}