<?php

namespace App\Service\Prepresse;

/**
 * Calculs de la retouche prépresse (docs/prepresse-retouche.md), les mêmes
 * que l'aperçu du navigateur (public/js/retouche-image.js) : où l'image se
 * pose sur le format fini, la résolution réelle à l'impression, la part
 * coupée ou déformée, et les contrôles qui bloquent la validation.
 *
 * Unités : millimètres pour le papier, pixels pour les fichiers.
 */
final class GeometrieRetouche
{
    public const ADAPTER = 'adapter';
    public const RECADRER = 'recadrer';
    public const ETIRER = 'etirer';
    public const MODES = [self::ADAPTER, self::RECADRER, self::ETIRER];

    /** Fond perdu ajouté autour quand il est demandé. */
    public const FOND_PERDU_MM = 5.0;

    /** Au-delà, c'est du grand format (vu de loin) : 72 dpi suffisent. */
    public const PETIT_FORMAT_MAX_MM = 420.0;
    public const DPI_MIN_GRAND_FORMAT = 72;
    public const DPI_PETIT_FORMAT = 300;
    /** En petit format, sous ce seuil l'image est floue : bloquant. Entre les deux : attention. */
    public const DPI_MIN_PETIT_FORMAT = 200;

    /** Écart de proportions toléré avant de parler de déformation. */
    public const DEFORMATION_TOLEREE = 0.02;

    /** Plus grand fichier de sortie accepté (pixels) : au-delà, baisser la résolution de sortie. */
    public const SORTIE_MAX_PX = 150_000_000;

    public const DIMENSION_MAX_MM = 20_000.0;
    public const DPI_SORTIE_MIN = 30;
    public const DPI_SORTIE_MAX = 1200;

    /**
     * Placement de l'image dans le fichier de sortie, en mm depuis le coin
     * haut gauche du fichier (fond perdu compris), et partie de l'image
     * source utilisée (pixels).
     *
     * @return array{
     *     sortieL: float, sortieH: float, fondPerdu: float,
     *     dest: array{x: float, y: float, l: float, h: float},
     *     source: array{x: float, y: float, l: float, h: float},
     *     sortiePx: array{l: int, h: int}
     * }
     */
    public static function placement(int $srcL, int $srcH, float $largeurMm, float $hauteurMm, string $mode, bool $fondPerdu, int $dpiSortie): array
    {
        $b = $fondPerdu ? self::FOND_PERDU_MM : 0.0;
        $sortieL = $largeurMm + 2 * $b;
        $sortieH = $hauteurMm + 2 * $b;
        $source = ['x' => 0.0, 'y' => 0.0, 'l' => (float) $srcL, 'h' => (float) $srcH];

        if (self::ADAPTER === $mode) {
            // Image entière dans le format fini ; les marges prennent la couleur de fond.
            $e = min($largeurMm / $srcL, $hauteurMm / $srcH);
            $l = $srcL * $e;
            $h = $srcH * $e;
            $dest = ['x' => $b + ($largeurMm - $l) / 2, 'y' => $b + ($hauteurMm - $h) / 2, 'l' => $l, 'h' => $h];
        } elseif (self::ETIRER === $mode) {
            $dest = ['x' => 0.0, 'y' => 0.0, 'l' => $sortieL, 'h' => $sortieH];
        } else {
            // Recadrer : remplit tout, fond perdu compris ; le surplus est coupé, centré.
            $e = max($sortieL / $srcL, $sortieH / $srcH);
            $sl = $sortieL / $e;
            $sh = $sortieH / $e;
            $source = ['x' => ($srcL - $sl) / 2, 'y' => ($srcH - $sh) / 2, 'l' => $sl, 'h' => $sh];
            $dest = ['x' => 0.0, 'y' => 0.0, 'l' => $sortieL, 'h' => $sortieH];
        }

        return [
            'sortieL' => $sortieL,
            'sortieH' => $sortieH,
            'fondPerdu' => $b,
            'dest' => $dest,
            'source' => $source,
            'sortiePx' => ['l' => self::px($sortieL, $dpiSortie), 'h' => self::px($sortieH, $dpiSortie)],
        ];
    }

