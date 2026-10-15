<?php

namespace App\Service\Site;

/**
 * Données structurées schema.org (JSON-LD) du site vitrine pour les
 * moteurs de recherche (docs/referencement.md). Tout vient des réglages et
 * du contenu saisi : un renseignement absent est laissé de côté, jamais
 * inventé (pas de note, pas de fourchette de prix, pas de coordonnées
 * devinées).
 *
 * Fonctions pures : les gabarits passent par ReferencementExtension, qui
 * lit les réglages.
 */
final class DonneesStructurees
{
    public const CONTEXTE = 'https://schema.org';

    /** Jours du réglage « Horaires » au format schema.org. */
    public const JOURS = [
        'lun-ven' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        'lun-sam' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        'lun-dim' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
    ];

    /** Identifiants des fiches de l'entreprise, repris d'une page à l'autre. */
    public static function id(string $base, string $quoi): string
    {
        return $base.'/#'.$quoi;
    }

    /**
     * L'entreprise : l'organisation (nom, raison sociale, logo, réseaux),
     * l'imprimerie de quartier (adresse, position, téléphone, horaires) et
     * le site lui-même.
     *
     * @param array{nom: string, raisonSociale?: ?string, telephone?: ?string, email?: ?string, adresse?: ?string} $entreprise
     * @param array{url: string, largeur?: int, hauteur?: int} $logo
     * @param array{jours?: mixed, ouverture?: mixed, fermeture?: mixed} $horaires
     * @param list<string> $memeQue pages des réseaux sociaux et fiche Google
     *
     * @return array<string, mixed>
     */
    public static function entreprise(string $base, array $entreprise, array $logo, array $horaires = [], ?string $lienPlan = null, array $memeQue = [], ?string $image = null): array
    {
        $telephone = self::texte($entreprise['telephone'] ?? null);
        $logoObjet = self::sansVide(['@type' => 'ImageObject', '@id' => self::id($base, 'logo'), 'url' => $logo['url'], 'width' => $logo['largeur'] ?? null, 'height' => $logo['hauteur'] ?? null]);

        $organisation = self::sansVide([
            '@type' => 'Organization',
            '@id' => self::id($base, 'organisation'),
            'name' => $entreprise['nom'],
            'legalName' => self::texte($entreprise['raisonSociale'] ?? null),
            'url' => $base.'/',
            'logo' => $logoObjet,
            'telephone' => $telephone,
            'email' => self::texte($entreprise['email'] ?? null),
            'sameAs' => array_values(array_unique($memeQue)),
            'contactPoint' => null === $telephone ? null : [
                '@type' => 'ContactPoint',
                'telephone' => $telephone,
                'contactType' => 'customer service',
                'areaServed' => 'ML',
                'availableLanguage' => 'French',
            ],
        ]);

        $commerce = self::sansVide([
            '@type' => 'LocalBusiness',
            '@id' => self::id($base, 'imprimerie'),
            'name' => $entreprise['nom'],
            'url' => $base.'/',
            'image' => $image ?? $logo['url'],
            'logo' => ['@id' => self::id($base, 'logo')],
            'telephone' => $telephone,
            'email' => self::texte($entreprise['email'] ?? null),
            'address' => self::adresse($entreprise['adresse'] ?? null),
            'geo' => self::position($lienPlan),
            'hasMap' => null !== $lienPlan && str_starts_with($lienPlan, 'https://') ? $lienPlan : null,
            'openingHoursSpecification' => self::horaires($horaires['jours'] ?? null, $horaires['ouverture'] ?? null, $horaires['fermeture'] ?? null),
            'parentOrganization' => ['@id' => self::id($base, 'organisation')],
            'sameAs' => array_values(array_unique($memeQue)),
        ]);

        $site = [
            '@type' => 'WebSite',
            '@id' => self::id($base, 'site'),
            'url' => $base.'/',
            'name' => $entreprise['nom'],
            'inLanguage' => 'fr',
            'publisher' => ['@id' => self::id($base, 'organisation')],
        ];

        return ['@context' => self::CONTEXTE, '@graph' => [$organisation, $commerce, $site]];
    }

    /**
     * Fil d'Ariane (BreadcrumbList).
     *
     * @param list<array{nom: string, url: string}> $etapes de l'accueil à la page
     *
     * @return array<string, mixed>|null
     */
    public static function filAriane(array $etapes): ?array
    {
        $elements = [];
        foreach (array_values(array_filter($etapes, static fn (array $e): bool => '' !== trim($e['nom']) && '' !== $e['url'])) as $rang => $etape) {
            $elements[] = ['@type' => 'ListItem', 'position' => $rang + 1, 'name' => trim($etape['nom']), 'item' => $etape['url']];
        }

        return \count($elements) < 2 ? null : ['@context' => self::CONTEXTE, '@type' => 'BreadcrumbList', 'itemListElement' => $elements];
    }

