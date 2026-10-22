<?php

namespace App\Service\DossiersClients;

/**
 * Rapprochement entre les clients de l'ERP et les dossiers déjà rangés
 * dans le dossier Clients de l'atelier (maquette DS02).
 *
 * 1. Le code du client au début ou dans le nom du dossier (« C-0087 Hôtel
 *    Niger ») : sûr, 100 %.
 * 2. Sinon la ressemblance des noms, sans accents ni casse, sans les mots
 *    d'entreprise (SARL, Ets, Société…) ni les années : « AWA MODE - Awa
 *    Diarra » pour Awa Diarra, « Pharma Fleuve » pour Pharmacie du Fleuve.
 *    Le score dit quelle part du nom du client se retrouve dans le dossier
 *    (70 %) et quelle part du dossier est le nom du client (30 %).
 *
 * Classe pure, testée sans base.
 */
final class Rapprochement
{
    /** En dessous, aucun dossier n'est proposé (« Introuvable »). */
    public const SEUIL_SUGGESTION = 60;

    public const LIE = 'lie';
    public const SUGGERE = 'suggere';
    public const INTROUVABLE = 'introuvable';

    /** Mots qui ne disent rien du client : formes de société, liaisons. */
    private const MOTS_VIDES = [
        'sarl', 'sa', 'sas', 'sasu', 'suarl', 'snc', 'gie', 'ets', 'etablissement', 'etablissements', 'ste', 'societe', 'cie', 'compagnie',
        'groupe', 'group', 'entreprise', 'entreprises', 'et', 'de', 'du', 'des', 'la', 'le', 'les', 'l', 'd', 'au', 'aux', 'en', 'chez', 'mali', 'bamako',
        'client', 'clients', 'dossier', 'fils', 'freres',
    ];

    public static function sansAccents(string $texte): string
    {
        if (\function_exists('transliterator_transliterate')) {
            $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $texte);
            if (false !== $t) {
                return $t;
            }
        }

