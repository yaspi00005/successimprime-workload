<?php

namespace App\Twig;

use App\Service\Site\CatalogueSite;
use App\Service\Site\EtatCatalogue;
use Twig\Attribute\AsTwigFunction;

/**
 * Colonne « Produits » du pied de page (maquette SW03) : les univers du
 * catalogue publié, chacun vers sa liste de produits ; catalogue vide :
 * rien (le pied montre alors « Devis gratuit » et le Studio textile).
 */
final class SitePiedExtension
{
    public const NOMBRE = 5;

    public function __construct(
        private readonly CatalogueSite $catalogue,
        private readonly EtatCatalogue $etat,
        private readonly SiteExtension $site,
    ) {
    }

    /** @return list<array{libelle: string, url: string}> */
    #[AsTwigFunction('site_pied_produits')]
    public function produits(): array
    {
        if (!$this->etat->aDesProduits()) {
            return [];
        }
        $liens = [];
        foreach (\array_slice($this->catalogue->univers(), 0, self::NOMBRE) as $u) {
            $url = $this->site->lien('app_site_produits', ['categorie' => $u['cle']]);
            if (null !== $url) {
                $liens[] = ['libelle' => $u['nom'], 'url' => $url];
            }
        }

        return $liens;
    }
}
