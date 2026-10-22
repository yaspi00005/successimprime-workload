<?php

namespace App\Service\Assistant;

use App\Entity\Clients;
use App\Entity\ProduitConfiguration;
use App\Entity\Promotion;
use App\Repository\PageSiteRepository;
use App\Service\Promotions\Promotions;
use App\Service\Site\Calculateur;
use App\Service\Site\CatalogueSite;
use App\Service\Site\ContactSite;
use App\Twig\SiteExtension;

/** SourceSite branchée sur les services du site vitrine. */
final class SourceSiteApplication implements SourceSite
{
    /** Page publiée => route du site. */
    public const PAGES = [
        'faq' => 'app_site_faq',
        'guide-fichiers' => 'app_site_guide',
        'nos-engagements' => 'app_site_engagements',
        'a-propos' => 'app_site_a_propos',
        'equipements' => 'app_site_equipements',
    ];

    public function __construct(
        private readonly CatalogueSite $catalogue,
        private readonly Calculateur $calculateur,
        private readonly ContactSite $contact,
        private readonly SiteExtension $site,
        private readonly PageSiteRepository $pages,
        private readonly Promotions $promotions,
    ) {
    }

    public function configurations(): array
    {
        return $this->catalogue->configurations();
    }

    public function estimation(ProduitConfiguration $configuration, array $valeurs): array
    {
        return $this->calculateur->resultat($configuration, $valeurs);
    }

    public function boutique(\DateTimeImmutable $maintenant): array
    {
        $entreprise = $this->site->entreprise();
        $horaires = $this->contact->horaires($maintenant);

        return [
            'nom' => $entreprise?->getNomAffiche() ?? 'Succès Imprim',
            'adresse' => $entreprise?->getAdresse(),
            'telephone' => $entreprise?->getTelephone(),
            'email' => $entreprise?->getEmail(),
            'horaires' => $horaires['texte'],
            'ouvert' => $horaires['etat'],
            'plan' => $this->contact->lienPlan(),
        ];
    }

    public function page(string $slug): ?array
    {
        if (!isset(self::PAGES[$slug])) {
            return null;
        }
        $page = $this->pages->publiee($slug);

        return null === $page ? null : ['titre' => $page->getTitre(), 'texte' => $page->getCorps(), 'url' => $this->site->lien(self::PAGES[$slug])];
    }

    public function promotions(\DateTimeImmutable $jour, ?Clients $client): array
    {
        if (null !== $client) {
            return $this->promotions->pourLeClient($client, $jour);
        }

        // Sur le site : seulement les offres faites à tout le monde, sans code ni liste de clients.
        return array_values(array_filter(
            $this->promotions->valables($jour),
            static fn (Promotion $p): bool => Promotion::SEGMENT_TOUS === $p->getSegment() && $p->estSansCode() && !$p->estAnniversaire() && 0 === $p->getClients()->count(),
        ));
    }

    public function lien(string $route, array $parametres = []): ?string
    {
        return $this->site->lien($route, $parametres);
    }
}
