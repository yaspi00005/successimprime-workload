<?php

namespace App\Twig;

use App\Entity\ArticleConseil;
use App\Service\Parametres\Parametres;
use App\Service\Site\AdresseSite;
use App\Service\Site\ContactSite;
use App\Service\Site\DonneesStructurees;
use App\Service\Site\MesureAudience;
use App\Service\Site\ReferencementSite;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Référencement du site vitrine dans les gabarits (docs/referencement.md) :
 * adresse canonique, titre de la page, données structurées, codes de
 * vérification des moteurs de recherche, mesure d'audience Google,
 * réseaux sociaux, image de partage et feuilles de style écrites dans la
 * page (une requête de moins avant le premier affichage sur un téléphone
 * en 3G).
 */
final class ReferencementExtension extends AbstractExtension
{
    /** Longueur visée d'un titre : au-delà, Google le coupe. */
    public const TITRE_MAX = 60;

    /** Nom de l'imprimerie dans les titres. */
    public const NOM = 'Succès Imprim';

    /** Suffixes du titre, du plus complet au plus court. */
    public const SUFFIXES_TITRE = [' · Succès Imprim, imprimerie à Bamako', ' · Succès Imprim'];

    /**
     * Image de partage par défaut (réseaux sociaux, WhatsApp) : le visuel de
     * la façade et de l'enseigne, 1200 × 630 (photo livrée avec le site,
     * docs/site-vitrine.md). Si le fichier manque : le logo sur fond blanc.
     */
    public const IMAGE_PARTAGE = ['chemin' => 'images/site/partage-enseigne-succes-imprim.jpg', 'largeur' => 1200, 'hauteur' => 630];
    public const IMAGE_PARTAGE_LOGO = ['chemin' => 'images/site/partage-succes-imprim.jpg', 'largeur' => 1200, 'hauteur' => 630];

    /**
     * Image de la fiche de l'imprimerie pour Google (LocalBusiness) : la
     * vraie façade (photos du local du 17/10/2026), en 16:9, 4:3 et 1:1
     * comme Google le recommande. Les fichiers absents sont ignorés ; sans
     * aucun, l'image de partage.
     */
    public const IMAGES_FICHE = [
        'images/site/photos/facade-local-succes-imprim-bamako-1200.webp',
        'images/site/fiche/facade-local-succes-imprim-bamako-4x3.webp',
        'images/site/fiche/facade-local-succes-imprim-bamako-1x1.webp',
    ];

    /** Logo pour Google (au moins 112 px de côté). */
    public const LOGO = ['chemin' => 'images/logo-succes-imprim.png', 'largeur' => 640, 'hauteur' => 357];

    /** @var array<string, ?string> feuilles de style lues, par chemin */
    private static array $feuilles = [];

