<?php

namespace App\Service;

use App\Entity\AchatDetail;
use App\Entity\Achats;
use App\Entity\Articles;
use App\Entity\StockEntrees;
use App\Entity\StockReservation;
use App\Entity\StockSorties;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Chiffres du catalogue des articles (maquette S01) et de la fiche
 * article (maquette S02), calculés en quelques requêtes groupées
 * plutôt qu'article par article.
 */
final class CatalogueStock
{
    public const ETAT_OK = 'ok';
    public const ETAT_BIENTOT = 'bientot';
    public const ETAT_COMMANDER = 'commander';

    public const JOURS_DORMANT = 90;

    /*
     * « Bientôt » : le disponible passe sous 1,5 fois le seuil minimum.
     */
    private const MARGE_BIENTOT = 1.5;

    private const MOIS_COURTS = ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param list<Articles> $articles
     *
     * @return list<array{article: Articles, physique: float, reserve: float, disponible: float, valeur: int, niveau: int, etat: string, dormant: bool}>
     */
    public function lignes(array $articles, \DateTimeImmutable $maintenant): array
    {
        if ($articles === []) {
            return [];
        }

        $entrees = $this->sommes(StockEntrees::class, 'quantites', $articles);
        $sorties = $this->sommes(StockSorties::class, 'quantite', $articles);
        $sorties30 = $this->sommes(StockSorties::class, 'quantite', $articles, null, $maintenant->modify('-30 days'));
        $reserves = $this->sommes(StockReservation::class, 'quantite', $articles, StockReservation::STATUT_ACTIVE);
        $dernieresSorties = $this->dernieresDates(StockSorties::class, $articles);
        $dernieresEntrees = $this->dernieresDates(StockEntrees::class, $articles);
        $limiteDormant = $maintenant->modify(sprintf('-%d days', self::JOURS_DORMANT));
        $lignes = [];

        foreach ($articles as $article) {
            $id = (int) $article->getId();
            $physique = max(0.0, ($entrees[$id] ?? 0.0) - ($sorties[$id] ?? 0.0));
            $reserve = $reserves[$id] ?? 0.0;
            $disponible = max(0.0, $physique - $reserve);
            $derniereActivite = max($dernieresSorties[$id] ?? null, $dernieresEntrees[$id] ?? null);

            $lignes[] = [
                'article' => $article,
                'physique' => $physique,
                'reserve' => $reserve,
                'conso30' => $sorties30[$id] ?? 0.0,
                'disponible' => $disponible,
                'valeur' => (int) round($physique * ($article->getCoutUnitaireStock() ?? 0)),
                'niveau' => self::niveau($physique, $article->getStockMin(), $article->getStockMax()),
                'etat' => self::etat($disponible, $article->getStockMin()),
                'dormant' => $physique > 0 && ($dernieresSorties[$id] ?? null) < $limiteDormant && $derniereActivite < $limiteDormant,
            ];
        }

        return $lignes;
    }

    /**
     * @param list<array{article: Articles, valeur: int, etat: string, dormant: bool}> $lignes
     *
     * @return array{valeur: int, actifs: int, familles: int, sousSeuil: int, dormants: int}
     */
    public static function indicateurs(array $lignes): array
    {
        $familles = [];

        foreach ($lignes as $ligne) {
            if ($ligne['article']->getFamille() !== null) {
                $familles[$ligne['article']->getFamille()->getId()] = true;
            }
        }

        return [
            'valeur' => array_sum(array_column($lignes, 'valeur')),
            'actifs' => \count(array_filter($lignes, static fn (array $l) => $l['article']->isActif())),
            'familles' => \count($familles),
            'sousSeuil' => \count(array_filter($lignes, static fn (array $l) => $l['etat'] === self::ETAT_COMMANDER)),
            'dormants' => \count(array_filter($lignes, static fn (array $l) => $l['dormant'])),
        ];
    }

