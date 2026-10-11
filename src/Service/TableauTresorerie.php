<?php

namespace App\Service;

use App\Entity\ClotureCaisse;
use App\Entity\CompteTresorerie;
use App\Entity\DecaissementRecurrent;
use App\Entity\MouvementTresorerie;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Trésorerie et dépenses (maquette 31) : soldes par type de compte,
 * prévision à 30 jours (créances qui arrivent à échéance moins charges
 * récurrentes), courbe sur 90 jours et dépenses du mois par catégorie.
 *
 * Réservé à l'administration : il montre tous les comptes, y compris
 * les mouvements confidentiels (salaires).
 */
final class TableauTresorerie
{
    public const JOURS_PASSES = 60;
    public const JOURS_PREVUS = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Creances $creances,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function donnees(\DateTimeImmutable $maintenant): array
    {
        $aujourdhui = $maintenant->setTime(0, 0);
        /** @var list<CompteTresorerie> $comptes */
        $comptes = $this->em->getRepository(CompteTresorerie::class)->findBy(['actif' => true], ['nom' => 'ASC']);

        $groupes = [
            'banque' => ['libelle' => 'Banque', 'solde' => 0, 'comptes' => 0],
            'mobile' => ['libelle' => 'Orange Money et Wave', 'solde' => 0, 'comptes' => 0],
            'caisse' => ['libelle' => 'Caisses', 'solde' => 0, 'comptes' => 0],
        ];
        $caisses = [];
        foreach ($comptes as $compte) {
            $cle = match ($compte->getType()) {
                CompteTresorerie::TYPE_BANQUE => 'banque',
                CompteTresorerie::TYPE_CAISSE => 'caisse',
                default => 'mobile',
            };
            $groupes[$cle]['solde'] += $compte->getSoldeActuel();
            ++$groupes[$cle]['comptes'];
            if ('caisse' === $cle) {
                $caisses[] = $compte;
            }
        }

        $aRapprocher = (int) $this->em->createQueryBuilder()
            ->select('COUNT(m.id)')
            ->from(MouvementTresorerie::class, 'm')
            ->leftJoin('m.compteSource', 'source')
            ->leftJoin('m.compteDestination', 'destination')
            ->andWhere('m.statut = :attente')
            ->andWhere('source.type = :banque OR destination.type = :banque')
            ->setParameter('attente', MouvementTresorerie::STATUT_EN_ATTENTE)
            ->setParameter('banque', CompteTresorerie::TYPE_BANQUE)
            ->getQuery()
            ->getSingleScalarResult();

        $cloturees = [] === $caisses ? 0 : (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT IDENTITY(c.compte))')
            ->from(ClotureCaisse::class, 'c')
            ->andWhere('c.compte IN (:caisses)')
            ->andWhere('c.clotureLe >= :jour')
            ->setParameter('caisses', $caisses)
            ->setParameter('jour', $aujourdhui)
            ->getQuery()
            ->getSingleScalarResult();

        $total = array_sum(array_column($groupes, 'solde'));
        $entrees = $this->encaissementsAttendus($maintenant);
        $charges = $this->chargesPrevues($aujourdhui);

        return [
            'groupes' => $groupes,
            'a_rapprocher' => $aRapprocher,
            'caisses_cloturees' => $cloturees,
            'nb_caisses' => \count($caisses),
            'total' => $total,
            'prevision' => [
                'entrees' => array_sum($entrees),
                'charges' => array_sum(array_column($charges, 'montant')),
            ],
            'charges' => $charges,
            'courbe' => self::courbe($total, $this->fluxPasses($aujourdhui), $entrees, $charges, $aujourdhui),
            'depenses' => $this->depensesDuMois($maintenant),
        ];
    }

