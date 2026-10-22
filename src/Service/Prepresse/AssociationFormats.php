<?php

namespace App\Service\Prepresse;

/**
 * Dépôt groupé (maquette PQ02, docs/prepresse.md) : chaque fichier déposé
 * est associé à un format de la commande, dans cet ordre :
 *
 * 1. le nom du fichier (« fofana_7,5×8.pdf » → 7,5 × 8 cm ; virgule ou
 *    point, « x » ou « × », cm par défaut, mm ou m si écrit) ;
 * 2. les dimensions lues dans le fichier : pixels et résolution d'une
 *    image, page (TrimBox, sinon MediaBox) d'un PDF ; fonds perdus de
 *    3 mm admis, PDF à l'échelle 1/10 admis pour les grands formats ;
 * 3. les proportions proches (écart de 3 % au plus) : « à vérifier » ;
 *    une image carrée va au seul format carré de la commande ;
 * 4. sinon « à choisir ».
 *
 * Un format déjà pris par un fichier sûr n'est proposé par les
 * proportions que s'il ne reste rien d'autre. Classe pure : le
 * navigateur montre le résultat, le serveur décide à l'enregistrement.
 */
final class AssociationFormats
{
    public const NOM = 'nom';
    public const DIMENSIONS = 'dimensions';
    public const PROPORTIONS = 'proportions';
    public const CARREE = 'carree';
    public const A_CHOISIR = 'choisir';

    public const LIBELLES = [
        self::NOM => 'nom du fichier',
        self::DIMENSIONS => 'dimensions de l\'image',
        self::PROPORTIONS => 'proportions proches',
        self::CARREE => 'image carrée',
        self::A_CHOISIR => 'à choisir',
    ];

    public const SUR = 'sur';
    public const VERIFIER = 'verifier';
    public const CHOISIR = 'choisir';

    /** Écart admis sur un côté lu dans le nom (arrondi d'écriture), en mm. */
    private const TOLERANCE_NOM_MM = 0.6;

    /** Écart admis sur un côté mesuré dans le fichier : 1,5 mm, ou 2 % sur un grand format. */
    private const TOLERANCE_MM = 1.5;
    private const TOLERANCE_RELATIVE = 0.02;

    /** Fonds perdus ajoutés autour du format fini, en mm par côté. */
    private const FONDS_PERDUS_MM = 3.0;

    /** Écart de proportions admis (« à vérifier »). */
    private const ECART_PROPORTIONS = 0.03;

    /** Une image est carrée à 1 % près. */
    private const ECART_CARRE = 0.01;

    /** Sous cette résolution (72, 96 dpi d'un écran ou d'un téléphone), elle ne dit rien de la taille d'impression. */
    public const DPI_MINIMUM = 100;

    /**
     * @param list<array{id: int|string, largeurMm: float, hauteurMm: float, aFichier?: bool}>                                                                         $formats
     * @param list<array{cle: string, nom: string, largeurPx?: ?int, hauteurPx?: ?int, dpi?: ?float, largeurMm?: ?float, hauteurMm?: ?float, vectoriel?: bool, photo?: bool}> $fichiers
     *
     * @return array{fichiers: array<string, array{format: int|string|null, methode: string, certitude: string, libelle: string, dimensions: string}>, resume: array{sur: int, verifier: int, choisir: int}}
     */
    public static function associer(array $formats, array $fichiers): array
    {
        $formats = array_values(array_filter($formats, static fn (array $f): bool => $f['largeurMm'] > 0 && $f['hauteurMm'] > 0));
        $resultats = [];
        $pris = [];

        // 1 et 2 : nom puis dimensions, les associations sûres d'abord.
        foreach ($fichiers as $fichier) {
            $format = self::parNom($formats, $fichier['nom']);
            $methode = self::NOM;
            if (null === $format && null !== ($mm = self::millimetres($fichier))) {
                $format = self::parDimensions($formats, $mm[0], $mm[1]);
                $methode = self::DIMENSIONS;
            }
            if (null !== $format) {
                $resultats[$fichier['cle']] = self::resultat($format['id'], $methode, self::SUR, $fichier);
                $pris[(string) $format['id']] = true;
            }
        }

        // 3 et 4 : proportions pour les autres, en évitant les formats déjà pris.
        foreach ($fichiers as $fichier) {
            if (isset($resultats[$fichier['cle']])) {
                continue;
            }
            $rapport = self::rapport($fichier);
            $choix = null === $rapport ? null : self::parProportions($formats, $rapport, $pris);
            if (null === $choix) {
                $resultats[$fichier['cle']] = self::resultat(null, self::A_CHOISIR, self::CHOISIR, $fichier);
                continue;
            }
            [$format, $carree] = $choix;
            $resultats[$fichier['cle']] = self::resultat($format['id'], $carree ? self::CARREE : self::PROPORTIONS, $carree ? self::SUR : self::VERIFIER, $fichier);
            $pris[(string) $format['id']] = true;
        }

        $resume = ['sur' => 0, 'verifier' => 0, 'choisir' => 0];
        foreach ($resultats as $r) {
            ++$resume[$r['certitude']];
        }

        return ['fichiers' => $resultats, 'resume' => $resume];
    }

