<?php

namespace App\Service\Stock;

use App\Entity\Articles;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\StockReservation;
use App\Entity\StockSorties;
use App\Service\NotificationService;
use App\Service\StockService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réservation du stock d'une commande, d'après la nomenclature des
 * produits (ProduitArticleStock, ou l'« article associé au stock » du
 * produit) et l'article des lignes de revente.
 *
 * Seul point d'entrée : synchroniser(), appelé après chaque
 * enregistrement d'une commande ou d'une ligne par
 * ReservationsCommandeListener, quel que soit l'écran (comptoir, devis
 * converti, espace client, Mes produits, conception, synchronisation).
 *
 * Règles (docs/stock-et-options.md, « Débit du stock par commande ») :
 *  - commande validée (Commandes::isStatut(), comme commandeEstValidee
 *    du comptoir) et pas à la corbeille : chaque ligne réserve ce
 *    que sa nomenclature demande ;
 *  - brouillon, commande en attente de confirmation (atelier ou
 *    validation interne de l'entreprise cliente), commande refusée ou à la
 *    corbeille, ligne annulée ou livrée : ses réservations encore
 *    actives sont libérées ;
 *  - une ligne dont le stock a déjà commencé à sortir (production
 *    terminée, livraison) garde ce qui lui reste : on ne recalcule
 *    plus, et rien n'est jamais remis en stock automatiquement ;
 *  - un manque de stock ne bloque jamais la commande : la réservation
 *    est posée quand même et les responsables du stock sont prévenus.
 */
final class ReservationsCommande
{
    public const GARDER = 'garder';
    public const LIBERER = 'liberer';
    public const RESERVER = 'reserver';

    /** Rôles prévenus d'un manque de stock à la commande (les mêmes que pour le stock bas). */
    public const ROLES_ALERTE = ['ROLE_ADMIN', 'ROLE_RESPONSABLE_GESTION'];

    private const TOLERANCE = 0.0005;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockService $stock,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Que faire des réservations d'une ligne.
     *
     * @param array<int, float> $besoins articleId => quantité demandée par la nomenclature
     * @param array<int, float> $actives articleId => quantité déjà réservée (active)
     */
    public static function decision(bool $commandeValidee, string $statutLigne, bool $stockDejaSorti, array $besoins, array $actives): string
    {
        if (!$commandeValidee || \in_array($statutLigne, [CommandesDetails::PRODUCTION_ANNULEE, CommandesDetails::PRODUCTION_LIVREE], true)) {
            return [] === $actives ? self::GARDER : self::LIBERER;
        }
        if ($stockDejaSorti) {
            // La matière a commencé à sortir : la réservation restante se consomme ou se libère à la livraison.
            return self::GARDER;
        }
        if ([] === $besoins) {
            return [] === $actives ? self::GARDER : self::LIBERER;
        }

        return self::memesQuantites($besoins, $actives) ? self::GARDER : self::RESERVER;
    }

    public static function commandeValidee(Commandes $commande): bool
    {
        return $commande->isStatut()
            && !$commande->isDeleted()
            && !$commande->attendValidationEntreprise()
            && !$commande->estRefuseeParEntreprise();
    }

    /**
     * Met les réservations de la commande en accord avec ses lignes.
     * N'enregistre pas (pas de flush) : l'appelant le fait.
     *
     * @return array{change: bool, manquants: list<string>}
     */
    public function synchroniser(Commandes $commande): array
    {
        $validee = self::commandeValidee($commande);
        [$actives, $sorties] = $this->etatStock($commande);

        $change = false;
        $reservee = false;
        foreach ($commande->getCommandesDetails() as $ligne) {
            $cle = spl_object_id($ligne);
            $activesLigne = $actives[$cle] ?? [];
            $besoins = [];
            $articles = [];
            if ($validee) {
                foreach ($this->stock->calculerBesoinsDetail($ligne) as $id => $besoin) {
                    $articles[$id] = $besoin['article'];
                    $besoins[$id] = (float) $besoin['quantite'];
                }
            }

            $decision = self::decision($validee, $ligne->getStatutProduction(), isset($sorties[$cle]), $besoins, self::totaux($activesLigne));
            if (self::GARDER === $decision) {
                continue;
            }

            foreach ($activesLigne as $reservations) {
                foreach ($reservations as $reservation) {
                    $reservation->liberer();
                }
            }
            $change = true;

            if (self::RESERVER !== $decision) {
                continue;
            }
            foreach ($besoins as $id => $quantite) {
                if ($quantite <= 0) {
                    continue;
                }
                $this->em->persist((new StockReservation())
                    ->setArticle($articles[$id])
                    ->setCommandeDetail($ligne)
                    ->setQuantite($quantite)
                    ->setStatut(StockReservation::STATUT_ACTIVE)
                    ->setObservation('Réservation automatique à la validation de la commande.'));
            }
            $reservee = true;
        }

        $manquants = $reservee ? $this->manquants($commande) : [];
        if ([] !== $manquants && null !== $commande->getId()) {
            $this->notifications->notifierRoles(
                self::ROLES_ALERTE,
                mb_substr(sprintf(
                    'Stock insuffisant pour la commande %s : %s. La commande est enregistrée, prévoir un réapprovisionnement.',
                    $commande->getNumero() ?? ('#'.$commande->getId()),
                    implode(' ; ', $manquants)
                ), 0, 1000),
                'app_commandes_show',
                ['id' => $commande->getId()]
            );
        }

        return ['change' => $change, 'manquants' => $manquants];
    }

    /**
     * Articles dont le stock ne couvre pas ce que la commande réserve :
     * « Bâche 440 g : besoin 12, disponible 4, manque 8 ».
     * Compte les réservations actives de la commande, y compris celles
     * pas encore enregistrées.
     *
     * @return list<string>
     */
    public function manquants(Commandes $commande): array
    {
        $parArticle = [];
        foreach ($this->reservationsActives($commande) as $reservation) {
            $article = $reservation->getArticle();
            if (!$article instanceof Articles || null === $article->getId()) {
                continue;
            }
            $parArticle[$article->getId()]['article'] = $article;
            $parArticle[$article->getId()]['quantite'] = ($parArticle[$article->getId()]['quantite'] ?? 0.0) + (float) $reservation->getQuantite();
        }

        $manquants = [];
        foreach ($parArticle as ['article' => $article, 'quantite' => $requis]) {
            $disponible = $this->stock->getStockDisponiblePourCommande($article, $commande);
            if ($disponible + self::TOLERANCE >= $requis) {
                continue;
            }
            $manquants[] = sprintf(
                '%s : besoin %s, disponible %s, manque %s',
                trim((string) $article->getDesignation()) ?: 'Article #'.$article->getId(),
                self::quantite($requis),
                self::quantite($disponible),
                self::quantite($requis - $disponible)
            );
        }

        return $manquants;
    }

    /**
     * Ce que la commande a réservé et ce qui est déjà sorti du stock, par
     * article, pour la fiche commande.
     *
     * @return list<array{article: Articles, reserve: float, sorti: float}>
     */
    public function resume(Commandes $commande): array
    {
        if (null === $commande->getId()) {
            return [];
        }
        $lignes = [];
        $ajouter = static function (?Articles $article, string $cle, float $quantite) use (&$lignes): void {
            if (null === $article || null === $article->getId()) {
                return;
            }
            $lignes[$article->getId()] ??= ['article' => $article, 'reserve' => 0.0, 'sorti' => 0.0];
            $lignes[$article->getId()][$cle] += $quantite;
        };
        foreach ($this->reservationsActives($commande) as $r) {
            $ajouter($r->getArticle(), 'reserve', (float) $r->getQuantite());
        }
        foreach ($this->sorties($commande) as $s) {
            $ajouter($s->getArticle(), 'sorti', (float) $s->getQuantite());
        }

        return array_values($lignes);
    }

    /**
     * Réservations actives et lignes déjà sorties du stock, rangées par
     * ligne de commande (spl_object_id) puis par article.
     *
     * @return array{0: array<int, array<int, list<StockReservation>>>, 1: array<int, true>}
     */
    private function etatStock(Commandes $commande): array
    {
        $actives = [];
        $sorties = [];
        if (null === $commande->getId()) {
            return [$actives, $sorties];
        }

        foreach ($this->reservations($commande) as $r) {
            $ligne = $r->getCommandeDetail();
            if (null === $ligne) {
                continue;
            }
            if (StockReservation::STATUT_CONSOMMEE === $r->getStatut()) {
                $sorties[spl_object_id($ligne)] = true;
            } elseif ($r->isActive() && null !== $r->getArticle()?->getId()) {
                $actives[spl_object_id($ligne)][$r->getArticle()->getId()][] = $r;
            }
        }
        foreach ($this->sorties($commande) as $s) {
            if (null !== $s->getCommandeDetail() && StockSorties::ORIGINE_MANUELLE !== $s->getOrigine()) {
                $sorties[spl_object_id($s->getCommandeDetail())] = true;
            }
        }

        return [$actives, $sorties];
    }

    /** @return list<StockReservation> */
    private function reservations(Commandes $commande): array
    {
        return $this->em->createQueryBuilder()
            ->select('r')->from(StockReservation::class, 'r')
            ->join('r.commandeDetail', 'd')
            ->andWhere('d.commande = :commande')->setParameter('commande', $commande)
            ->getQuery()->getResult();
    }

    /**
     * Réservations actives, enregistrées ou non.
     *
     * @return list<StockReservation>
     */
    private function reservationsActives(Commandes $commande): array
    {
        $liste = [];
        foreach (null !== $commande->getId() ? $this->reservations($commande) : [] as $r) {
            $liste[spl_object_id($r)] = $r;
        }
        foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $r) {
            if ($r instanceof StockReservation && $r->getCommandeDetail()?->getCommande() === $commande) {
                $liste[spl_object_id($r)] = $r;
            }
        }

        return array_values(array_filter($liste, static fn (StockReservation $r): bool => $r->isActive()));
    }

    /** @return list<StockSorties> */
    private function sorties(Commandes $commande): array
    {
        return $this->em->createQueryBuilder()
            ->select('s')->from(StockSorties::class, 's')
            ->join('s.commandeDetail', 'd')
            ->andWhere('d.commande = :commande')->setParameter('commande', $commande)
            ->getQuery()->getResult();
    }

    /**
     * @param array<int, list<StockReservation>> $reservations
     *
     * @return array<int, float>
     */
    private static function totaux(array $reservations): array
    {
        return array_map(static fn (array $liste): float => array_sum(array_map(static fn (StockReservation $r): float => (float) $r->getQuantite(), $liste)), $reservations);
    }

    /**
     * @param array<int, float> $a
     * @param array<int, float> $b
     */
    private static function memesQuantites(array $a, array $b): bool
    {
        $a = array_filter($a, static fn (float $q): bool => $q > 0);
        $b = array_filter($b, static fn (float $q): bool => $q > 0);
        ksort($a);
        ksort($b);
        if (array_keys($a) !== array_keys($b)) {
            return false;
        }
        foreach ($a as $id => $quantite) {
            // Les réservations sont enregistrées à 3 décimales.
            if (abs(round($quantite, 3) - $b[$id]) > self::TOLERANCE) {
                return false;
            }
        }

        return true;
    }

    private static function quantite(float $q): string
    {
        return abs($q - round($q)) < 0.000001 ? number_format($q, 0, ',', ' ') : rtrim(rtrim(number_format($q, 3, ',', ' '), '0'), ',');
    }
}
