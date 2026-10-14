<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\AccesEspaceClient;
use App\Entity\Clients;
use App\Entity\ModificationClient;
use App\Entity\User;
use App\Repository\AccesEspaceClientRepository;
use App\Service\EspaceClient\AccesEspace;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * « Envoyer l'accès à l'espace client » (fiche client, ERP local) : le
 * message WhatsApp, le journal sans le code, et les fiches qui n'y ont pas droit.
 */
final class AccesEspaceTest extends TestCase
{
    /** @var list<object> */
    private array $persistes = [];

    private ?AccesEspaceClient $enBase = null;

    protected function setUp(): void
    {
        $this->persistes = [];
        $this->enBase = null;
    }

    private function service(bool $enLigne = false, string $urlSite = 'https://www.succesimprim.ml'): AccesEspace
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persistes[] = $o;
        });
        $repository = $this->createStub(AccesEspaceClientRepository::class);
        $repository->method('duClient')->willReturnCallback(fn (): ?AccesEspaceClient => $this->enBase);
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $p = [], int $type = UrlGeneratorInterface::ABSOLUTE_PATH): string => (UrlGeneratorInterface::ABSOLUTE_URL === $type ? 'http://erp.local' : '').'/espace/premiere-connexion?'.http_build_query($p));

        return new AccesEspace($em, $repository, $urls, $enLigne, $urlSite);
    }

    private static function fiche(string $code = 'CLI-000042', ?string $telephone = '76124212'): Clients
    {
        return (new Clients())->setCode($code)->setNom('Hôtel Niger')->setTelephone($telephone);
    }

    public function testLeMessageDonneLIdentifiantLeCodeLaDureeEtLeLien(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-14 10:00');
        $resultat = $this->service()->donner(self::fiche(), new User(), $maintenant);
        $message = $resultat['message'];

        self::assertStringContainsString('Identifiant (votre numéro) : 76 12 42 12', $message);
        self::assertMatchesRegularExpression('/Code d\'accès : [A-Z2-9]{4}-[A-Z2-9]{4}\n/', $message);
        self::assertStringContainsString('Valable 48 heures (jusqu\'au 16/10/2026 à 10:00), pour une seule connexion.', $message);
        self::assertStringContainsString('Première connexion : https://www.succesimprim.ml/espace/premiere-connexion?tel=76124212', $message);
        self::assertStringContainsString('adresse e-mail', $message);
        self::assertStringContainsString('mot de passe', $message);
        self::assertStringContainsString('Ne donnez ce code à personne.', $message);
        self::assertDoesNotMatchRegularExpression('/\d[\d ]* F\b/', $message, 'jamais de montant');

        // Le lien wa.me porte le message, vers le numéro de la fiche.
        self::assertStringStartsWith('https://wa.me/22376124212?text=', $resultat['whatsapp']);
        self::assertSame($message, rawurldecode(substr($resultat['whatsapp'], \strlen('https://wa.me/22376124212?text='))));
    }

    public function testJamaisLeCodeDansLeLienNiDansLeJournal(): void
    {
        $resultat = $this->service()->donner(self::fiche(), null, new \DateTimeImmutable('2026-10-14 10:00'));
        preg_match('/Code d\'accès : ([A-Z2-9-]{9})/', $resultat['message'], $m);
        $code = AccesEspaceClient::normaliserCode($m[1]);

        self::assertStringNotContainsString($code, $resultat['lien']);
        self::assertStringNotContainsString($m[1], $resultat['lien']);
        $journal = array_values(array_filter($this->persistes, static fn (object $o): bool => $o instanceof ModificationClient));
        self::assertCount(1, $journal, 'qui a donné l\'accès, à qui, quand');
        self::assertSame('Accès à l\'espace client', $journal[0]->getChamp());
        foreach ([$journal[0]->getAvant(), $journal[0]->getApres()] as $texte) {
            self::assertStringNotContainsString($code, (string) $texte);
            self::assertStringNotContainsString($m[1], (string) $texte);
        }
        self::assertSame(AccesEspaceClient::empreinteDe('76124212', $code), $resultat['acces']->getEmpreinte());
    }

    public function testUnNouvelAccesRemplaceLePrecedent(): void
    {
        $service = $this->service();
        $maintenant = new \DateTimeImmutable('2026-10-14 10:00');
        $premier = $service->donner(self::fiche(), null, $maintenant)['acces'];
        $ancienne = $premier->getEmpreinte();
        $this->enBase = $premier;
        $this->persistes = [];

        $second = $service->donner(self::fiche(), null, $maintenant->modify('+1 hour'));

        self::assertSame($premier, $second['acces'], 'la même ligne : une par client');
        self::assertNotSame($ancienne, $premier->getEmpreinte());
        self::assertFalse($premier->valide($ancienne, $maintenant->modify('+1 hour')));
        $journal = array_values(array_filter($this->persistes, static fn (object $o): bool => $o instanceof ModificationClient));
        self::assertSame('Code précédent remplacé', $journal[0]->getAvant());
    }

    public function testPasDAccesPourUneFicheDeDemonstrationSansNumeroOuEnLigne(): void
    {
        self::assertNotNull($this->service()->empechement(self::fiche('DEMO-0002')));
        self::assertSame(AccesEspace::SANS_NUMERO, $this->service()->empechement(self::fiche('CLI-000042', null)));
        self::assertSame(AccesEspace::SANS_NUMERO, $this->service()->empechement(self::fiche('CLI-000042', '1234')));
        self::assertNotNull($this->service(true)->empechement(self::fiche()), 'se donne au local');
        self::assertNull($this->service()->empechement(self::fiche()));

        $this->expectException(\DomainException::class);
        $this->service()->donner(self::fiche('DEMO-0002'), null, new \DateTimeImmutable());
    }

    public function testSansAdresseDuSiteLeLienMeneAuServeurQuiRepond(): void
    {
        $service = $this->service(false, '');

        self::assertFalse($service->siteRegle());
        self::assertSame('http://erp.local/espace/premiere-connexion?tel=76124212', $service->lien('+223 76 12 42 12'));
    }
}
