<?php

namespace App\Service\Site;

use App\Controller\SiteController;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Service\Catalogue\NomCommercial;
use App\Service\EspaceClient\NouvelleCommande;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Catalogue du site vitrine (maquettes 02 et 03) : catégories et produits
 * publiés, prix de départ public. Seul le prix normal (B2C) sort d'ici,
 * jamais le prix B2B, le coût ni la marge.
 */
final class CatalogueSite
{
    /** Unité du prix selon le mode de calcul de la configuration. */
    public const UNITES = [
        'metre_carre' => 'le m²',
        'metre' => 'le mètre',
        'unite' => 'l\'unité',
        'exemplaire' => 'l\'exemplaire',
        'feuille' => 'la feuille',
        'heure' => 'l\'heure',
        'face' => 'la face',
        'point' => 'le point',
        'forfait' => 'le forfait',
    ];

    /** Illustration d'un produit : mots de son nom => dessin (templates/site/_illustrations_produits.svg.twig). */
    public const ILLUSTRATIONS = [
        'microperfore' => ['micro-perf', 'microperf', 'micro perf'],
        'xbanner' => ['x-banner', 'xbanner', 'x banner'],
        'rollup' => ['roll-up', 'rollup', 'roll up', 'kakémono', 'kakemono'],
        'doorshape' => ['door shape', 'doorshape', 'door-shape', 'beach flag', 'drapeau'],
        'vinyle' => ['vinyle', 'adhésif', 'adhesif', 'autocollant', 'sticker', 'étiquette'],
        'panneau' => ['panneau', 'akylux', 'dibond', 'plaque', 'enseigne', 'forex'],
        'casquette' => ['casquette', 'bob '],
        'tshirt' => ['t-shirt', 'tee-shirt', 'tshirt', 'polo', 'sweat', 'textile', 'dtf', 'tote'],
        'cartes' => ['carte'],
        'brochure' => ['brochure', 'dépliant', 'depliant', 'catalogue', 'livret', 'magazine'],
        'affiche' => ['affiche', 'poster'],
        'papier' => ['flyer', 'prospectus', 'papier', 'a4', 'a5', 'a3', 'calendrier'],
        'bache' => ['bâche', 'bache', 'banderole', 'toile'],
    ];

    /** Illustration par défaut d'une famille d'univers (SiteController::FAMILLES). */
    public const ILLUSTRATION_FAMILLE = ['grand' => 'bache', 'textile' => 'tshirt', 'petit' => 'papier', 'signaletique' => 'rollup'];

