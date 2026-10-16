<?php

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * « Garder le total » (décision de Yaya, 15/10/2026) : une ligne de
 * commande ou de devis dont la remise a été convertie par la bascule
 * (pourcentage → F par unité, docs/migration-de-base.md) et dont le
 * nouveau calcul ne redonne pas exactement le total enregistré est
 * marquée total_fige par app:bascule:verifier --corriger.
 *
 * Elle garde son total enregistré quand on la réenregistre sans rien
 * changer. Dès que change ce qui fait le total (quantité, prix, remise,
 * remise de promotion ou de code promo, TVA, mode de calcul, dimensions,
 * finitions), elle est recalculée et redevient une ligne ordinaire.
 *
 * La classe qui l'utilise porte #[ORM\HasLifecycleCallbacks] et appelle
 * garderTotalFige() en tête de calculerTotaux().
 */
trait TotalFigeTrait
{
    #[ORM\Column(options: ['default' => false])]
    private bool $totalFige = false;

    /** Ce qui fait le total, tel que lu en base (ligne figée seulement, jamais enregistré). */
    private ?string $calculAuChargement = null;

    /**
     * Totaux HT et TTC lus en base : ce sont eux qui restent, pas ceux
     * qu'un formulaire a pu renvoyer (aperçu calculé par la page).
     *
     * @var array{int, int}|null
     */
    private ?array $totauxAuChargement = null;

    public function isTotalFige(): bool
    {
        return $this->totalFige;
    }

    /**
     * Fige le total tel qu'il est (ligne de devis figée reprise dans une
     * commande, essais). La bascule, elle, écrit la colonne directement.
     */
    public function figerTotal(): static
    {
        $this->totalFige = true;
        $this->calculAuChargement = $this->empreinteCalcul();
        $this->totauxAuChargement = [$this->totalHt, $this->totalTtc];

        return $this;
    }

    #[ORM\PostLoad]
    public function memoriserCalculFige(): void
    {
        $this->calculAuChargement = $this->totalFige ? $this->empreinteCalcul() : null;
        $this->totauxAuChargement = $this->totalFige ? [$this->totalHt, $this->totalTtc] : null;
    }

    /**
     * Vrai si la ligne est figée et que rien de ce qui fait son total n'a
     * changé depuis sa lecture : le total enregistré reste (remis tel qu'il
     * a été lu). Sinon, la ligne redevient ordinaire (le total est
     * recalculé).
     */
    private function garderTotalFige(): bool
    {
        if ($this->totalFige && null !== $this->totauxAuChargement && $this->calculAuChargement === $this->empreinteCalcul()) {
            [$this->totalHt, $this->totalTtc] = $this->totauxAuChargement;

            return true;
        }

        $this->totalFige = false;
        $this->calculAuChargement = null;
        $this->totauxAuChargement = null;

        return false;
    }

    /**
     * Tout ce dont calculerTotaux() se sert, ramené à une forme stable
     * (« 1.5 » et « 1.5000 » sont la même largeur). Au m², une ligne
     * produit refait sa surface depuis la largeur et la longueur : ce sont
     * elles qui comptent.
     */
    private function empreinteCalcul(): string
    {
        $nombre = static fn (?string $valeur): ?string => null === $valeur || '' === trim($valeur)
            ? null
            : number_format((float) $valeur, 4, '.', '');

        $dimensions = match ($this->modeCalcul) {
            'metre_carre' => self::TYPE_PRODUIT === $this->typeLigne
                ? [$nombre($this->largeur), $nombre($this->longueur)]
                : [$nombre($this->surface)],
            'metre' => [$nombre($this->longueur)],
            'face' => [max(1, $this->nombreFaces)],
            default => [],
        };

        $finitions = [];
        foreach ($this->finitions as $finition) {
            $finitions[] = [
                $finition->getFinition()?->getId(),
                $finition->getPrixApplique(),
                $finition->getModeCalcul(),
                $finition->getQuantite(),
                max(0, $finition->getMontant()),
            ];
        }
        sort($finitions);

        return (string) json_encode([
            $this->typeLigne,
            $this->modeCalcul,
            $this->quantite,
            $nombre($this->prixUnitaire),
            $this->remise,
            $this->remisePromotion,
            $this->remiseCodePromo,
            $this->tva,
            $dimensions,
            $finitions,
        ]);
    }
}