    /**
     * Dimensions écrites dans un nom de fichier, en mm (largeur, hauteur) :
     * « 7,5×8 » (cm), « 55x70mm », « 1.2x3m ». Tous les couples trouvés.
     *
     * @return list<array{0: float, 1: float}>
     */
    public static function dimensionsDansNom(string $nom): array
    {
        $base = (string) preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', $nom);
        $base = str_replace(["\u{00A0}", "\u{202F}"], ' ', $base);
        if (!preg_match_all('/(?<![\d.,])(\d{1,4}(?:[.,]\d{1,2})?)\s*(?:cm|mm)?\s*[x×X*]\s*(\d{1,4}(?:[.,]\d{1,2})?)\s*(mm|cm|m)?(?![\d])/u', $base, $trouves, \PREG_SET_ORDER)) {
            return [];
        }
        $couples = [];
        foreach ($trouves as $t) {
            $facteur = match (strtolower($t[3] ?? '')) {
                'mm' => 1.0,
                'm' => 1000.0,
                default => 10.0,
            };
            $a = (float) str_replace(',', '.', $t[1]) * $facteur;
            $b = (float) str_replace(',', '.', $t[2]) * $facteur;
            if ($a > 0 && $b > 0) {
                $couples[] = [$a, $b];
            }
        }

        return $couples;
    }

    /** « 7,5 × 8 cm » : le libellé d'un format, en cm. */
    public static function libelleFormat(float $largeurMm, float $hauteurMm): string
    {
        return self::nombre($largeurMm / 10).' × '.self::nombre($hauteurMm / 10).' cm';
    }

    /**
     * Ce qu'on montre du fichier : « 75 × 80 mm · vectoriel »,
     * « 650 × 827 px · 300 dpi », « 4 032 × 3 024 px · photo ».
     *
     * @param array{largeurPx?: ?int, hauteurPx?: ?int, dpi?: ?float, largeurMm?: ?float, hauteurMm?: ?float, vectoriel?: bool, photo?: bool} $fichier
     */
    public static function dimensionsTexte(array $fichier): string
    {
        if (!empty($fichier['vectoriel'])) {
            $mm = ($fichier['largeurMm'] ?? 0) > 0 && ($fichier['hauteurMm'] ?? 0) > 0
                ? self::nombre((float) $fichier['largeurMm'], 0).' × '.self::nombre((float) $fichier['hauteurMm'], 0).' mm · '
                : '';

            return $mm.'vectoriel';
        }
        if (($fichier['largeurPx'] ?? 0) > 0 && ($fichier['hauteurPx'] ?? 0) > 0) {
            $px = number_format((int) $fichier['largeurPx'], 0, ',', ' ').' × '.number_format((int) $fichier['hauteurPx'], 0, ',', ' ').' px';
            if (($fichier['dpi'] ?? 0) >= self::DPI_MINIMUM) {
                return $px.' · '.(int) round((float) $fichier['dpi']).' dpi';
            }

            return $px.(!empty($fichier['photo']) ? ' · photo' : '');
        }

        return 'dimensions inconnues';
    }

