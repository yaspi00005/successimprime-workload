<?php

namespace App\Service\Production;

use App\Entity\Articles;
use App\Entity\AtelierMachine;
use App\Entity\CommandesDetails;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\PieceTirage;
use App\Entity\StockSorties;
use App\Entity\TirageRouleau;
use App\Service\Atelier\ActionsOrdre;
use App\Service\StockService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce que la production sort du stock en plus de la recette du produit
 * (PD04 et PD06, docs/production-rouleau.md) :
 *  - à « Impression terminée » d'un rouleau, la chute (ROUL-<numéro>) et
 *    l'encre estimée (ROUL-<numéro>-ENCRE), partagées entre ses lignes ;
 *  - à « Terminé » d'un travail imprimé seul, son encre (PROD-…-ENCRE) ;
 *  - au contrôle final, les œillets et attaches des finitions (FIN-…).
 * Origine « production », pour que la livraison et les réservations les
 * reconnaissent. Idempotent (StockService::sortirArticlePourDetail). La
 * chute ne va jamais au journal de gâche de la machine. Quantités
 * seulement. Ne flushe pas.
 */
final class SortiesProduction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockService $stock,
    ) {
    }

    /**
     * Encres de la machine : articles en litres rattachés à la machine
     * (comme la consommation d'encre du parc).
     *
     * @return array<int, Articles> id => article
     */
    public function encres(Machines $machine): array
    {
        /** @var list<Articles> $articles */
        $articles = $this->em->createQueryBuilder()->select('a')->from(Articles::class, 'a')
            ->where('a.machine = :m')->andWhere('a.unite = :litre')->andWhere('a.actif = true')
            ->setParameter('m', $machine)->setParameter('litre', 'litre')
            ->orderBy('a.id', 'ASC')->getQuery()->getResult();
        $parId = [];
        foreach ($articles as $a) {
            $parId[(int) $a->getId()] = $a;
        }

        return $parId;
    }

    /**
     * m² imprimés par ligne de commande sur le rouleau (surface des pièces).
     *
     * @return array<int, float> id de la ligne => m²
     */
    public static function m2ParLigne(TirageRouleau $tirage): array
    {
        $m2 = [];
        foreach ($tirage->piecesTriees() as $p) {
            $id = (int) $p->getOrdre()->getCommandeDetail()?->getId();
            if ($id > 0) {
                $m2[$id] = ($m2[$id] ?? 0.0) + self::m2Piece($p);
            }
        }

        return $m2;
    }

    public static function m2Piece(PieceTirage $p): float
    {
        return $p->getLargeurCm() * $p->getHauteurCm() / 10000;
    }

    /**
     * À « Impression terminée » : chute du rouleau et encre, ligne par
     * ligne. Rend ce qui n'a pas pu sortir (à dire sur l'écran).
     *
     * @return list<string>
     */
    public function apresRouleau(TirageRouleau $tirage, ?float $encreMlM2): array
    {
        $notes = [];
        $lignes = [];
        foreach ($tirage->ordres() as $o) {
            $d = $o->getCommandeDetail();
            if (null !== $d && null !== $d->getId()) {
                $lignes[(int) $d->getId()] = $d;
            }
        }
        $m2 = array_intersect_key(self::m2ParLigne($tirage), $lignes);
        if ([] === $m2) {
            return $notes;
        }

        // Chute : la part de chaque ligne dans le rouleau consommé, moins ce que la recette a déjà sorti du même article.
        $article = $tirage->getSupport();
        if (null === $article) {
            $notes[] = 'Aucun article de stock pour ce support : la chute du rouleau n\'est pas sortie.';
        } else {
            $consomme = ConsommationProduction::versUniteStock($article->getUnite(), $tirage->getConsommeM2(), $article->getLaize() ?? $tirage->getLaizeM(), $article->getLongueurUniteAchat());
            if (null === $consomme) {
                $notes[] = \sprintf('%s est compté en %s : la chute du rouleau n\'est pas sortie.', $article->getDesignation(), $article->getUnite());
            } elseif ($consomme > 0) {
                $deja = $this->dejaSortis(array_values($lignes), $article);
                foreach (ConsommationProduction::complements($consomme, $m2, $deja) as $id => $q) {
                    if ($q > 0) {
                        $this->stock->sortirArticlePourDetail($lignes[$id], $article, $q, StockSorties::ORIGINE_PRODUCTION, self::referenceRouleau($tirage));
                    }
                }
            }
        }

        // Encre : m² imprimés × ml/m² du profil, partagée entre les lignes puis entre les cartouches.
        $ml = null !== $encreMlM2 && $encreMlM2 > 0 ? round(array_sum($m2) * $encreMlM2, 1) : null;
        $encres = $this->encres($tirage->getMachine());
        if (null === $ml) {
            $notes[] = 'Le profil de la machine ne donne pas l\'encre par m² : l\'encre n\'est pas sortie.';
        } elseif ([] === $encres) {
            $notes[] = \sprintf('%s n\'a pas d\'article d\'encre en stock : l\'encre n\'est pas sortie.', $tirage->getMachine()->getNom());
        } else {
            $noms = array_map(static fn (Articles $a): string => (string) $a->getDesignation(), $encres);
            foreach (ConsommationProduction::repartir($ml, $m2, 1) as $id => $mlLigne) {
                foreach (ConsommationProduction::encre($mlLigne, $noms) as $articleId => $litres) {
                    if ($litres > 0) {
                        $this->stock->sortirArticlePourDetail($lignes[$id], $encres[$articleId], $litres, StockSorties::ORIGINE_PRODUCTION, self::referenceRouleau($tirage).'-ENCRE');
                    }
                }
            }
        }

        return $notes;
    }

    /**
     * À « Terminé » d'un travail imprimé seul (pas sur un rouleau en
     * impression ; une pièce refaite l'est toujours seule) : l'encre de ses
     * pièces d'après le profil de la machine, s'il le dit.
     */
    public function encreOrdre(OrdreProduction $ordre, int $imprime): void
    {
        $machine = $ordre->getMachine();
        $ligne = $ordre->getCommandeDetail();
        if (null === $machine || null === $ligne || $imprime < 1 || true === $ordre->getTirage()?->estLance()) {
            return;
        }
        $encreMlM2 = $this->profilLigne($machine, $ligne)['encreMlM2'] ?? null;
        $m2 = ActionsOrdre::surfacePiece($ordre) * $imprime;
        $encres = $this->encres($machine);
        if (null === $encreMlM2 || $encreMlM2 <= 0 || $m2 <= 0 || [] === $encres) {
            return;
        }
        $noms = array_map(static fn (Articles $a): string => (string) $a->getDesignation(), $encres);
        foreach (ConsommationProduction::encre(round($m2 * $encreMlM2, 1), $noms) as $articleId => $litres) {
            if ($litres > 0) {
                $this->stock->sortirArticlePourDetail($ligne, $encres[$articleId], $litres, StockSorties::ORIGINE_PRODUCTION, $ordre->referenceStock().'-ENCRE');
            }
        }
    }

    /**
     * Au contrôle final : les articles posés avec les finitions (œillets :
     * le nombre compté pour le contrôle × pièces ; autre finition : un par
     * pièce), dans l'unité de stock de l'article. Chaque reprise sort ses
     * propres pièces (FIN-…-R1). Rend ce qui n'a pas pu sortir.
     *
     * @return list<string>
     */
    public function finitions(OrdreProduction $ordre): array
    {
        $ligne = $ordre->getCommandeDetail();
        if (null === $ligne) {
            return [];
        }
        $passages = [0 => max(1, (int) $ordre->getQuantite())] + $ordre->getPiecesReprises();
        $notes = [];
        foreach (self::articlesFinitions($ligne) as $f) {
            foreach ($passages as $reprise => $pieces) {
                $quantite = ConsommationProduction::piecesEnStock($f['article']->getUnite(), $f['parPiece'] * $pieces, (float) ($f['article']->getQuantiteParUniteAchat() ?? 0));
                if (null === $quantite) {
                    $notes[$f['article']->getId()] = \sprintf('%s est compté en %s sans dire combien il en contient : rien n\'est sorti.', $f['article']->getDesignation(), $f['article']->getUnite());
                    continue;
                }
                if ($quantite > 0) {
                    $this->stock->sortirArticlePourDetail($ligne, $f['article'], $quantite, StockSorties::ORIGINE_PRODUCTION, self::referenceFinition($ordre, $reprise));
                }
            }
        }

        return array_values($notes);
    }

    /**
     * Les finitions de la ligne qui ont un article de stock, avec le nombre
     * posé par pièce (œillets d'après l'espacement et la taille, sinon 1).
     *
     * @return list<array{article: Articles, parPiece: int}>
     */
    public static function articlesFinitions(CommandesDetails $ligne): array
    {
        $l = round((float) ($ligne->getLargeur() ?? 0) * 100, 1);
        $h = round((float) ($ligne->getLongueur() ?? 0) * 100, 1);
        $articles = [];
        foreach ($ligne->getFinitions() as $f) {
            $article = $f->getFinition()?->getArticleStock();
            if (null === $article) {
                continue;
            }
            $espacement = ReglesControleFinal::espacementOeillets((string) $f->getNomFinition());
            $articles[] = ['article' => $article, 'parPiece' => null !== $espacement && $l > 0 && $h > 0 ? ReglesControleFinal::oeilletsParPiece($l, $h, $espacement) : 1];
        }

        return $articles;
    }

    /** Profil d'impression de la machine pour le support de la ligne, ou null. */
    public function profilLigne(Machines $machine, CommandesDetails $ligne): ?array
    {
        $atelier = $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine]);

        return null !== $atelier ? ProfilsImpression::choisir($atelier->getProfils(), $ligne->getArticle()?->getId(), FichierProduction::libelleSupport($ligne), (string) FichierProduction::codeSupport($ligne)) : null;
    }

    /**
     * Ce que le rouleau a sorti du stock (chute, encre), par article, en
     * quantités lisibles.
     *
     * @return list<array{article: string, quantite: string}>
     */
    public function duRouleau(TirageRouleau $tirage): array
    {
        $rangs = $this->em->createQueryBuilder()
            ->select('a.designation AS designation', 'a.unite AS unite', 'SUM(s.quantite) AS quantite')
            ->from(StockSorties::class, 's')->join('s.article', 'a')
            ->where('s.referenceOrigine IN (:refs)')->andWhere('s.origine = :o')
            ->setParameter('refs', [self::referenceRouleau($tirage), self::referenceRouleau($tirage).'-ENCRE'])->setParameter('o', StockSorties::ORIGINE_PRODUCTION)
            ->groupBy('a.id', 'a.designation', 'a.unite')->orderBy('a.unite', 'DESC')->addOrderBy('a.designation', 'ASC')
            ->getQuery()->getArrayResult();

        return array_map(static fn (array $r): array => ['article' => (string) $r['designation'], 'quantite' => QuantiteLisible::texte((float) $r['quantite'], (string) $r['unite'])], $rangs);
    }

    public static function referenceRouleau(TirageRouleau $tirage): string
    {
        return 'ROUL-'.$tirage->getNumero();
    }

    public static function referenceFinition(OrdreProduction $ordre, int $reprise = 0): string
    {
        return \sprintf('FIN-%06d', (int) $ordre->getId()).($reprise > 0 ? '-R'.$reprise : '');
    }

    /**
     * Ce qui est déjà sorti de cet article pour ces lignes (production).
     *
     * @param list<CommandesDetails> $lignes
     *
     * @return array<int, float> id de la ligne => quantité
     */
    private function dejaSortis(array $lignes, Articles $article): array
    {
        $rangs = $this->em->createQueryBuilder()->select('IDENTITY(s.commandeDetail) AS ligne', 'SUM(s.quantite) AS quantite')
            ->from(StockSorties::class, 's')
            ->where('s.commandeDetail IN (:lignes)')->andWhere('s.article = :a')->andWhere('s.origine = :o')
            ->setParameter('lignes', $lignes)->setParameter('a', $article)->setParameter('o', StockSorties::ORIGINE_PRODUCTION)
            ->groupBy('s.commandeDetail')->getQuery()->getArrayResult();

        return array_column(array_map(static fn (array $r): array => [(int) $r['ligne'], (float) $r['quantite']], $rangs), 1, 0);
    }
}
