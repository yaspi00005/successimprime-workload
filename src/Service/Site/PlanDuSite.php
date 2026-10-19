<?php

namespace App\Service\Site;

use App\Entity\PageSite;
use App\Twig\SiteExtension;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Routing\Exception\ExceptionInterface as ExceptionRoutage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Plan du site vitrine pour les moteurs de recherche (sitemap.xml,
 * docs/referencement.md) : l'accueil, les pages du menu et du pied de page
 * qui existent, la demande de devis, puis ce que donnent les
 * SourcePlanSite (pages de contenu publiées, réalisations, conseils).
 *
 * Toutes les adresses sont sur l'adresse publique du site (AdresseSite),
 * jamais sur celle de l'ERP local ou de la requête. La date de dernière
 * modification n'est donnée que si elle est connue : une page fixe
 * (calculateur, contact) n'en a pas, plutôt qu'une date fausse.
 */
final class PlanDuSite
{
    /** Pages fixes hors menu et pied de page. */
    public const AUTRES_PAGES = ['app_site_devis'];

    /** Jamais dans le plan : « Suivre ma commande » (recherche par numéro et téléphone, noindex) et l'aperçu /site. */
    public const EXCLUES = ['app_site_accueil', 'app_site_suivi'];

    /**
     * @param iterable<SourcePlanSite> $sources
     */
    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly AdresseSite $adresse,
        #[AutowireIterator('app.site.plan')] private readonly iterable $sources = [],
        private readonly ?EtatCatalogue $catalogue = null,
    ) {
    }

    /**
     * @return list<array{url: string, modifieLe: ?\DateTimeInterface, priorite: string, images: list<string>}>
     */
    public function pages(): array
    {
        /** @var array<string, array{url: string, modifieLe: ?\DateTimeInterface, priorite: string, images: list<string>}> $pages */
        $pages = [];
        $ajouter = function (string $route, array $parametres, string $priorite, ?\DateTimeInterface $modifieLe = null, array $images = []) use (&$pages): void {
            if (null === $chemin = $this->chemin($route, $parametres)) {
                return;
            }
            $url = $this->adresse->url($chemin);
            if (isset($pages[$url])) {
                // Une source précise la date ou les images d'une page déjà listée.
                $pages[$url]['modifieLe'] = self::plusRecente($pages[$url]['modifieLe'], $modifieLe);
                $pages[$url]['images'] = array_values(array_unique([...$pages[$url]['images'], ...$images]));

                return;
            }
            $pages[$url] = ['url' => $url, 'modifieLe' => $modifieLe, 'priorite' => $priorite, 'images' => array_values(array_unique($images))];
        };

        $ajouter('app_home', [], '1.0');
        // Les pages de contenu (équipements, FAQ…) n'entrent que publiées : c'est PlanPages qui les donne.
        $pagesDeContenu = array_column(PageSite::PAGES, 'route');
        // « Produits » et « Calculateur » vides : pas proposés aux moteurs (EtatCatalogue).
        $pagesDeContenu = [...$pagesDeContenu, ...($this->catalogue?->routesVides() ?? [])];
        foreach ([...array_keys(SiteExtension::MENU + SiteExtension::PIED), ...self::AUTRES_PAGES] as $route) {
            if (!\in_array($route, [...self::EXCLUES, ...$pagesDeContenu], true)) {
                $ajouter($route, [], isset(SiteExtension::MENU[$route]) ? '0.8' : '0.6');
            }
        }
        foreach ($this->sources as $source) {
            foreach ($source->pagesDuPlan() as $page) {
                if (\in_array($page['route'], self::EXCLUES, true)) {
                    continue;
                }
                $images = array_map($this->adresse->url(...), $page['images'] ?? []);
                $ajouter($page['route'], $page['parametres'] ?? [], $page['priorite'] ?? (isset(SiteExtension::MENU[$page['route']]) ? '0.8' : '0.5'), $page['modifieLe'] ?? null, $images);
            }
        }

        // L'accueil change quand le contenu change.
        $accueil = $this->adresse->url('/');
        if (isset($pages[$accueil])) {
            foreach ($pages as $page) {
                $pages[$accueil]['modifieLe'] = self::plusRecente($pages[$accueil]['modifieLe'], $page['modifieLe']);
            }
        }

        return array_values($pages);
    }

    private static function plusRecente(?\DateTimeInterface $a, ?\DateTimeInterface $b): ?\DateTimeInterface
    {
        if (null === $a || null === $b) {
            return $a ?? $b;
        }

        return $b > $a ? $b : $a;
    }

    /** @param array<string, mixed> $parametres */
    private function chemin(string $route, array $parametres): ?string
    {
        try {
            return $this->urls->generate($route, $parametres, UrlGeneratorInterface::ABSOLUTE_PATH);
        } catch (ExceptionRoutage) {
            return null;
        }
    }
}
