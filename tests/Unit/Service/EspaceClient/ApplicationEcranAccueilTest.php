<?php

namespace App\Tests\Unit\Service\EspaceClient;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Application Succès Imprim sur l'écran d'accueil (docs/espace-client.md,
 * « Application sur l'écran d'accueil ») : ce qu'il faut pour que les
 * téléphones proposent l'installation, et une carte sans montant ni donnée
 * personnelle, montrée seulement sur le tableau de bord du site en ligne.
 */
final class ApplicationEcranAccueilTest extends TestCase
{
    private const RACINE = __DIR__.'/../../../..';

    public function testLeManifesteRemplitLesConditionsDInstallation(): void
    {
        $m = json_decode((string) file_get_contents(self::RACINE.'/public/espace.webmanifest'), true, flags: \JSON_THROW_ON_ERROR);

        self::assertStringContainsString('Succès Imprim', $m['name']);
        self::assertSame('Succès Imprim', $m['short_name']);
        self::assertSame('fr', $m['lang']);
        self::assertSame('/espace', $m['scope']);
        self::assertStringStartsWith($m['scope'], $m['start_url'], 'L\'application s\'ouvre sur l\'espace client.');
        self::assertSame('standalone', $m['display']);
        self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $m['theme_color']);
        self::assertMatchesRegularExpression('/^#[0-9A-F]{6}$/i', $m['background_color']);
        self::assertNotTrue($m['prefer_related_applications'] ?? false, 'Pas encore d\'application dans les magasins.');

        $trouvees = [];
        foreach ($m['icons'] as $icone) {
            [$largeur, $hauteur] = getimagesize(self::RACINE.'/public'.$icone['src']) ?: [0, 0];
            self::assertSame($icone['sizes'], $largeur.'x'.$hauteur, $icone['src']);
            self::assertSame('image/png', $icone['type']);
            $trouvees[] = ($icone['purpose'] ?? 'any').' '.$icone['sizes'];
        }
        foreach (['any 192x192', 'any 512x512', 'maskable 192x192', 'maskable 512x512'] as $attendue) {
            self::assertContains($attendue, $trouvees);
        }
    }

    public function testLIconeDeLIphoneEstOpaqueEtAppeleeParLesPagesDeLEspace(): void
    {
        $png = (string) file_get_contents(self::RACINE.'/public/images/icone-180.png');
        self::assertSame([180, 180], \array_slice(getimagesizefromstring($png) ?: [], 0, 2));
        // Type de couleur du PNG : 2 = RVB sans transparence (l'iPhone remplit le transparent en noir).
        self::assertSame(2, \ord($png[25]));

        foreach (['espace/app/_app.html.twig', 'espace/_page.html.twig'] as $gabarit) {
            $contenu = (string) file_get_contents(self::RACINE.'/templates/'.$gabarit);
            self::assertStringContainsString('<link rel="manifest" href="/espace.webmanifest">', $contenu, $gabarit);
            self::assertStringContainsString('rel="apple-touch-icon" sizes="180x180" href="{{ asset(\'images/icone-180.png\') }}"', $contenu, $gabarit);
        }
    }

    public function testLeServiceWorkerNeGardeQueLaPageSansConnexion(): void
    {
        $sw = (string) file_get_contents(self::RACINE.'/public/sw.js');

        // L'écoute des pages n'existe que pour l'espace client : le personnel (portée « / ») garde le comportement d'avant.
        $espace = strpos($sw, 'if (ESPACE) {');
        self::assertNotFalse($espace);
        self::assertSame(1, substr_count($sw, "addEventListener('fetch'"));
        self::assertGreaterThan($espace, strpos($sw, "addEventListener('fetch'"));
        self::assertStringContainsString("r.mode !== 'navigate' || r.method !== 'GET' || (r.destination && r.destination !== 'document')", $sw);

        // Rien de l'espace (pages, montants, fichiers) n'est mis en cache : seule la page « Pas de connexion ».
        self::assertStringNotContainsString('.put(', $sw);
        self::assertStringNotContainsString('addAll(', $sw);
        self::assertSame(1, substr_count($sw, 'c.add('));
        self::assertStringContainsString("var HORS_LIGNE = '/hors-ligne-espace.html';", $sw);

        $page = (string) file_get_contents(self::RACINE.'/public/hors-ligne-espace.html');
        self::assertStringContainsString('Pas de connexion internet', $page);
        self::assertStringNotContainsString('{{', $page);
        self::assertDoesNotMatchRegularExpression('~\b(?:src|href)\s*=\s*["\'](?:https?:)?//~i', $page, 'Tout est dans la page.');
    }

    public function testLaCarteNeMontreNiMontantNiDonneePersonnelle(): void
    {
        $twig = new Environment(new FilesystemLoader(self::RACINE.'/templates'), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('asset', static fn (string $chemin): string => '/'.$chemin));
        $twig->addFunction(new TwigFunction('url', static fn (string $route): string => 'https://site.example/espace'));

        // Aucune variable de la page : la carte ne peut rien dire du client.
        $html = $twig->render('espace/app/_installer_carte.html.twig');

        self::assertStringContainsString('Installez Succès Imprim sur votre téléphone', $html);
        self::assertStringContainsString('>Ajouter à l\'écran d\'accueil</button>', $html);
        self::assertStringContainsString('>Plus tard</button>', $html);
        self::assertStringContainsString('Sur l\'écran d\'accueil', $html);
        self::assertStringContainsString('<svg', $html, 'L\'icône Partager est dessinée dans la page.');
        self::assertDoesNotMatchRegularExpression('~\d[\d  ]*\s?(?:F|FCFA)\b~u', strip_tags($html));
        self::assertDoesNotMatchRegularExpression('~<img[^>]+src="(?:https?:)?//~', $html);
        self::assertStringContainsString('hidden aria-labelledby="ec-inviter-titre"', $html, 'Cachée tant que le script ne la montre pas.');
    }

    public function testLaCarteNEstQueSurLeTableauDeBordDuSiteEnLigne(): void
    {
        $inclusions = [];
        $scripts = [];
        $gabarits = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::RACINE.'/templates', \FilesystemIterator::SKIP_DOTS));
        foreach ($gabarits as $fichier) {
            $contenu = (string) file_get_contents((string) $fichier);
            $nom = substr((string) $fichier, \strlen(self::RACINE.'/templates/'));
            if (str_contains($contenu, "'espace/app/_installer_carte.html.twig'")) {
                $inclusions[] = $nom;
            }
            if (str_contains($contenu, "asset('js/espace-installer.js')")) {
                $scripts[] = $nom;
            }
        }
        sort($scripts);

        self::assertSame(['espace/app/accueil.html.twig'], $inclusions);
        self::assertSame(['espace/app/accueil.html.twig', 'espace/app/compte.html.twig'], $scripts);
        $accueil = (string) file_get_contents(self::RACINE.'/templates/espace/app/accueil.html.twig');
        self::assertStringContainsString("{% if site_en_ligne() %}{% include 'espace/app/_installer_carte.html.twig' %}{% endif %}", $accueil);
    }
}