    /**
     * Un conseil (Article) : titre, résumé, dates, image, auteur et
     * éditeur Succès Imprim.
     *
     * @return array<string, mixed>
     */
    public static function article(string $base, string $url, string $titre, ?string $resume, \DateTimeInterface $publieLe, \DateTimeInterface $modifieLe, ?string $image, string $nomEntreprise, string $logo): array
    {
        $editeur = ['@type' => 'Organization', '@id' => self::id($base, 'organisation'), 'name' => $nomEntreprise, 'logo' => ['@type' => 'ImageObject', 'url' => $logo]];

        return self::sansVide([
            '@context' => self::CONTEXTE,
            '@type' => 'Article',
            'headline' => mb_substr($titre, 0, 110),
            'description' => self::texte($resume),
            'datePublished' => $publieLe->format('c'),
            'dateModified' => max($publieLe, $modifieLe)->format('c'),
            'inLanguage' => 'fr',
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $url],
            'image' => $image,
            'author' => $editeur,
            'publisher' => $editeur,
        ]);
    }

    /**
     * Les produits de la page « Nos produits » avec leur prix de départ
     * public (OfferCatalog de services d'impression). Un produit « sur
     * devis » n'a pas d'offre chiffrée. Le prix est le même que celui
     * affiché : jamais le prix B2B.
     *
     * @param list<array{nom: string, produits: list<array{nom: string, description?: ?string, prix?: ?array{montant: int|float, unite: string}}>}> $univers
     *
     * @return array<string, mixed>|null
     */
    public static function catalogue(string $base, string $url, array $univers): ?array
    {
        $offres = [];
        foreach ($univers as $u) {
            foreach ($u['produits'] as $produit) {
                $prix = $produit['prix'] ?? null;
                $offres[] = self::sansVide([
                    '@type' => 'Offer',
                    'itemOffered' => self::sansVide([
                        '@type' => 'Service',
                        'name' => $produit['nom'],
                        'description' => self::texte($produit['description'] ?? null),
                        'category' => self::texte($u['nom']),
                        'provider' => ['@id' => self::id($base, 'imprimerie')],
                        'areaServed' => ['@type' => 'City', 'name' => 'Bamako'],
                    ]),
                    'priceSpecification' => null === $prix ? null : self::sansVide([
                        '@type' => 'UnitPriceSpecification',
                        // « À partir de » : le plus petit prix, jamais une fourchette inventée.
                        'minPrice' => $prix['montant'],
                        'priceCurrency' => 'XOF',
                        // « le m² » donne « m² ».
                        'unitText' => self::texte(preg_replace('/^(le|la|les|l\')\s*/u', '', (string) ($prix['unite'] ?? ''))),
                    ]),
                ]);
            }
        }

        return [] === $offres ? null : [
            '@context' => self::CONTEXTE,
            '@type' => 'OfferCatalog',
            'name' => 'Nos produits',
            'url' => $url,
            'itemListElement' => $offres,
        ];
    }

    /**
     * Horaires du réglage « Horaires » (OpeningHoursSpecification), ou
     * null si le réglage est incomplet.
     *
     * @return list<array<string, mixed>>|null
     */
    public static function horaires(mixed $jours, mixed $ouverture, mixed $fermeture): ?array
    {
        $jours = self::JOURS[\is_string($jours) ? $jours : ''] ?? null;
        foreach ([$ouverture, $fermeture] as $heure) {
            if (!\is_string($heure) || !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $heure)) {
                return null;
            }
        }
        if (null === $jours || $fermeture <= $ouverture) {
            return null;
        }

        return [['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $jours, 'opens' => $ouverture, 'closes' => $fermeture]];
    }

    /**
     * Adresse postale : « Bamako, Sotuba ACI » donne la rue « Sotuba ACI »
     * à Bamako, au Mali. Sans « Bamako » dans l'adresse, la ville n'est
     * pas devinée.
     *
     * @return array<string, string>|null
     */
    public static function adresse(?string $adresse): ?array
    {
        $adresse = trim((string) $adresse);
        if ('' === $adresse) {
            return null;
        }
        $aBamako = (bool) preg_match('/\bbamako\b/iu', $adresse);
        $rue = (string) preg_replace('/\s*[,\-–]?\s*\bbamako\b\s*[,\-–]?\s*/iu', ', ', $adresse);
        $rue = trim((string) preg_replace('/,?\s*\bmali\b\s*$/iu', '', $rue), " ,\u{a0}");

        return self::sansVide([
            '@type' => 'PostalAddress',
            'streetAddress' => '' === $rue ? null : $rue,
            'addressLocality' => $aBamako ? 'Bamako' : null,
            'addressCountry' => 'ML',
        ]);
    }

    /**
     * Position de la boutique, seulement si le réglage du plan la donne :
     * coordonnées « 12.6392, -8.0029 », ou lien Google Maps qui les
     * contient (« @12.6392,-8.0029 », « destination=12.6392,-8.0029 »,
     * « q=12.6392,-8.0029 »). Un lien court (maps.app.goo.gl) n'en dit rien.
     *
     * @return array{'@type': string, latitude: float, longitude: float}|null
     */
    public static function position(?string $lienPlan): ?array
    {
        $lienPlan = rawurldecode(trim((string) $lienPlan));
        $nombre = '(-?\d{1,3}\.\d{3,})';
        if (!preg_match('/^'.$nombre.'\s*[,;]\s*'.$nombre.'$/', $lienPlan, $m)
            && !preg_match('/[@=]'.$nombre.',\s*'.$nombre.'/', $lienPlan, $m)) {
            return null;
        }
        $latitude = (float) $m[1];
        $longitude = (float) $m[2];
        if (abs($latitude) > 90 || abs($longitude) > 180) {
            return null;
        }

        return ['@type' => 'GeoCoordinates', 'latitude' => $latitude, 'longitude' => $longitude];
    }

    private static function texte(?string $texte): ?string
    {
        $texte = trim((string) $texte);

        return '' === $texte ? null : $texte;
    }

    /**
     * @param array<string, mixed> $donnees
     *
     * @return array<string, mixed>
     */
    private static function sansVide(array $donnees): array
    {
        return array_filter($donnees, static fn (mixed $v): bool => null !== $v && '' !== $v && [] !== $v);
    }
}
