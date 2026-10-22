<?php

namespace App\Service\Achats;

use App\Entity\AchatDetail;
use App\Entity\Achats;
use App\Entity\Articles;
use App\Entity\CommandesDetails;
use App\Entity\Machines;
use App\Entity\ProduitArticleStock;
use App\Entity\Produits;
use App\Entity\User;
use App\Service\CalculateurCoutRevient;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Coûts, marges et bénéfice brut par produit sur un mois (maquette SU04,
 * docs/achats.md). Les calculs sont dans CalculsMarges ; ici, les lignes
 * vendues, les réceptions et les réglages.
 *
 * - Lignes vendues : commandes du mois (date de commande), ni supprimées,
 *   ni soldes d'ouverture, lignes non annulées, avec un produit du
 *   catalogue. Comme les statistiques (docs/hors-imprimerie.md), les
 *   ventes « hors imprimerie » sont comptées à part et n'entrent pas
 *   dans les marges de l'imprimerie.
 * - Matière : l'article principal du produit (fiche produit, sinon la
 *   première matière liée), à son coût rendu Bamako d'après les
 *   réceptions (CalculsMarges::unitairesReceptions) ; sans réception, au
 *   coût moyen du stock, sans frais.
 * - Machine : celle de la ligne, sinon celle du produit, sinon celle de
 *   l'article.
 *
 * Réglages (tuile « Achats et fournisseurs ») : gâche par défaut (8 %),
 * gâche mesurée de chaque article et coût machine et main-d'œuvre au m²
 * de chaque machine, saisis sur Coûts et marges › Régler les coûts.
 */
final class CoutsMarges
{
    public const TUILE = 'achats';
    public const CLE_GACHE_DEFAUT = 'gache_defaut_pourcent';
    public const CLE_GACHES = 'gache_articles';
    public const CLE_MACHINES = 'couts_machine_m2';

