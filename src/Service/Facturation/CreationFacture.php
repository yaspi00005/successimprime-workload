<?php

namespace App\Service\Facturation;

use App\Entity\Commandes;
use App\Entity\Factures;
use App\Service\NumerotationFactures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Crée le brouillon d'un document depuis une commande (« Nouveau
 * document », maquette FD01) : facture, proforma ou facture d'acompte.
 * Les montants sont ceux de la commande ; la facture reçoit son numéro à
 * la validation (FacturesController::emettre), la proforma tout de suite.
 */
final class CreationFacture
{
    /** Parts d'acompte proposées, en %. */
    public const ACOMPTES = [30, 50, 70, 100];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NumerotationFactures $numerotation,
    ) {
    }

    /** @throws \DomainException */
    public function brouillon(Commandes $commande, string $type, ?int $pourcentageAcompte = null, ?\DateTimeImmutable $le = null): Factures
    {
        if ($commande->estSoldeOuverture()) {
            throw new \DomainException('Un solde d\'ouverture ne se facture pas.');
        }
        if (Factures::TYPE_FACTURE === $type) {
            $existante = $this->em->createQueryBuilder()->select('f')->from(Factures::class, 'f')
                ->where('f.commande = :c AND f.typeDocument = :t AND f.etat <> :annulee')
                ->setParameter('c', $commande)->setParameter('t', Factures::TYPE_FACTURE)->setParameter('annulee', Factures::ETAT_ANNULEE)
                ->setMaxResults(1)->getQuery()->getOneOrNullResult();
            if ($existante instanceof Factures) {
                throw new \DomainException(\sprintf('La commande %s a déjà sa facture (%s).', $commande->getNumero(), $existante->getNumero() ?? 'brouillon'), (int) $existante->getId());
            }
        }
        $le ??= new \DateTimeImmutable('today');
        $facture = new Factures();
        if (Factures::TYPE_ACOMPTE === $type) {
            $facture->chargerAcompte($commande, $pourcentageAcompte ?? 50);
        } else {
            $facture->chargerDepuisCommande($commande);
        }
        $facture->setTypeDocument($type)
            ->setEmetteur(Factures::EMETTEUR_MDG_SUCCESS)
            ->setDateFacture($le)
            ->setEtat(Factures::ETAT_BROUILLON);
        if (Factures::TYPE_FACTURE === $type) {
            $facture->setComptabilisee(true);
        }
        // Échéance à 30 jours pour un compte pro, au jour même sinon (modifiable dans l'éditeur).
        $facture->setDateEcheance($commande->getClients()?->isOrganisation() ? $le->modify('+30 days') : $le);
        $facture->genererTokenAuthenticite();
        $this->em->persist($facture);
        $this->em->flush();
        if (Factures::TYPE_PROFORMA === $type) {
            $this->numerotation->attribuer($facture);
        }
        if ($facture->isComptabilisee()) {
            foreach ($commande->getPaiements() as $paiement) {
                if ($paiement->estValide() && null === $paiement->getFacture()) {
                    $paiement->setFacture($facture);
                }
            }
        }
        $facture->synchroniserPaiementsDepuisCommande();
        $this->em->flush();

        return $facture;
    }
}