    /**
     * Quantité à commander pour revenir au maximum (à défaut trois fois
     * le seuil), arrondie à l'unité d'achat entière.
     *
     * @return array{quantite: float, unitesAchat: ?int}
     */
    public static function aCommander(Articles $article, float $disponible): array
    {
        $cible = $article->getStockMax() ?? $article->getStockMin() * 3;
        $manque = max(0.0, $cible - $disponible);
        $facteur = $article->getFacteurConversion();
        if ($article->getUniteAchat() && $facteur > 0 && $facteur != 1.0) {
            $unites = (int) max(1, ceil($manque / $facteur));

            return ['quantite' => $unites * $facteur, 'unitesAchat' => $unites];
        }

        return ['quantite' => ceil(max($manque, $article->getStockMin())), 'unitesAchat' => null];
    }

    public static function etat(float $disponible, float $minimum): string
    {
        if ($disponible <= 0 || ($minimum > 0 && $disponible <= $minimum)) {
            return self::ETAT_COMMANDER;
        }

        if ($minimum > 0 && $disponible <= $minimum * self::MARGE_BIENTOT) {
            return self::ETAT_BIENTOT;
        }

        return self::ETAT_OK;
    }

    /**
     * Remplissage en % : par rapport au maximum, à défaut à trois fois le minimum.
     */
    public static function niveau(float $physique, float $minimum, ?float $maximum): int
    {
        $reference = $maximum ?? ($minimum > 0 ? $minimum * 3 : null);

        if ($reference === null || $reference <= 0) {
            return $physique > 0 ? 100 : 0;
        }

        return (int) max(0, min(100, round($physique / $reference * 100)));
    }

    /**
     * Sorties par mois sur les $mois derniers mois, le mois en cours compris.
     *
     * @return list<array{libelle: string, quantite: float, enCours: bool}>
     */
    public function consommationParMois(Articles $article, \DateTimeImmutable $maintenant, int $mois = 6): array
    {
        $debut = $maintenant->modify('first day of this month')->setTime(0, 0)->modify(sprintf('-%d months', $mois - 1));
        $lignes = $this->em->createQueryBuilder()
            ->select('s.date, s.quantite')
            ->from(StockSorties::class, 's')
            ->where('s.article = :article')
            ->andWhere('s.date >= :debut')
            ->setParameter('article', $article)
            ->setParameter('debut', $debut)
            ->getQuery()
            ->getArrayResult();

        $parMois = [];

        foreach ($lignes as $ligne) {
            $cle = $ligne['date']->format('Y-m');
            $parMois[$cle] = ($parMois[$cle] ?? 0.0) + (float) $ligne['quantite'];
        }

        $serie = [];

        for ($i = 0; $i < $mois; ++$i) {
            $m = $debut->modify(sprintf('+%d months', $i));
            $serie[] = [
                'libelle' => self::MOIS_COURTS[(int) $m->format('n') - 1],
                'quantite' => round($parMois[$m->format('Y-m')] ?? 0.0, 3),
                'enCours' => $i === $mois - 1,
            ];
        }

        return $serie;
    }

    /**
     * Moyenne mensuelle sur les mois terminés, et nombre de jours que le
     * disponible couvre à ce rythme.
     *
     * @param list<array{quantite: float, enCours: bool}> $serie
     *
     * @return array{moyenne: float, couvertureJours: ?int}
     */
    public static function couverture(array $serie, float $disponible): array
    {
        $termines = array_values(array_filter($serie, static fn (array $m) => !$m['enCours']));
        $moyenne = $termines !== [] ? array_sum(array_column($termines, 'quantite')) / \count($termines) : 0.0;

        return [
            'moyenne' => round($moyenne, 1),
            'couvertureJours' => $moyenne > 0 ? (int) floor($disponible / ($moyenne / 30)) : null,
        ];
    }

