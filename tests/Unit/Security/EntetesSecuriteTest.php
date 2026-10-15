<?php

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\EventSubscriber\EntetesSecuriteSubscriber;
use App\Security\EntetesSecurite;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * En-têtes de sécurité (docs/securite.md) : une seule politique, posée
 * sur chaque réponse par EntetesSecuriteSubscriber.
 */
final class EntetesSecuriteTest extends TestCase
{
    /** @return array<string, string> directive => sources */
    private static function directives(string $politique): array
    {
        $directives = [];
        foreach (explode(';', $politique) as $morceau) {
            $mots = preg_split('/\s+/', trim($morceau)) ?: [];
            $directives[array_shift($mots)] = implode(' ', $mots);
        }

        return $directives;
    }

    public function testPolitiqueDuSitePublic(): void
    {
        $d = self::directives(EntetesSecurite::politique(EntetesSecurite::ZONE_PUBLIQUE));

        self::assertSame("'self'", $d['default-src']);
        self::assertSame("'none'", $d['object-src']);
        self::assertSame("'self'", $d['base-uri']);
        self::assertSame("'self'", $d['frame-ancestors']);
        // Le formulaire « Payer » repart vers la page d'Orange Money.
        self::assertSame("'self' https://*.orange-money.com https://orange-money.com", $d['form-action']);
        self::assertStringContainsString('data:', $d['img-src']);
        self::assertStringContainsString('blob:', $d['img-src']);
        // Rien d'un autre site sur le site public et l'espace client.
        foreach ($d as $nom => $sources) {
            self::assertStringNotContainsString('cdn.jsdelivr.net', $sources, $nom);
            self::assertStringNotContainsString('googleapis', $sources, $nom);
        }
        self::assertStringNotContainsString("'unsafe-eval'", EntetesSecurite::politique(EntetesSecurite::ZONE_ADMIN));
    }

    public function testAdministrationSansCdn(): void
    {
        $admin = self::directives(EntetesSecurite::politique(EntetesSecurite::ZONE_ADMIN));
        $public = self::directives(EntetesSecurite::politique(EntetesSecurite::ZONE_PUBLIQUE));

        // Scripts, styles et polices : seulement le site (public/vendor), comme le site public (D4).
        foreach (['script-src', 'style-src', 'font-src', 'connect-src', 'default-src'] as $nom) {
            self::assertSame($public[$nom], $admin[$nom], $nom);
            self::assertStringNotContainsString('https:', $admin[$nom], $nom);
        }
        foreach (['cdn.jsdelivr.net', 'cdnjs.cloudflare.com', 'googleapis', 'gstatic'] as $hote) {
            self::assertStringNotContainsString($hote, EntetesSecurite::politique(EntetesSecurite::ZONE_ADMIN), $hote);
        }
        // Seule exception, la même partout : les fonds de carte (des images) de la page Contact,
        // des clients et des tournées.
        self::assertSame("'self' data: blob: https://tile.openstreetmap.org", $public['img-src']);
        self::assertSame($public['img-src'], $admin['img-src']);
        self::assertSame("'none'", $admin['object-src']);
        self::assertSame("'self'", $admin['frame-ancestors']);
    }

    public function testZones(): void
    {
        foreach (['app_site_accueil', 'app_espace_commande', 'app_avis_commande', 'app_assistant_public_message', 'app_facture_suivi', 'app_login', 'app_home'] as $route) {
            self::assertSame(EntetesSecurite::ZONE_PUBLIQUE, EntetesSecurite::zone($route, false), $route);
        }
        // Le personnel connecté, l'écran TV et la tablette chargent le thème.
        self::assertSame(EntetesSecurite::ZONE_ADMIN, EntetesSecurite::zone('app_home', true));
        self::assertSame(EntetesSecurite::ZONE_ADMIN, EntetesSecurite::zone('app_tv_ecran', false));
        self::assertSame(EntetesSecurite::ZONE_ADMIN, EntetesSecurite::zone('app_tablette_index', false));
        self::assertSame(EntetesSecurite::ZONE_ADMIN, EntetesSecurite::zone('', false));
    }

