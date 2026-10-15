<?php

namespace App\Twig;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Twig\Cache\NullCache;
use Twig\Environment;
use Twig\Error\Error;
use Twig\NodeVisitor\EscaperNodeVisitor;

/**
 * Prépare le cache des gabarits Twig avec peu de mémoire
 * (docs/deploiement.md, « Mémoire de cache:clear »).
 *
 * Le réchauffeur de Symfony charge chaque gabarit compilé dans le
 * processus, et Twig garde en plus chaque nœud analysé pour l'échappement :
 * avec plus de 500 gabarits, `cache:clear` en production montait à
 * 258 Mo et échouait sous la limite de 256 Mo de l'hébergement.
 *
 * Ici chaque gabarit est compilé puis écrit dans le fichier de cache que
 * Twig lira (même clé), sans charger sa classe, et la mémoire de
 * l'analyse est vidée entre deux gabarits. Si la clé ne correspondait
 * plus après une mise à jour de Twig, un gabarit serait simplement
 * compilé à sa première ouverture, comme sans cache.
 */
#[AsDecorator('twig.template_cache_warmer', onInvalid: ContainerInterface::IGNORE_ON_INVALID_REFERENCE)]
final class GabaritsCacheWarmer implements CacheWarmerInterface
{
    /**
     * @param \Closure(): Environment $twig     différé : un réchauffeur facultatif ne construit rien tant qu'il ne sert pas
     * @param iterable<string>        $gabarits les gabarits de l'application et des bundles
     */
    public function __construct(
        #[AutowireServiceClosure('twig')] private readonly \Closure $twig,
        #[Autowire(service: 'twig.template_iterator')] private readonly iterable $gabarits,
    ) {
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        /** @var Environment $twig */
        $twig = ($this->twig)();
        $cache = $twig->getCache(false);
        if ($cache instanceof NullCache) {
            return [];
        }

        foreach ($this->gabarits as $nom) {
            try {
                $code = $twig->compileSource($twig->getLoader()->getSourceContext($nom));
                $cache->write($cache->generateKey($nom, $twig->getTemplateClass($nom)), $code);
            } catch (Error) {
                // Comme le réchauffeur de Symfony : un gabarit qui ne se compile pas ici (fonction absente
                // dans cet environnement) est laissé de côté ; lint:twig sert à trouver les vraies erreurs.
            } finally {
                self::oublierAnalyse($twig);
            }
        }

        return [];
    }

    public function isOptional(): bool
    {
        return true;
    }

    /**
     * Twig garde chaque nœud dont il a analysé l'échappement
     * (SafeAnalysisNodeVisitor), sans jamais le relâcher : environ 70 Mo
     * pour tous nos gabarits. Ce n'est utile que pendant la compilation
     * d'un gabarit ; on le vide donc après chacun.
     */
    private static function oublierAnalyse(Environment $twig): void
    {
        try {
            foreach ($twig->getNodeVisitors() as $visiteur) {
                if (!$visiteur instanceof EscaperNodeVisitor) {
                    continue;
                }
                $analyse = (new \ReflectionProperty(EscaperNodeVisitor::class, 'safeAnalysis'))->getValue($visiteur);
                if (\is_object($analyse) && property_exists($analyse, 'data')) {
                    (new \ReflectionProperty($analyse, 'data'))->setValue($analyse, []);
                }
            }
        } catch (\ReflectionException) {
            // Twig a changé : on garde seulement le surcoût de mémoire.
        }
    }
}
