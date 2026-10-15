<?php

namespace App\Security;

/**
 * En-têtes de sécurité de toutes les réponses (docs/securite.md), au local
 * comme en ligne, posés par EntetesSecuriteSubscriber.
 *
 * La politique de contenu (Content-Security-Policy) tient compte de ce qui
 * existe aujourd'hui : beaucoup de pages ont des scripts et des styles
 * écrits dans la page, d'où 'unsafe-inline'. Elle ferme le reste : aucun
 * plugin (object-src), aucune balise <base> venue d'ailleurs, aucun
 * formulaire envoyé hors du site (sauf la page de paiement d'Orange
 * Money), aucune page du site affichée dans le cadre d'un autre site.
 *
 * Deux zones :
 *  - le site public, l'espace client et les pages sans compte : tout vient
 *    du site lui-même ;
 *  - l'administration (personnel connecté, écran TV, tablette) : en plus,
 *    les polices Google du thème, les scripts de cdn.jsdelivr.net
 *    (Sortable, Chart.js) et les fonds de carte OpenStreetMap. À retirer
 *    quand ces fichiers seront copiés dans public/vendor.
 */
final class EntetesSecurite
{
    public const ZONE_PUBLIQUE = 'publique';
    public const ZONE_ADMIN = 'admin';

    /** Page de paiement d'Orange Money : le bouton « Payer » y renvoie (OrangeMoney::lienOrange). */
    public const ORANGE_MONEY = 'https://*.orange-money.com https://orange-money.com';

    /** Un an : le navigateur n'essaie plus le site en http (seulement quand la requête est en https). */
    public const HSTS = 'max-age=31536000';

    /** Routes du site public, de l'espace client et des pages sans compte (préfixes de nom). */
    private const ROUTES_PUBLIQUES = [
        'app_site_', 'app_espace_', 'app_avis_', 'app_assistant_public_', 'app_facture_suivi',
        'app_orange_money_', 'app_appareil_autoriser', 'app_login', 'app_verification',
        'app_factures_pdf_public', 'app_devis_pdf_public',
    ];

    /** @var array<string, list<string>> */
    private const POLITIQUE = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'unsafe-inline'"],
        'style-src' => ["'self'", "'unsafe-inline'"],
        // data: et blob: : vues de l'atelier 3D, repères du BAT, aperçus avant envoi.
        'img-src' => ["'self'", 'data:', 'blob:'],
        'font-src' => ["'self'", 'data:'],
        'connect-src' => ["'self'"],
        'media-src' => ["'self'", 'blob:'],
        'worker-src' => ["'self'", 'blob:'],
        // Aperçu des BAT et des fichiers en PDF dans la page.
        'frame-src' => ["'self'"],
        'object-src' => ["'none'"],
        'base-uri' => ["'self'"],
        'form-action' => ["'self'", self::ORANGE_MONEY],
        'frame-ancestors' => ["'self'"],
        'manifest-src' => ["'self'"],
    ];

    /** Ce que l'administration charge encore d'ailleurs. */
    private const AJOUTS_ADMIN = [
        'script-src' => ['https://cdn.jsdelivr.net'],
        'style-src' => ['https://fonts.googleapis.com', 'https://cdn.jsdelivr.net', 'https://cdnjs.cloudflare.com'],
        'font-src' => ['https://fonts.gstatic.com', 'https://cdnjs.cloudflare.com'],
        'img-src' => ['https://*.tile.openstreetmap.org'],
    ];

    /**
     * Fichier servi par l'application (image, PDF, JSON…) : rien ne s'y
     * exécute, même un SVG ou un HTML déguisé en fichier de client.
     */
    public const POLITIQUE_FICHIER = "default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; sandbox";

    /** Fonctions du navigateur : caméra (pointage, photos) et position (livraison) pour le site seulement. */
    public const PERMISSIONS = 'camera=(self), geolocation=(self), fullscreen=(self), microphone=(), payment=(), usb=(), serial=(), midi=(), magnetometer=(), gyroscope=(), accelerometer=(), browsing-topics=()';

    public static function zone(string $route, bool $personnelConnecte): string
    {
        if ($personnelConnecte) {
            return self::ZONE_ADMIN;
        }
        // « / » : l'accueil du site pour un visiteur, le tableau de bord pour le personnel.
        if ('app_home' === $route) {
            return self::ZONE_PUBLIQUE;
        }
        foreach (self::ROUTES_PUBLIQUES as $prefixe) {
            if (str_starts_with($route, $prefixe)) {
                return self::ZONE_PUBLIQUE;
            }
        }

        return self::ZONE_ADMIN;
    }

    public static function politique(string $zone): string
    {
        $directives = self::POLITIQUE;
        if (self::ZONE_ADMIN === $zone) {
            foreach (self::AJOUTS_ADMIN as $nom => $sources) {
                $directives[$nom] = [...$directives[$nom], ...$sources];
            }
        }

        return implode('; ', array_map(static fn (string $nom, array $sources): string => $nom.' '.implode(' ', $sources), array_keys($directives), $directives));
    }

    /**
     * En-têtes à poser sur une réponse (ceux déjà posés par la page sont gardés).
     *
     * @return array<string, string>
     */
    public static function entetes(?string $typeContenu, string $zone, bool $https): array
    {
        $type = strtolower(trim(explode(';', (string) $typeContenu)[0]));
        $entetes = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => self::PERMISSIONS,
        ];
        if ('' === $type || 'text/html' === $type || 'application/xhtml+xml' === $type) {
            $entetes['Content-Security-Policy'] = self::politique($zone);
        } elseif ('application/pdf' === $type) {
            // Le lecteur PDF du navigateur ne s'ouvre pas dans une page « sandbox ».
            $entetes['Content-Security-Policy'] = "frame-ancestors 'self'";
        } else {
            $entetes['Content-Security-Policy'] = self::POLITIQUE_FICHIER;
        }
        if ($https) {
            $entetes['Strict-Transport-Security'] = self::HSTS;
        }

        return $entetes;
    }
}
