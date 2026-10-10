<?php

namespace App\Service;

use App\Entity\Articles;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\Factures;
use App\Entity\Machines;
use App\Entity\MouvementTresorerie;
use App\Entity\Paiements;
use App\Entity\StockEntrees;
use App\Entity\StockSorties;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Donnees du tableau de bord direction (maquette 20) : indicateurs du
 * mois avec leur tendance, production en cours en colonnes, croissance
 * du CA sur 12 mois, encaissements du jour, charge des machines et
 * alertes. Tout vient de la base, rien n'est simule.
 */
final class TableauDeBordService
{
    /**
     * Colonnes de la maquette 20. « Finition » se remplira quand l'atelier
     * aura ce statut (ecran Production Kanban, maquette 23).
     */
    public const COLONNES_PRODUCTION = [
        'pao' => 'PAO',
        'bat' => 'BAT envoyé',
        'impression' => 'Impression',
        'finition' => 'Finition',
        'prete' => 'Prête',
    ];

    private const CARTES_PAR_COLONNE = 6;

    private const MOIS_COURTS = ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * CA, encaisse et solde du mois en cours, compares au meme nombre
     * de jours du mois precedent, avec 8 semaines d'historique pour la
     * courbe de tendance.
     *
     * @return array<string, mixed>
     */
    public function indicateurs(\DateTimeImmutable $maintenant): array
    {
        $debutMois = $maintenant->modify('first day of this month')->setTime(0, 0);
        $debutMoisPrecedent = $debutMois->modify('-1 month');
        $finComparaison = $maintenant->modify('-1 month');
        $debutSerie = $maintenant->setTime(0, 0)->modify('monday this week')->modify('-7 weeks');

        $ventes = $this->ventesDepuis(min($debutSerie, $debutMoisPrecedent));
        $encaissements = $this->mouvementsDepuis(min($debutSerie, $debutMoisPrecedent), MouvementTresorerie::TYPE_ENCAISSEMENT);
        $decaissements = $this->mouvementsDepuis($debutMois, MouvementTresorerie::TYPE_DECAISSEMENT);

        $caMois = self::somme($ventes, $debutMois, $maintenant);
        $caComparaison = self::somme($ventes, $debutMoisPrecedent, $finComparaison);
        $encaisseMois = self::somme($encaissements, $debutMois, $maintenant);
        $decaisseMois = self::somme($decaissements, $debutMois, $maintenant);

        return [
            'ca' => [
                'valeur' => $caMois,
                'variation' => self::variation($caMois, $caComparaison),
                'courbe' => self::pointsCourbe(self::serieHebdomadaire($ventes, $debutSerie, 8)),
            ],
            'encaisse' => [
                'valeur' => $encaisseMois,
                'partDuCa' => $caMois > 0 ? (int) round($encaisseMois * 100 / $caMois) : null,
                'courbe' => self::pointsCourbe(self::serieHebdomadaire($encaissements, $debutSerie, 8)),
            ],
            'solde' => [
                'valeur' => $encaisseMois - $decaisseMois,
                'decaisse' => $decaisseMois,
            ],
        ];
    }

    /**
     * @return array{total: int, facturesEchues: int}
     */
    public function creances(int $totalReste, \DateTimeImmutable $maintenant): array
    {
        $facturesEchues = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(f.id)')
            ->from(Factures::class, 'f')
            ->andWhere('f.etat = :emise')
            ->andWhere('f.resteAPayer > 0')
            ->andWhere('f.dateEcheance < :aujourdhui')
            ->setParameter('emise', Factures::ETAT_EMISE)
            ->setParameter('aujourdhui', $maintenant->setTime(0, 0))
            ->getQuery()
            ->getSingleScalarResult();

        return ['total' => $totalReste, 'facturesEchues' => $facturesEchues];
    }

