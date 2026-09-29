<?php

namespace App\Service\Statistiques;

use App\Entity\Articles;
use App\Entity\StockEntrees;
use App\Entity\StockSorties;
use App\Service\EvolutionTemporelleService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Variation du stock (entrées/sorties) et ventes directes des
 * articles (consommables vendables) dans le temps. Utilise par la
 * page "Statistiques globales" et par l'onglet Accueil.
 */
final class StatistiquesArticlesService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EvolutionTemporelleService $evolutionTemporelleService
    ) {
    }

    /**
     * Quantités totales entrées/sorties de stock par période, tous
     * articles confondus : permet de voir la variation du stock
     * dans le temps.
     *
     * @return array{labels: list<string>, entrees: list<int>, sorties: list<int>}
     */
    public function evolutionStock(string $granularite): array
    {
        [$cles, $labels, $debutFenetre] = $this->evolutionTemporelleService->genererPaniers($granularite);

        $entrees = array_fill_keys($cles, 0);
        $sorties = array_fill_keys($cles, 0);

        $listeEntrees = $this->entityManager
            ->getRepository(StockEntrees::class)
            ->createQueryBuilder('e')
            ->andWhere('e.date >= :debut')
            ->setParameter('debut', $debutFenetre)
            ->getQuery()
            ->getResult();

        foreach ($listeEntrees as $entree) {
            if (!$entree instanceof StockEntrees || $entree->getDate() === null) {
                continue;
            }

            $cle = $this->evolutionTemporelleService->clePourDate($entree->getDate(), $granularite);

            if (isset($entrees[$cle])) {
                $entrees[$cle] += (int) $entree->getQuantites();
            }
        }

        $listeSorties = $this->entityManager
            ->getRepository(StockSorties::class)
            ->createQueryBuilder('s')
            ->andWhere('s.date >= :debut')
            ->setParameter('debut', $debutFenetre)
            ->getQuery()
            ->getResult();

        foreach ($listeSorties as $sortie) {
            if (!$sortie instanceof StockSorties || $sortie->getDate() === null) {
                continue;
            }

            $cle = $this->evolutionTemporelleService->clePourDate($sortie->getDate(), $granularite);

            if (isset($sorties[$cle])) {
                $sorties[$cle] += (int) $sortie->getQuantite();
            }
        }

        return [
            'labels' => $labels,
            'entrees' => array_values($entrees),
            'sorties' => array_values($sorties),
        ];
    }

    /**
     * Articles vendus directement au client (livrés sur une
     * commande, hors consommation en production), classés par
     * quantité écoulée sur la période.
     *
     * @return list<array{nom: string, nombre: int, quantite: int, montant: int}>
     */
    public function ventesArticles(\DateTimeImmutable $debut, \DateTimeImmutable $fin, int $limite = 15): array
    {
        $sorties = $this->entityManager
            ->getRepository(StockSorties::class)
            ->createQueryBuilder('s')
            ->leftJoin('s.article', 'article')
            ->addSelect('article')
            ->andWhere('s.date BETWEEN :debut AND :fin')
            ->andWhere('s.origine = :origine')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('origine', StockSorties::ORIGINE_LIVRAISON)
            ->getQuery()
            ->getResult();

        $parArticle = [];

        foreach ($sorties as $sortie) {
            if (!$sortie instanceof StockSorties) {
                continue;
            }

            $article = $sortie->getArticle();

            if ($article === null) {
                continue;
            }

            $cle = $article->getId();

            $parArticle[$cle] ??= [
                'nom' => $article->getDesignation() ?? ('Article #' . $cle),
                'nombre' => 0,
                'quantite' => 0,
                'montant' => 0,
            ];

            $quantite = (int) $sortie->getQuantite();

            ++$parArticle[$cle]['nombre'];
            $parArticle[$cle]['quantite'] += $quantite;
            $parArticle[$cle]['montant'] += $quantite * (int) ($article->getPrixVente() ?? 0);
        }

        usort($parArticle, static fn (array $a, array $b): int => $b['quantite'] <=> $a['quantite']);

        return array_slice(array_values($parArticle), 0, $limite);
    }
}
