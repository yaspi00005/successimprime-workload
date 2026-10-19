<?php

namespace App\Service\DossiersClients;

/**
 * Ce que l'on devine d'un nom de fichier (maquettes DS01 et DS03) : d'où il
 * vient (WhatsApp, scanner, téléchargement), dans quel sous-dossier le
 * ranger, le nom propre qu'il prendra au classement
 * (« CM-0175_photo-facade_2026-10-19.jpg ») et la famille de versions à
 * laquelle il appartient (« kakemono_v2.ai », « kakemono v3 FINAL.ai »).
 *
 * Classe pure, testée sans base.
 */
final class NommageFichier
{
    public const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp', 'tif', 'tiff'];
    public const SOURCES = ['ai', 'psd', 'indd', 'eps', 'cdr', 'afdesign', 'sketch', 'svg', 'idml', 'psb'];

    /** Programmes : jamais « Ouvrir » depuis l'ERP (le programme du poste les refuse aussi). */
    public const PROGRAMMES = ['exe', 'com', 'bat', 'cmd', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'wsh', 'msi', 'msp', 'scr', 'hta', 'lnk', 'url', 'reg', 'cpl', 'jar', 'pif', 'dll', 'sys', 'application', 'appref-ms'];

    public static function estProgramme(string $nom): bool
    {
        return \in_array(strtolower(pathinfo($nom, \PATHINFO_EXTENSION)), self::PROGRAMMES, true);
    }

    /** Mots de version et de bruit retirés pour retrouver la famille d'un fichier. */
    private const MOTS_VERSION = ['final', 'finale', 'def', 'definitif', 'definitive', 'ok', 'valide', 'copie', 'copy', 'new', 'nouveau', 'bis', 'hd', 'bd', 'web', 'print'];

    /**
     * D'où vient un fichier déposé en vrac, d'après son nom et le
     * sous-dossier de « À classer » où il est (« Bureau », « PAO-2 »).
     *
     * @return array{type: string, libelle: string}
     */
    public static function origine(string $cheminRelatif): array
    {
        $cheminRelatif = str_replace('\\', '/', $cheminRelatif);
        $nom = basename($cheminRelatif);
        $dossier = \dirname($cheminRelatif);
        $lieu = '.' === $dossier ? '' : str_replace('/', ' · ', $dossier);
        $n = mb_strtolower($nom);
        $type = match (true) {
            str_contains($n, 'whatsapp') || 1 === preg_match('/^(img|vid|aud|doc|ptt)-\d{8}-wa\d+/i', $nom) => 'whatsapp',
            1 === preg_match('/(^|[^a-z])(scan|numeris|numéris|doc\d{3,}|scanned)/u', $n) => 'scanner',
            1 === preg_match('/^(img|dsc|dcim|pxl|photo)[_-]?\d{4}/i', $nom) => 'photo',
            1 === preg_match('/\(\d+\)\.[a-z0-9]+$|^download|telecharg|téléchar/u', $n) => 'telechargement',
            default => 'depot',
        };
        $libelle = match ($type) {
            'whatsapp' => 'WhatsApp',
            'scanner' => 'scanner',
            'photo' => 'photo du téléphone',
            'telechargement' => 'Téléchargements',
            default => '' !== $lieu ? '' : 'dépôt',
        };

        // « WhatsApp · WhatsApp Images » : le dossier dit déjà d'où vient le fichier.
        if ('' !== $libelle && '' !== $lieu && str_starts_with(Rapprochement::sansAccents(mb_strtolower($lieu)), Rapprochement::sansAccents(mb_strtolower($libelle)))) {
            $libelle = '';
        }

        return ['type' => $type, 'libelle' => trim($libelle.('' !== $lieu ? ('' !== $libelle ? ' · ' : '').$lieu : ''))];
    }