    public function testFichiersSansScriptEtPdfLisible(): void
    {
        $image = EntetesSecurite::entetes('image/svg+xml', EntetesSecurite::ZONE_PUBLIQUE, false);
        self::assertSame(EntetesSecurite::POLITIQUE_FICHIER, $image['Content-Security-Policy']);
        self::assertStringContainsString('sandbox', $image['Content-Security-Policy']);
        self::assertStringContainsString("default-src 'none'", $image['Content-Security-Policy']);

        // Le lecteur PDF du navigateur refuse une page « sandbox ».
        $pdf = EntetesSecurite::entetes('application/pdf', EntetesSecurite::ZONE_PUBLIQUE, false);
        self::assertSame("frame-ancestors 'self'", $pdf['Content-Security-Policy']);

        $html = EntetesSecurite::entetes('text/html; charset=UTF-8', EntetesSecurite::ZONE_PUBLIQUE, false);
        self::assertSame(EntetesSecurite::politique(EntetesSecurite::ZONE_PUBLIQUE), $html['Content-Security-Policy']);
        foreach ([$image, $pdf, $html] as $entetes) {
            self::assertSame('nosniff', $entetes['X-Content-Type-Options']);
            self::assertSame('SAMEORIGIN', $entetes['X-Frame-Options']);
            self::assertSame('strict-origin-when-cross-origin', $entetes['Referrer-Policy']);
            self::assertStringContainsString('microphone=()', $entetes['Permissions-Policy']);
        }
    }

    public function testHstsSeulementEnHttps(): void
    {
        self::assertArrayNotHasKey('Strict-Transport-Security', EntetesSecurite::entetes('text/html', EntetesSecurite::ZONE_PUBLIQUE, false));
        self::assertSame('max-age=31536000', EntetesSecurite::entetes('text/html', EntetesSecurite::ZONE_PUBLIQUE, true)['Strict-Transport-Security']);
    }

    public function testAbonneGardeLesEntetesDeLaPage(): void
    {
        $reponse = new Response('<p>Suivi</p>', 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Referrer-Policy' => 'no-referrer']);
        $this->poser(Request::create('https://succesimprim.com/f/ABCD-EF'), $reponse, null, 'app_facture_suivi');

        self::assertSame('no-referrer', $reponse->headers->get('Referrer-Policy'));
        self::assertSame(EntetesSecurite::politique(EntetesSecurite::ZONE_PUBLIQUE), $reponse->headers->get('Content-Security-Policy'));
        self::assertSame('max-age=31536000', $reponse->headers->get('Strict-Transport-Security'));

        $json = new JsonResponse(['ok' => true]);
        $this->poser(Request::create('http://localhost/assistant/message', 'POST'), $json, null, 'app_assistant_public_message');
        self::assertSame(EntetesSecurite::POLITIQUE_FICHIER, $json->headers->get('Content-Security-Policy'));
        self::assertFalse($json->headers->has('Strict-Transport-Security'));

        // Le personnel connecté a la politique de l'administration, même sur « / ».
        $tableau = new Response('<p>Tableau</p>', 200, ['Content-Type' => 'text/html']);
        $this->poser(Request::create('http://localhost/'), $tableau, new User(), 'app_home');
        self::assertSame(EntetesSecurite::politique(EntetesSecurite::ZONE_ADMIN), $tableau->headers->get('Content-Security-Policy'));

        // Une politique posée par la page (photo de réclamation) reste la sienne.
        $fichier = new BinaryFileResponse(__FILE__, 200, ['Content-Type' => 'image/jpeg', 'Content-Security-Policy' => "default-src 'none'"]);
        $this->poser(Request::create('http://localhost/espace/reclamations/photo/3'), $fichier, null, 'app_espace_reclamation_photo');
        self::assertSame("default-src 'none'", $fichier->headers->get('Content-Security-Policy'));
    }

    private function poser(Request $requete, Response $reponse, ?User $utilisateur, string $route): void
    {
        $requete->attributes->set('_route', $route);
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($utilisateur);
        $kernel = $this->createStub(HttpKernelInterface::class);
        (new EntetesSecuriteSubscriber($security))->poser(new ResponseEvent($kernel, $requete, HttpKernelInterface::MAIN_REQUEST, $reponse));
    }
}
