<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\Entreprise;
use App\Repository\AppareilAutoriseRepository;
use App\Repository\EntrepriseRepository;
use App\Security\AccesEnLigne;
use App\Service\NotificationService;
use App\Service\Site\AdresseSite;
use App\Tests\Unit\Security\FabriqueCodes;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class AdresseSiteTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function saisiesProvider(): iterable
    {
        yield 'domaine seul' => ['succesimprim.com', 'https://succesimprim.com'];
        yield 'avec www et barre : sans www' => ['https://www.succesimprim.com/', 'https://succesimprim.com'];
        yield 'www en majuscules' => ['WWW.SuccesImprim.com', 'https://succesimprim.com'];
        yield 'www seul devant un nom de premier niveau' => ['www.com', 'https://www.com'];
        yield 'http et chemin' => ['http://succesimprim.com/accueil?x=1', 'https://succesimprim.com'];
        yield 'majuscules et blancs' => ['  SuccesImprim.COM ', 'https://succesimprim.com'];
        yield 'vide' => ['', null];
        yield 'null' => [null, null];
        yield 'localhost' => ['http://localhost:8000', null];
        yield 'adresse IP' => ['http://192.168.1.10', null];
        yield 'nom sans point' => ['erp', null];
        yield 'nom local' => ['erp.local', null];
        yield 'avec port' => ['https://succesimprim.com:8443', null];
        yield 'autre protocole' => ['ftp://succesimprim.com', null];
        yield 'espace' => ['succes imprim.com', null];
        yield 'caractères interdits' => ['https://succes_imprim.com', null];
    }

    #[DataProvider('saisiesProvider')]
    public function testNormaliser(?string $saisie, ?string $attendu): void
    {
        self::assertSame($attendu, AdresseSite::normaliser($saisie));
    }

    public function testAdresseDeRequeteGardeLeSchemaEtLePort(): void
    {
        self::assertSame('http://127.0.0.1:8195', AdresseSite::normaliser('http://127.0.0.1:8195', false));
    }

    public function testOrdreDeChoix(): void
    {
        // Le champ « Site » de l'entité passe avant tout.
        self::assertSame('https://succesimprim.com', AdresseSite::choisir('succesimprim.com', true, 'https://autre.example.org', '', 'http://localhost'));
        // En ligne sans champ : DEFAULT_URI ; l'adresse de synchronisation est ignorée.
        self::assertSame('https://serveur.example.org', AdresseSite::choisir(null, true, 'https://serveur.example.org', 'https://synchro.example.org', null));
        // ERP local sans champ : l'adresse du site connue par la synchronisation, jamais celle de l'ERP.
        self::assertSame('https://synchro.example.org', AdresseSite::choisir('', false, 'http://localhost', 'https://synchro.example.org', 'http://192.168.1.10:8000'));
        // Rien de public : l'adresse de la requête, puis le domaine prévu.
        self::assertSame('http://127.0.0.1:8195', AdresseSite::choisir(null, true, 'http://localhost', '', 'http://127.0.0.1:8195'));
        self::assertSame('https://succesimprim.com', AdresseSite::choisir(null, false, '', '', null));
    }

    public function testUrlDUnCheminOuDUneAdresseComplete(): void
    {
        $entreprises = $this->createStub(EntrepriseRepository::class);
        $entreprises->method('parCode')->willReturn((new Entreprise())->setCode('mdg_success')->setSiteWeb('www.succesimprim.com'));
        $acces = new AccesEnLigne(false, $this->createStub(AppareilAutoriseRepository::class), $this->createStub(EntityManagerInterface::class), (new FabriqueCodes())->codes(), $this->createStub(NotificationService::class));
        $requetes = new RequestStack();
        $requetes->push(Request::create('http://192.168.1.10:8000/site'));
        $adresse = new AdresseSite($entreprises, $acces, $requetes);

        self::assertSame('https://succesimprim.com', $adresse->base());
        self::assertSame('https://succesimprim.com/', $adresse->url('/'));
        self::assertSame('https://succesimprim.com/faq', $adresse->url('/faq'));
        self::assertSame('https://succesimprim.com/images/logo.png', $adresse->url('images/logo.png'));
        // Une adresse complète de l'ERP garde seulement son chemin.
        self::assertSame('https://succesimprim.com/uploads/a.webp', $adresse->url('http://192.168.1.10:8000/uploads/a.webp'));
    }
}
