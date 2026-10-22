<?php

namespace App\Service\Facturation;

use App\Entity\Factures;
use App\Entity\User;
use App\Service\NumerotationFactures;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Valider et numéroter » (règle de Yaya du 19/10/2026) : le numéro
 * définitif n'est donné qu'ici, à la date du jour (les numéros suivent
 * l'ordre des dates, l'échéance garde son délai) ; ensuite la facture ne
 * se modifie plus, toute correction passe par un avoir.
 */
final class ValidationFacture
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NumerotationFactures $numerotation,
    ) {
    }

    public function valider(Factures $facture, ?User $par, ?\DateTimeImmutable $maintenant = null): void
    {
        $maintenant ??= new \DateTimeImmutable();
        if (null === $facture->getNumero()) {
            $aujourdhui = $maintenant->setTime(0, 0);
            $date = $facture->getDateFacture();
            if (null === $date || $date < $aujourdhui) {
                $delai = null !== $date && null !== $facture->getDateEcheance() ? (int) $date->diff($facture->getDateEcheance())->days : null;
                $facture->setDateFacture($aujourdhui);
                if (null !== $delai) {
                    $facture->setDateEcheance($aujourdhui->modify('+'.$delai.' days'));
                }
            }
        }
        // Numéro et validation ensemble : deux validations simultanées n'ont jamais le même numéro.
        $this->em->wrapInTransaction(function () use ($facture, $par, $maintenant): void {
            $this->numerotation->attribuer($facture);
            $facture->setEtat(Factures::ETAT_EMISE);
            $facture->marquerValidee($par, $maintenant);
        });
    }
}
