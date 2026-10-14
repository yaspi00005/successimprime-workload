<?php

namespace App\Service\Site;

use App\Entity\ImageSite;
use App\Entity\Realisation;

/**
 * Avant / après d'une réalisation (docs/atelier-3d.md, animations du site).
 *
 * Pas de réglage : dans l'admin, il suffit que le texte alternatif d'une
 * photo commence par « Avant » et celui d'une autre par « Après » (avec ou
 * sans accent, en majuscules ou non). La page Réalisations montre alors les
 * deux photos superposées avec un curseur, et l'accueil la plus récente
 * de ces paires. Les autres photos restent affichées comme d'habitude.
 */
final class AvantApres
{
    /**
     * @param iterable<ImageSite> $images dans l'ordre de l'admin
     *
     * @return array{avant: ImageSite, apres: ImageSite}|null
     */
    public static function paire(iterable $images): ?array
    {
        $avant = $apres = null;
        foreach ($images as $image) {
            $texte = self::sansAccents(mb_strtolower(trim((string) $image->getTexteAlternatif())));
            if (null === $avant && preg_match('/^avant\b/u', $texte)) {
                $avant = $image;
            } elseif (null === $apres && preg_match('/^apres\b/u', $texte)) {
                $apres = $image;
            }
        }

        return null !== $avant && null !== $apres ? ['avant' => $avant, 'apres' => $apres] : null;
    }

    /**
     * La réalisation publiée la plus récente qui a sa paire.
     *
     * @param iterable<Realisation> $realisations de la plus récente à la plus ancienne
     *
     * @return array{realisation: Realisation, avant: ImageSite, apres: ImageSite}|null
     */
    public static function plusRecente(iterable $realisations): ?array
    {
        foreach ($realisations as $r) {
            $paire = self::paire($r->getImages());
            if (null !== $paire) {
                return ['realisation' => $r] + $paire;
            }
        }

        return null;
    }

    private static function sansAccents(string $texte): string
    {
        return strtr($texte, ['à' => 'a', 'â' => 'a', 'è' => 'e', 'é' => 'e', 'ê' => 'e']);
    }
}