    /**
     * @param list<array{id: int|string, largeurMm: float, hauteurMm: float, aFichier?: bool}> $formats
     *
     * @return array{id: int|string, largeurMm: float, hauteurMm: float, aFichier?: bool}|null
     */
    private static function parNom(array $formats, string $nom): ?array
    {
        foreach (self::dimensionsDansNom($nom) as [$a, $b]) {
            $trouves = array_values(array_filter($formats, static fn (array $f): bool => self::memesCotes($a, $b, $f['largeurMm'], $f['hauteurMm'], static fn (float $x, float $y): bool => abs($x - $y) <= self::TOLERANCE_NOM_MM)));
            if ([] !== $trouves) {
                return self::prefererSansFichier($trouves);
            }
        }

        return null;
    }

    /**
     * @param list<array{id: int|string, largeurMm: float, hauteurMm: float, aFichier?: bool}> $formats
     *
     * @return array{id: int|string, largeurMm: float, hauteurMm: float, aFichier?: bool}|null
     */
    private static function parDimensions(array $formats, float $largeur, float $hauteur): ?array
    {
        $proche = static fn (float $mesure, float $attendu): bool => abs($mesure - $attendu) <= max(self::TOLERANCE_MM, $attendu * self::TOLERANCE_RELATIVE);
        foreach ([1.0, 10.0] as $echelle) {
            $trouves = array_values(array_filter($formats, static function (array $f) use ($largeur, $hauteur, $echelle, $proche): bool {
                $l = $largeur * $echelle;
                $h = $hauteur * $echelle;
                // Format fini, ou format avec ses fonds perdus (3 mm de chaque côté).
                return self::memesCotes($l, $h, $f['largeurMm'], $f['hauteurMm'], $proche)
                    || self::memesCotes($l, $h, $f['largeurMm'] + 2 * self::FONDS_PERDUS_MM, $f['hauteurMm'] + 2 * self::FONDS_PERDUS_MM, $proche);
            }));
            // L'échelle 1/10 ne vaut que pour un grand format (comme AnalyseFichierImpression).
            $trouves = 10.0 === $echelle ? array_values(array_filter($trouves, static fn (array $f): bool => max($f['largeurMm'], $f['hauteurMm']) >= 500)) : $trouves;
            if ([] !== $trouves) {
                return self::prefererSansFichier($trouves);
            }
        }

        return null;
    }

    /**
     * @param list<array{id: int|string, largeurMm: float, hauteurMm: float, aFichier?: bool}> $formats
     * @param array<string, true>                                                              $pris
     *
     * @return array{0: array{id: int|string, largeurMm: float, hauteurMm: float, aFichier?: bool}, 1: bool}|null le format et « image carrée »
     */
    private static function parProportions(array $formats, float $rapport, array $pris): ?array
    {
        $carres = array_values(array_filter($formats, static fn (array $f): bool => abs(self::rapportDe($f['largeurMm'], $f['hauteurMm']) - 1) <= self::ECART_CARRE));
        if (abs($rapport - 1) <= self::ECART_CARRE) {
            // Un seul format carré, ou un seul encore libre (les autres ont leur fichier) : c'est lui.
            $libres = array_values(array_filter($carres, static fn (array $f): bool => !isset($pris[(string) $f['id']]) && empty($f['aFichier'])));
            if (1 === \count($carres) || 1 === \count($libres)) {
                return [1 === \count($carres) ? $carres[0] : $libres[0], true];
            }
        }
        $candidats = [];
        foreach ($formats as $f) {
            $ecart = abs($rapport - self::rapportDe($f['largeurMm'], $f['hauteurMm'])) / self::rapportDe($f['largeurMm'], $f['hauteurMm']);
            if ($ecart <= self::ECART_PROPORTIONS) {
                $candidats[] = ['format' => $f, 'ecart' => $ecart, 'pris' => isset($pris[(string) $f['id']]), 'fichier' => !empty($f['aFichier'])];
            }
        }
        if ([] === $candidats) {
            return null;
        }
        // Libre d'abord, puis sans fichier reçu, puis le plus proche.
        usort($candidats, static fn (array $a, array $b): int => [$a['pris'], $a['fichier'], $a['ecart']] <=> [$b['pris'], $b['fichier'], $b['ecart']]);

        return [$candidats[0]['format'], false];
    }