    /**
     * Solde total jour par jour : reconstitué en arrière depuis le solde
     * actuel avec les mouvements validés, puis projeté en avant.
     *
     * @param array<string, int> $fluxPasses  Y-m-d => entrées − sorties du jour
     * @param array<string, int> $entrees     Y-m-d => encaissements attendus
     * @param list<array{date: \DateTimeImmutable, montant: int, libelle: string}> $charges
     *
     * @return list<array{date: \DateTimeImmutable, solde: int, prevu: bool}>
     */
    public static function courbe(int $soldeActuel, array $fluxPasses, array $entrees, array $charges, \DateTimeImmutable $aujourdhui): array
    {
        $points = [];
        $solde = $soldeActuel;
        for ($j = 0; $j <= self::JOURS_PASSES; ++$j) {
            $jour = $aujourdhui->modify('-'.$j.' days');
            $points[] = ['date' => $jour, 'solde' => $solde, 'prevu' => false];
            // Solde de la veille au soir : on retire les flux de ce jour.
            $solde -= $fluxPasses[$jour->format('Y-m-d')] ?? 0;
        }
        $points = array_reverse($points);

        $sorties = [];
        foreach ($charges as $charge) {
            $cle = $charge['date']->format('Y-m-d');
            $sorties[$cle] = ($sorties[$cle] ?? 0) + $charge['montant'];
        }
        $solde = $soldeActuel;
        for ($j = 1; $j <= self::JOURS_PREVUS; ++$j) {
            $jour = $aujourdhui->modify('+'.$j.' days');
            $cle = $jour->format('Y-m-d');
            $solde += ($entrees[$cle] ?? 0) - ($sorties[$cle] ?? 0);
            $points[] = ['date' => $jour, 'solde' => $solde, 'prevu' => true];
        }

        return $points;
    }

    /**
     * Tracé SVG de la courbe : chemins réel et prévu, aire, repère du jour.
     *
     * @param list<array{date: \DateTimeImmutable, solde: int, prevu: bool}> $points
     *
     * @return array{reel: string, prevu: string, aire: string, x_aujourdhui: float, min: int, max: int}
     */
    public static function trace(array $points, int $largeur, int $hauteur): array
    {
        $soldes = array_column($points, 'solde');
        $min = min([0, ...$soldes]);
        $max = max([1, ...$soldes]);
        $ecart = max(1, $max - $min);
        $n = max(1, \count($points) - 1);
        $coord = static fn (int $i, int $s): string => sprintf('%.1f,%.1f', $i * $largeur / $n, $hauteur - ($s - $min) * $hauteur / $ecart);

        $reel = [];
        $prevu = [];
        $xAujourdhui = 0.0;
        foreach ($points as $i => $p) {
            if ($p['prevu']) {
                $prevu[] = $coord($i, $p['solde']);
            } else {
                $reel[] = $coord($i, $p['solde']);
                $xAujourdhui = $i * $largeur / $n;
            }
        }
        // La prévision part du point d'aujourd'hui.
        if ([] !== $reel && [] !== $prevu) {
            array_unshift($prevu, end($reel));
        }
        $tous = [...$reel, ...\array_slice($prevu, 1)];

        return [
            'reel' => 'M'.implode(' L', $reel),
            'prevu' => [] === $prevu ? '' : 'M'.implode(' L', $prevu),
            'aire' => 'M0,'.$hauteur.' L'.implode(' L', $tous).' L'.$largeur.','.$hauteur.' Z',
            'x_aujourdhui' => round($xAujourdhui, 1),
            'min' => $min,
            'max' => $max,
        ];
    }

    /**
     * Prochaines dates d'une charge récurrente jusqu'à l'horizon.
     *
     * @return list<\DateTimeImmutable>
     */
    public static function echeancesRecurrentes(?\DateTimeImmutable $prochaine, string $frequence, \DateTimeImmutable $depuis, \DateTimeImmutable $jusqua): array
    {
        if (null === $prochaine) {
            return [];
        }
        $pas = DecaissementRecurrent::FREQUENCE_ANNUELLE === $frequence ? '+1 year' : '+1 month';
        $dates = [];
        for ($date = $prochaine->setTime(0, 0); $date <= $jusqua; $date = $date->modify($pas)) {
            if ($date >= $depuis) {
                $dates[] = $date;
            }
            if (\count($dates) > 40) {
                break;
            }
        }

        return $dates;
    }

