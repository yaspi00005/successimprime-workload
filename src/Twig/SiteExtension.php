<?php

namespace App\Twig;

use App\Controller\UtilisateursController;
use App\Entity\Entreprise;
use App\Repository\EntrepriseRepository;
use App\Security\AccesEnLigne;
use App\Service\Site\ActionsSociales;
use App\Service\Site\EtatCatalogue;
use App\Service\Site\JetonsSite;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Site vitrine (maquettes 01 à 12) : menu, entreprise affichée en pied de
 * page et lien WhatsApp de l'atelier. Une page pas encore construite
 * n'apparaît pas dans le menu. Référencement (titres, adresse canonique,
 * données pour Google) : ReferencementExtension.
 */
final class SiteExtension extends AbstractExtension
{
    /** Menu de l'en-tête, dans l'ordre des maquettes : route => libellé. */
    public const MENU = [
        'app_site_accueil' => 'Accueil',
        'app_site_produits' => 'Produits',
        'app_site_calculateur' => 'Calculateur',
        'app_site_atelier' => 'Studio textile',
        'app_site_realisations' => 'Réalisations',
        'app_site_engagements' => 'Nos engagements',
        'app_site_contact' => 'Contact',
    ];

    /** Pied de page : liens secondaires. */
    public const PIED = [
        'app_site_equipements' => 'Nos équipements',
        'app_site_guide' => 'Préparer son fichier',
        'app_site_a_propos' => 'Qui sommes-nous ?',
        'app_site_faq' => 'Questions fréquentes',
        'app_site_suivi' => 'Suivre ma commande',
        'app_site_conseils' => 'Conseils',
    ];

    /**
     * Pied de page : liens qui ne paraissent qu'avec leur contenu (« Nos
     * actions sociales » dès la première action montrée, ActionsSociales).
     * Hors de PIED : le plan du site ne les prend que par leur source.
     */
    public const PIED_SELON_CONTENU = [
        ActionsSociales::ROUTE => 'Nos actions sociales',
    ];

    /** Entreprise du site : celle qui porte le nom commercial (Succès Imprim). */
    public const CODE_ENTREPRISE = 'mdg_success';

    private ?Entreprise $entreprise = null;
    private bool $entrepriseLue = false;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly EntrepriseRepository $entreprises,
        private readonly AccesEnLigne $acces,
        private readonly ?ActionsSociales $actionsSociales = null,
        private readonly ?JetonsSite $jetons = null,
        private readonly ?EtatCatalogue $catalogue = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_lien', $this->lien(...)),
            new TwigFunction('site_menu', fn (array $liens = self::MENU): array => $this->menu($liens)),
            new TwigFunction('site_pied', fn (): array => $this->menu(self::PIED + $this->piedSelonContenu())),
            new TwigFunction('site_entreprise', $this->entreprise(...)),
            new TwigFunction('site_whatsapp', $this->whatsapp(...)),
            new TwigFunction('site_en_ligne', $this->acces->estEnLigne(...)),
            new TwigFunction('site_jeton', $this->jeton(...)),
            // Catalogue public (EtatCatalogue) : produits montrables, calculateur utilisable.
            new TwigFunction('site_catalogue', fn (): array => ['produits' => $this->catalogue?->aDesProduits() ?? true, 'calculateur' => $this->catalogue?->aUnCalculateur() ?? true]),
        ];
    }

    /**
     * Adresse d'une page du site, ou null si elle n'existe pas encore.
     * L'accueil est « / » en ligne. Sur les pages du site, les
     * identifiants de produit et de finitions deviennent des jetons
     * (JetonsSite::PARAMETRES_PRODUIT) : jamais d'identifiant de la base
     * dans une adresse publique.
     */
    public function lien(string $route, array $parametres = []): ?string
    {
        if ('app_site_accueil' === $route && $this->acces->estEnLigne()) {
            return $this->urls->generate('app_home');
        }
        if (null !== $this->jetons && str_starts_with($route, 'app_site_')) {
            $parametres = $this->jetons->encoderParametres($parametres);
        }
        try {
            return $this->urls->generate($route, $parametres);
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /**
     * Paramètres de produit d'une adresse publique en jetons (lien du site
     * vers l'espace client, comme « Commander » du calculateur).
     *
     * @param array<string, mixed> $parametres
     *
     * @return array<string, mixed>
     */
    public function jetons(array $parametres): array
    {
        return null === $this->jetons ? $parametres : $this->jetons->encoderParametres($parametres);
    }

    /** Jeton d'adresse d'un identifiant (JetonsSite) : configuration, finition… */
    public function jeton(string $type, int|string|null $id): string
    {
        if (null === $this->jetons || !JetonsSite::estIdentifiant($id)) {
            return '';
        }

        return $this->jetons->encoder($type, (int) $id);
    }

    /**
     * @param array<string, string> $liens
     *
     * @return list<array{route: string, libelle: string, url: string}>
     */
    public function menu(array $liens): array
    {
        $menu = [];
        // « Produits » et « Calculateur » sans rien à montrer : hors du menu (EtatCatalogue).
        $liens = array_diff_key($liens, array_flip($this->catalogue?->routesVides() ?? []));
        foreach ($liens as $route => $libelle) {
            if (null !== $url = $this->lien($route)) {
                $menu[] = ['route' => $route, 'libelle' => $libelle, 'url' => $url];
            }
        }

        return $menu;
    }

    /** @return array<string, string> liens du pied de page dont le contenu est là */
    private function piedSelonContenu(): array
    {
        return true === $this->actionsSociales?->visibles() ? self::PIED_SELON_CONTENU : [];
    }

    public function entreprise(): ?Entreprise
    {
        if (!$this->entrepriseLue) {
            $this->entrepriseLue = true;
            $this->entreprise = $this->entreprises->parCode(self::CODE_ENTREPRISE);
            foreach (null === $this->entreprise ? $this->entreprises->dansLOrdre() : [] as $e) {
                if ($e->isActif()) {
                    $this->entreprise = $e;
                    break;
                }
            }
        }

        return $this->entreprise;
    }

    /** Lien wa.me vers l'atelier, avec un premier message. */
    public function whatsapp(string $texte = 'Bonjour Succès Imprim, '): ?string
    {
        $telephone = trim((string) $this->entreprise()?->getTelephone());

        return '' === $telephone ? null : 'https://wa.me/'.UtilisateursController::numeroWhatsApp($telephone).'?text='.rawurlencode($texte);
    }
}
