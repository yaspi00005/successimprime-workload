<?php

namespace App\Tests\Unit\Twig;

use App\Twig\GabaritsCacheWarmer;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\LoaderError;
use Twig\Loader\ArrayLoader;
use Twig\Loader\LoaderInterface;
use Twig\NodeVisitor\EscaperNodeVisitor;
use Twig\Source;

/**
 * Le cache des gabarits se prépare sans charger les gabarits en mémoire
 * (cache:clear sous 256 Mo sur l'hébergement, docs/deploiement.md).
 */
final class GabaritsCacheWarmerTest extends TestCase
{
    private const GABARITS = [
        'base.html.twig' => '<p>{% block corps %}{% endblock %}</p>',
        'page.html.twig' => '{% extends "base.html.twig" %}{% block corps %}{{ texte }} {{ (noms[cle] ?? cle)|upper }}{% endblock %}',
        'casse.html.twig' => '{{ fonction_absente() }}',
    ];

    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir().'/si-gabarits-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dossier)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dossier, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
                $f->isDir() ? rmdir((string) $f) : unlink((string) $f);
            }
            rmdir($this->dossier);
        }
    }

    public function testLesGabaritsCompilesSontCeuxQueTwigLiraEnsuite(): void
    {
        $twig = new Environment(new ArrayLoader(self::GABARITS), ['cache' => $this->dossier]);
        $warmer = new GabaritsCacheWarmer(static fn (): Environment => $twig, array_keys(self::GABARITS));

        self::assertTrue($warmer->isOptional());
        self::assertSame([], $warmer->warmUp($this->dossier));
        // Deux gabarits compilés ; celui qui ne se compile pas est laissé de côté.
        self::assertCount(2, glob($this->dossier.'/*/*.php') ?: []);
        // Aucune classe de gabarit chargée pendant la préparation.
        self::assertFalse(class_exists($twig->getTemplateClass('page.html.twig'), false));

        // Une autre requête (même réglage) lit le cache sans jamais recompiler la source.
        $lecture = new Environment(new class(new ArrayLoader(self::GABARITS)) implements LoaderInterface {
            public function __construct(private readonly ArrayLoader $source)
            {
            }

            public function getSourceContext(string $name): Source
            {
                throw new LoaderError('Le gabarit aurait dû venir du cache : '.$name);
            }

            public function getCacheKey(string $name): string
            {
                return $this->source->getCacheKey($name);
            }

            public function isFresh(string $name, int $time): bool
            {
                return $this->source->isFresh($name, $time);
            }

            public function exists(string $name): bool
            {
                return $this->source->exists($name);
            }
        }, ['cache' => $this->dossier]);
        self::assertSame('<p>&lt;b&gt;Bonjour CLIENT</p>', $lecture->render('page.html.twig', ['texte' => '<b>Bonjour', 'noms' => ['c' => 'client'], 'cle' => 'c']));
    }

    public function testLaMemoireDeLAnalyseDEchappementEstVideeEntreDeuxGabarits(): void
    {
        $twig = new Environment(new ArrayLoader(self::GABARITS), ['cache' => $this->dossier]);
        (new GabaritsCacheWarmer(static fn (): Environment => $twig, array_keys(self::GABARITS)))->warmUp($this->dossier);

        foreach ($twig->getNodeVisitors() as $visiteur) {
            if ($visiteur instanceof EscaperNodeVisitor) {
                $analyse = (new \ReflectionProperty(EscaperNodeVisitor::class, 'safeAnalysis'))->getValue($visiteur);
                self::assertSame([], (new \ReflectionProperty($analyse, 'data'))->getValue($analyse));

                return;
            }
        }
        self::fail('Visiteur d\'échappement introuvable');
    }

    public function testSansCacheTwigIlNYARienAPreparer(): void
    {
        $twig = new Environment(new ArrayLoader(self::GABARITS), ['cache' => false]);
        (new GabaritsCacheWarmer(static fn (): Environment => $twig, array_keys(self::GABARITS)))->warmUp($this->dossier);

        self::assertDirectoryDoesNotExist($this->dossier);
    }
}