    /** @var list<ProduitConfiguration>|null */
    private ?array $configurations = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NouvelleCommande $commande,
    ) {
    }

    /**
     * Configurations que le visiteur peut chiffrer : les mêmes que la
     * commande en ligne (actives, avec un prix, produit et catégorie publiés).
     *
     * @return list<ProduitConfiguration>
     */
    public function configurations(): array
    {
        return $this->configurations ??= $this->commande->produits();
    }

    /**
     * Univers de la page Produits : catégories publiées avec leurs produits
     * publiés et actifs, chacun avec son prix de départ.
     *
     * @return list<array{nom: string, cle: string, ids: list<int>, famille: string, produits: list<array<string, mixed>>}>
     */
    public function univers(): array
    {
        $produits = $this->em->createQueryBuilder()
            ->select('p', 'cat')
            ->from(Produits::class, 'p')
            ->innerJoin('p.categorieProduit', 'cat')
            ->where('p.publie = true')->andWhere('p.actif = true')->andWhere('cat.publie = true')
            ->orderBy('cat.ordre', 'ASC')->addOrderBy('cat.nom', 'ASC')->addOrderBy('p.ordre', 'ASC')->addOrderBy('p.nom', 'ASC')
            ->getQuery()->getResult();

        return self::regrouper($produits, $this->configurations());
    }

    /**
     * Regroupe les produits par catégorie affichée. Les catégories et
     * produits de démonstration portent « (démo) » : ils rejoignent ceux
     * du même nom, comme sur l'accueil.
     *
     * @param list<Produits>             $produits
     * @param list<ProduitConfiguration> $configurations
     *
     * @return list<array{nom: string, cle: string, ids: list<int>, famille: string, produits: list<array<string, mixed>>}>
     */
    public static function regrouper(array $produits, array $configurations): array
    {
        $parProduit = [];
        foreach ($configurations as $c) {
            $parProduit[$c->getProduit()?->getId()][] = $c;
        }

        $univers = [];
        $vus = [];
        foreach ($produits as $p) {
            $categorie = $p->getCategorieProduit();
            $nomCategorie = self::nomAffiche((string) $categorie?->getNom());
            $nom = self::nomAffiche((string) $p->getNom());
            if (null === $categorie || isset($vus[mb_strtolower($nom)])) {
                continue;
            }
            $vus[mb_strtolower($nom)] = true;
            if (!isset($univers[$nomCategorie])) {
                $univers[$nomCategorie] = ['nom' => $nomCategorie, 'cle' => self::cle($nomCategorie), 'ids' => [], 'famille' => SiteController::famille($nomCategorie, \count($univers)), 'produits' => []];
            }
            if (!\in_array($categorie->getId(), $univers[$nomCategorie]['ids'], true)) {
                $univers[$nomCategorie]['ids'][] = $categorie->getId();
            }
            $configs = $parProduit[$p->getId()] ?? [];
            $depart = self::prixDepart($configs);
            // Le nom commercial d'abord, la désignation à côté (docs/noms-commerciaux.md).
            $noms = NomCommercial::client($p->getNomCommercial(), $nom);
            $univers[$nomCategorie]['produits'][] = [
                'id' => $p->getId(),
                'nom' => $noms['nom'],
                'technique' => $noms['technique'],
                'description' => self::description($p, $depart['configuration'] ?? $configs[0] ?? null),
                'illustration' => self::illustration($nom.' '.$noms['nom'], $univers[$nomCategorie]['famille']),
                'categorie' => $nomCategorie,
                'configuration' => $depart ? $depart['configuration']->getId() : null,
                'prix' => null === $depart ? null : ['montant' => $depart['montant'], 'unite' => $depart['unite'], 'minimum' => $depart['minimum']],
            ];
        }

        return array_values($univers);
    }

    /**
     * Groupes de la liste du calculateur : configurations par catégorie affichée.
     *
     * @param list<ProduitConfiguration> $configurations
     *
     * @return list<array{nom: string, cle: string, ids: list<int>, options: list<array{id: int, nom: string}>}>
     */
    public static function groupes(array $configurations): array
    {
        $groupes = [];
        foreach ($configurations as $c) {
            $categorie = $c->getProduit()?->getCategorieProduit();
            $nom = null === $categorie ? 'Autres produits' : self::nomAffiche((string) $categorie->getNom());
            $groupes[$nom] ??= ['nom' => $nom, 'cle' => self::cle($nom), 'ids' => [], 'options' => []];
            if (null !== $categorie && !\in_array($categorie->getId(), $groupes[$nom]['ids'], true)) {
                $groupes[$nom]['ids'][] = $categorie->getId();
            }
            $groupes[$nom]['options'][] = ['id' => (int) $c->getId(), 'nom' => self::libelle($c)];
        }

        return array_values($groupes);
    }

    /**
     * Clé d'adresse d'un univers (?categorie=grand-format) : son nom
     * affiché en minuscules sans accents. Plusieurs catégories du même nom
     * (démo) n'en font qu'une, comme sur la page.
     */
    public static function cle(string $nom): string
    {
        return MarkdownSite::ancre(self::nomAffiche($nom));
    }

    /**
     * Rang de l'univers choisi par ?categorie= (sa clé), sinon null (tous).
     *
     * @param list<array{cle: string}> $univers
     */
    public static function universChoisi(array $univers, ?string $cle): ?int
    {
        foreach ($univers as $rang => $u) {
            if (null !== $cle && '' !== $cle && $cle === $u['cle']) {
                return $rang;
            }
        }

        return null;
    }

    /**
     * Clé de l'univers (ou du groupe du calculateur) qui contient cette
     * catégorie : ancienne adresse ?categorie=<identifiant>, redirigée.
     *
     * @param list<array{cle: string, ids: list<int>}> $univers
     */
    public static function cleDeCategorie(array $univers, int $categorie): ?string
    {
        foreach ($univers as $u) {
            if (\in_array($categorie, $u['ids'], true)) {
                return $u['cle'];
            }
        }

        return null;
    }

    /**
     * Prix de départ public : la configuration la moins chère, au prix
     * normal (jamais le prix B2B).
     *
     * @param list<ProduitConfiguration> $configurations
     *
     * @return array{configuration: ProduitConfiguration, montant: int, unite: string, minimum: int}|null
     */
    public static function prixDepart(array $configurations): ?array
    {
        $depart = null;
        foreach ($configurations as $c) {
            $prix = $c->getPrixApplicable(false);
            if ($prix > 0 && (null === $depart || $prix < $depart['montant'])) {
                $depart = ['configuration' => $c, 'montant' => $prix, 'unite' => self::unite($c->getModeCalcul()), 'minimum' => max(1, $c->getQuantiteMinimale())];
            }
        }

        return $depart;
    }

    public static function unite(string $modeCalcul): string
    {
        return self::UNITES[$modeCalcul] ?? self::UNITES['unite'];
    }

    /** Dessin d'un produit d'après son nom, sinon celui de sa famille. */
    public static function illustration(string $nom, string $famille): string
    {
        $nom = mb_strtolower($nom).' ';
        foreach (self::ILLUSTRATIONS as $dessin => $mots) {
            foreach ($mots as $mot) {
                if (str_contains($nom, $mot)) {
                    return $dessin;
                }
            }
        }

        return self::ILLUSTRATION_FAMILLE[$famille] ?? 'bache';
    }

    /** Nom montré au public : sans la marque « (démo) » des données de démonstration. */
    public static function nomAffiche(string $nom): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) preg_replace('/\s*\(démo\)/u', '', $nom)) ?? $nom);
    }

    /**
     * Libellé d'une configuration : produit · support · format, chaque
     * partie sous son nom commercial suivi de sa désignation
     * (« Autocollant (Vinyle adhésif) · Vinyle blanc · Sur mesure »).
     */
    public static function libelle(ProduitConfiguration $c): string
    {
        return self::nomAffiche(NomCommercial::texteConfiguration($c));
    }

    /**
     * Recherche de la page Nos produits (?q=) : les produits dont le nom
     * commercial, la désignation ou l'univers contient le texte, sans
     * tenir compte des accents ni des majuscules (« autocollant » trouve
     * « Vinyle adhésif »). Les univers sans produit trouvé disparaissent.
     *
     * @param list<array{nom: string, ids: list<int>, famille: string, produits: list<array<string, mixed>>}> $univers
     *
     * @return list<array{nom: string, cle: string, ids: list<int>, famille: string, produits: list<array<string, mixed>>}>
     */
    public static function rechercher(array $univers, ?string $recherche): array
    {
        if ('' === NomCommercial::cle($recherche)) {
            return $univers;
        }
        $trouves = [];
        foreach ($univers as $u) {
            $u['produits'] = array_values(array_filter($u['produits'], static fn (array $p): bool => NomCommercial::correspond($recherche, $p['nom'], $p['technique'] ?? null, $u['nom'])));
            if ([] !== $u['produits']) {
                $trouves[] = $u;
            }
        }

        return $trouves;
    }

    /** Phrase courte de la carte : la description du produit, sinon son support et son format. */
    private static function description(Produits $p, ?ProduitConfiguration $c): string
    {
        $texte = self::nomAffiche(trim((string) $p->getDescription()));
        if ('' === $texte && null !== $c) {
            $texte = implode(' · ', array_filter([
                null !== $c->getSupport() ? NomCommercial::texteClient($c->getSupport()->getNomCommercial(), $c->getSupport()->getNom()) : null,
                null !== $c->getFormat() ? NomCommercial::texteClient($c->getFormat()->getNomCommercial(), $c->getFormat()->getNom()) : null,
            ]));
        }

        return mb_strimwidth($texte, 0, 90, '…');
    }
}
