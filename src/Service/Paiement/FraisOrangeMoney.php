<?php

namespace App\Service\Paiement;

use App\Entity\Paiements;
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

    /** Modes où le client peut payer les frais en plus : Orange Money et Wave (mêmes taux). */
    public const MODES = [Paiements::MODE_ORANGE_MONEY, Paiements::MODE_WAVE];

    /**
     * Frais d'un paiement saisi par l'équipe, à la caisse
     * (CommandesController::paiement) comme à la modification d'un paiement
     * en attente (PaiementsController::edit) : seulement en Orange Money ou
     * Wave (les cases sont décochées pour tout autre mode), seulement pour
     * les cases cochées, et toujours calculés ici, jamais repris du
     * formulaire. Ils vont sur le paiement, pas dans le montant.
     *
     * Un paiement en attente dont ni le montant, ni le mode, ni la case
     * n'ont changé garde ses frais déjà calculés (ceux d'un paiement en ligne
     * viennent de la transaction Orange Money, aux taux du jour du paiement).
     *
     * @param array{montant: int, mode: ?string, fondsSoutienInclus: bool, fraisRetraitInclus: bool}|null $avant paiement tel qu'enregistré, null pour un nouveau
     */
    public static function appliquer(Paiements $paiement, ParametresPaiement $parametres, ?array $avant = null): void
    {
        if (!\in_array($paiement->getMode(), self::MODES, true)) {
            $paiement->setFondsSoutienInclus(false)->setFraisRetraitInclus(false);
        }

        $frais = self::calculer($paiement->getMontant(), $parametres);
        $memeBase = null !== $avant
            && $avant['montant'] === $paiement->getMontant()
            && $avant['mode'] === $paiement->getMode();

        $paiement->setMontantFondsSoutien(match (true) {
            !$paiement->isFondsSoutienInclus() => 0,
            $memeBase && $avant['fondsSoutienInclus'] && $paiement->getMontantFondsSoutien() > 0 => $paiement->getMontantFondsSoutien(),
            default => $frais->fondsSoutien,
        });

        $paiement->setMontantFraisRetrait(match (true) {
            !$paiement->isFraisRetraitInclus() => 0,
            $memeBase && $avant['fraisRetraitInclus'] && $paiement->getMontantFraisRetrait() > 0 => $paiement->getMontantFraisRetrait(),
            default => $frais->fraisRetrait,
        });
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