    /** @return array<string, int> */
    private function fluxPasses(\DateTimeImmutable $aujourdhui): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('m.type', 'm.montant', 'm.dateOperation')
            ->from(MouvementTresorerie::class, 'm')
            ->andWhere('m.statut = :valide')
            ->andWhere('m.dateOperation >= :debut')
            ->andWhere('m.type != :transfert')
            ->setParameter('valide', MouvementTresorerie::STATUT_VALIDE)
            ->setParameter('debut', $aujourdhui->modify('-'.self::JOURS_PASSES.' days'))
            ->setParameter('transfert', MouvementTresorerie::TYPE_TRANSFERT)
            ->getQuery()
            ->getArrayResult();
        $flux = [];
        foreach ($lignes as $l) {
            $cle = $l['dateOperation']->format('Y-m-d');
            $flux[$cle] = ($flux[$cle] ?? 0) + (MouvementTresorerie::TYPE_ENCAISSEMENT === $l['type'] ? 1 : -1) * (int) $l['montant'];
        }

        return $flux;
    }

    /**
     * Restes dus attendus d'ici 30 jours, au jour de leur échéance.
     *
     * @return array<string, int>
     */
    private function encaissementsAttendus(\DateTimeImmutable $maintenant): array
    {
        $entrees = [];
        foreach ($this->creances->donnees($maintenant)['lignes'] as $ligne) {
            $jours = -$ligne['jours'];
            if ($jours >= 1 && $jours <= self::JOURS_PREVUS && null !== $ligne['echeance']) {
                $cle = $ligne['echeance']->format('Y-m-d');
                $entrees[$cle] = ($entrees[$cle] ?? 0) + $ligne['reste'];
            }
        }

        return $entrees;
    }

    /** @return list<array{date: \DateTimeImmutable, montant: int, libelle: string}> */
    private function chargesPrevues(\DateTimeImmutable $aujourdhui): array
    {
        $charges = [];
        $horizon = $aujourdhui->modify('+'.self::JOURS_PREVUS.' days');
        foreach ($this->em->getRepository(DecaissementRecurrent::class)->findBy(['actif' => true]) as $recurrent) {
            foreach (self::echeancesRecurrentes($recurrent->getProchaineDateExecution(), $recurrent->getFrequence(), $aujourdhui->modify('+1 day'), $horizon) as $date) {
                $charges[] = ['date' => $date, 'montant' => $recurrent->getMontant(), 'libelle' => $recurrent->getLibelle()];
            }
        }
        usort($charges, static fn (array $a, array $b): int => $a['date'] <=> $b['date']);

        return $charges;
    }

    /** @return list<array{categorie: string, libelle: string, montant: int}> */
    private function depensesDuMois(\DateTimeImmutable $maintenant): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('m.categorie', 'SUM(m.montant) AS total')
            ->from(MouvementTresorerie::class, 'm')
            ->andWhere('m.statut = :valide')
            ->andWhere('m.type = :sortie')
            ->andWhere('m.dateOperation >= :debut')
            ->setParameter('valide', MouvementTresorerie::STATUT_VALIDE)
            ->setParameter('sortie', MouvementTresorerie::TYPE_DECAISSEMENT)
            ->setParameter('debut', $maintenant->modify('first day of this month')->setTime(0, 0))
            ->groupBy('m.categorie')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $l): array => [
            'categorie' => (string) $l['categorie'],
            'libelle' => MouvementTresorerie::CATEGORIES_LABELS[$l['categorie']] ?? (string) $l['categorie'],
            'montant' => (int) $l['total'],
        ], $lignes);
    }
}