    /**
     * @param array{largeurPx?: ?int, hauteurPx?: ?int, dpi?: ?float, largeurMm?: ?float, hauteurMm?: ?float} $fichier
     *
     * @return array{0: float, 1: float}|null
     */
    private static function millimetres(array $fichier): ?array
    {
        if (($fichier['largeurMm'] ?? 0) > 0 && ($fichier['hauteurMm'] ?? 0) > 0) {
            return [(float) $fichier['largeurMm'], (float) $fichier['hauteurMm']];
        }
        if (($fichier['largeurPx'] ?? 0) > 0 && ($fichier['hauteurPx'] ?? 0) > 0 && ($fichier['dpi'] ?? 0) >= self::DPI_MINIMUM) {
            $dpi = (float) $fichier['dpi'];

            return [(int) $fichier['largeurPx'] / $dpi * 25.4, (int) $fichier['hauteurPx'] / $dpi * 25.4];
        }

        return null;
    }

    /** @param array{largeurPx?: ?int, hauteurPx?: ?int, largeurMm?: ?float, hauteurMm?: ?float} $fichier */
    private static function rapport(array $fichier): ?float
    {
        if (($fichier['largeurMm'] ?? 0) > 0 && ($fichier['hauteurMm'] ?? 0) > 0) {
            return self::rapportDe((float) $fichier['largeurMm'], (float) $fichier['hauteurMm']);
        }
        if (($fichier['largeurPx'] ?? 0) > 0 && ($fichier['hauteurPx'] ?? 0) > 0) {
            return self::rapportDe((float) $fichier['largeurPx'], (float) $fichier['hauteurPx']);
        }

        return null;
    }

    /** Côté long sur côté court : le fichier peut être tourné. */
    private static function rapportDe(float $a, float $b): float
    {
        return max($a, $b) / min($a, $b);
    }

    private static function memesCotes(float $a, float $b, float $l, float $h, callable $proche): bool
    {
        return ($proche($a, $l) && $proche($b, $h)) || ($proche($a, $h) && $proche($b, $l));
    }

    /**
     * Deux formats de mêmes dimensions (deux lignes) : celui qui attend
     * encore son fichier d'abord.
     *
     * @param non-empty-list<array{id: int|string, largeurMm: float, hauteurMm: float, aFichier?: bool}> $formats
     *
     * @return array{id: int|string, largeurMm: float, hauteurMm: float, aFichier?: bool}
     */
    private static function prefererSansFichier(array $formats): array
    {
        foreach ($formats as $f) {
            if (empty($f['aFichier'])) {
                return $f;
            }
        }

        return $formats[0];
    }

    /**
     * @param array{nom: string, largeurPx?: ?int, hauteurPx?: ?int, dpi?: ?float, largeurMm?: ?float, hauteurMm?: ?float, vectoriel?: bool, photo?: bool} $fichier
     *
     * @return array{format: int|string|null, methode: string, certitude: string, libelle: string, dimensions: string}
     */
    private static function resultat(int|string|null $format, string $methode, string $certitude, array $fichier): array
    {
        return ['format' => $format, 'methode' => $methode, 'certitude' => $certitude, 'libelle' => self::LIBELLES[$methode], 'dimensions' => self::dimensionsTexte($fichier)];
    }

    private static function nombre(float $valeur, int $decimales = 2): string
    {
        $texte = number_format($valeur, $decimales, ',', ' ');

        return $decimales > 0 ? rtrim(rtrim($texte, '0'), ',') : $texte;
    }
}