        return strtr($texte, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'É' => 'E', 'È' => 'E', 'À' => 'A', 'Ç' => 'C']);
    }

    /**
     * Mots significatifs d'un nom : minuscules, sans accents, sans mots
     * vides ni nombres (« École Les Étoiles 2025 » → ecole, etoiles).
     *
     * @return list<string>
     */
    public static function mots(string $nom): array
    {
        $texte = preg_replace('/[^a-z0-9]+/', ' ', self::sansAccents(mb_strtolower($nom))) ?? '';
        $mots = [];
        foreach (explode(' ', $texte) as $m) {
            if ('' === $m || ctype_digit($m) || \in_array($m, self::MOTS_VIDES, true)) {
                continue;
            }
            $mots[$m] = true;
        }

        return array_keys($mots);
    }

    /** Le code du client figure-t-il dans le nom, seul (pas collé à un autre chiffre ou lettre) ? */
    public static function contientCode(string $nom, string $code): bool
    {
        $code = trim($code);
        if ('' === $code) {
            return false;
        }

        return 1 === preg_match('/(?<![A-Za-z0-9])'.preg_quote($code, '/').'(?![A-Za-z0-9])/i', $nom);
    }

    /**
     * Ressemblance de 0 à 100 entre le nom d'un client et un nom de dossier.
     */
    public static function score(string $nomClient, string $nomDossier): int
    {
        $a = self::mots($nomClient);
        $b = self::mots($nomDossier);
        if ([] === $a || [] === $b) {
            return 0;
        }
        $trouve = 0.0;
        $utilises = [];
        foreach ($a as $mot) {
            $meilleur = 0.0;
            $indice = null;
            foreach ($b as $i => $autre) {
                $s = self::ressemblanceMot($mot, $autre);
                if ($s > $meilleur) {
                    $meilleur = $s;
                    $indice = $i;
                }
            }
            if (null !== $indice) {
                $utilises[$indice] = true;
            }
            $trouve += $meilleur;
        }
        $couverture = $trouve / \count($a);
        $precision = $trouve / max(\count($b), \count($utilises));

        return (int) round(100 * (0.7 * $couverture + 0.3 * $precision));
    }

    /**
     * Part des mots du client qu'on retrouve dans un texte (nom de fichier),
     * de 0 à 1 : sert à deviner le client d'un fichier « à classer ».
     */
    public static function couverture(string $nomClient, string $texte): float
    {
        $a = self::mots($nomClient);
        $b = self::mots($texte);
        if ([] === $a || [] === $b) {
            return 0.0;
        }
        $trouve = 0.0;
        foreach ($a as $mot) {
            $meilleur = 0.0;
            foreach ($b as $autre) {
                $meilleur = max($meilleur, self::ressemblanceMot($mot, $autre));
            }
            $trouve += $meilleur >= 0.85 ? 1 : 0;
        }

        return $trouve / \count($a);
    }

    /** 1 pour le même mot, 0,85 pour un début de mot (« pharma »), sinon selon les lettres en commun. */
    public static function ressemblanceMot(string $a, string $b): float
    {
        if ($a === $b) {
            return 1.0;
        }
        $court = \strlen($a) <= \strlen($b) ? $a : $b;
        $long = $court === $a ? $b : $a;
        if (\strlen($court) >= 4 && str_starts_with($long, $court)) {
            return 0.85;
        }
        if (\strlen($court) < 4) {
            return 0.0;
        }
        $s = 1 - levenshtein($a, $b) / max(\strlen($a), \strlen($b));

        return $s >= 0.75 ? round($s, 2) : 0.0;
    }

    /**
     * Le meilleur dossier pour chaque client, un dossier n'allant qu'à un
     * seul client (le plus ressemblant d'abord). Les clients déjà liés et
     * les dossiers déjà pris sont laissés de côté par l'appelant.
     *
     * @param array<int|string, array{code: string, noms: list<string>}> $clients clé => code et noms (nom complet, raison sociale, prénom nom)
     * @param list<string>                                               $dossiers noms des dossiers libres
     *
     * @return array<int|string, array{dossier: ?string, score: int, statut: string}>
     */
    public static function proposer(array $clients, array $dossiers): array
    {
        $resultat = [];
        $pris = [];
        // 1. Le code dans le nom : lié d'office.
        foreach ($clients as $cle => $c) {
            foreach ($dossiers as $d) {
                if (!isset($pris[$d]) && self::contientCode($d, $c['code'])) {
                    $resultat[$cle] = ['dossier' => $d, 'score' => 100, 'statut' => self::LIE];
                    $pris[$d] = true;
                    break;
                }
            }
        }
        // 2. Les ressemblances, de la plus forte à la plus faible.
        $paires = [];
        $motsDossiers = [];
        foreach ($dossiers as $d) {
            if (!isset($pris[$d])) {
                $motsDossiers[$d] = self::mots($d);
            }
        }
        foreach ($clients as $cle => $c) {
            if (isset($resultat[$cle])) {
                continue;
            }
            $motsClient = [];
            foreach ($c['noms'] as $n) {
                $motsClient = [...$motsClient, ...self::mots($n)];
            }
            foreach ($motsDossiers as $d => $motsD) {
                // Pré-filtre : au moins un mot en commun ou un début de mot.
                if (!self::unMotProche($motsClient, $motsD)) {
                    continue;
                }
                $score = 0;
                foreach ($c['noms'] as $n) {
                    $score = max($score, self::score($n, (string) $d));
                }
                if ($score >= self::SEUIL_SUGGESTION) {
                    $paires[] = [$score, $cle, (string) $d];
                }
            }
        }
        usort($paires, static fn (array $x, array $y): int => [$y[0], (string) $x[1], $x[2]] <=> [$x[0], (string) $y[1], $y[2]]);
        foreach ($paires as [$score, $cle, $d]) {
            if (isset($resultat[$cle]) || isset($pris[$d])) {
                continue;
            }
            $resultat[$cle] = ['dossier' => $d, 'score' => $score, 'statut' => self::SUGGERE];
            $pris[$d] = true;
        }
        foreach ($clients as $cle => $c) {
            $resultat[$cle] ??= ['dossier' => null, 'score' => 0, 'statut' => self::INTROUVABLE];
        }

        return $resultat;
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private static function unMotProche(array $a, array $b): bool
    {
        foreach ($a as $x) {
            foreach ($b as $y) {
                if (self::ressemblanceMot($x, $y) > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
