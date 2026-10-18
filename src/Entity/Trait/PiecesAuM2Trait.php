<?php

namespace App\Entity\Trait;

use App\Service\PiecesParMetreCarre;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Pièces au m² (docs/saisie-devis-commandes.md) sur une ligne de commande
 * ou de devis au m² : petites pièces (autocollants, étiquettes) facturées
 * selon le nombre de pièces qui tiennent sur 1 m², espacement compris.
 *
 * La ligne garde l'espacement retenu quand le calcul a été choisi : un
 * changement du réglage ne modifie pas le total d'une ligne déjà
 * enregistrée. Sans espacement (null), la ligne reste au calcul au m²
 * habituel (surface d'une pièce × quantité).
 *
 * La quantité reste le nombre de pièces à produire ; la surface facturée
 * en découle : pièces ÷ pièces au m², au centième de m² supérieur.
 *
 * La classe qui l'utilise a largeur et longueur (en mètres), quantite,
 * modeCalcul, typeLigne et la constante TYPE_PRODUIT.
 */
trait PiecesAuM2Trait
{
    #[ORM\Column(type: Types::DECIMAL, precision: 4, scale: 1, nullable: true)]
    private ?string $espacementPiecesM2 = null;

    /** Espacement (mm) du calcul en pièces au m² ; null : calcul au m² habituel. */
    public function getEspacementPiecesM2(): ?float
    {
        return null === $this->espacementPiecesM2 ? null : (float) $this->espacementPiecesM2;
    }

    /** Pose l'espacement tel quel (reprise d'un devis, synchronisation, essais). */
    public function setEspacementPiecesM2(string|float|int|null $espacement): static
    {
        $this->espacementPiecesM2 = null === $espacement || '' === $espacement
            ? null
            : number_format(PiecesParMetreCarre::espacementValide($espacement), 1, '.', '');

        return $this;
    }

    /**
     * Choix de la saisie (case « Pièces au m² ») : le calcul n'est retenu que
     * pour une ligne produit au m² dont la pièce tient sur 1 m. Une ligne qui
     * l'avait déjà garde son espacement ; sinon celui du réglage est pris.
     */
    public function choisirPiecesAuM2(bool $oui, float $espacementReglage): static
    {
        $espacement = $this->getEspacementPiecesM2() ?? PiecesParMetreCarre::espacementValide($espacementReglage);
        $this->espacementPiecesM2 = null;

        if ($oui && $this->peutEtreEnPiecesAuM2()) {
            $calcul = PiecesParMetreCarre::pourDimensionsEnMetres($this->dimensionPiecesM2($this->largeur), $this->dimensionPiecesM2($this->longueur), $espacement);
            if (null !== $calcul && !$calcul['tropGrande']) {
                $this->setEspacementPiecesM2($espacement);
            }
        }

        return $this;
    }

    /** Ligne produit au m² : la case « Pièces au m² » a un sens. */
    public function peutEtreEnPiecesAuM2(): bool
    {
        return self::TYPE_PRODUIT === $this->typeLigne && 'metre_carre' === $this->modeCalcul;
    }

    /**
     * Le calcul de la ligne quand elle est facturée en pièces au m², avec la
     * surface facturée ; null sinon (calcul au m² habituel).
     *
     * @return array{parM2: int, colonnes: int, rangees: int, tournee: bool, tropGrande: bool, espacement: float, surface: float}|null
     */
    public function getPiecesAuM2(): ?array
    {
        $espacement = $this->getEspacementPiecesM2();
        if (null === $espacement || !$this->peutEtreEnPiecesAuM2()) {
            return null;
        }

        $calcul = PiecesParMetreCarre::pourDimensionsEnMetres($this->dimensionPiecesM2($this->largeur), $this->dimensionPiecesM2($this->longueur), $espacement);
        if (null === $calcul || $calcul['tropGrande']) {
            return null;
        }

        return $calcul + ['surface' => PiecesParMetreCarre::surface($this->quantite, $calcul['parM2'])];
    }

    public function isEnPiecesAuM2(): bool
    {
        return null !== $this->getPiecesAuM2();
    }

    /** Surface facturée en pièces au m², ou null (calcul au m² habituel). */
    private function surfacePiecesAuM2(): ?float
    {
        return $this->getPiecesAuM2()['surface'] ?? null;
    }

    private function dimensionPiecesM2(?string $metres): ?float
    {
        return null !== $metres && is_numeric($metres) ? (float) $metres : null;
    }
}
