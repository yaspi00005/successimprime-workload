<?php

namespace App\Service\Site;

use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce que le catalogue public peut montrer en ce moment (docs/site-vitrine.md,
 * « Catalogue vide ») : des produits sur « Nos produits », un produit
 * chiffrable dans le calculateur. Tant qu'il n'y a rien, le site ne promet
 * ni « prix de départ » ni « prix immédiat » : « Produits » et « Calculateur »
 * quittent le menu et le plan du site, l'accueil propose « Demander un
 * devis » et les descriptions pour Google changent.
 *
 * Mêmes conditions que la page (CatalogueSite::univers) et que le
 * calculateur (NouvelleCommande::produits), en deux comptages légers.
 */
class EtatCatalogue
{
    private ?bool $produits = null;
    private ?bool $calculateur = null;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Au moins un produit publié, actif et visible en ligne, dans une catégorie publiée. */
    public function aDesProduits(): bool
    {
        return $this->produits ??= 0 < (int) $this->em->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Produits::class, 'p')
            ->innerJoin('p.categorieProduit', 'cat')
            ->where('p.publie = true')->andWhere('p.actif = true')->andWhere('p.visibleEnLigne = true')->andWhere('cat.publie = true')
            ->getQuery()->getSingleScalarResult();
    }

    /** Au moins une configuration active avec un prix, d'un produit vendu en ligne. */
    public function aUnCalculateur(): bool
    {
        return $this->calculateur ??= 0 < (int) $this->em->createQueryBuilder()
            ->select('COUNT(pc.id)')
            ->from(ProduitConfiguration::class, 'pc')
            ->innerJoin('pc.produit', 'p')
            ->leftJoin('p.categorieProduit', 'cat')
            ->where('pc.active = true')->andWhere('p.actif = true')->andWhere('p.publie = true')->andWhere('p.visibleEnLigne = true')
            ->andWhere('cat.id IS NULL OR cat.publie = true')
            ->andWhere('pc.prixBase > 0')
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Pages qui n'auraient rien à montrer : hors du menu, du pied de page et
     * du plan du site. Leur adresse répond toujours (message et « Demander
     * un devis »), sans être proposée aux moteurs.
     *
     * @return list<string> noms de routes
     */
    public function routesVides(): array
    {
        return array_keys(array_filter([
            'app_site_produits' => !$this->aDesProduits(),
            'app_site_calculateur' => !$this->aUnCalculateur(),
        ]));
    }
}
