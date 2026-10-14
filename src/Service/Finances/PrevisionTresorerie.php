<?php

namespace App\Service\Finances;

use App\Entity\CompteTresorerie;
use App\Entity\Depense;
use App\Entity\MouvementTresorerie;
use App\Entity\Paiements;
use App\Service\Creances;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Prévision de trésorerie à 90 jours (maquette F05). On part du solde de
 * tous les comptes actifs (mouvements validés seulement), puis jour par
 * jour :
 * - entrées : le reste dû de chaque commande (facturée ou en cours) au
 *   jour où on l'attend, multiplié par une probabilité qui baisse avec le
 *   retard et tient compte du retard moyen du client ; plus la moyenne
 *   des ventes payées au comptoir sur les 90 derniers jours ;
 * - sorties : l'échéancier de F04 (fournisseurs, charges récurrentes,
 *   impôts, salaires) plus la moyenne des dépenses courantes payées au
 *   comptant (achats, carburant, transport, entretien, frais bancaires).
 *
 * Rien n'est enregistré : c'est une estimation recalculée à chaque
 * affichage, qui ne crée ni ne valide aucun mouvement.
 */
final class PrevisionTresorerie
{
    public const JOURS = 90;

    /** Période de référence des moyennes (ventes comptoir, dépenses courantes). */
    public const JOURS_HISTORIQUE = 90;

    /** Un paiement reçu dans les 3 jours qui suivent la commande est une vente comptoir. */
    public const JOURS_COMPTOIR = 3;

    /** Une créance déjà échue est attendue une semaine après la relance. */
    public const JOURS_RELANCE = 7;

    /**
     * probabilite : multiplie la probabilité d'encaisser ;
     * retard_client : tient compte du retard moyen du client ;
     * decalage : jours ajoutés à chaque date attendue ;
     * comptoir / courantes : multiplient les moyennes.
     */
    public const SCENARIOS = [
        'prudent' => ['libelle' => 'Scénario prudent', 'probabilite' => 0.85, 'retard_client' => true, 'decalage' => 7, 'comptoir' => 0.85, 'courantes' => 1.1],
        'realiste' => ['libelle' => 'Scénario réaliste', 'probabilite' => 1.0, 'retard_client' => true, 'decalage' => 0, 'comptoir' => 1.0, 'courantes' => 1.0],
        'optimiste' => ['libelle' => 'Scénario optimiste', 'probabilite' => 1.1, 'retard_client' => false, 'decalage' => 0, 'comptoir' => 1.1, 'courantes' => 0.95],
    ];

    /** Chance d'encaisser un reste dû selon son retard (tranches de la balance âgée). */
    public const PROBABILITES = ['a_echoir' => 0.9, '0_30' => 0.75, '31_60' => 0.5, '61_90' => 0.3, 'plus_90' => 0.1];

