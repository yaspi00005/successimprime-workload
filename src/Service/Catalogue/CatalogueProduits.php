<?php

namespace App\Service\Catalogue;

use App\Entity\CategorieProduit;
use App\Entity\CommandesDetails;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Catalogue « Produits et services » de l'application (maquette PR01,
 * docs/produits.md) : prix de base, coût de revient, marge, délai,
 * machine et visibilité en ligne de chaque produit, filtres par famille,
 * indicateurs et export de la grille.
 *
 * Le coût et la marge ne sont calculés que si l'appelant a le droit
 * « Marges et coûts de revient » ($avecCouts) : sans lui, ils ne sont
 * ni dans la page ni dans l'export.
 */
final class CatalogueProduits
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Familles (catégories) avec leur nombre de produits, pour les filtres.
     *
     * @return list<array{id: int, nom: string, nombre: int}>
     */
    public function familles(): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('cat.id', 'cat.nom', 'COUNT(p.id) AS nombre')
            ->from(CategorieProduit::class, 'cat')
            ->leftJoin('cat.produits', 'p')
            ->groupBy('cat.id')->addGroupBy('cat.nom')->addGroupBy('cat.ordre')
            ->orderBy('cat.ordre', 'ASC')->addOrderBy('cat.nom', 'ASC')
            ->getQuery()->getArrayResult();

        return array_values(array_map(static fn (array $l): array => ['id' => (int) $l['id'], 'nom' => (string) $l['nom'], 'nombre' => (int) $l['nombre']], array_filter($lignes, static fn (array $l): bool => (int) $l['nombre'] > 0)));
    }

    /**
     * @return list<Produits>
     */
    public function produits(?int $famille = null, string $recherche = ''): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('p', 'cat', 'm', 'pc', 'pas', 'a')
            ->from(Produits::class, 'p')
            ->leftJoin('p.categorieProduit', 'cat')
            ->leftJoin('p.machine', 'm')
            ->leftJoin('p.configurations', 'pc')
            ->leftJoin('p.articlesStock', 'pas')
            ->leftJoin('pas.article', 'a')
            ->orderBy('cat.ordre', 'ASC')->addOrderBy('cat.nom', 'ASC')->addOrderBy('p.ordre', 'ASC')->addOrderBy('p.nom', 'ASC');
        if (null !== $famille) {
            $qb->andWhere('cat.id = :famille')->setParameter('famille', $famille);
        }
        $produits = $qb->getQuery()->getResult();
        $recherche = trim($recherche);
        if ('' !== $recherche) {
            $produits = array_filter($produits, static fn (Produits $p): bool => NomCommercial::correspond($recherche, $p->getNom(), $p->getNomCommercial(), $p->getCode()));
        }

        return array_values($produits);
    }

    /**
     * Une ligne du catalogue par produit.
     *
     * @param list<Produits>   $produits
     * @param array<int, array{ca: int, quantite: float, clients: int}> $ventes par produit
     *
     * @return list<array<string, mixed>>
     */
    public static function lignes(array $produits, array $ventes, bool $avecCouts, int $cible, int $minimum): array
    {
        $lignes = [];
        foreach ($produits as $p) {
            $base = CoutProduit::configurationDeBase($p);
            $mode = $base?->getModeCalcul() ?? $p->getModeCalcul();
            $prix = CoutProduit::prixBase($p, $base);
            $ligne = [
                'produit' => $p,
                'configuration' => $base,
                'prix' => $prix,
                'unite' => CoutProduit::unite($mode),
                'mode' => $mode,
                'delai' => $p->getDelaiHeures(),
                'machine' => $p->getMachine()?->getNom(),
                'enLigne' => $p->estEnLigne(),
                'ventes' => $ventes[(int) $p->getId()] ?? ['ca' => 0, 'quantite' => 0.0, 'clients' => 0],
                'configurations' => \count(array_filter($p->getConfigurations()->toArray(), static fn (ProduitConfiguration $c): bool => $c->isActive())),
            ];
            if ($avecCouts) {
                $cout = CoutProduit::detail($p, $mode);
                $marge = CoutProduit::marge($prix, $cout['total']);
                // Coût incomplet (prix d'achat manquant…) : la marge n'est qu'un plafond, signalée comme telle.
                $ligne += ['cout' => $cout['total'], 'coutSource' => $cout['source'], 'marge' => $marge, 'niveau' => CoutProduit::niveau($marge, $cible, $minimum),
                    'coutComplet' => $cout['complet'], 'manquants' => $cout['manquants'], 'prixAchatManquants' => $cout['prixAchatManquants']];
            }
            $lignes[] = $ligne;
        }

        return $lignes;
    }

    /**
     * Indicateurs du haut de l'écran.
     *
     * @param list<array<string, mixed>> $lignes toutes les lignes (sans filtre)
     *
     * @return array{actifs: int, enLigne: int, marge: ?int, ponderee: bool, aRevoir: int, meilleur: ?array<string, mixed>}
     */
    public static function indicateurs(array $lignes, bool $avecCouts, int $cible): array
    {
        $actives = array_values(array_filter($lignes, static fn (array $l): bool => $l['produit']->isActif()));
        $meilleur = null;
        foreach ($actives as $l) {
            if ($l['ventes']['ca'] > 0 && (null === $meilleur || $l['ventes']['ca'] > $meilleur['ventes']['ca'])) {
                $meilleur = $l;
            }
        }
        $moyenne = $avecCouts
            // Une marge sur un coût incomplet n'entre pas dans la moyenne : elle serait trop haute.
            ? CoutProduit::margeMoyenne(array_map(static fn (array $l): array => ['marge' => ($l['coutComplet'] ?? true) ? $l['marge'] : null, 'ventes' => $l['ventes']['ca']], $actives))
            : ['marge' => null, 'ponderee' => false];

        return [
            'actifs' => \count($actives),
            'enLigne' => \count(array_filter($actives, static fn (array $l): bool => $l['enLigne'])),
            'marge' => $moyenne['marge'],
            'ponderee' => $moyenne['ponderee'],
            'aRevoir' => $avecCouts ? \count(array_filter($actives, static fn (array $l): bool => null !== $l['marge'] && $l['marge'] < $cible)) : 0,
            'meilleur' => $meilleur,
        ];
    }

    /**
     * Ventes du mois par produit : chiffre d'affaires HT des lignes de
     * commande (hors commandes supprimées, soldes d'ouverture et lignes
     * annulées), quantité vendue dans l'unité du produit (m² pour un
     * produit au m²) et nombre de clients.
     *
     * @return array<int, array{ca: int, quantite: float, clients: int}>
     */
    public function ventes(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?Produits $seul = null): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('IDENTITY(d.produit) AS produit', 'd.totalHt', 'd.quantite', 'd.surface', 'd.modeCalcul', 'IDENTITY(c.clients) AS client')
            ->from(CommandesDetails::class, 'd')
            ->innerJoin('d.commande', 'c')
            ->where('d.produit IS NOT NULL')
            ->andWhere('c.deleted = false AND c.soldeOuverture = false')
            ->andWhere('c.dateCommande >= :debut AND c.dateCommande < :fin')
            ->andWhere('d.statutProduction IS NULL OR d.statutProduction != :annulee')
            ->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE);
        if (null !== $seul) {
            $qb->andWhere('d.produit = :seul')->setParameter('seul', $seul);
        }

        $ventes = [];
        $clients = [];
        foreach ($qb->getQuery()->getArrayResult() as $l) {
            $id = (int) $l['produit'];
            $ventes[$id] ??= ['ca' => 0, 'quantite' => 0.0, 'clients' => 0];
            $ventes[$id]['ca'] += (int) $l['totalHt'];
            $ventes[$id]['quantite'] += 'metre_carre' === $l['modeCalcul'] && (float) $l['surface'] > 0
                ? (float) $l['surface'] * max(1, (int) $l['quantite'])
                : max(1, (int) $l['quantite']);
            if (null !== $l['client']) {
                $clients[$id][(int) $l['client']] = true;
            }
        }
        foreach ($ventes as $id => $v) {
            $ventes[$id]['clients'] = \count($clients[$id] ?? []);
        }

        return $ventes;
    }

    /**
     * Grille exportée (CSV pour Excel, séparateur « ; »). Le coût et la
     * marge n'y sont que pour qui a le droit de les voir.
     *
     * @param list<array<string, mixed>> $lignes
     */
    public static function csv(array $lignes, bool $avecCouts): string
    {
        $entete = ['Code', 'Produit', 'Nom commercial', 'Catégorie', 'Unité', 'Prix de base (F)', 'Prix revendeur (F)'];
        if ($avecCouts) {
            array_push($entete, 'Coût de revient (F)', 'Marge (%)', 'Coût complet');
        }
        array_push($entete, 'Délai (h)', 'Machine', 'En vente', 'En ligne');

        $sortie = fopen('php://temp', 'r+');
        fwrite($sortie, "\u{FEFF}");
        fputcsv($sortie, $entete, ';', '"', '');
        foreach ($lignes as $l) {
            $p = $l['produit'];
            $ligne = [
                $p->getCode(),
                $p->getNom(),
                (string) $p->getNomCommercial(),
                (string) $p->getCategorieProduit()?->getNom(),
                $l['unite'],
                $l['prix'] ?? '',
                $l['configuration']?->getPrixB2B() ?? '',
            ];
            if ($avecCouts) {
                // « Non » : un prix d'achat manque dans le stock, la marge est trop haute.
                array_push($ligne, $l['cout'] ?? '', $l['marge'] ?? '', null === ($l['cout'] ?? null) ? '' : (($l['coutComplet'] ?? true) ? 'Oui' : 'Non'));
            }
            array_push($ligne, $l['delai'] ?? '', (string) $l['machine'], $p->isActif() ? 'oui' : 'non', $l['enLigne'] ? 'oui' : 'non');
            fputcsv($sortie, array_map(static fn (mixed $v): string => self::cellule((string) $v), $ligne), ';', '"', '');
        }
        rewind($sortie);
        $csv = (string) stream_get_contents($sortie);
        fclose($sortie);

        return $csv;
    }

    /** Une cellule qui commence par = + - @ ne devient pas une formule dans Excel. */
    private static function cellule(string $valeur): string
    {
        return '' !== $valeur && \in_array($valeur[0], ['=', '+', '-', '@'], true) && !is_numeric($valeur) ? "'".$valeur : $valeur;
    }
}
