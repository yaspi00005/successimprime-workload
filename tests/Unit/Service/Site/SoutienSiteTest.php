<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\ReglageSite;
use App\Repository\ActionSocialeRepository;
use App\Service\Site\ActionsSociales;
use App\Service\Site\SoutienSite;
use App\Twig\SiteSocialExtension;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * « Nous soutenir » (docs/site-vitrine.md) : lien de l'avis Google réglé
 * dans Réglages › Site vitrine, QR code fait par le site, carte de l'espace
 * client fermée par personne.
 */
final class SoutienSiteTest extends TestCase
{
    public function testLienParDefautEtSaisie(): void
    {
        self::assertSame('https://g.page/r/Cb0JM2s-39CFECE/review', SoutienSite::LIEN_DEFAUT, 'lien envoyé par Yaya le 17/10/2026');
        self::assertSame(['valeurs' => SoutienSite::DEFAUTS, 'erreurs' => []], SoutienSite::lire([]));

        $lu = SoutienSite::lire(['lien' => ' https://g.page/r/AUTRE/review ', 'qr' => '1']);
        self::assertSame(['lien' => 'https://g.page/r/AUTRE/review', 'qr' => true], $lu['valeurs']);
        self::assertSame('https://search.google.com/local/writereview?placeid=X', SoutienSite::lire(['lien' => 'https://search.google.com/local/writereview?placeid=X'])['valeurs']['lien']);

        // Case du QR décochée : absente du formulaire.
        self::assertFalse(SoutienSite::lire(['lien' => SoutienSite::LIEN_DEFAUT])['valeurs']['qr']);
        // Lien vidé : « Nous soutenir » disparaît partout.
        self::assertSame('', SoutienSite::lire(['lien' => '  '])['valeurs']['lien']);

        foreach (['http://g.page/r/X/review', 'https://exemple.com/avis', 'javascript:alert(1)', 'https://g.page.exemple.com/r', 'https://g.page/r/"><script>'] as $refuse) {
            $lu = SoutienSite::lire(['lien' => $refuse]);
            self::assertArrayHasKey('lien', $lu['erreurs'], $refuse);
            self::assertSame(SoutienSite::LIEN_DEFAUT, $lu['valeurs']['lien'], $refuse);
        }
    }

    public function testUnLienRefuseGardeLAncien(): void
    {
        $reglage = (new ReglageSite(SoutienSite::CLE_REGLAGE))->setValeurs(['lien' => 'https://g.page/r/ANCIEN/review', 'qr' => true]);
        $this->forcerId($reglage);
        $soutien = new SoutienSite($this->em($reglage));

        $erreurs = $soutien->enregistrer(['lien' => 'https://exemple.com/avis', 'qr' => '1']);
        self::assertCount(1, $erreurs);
        self::assertSame(['lien' => 'https://g.page/r/ANCIEN/review', 'qr' => true], $reglage->getValeurs());

        self::assertSame([], $soutien->enregistrer(['lien' => '']));
        self::assertNull($soutien->lien());
        self::assertFalse($soutien->avecQr());
        self::assertNull($soutien->qrSvg(), 'sans lien, pas de QR');
    }

    public function testSansReglageLeLienParDefaut(): void
    {
        $soutien = new SoutienSite($this->em(null));
        self::assertSame(SoutienSite::LIEN_DEFAUT, $soutien->lien());
        self::assertTrue($soutien->avecQr());

        // Un réglage reçu illisible est relu comme une saisie : le lien par défaut reste.
        $recu = (new ReglageSite(SoutienSite::CLE_REGLAGE))->setValeurs(['lien' => 'javascript:alert(1)', 'qr' => '0']);
        $soutien = new SoutienSite($this->em($recu));
        self::assertSame(['lien' => SoutienSite::LIEN_DEFAUT, 'qr' => false], $soutien->reglages());
        self::assertNull($soutien->qrSvg(), 'QR coupé');
    }

    public function testQrCodeFaitIciSansRienDExterieur(): void
    {
        $svg = (string) (new SoutienSite($this->em(null)))->qrSvg();
        self::assertStringContainsString('<svg', $svg);
        self::assertLessThan(40 * 1024, \strlen($svg));
        // Aucune ressource chargée ailleurs : ni image, ni script, ni adresse autre que l'espace de noms SVG.
        self::assertDoesNotMatchRegularExpression('#<(image|script|use)\b#i', $svg);
        preg_match_all('#https?://[^"\s]+#', $svg, $adresses);
        self::assertSame([], array_values(array_filter($adresses[0], static fn (string $a): bool => !str_starts_with($a, 'http://www.w3.org/'))));
    }

    public function testCarteDeLEspaceEtInvitationDUneCommandeLivree(): void
    {
        $extension = $this->extension(new SoutienSite($this->em(null)));
        $compte = new CompteClient();
        $carte = $extension->espaceSoutien($compte);
        self::assertSame(SoutienSite::LIEN_DEFAUT, $carte['lien'] ?? null);
        self::assertStringStartsWith('/nous-soutenir/qr-code.svg?v=', (string) ($carte['qr'] ?? null));
        self::assertNull($extension->espaceSoutien($compte->masquerSoutien(new \DateTimeImmutable())), 'fermée pour cette personne');

        self::assertNull($extension->espaceSoutienCommande(new Commandes()), 'commande sans ligne livrée : pas d\'invitation');

        $coupe = $this->extension(new SoutienSite($this->em((new ReglageSite(SoutienSite::CLE_REGLAGE))->setValeurs(['lien' => '', 'qr' => true]))));
        self::assertNull($coupe->soutien());
        self::assertNull($coupe->espaceSoutien(new CompteClient()));
    }

    private function extension(SoutienSite $soutien): SiteSocialExtension
    {
        $routes = new RouteCollection();
        $routes->add('app_site_soutien_qr', new Route('/nous-soutenir/qr-code.svg'));

        return new SiteSocialExtension(new ActionsSociales($this->createStub(ActionSocialeRepository::class)), $this->createStub(ActionSocialeRepository::class), $soutien, new UrlGenerator($routes, new RequestContext()));
    }

    private function em(?ReglageSite $reglage): EntityManagerInterface
    {
        $depot = $this->createStub(EntityRepository::class);
        $depot->method('findOneBy')->willReturn($reglage);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($depot);

        return $em;
    }

    /** Réglage déjà en base : l'ancienne valeur sert de repli. */
    private function forcerId(ReglageSite $reglage): void
    {
        (new \ReflectionProperty(ReglageSite::class, 'id'))->setValue($reglage, 1);
    }
}