    /**
     * Lignes de commande en cours, rangees par etape de fabrication.
     *
     * @return array<string, array{libelle: string, total: int, cartes: list<CommandesDetails>}>
     */
    public function production(): array
    {
        $colonnes = [];

        foreach (self::COLONNES_PRODUCTION as $cle => $libelle) {
            $colonnes[$cle] = ['libelle' => $libelle, 'total' => 0, 'cartes' => []];
        }

        $lignes = $this->entityManager->createQueryBuilder()
            ->select('d', 'c', 'cl', 'cp', 'm')
            ->from(CommandesDetails::class, 'd')
            ->join('d.commande', 'c')
            ->leftJoin('c.clients', 'cl')
            ->leftJoin('d.controlesPrePresse', 'cp')
            ->leftJoin('d.machine', 'm')
            ->andWhere('c.deleted = false')
            ->andWhere('d.productionNecessaire = true')
            ->andWhere('d.statutProduction IN (:statuts)')
            ->setParameter('statuts', [
                CommandesDetails::PRODUCTION_A_PRODUIRE,
                CommandesDetails::PRODUCTION_EN_COURS,
                CommandesDetails::PRODUCTION_TERMINEE,
                CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
            ])
            ->orderBy('c.dateCommande', 'ASC')
            ->getQuery()
            ->getResult();

        foreach ($lignes as $ligne) {
            $dernierControle = null;

            foreach ($ligne->getControlesPrePresse() as $controle) {
                if (null === $dernierControle || $controle->getId() > $dernierControle->getId()) {
                    $dernierControle = $controle;
                }
            }

            $cle = self::colonnePourLigne(
                $ligne->getStatutProduction(),
                $ligne->isPrePresseNecessaire(),
                $ligne->isBatValide(),
                $dernierControle?->getStatut(),
            );

            ++$colonnes[$cle]['total'];

            if (\count($colonnes[$cle]['cartes']) < self::CARTES_PAR_COLONNE) {
                $colonnes[$cle]['cartes'][] = $ligne;
            }
        }

        return $colonnes;
    }

    /**
     * Range une ligne de commande dans une colonne du tableau de production.
     */
    public static function colonnePourLigne(
        string $statutProduction,
        bool $prePresseNecessaire,
        bool $batValide,
        ?string $statutDernierControle,
    ): string {
        return match ($statutProduction) {
            CommandesDetails::PRODUCTION_TERMINEE,
            CommandesDetails::PRODUCTION_PRETE_LIVRAISON => 'prete',
            CommandesDetails::PRODUCTION_EN_COURS => 'impression',
            default => match (true) {
                !$prePresseNecessaire,
                $batValide,
                \in_array($statutDernierControle, [ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION], true) => 'impression',
                ControlePrePresse::STATUT_EN_ATTENTE_BAT === $statutDernierControle => 'bat',
                default => 'pao',
            },
        };
    }

    /**
     * CA des 12 derniers mois, le mois en cours en dernier.
     *
     * @return list<array{libelle: string, montant: int, hauteur: int, courant: bool}>
     */
    public function croissanceCa(\DateTimeImmutable $maintenant): array
    {
        $debut = $maintenant->modify('first day of this month')->setTime(0, 0)->modify('-11 months');

        return self::serieMensuelle($this->ventesDepuis($debut), $debut, 12);
    }

    /**
     * @return list<array{libelle: string, montant: int, part: int}>
     */
    public function encaissementsDuJour(\DateTimeImmutable $maintenant): array
    {
        $resultats = $this->entityManager->createQueryBuilder()
            ->select('p.mode AS mode', 'SUM(p.montant) AS montant')
            ->from(Paiements::class, 'p')
            ->andWhere('p.statut = :valide')
            ->andWhere('p.date >= :debut')
            ->setParameter('valide', Paiements::STATUT_VALIDE)
            ->setParameter('debut', $maintenant->setTime(0, 0))
            ->groupBy('p.mode')
            ->orderBy('montant', 'DESC')
            ->getQuery()
            ->getArrayResult();

        $lignes = array_map(static fn (array $ligne): array => [
            'libelle' => Paiements::MODES_LABELS[$ligne['mode']] ?? (string) $ligne['mode'],
            'mode' => (string) $ligne['mode'],
            'montant' => (int) $ligne['montant'],
        ], $resultats);

        return self::avecParts($lignes);
    }

