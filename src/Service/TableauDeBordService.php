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
     * Indicateurs du mois en cours (docs/indicateurs.md, section 1) : CA
     * facture, encaisse, taux d'encaissement et solde, compares au meme
     * nombre de jours du mois precedent et du meme mois l'an dernier, avec
     * 8 semaines d'historique pour la courbe de tendance.
     *
     * @return array<string, mixed>
     */
    public function indicateurs(\DateTimeImmutable $maintenant): array
    {
        $debutMois = $maintenant->modify('first day of this month')->setTime(0, 0);
        $debutMoisPrecedent = $debutMois->modify('-1 month');
        $debutAnPasse = $debutMois->modify('-1 year');
        $debutSerie = $maintenant->setTime(0, 0)->modify('monday this week')->modify('-7 weeks');

        $factures = $this->facturesDepuis(min($debutSerie, $debutAnPasse));
        $encaissements = $this->mouvementsDepuis(min($debutSerie, $debutMoisPrecedent), MouvementTresorerie::TYPE_ENCAISSEMENT);
        $decaissements = $this->mouvementsDepuis($debutMois, MouvementTresorerie::TYPE_DECAISSEMENT);

        $caMois = self::somme($factures, $debutMois, $maintenant);
        $encaisseMois = self::somme($encaissements, $debutMois, $maintenant);
        $decaisseMois = self::somme($decaissements, $debutMois, $maintenant);

        return [
            'ca' => [
                'valeur' => $caMois,
                'variation' => self::variation($caMois, self::somme($factures, $debutMoisPrecedent, $maintenant->modify('-1 month'))),
                'variationAnPasse' => self::variation($caMois, self::somme($factures, $debutAnPasse, $maintenant->modify('-1 year'))),
                'courbe' => self::pointsCourbe(self::serieHebdomadaire($factures, $debutSerie, 8)),
            ],
            'encaisse' => [
                'valeur' => $encaisseMois,
                'variation' => self::variation($encaisseMois, self::somme($encaissements, $debutMoisPrecedent, $maintenant->modify('-1 month'))),
                'taux' => $caMois > 0 ? (int) round($encaisseMois * 100 / $caMois) : null,
                'courbe' => self::pointsCourbe(self::serieHebdomadaire($encaissements, $debutSerie, 8)),
            ],
            'solde' => [
                'valeur' => $encaisseMois - $decaisseMois,
                'decaisse' => $decaisseMois,
            ],
        ];
    }

    /**
     * Factures emises non soldees.
     *
     * @return array{total: int, facturesEchues: int}
     */
    public function creances(\DateTimeImmutable $maintenant): array
    {
        $resultat = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(f.resteAPayer), 0) AS total')
            ->addSelect('SUM(CASE WHEN f.dateEcheance < :aujourdhui THEN 1 ELSE 0 END) AS echues')
            ->from(Factures::class, 'f')
            ->andWhere('f.etat = :emise')
            // Une proforma n'est ni une vente ni une créance.
            ->andWhere('f.typeDocument = :facture')
            ->andWhere('f.resteAPayer > 0')
            ->setParameter('emise', Factures::ETAT_EMISE)
            ->setParameter('facture', Factures::TYPE_FACTURE)
            ->setParameter('aujourdhui', $maintenant->setTime(0, 0))
            ->getQuery()
            ->getSingleResult();

        return ['total' => (int) $resultat['total'], 'facturesEchues' => (int) $resultat['echues']];
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

        return self::serieMensuelle($this->facturesDepuis($debut), $debut, 12);
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
     * Alertes de la vue direction, seuils de docs/indicateurs.md.
     *
     * @param array{indicateurs: array<string, mixed>, creances: array{total: int, facturesEchues: int}}|null $finances
     *
     * @return list<array{niveau: string, texte: string, route: string}>
     */
    public function alertes(?array $finances, bool $voirStock, \DateTimeImmutable $maintenant): array
    {
        $alertes = [];

        $enRetard = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT c.id)')
            ->from(Commandes::class, 'c')
            ->join('c.commandesDetails', 'cd')
            ->andWhere('c.deleted = false')
            ->andWhere('c.dateLivraison < :maintenant')
            ->andWhere('cd.statutProduction IN (:statuts)')
            ->setParameter('maintenant', $maintenant)
            ->setParameter('statuts', [
                CommandesDetails::PRODUCTION_A_PRODUIRE,
                CommandesDetails::PRODUCTION_EN_COURS,
                CommandesDetails::PRODUCTION_TERMINEE,
                CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
                CommandesDetails::PRODUCTION_EN_LIVRAISON,
            ])
            ->getQuery()
            ->getSingleScalarResult();

        if ($enRetard > 0) {
            $alertes[] = ['niveau' => 'danger', 'texte' => sprintf('%d commande%s en retard', $enRetard, $enRetard > 1 ? 's' : ''), 'route' => 'app_ventes_index'];
        }

        $batEnAttente = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(cp.id)')
            ->from(ControlePrePresse::class, 'cp')
            ->andWhere('cp.statut = :statut')
            ->andWhere('COALESCE(cp.traiteLe, cp.creeLe) < :limite')
            ->setParameter('statut', ControlePrePresse::STATUT_EN_ATTENTE_BAT)
            ->setParameter('limite', $maintenant->modify('-48 hours'))
            ->getQuery()
            ->getSingleScalarResult();

        if ($batEnAttente > 0) {
            $alertes[] = ['niveau' => 'warning', 'texte' => sprintf('%d BAT sans réponse du client depuis plus de 48 h', $batEnAttente), 'route' => 'app_controle_pre_presse_index'];
        }

        if (null !== $finances) {
            $ca = (int) $finances['indicateurs']['ca']['valeur'];
            $taux = $finances['indicateurs']['encaisse']['taux'];
            $creances = $finances['creances'];

            if (null !== $taux && $taux < 80) {
                $alertes[] = ['niveau' => 'warning', 'texte' => sprintf('Taux d\'encaissement du mois à %d %% (objectif 80 %%)', $taux), 'route' => 'app_factures_index'];
            }

            if ($ca > 0 && $creances['total'] > $ca * 0.2) {
                $alertes[] = ['niveau' => 'warning', 'texte' => 'Créances au-dessus de 20 % du CA du mois', 'route' => 'app_factures_index'];
            }

            if ($creances['facturesEchues'] > 0) {
                $alertes[] = [
                    'niveau' => 'danger',
                    'texte' => sprintf('%d facture%s échue%s à relancer', $creances['facturesEchues'], $creances['facturesEchues'] > 1 ? 's' : '', $creances['facturesEchues'] > 1 ? 's' : ''),
                    'route' => 'app_factures_index',
                ];
            }
        }

        if ($voirStock) {
            foreach ($this->articlesSousSeuil() as $article) {
                $alertes[] = [
                    'niveau' => $article['reste'] <= 0 ? 'danger' : 'warning',
                    'texte' => sprintf('%s : %s %s restants', $article['designation'], self::nombre($article['reste']), $article['unite']),
                    'route' => 'app_stock_etat_index',
                ];
            }
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
     * Factures emises (le CA facture), hors brouillons et annulees.
     *
     * @return list<array{date: \DateTimeInterface, montant: int}>
     */
    private function facturesDepuis(\DateTimeImmutable $debut): array
    {
        $resultats = $this->entityManager->createQueryBuilder()
            ->select('f.dateFacture AS date', 'f.totalTtc AS montant')
            ->from(Factures::class, 'f')
            ->andWhere('f.etat = :emise')
            ->andWhere('f.typeDocument = :facture')
            ->andWhere('f.dateFacture >= :debut')
            ->setParameter('emise', Factures::ETAT_EMISE)
            ->setParameter('facture', Factures::TYPE_FACTURE)
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
