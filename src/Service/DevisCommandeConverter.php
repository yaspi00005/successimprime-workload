<?php

namespace App\Service;

use App\Entity\CommandeDetailFinition;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\DemandeExceptionPromotion;
use App\Entity\Devis;
use Doctrine\ORM\EntityManagerInterface;

final class DevisCommandeConverter
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    public function convertir(Devis $devis): Commandes
    {
        $this->verifierDevis($devis);

        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();

        try {
            $commande = $this->creerCommande($devis);

            foreach ($devis->getDevisDetails() as $devisDetail) {
                $commandeDetail = new CommandesDetails();

                /*
                 * Produit et origine de la ligne.
                 */
                $commandeDetail
                    ->setProduit($devisDetail->getProduit())
                    ->setProduitConfiguration(
                        $devisDetail->getProduitConfiguration()
                    )
                    ->setDesignation($devisDetail->getDesignation())
                    ->setTypeLigne($devisDetail->getTypeLigne())
                    ->setModeSaisie($devisDetail->getModeSaisie())
                    ->setModeConfiguration(
                        $devisDetail->getModeConfiguration()
                    );

                /*
                 * Paramètres d’impression.
                 */
                $commandeDetail
                    ->setTypeImpression(
                        $devisDetail->getTypeImpression()
                    )
                    ->setSupport($devisDetail->getSupport())
                    ->setFormat($devisDetail->getFormat());

                /*
                 * Quantités et dimensions.
                 */
                $commandeDetail
                    ->setQuantite($devisDetail->getQuantite())
                    ->setLongueur($devisDetail->getLongueur())
                    ->setLargeur($devisDetail->getLargeur())
                    ->setSurface($devisDetail->getSurface())
                    ->setNombreFaces(
                        $devisDetail->getNombreFaces()
                    );

                /*
                 * Copie des montants du devis.
                 *
                 * Il ne faut pas recalculer les tarifs ici :
                 * la commande doit conserver l'instantané du devis accepté.
                 */
                $commandeDetail
                    ->setPrixUnitaire(
    $devisDetail->getPrixUnitaire()
)
->setRemise(
    $devisDetail->getRemise()
)
->setTotalHt(
    $devisDetail->getTotalHt()
)
->setTotalTtc(
    $devisDetail->getTotalTtc()
)
->setObservation(
    $devisDetail->getObservation()
);

                /*
                 * Promotion de la ligne (docs/promotions.md) : elle suit
                 * telle quelle, comme les montants. L'exception B2B
                 * acceptée est recopiée sur la ligne de la commande ;
                 * une demande encore en attente y passe.
                 */
                $commandeDetail->reprendrePromotion(
                    $devisDetail->getPromotion(),
                    $devisDetail->getLibellePromotion(),
                    $devisDetail->getRemisePromotion(),
                    $devisDetail->getMontantPromotion(),
                    $devisDetail->isPromotionRetiree()
                );
                // Part du code promo du devis : elle suit aussi, déjà dans les montants.
                $commandeDetail->setRemiseCodePromo($devisDetail->getRemiseCodePromo());

                if (null !== $devisDetail->getId()) {
                    foreach ($this->entityManager->getRepository(DemandeExceptionPromotion::class)->findBy(['ligneDevis' => $devisDetail]) as $demande) {
                        if ($demande->estAcceptee()) {
                            $this->entityManager->persist($demande->reprisePour($commandeDetail));
                        } elseif ($demande->estEnAttente()) {
                            $demande->transfererVers($commandeDetail);
                        }
                    }
                }

                /*
                 * addCommandesDetail() doit également appeler :
                 * $commandeDetail->setCommandes($this)
                 */
                $commande->addCommandesDetail($commandeDetail);

                $this->copierFinitions(
                    $devisDetail,
                    $commandeDetail
                );
            }

            /*
             * Relation entre le devis et la nouvelle commande.
             */
            $devis
                ->setCommande($commande)
                ->setStatut(Devis::STATUT_CONVERTI);

            $this->entityManager->persist($commande);
            $this->entityManager->flush();

            /*
             * Numéro de commande, comme une commande saisie à la caisse
             * (CMD-000123-10-2026) : il faut l'identifiant, donc après
             * le premier enregistrement.
             */
            $commande->setNumero(\sprintf('CMD-%06d-%s', $commande->getId(), (new \DateTimeImmutable())->format('m-Y')));
            $this->entityManager->flush();

            $connection->commit();

            return $commande;
        } catch (\Throwable $exception) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }

            /*
             * L'EntityManager peut être fermé après une erreur SQL.
             * On laisse remonter l'erreur réelle au contrôleur.
             */
            throw $exception;
        }
    }

    private function verifierDevis(Devis $devis): void
    {
        if ($devis->isDeleted()) {
            throw new \LogicException(
                'Un devis supprimé ne peut pas être converti.'
            );
        }

        if ($devis->getStatut() !== Devis::STATUT_ACCEPTE) {
            throw new \LogicException(
                'Le devis doit être accepté avant sa conversion.'
            );
        }

        if ($devis->getCommande() !== null) {
            throw new \LogicException(
                'Ce devis a déjà été converti en commande.'
            );
        }

        if ($devis->getDevisDetails()->isEmpty()) {
            throw new \LogicException(
                'Le devis ne contient aucune ligne.'
            );
        }
    }

    private function creerCommande(Devis $devis): Commandes
    {
        $commande = new Commandes();

        $commande
            ->setClients($devis->getClients())
            ->setAgents($devis->getAgents())
            ->setDateCommande(new \DateTimeImmutable())
            ->setRemise($devis->getRemise())
            ->setTva($devis->getTva())
            ->setTotalHt($devis->getTotalHt())
            ->setTotalTtc($devis->getTotalTtc())
            ->setMontantApayer($devis->getMontantApayer())
            ->setObservation($devis->getObservation())
            ->setEtat(true);

        /*
         * Frais de livraison (déjà dans les totaux copiés) et objet du
         * devis. Des frais de livraison font une commande à livrer.
         */
        $commande
            ->setFraisLivraison($devis->getFraisLivraison())
            ->setObjet($devis->getObjet());

        if ($devis->getFraisLivraison() > 0) {
            $commande->setModeLivraison(Commandes::LIVRAISON_DOMICILE);
        }

        /*
         * Code promo (docs/promotions.md) : la commande reprend le code du
         * devis et son utilisation (le devis transformé ne compte plus).
         */
        $commande
            ->setCodePromo($devis->getCodePromo())
            ->setCodeSaisi($devis->getCodeSaisi())
            ->setRemiseCodePromo($devis->getRemiseCodePromo());

        /*
         * Utilisez directement la constante si elle existe
         * dans l'entité Commandes.
         */
        if (defined(Commandes::class . '::STATUT_VALIDEE')) {
            $commande->setStatut(
                constant(Commandes::class . '::STATUT_VALIDEE')
            );
        } else {
            $commande->setStatut('validee');
        }

        return $commande;
    }

    private function copierFinitions(
        object $devisDetail,
        CommandesDetails $commandeDetail
    ): void {
        /*
         * Protection supplémentaire contre les doublons présents
         * accidentellement dans la collection du devis.
         */
        $finitionsCopiees = [];

        foreach ($devisDetail->getFinitions() as $devisFinition) {
            $configurationFinition = $devisFinition
                ->getConfigurationFinition();

            $finition = $devisFinition->getFinition();

            /*
             * Une finition automatique est identifiée par sa
             * configuration. Une finition manuelle est identifiée
             * directement par sa finition.
             */
            if ($configurationFinition?->getId() !== null) {
                $cleUnique = sprintf(
                    'configuration-%d',
                    $configurationFinition->getId()
                );
            } elseif ($finition?->getId() !== null) {
                $cleUnique = sprintf(
                    'finition-%d',
                    $finition->getId()
                );
            } else {
                throw new \LogicException(
                    'Une finition du devis est invalide : '
                    . 'aucune finition n’est associée.'
                );
            }

            /*
             * La même finition ne doit être copiée qu'une seule fois
             * dans un même détail de commande.
             */
            if (isset($finitionsCopiees[$cleUnique])) {
                continue;
            }

            $finitionsCopiees[$cleUnique] = true;

            $commandeFinition = new CommandeDetailFinition();

            $commandeFinition
                ->setConfigurationFinition(
                    $configurationFinition
                )
                ->setFinition($finition)
                ->setNomFinition(
                    $devisFinition->getNomFinition()
                )
                ->setObligatoire(
                    $devisFinition->isObligatoire()
                )
                ->setPrixApplique(
                    max(
                        0,
                        (int) $devisFinition->getPrixApplique()
                    )
                )
                ->setModeCalcul(
                    $devisFinition->getModeCalcul() ?: 'forfait'
                )
                ->setQuantite(
                    max(
                        1,
                        (int) $devisFinition->getQuantite()
                    )
                )
                ->setMontant(
                    max(
                        0,
                        (int) $devisFinition->getMontant()
                    )
                );

            /*
             * addFinition() doit définir le côté propriétaire :
             * $commandeFinition->setCommandeDetail($this)
             */
            $commandeDetail->addFinition($commandeFinition);
        }
    }
}