    /**
     * Travaux en file ou en cours sur chaque machine.
     *
     * @return list<array{libelle: string, montant: int, part: int}>
     */
    public function chargeMachines(): array
    {
        $resultats = $this->entityManager->createQueryBuilder()
            ->select('m.nom AS nom', 'COUNT(d.id) AS travaux')
            ->from(Machines::class, 'm')
            ->leftJoin('m.commandesDetails', 'd', 'WITH', 'd.statutProduction IN (:statuts)')
            ->leftJoin('d.commande', 'c')
            ->andWhere('c.id IS NULL OR c.deleted = false')
            ->setParameter('statuts', [CommandesDetails::PRODUCTION_A_PRODUIRE, CommandesDetails::PRODUCTION_EN_COURS])
            ->groupBy('m.id')
            ->orderBy('travaux', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getArrayResult();

        $lignes = array_map(static fn (array $ligne): array => [
            'libelle' => (string) $ligne['nom'],
            'montant' => (int) $ligne['travaux'],
        ], $resultats);

        return self::avecParts($lignes, false);
    }

    /**
     * @return list<array{niveau: string, texte: string, route: string}>
     */
    public function alertes(int $facturesEchues, bool $voirStock, bool $voirFinances): array
    {
        $alertes = [];

        if ($voirStock) {
            foreach ($this->articlesSousSeuil() as $article) {
                $alertes[] = [
                    'niveau' => $article['reste'] <= 0 ? 'danger' : 'warning',
                    'texte' => sprintf('%s : %s %s restants', $article['designation'], self::nombre($article['reste']), $article['unite']),
                    'route' => 'app_stock_etat_index',
                ];
            }
        }

        $batEnAttente = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(cp.id)')
            ->from(ControlePrePresse::class, 'cp')
            ->andWhere('cp.statut = :statut')
            ->setParameter('statut', ControlePrePresse::STATUT_EN_ATTENTE_BAT)
            ->getQuery()
            ->getSingleScalarResult();

        if ($batEnAttente > 0) {
            $alertes[] = [
                'niveau' => 'warning',
                'texte' => sprintf('%d BAT en attente de réponse du client', $batEnAttente),
                'route' => 'app_controle_pre_presse_index',
            ];
        }

        if ($voirFinances && $facturesEchues > 0) {
            $alertes[] = [
                'niveau' => 'danger',
                'texte' => sprintf('%d facture%s échue%s à relancer', $facturesEchues, $facturesEchues > 1 ? 's' : '', $facturesEchues > 1 ? 's' : ''),
                'route' => 'app_factures_index',
            ];
        }

        return $alertes;
    }

    /**
     * Pourcentage d'evolution, ou null quand il n'y a pas de base de comparaison.
     */
    public static function variation(int $valeur, int $reference): ?int
    {
        if ($reference <= 0) {
            return null;
        }

        return (int) round(($valeur - $reference) * 100 / $reference);
    }

    /**
     * Points SVG d'une courbe de tendance tenant dans largeur x hauteur.
     *
     * @param list<int> $valeurs
     */
    public static function pointsCourbe(array $valeurs, int $largeur = 80, int $hauteur = 24): string
    {
        if (\count($valeurs) < 2 || 0 === max($valeurs)) {
            return '';
        }

        $min = min($valeurs);
        $ecart = max($valeurs) - $min;
        $pas = $largeur / (\count($valeurs) - 1);
        $points = [];

        foreach (array_values($valeurs) as $i => $valeur) {
            $y = 0 === $ecart ? $hauteur / 2 : $hauteur - 2 - ($valeur - $min) * ($hauteur - 4) / $ecart;
            $points[] = round($i * $pas, 1).','.round($y, 1);
        }

        return implode(' ', $points);
    }

    /**
     * @param list<array{date: \DateTimeInterface, montant: int}> $operations
     *
     * @return list<int>
     */
    public static function serieHebdomadaire(array $operations, \DateTimeImmutable $debut, int $semaines): array
    {
        $serie = array_fill(0, $semaines, 0);

        foreach ($operations as $operation) {
            $index = intdiv((int) $debut->diff($operation['date'])->format('%r%a'), 7);

            if ($operation['date'] >= $debut && $index < $semaines) {
                $serie[$index] += $operation['montant'];
            }
        }

        return $serie;
    }

    /**
     * @param list<array{date: \DateTimeInterface, montant: int}> $operations
     *
     * @return list<array{libelle: string, montant: int, hauteur: int, courant: bool}>
     */
    public static function serieMensuelle(array $operations, \DateTimeImmutable $debut, int $mois): array
    {
        $montants = array_fill(0, $mois, 0);
        $origine = (int) $debut->format('Y') * 12 + (int) $debut->format('n') - 1;

        foreach ($operations as $operation) {
            $index = (int) $operation['date']->format('Y') * 12 + (int) $operation['date']->format('n') - 1 - $origine;

            if ($index >= 0 && $index < $mois) {
                $montants[$index] += $operation['montant'];
            }
        }

        $max = max(1, ...$montants);
        $serie = [];

        foreach ($montants as $i => $montant) {
            $serie[] = [
                'libelle' => self::MOIS_COURTS[(int) $debut->modify(sprintf('+%d months', $i))->format('n') - 1],
                'montant' => $montant,
                'hauteur' => (int) round($montant * 100 / $max),
                'courant' => $i === $mois - 1,
            ];
        }

        return $serie;
    }

    /**
     * @param list<array{date: \DateTimeInterface, montant: int}> $operations
     */
    private static function somme(array $operations, \DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        $total = 0;

        foreach ($operations as $operation) {
            if ($operation['date'] >= $debut && $operation['date'] <= $fin) {
                $total += $operation['montant'];
            }
        }

        return $total;
    }

    /**
     * Ajoute a chaque ligne sa part de la plus grande (barres) ou du total.
     *
     * @param list<array{libelle: string, montant: int}> $lignes
     *
     * @return list<array{libelle: string, montant: int, part: int}>
     */
    private static function avecParts(array $lignes, bool $duTotal = true): array
    {
        $montants = array_column($lignes, 'montant');
        $base = $duTotal ? array_sum($montants) : max([0, ...$montants]);

        foreach ($lignes as &$ligne) {
            $ligne['part'] = $base > 0 ? (int) round($ligne['montant'] * 100 / $base) : 0;
        }

        return $lignes;
    }

    /**
     * @return list<array{date: \DateTimeInterface, montant: int}>
     */
    private function ventesDepuis(\DateTimeImmutable $debut): array
    {
        $resultats = $this->entityManager->createQueryBuilder()
            ->select('c.dateCommande AS date', 'c.totalTtc AS montant')
            ->from(Commandes::class, 'c')
            ->andWhere('c.deleted = false')
            ->andWhere('c.dateCommande >= :debut')
            ->setParameter('debut', $debut)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $ligne): array => ['date' => $ligne['date'], 'montant' => (int) $ligne['montant']], $resultats);
    }

    /**
     * @return list<array{date: \DateTimeInterface, montant: int}>
     */
    private function mouvementsDepuis(\DateTimeImmutable $debut, string $type): array
    {
        $resultats = $this->entityManager->createQueryBuilder()
            ->select('m.dateOperation AS date', 'm.montant AS montant')
            ->from(MouvementTresorerie::class, 'm')
            ->andWhere('m.type = :type')
            ->andWhere('m.statut = :valide')
            ->andWhere('m.dateOperation >= :debut')
            ->setParameter('type', $type)
            ->setParameter('valide', MouvementTresorerie::STATUT_VALIDE)
            ->setParameter('debut', $debut)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $ligne): array => ['date' => $ligne['date'], 'montant' => (int) $ligne['montant']], $resultats);
    }

    /**
     * @return list<array{designation: string, reste: float, unite: string}>
     */
    private function articlesSousSeuil(): array
    {
        $resultats = $this->entityManager->createQueryBuilder()
            ->select('a.designation AS designation', 'a.unite AS unite', 'a.stockMin AS seuil')
            ->addSelect(sprintf('(SELECT COALESCE(SUM(e.quantites), 0) FROM %s e WHERE e.article = a) AS entrees', StockEntrees::class))
            ->addSelect(sprintf('(SELECT COALESCE(SUM(s.quantite), 0) FROM %s s WHERE s.article = a) AS sorties', StockSorties::class))
            ->from(Articles::class, 'a')
            ->andWhere('a.actif = true')
            ->andWhere('a.stockMin > 0')
            ->getQuery()
            ->getArrayResult();

        $sousSeuil = [];

        foreach ($resultats as $ligne) {
            $reste = max(0.0, (float) $ligne['entrees'] - (float) $ligne['sorties']);

            if ($reste <= (float) $ligne['seuil']) {
                $sousSeuil[] = ['designation' => (string) $ligne['designation'], 'reste' => $reste, 'unite' => (string) $ligne['unite']];
            }
        }

        usort($sousSeuil, static fn (array $a, array $b): int => $a['reste'] <=> $b['reste']);

        return \array_slice($sousSeuil, 0, 5);
    }

    private static function nombre(float $valeur): string
    {
        return rtrim(rtrim(number_format($valeur, 2, ',', ' '), '0'), ',');
    }
}