    /** Dépenses payées au comptant qui ne sont pas dans l'échéancier. */
    public const CATEGORIES_COURANTES = [
        MouvementTresorerie::CATEGORIE_ACHAT,
        MouvementTresorerie::CATEGORIE_CARBURANT,
        MouvementTresorerie::CATEGORIE_TRANSPORT,
        MouvementTresorerie::CATEGORIE_ENTRETIEN,
        MouvementTresorerie::CATEGORIE_FRAIS_BANCAIRE,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Creances $creances,
        private readonly Echeancier $echeancier,
        private readonly ReglagesFinances $reglages,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function donnees(\DateTimeImmutable $maintenant, string $scenario = 'prudent'): array
    {
        $cle = isset(self::SCENARIOS[$scenario]) ? $scenario : 'prudent';
        $s = self::SCENARIOS[$cle];
        $aujourdhui = $maintenant->setTime(0, 0);
        $horizon = $aujourdhui->modify('+'.self::JOURS.' days');

        /** @var list<CompteTresorerie> $comptes */
        $comptes = $this->em->getRepository(CompteTresorerie::class)->findBy(['actif' => true]);
        $depart = array_sum(array_map(static fn (CompteTresorerie $c): int => $c->getSoldeActuel(), $comptes));

        // Entrées : restes dus des commandes, pondérés.
        $lignes = $this->creances->donnees($maintenant)['lignes'];
        $clients = array_values(array_unique(array_filter(array_map(static fn (array $l): ?int => $l['commande']->getClients()?->getId(), $lignes))));
        $retards = $s['retard_client'] ? $this->retardsClients($clients, $aujourdhui) : [];
        $entrees = [];
        $detailEntrees = ['creances' => ['montant' => 0, 'nombre' => 0], 'commandes' => ['montant' => 0, 'nombre' => 0], 'comptoir' => ['montant' => 0, 'par_jour' => 0]];
        $aRelancer = [];
        foreach ($lignes as $l) {
            if ($l['jours'] > 0) {
                $aRelancer[] = ['client' => $l['client'], 'document' => $l['document'], 'reste' => $l['reste'], 'jours' => $l['jours']];
            }
            if (null === $l['echeance']) {
                continue;
            }
            $client = $l['commande']->getClients()?->getId();
            $retardClient = null !== $client ? ($retards[$client] ?? null) : null;
            $date = self::dateAttendue($l['echeance'], $aujourdhui, $retardClient, $s['decalage']);
            if ($date > $horizon) {
                continue;
            }
            $montant = (int) round($l['reste'] * self::probabilite($l['jours'], $retardClient, $s['probabilite']));
            $jour = $date->format('Y-m-d');
            $entrees[$jour] = ($entrees[$jour] ?? 0) + $montant;
            // Commande en cours : pas encore facturée ni livrée ; le reste est une créance.
            $type = null === $l['facture'] && $l['jours'] <= 0 ? 'commandes' : 'creances';
            $detailEntrees[$type]['montant'] += $montant;
            ++$detailEntrees[$type]['nombre'];
        }
        usort($aRelancer, static fn (array $a, array $b): int => [$b['jours'] > 30, $b['reste']] <=> [$a['jours'] > 30, $a['reste']]);

        $comptoir = (int) round($this->ventesComptoirParJour($aujourdhui) * $s['comptoir']);
        $detailEntrees['comptoir'] = ['montant' => $comptoir * self::JOURS, 'par_jour' => $comptoir];

        // Sorties : l'échéancier, plus les dépenses courantes moyennes.
        $echeances = $this->echeancier->sorties($aujourdhui, $horizon);
        $sorties = [];
        $detailSorties = array_fill_keys(array_keys(Echeancier::TYPES), 0);
        $selonDeclaration = [];
        foreach ($echeances as $e) {
            if (null === $e['montant']) {
                $selonDeclaration[] = $e;
                continue;
            }
            $jour = $e['date']->format('Y-m-d');
            $sorties[$jour] = ($sorties[$jour] ?? 0) + $e['montant'];
            $detailSorties[$e['type']] += $e['montant'];
        }
        $courantes = (int) round($this->depensesCourantesParJour($aujourdhui) * $s['courantes']);
        $detailSorties['courantes'] = $courantes * self::JOURS;

        for ($j = 1; $j <= self::JOURS; ++$j) {
            $jour = $aujourdhui->modify('+'.$j.' days')->format('Y-m-d');
            $entrees[$jour] = ($entrees[$jour] ?? 0) + $comptoir;
            $sorties[$jour] = ($sorties[$jour] ?? 0) + $courantes;
        }

        $points = self::soldes($depart, $entrees, $sorties, $aujourdhui, self::JOURS);
        $seuil = $this->reglages->seuilTresorerie();
        $bas = self::pointBas($points);
        $avecMontant = array_values(array_filter($echeances, static fn (array $e): bool => null !== $e['montant']));
        $reperes = self::reperes($avecMontant, $aujourdhui, 5, max(100000, (int) round(abs($depart) * 0.02)));

        return [
            'scenario' => $cle,
            'libelle_scenario' => $s['libelle'],
            'aujourdhui' => $aujourdhui,
            'horizon' => $horizon,
            'depart' => $depart,
            'nb_comptes' => \count($comptes),
            'seuil' => $seuil,
            'points' => $points,
            'point_bas' => $bas,
            'cause_point_bas' => self::cause($avecMontant, $aujourdhui, $bas['date']),
            'sous_seuil' => self::premierSousSeuil($points, $seuil),
            'entrees' => array_sum(array_column($points, 'entrees')),
            'sorties' => array_sum(array_column($points, 'sorties')),
            'detail_entrees' => $detailEntrees,
            'detail_sorties' => $detailSorties,
            'courantes_par_jour' => $courantes,
            'selon_declaration' => $selonDeclaration,
            'a_relancer' => \array_slice($aRelancer, 0, 3),
            'trace' => self::trace($points, $seuil, $reperes),
        ];
    }

    /**
     * Message à la direction quand le solde prévu passe sous le seuil,
     * avec les créances à relancer en priorité ; null sinon.
     *
     * @param array{seuil: int, aujourdhui: \DateTimeImmutable, sous_seuil: ?array{date: \DateTimeImmutable, solde: int}, point_bas: array{date: \DateTimeImmutable, solde: int}, a_relancer: list<array{client: string, reste: int}>} $donnees
     */
    public static function messageAlerte(array $donnees): ?string
    {
        $sous = $donnees['sous_seuil'];
        if (null === $sous) {
            return null;
        }
        $f = static fn (int $n): string => number_format($n, 0, ',', ' ').' F';
        $message = $sous['date'] <= $donnees['aujourdhui']
            ? \sprintf('Trésorerie déjà sous le seuil de %s', $f($donnees['seuil']))
            : \sprintf('Trésorerie : le solde prévu passe sous %s le %s', $f($donnees['seuil']), $sous['date']->format('d/m'));
        $message .= \sprintf(' (point bas %s le %s).', $f($donnees['point_bas']['solde']), $donnees['point_bas']['date']->format('d/m'));
        if ([] !== $donnees['a_relancer']) {
            $message .= ' À relancer : '.implode(', ', array_map(static fn (array $c): string => $c['client'].' '.$f($c['reste']), $donnees['a_relancer'])).'.';
        }

        return mb_strlen($message) > 255 ? mb_substr($message, 0, 254).'…' : $message;
    }

    /**
     * Chance d'encaisser : selon le retard, plus ou moins selon le retard
     * moyen du client (bon payeur : +5 points ; plus de 45 jours : −15),
     * puis le coefficient du scénario.
     */
    public static function probabilite(int $joursDeRetard, ?int $retardClient, float $coefficient = 1.0): float
    {
        $base = $joursDeRetard <= 0 ? self::PROBABILITES['a_echoir'] : self::PROBABILITES[Creances::tranche($joursDeRetard)];
        if (null !== $retardClient) {
            $base += $retardClient <= 15 ? 0.05 : ($retardClient > 45 ? -0.15 : 0.0);
        }

        return round(max(0.0, min(1.0, $base * $coefficient)), 3);
    }

    /**
     * Jour où l'on attend l'argent : l'échéance plus le retard habituel du
     * client. Une créance déjà échue est attendue après une relance,
     * d'autant plus tard qu'elle est ancienne : 7 jours par tranche de la
     * balance âgée (1 à 30 jours de retard : 7 jours ; plus de 90 : 28).
     */
    public static function dateAttendue(\DateTimeImmutable $echeance, \DateTimeImmutable $aujourdhui, ?int $retardClient, int $decalage = 0): \DateTimeImmutable
    {
        $aujourdhui = $aujourdhui->setTime(0, 0);
        $echeance = $echeance->setTime(0, 0);
        if ($echeance >= $aujourdhui) {
            $date = $echeance->modify('+'.(max(0, (int) $retardClient) + $decalage).' days');

            return max($date, $aujourdhui->modify('+1 day'));
        }
        $retard = (int) $echeance->diff($aujourdhui)->format('%a');
        $rang = (int) array_search(Creances::tranche($retard), array_keys(Creances::TRANCHES), true) + 1;

        return $aujourdhui->modify('+'.(self::JOURS_RELANCE * $rang + $decalage).' days');
    }

    /**
     * Retard moyen d'un client sur ses commandes soldées : il faut au
     * moins deux commandes pour en tirer une habitude.
     *
     * @param list<int> $retards jours entre l'échéance et le dernier paiement
     */
    public static function retardMoyen(array $retards): ?int
    {
        if (\count($retards) < 2) {
            return null;
        }

        return (int) round(array_sum(array_map(static fn (int $r): int => max(0, $r), $retards)) / \count($retards));
    }

    /**
     * Solde à la fin de chaque jour. Ce qui est daté d'aujourd'hui ou avant
     * (dettes en retard) compte dès demain.
     *
     * @param array<string, int> $entrees Y-m-d => montant
     * @param array<string, int> $sorties Y-m-d => montant
     *
     * @return list<array{date: \DateTimeImmutable, solde: int, entrees: int, sorties: int}>
     */
    public static function soldes(int $depart, array $entrees, array $sorties, \DateTimeImmutable $aujourdhui, int $jours): array
    {
        $aujourdhui = $aujourdhui->setTime(0, 0);
        $demain = $aujourdhui->modify('+1 day')->format('Y-m-d');
        $ramener = static function (array $flux) use ($demain): array {
            $ramenes = [];
            foreach ($flux as $jour => $montant) {
                $cle = $jour < $demain ? $demain : $jour;
                $ramenes[$cle] = ($ramenes[$cle] ?? 0) + $montant;
            }

            return $ramenes;
        };
        $entrees = $ramener($entrees);
        $sorties = $ramener($sorties);

        $points = [['date' => $aujourdhui, 'solde' => $depart, 'entrees' => 0, 'sorties' => 0]];
        $solde = $depart;
        for ($j = 1; $j <= $jours; ++$j) {
            $jour = $aujourdhui->modify('+'.$j.' days');
            $cle = $jour->format('Y-m-d');
            $e = $entrees[$cle] ?? 0;
            $s = $sorties[$cle] ?? 0;
            $solde += $e - $s;
            $points[] = ['date' => $jour, 'solde' => $solde, 'entrees' => $e, 'sorties' => $s];
        }

        return $points;
    }

    /**
     * Le solde le plus bas (le premier en cas d'égalité).
     *
     * @param list<array{date: \DateTimeImmutable, solde: int}> $points
     *
     * @return array{date: \DateTimeImmutable, solde: int, index: int}
     */
    public static function pointBas(array $points): array
    {
        $bas = 0;
        foreach ($points as $i => $p) {
            if ($p['solde'] < $points[$bas]['solde']) {
                $bas = $i;
            }
        }

        return ['date' => $points[$bas]['date'], 'solde' => $points[$bas]['solde'], 'index' => $bas];
    }

    /**
     * Premier jour où le solde prévu passe sous le seuil d'alerte.
     *
     * @param list<array{date: \DateTimeImmutable, solde: int}> $points
     *
     * @return array{date: \DateTimeImmutable, solde: int}|null
     */
    public static function premierSousSeuil(array $points, int $seuil): ?array
    {
        foreach ($points as $p) {
            if ($p['solde'] < $seuil) {
                return ['date' => $p['date'], 'solde' => $p['solde']];
            }
        }

        return null;
    }

    /**
     * Les grosses sorties à marquer sur la courbe (« Salaires 05/11 ») :
     * les jours qui pèsent le plus, au moins 5 jours d'écart, par date.
     *
     * @param list<array{date: \DateTimeImmutable, titre: string, montant: ?int, type: string}> $echeances
     *
     * @return list<array{date: \DateTimeImmutable, libelle: string, total: int}>
     */
    public static function reperes(array $echeances, \DateTimeImmutable $aujourdhui, int $nombre, int $minimum): array
    {
        $demain = $aujourdhui->setTime(0, 0)->modify('+1 day');
        $jours = [];
        foreach ($echeances as $e) {
            $date = $e['date'] < $demain ? $demain : $e['date'];
            $cle = $date->format('Y-m-d');
            $jours[$cle] ??= ['date' => $date, 'total' => 0, 'principale' => $e];
            $jours[$cle]['total'] += (int) $e['montant'];
            if ((int) $e['montant'] > (int) $jours[$cle]['principale']['montant']) {
                $jours[$cle]['principale'] = $e;
            }
        }
        $jours = array_values(array_filter($jours, static fn (array $j): bool => $j['total'] >= $minimum));
        usort($jours, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        $retenus = [];
        foreach ($jours as $j) {
            foreach ($retenus as $r) {
                if (abs((int) $r['date']->diff($j['date'])->format('%r%a')) < 5) {
                    continue 2;
                }
            }
            $retenus[] = ['date' => $j['date'], 'libelle' => self::nomRepere($j['principale']).' '.$j['date']->format('d/m'), 'total' => $j['total']];
            if (\count($retenus) >= $nombre) {
                break;
            }
        }
        usort($retenus, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $retenus;
    }

    /**
     * Ce qui fait descendre au point bas : la plus grosse sortie du
     * dernier jour de paiement avant lui (« après les salaires »).
     *
     * @param list<array{date: \DateTimeImmutable, titre: string, montant: ?int, type: string}> $echeances
     */
    public static function cause(array $echeances, \DateTimeImmutable $aujourdhui, \DateTimeImmutable $pointBas): ?string
    {
        $demain = $aujourdhui->setTime(0, 0)->modify('+1 day');
        $retenue = null;
        foreach ($echeances as $e) {
            $date = $e['date'] < $demain ? $demain : $e['date'];
            if ($date > $pointBas) {
                continue;
            }
            if (null === $retenue || $date > $retenue['jour'] || ($date == $retenue['jour'] && (int) $e['montant'] > (int) $retenue['montant'])) {
                $retenue = $e + ['jour' => $date];
            }
        }
        if (null === $retenue) {
            return null;
        }

        return 'après '.match ($retenue['type']) {
            'salaires' => 'les salaires',
            'fournisseur' => 'les paiements fournisseurs',
            'fiscal' => 'les impôts et taxes',
            default => '« '.$retenue['titre'].' »',
        };
    }

    /**
     * Graduations en millions, assez rondes : pas de 1, 2, 2,5 ou 5 × 10ⁿ.
     *
     * @return array{min: int, max: int, pas: int}
     */
    public static function echelle(int $bas, int $haut): array
    {
        $brut = max(1, $haut - $bas) / 4;
        $puissance = 10 ** floor(log10($brut));
        $pas = 10 * $puissance;
        foreach ([1, 2, 2.5, 5] as $m) {
            if ($m * $puissance >= $brut) {
                $pas = $m * $puissance;
                break;
            }
        }
        $pas = (int) max(100000, $pas);

        return ['min' => (int) (floor($bas / $pas) * $pas), 'max' => (int) (ceil($haut / $pas) * $pas), 'pas' => $pas];
    }

    /** « 2 M », « 2,5 M », « −1 M », « 0 ». */
    public static function millions(int $montant): string
    {
        if (0 === $montant) {
            return '0';
        }
        $texte = rtrim(rtrim(number_format(abs($montant) / 1000000, 1, ',', ''), '0'), ',');

        return ($montant < 0 ? '−' : '').$texte.' M';
    }

    /**
     * Tracé de la courbe (viewBox 1000 × 300, étiré à la largeur) et
     * positions en % des graduations, des dates et des repères, posés en
     * HTML pour rester lisibles sur téléphone.
     *
     * @param list<array{date: \DateTimeImmutable, solde: int}>                    $points
     * @param list<array{date: \DateTimeImmutable, libelle: string, total: int}> $reperes
     *
     * @return array<string, mixed>
     */
    public static function trace(array $points, int $seuil, array $reperes = [], int $largeur = 1000, int $hauteur = 300): array
    {
        $soldes = array_column($points, 'solde');
        $echelle = self::echelle(min([...$soldes, $seuil]), max([...$soldes, $seuil]));
        $ecart = max(1, $echelle['max'] - $echelle['min']);
        $n = max(1, \count($points) - 1);
        $y = static fn (int $s): float => round(($echelle['max'] - $s) * 100 / $ecart, 2);
        $x = static fn (int $i): float => round($i * 100 / $n, 2);

        $coords = [];
        $index = [];
        foreach ($points as $i => $p) {
            $coords[] = \sprintf('%.1f,%.1f', $x($i) * $largeur / 100, $y($p['solde']) * $hauteur / 100);
            $index[$p['date']->format('Y-m-d')] = $i;
        }
        $ligne = 'M'.implode(' L', $coords);

        $graduations = [];
        for ($v = $echelle['min']; $v <= $echelle['max']; $v += $echelle['pas']) {
            $graduations[] = ['y' => $y($v), 'libelle' => self::millions($v)];
        }

        // Dates : aujourd'hui, puis les 1er et 15 du mois assez loin de lui.
        $dates = [];
        foreach ($points as $i => $p) {
            $jour = (int) $p['date']->format('j');
            if (0 === $i || ($i >= 5 && (1 === $jour || 15 === $jour))) {
                $dates[] = ['x' => $x($i), 'libelle' => $p['date']->format('d/m')];
            }
        }

        $marques = [];
        foreach ($reperes as $r) {
            $i = $index[$r['date']->format('Y-m-d')] ?? null;
            if (null !== $i) {
                $marques[] = $r + ['x' => $x($i), 'y' => $y($points[$i]['solde'])];
            }
        }
        $bas = self::pointBas($points);

        return [
            'ligne' => $ligne,
            'aire' => $ligne.\sprintf(' L%d,%d L0,%d Z', $largeur, $hauteur, $hauteur),
            'largeur' => $largeur,
            'hauteur' => $hauteur,
            'seuil_y' => $y($seuil),
            'seuil_visible' => $seuil >= $echelle['min'] && $seuil <= $echelle['max'],
            'graduations' => $graduations,
            'dates' => $dates,
            'reperes' => $marques,
            'depart' => ['x' => 0, 'y' => $y($points[0]['solde'])],
            'bas' => ['x' => $x($bas['index']), 'y' => $y($bas['solde'])],
        ];
    }

    /**
     * « Salaires », « Fournisseurs », « Taxes », sinon le premier mot : « Loyer ».
     *
     * @param array{type: string, titre: string} $echeance
     */
    private static function nomRepere(array $echeance): string
    {
        $mot = explode(' ', trim($echeance['titre']))[0];

        return match ($echeance['type']) {
            'salaires' => 'Salaires',
            'fournisseur' => 'Fournisseurs',
            'fiscal' => 'Taxes',
            default => mb_strtoupper(mb_substr($mot, 0, 1)).mb_substr($mot, 1),
        };
    }

    /**
     * Moyenne par jour des ventes payées au comptoir : paiements validés
     * des 90 derniers jours reçus au plus 3 jours après la commande. Les
     * paiements plus tardifs soldent des créances, déjà comptées.
     */
    private function ventesComptoirParJour(\DateTimeImmutable $aujourdhui): float
    {
        $total = (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(p.montant), 0)')
            ->from(Paiements::class, 'p')
            ->join('p.commande', 'c')
            ->andWhere('p.statut = :valide')
            ->andWhere('p.mode NOT IN (:solde)')
            ->andWhere('p.date >= :debut AND p.date < :fin')
            ->andWhere('DATE_DIFF(p.date, c.dateCommande) <= :jours')
            ->setParameter('valide', Paiements::STATUT_VALIDE)
            ->setParameter('solde', Paiements::MODES_SANS_ENCAISSEMENT)
            ->setParameter('debut', $aujourdhui->modify('-'.self::JOURS_HISTORIQUE.' days'))
            ->setParameter('fin', $aujourdhui)
            ->setParameter('jours', self::JOURS_COMPTOIR)
            ->getQuery()
            ->getSingleScalarResult();

        return $total / self::JOURS_HISTORIQUE;
    }

    /**
     * Moyenne par jour des dépenses courantes validées des 90 derniers
     * jours. Les factures fournisseurs à échéance (dépenses avec une
     * échéance) sont déjà dans l'échéancier : elles ne comptent pas.
     */
    private function depensesCourantesParJour(\DateTimeImmutable $aujourdhui): float
    {
        $total = (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(m.montant), 0)')
            ->from(MouvementTresorerie::class, 'm')
            ->leftJoin(Depense::class, 'depense', 'WITH', 'depense.mouvement = m AND depense.echeance IS NOT NULL')
            ->andWhere('m.statut = :valide')
            ->andWhere('m.type = :sortie')
            ->andWhere('m.categorie IN (:courantes)')
            ->andWhere('m.dateOperation >= :debut AND m.dateOperation < :fin')
            ->andWhere('depense.id IS NULL')
            ->setParameter('valide', MouvementTresorerie::STATUT_VALIDE)
            ->setParameter('sortie', MouvementTresorerie::TYPE_DECAISSEMENT)
            ->setParameter('courantes', self::CATEGORIES_COURANTES)
            ->setParameter('debut', $aujourdhui->modify('-'.self::JOURS_HISTORIQUE.' days'))
            ->setParameter('fin', $aujourdhui)
            ->getQuery()
            ->getSingleScalarResult();

        return $total / self::JOURS_HISTORIQUE;
    }

    /**
     * Retard moyen de paiement de chaque client, sur ses commandes de
     * l'année écoulée entièrement payées.
     *
     * @param list<int> $clients
     *
     * @return array<int, int> client => jours
     */
    private function retardsClients(array $clients, \DateTimeImmutable $aujourdhui): array
    {
        if ([] === $clients) {
            return [];
        }
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(c.clients) AS client', 'c.dateCommande', 'c.dateLivraison', 'c.totalTtc', 'MAX(p.date) AS dernier', 'SUM(p.montant) AS paye')
            ->from(Paiements::class, 'p')
            ->join('p.commande', 'c')
            ->andWhere('c.clients IN (:clients)')
            ->andWhere('p.statut = :valide')
            ->andWhere('c.dateCommande >= :depuis')
            ->setParameter('clients', $clients)
            ->setParameter('valide', Paiements::STATUT_VALIDE)
            ->setParameter('depuis', $aujourdhui->modify('-1 year'))
            ->groupBy('c.id')
            ->addGroupBy('c.clients')
            ->addGroupBy('c.dateCommande')
            ->addGroupBy('c.dateLivraison')
            ->addGroupBy('c.totalTtc')
            ->getQuery()
            ->getArrayResult();

        $retards = [];
        foreach ($lignes as $l) {
            $echeance = $l['dateLivraison'] ?? $l['dateCommande'];
            if ((int) $l['paye'] < (int) $l['totalTtc'] || null === $echeance || null === $l['dernier']) {
                continue;
            }
            $dernier = new \DateTimeImmutable((string) $l['dernier']);
            $retards[(int) $l['client']][] = (int) \DateTimeImmutable::createFromInterface($echeance)->setTime(0, 0)->diff($dernier->setTime(0, 0))->format('%r%a');
        }

        return array_filter(array_map(self::retardMoyen(...), $retards), static fn (?int $r): bool => null !== $r);
    }
}