    /**
     * Date du dernier achat reçu de chaque fournisseur pour cet article.
     *
     * @return array<int, \DateTimeImmutable> par id de fournisseur
     */
    public function derniersAchats(Articles $article): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(a.fournisseur) AS fournisseur, MAX(a.dateReception) AS derniere')
            ->from(AchatDetail::class, 'l')
            ->join('l.achat', 'a')
            ->where('l.article = :article')
            ->andWhere('a.statut = :recu')
            ->groupBy('a.fournisseur')
            ->setParameter('article', $article)
            ->setParameter('recu', Achats::STATUT_RECU)
            ->getQuery()
            ->getArrayResult();

        $dates = [];

        foreach ($lignes as $ligne) {
            if ($ligne['fournisseur'] !== null && $ligne['derniere'] !== null) {
                $dates[(int) $ligne['fournisseur']] = new \DateTimeImmutable((string) $ligne['derniere']);
            }
        }

        return $dates;
    }

    /**
     * Derniers mouvements (entrées et sorties) de l'article, du plus récent au plus ancien.
     *
     * @return list<array{date: \DateTimeImmutable, sens: string, quantite: float, libelle: string}>
     */
    public function derniersMouvements(Articles $article, int $nombre = 8): array
    {
        $mouvements = [];

        foreach ($this->em->getRepository(StockEntrees::class)->findBy(['article' => $article], ['date' => 'DESC'], $nombre) as $entree) {
            $mouvements[] = ['date' => $entree->getDate(), 'sens' => 'entree', 'quantite' => (float) $entree->getQuantites(), 'libelle' => 'Entrée'.($entree->getPrix() ? ' · '.number_format((int) $entree->getPrix(), 0, ',', ' ').' F' : '')];
        }

        foreach ($this->em->getRepository(StockSorties::class)->findBy(['article' => $article], ['date' => 'DESC'], $nombre) as $sortie) {
            $commande = $sortie->getCommandeDetail()?->getCommande();
            $mouvements[] = ['date' => $sortie->getDate(), 'sens' => 'sortie', 'quantite' => (float) $sortie->getQuantite(), 'libelle' => $commande?->getNumero() ? 'Sortie · commande '.$commande->getNumero() : 'Sortie · '.($sortie->getOrigine() ?? 'manuelle')];
        }

        usort($mouvements, static fn (array $a, array $b) => $b['date'] <=> $a['date']);

        return \array_slice($mouvements, 0, $nombre);
    }

    /**
     * @param class-string $classe
     * @param list<Articles> $articles
     *
     * @return array<int, float>
     */
    private function sommes(string $classe, string $champ, array $articles, ?string $statut = null, ?\DateTimeImmutable $depuis = null): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select(sprintf('IDENTITY(x.article) AS article, COALESCE(SUM(x.%s), 0) AS total', $champ))
            ->from($classe, 'x')
            ->where('x.article IN (:articles)')
            ->groupBy('x.article')
            ->setParameter('articles', $articles);

        if ($statut !== null) {
            $qb->andWhere('x.statut = :statut')->setParameter('statut', $statut);
        }

        if ($depuis !== null) {
            $qb->andWhere('x.date >= :depuis')->setParameter('depuis', $depuis);
        }

        $totaux = [];

        foreach ($qb->getQuery()->getArrayResult() as $ligne) {
            $totaux[(int) $ligne['article']] = (float) $ligne['total'];
        }

        return $totaux;
    }

    /**
     * @param class-string $classe
     * @param list<Articles> $articles
     *
     * @return array<int, \DateTimeImmutable>
     */
    private function dernieresDates(string $classe, array $articles): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(x.article) AS article, MAX(x.date) AS derniere')
            ->from($classe, 'x')
            ->where('x.article IN (:articles)')
            ->groupBy('x.article')
            ->setParameter('articles', $articles)
            ->getQuery()
            ->getArrayResult();

        $dates = [];

        foreach ($lignes as $ligne) {
            if ($ligne['derniere'] !== null) {
                $dates[(int) $ligne['article']] = new \DateTimeImmutable((string) $ligne['derniere']);
            }
        }

        return $dates;
    }
}
