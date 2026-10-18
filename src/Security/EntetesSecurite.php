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
 * Tout vient du site lui-même, sauf les fonds de carte OpenStreetMap
 * (tile.openstreetmap.org : des images, aucun code) des cartes Leaflet :
 * page Contact du site, clients et tournées dans l'administration. Aucun
 * script, style ou police d'un autre site : Sortable, Chart.js,
 * Bootstrap 4, Font Awesome 4, Leaflet et les polices (thème, écran TV)
 * sont copiés dans public/vendor (docs/securite.md).
 *
 * Deux zones gardées pour la suite : le site public, l'espace client et
 * les pages sans compte ; l'administration (personnel connecté, écran TV,
 * tablette), qui n'a aujourd'hui rien de plus (AJOUTS_ADMIN vide).
 *
 * Seule exception demandée par Yaya (15/10/2026) : la mesure d'audience
 * Google (gtag.js), sur les pages publiques du site en ligne que
 * MesureAudience retient, et sur elles seules (AJOUTS_MESURE_AUDIENCE).
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
        'app_factures_pdf_public', 'app_devis_pdf_public', 'app_devis_lien',
    ];

    /** @var array<string, list<string>> */
    private const POLITIQUE = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", "'unsafe-inline'"],
        'style-src' => ["'self'", "'unsafe-inline'"],
        // data: et blob: : vues de l'atelier 3D, repères du BAT, aperçus avant envoi.
        // Fonds de carte OpenStreetMap (page Contact, clients, tournées).
        'img-src' => ["'self'", 'data:', 'blob:', 'https://tile.openstreetmap.org'],
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

    /**
     * Ce que l'administration seule chargerait d'ailleurs : rien aujourd'hui.
     *
     * @var array<string, list<string>>
     */
    private const AJOUTS_ADMIN = [];

    /**
     * Mesure d'audience Google (MesureAudience) : le script gtag.js, puis
     * les envois des mesures (régions de Google Analytics comprises). Pas
     * de Google Signals ni de publicité (public/js/mesure-audience.js) :
     * aucun site de Google de plus.
     *
     * @var array<string, list<string>>
     */
    public const AJOUTS_MESURE_AUDIENCE = [
        'script-src' => ['https://www.googletagmanager.com'],
        'img-src' => ['https://*.google-analytics.com', 'https://*.googletagmanager.com'],
        'connect-src' => ['https://*.google-analytics.com', 'https://*.analytics.google.com', 'https://*.googletagmanager.com'],
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

    /**
     * @param bool $mesureAudience page qui porte la balise Google (MesureAudience::identifiant)
     */
    public static function politique(string $zone, bool $mesureAudience = false): string
    {
        $directives = self::POLITIQUE;
        $ajouts = self::ZONE_ADMIN === $zone ? self::AJOUTS_ADMIN : [];
        if ($mesureAudience) {
            $ajouts = array_merge_recursive($ajouts, self::AJOUTS_MESURE_AUDIENCE);
        }
        foreach ($ajouts as $nom => $sources) {
            $directives[$nom] = [...$directives[$nom], ...$sources];
        }

        return implode('; ', array_map(static fn (string $nom, array $sources): string => $nom.' '.implode(' ', $sources), array_keys($directives), $directives));
    }

    /**
     * En-têtes à poser sur une réponse (ceux déjà posés par la page sont gardés).
     *
     * @param bool $mesureAudience page HTML qui porte la balise Google (MesureAudience::identifiant)
     *
     * @return array<string, string>
     */
    public static function entetes(?string $typeContenu, string $zone, bool $https, bool $mesureAudience = false): array
    {
        $type = strtolower(trim(explode(';', (string) $typeContenu)[0]));
        $entetes = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => self::PERMISSIONS,
        ];
        if ('' === $type || 'text/html' === $type || 'application/xhtml+xml' === $type) {
            $entetes['Content-Security-Policy'] = self::politique($zone, $mesureAudience);
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