    /** Échelles de livraison reconnues : un grand format arrive souvent au 1/10, parfois au 1/5, 1/4 ou 1/2. */
    public const ECHELLES = [10, 5, 4, 2];
    /** Écart toléré entre le fichier agrandi et la taille commandée pour reconnaître une échelle. */
    public const ECHELLE_TOLEREE = 0.04;
    /** Résolution supposée d'une image qui n'en déclare pas (celle des logiciels de dessin par défaut). */
    public const DPI_SANS_DECLARATION = 72;

    /**
     * Taille physique du fichier reçu (pixels ÷ résolution déclarée), en mm.
     *
     * @return array{largeurMm: float, hauteurMm: float, dpi: int, declare: bool}
     */
    public static function tailleRecue(int $largeurPx, int $hauteurPx, ?int $dpi): array
    {
        $declare = null !== $dpi && $dpi > 0;
        $d = $declare ? $dpi : self::DPI_SANS_DECLARATION;

        return [
            'largeurMm' => round($largeurPx / $d * 25.4, 1),
            'hauteurMm' => round($hauteurPx / $d * 25.4, 1),
            'dpi' => $d,
            'declare' => $declare,
        ];
    }

    /**
     * Échelle du fichier reçu par rapport à la taille commandée (10 pour
     * un fichier au 1/10 : 12 × 20 cm reçu pour 120 × 200 cm commandé),
     * dans un sens ou tourné. null si aucune échelle connue ne colle.
     */
    public static function echelleDetectee(float $recuL, float $recuH, ?float $commandeL, ?float $commandeH): ?int
    {
        if ($recuL <= 0 || $recuH <= 0 || null === $commandeL || null === $commandeH || $commandeL <= 0 || $commandeH <= 0) {
            return null;
        }
        $proche = static fn (float $a, float $b): bool => abs($a - $b) <= $b * self::ECHELLE_TOLEREE;
        foreach (self::ECHELLES as $k) {
            if (($proche($recuL * $k, $commandeL) && $proche($recuH * $k, $commandeH))
                || ($proche($recuL * $k, $commandeH) && $proche($recuH * $k, $commandeL))) {
                return $k;
            }
        }

        return null;
    }

    public static function px(float $mm, int $dpi): int
    {
        return max(1, (int) round($mm / 25.4 * $dpi));
    }

    public static function mmEnPx(float $mm, float $dpi): float
    {
        return $mm / 25.4 * $dpi;
    }

    /**
     * Résolution réelle à l'impression : pixels d'origine par pouce posé
     * sur le papier. Agrandir à la sortie n'y change rien.
     *
     * @param array{dest: array{l: float, h: float}, source: array{l: float, h: float}} $placement
     */
    public static function dpiEffectif(array $placement): int
    {
        $d = $placement['dest'];
        $s = $placement['source'];

        return (int) round(min($s['l'] / ($d['l'] / 25.4), $s['h'] / ($d['h'] / 25.4)));
    }

    /**
     * Déformation en mode « étirer » : + quand la largeur est étirée par
     * rapport à la hauteur, - quand c'est la hauteur. En %, arrondi.
     */
    public static function deformation(int $srcL, int $srcH, float $sortieL, float $sortieH): int
    {
        $r = ($sortieL / $sortieH) / ($srcL / $srcH);

        return (int) round(($r >= 1 ? $r - 1 : -(1 / $r - 1)) * 100);
    }

    /**
     * Pixels coupés de chaque côté en mode « recadrer » (dans le fichier
     * d'origine), et part de l'image perdue.
     *
     * @param array{source: array{x: float, y: float, l: float, h: float}} $placement
     *
     * @return array{horizontal: int, vertical: int, pourcent: int}
     */
    public static function coupe(int $srcL, int $srcH, array $placement): array
    {
        $s = $placement['source'];
        $perdu = 1 - ($s['l'] * $s['h']) / ($srcL * $srcH);

        return ['horizontal' => (int) round($s['x']), 'vertical' => (int) round($s['y']), 'pourcent' => (int) round($perdu * 100)];
    }

    public static function grandFormat(float $largeurMm, float $hauteurMm): bool
    {
        return max($largeurMm, $hauteurMm) > self::PETIT_FORMAT_MAX_MM;
    }

