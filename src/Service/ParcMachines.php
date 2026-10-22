<?php

namespace App\Service;

use App\Entity\CommandesDetails;
use App\Entity\Machines;
use App\Entity\Maintenance;
use App\Entity\OrdreProduction;
use App\Entity\StockSorties;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Machines et maintenance (maquette 27) : état de chaque machine,
 * m² imprimés du mois, encre consommée au m², prochaine maintenance,
 * interventions et temps d'arrêt sur 30 jours.
 */
final class ParcMachines
{
    public const ETATS = [
        'en_marche' => ['En marche', 'vert'],
        'pause' => ['Pause', 'orange'],
        'maintenance' => ['Maintenance', 'rouge'],
        'disponible' => ['Disponible', 'gris'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function donnees(\DateTimeImmutable $maintenant): array
    {
        $machines = $this->em->getRepository(Machines::class)->findBy([], ['nom' => 'ASC']);
        $debutMois = $maintenant->modify('first day of this month')->setTime(0, 0);
        $metres = $this->metresDuMois($debutMois);
        $encre = $this->encreDuMois($debutMois);
        $ordres = $this->ordresActifs();
        $interventions = $this->em->getRepository(Maintenance::class)->findBy([], ['date' => 'DESC'], 30);
        $depuis30 = $maintenant->modify('-30 days');

        $cartes = [];
        $arrets = [];
        foreach ($machines as $machine) {
            $id = (int) $machine->getId();
            $m2 = $metres[$id] ?? 0.0;
            $prochaine = null;
            $enMaintenance = false;
            $arrets[$id] = ['machine' => $machine, 'minutes' => 0, 'couleur' => TableauProduction::couleur($machine)];
            foreach ($machine->getMaintenances() as $maintenance) {
                $date = \DateTimeImmutable::createFromInterface($maintenance->getDate());
                $fin = $date->modify('+'.($maintenance->getDureeArretMinutes() ?? 60).' minutes');
                if ($date <= $maintenant && $fin > $maintenant) {
                    $enMaintenance = true;
                }
                if ($date > $maintenant && (null === $prochaine || $date < $prochaine)) {
                    $prochaine = $date;
                }
                if ($date >= $depuis30 && $date <= $maintenant) {
                    $arrets[$id]['minutes'] += $maintenance->getDureeArretMinutes() ?? 0;
                }
            }
            $ordre = $ordres[$id] ?? null;
            $etat = match (true) {
                $enMaintenance => 'maintenance',
                OrdreProduction::STATUT_EN_COURS === $ordre?->getStatut() => 'en_marche',
                OrdreProduction::STATUT_EN_PAUSE === $ordre?->getStatut() => 'pause',
                default => 'disponible',
            };

            $cartes[] = [
                'machine' => $machine,
                'etat' => $etat,
                'ordre' => $ordre,
                'm2' => $m2,
                'encreMlM2' => $m2 >= 1 && isset($encre[$id]) ? round($encre[$id] * 1000 / $m2, 1) : null,
                'prochaine' => $prochaine,
                'couleur' => TableauProduction::couleur($machine),
            ];
        }

        $maxArret = max([1, ...array_column($arrets, 'minutes')]);

        return [
            'cartes' => $cartes,
            'interventions' => $interventions,
            'arrets' => array_values($arrets),
            'max_arret' => $maxArret,
            'maintenant' => $maintenant,
        ];
    }

    /** « dans 3 jours », « demain 8 h », « aujourd'hui 15 h ». */
    public static function quand(\DateTimeImmutable $date, \DateTimeImmutable $maintenant): string
    {
        $jours = (int) $maintenant->setTime(0, 0)->diff($date->setTime(0, 0))->format('%r%a');
        $heure = '00:00' === $date->format('H:i') ? '' : ' '.$date->format('G').' h'.('00' === $date->format('i') ? '' : ' '.$date->format('i'));

        return match (true) {
            0 === $jours => 'Aujourd’hui'.$heure,
            1 === $jours => 'Demain'.$heure,
            $jours < 14 => sprintf('Dans %d jours', $jours),
            default => 'Le '.$date->format('d/m'),
        };
    }

    public static function duree(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return intdiv($minutes, 60).' h'.(0 === $minutes % 60 ? '' : ' '.str_pad((string) ($minutes % 60), 2, '0', \STR_PAD_LEFT));
    }

    /** @return array<int, float> */
    private function metresDuMois(\DateTimeImmutable $debut): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(detail.machine) AS machine', 'detail.surface', 'detail.quantite')
            ->from(CommandesDetails::class, 'detail')
            ->andWhere('detail.machine IS NOT NULL')
            ->andWhere('detail.productionTermineeLe >= :debut')
            ->setParameter('debut', $debut)
            ->getQuery()
            ->getArrayResult();
        $totaux = [];
        foreach ($lignes as $ligne) {
            $totaux[(int) $ligne['machine']] = ($totaux[(int) $ligne['machine']] ?? 0.0) + (float) $ligne['surface'] * (int) $ligne['quantite'];
        }

        return $totaux;
    }

    /**
     * Encre sortie du stock ce mois (en litres) pour les articles rattachés
     * à une machine.
     *
     * @return array<int, float>
     */
    private function encreDuMois(\DateTimeImmutable $debut): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(article.machine) AS machine', 'COALESCE(SUM(sortie.quantite), 0) AS total')
            ->from(StockSorties::class, 'sortie')
            ->innerJoin('sortie.article', 'article')
            ->andWhere('article.machine IS NOT NULL')
            ->andWhere('article.unite = :litre')
            ->andWhere('sortie.date >= :debut')
            ->setParameter('litre', 'litre')
            ->setParameter('debut', $debut)
            ->groupBy('article.machine')
            ->getQuery()
            ->getArrayResult();

        return array_column(array_map(static fn (array $l): array => [(int) $l['machine'], (float) $l['total']], $lignes), 1, 0);
    }

    /** @return array<int, OrdreProduction> machine => ordre en cours (sinon en pause) */
    private function ordresActifs(): array
    {
        /** @var list<OrdreProduction> $ordres */
        $ordres = $this->em->createQueryBuilder()
            ->select('ordre', 'detail')
            ->from(OrdreProduction::class, 'ordre')
            ->leftJoin('ordre.commandeDetail', 'detail')
            ->andWhere('ordre.machine IS NOT NULL')
            ->andWhere('ordre.statut IN (:statuts)')
            ->setParameter('statuts', [OrdreProduction::STATUT_EN_COURS, OrdreProduction::STATUT_EN_PAUSE])
            ->getQuery()
            ->getResult();
        $parMachine = [];
        foreach ($ordres as $ordre) {
            $id = (int) $ordre->getMachine()?->getId();
            if (!isset($parMachine[$id]) || OrdreProduction::STATUT_EN_COURS === $ordre->getStatut()) {
                $parMachine[$id] = $ordre;
            }
        }

        return $parMachine;
    }
}