    /** @var array<int, array{achat: float, transport: float, transit: float, quantite: float, source: string}|null> */
    private array $unitaires = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
    ) {
    }

    /**
     * @return array{
     *     mois: \DateTimeImmutable,
     *     produits: list<array<string, mixed>>,
     *     total: array<string, mixed>,
     *     hors: array{ca: int, lignes: int},
     *     libres: array{ca: int, lignes: int},
     *     incomplets: int
     * }
     */
    public function analyser(\DateTimeImmutable $mois, ?\DateTimeImmutable $jusquA = null): array
    {
        $debut = $mois->modify('first day of this month')->setTime(0, 0);
        $fin = $debut->modify('+1 month');
        // Ventes comptées jusqu'à cette date (comparaison d'un mois en cours au même nombre de jours du mois d'avant).
        $finVentes = null !== $jusquA && $jusquA < $fin ? $jusquA : $fin;
        // Le coût de la matière dépend de la fin du mois : pas de reprise d'un autre mois.
        $this->unitaires = [];
        $gacheDefaut = $this->gacheDefaut();
        $gaches = $this->gachesArticles();
        $machinesM2 = $this->coutsMachines();

        $produits = [];
        $hors = ['ca' => 0, 'lignes' => 0];
        $libres = ['ca' => 0, 'lignes' => 0];

        foreach ($this->lignes($debut, $finVentes) as $ligne) {
            $commande = $ligne->getCommande();
            if ($commande?->isHorsImprimerie()) {
                $hors['ca'] += $ligne->getTotalHt();
                ++$hors['lignes'];

                continue;
            }
            $produit = $ligne->getProduit();
            if (!$produit instanceof Produits) {
                $libres['ca'] += $ligne->getTotalHt();
                ++$libres['lignes'];

                continue;
            }

            $id = (int) $produit->getId();
            if (!isset($produits[$id])) {
                $article = self::articlePrincipal($produit);
                $produits[$id] = [
                    'produit' => $produit,
                    'nom' => self::nomProduit($produit),
                    'article' => $article,
                    'fournisseur' => $article?->getFournisseur(),
                    'unite' => $produit->utiliseSurface() ? 'm²' : ($produit->utiliseLongueur() ? 'm' : 'pièce'),
                    'quantite' => 0.0,
                    'ca' => 0,
                    'lignes' => 0,
                    'couts' => array_fill_keys(array_keys(CalculsMarges::POSTES), 0.0),
                    'gache' => null !== $article ? CalculsMarges::gache($gaches[(int) $article->getId()] ?? null, $gacheDefaut) : null,
                    'source' => null,
                    'manquants' => [],
                    'machines' => [],
                ];
            }
            $p = &$produits[$id];

            $quantite = max(1, $ligne->getQuantite());
            $surface = self::surfaceVendue($ligne);
            $p['quantite'] += CalculsMarges::quantiteVendue($p['unite'], $surface, (float) ($ligne->getLongueur() ?? 0), $quantite);
            $p['ca'] += $ligne->getTotalHt();
            ++$p['lignes'];

            // Matière principale.
            $article = $p['article'];
            $liaison = null !== $article ? self::liaison($produit, $article) : null;
            $besoin = 0.0;
            $unitaires = null;
            if (null === $article) {
                $p['manquants']['matiere'] = 'matière non liée au produit';
            } else {
                $besoin = self::besoin($liaison, $article, $quantite, $surface, (float) ($ligne->getLongueur() ?? 0));
                $unitaires = $this->unitairesArticle($article, $fin);
                if (null === $unitaires) {
                    $p['manquants']['cout'.$article->getId()] = sprintf('coût de %s (ni réception ni coût moyen)', SuiviAchats::nomCourt($article));
                } else {
                    $p['source'] ??= $unitaires['source'];
                }
            }

            // Surface imprimée : la surface vendue, sinon (pièces sans dimensions) les m² de matière.
            $surfaceImprimee = $surface > 0 ? $surface : ('m2' === $article?->getUnite() ? $besoin : 0.0);
            $surfaceImprimee *= max(1, $ligne->getNombreFaces());
            $machine = $ligne->getMachine() ?? $produit->getMachine() ?? $article?->getMachine();
            $encreM2 = null;
            $machineM2 = null;
            if ($surfaceImprimee > 0) {
                if (!$machine instanceof Machines) {
                    $p['manquants']['machine'] = 'machine de la ligne';
                } else {
                    $p['machines'][(int) $machine->getId()] = $machine->getNom();
                    $encreM2 = $machine->getCoutEncreM2();
                    $machineM2 = CalculsMarges::machineM2($machinesM2[(int) $machine->getId()] ?? null, CalculateurCoutRevient::coutHoraire($machine), $machine->getVitesseM2Heure());
                    if (null === $encreM2) {
                        $p['manquants']['encre'.$machine->getId()] = sprintf('encre au m² de %s', $machine->getNom());
                    }
                    if (null === $machineM2) {
                        $p['manquants']['machine'.$machine->getId()] = sprintf('coût machine au m² de %s', $machine->getNom());
                    }
                }
            }

            $p['couts'] = CalculsMarges::additionner($p['couts'], CalculsMarges::coutsLigne(
                $besoin,
                $unitaires,
                (float) ($p['gache']['pourcent'] ?? 0),
                $surfaceImprimee,
                $encreM2,
                $machineM2,
                $this->autres($ligne, $produit, $article, $quantite, $surface, $fin),
            ));
            unset($p);
        }

        $lignes = [];
        $total = ['ca' => 0, 'cout' => 0, 'benefice' => 0, 'couts' => array_fill_keys(array_keys(CalculsMarges::POSTES), 0.0)];
        $incomplets = 0;
        foreach ($produits as $p) {
            $p['bilan'] = CalculsMarges::bilan($p['ca'], $p['quantite'], $p['couts']);
            $p['manquants'] = array_values($p['manquants']);
            $p['origine'] = self::origine($p['fournisseur']);
            $p['frais'] = $p['bilan']['unitaires']['transport'] + $p['bilan']['unitaires']['transit'];
            $p['atelier'] = $p['bilan']['unitaires']['encre'] + $p['bilan']['unitaires']['gache'] + $p['bilan']['unitaires']['machine'] + $p['bilan']['unitaires']['autres'];
            $total['ca'] += $p['bilan']['ca'];
            $total['cout'] += $p['bilan']['cout'];
            $total['benefice'] += $p['bilan']['benefice'];
            $total['couts'] = CalculsMarges::additionner($total['couts'], $p['couts']);
            if ([] !== $p['manquants']) {
                ++$incomplets;
            }
            $lignes[] = $p;
        }
        // Le plus gros bénéfice d'abord.
        usort($lignes, static fn (array $a, array $b): int => [$b['bilan']['benefice'], $a['nom']] <=> [$a['bilan']['benefice'], $b['nom']]);
        $total['taux'] = $total['ca'] > 0 ? round($total['benefice'] / $total['ca'] * 100, 1) : null;

        return [
            'mois' => $debut,
            'produits' => $lignes,
            'total' => $total,
            'hors' => $hors,
            'libres' => $libres,
            'incomplets' => $incomplets,
        ];
    }

    /**
     * Bénéfice brut du mois d'avant, pour comparer : en entier pour un mois
     * fini ; pour le mois en cours, du 1er au même jour du mois d'avant.
     *
     * @return array{mois: \DateTimeImmutable, benefice: int, jusquAu: ?int}
     */
    public function moisPrecedent(\DateTimeImmutable $mois, \DateTimeImmutable $aujourdhui): array
    {
        $debut = $mois->modify('first day of this month')->setTime(0, 0);
        $avant = $debut->modify('-1 month');
        $jusquAu = null;
        $limite = null;
        if ($aujourdhui >= $debut && $aujourdhui < $debut->modify('+1 month')) {
            $jusquAu = min((int) $aujourdhui->format('j'), (int) $avant->format('t'));
            $limite = $avant->modify(sprintf('+%d days', $jusquAu));
        }

        return ['mois' => $avant, 'benefice' => $this->analyser($avant, $limite)['total']['benefice'], 'jusquAu' => $jusquAu];
    }

    /* ---------- Réglages ---------- */

    public function gacheDefaut(): float
    {
        return (float) ($this->parametres->valeur(self::TUILE, self::CLE_GACHE_DEFAUT) ?? CalculsMarges::GACHE_DEFAUT);
    }

    /** @return array<int, float> gâche mesurée par article */
    public function gachesArticles(): array
    {
        return self::liste($this->parametres->valeur(self::TUILE, self::CLE_GACHES), 100.0);
    }

    /** @return array<int, int> coût machine et main-d'œuvre au m² par machine */
    public function coutsMachines(): array
    {
        return array_map('intval', self::liste($this->parametres->valeur(self::TUILE, self::CLE_MACHINES), 10000000.0));
    }

    /**
     * Ce que l'écran « Régler les coûts » montre : les machines avec leur
     * coût saisi et celui déduit des Coûts de revient ; les articles
     * principaux des produits avec leur gâche.
     *
     * @return array{machines: list<array<string, mixed>>, articles: list<array<string, mixed>>, gacheDefaut: float}
     */
    public function reglages(): array
    {
        $saisis = $this->coutsMachines();
        $machines = [];
        foreach ($this->em->getRepository(Machines::class)->findBy([], ['nom' => 'ASC']) as $m) {
            $machines[] = [
                'machine' => $m,
                'saisi' => $saisis[(int) $m->getId()] ?? null,
                'deduit' => CalculsMarges::machineM2(null, CalculateurCoutRevient::coutHoraire($m), $m->getVitesseM2Heure()),
                'encre' => $m->getCoutEncreM2(),
            ];
        }

        $gaches = $this->gachesArticles();
        $articles = [];
        foreach ($this->articlesPrincipaux() as $a) {
            $articles[] = ['article' => $a, 'saisie' => $gaches[(int) $a->getId()] ?? null];
        }

        return ['machines' => $machines, 'articles' => $articles, 'gacheDefaut' => $this->gacheDefaut()];
    }

    /**
     * Enregistre la saisie de « Régler les coûts » dans la tuile (depuis le
     * site en ligne : proposée à l'ERP). Une case vide reprend le défaut.
     *
     * @param array<array-key, mixed> $machines   id => F/m²
     * @param array<array-key, mixed> $gaches     id => %
     */
    public function enregistrer(mixed $gacheDefaut, array $machines, array $gaches, ?User $par): string
    {
        $idsMachines = array_map(static fn (Machines $m): int => (int) $m->getId(), $this->em->getRepository(Machines::class)->findAll());
        $idsArticles = array_map(static fn (Articles $a): int => (int) $a->getId(), $this->articlesPrincipaux());

        $valeurs = [
            self::CLE_MACHINES => array_map('intval', self::lireSaisie($machines, $idsMachines, 10000000.0, 0)),
            self::CLE_GACHES => self::lireSaisie($gaches, $idsArticles, 100.0, 1),
        ];
        $defaut = self::nombre($gacheDefaut);
        if (null !== $defaut && $defaut <= 100) {
            $valeurs[self::CLE_GACHE_DEFAUT] = (int) round($defaut);
        }

        return $this->parametres->enregistrer(self::TUILE, $valeurs, $par);
    }

    /**
     * Saisie d'un tableau id => nombre : les id connus, les nombres entre 0
     * et le plafond, arrondis ; triée par id (comparaison au réglage).
     *
     * @param array<array-key, mixed> $saisie
     * @param list<int>               $ids
     *
     * @return array<int, float>
     */
    public static function lireSaisie(array $saisie, array $ids, float $plafond, int $decimales): array
    {
        $valeurs = [];
        foreach ($saisie as $id => $valeur) {
            $nombre = self::nombre($valeur);
            if (null === $nombre || $nombre > $plafond || !\in_array((int) $id, $ids, true)) {
                continue;
            }
            $valeurs[(int) $id] = round($nombre, $decimales);
        }
        ksort($valeurs);

        return $valeurs;
    }

    /* ---------- Interne ---------- */

    /** @return list<CommandesDetails> */
    private function lignes(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->em->createQueryBuilder()
            ->select('l', 'c', 'p', 'm', 'pm', 'a', 'pa', 'f', 'fi')
            ->from(CommandesDetails::class, 'l')
            ->innerJoin('l.commande', 'c')
            ->leftJoin('l.produit', 'p')
            ->leftJoin('l.machine', 'm')
            ->leftJoin('p.machine', 'pm')
            ->leftJoin('p.articleStock', 'a')
            ->leftJoin('p.articlesStock', 'pa')
            ->leftJoin('l.finitions', 'f')
            ->leftJoin('f.finition', 'fi')
            ->andWhere('c.deleted = false AND c.soldeOuverture = false')
            ->andWhere('l.statutProduction != :annulee')
            ->andWhere('c.dateCommande >= :debut AND c.dateCommande < :fin')
            ->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();
    }

    /** L'article principal : celui de la fiche produit, sinon la première matière liée active. */
    public static function articlePrincipal(Produits $produit): ?Articles
    {
        if (null !== $produit->getArticleStock()) {
            return $produit->getArticleStock();
        }
        $liaisons = self::liaisonsActives($produit);

        return [] !== $liaisons ? $liaisons[0]->getArticle() : null;
    }

    /** @return list<ProduitArticleStock> triées par ordre */
    private static function liaisonsActives(Produits $produit): array
    {
        $liaisons = array_values(array_filter($produit->getArticlesStock()->toArray(), static fn (ProduitArticleStock $l): bool => $l->isActif() && $l->getArticle() instanceof Articles));
        usort($liaisons, static fn (ProduitArticleStock $a, ProduitArticleStock $b): int => [$a->getOrdre(), $a->getId()] <=> [$b->getOrdre(), $b->getId()]);

        return $liaisons;
    }

    private static function liaison(Produits $produit, Articles $article): ?ProduitArticleStock
    {
        foreach (self::liaisonsActives($produit) as $l) {
            if ($l->getArticle() === $article) {
                return $l;
            }
        }

        return null;
    }

    /**
     * Quantité de l'article consommée par la ligne, sans la gâche (comptée
     * à part) : selon la liaison au produit, sinon la surface vendue pour
     * un article au m², sinon une unité par pièce.
     */
    private static function besoin(?ProduitArticleStock $liaison, Articles $article, int $quantite, float $surface, float $longueur): float
    {
        if (null !== $liaison) {
            return CalculateurCoutRevient::besoinArticle($liaison->getModeCalcul(), (float) $liaison->getCoefficient(), $quantite, $surface, $longueur * $quantite);
        }

        return match ($article->getUnite()) {
            'm2' => $surface,
            'metre', 'm' => $longueur * $quantite,
            default => (float) $quantite,
        };
    }

    /** Surface vendue d'une ligne, en m² (dimensions en mètres). */
    private static function surfaceVendue(CommandesDetails $ligne): float
    {
        $surface = $ligne->getSurfaceTotale();
        if ($surface > 0) {
            return $surface;
        }

        return max(0.0, (float) ($ligne->getLargeur() ?? 0) * (float) ($ligne->getLongueur() ?? 0) * max(1, $ligne->getQuantite()));
    }

    /**
     * Autres matières liées au produit (au coût rendu, sans gâche) et
     * finitions de la ligne (à leur coût unitaire).
     */
    private function autres(CommandesDetails $ligne, Produits $produit, ?Articles $principal, int $quantite, float $surface, \DateTimeImmutable $fin): float
    {
        $autres = 0.0;
        foreach (self::liaisonsActives($produit) as $l) {
            $article = $l->getArticle();
            if ($article === $principal || null === $article) {
                continue;
            }
            $unitaires = $this->unitairesArticle($article, $fin);
            if (null === $unitaires) {
                continue;
            }
            $besoin = CalculateurCoutRevient::besoinArticle($l->getModeCalcul(), (float) $l->getCoefficient(), $quantite, $surface, (float) ($ligne->getLongueur() ?? 0) * $quantite);
            $autres += $besoin * ($unitaires['achat'] + $unitaires['transport'] + $unitaires['transit']);
        }
        foreach ($ligne->getFinitions() as $f) {
            $autres += (int) $f->getFinition()?->getCoutUnitaire() * max(1, $f->getQuantite());
        }

        return $autres;
    }

    /**
     * Coût rendu Bamako d'une unité de stock de l'article à la fin du mois.
     *
     * @return array{achat: float, transport: float, transit: float, quantite: float, source: string}|null
     */
    private function unitairesArticle(Articles $article, \DateTimeImmutable $fin): ?array
    {
        $id = (int) $article->getId();
        if (\array_key_exists($id, $this->unitaires)) {
            return $this->unitaires[$id];
        }

        /** @var list<AchatDetail> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('d', 'a')
            ->from(AchatDetail::class, 'd')
            ->innerJoin('d.achat', 'a')
            ->andWhere('d.article = :article')
            ->andWhere('a.statut = :recu AND a.dateReception IS NOT NULL AND a.dateReception < :fin')
            ->setParameter('article', $article)
            ->setParameter('recu', Achats::STATUT_RECU)
            ->setParameter('fin', $fin)
            ->orderBy('a.dateReception', 'DESC')
            ->setMaxResults(60)
            ->getQuery()
            ->getResult();

        $donnees = array_map(static fn (AchatDetail $d): array => [
            'recueLe' => $d->getAchat()?->getDateReception() ?? $fin,
            'quantiteAchat' => $d->getQuantite(),
            'prixUnitaire' => $d->getPrixUnitaire(),
            'quantiteStock' => $d->getQuantiteStock(),
            'coutRevient' => $d->getCoutRevientUnitaire(),
            'bonStock' => $d->getQuantiteEntree() ?? (null !== $d->getQuantiteStock() && $d->getQuantite() > 0 ? $d->getQuantiteStock() * $d->getQuantiteBonEtat() / $d->getQuantite() : null),
            'fraisTransport' => (int) $d->getAchat()?->getFraisTransport(),
            'fraisTransit' => (int) $d->getAchat()?->getFraisTransit(),
        ], $lignes);

        $unitaires = CalculsMarges::unitairesReceptions(CalculsMarges::receptionsRetenues($donnees, $fin));
        if (null !== $unitaires) {
            return $this->unitaires[$id] = $unitaires + ['source' => 'receptions'];
        }
        if (null !== $article->getCoutMoyen() && $article->getCoutMoyen() > 0) {
            return $this->unitaires[$id] = ['achat' => (float) $article->getCoutMoyen(), 'transport' => 0.0, 'transit' => 0.0, 'quantite' => 0.0, 'source' => 'stock'];
        }

        return $this->unitaires[$id] = null;
    }

    /** @return list<Articles> les articles principaux des produits actifs */
    private function articlesPrincipaux(): array
    {
        $articles = [];
        foreach ($this->em->getRepository(Produits::class)->findBy(['actif' => true]) as $p) {
            $a = self::articlePrincipal($p);
            if (null !== $a) {
                $articles[(int) $a->getId()] = $a;
            }
        }
        uasort($articles, static fn (Articles $a, Articles $b): int => strcasecmp((string) $a->getDesignation(), (string) $b->getDesignation()));

        return array_values($articles);
    }

    private static function nomProduit(Produits $produit): string
    {
        return trim((string) preg_replace('/\s*\(démo\)\s*$/u', '', (string) $produit->getNom()));
    }

    /** « Dakar » pour un fournisseur hors de Bamako, sinon null (pas de « depuis Bamako »). */
    private static function origine(mixed $fournisseur): ?string
    {
        $ville = $fournisseur?->getVille();

        return null !== $ville && '' !== trim($ville) && 0 !== strcasecmp(trim($ville), 'Bamako') ? trim($ville) : null;
    }

    /**
     * @return array<int, float>
     */
    private static function liste(mixed $valeur, float $plafond): array
    {
        $liste = [];
        foreach (\is_array($valeur) ? $valeur : [] as $id => $nombre) {
            if (is_numeric($nombre) && (float) $nombre >= 0 && (float) $nombre <= $plafond && (int) $id > 0) {
                $liste[(int) $id] = (float) $nombre;
            }
        }

        return $liste;
    }

    private static function nombre(mixed $valeur): ?float
    {
        $texte = str_replace([' ', ' ', ' ', ','], ['', '', '', '.'], trim((string) (\is_scalar($valeur) ? $valeur : '')));
        if ('' === $texte || !is_numeric($texte) || (float) $texte < 0) {
            return null;
        }

        return (float) $texte;
    }
}