    /**
     * Le sous-dossier où ranger un fichier, d'après son type et son nom,
     * avec la raison (affichée) et la sûreté (0 à 100).
     *
     * @return array{cle: string, raison: string, surete: int}
     */
    public static function sousDossier(string $nom, string $origine = 'depot'): array
    {
        $n = Rapprochement::sansAccents(mb_strtolower($nom));
        $ext = strtolower(pathinfo($nom, \PATHINFO_EXTENSION));
        $mots = static fn (string $motif): bool => 1 === preg_match('/(^|[^a-z])('.$motif.')([^a-z]|$)/', $n);

        return match (true) {
            $mots('bat|epreuve') => ['cle' => 'bat', 'raison' => 'BAT dans le nom', 'surete' => 95],
            $mots('devis|facture|bon|contrat|signe|signee|recu|bl|commande') && \in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx'], true) => ['cle' => 'documents', 'raison' => 'document (devis, facture, bon)', 'surete' => 90],
            'scanner' === $origine => ['cle' => 'documents', 'raison' => 'scan', 'surete' => 75],
            \in_array($origine, ['whatsapp', 'photo'], true) && \in_array($ext, self::IMAGES, true) && $mots('pose|chantier|facade|installe|installation|vitrine|enseigne') => ['cle' => 'photos', 'raison' => 'photo de pose', 'surete' => 92],
            \in_array($origine, ['whatsapp', 'photo'], true) && \in_array($ext, self::IMAGES, true) => ['cle' => 'photos', 'raison' => 'photo du téléphone', 'surete' => 80],
            $mots('final|finale|def|definitif|pret|imprim|print|hd') && \in_array($ext, ['pdf', 'tif', 'tiff'], true) => ['cle' => 'prets', 'raison' => 'fichier final', 'surete' => 85],
            \in_array($ext, ['psd', 'indd', 'idml', 'afdesign', 'psb'], true), $mots('sans titre|untitled') => ['cle' => 'conceptions', 'raison' => 'fichier de travail', 'surete' => 70],
            \in_array($ext, ['ai', 'eps', 'svg', 'cdr'], true) || $mots('logo|charte|police|font') => ['cle' => 'recus', 'raison' => 'logo ou charte du client', 'surete' => 80],
            default => ['cle' => 'recus', 'raison' => 'fichier reçu', 'surete' => 60],
        };
    }

    /**
     * Description propre tirée du nom d'origine, sans le bruit des
     * téléphones et des scanners (« IMG_20261019_0912 », « WhatsApp Image
     * 2026-10-19 at 09.12.33 », « Sans titre-1 »). Si rien ne reste : le
     * type du sous-dossier, puis la désignation de la commande
     * (« photo-bache-facade »).
     */
    public static function description(string $nomOrigine, string $cleSousDossier, ?string $designation = null, ?string $origine = null): string
    {
        $base = pathinfo($nomOrigine, \PATHINFO_FILENAME);
        $texte = Rapprochement::sansAccents(mb_strtolower($base));
        $texte = (string) preg_replace([
            '/whatsapp (image|video|audio)( \d{4}-\d{2}-\d{2})?( at [\d.]+)?/',
            '/^(img|vid|aud|doc|ptt)-\d{8}-wa\d+/',
            '/^(img|dsc|dcim|pxl|photo|scan|doc)[_ -]?\d+/',
            '/\b(sans titre|untitled|copie|copy|scan|scanned|numerisation)\b/',
            '/\(\d+\)/',
            '/\b\d{4}[-_.]?\d{2}[-_.]?\d{2}\b/',
            '/\b\d{6,}\b/',
            '/\b[a-z]{1,4}-[a-z]?\d{2,6}\b/', // un numéro de commande ou de client : il sera en tête
        ], ' ', $texte);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', $texte), '-');
        $slug = trim((string) preg_replace('/-(\d{1,3})$/', '', $slug), '-'); // « Sans titre-1 »
        if (\strlen($slug) >= 3 && !ctype_digit(str_replace('-', '', $slug))) {
            return substr($slug, 0, 60);
        }
        $type = match ($cleSousDossier) {
            'photos' => 'photo',
            'documents' => 'scanner' === $origine ? 'scan' : 'document',
            'bat' => 'bat',
            'prets' => 'impression',
            'conceptions' => 'conception',
            default => 'fichier',
        };
        if (null !== $designation && '' !== trim($designation)) {
            $mots = \array_slice(array_values(array_filter(explode('-', ModeleSousDossiers::slug($designation)), static fn (string $m): bool => \strlen($m) > 2 && !ctype_digit($m))), 0, 2);
            if ([] !== $mots) {
                return $type.'-'.implode('-', $mots);
            }
        }

        return $type;
    }