    /**
     * Sortie conseillée : 300 dpi en petit format, 150 jusqu'à 2 m, 100 au-delà
     * (une bâche à 150 dpi ferait un fichier énorme sans rien gagner de loin).
     */
    public static function dpiSortieConseille(float $largeurMm, float $hauteurMm): int
    {
        $grand = max($largeurMm, $hauteurMm);

        return match (true) {
            $grand <= self::PETIT_FORMAT_MAX_MM => 300,
            $grand <= 2000 => 150,
            default => 100,
        };
    }

    /**
     * Contrôles avant validation (maquette PP04). Chaque contrôle :
     * cle, libelle, valeur, etat (ok, attention, bloquant).
     *
     * @param array<string, mixed> $reglages réglages normalisés (self::reglages)
     *
     * @return list<array{cle: string, libelle: string, valeur: string, etat: string}>
     */
    public static function controles(int $srcL, int $srcH, array $reglages): array
    {
        $p = self::placement($srcL, $srcH, $reglages['largeurMm'], $reglages['hauteurMm'], $reglages['mode'], $reglages['fondPerdu'], $reglages['dpiSortie']);
        $dpi = self::dpiEffectif($p);
        $grand = self::grandFormat($reglages['largeurMm'], $reglages['hauteurMm']);
        $taille = self::mm($reglages['largeurMm']).' × '.self::mm($reglages['hauteurMm']).' mm';
        $controles = [];

        if ($grand) {
            $etat = $dpi >= self::DPI_MIN_GRAND_FORMAT ? 'ok' : 'bloquant';
            $valeur = $dpi >= self::DPI_MIN_GRAND_FORMAT
                ? \sprintf('%d dpi à %s : suffisant pour un grand format (minimum %d dpi).', $dpi, $taille, self::DPI_MIN_GRAND_FORMAT)
                : \sprintf('%d dpi à %s : trop faible, l\'image sera floue même de loin (minimum %d dpi).', $dpi, $taille, self::DPI_MIN_GRAND_FORMAT);
        } else {
            $etat = $dpi >= self::DPI_PETIT_FORMAT ? 'ok' : ($dpi >= self::DPI_MIN_PETIT_FORMAT ? 'attention' : 'bloquant');
            $valeur = match ($etat) {
                'ok' => \sprintf('%d dpi à %s : bon pour un petit format (%d dpi conseillés).', $dpi, $taille, self::DPI_PETIT_FORMAT),
                'attention' => \sprintf('%d dpi à %s : un peu juste pour un petit format, vu de près (%d dpi conseillés).', $dpi, $taille, self::DPI_PETIT_FORMAT),
                default => \sprintf('%d dpi à %s : trop faible, l\'image sera floue (%d dpi conseillés en petit format).', $dpi, $taille, self::DPI_PETIT_FORMAT),
            };
        }
        $controles[] = ['cle' => 'resolution', 'libelle' => 'Résolution', 'valeur' => $valeur, 'etat' => $etat, 'dpi' => $dpi];

        if (self::ETIRER === $reglages['mode']) {
            $def = self::deformation($srcL, $srcH, $p['sortieL'], $p['sortieH']);
            $bloque = abs($def) > self::DEFORMATION_TOLEREE * 100;
            $controles[] = [
                'cle' => 'deformation',
                'libelle' => 'Déformation',
                'valeur' => $bloque
                    ? \sprintf('%s%d %% : %s étirée par rapport à %s. Le texte et les formes seront déformés.', $def > 0 ? '+' : '−', abs($def), $def > 0 ? 'largeur' : 'hauteur', $def > 0 ? 'la hauteur' : 'la largeur')
                    : 'Proportions presque identiques : pas de déformation visible.',
                'etat' => $bloque ? 'bloquant' : 'ok',
                'pourcent' => $def,
            ];
        } elseif (self::RECADRER === $reglages['mode']) {
            $c = self::coupe($srcL, $srcH, $p);
            $controles[] = ['cle' => 'proportions', 'libelle' => 'Proportions conservées', 'valeur' => $c['pourcent'] > 0 ? \sprintf('recadrage %d %%', $c['pourcent']) : 'rien de coupé', 'etat' => $c['pourcent'] > 25 ? 'attention' : 'ok'];
        } else {
            $controles[] = ['cle' => 'proportions', 'libelle' => 'Proportions conservées', 'valeur' => 'image entière, avec des marges', 'etat' => 'ok'];
        }

        $pixels = $p['sortiePx']['l'] * $p['sortiePx']['h'];
        if ($pixels > self::SORTIE_MAX_PX) {
            $controles[] = ['cle' => 'sortie', 'libelle' => 'Taille du fichier', 'valeur' => \sprintf('%s × %s px : trop grand pour être généré. Baissez la résolution de sortie.', self::nombre($p['sortiePx']['l']), self::nombre($p['sortiePx']['h'])), 'etat' => 'bloquant'];
        }

        return $controles;
    }

