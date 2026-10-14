<?php

namespace App\Twig;

use App\Controller\SiteController;
use App\Entity\ImageSite;
use App\Entity\Partenaire;
use App\Repository\PartenaireRepository;
use App\Service\Site\ChiffresSite;
use App\Service\Site\IconesSite;
use App\Service\Site\ImagesSite;
use App\Service\Site\MarkdownSite;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Contenu du site vitrine dans les gabarits : texte en Markdown simplifié
 * (déjà échappé), images, chiffres et partenaires.
 */
final class SiteContenuExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImagesSite $images,
        private readonly ChiffresSite $chiffres,
        private readonly PartenaireRepository $partenaires,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('site_markdown', MarkdownSite::html(...), ['is_safe' => ['html']]),
            new TwigFilter('site_texte', MarkdownSite::texte(...)),
            new TwigFilter('site_sans_demo', self::sansDemo(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_sections', MarkdownSite::sections(...)),
            // Les blocs viennent de MarkdownSite, qui échappe tout le texte saisi.
            new TwigFunction('site_blocs', MarkdownSite::rendreBlocs(...), ['is_safe' => ['html']]),
            new TwigFunction('site_image', $this->image(...)),
            new TwigFunction('site_icone', IconesSite::pour(...)),
            new TwigFunction('site_famille', static fn (?string $nom, int $rang = 0): string => SiteController::famille(self::sansDemo((string) $nom), $rang)),
            new TwigFunction('site_chiffres', $this->chiffres->aMontrer(...)),
            new TwigFunction('site_partenaires', $this->partenaires(...)),
            new TwigFunction('site_json_ld', self::jsonLd(...), ['is_safe' => ['html']]),
        ];
    }

    /** @return array{chemin: string, largeur: int, hauteur: int}|null */
    public function image(?ImageSite $image, string $taille = 'grande'): ?array
    {
        return null === $image ? null : $this->images->fichier($image, $taille);
    }

    /** @return list<Partenaire> */
    public function partenaires(): array
    {
        return $this->partenaires->visibles();
    }

    /** « Grand format (démo) » donne « Grand format ». */
    public static function sansDemo(?string $texte): string
    {
        return trim(str_replace('(démo)', '', (string) $texte));
    }

    /**
     * Données structurées (schema.org) pour une balise script : « < » et
     * « & » échappés, rien ne peut fermer la balise.
     *
     * @param array<string, mixed> $donnees
     */
    public static function jsonLd(array $donnees): string
    {
        return '<script type="application/ld+json">'.json_encode($donnees, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES).'</script>';
    }
}
