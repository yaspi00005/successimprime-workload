<?php

namespace App\Service\Site;

use App\Twig\SiteExtension;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Plan du site vitrine pour les moteurs de recherche : les pages du menu
 * et du pied de page qui existent, la demande de devis, puis les pages à
 * contenu variable fournies par les SourcePlanSite.
 */
final class PlanDuSite
{
    /** Pages fixes hors menu et pied de page. */
    public const AUTRES_PAGES = ['app_site_devis'];

    /**
     * @param iterable<SourcePlanSite> $sources
     */
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        #[AutowireIterator('app.site.plan')] private readonly iterable $sources = [],
    ) {
    }

    /**
     * @return list<array{url: string, modifieLe: ?\DateTimeInterface, priorite: string}>
     */
    public function pages(): array
    {
        $pages = [['url' => $this->urls->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL), 'modifieLe' => null, 'priorite' => '1.0']];
        // « Suivre ma commande » n'est pas indexée (recherche par numéro et téléphone).
        $routes = array_diff(array_keys(SiteExtension::MENU + SiteExtension::PIED), ['app_site_accueil', 'app_site_suivi']);
        foreach ([...$routes, ...self::AUTRES_PAGES] as $route) {
            if (null !== $url = $this->url($route, [])) {
                $pages[] = ['url' => $url, 'modifieLe' => null, 'priorite' => isset(SiteExtension::MENU[$route]) ? '0.8' : '0.6'];
            }
        }
        foreach ($this->sources as $source) {
            foreach ($source->pagesDuPlan() as $page) {
                if (null !== $url = $this->url($page['route'], $page['parametres'] ?? [])) {
                    $pages[] = ['url' => $url, 'modifieLe' => $page['modifieLe'] ?? null, 'priorite' => '0.5'];
                }
            }
        }

        return $pages;
    }

    /** @param array<string, mixed> $parametres */
    private function url(string $route, array $parametres): ?string
    {
        try {
            return $this->urls->generate($route, $parametres, UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (RouteNotFoundException) {
            return null;
        }
    }
}