    /** @param list<array{etat: string}> $controles */
    public static function bloquants(array $controles): array
    {
        return array_values(array_filter($controles, static fn (array $c): bool => 'bloquant' === $c['etat']));
    }

    /**
     * Réglages envoyés par le navigateur, vérifiés et bornés. Lève
     * \DomainException avec un message lisible si un réglage est faux.
     *
     * @param array<string, mixed> $d
     *
     * @return array<string, mixed>
     */
    public static function reglages(array $d): array
    {
        $largeur = round((float) ($d['largeurMm'] ?? 0), 1);
        $hauteur = round((float) ($d['hauteurMm'] ?? 0), 1);
        if ($largeur < 5 || $hauteur < 5 || $largeur > self::DIMENSION_MAX_MM || $hauteur > self::DIMENSION_MAX_MM) {
            throw new \DomainException('Dimensions finales invalides (entre 5 mm et 20 m).');
        }
        $mode = \in_array($d['mode'] ?? null, self::MODES, true) ? $d['mode'] : self::RECADRER;
        $dpi = (int) ($d['dpiSortie'] ?? 0);
        if ($dpi < self::DPI_SORTIE_MIN || $dpi > self::DPI_SORTIE_MAX) {
            throw new \DomainException(\sprintf('Résolution de sortie invalide (entre %d et %d dpi).', self::DPI_SORTIE_MIN, self::DPI_SORTIE_MAX));
        }
        $c = \is_array($d['contour'] ?? null) ? $d['contour'] : [];
        $contour = null;
        if (!empty($c['actif'])) {
            $epaisseur = round((float) ($c['epaisseurMm'] ?? 0), 2);
            if ($epaisseur < 0.1 || $epaisseur > 50) {
                throw new \DomainException('Épaisseur du contour invalide (entre 0,1 et 50 mm).');
            }
            $contour = [
                'type' => 'forme' === ($c['type'] ?? null) ? 'forme' : 'rectangle',
                'couleur' => self::couleur($c['couleur'] ?? '#000000') ?? '#000000',
                'epaisseurMm' => $epaisseur,
                'retraitMm' => max(0.0, min(500.0, round((float) ($c['retraitMm'] ?? 0), 1))),
                'source' => 'auto' === ($c['source'] ?? null) ? 'auto' : 'alpha',
            ];
        }
        $fond = $d['fond'] ?? '#FFFFFF';

        return [
            'largeurMm' => $largeur,
            'hauteurMm' => $hauteur,
            'mode' => $mode,
            'dpiSortie' => $dpi,
            'fondPerdu' => !empty($d['fondPerdu']),
            // Couleur des marges en mode « adapter » : une couleur, ou transparent (PNG seulement).
            'fond' => 'transparent' === $fond ? 'transparent' : (self::couleur($fond) ?? '#FFFFFF'),
            'contour' => $contour,
        ];
    }

    /** « #1a2B3c » → « #1A2B3C », ou null. */
    public static function couleur(mixed $hex): ?string
    {
        return \is_string($hex) && 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $hex) ? strtoupper($hex) : null;
    }

    /** @return array{0: int, 1: int, 2: int} */
    public static function rvb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    public static function mm(float $v): string
    {
        return self::nombre($v, abs($v - round($v)) < 0.05 ? 0 : 1);
    }

    public static function nombre(float|int $v, int $decimales = 0): string
    {
        return number_format($v, $decimales, ',', ' ');
    }
}
