<?php

namespace App\Twig;

use App\Entity\CompteTresorerie;
use App\Service\Paiement\ComptesOrangeMoney;
use Twig\Attribute\AsTwigFunction;

/**
 * Part d'un compte Orange Money qu'Orange va débiter (fonds de soutien et
 * frais de retrait payés par les clients) et part transférable. Seulement
 * là où les soldes se montrent déjà (ROLE_TRESORERIE_VOIR) : la fonction
 * ne vérifie pas les droits, l'écran qui l'appelle les a.
 */
final class OrangeMoneyExtension
{
    public function __construct(private readonly ComptesOrangeMoney $comptes)
    {
    }

    /** @return array{solde: int, a_prelever: int, transferable: int}|null null hors Orange Money */
    #[AsTwigFunction('etat_orange_money')]
    public function etat(CompteTresorerie $compte): ?array
    {
        return $this->comptes->etat($compte);
    }
}
