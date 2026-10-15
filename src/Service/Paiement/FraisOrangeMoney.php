<?php

namespace App\Service\Paiement;

use App\Entity\ParametresPaiement;

/**
 * Frais d'un paiement Orange Money, payés par le client en plus du
 * montant (docs/espace-client.md) : le fonds de soutien (prélèvement de
 * l'État sur les retraits mobile money) et le frais de retrait d'Orange.
 * Taux de Réglages › Paiements (ParametresPaiement), arrondis au franc.
 *
 * Le montant reste la part de la commande (acompte, pack) ; les frais
 * vont sur le paiement, jamais dans le prix ni sur la facture.
 */
final class FraisOrangeMoney
{
    public function __construct(
        public readonly int $montant,
        public readonly float $tauxFondsSoutien,
        public readonly int $fondsSoutien,
        public readonly float $tauxFraisRetrait,
        public readonly int $fraisRetrait,
    ) {
    }

    public static function calculer(int $montant, ParametresPaiement $parametres): self
    {
        $montant = max(0, $montant);

        return new self(
            $montant,
            $parametres->getTauxFondsSoutien(),
            max(0, $parametres->calculerFondsSoutien($montant)),
            $parametres->getTauxFraisRetrait(),
            max(0, $parametres->calculerFraisRetrait($montant)),
        );
    }

    /**
     * Retrouve le montant d'une somme reçue frais compris (relevé du compte
     * marchand) : null si aucun montant ne donne exactement cette somme.
     */
    public static function decomposer(int $total, ParametresPaiement $parametres): ?self
    {
        $taux = 1 + ($parametres->getTauxFondsSoutien() + $parametres->getTauxFraisRetrait()) / 100;
        $estime = (int) round($total / $taux);
        foreach ([0, -1, 1, -2, 2] as $ecart) {
            $frais = self::calculer($estime + $ecart, $parametres);
            if ($frais->total() === $total && $frais->montant > 0) {
                return $frais;
            }
        }

        return null;
    }

    /** Ce que le client paie chez Orange Money. */
    public function total(): int
    {
        return $this->montant + $this->fondsSoutien + $this->fraisRetrait;
    }

    public function frais(): int
    {
        return $this->fondsSoutien + $this->fraisRetrait;
    }

    /** Taux tel qu'il est réglé : « 1 % », « 1,5 % ». */
    public static function taux(float $taux): string
    {
        return rtrim(rtrim(number_format($taux, 2, ',', ' '), '0'), ',').' %';
    }
}