    /** « CM-0175_photo-facade_2026-10-19.jpg » */
    public static function nomAutomatique(string $prefixe, string $description, \DateTimeInterface $date, string $extension): string
    {
        $prefixe = trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $prefixe), '-');
        $description = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower(Rapprochement::sansAccents($description))), '-') ?: 'fichier';
        $extension = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $extension));

        return ('' !== $prefixe ? $prefixe.'_' : '').$description.'_'.$date->format('Y-m-d').('' !== $extension ? '.'.$extension : '');
    }

    /**
     * Un nom libre dans un dossier : « nom.jpg », sinon « nom-2.jpg »… Le
     * fichier déjà là n'est jamais écrasé.
     *
     * @param callable(string): bool $existe
     */
    public static function nomLibre(string $nom, callable $existe): string
    {
        if (!$existe($nom)) {
            return $nom;
        }
        $ext = pathinfo($nom, \PATHINFO_EXTENSION);
        $base = pathinfo($nom, \PATHINFO_FILENAME);
        for ($i = 2; $i < 1000; ++$i) {
            $essai = $base.'-'.$i.('' !== $ext ? '.'.$ext : '');
            if (!$existe($essai)) {
                return $essai;
            }
        }

        return $base.'-'.bin2hex(random_bytes(3)).('' !== $ext ? '.'.$ext : '');
    }

    /**
     * Numéros qui peuvent désigner un client ou une commande dans un nom
     * (« CM-0175 », « C-0087 », « D-2026-0214 », « CMD-D00004 »).
     *
     * @return list<string>
     */
    public static function numeros(string $nom): array
    {
        preg_match_all('/(?<![A-Za-z0-9])([A-Za-z]{1,5}-(?:[A-Za-z]{0,2}\d{2,8})(?:-\d{2,6})?)(?![A-Za-z0-9])/', $nom, $m);

        return array_values(array_unique(array_map('strtoupper', $m[1])));
    }

    /**
     * Famille et numéro de version : « kakemono-soiree-jazz_v3.ai » →
     * [kakemono-soiree-jazz.ai, 3] ; « logo FINAL (2).pdf » → [logo.pdf, null].
     *
     * @return array{base: string, version: ?int}
     */
    public static function version(string $nom): array
    {
        $ext = strtolower(pathinfo($nom, \PATHINFO_EXTENSION));
        $texte = Rapprochement::sansAccents(mb_strtolower(pathinfo($nom, \PATHINFO_FILENAME)));
        $version = null;
        if (preg_match('/(?:^|[^a-z0-9])v(?:ersion)?[ _.-]?(\d{1,3})(?![0-9])/', $texte, $m)) {
            $version = (int) $m[1];
            $texte = (string) preg_replace('/(?:^|[^a-z0-9])v(?:ersion)?[ _.-]?\d{1,3}(?![0-9])/', ' ', $texte);
        }
        $texte = (string) preg_replace('/\(\d+\)/', ' ', $texte);
        $mots = array_values(array_filter(preg_split('/[^a-z0-9]+/', $texte) ?: [], static fn (string $m): bool => '' !== $m && !\in_array($m, self::MOTS_VERSION, true)));
        $base = implode('-', $mots) ?: 'fichier';

        return ['base' => $base.('' !== $ext ? '.'.$ext : ''), 'version' => $version];
    }

    /** Fichier source (AI, PSD…) : jamais publié pour le client sans choix exprès. */
    public static function estSource(string $nom): bool
    {
        return \in_array(strtolower(pathinfo($nom, \PATHINFO_EXTENSION)), self::SOURCES, true);
    }

    /** Pastille de type (« AI », « PSD », « JPG ») et sa couleur. */
    public static function pastille(string $extension): array
    {
        $e = strtolower($extension);

        return ['texte' => strtoupper('jpeg' === $e ? 'jpg' : ('' === $e ? '?' : substr($e, 0, 4))), 'couleur' => match ($e) {
            'ai', 'eps' => '#E8590C',
            'psd', 'psb' => '#2F80ED',
            'indd', 'idml' => '#DB2777',
            'pdf' => '#DC2626',
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'tif', 'tiff' => '#0E7C66',
            default => '#6B7280',
        }];
    }
}