    public function __construct(
        private readonly AdresseSite $adresse,
        private readonly ReferencementSite $referencement,
        private readonly SiteExtension $site,
        private readonly Parametres $parametres,
        private readonly RequestStack $requetes,
        private readonly MesureAudience $mesureAudience,
        #[Autowire('%kernel.project_dir%/public')] private readonly string $dossierPublic,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('site_titre', self::titre(...)),
            new TwigFilter('site_description', self::description(...)),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_adresse', $this->adresse->url(...)),
            new TwigFunction('site_canonique', $this->canonique(...)),
            new TwigFunction('site_verifications', $this->verifications(...)),
            // Identifiant de mesure Google de la page affichée, ou null (MesureAudience).
            new TwigFunction('site_mesure_audience', fn (): ?string => $this->mesureAudience->identifiant($this->requetes->getMainRequest())),
            new TwigFunction('site_reseaux', $this->referencement->reseaux(...)),
            new TwigFunction('site_fiche_google', fn (): ?string => $this->referencement->reglages()['fiche_google'] ?: null),
            new TwigFunction('site_donnees_entreprise', $this->donneesEntreprise(...), ['is_safe' => ['html']]),
            new TwigFunction('site_fil_ariane', $this->filAriane(...), ['is_safe' => ['html']]),
            new TwigFunction('site_donnees_article', $this->donneesArticle(...), ['is_safe' => ['html']]),
            new TwigFunction('site_donnees_catalogue', $this->donneesCatalogue(...), ['is_safe' => ['html']]),
            new TwigFunction('site_styles', $this->styles(...), ['is_safe' => ['html']]),
            new TwigFunction('site_image_partage', $this->imagePartage(...)),
        ];
    }

    /**
     * Titre de la page avec le nom de l'imprimerie : le suffixe le plus
     * complet qui tient dans 60 caractères, sinon le plus court, sinon le
     * titre seul (déjà long, il dit de quoi parle la page). Un titre qui
     * porte déjà le nom reste tel quel.
     */
    public static function titre(string $titre): string
    {
        $titre = trim((string) preg_replace('/\s+/u', ' ', $titre));
        if (str_contains(mb_strtolower($titre), mb_strtolower(self::NOM))) {
            return $titre;
        }
        foreach (self::SUFFIXES_TITRE as $suffixe) {
            if (mb_strlen($titre.$suffixe) <= self::TITRE_MAX) {
                return $titre.$suffixe;
            }
        }

        return $titre;
    }

    /** Longueur visée d'une description : au-delà, Google la coupe. */
    public const DESCRIPTION_MAX = 158;

    /**
     * Description d'une page tirée d'un texte : une seule ligne, coupée
     * entre deux mots avant 158 caractères.
     */
    public static function description(?string $texte): string
    {
        $texte = trim((string) preg_replace('/\s+/u', ' ', (string) $texte));
        if (mb_strlen($texte) <= self::DESCRIPTION_MAX) {
            return $texte;
        }
        $coupe = mb_substr($texte, 0, self::DESCRIPTION_MAX - 1);
        $espace = mb_strrpos($coupe, ' ');

        return rtrim(false === $espace || $espace < 80 ? $coupe : mb_substr($coupe, 0, $espace), ' ,;:.–-').'…';
    }

    /**
     * Adresse canonique de la page affichée : adresse du site, chemin sans
     * paramètres (filtres, calculateur prérempli, retours de formulaire).
     * L'aperçu /site de l'ERP local renvoie à l'accueil « / ».
     */
    public function canonique(): string
    {
        return $this->adresse->url(self::cheminCanonique($this->requetes->getMainRequest()));
    }

    /** Chemin canonique d'une requête : sans paramètres ; « / » pour l'aperçu /site. */
    public static function cheminCanonique(?Request $requete): string
    {
        if (null === $requete || 'app_site_accueil' === $requete->attributes->get('_route')) {
            return '/';
        }

        return $requete->getPathInfo();
    }

    /**
     * Balises de vérification réglées (Google Search Console, Bing).
     *
     * @return array<string, string> nom de la balise => code
     */
    public function verifications(): array
    {
        $reglages = $this->referencement->reglages();

        return array_filter(['google-site-verification' => $reglages['google'], 'msvalidate.01' => $reglages['bing']]);
    }

    /** Organisation, imprimerie et site (accueil, contact, à propos). */
    public function donneesEntreprise(): string
    {
        $entreprise = $this->site->entreprise();
        $memeQue = array_column($this->referencement->reseaux(), 'url');
        if ('' !== $fiche = $this->referencement->reglages()['fiche_google']) {
            $memeQue[] = $fiche;
        }

        return SiteContenuExtension::jsonLd(DonneesStructurees::entreprise(
            $this->adresse->base(),
            [
                'nom' => $entreprise?->getNomAffiche() ?? 'Succès Imprim',
                'raisonSociale' => $entreprise?->getRaisonSociale(),
                'telephone' => $entreprise?->getTelephone(),
                'email' => $entreprise?->getEmail(),
                'adresse' => $entreprise?->getAdresse(),
            ],
            ['url' => $this->adresse->url(self::LOGO['chemin']), 'largeur' => self::LOGO['largeur'], 'hauteur' => self::LOGO['hauteur']],
            [
                'jours' => $this->parametres->valeur('horaires', 'jours'),
                'ouverture' => $this->parametres->valeur('horaires', 'ouverture'),
                'fermeture' => $this->parametres->valeur('horaires', 'fermeture'),
            ],
            self::lienPlan((string) $this->parametres->valeur('site_contact', 'lien_plan')),
            $memeQue,
            array_map($this->adresse->url(...), $this->imagesFiche()),
        ));
    }

    /**
     * Images de la fiche de l'imprimerie pour Google : la vraie façade
     * (IMAGES_FICHE), sinon l'image de partage.
     *
     * @return list<string> chemins dans public/
     */
    public function imagesFiche(): array
    {
        $images = array_values(array_filter(self::IMAGES_FICHE, fn (string $chemin): bool => is_file($this->dossierPublic.'/'.$chemin)));

        return [] === $images ? [$this->imagePartage()['chemin']] : $images;
    }

    /**
     * Image de partage par défaut : la façade (IMAGE_PARTAGE), sinon le logo
     * sur fond blanc si la photo a été retirée de public/.
     *
     * @return array{chemin: string, largeur: int, hauteur: int}
     */
    public function imagePartage(): array
    {
        return is_file($this->dossierPublic.'/'.self::IMAGE_PARTAGE['chemin']) ? self::IMAGE_PARTAGE : self::IMAGE_PARTAGE_LOGO;
    }

    /** Le réglage du plan tel que saisi s'il donne des coordonnées, sinon le lien retenu par la page Contact. */
    private static function lienPlan(string $saisi): ?string
    {
        return null !== DonneesStructurees::position($saisi) ? trim($saisi) : ContactSite::lien($saisi);
    }

    /**
     * Fil d'Ariane pour Google, de l'accueil à la page.
     *
     * @param list<array{nom: string, url?: ?string}> $etapes chemins du site (« /conseils ») ; sans url : la page affichée
     */
    public function filAriane(array $etapes): string
    {
        $liste = [['nom' => 'Accueil', 'url' => $this->adresse->url('/')]];
        foreach ($etapes as $etape) {
            $url = $etape['url'] ?? null;
            $liste[] = ['nom' => (string) $etape['nom'], 'url' => null === $url ? $this->canonique() : $this->adresse->url($url)];
        }
        $donnees = DonneesStructurees::filAriane($liste);

        return null === $donnees ? '' : SiteContenuExtension::jsonLd($donnees);
    }

    /** @param array{chemin: string}|null $image */
    public function donneesArticle(ArticleConseil $article, ?array $image = null): string
    {
        return SiteContenuExtension::jsonLd(DonneesStructurees::article(
            $this->adresse->base(),
            $this->canonique(),
            $article->getTitre(),
            $article->getResume(),
            $article->getPublieLe(),
            $article->getModifieLe(),
            null === $image ? null : $this->adresse->url($image['chemin']),
            $this->site->entreprise()?->getNomAffiche() ?? 'Succès Imprim',
            $this->adresse->url(self::LOGO['chemin']),
        ));
    }

    /** @param list<array<string, mixed>> $univers */
    public function donneesCatalogue(array $univers): string
    {
        $donnees = DonneesStructurees::catalogue($this->adresse->base(), $this->canonique(), $univers);

        return null === $donnees ? '' : SiteContenuExtension::jsonLd($donnees);
    }

    /**
     * Feuilles de style du site écrites dans la page : le premier
     * affichage n'attend pas un aller-retour de plus (2 s en 3G lente).
     * Une feuille illisible reste un lien classique.
     */
    public function styles(string ...$chemins): string
    {
        $html = '';
        foreach ($chemins as $chemin) {
            $chemin = ltrim($chemin, '/');
            if (!\array_key_exists($chemin, self::$feuilles)) {
                $fichier = $this->dossierPublic.'/'.$chemin;
                $contenu = str_ends_with($chemin, '.css') && !str_contains($chemin, '..') && is_file($fichier) ? file_get_contents($fichier) : false;
                self::$feuilles[$chemin] = false === $contenu ? null : self::minifier(self::urlsDepuisLaRacine($contenu, $chemin));
            }
            $html .= null === self::$feuilles[$chemin]
                ? '<link rel="stylesheet" href="/'.htmlspecialchars($chemin, \ENT_QUOTES).'">'
                : '<style>'.self::$feuilles[$chemin].'</style>';
        }

        return $html;
    }

    /**
     * Les adresses relatives d'une feuille (« ../vendor/police.woff2 »)
     * partent de la racine du site une fois la feuille écrite dans la page.
     */
    public static function urlsDepuisLaRacine(string $css, string $chemin): string
    {
        $dossier = \dirname('/'.ltrim($chemin, '/'));

        return (string) preg_replace_callback('/url\(\s*([\'"]?)(?![a-z][a-z0-9+.-]*:|\/|#)([^\'")]+)\1\s*\)/i', static function (array $m) use ($dossier): string {
            $parties = [];
            foreach (explode('/', $dossier.'/'.$m[2]) as $partie) {
                if ('..' === $partie) {
                    array_pop($parties);
                } elseif ('' !== $partie && '.' !== $partie) {
                    $parties[] = $partie;
                }
            }

            return 'url('.$m[1].'/'.implode('/', $parties).$m[1].')';
        }, $css);
    }

    /**
     * Feuille de style allégée : sans commentaires ni blancs inutiles.
     * Rien ne peut fermer la balise <style>.
     */
    public static function minifier(string $css): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        $css = (string) preg_replace('/\s+/', ' ', $css);
        $css = (string) preg_replace('/\s*([{};,>])\s*/', '$1', $css);
        $css = str_replace(';}', '}', $css);

        return str_ireplace('</style', '<\/style', trim($css));
    }
}
