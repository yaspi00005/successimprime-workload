<?php

namespace App\Tests\Unit\Service\Site;

use App\Service\Site\DonneesStructurees;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DonneesStructureesTest extends TestCase
{
    private const BASE = 'https://succesimprim.com';

    public function testEntrepriseComplete(): void
    {
        $donnees = DonneesStructurees::entreprise(
            self::BASE,
            ['nom' => 'Succès Imprim', 'raisonSociale' => 'Madial Group SARL', 'telephone' => '+223 70 38 69 12', 'email' => '', 'adresse' => 'Bamako, Sotuba ACI'],
            ['url' => self::BASE.'/images/logo.png', 'largeur' => 640, 'hauteur' => 357],
            ['jours' => 'lun-sam', 'ouverture' => '08:00', 'fermeture' => '18:00'],
            'https://www.google.com/maps/dir/?api=1&destination=12.6392,-7.9561',
            ['https://www.facebook.com/succesimprim', 'https://www.facebook.com/succesimprim'],
            self::BASE.'/images/site/partage.jpg',
        );

        self::assertSame('https://schema.org', $donnees['@context']);
        [$organisation, $commerce, $site] = $donnees['@graph'];

        self::assertSame('Organization', $organisation['@type']);
        self::assertSame(self::BASE.'/#organisation', $organisation['@id']);
        self::assertSame('Madial Group SARL', $organisation['legalName']);
        self::assertSame(self::BASE.'/', $organisation['url']);
        self::assertSame(['@type' => 'ImageObject', '@id' => self::BASE.'/#logo', 'url' => self::BASE.'/images/logo.png', 'width' => 640, 'height' => 357], $organisation['logo']);
        self::assertSame(['https://www.facebook.com/succesimprim'], $organisation['sameAs']);
        self::assertSame('+223 70 38 69 12', $organisation['contactPoint']['telephone']);
        // Pas d'e-mail réglé : pas de clé vide.
        self::assertArrayNotHasKey('email', $organisation);

        self::assertSame('LocalBusiness', $commerce['@type']);
        self::assertSame(self::BASE.'/#imprimerie', $commerce['@id']);
        self::assertSame(self::BASE.'/images/site/partage.jpg', $commerce['image']);
        self::assertSame(['@type' => 'PostalAddress', 'streetAddress' => 'Sotuba ACI', 'addressLocality' => 'Bamako', 'addressCountry' => 'ML'], $commerce['address']);
        self::assertSame(['@type' => 'GeoCoordinates', 'latitude' => 12.6392, 'longitude' => -7.9561], $commerce['geo']);
        self::assertSame('https://www.google.com/maps/dir/?api=1&destination=12.6392,-7.9561', $commerce['hasMap']);
        self::assertSame(['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], $commerce['openingHoursSpecification'][0]['dayOfWeek']);
        self::assertSame(['@id' => self::BASE.'/#organisation'], $commerce['parentOrganization']);

        self::assertSame(['@type' => 'WebSite', '@id' => self::BASE.'/#site', 'url' => self::BASE.'/', 'name' => 'Succès Imprim', 'inLanguage' => 'fr', 'publisher' => ['@id' => self::BASE.'/#organisation']], $site);
    }

    public function testEntrepriseSansRenseignementsNInventeRien(): void
    {
        [$organisation, $commerce] = DonneesStructurees::entreprise(self::BASE, ['nom' => 'Succès Imprim'], ['url' => self::BASE.'/logo.png'])['@graph'];

        foreach (['telephone', 'email', 'sameAs', 'contactPoint', 'legalName'] as $cle) {
            self::assertArrayNotHasKey($cle, $organisation, $cle);
        }
        foreach (['address', 'geo', 'hasMap', 'openingHoursSpecification', 'telephone', 'aggregateRating', 'priceRange'] as $cle) {
            self::assertArrayNotHasKey($cle, $commerce, $cle);
        }
        // Sans image de partage : le logo.
        self::assertSame(self::BASE.'/logo.png', $commerce['image']);
    }

    public function testFilAriane(): void
    {
        $fil = DonneesStructurees::filAriane([
            ['nom' => 'Accueil', 'url' => self::BASE.'/'],
            ['nom' => ' Conseils ', 'url' => self::BASE.'/conseils'],
            ['nom' => '', 'url' => self::BASE.'/vide'],
            ['nom' => 'Bien choisir sa bâche', 'url' => self::BASE.'/conseils/bache'],
        ]);

        self::assertNotNull($fil);
        self::assertSame('BreadcrumbList', $fil['@type']);
        self::assertSame([1, 2, 3], array_column($fil['itemListElement'], 'position'));
        self::assertSame(['Accueil', 'Conseils', 'Bien choisir sa bâche'], array_column($fil['itemListElement'], 'name'));
        self::assertNull(DonneesStructurees::filAriane([['nom' => 'Accueil', 'url' => self::BASE.'/']]));
    }

    public function testArticle(): void
    {
        $article = DonneesStructurees::article(self::BASE, self::BASE.'/conseils/bache', str_repeat('Titre ', 30), '  ', new \DateTimeImmutable('2026-10-01 10:00:00+00:00'), new \DateTimeImmutable('2026-09-01 10:00:00+00:00'), null, 'Succès Imprim', self::BASE.'/logo.png');

        self::assertSame('Article', $article['@type']);
        self::assertSame(110, mb_strlen($article['headline']));
        // Modifié « avant » d'être publié (article programmé) : la date de publication.
        self::assertSame('2026-10-01T10:00:00+00:00', $article['dateModified']);
        self::assertSame(['@type' => 'WebPage', '@id' => self::BASE.'/conseils/bache'], $article['mainEntityOfPage']);
        self::assertSame(self::BASE.'/#organisation', $article['publisher']['@id']);
        self::assertSame($article['publisher'], $article['author']);
        self::assertArrayNotHasKey('description', $article);
        self::assertArrayNotHasKey('image', $article);
    }

    public function testCatalogueAvecPrixPublicDeDepart(): void
    {
        $catalogue = DonneesStructurees::catalogue(self::BASE, self::BASE.'/produits', [
            ['nom' => 'Grand format', 'produits' => [
                ['nom' => 'Bâche', 'description' => 'Bâche 440 g', 'prix' => ['montant' => 3500, 'unite' => 'le m²']],
                ['nom' => 'Enseigne lumineuse', 'description' => null, 'prix' => null],
            ]],
        ]);

        self::assertNotNull($catalogue);
        self::assertSame('OfferCatalog', $catalogue['@type']);
        [$bache, $enseigne] = $catalogue['itemListElement'];
        self::assertSame(['@type' => 'UnitPriceSpecification', 'minPrice' => 3500, 'priceCurrency' => 'XOF', 'unitText' => 'm²'], $bache['priceSpecification']);
        self::assertSame('Grand format', $bache['itemOffered']['category']);
        self::assertSame(['@id' => self::BASE.'/#imprimerie'], $bache['itemOffered']['provider']);
        // Sur devis : pas de prix.
        self::assertArrayNotHasKey('priceSpecification', $enseigne);
        self::assertArrayNotHasKey('description', $enseigne['itemOffered']);
        self::assertNull(DonneesStructurees::catalogue(self::BASE, self::BASE.'/produits', [['nom' => 'Vide', 'produits' => []]]));
    }

    public function testHoraires(): void
    {
        self::assertSame([['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => DonneesStructurees::JOURS['lun-ven'], 'opens' => '07:30', 'closes' => '17:00']], DonneesStructurees::horaires('lun-ven', '07:30', '17:00'));
        self::assertNull(DonneesStructurees::horaires('lun-ven', '18:00', '08:00'));
        self::assertNull(DonneesStructurees::horaires('lun-ven', '8h', '18:00'));
        self::assertNull(DonneesStructurees::horaires('mar-jeu', '08:00', '18:00'));
        self::assertNull(DonneesStructurees::horaires(null, null, null));
    }

    /**
     * @return iterable<string, array{?string, ?array<string, string>}>
     */
    public static function adressesProvider(): iterable
    {
        yield 'Bamako en tête' => ['Bamako, Sotuba ACI', ['@type' => 'PostalAddress', 'streetAddress' => 'Sotuba ACI', 'addressLocality' => 'Bamako', 'addressCountry' => 'ML']];
        yield 'Bamako et Mali à la fin' => ['Sotuba ACI, Bamako, Mali', ['@type' => 'PostalAddress', 'streetAddress' => 'Sotuba ACI', 'addressLocality' => 'Bamako', 'addressCountry' => 'ML']];
        yield 'sans ville' => ['Sotuba ACI', ['@type' => 'PostalAddress', 'streetAddress' => 'Sotuba ACI', 'addressCountry' => 'ML']];
        yield 'vide' => ['  ', null];
    }

    /** @param array<string, string>|null $attendu */
    #[DataProvider('adressesProvider')]
    public function testAdresse(?string $saisie, ?array $attendu): void
    {
        self::assertSame($attendu, DonneesStructurees::adresse($saisie));
    }

    /**
     * @return iterable<string, array{?string, ?array{float, float}}>
     */
    public static function positionsProvider(): iterable
    {
        yield 'coordonnées' => ['12.6392, -7.9561', [12.6392, -7.9561]];
        yield 'point-virgule' => ['12.6392;-7.9561', [12.6392, -7.9561]];
        yield 'lien @' => ['https://www.google.com/maps/place/X/@12.6392,-7.9561,17z', [12.6392, -7.9561]];
        yield 'lien destination' => ['https://www.google.com/maps/dir/?api=1&destination=12.6392%2C-7.9561', [12.6392, -7.9561]];
        // Lien long d'une fiche (celui de la boutique) : le repère !3d…!4d…, pas le centre de la vue @….
        yield 'fiche : repère plutôt que vue' => ['https://www.google.com/maps/place/Succ%C3%A8s+Imprim/@12.6517571,-7.9325125,17z/data=!3m1!4b1!4m6!3m5!1s0xe51cd45a9a50e9b:0x85d0df3e6b3309bd!8m2!3d12.6517571!4d-7.9299376!16s%2Fg%2F11', [12.6517571, -7.9299376]];
        yield 'repère seul' => ['https://www.google.com/maps/place/X/data=!4m5!3m4!1s0x0:0x0!8m2!3d12.6517571!4d-7.9299376', [12.6517571, -7.9299376]];
        yield 'lien court' => ['https://maps.app.goo.gl/AbCdEf', null];
        yield 'trop imprécis' => ['12.6, -7.9', null];
        yield 'hors limites' => ['112.6392, -7.9561', null];
        yield 'vide' => [null, null];
    }

    /** @param array{float, float}|null $attendu */
    #[DataProvider('positionsProvider')]
    public function testPosition(?string $saisie, ?array $attendu): void
    {
        $position = DonneesStructurees::position($saisie);
        self::assertSame($attendu, null === $position ? null : [$position['latitude'], $position['longitude']]);
    }
}
