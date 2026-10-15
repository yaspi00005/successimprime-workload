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
use App\Service\Site\PhotosSite;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Contenu du site vitrine dans les gabarits : texte en Markdown simplifié
 * (déjà échappé), images, photos livrées avec le site, chiffres et
 * partenaires.
 */
final class SiteContenuExtension extends AbstractExtension
{
    public function __construct(
        private readonly ImagesSite $images,
        private readonly ChiffresSite $chiffres,
        private readonly PartenaireRepository $partenaires,
        private readonly PhotosSite $photos,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('site_markdown', MarkdownSite::html(...), ['is_safe' => ['html']]),
            new TwigFilter('site_texte', MarkdownSite::texte(...)),
            new TwigFilter('site_sans_demo', self::sansDemo(...)),
            new TwigFilter('site_sans_balises', self::sansBalises(...)),
            new TwigFilter('site_date', self::date(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_sections', MarkdownSite::sections(...)),
            // Les blocs viennent de MarkdownSite, qui échappe tout le texte saisi.
            new TwigFunction('site_blocs', MarkdownSite::rendreBlocs(...), ['is_safe' => ['html']]),
            new TwigFunction('site_image', $this->image(...)),
            new TwigFunction('site_photo', $this->photos->photo(...)),
            new TwigFunction('site_photos_sections', $this->photos->pourSections(...)),
            new TwigFunction('site_icone', IconesSite::pour(...)),
            new TwigFunction('site_famille', static fn (?string $nom, int $rang = 0): string => SiteController::famille(self::sansDemo((string) $nom), $rang)),
            new TwigFunction('site_chiffres', $this->chiffres->aMontrer(...)),
            new TwigFunction('site_partenaires', $this->partenaires(...)),
            new TwigFunction('site_json_ld', self::jsonLd(...), ['is_safe' => ['html']]),
            new TwigFunction('site_lien_de', self::lienDe(...)),
            new TwigFunction('site_lien_sur', static fn (?string $url): ?string => null === $url ? null : MarkdownSite::lienSur($url)),
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

    /** Texte d'un morceau de HTML produit par MarkdownSite (élément de liste…). */
    public static function sansBalises(?string $html): string
    {
        return trim(html_entity_decode(strip_tags((string) $html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }

    /** Adresse du premier lien d'un morceau de HTML produit par MarkdownSite, revérifiée. */
    public static function lienDe(?string $html): ?string
    {
        if (!preg_match('/<a href="([^"]*)"/', (string) $html, $m)) {
            return null;
        }

        return MarkdownSite::lienSur(html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
    }

    public const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /** « 14 octobre 2026 », ou « octobre 2026 » sans le jour. */
    public static function date(?\DateTimeInterface $date, bool $jour = true): string
    {
        if (null === $date) {
            return '';
        }
        $mois = self::MOIS[(int) $date->format('n') - 1].' '.$date->format('Y');

        return $jour ? ('1' === $date->format('j') ? '1er' : $date->format('j')).' '.$mois : $mois;
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
