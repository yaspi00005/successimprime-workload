<?php

namespace App\Twig;

use App\Controller\UtilisateursController;
use App\Entity\Entreprise;
use App\Repository\EntrepriseRepository;
use App\Security\AccesEnLigne;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Site vitrine (maquettes 01 à 12) : menu, entreprise affichée en pied de
 * page et lien WhatsApp de l'atelier. Une page pas encore construite
 * n'apparaît pas dans le menu.
 */
final class SiteExtension extends AbstractExtension
{
    /** Menu de l'en-tête, dans l'ordre des maquettes : route => libellé. */
    public const MENU = [
        'app_site_accueil' => 'Accueil',
        'app_site_produits' => 'Produits',
        'app_site_calculateur' => 'Calculateur',
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

    /** Entreprise du site : celle qui porte le nom commercial (Succès Imprim). */
    public const CODE_ENTREPRISE = 'mdg_success';

    private ?Entreprise $entreprise = null;
    private bool $entrepriseLue = false;

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly EntrepriseRepository $entreprises,
        private readonly AccesEnLigne $acces,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('site_lien', $this->lien(...)),
            new TwigFunction('site_menu', fn (array $liens = self::MENU): array => $this->menu($liens)),
            new TwigFunction('site_pied', fn (): array => $this->menu(self::PIED)),
            new TwigFunction('site_entreprise', $this->entreprise(...)),
            new TwigFunction('site_whatsapp', $this->whatsapp(...)),
        ];
    }

    /** Adresse d'une page du site, ou null si elle n'existe pas encore. L'accueil est « / » en ligne. */
    public function lien(string $route, array $parametres = []): ?string
    {
        if ('app_site_accueil' === $route && $this->acces->estEnLigne()) {
            return $this->urls->generate('app_home');
        }
        try {
            return $this->urls->generate($route, $parametres);
        } catch (RouteNotFoundException) {
            return null;
        }
    }

    /**
     * @param array<string, string> $liens
     *
     * @return list<array{route: string, libelle: string, url: string}>
     */
    private function menu(array $liens): array
    {
        $menu = [];
        foreach ($liens as $route => $libelle) {
            if (null !== $url = $this->lien($route)) {
                $menu[] = ['route' => $route, 'libelle' => $libelle, 'url' => $url];
            }
        }

        return $menu;
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
