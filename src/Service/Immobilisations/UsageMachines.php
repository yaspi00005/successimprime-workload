<?php

namespace App\Service\Immobilisations;

use App\Entity\Format;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\ReleveCompteur;
use App\Repository\ReleveCompteurRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Usage des machines mois par mois (M01, M02), dans l'unité de chacune :
 * m², mètres de film, pages A4-équivalent, cartes ou transferts.
 *
 * L'usage cumulé à la fin d'un mois vient du relevé de compteurs de ce
 * mois quand il existe (le compteur de la machine fait foi). Sinon, il
 * se déduit du compteur actuel moins la production terminée depuis
 * (ordres de production terminés sur la machine, convertis dans son
 * unité). Enregistrer un relevé recale le compteur de la machine.
 */
final class UsageMachines
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReleveCompteurRepository $releves,
    ) {
    }

    /**
     * Suivi de chaque machine depuis $depuis : relevés, production par
     * mois et compteur actuel, par id de machine.
     *
     * @param list<Machines> $machines
     *
     * @return array<int, array{machine: Machines, releves: array<string, ReleveCompteur>, production: array<string, float>, compteur: float}>
     */
    public function suivi(array $machines, \DateTimeImmutable $depuis): array
    {
        $releves = $this->releves->deMachines($machines);
        $production = $this->productionParMois($machines, $depuis);
        $suivi = [];
        foreach ($machines as $m) {
            $id = (int) $m->getId();
            $suivi[$id] = [
                'machine' => $m,
                'releves' => $releves[$id] ?? [],
                'production' => $production[$id] ?? [],
                'compteur' => $m->usage(),
            ];
        }

        return $suivi;
    }

    /**
     * Usage produit par mois : ordres terminés sur la machine, surface ×
     * quantité (ou quantité seule pour les cartes et transferts).
     *
     * @param list<Machines> $machines
     *
     * @return array<int, array<string, float>>
     */
    public function productionParMois(array $machines, \DateTimeImmutable $depuis): array
    {
        if ([] === $machines) {
            return [];
        }
        $parId = [];
        foreach ($machines as $m) {
            $parId[(int) $m->getId()] = $m;
        }
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(o.machine) AS machine', 'o.termineLe AS le', 'o.quantiteProduite + o.quantiteRebut AS quantite', 'd.largeur AS largeur', 'd.longueur AS longueur',
                'f.largeur AS formatLargeur', 'f.hauteur AS formatHauteur', 'f.unite AS formatUnite')
            ->from(OrdreProduction::class, 'o')
            ->leftJoin('o.commandeDetail', 'd')
            ->leftJoin('d.format', 'f')
            ->andWhere('o.machine IN (:machines)')
            ->andWhere('o.statut = :termine')
            ->andWhere('o.termineLe >= :depuis')
            ->setParameter('machines', array_keys($parId))
            ->setParameter('termine', OrdreProduction::STATUT_TERMINE)
            ->setParameter('depuis', $depuis)
            ->getQuery()->getArrayResult();

        $parMois = [];
        foreach ($lignes as $l) {
            $machine = $parId[(int) $l['machine']] ?? null;
            if (null === $machine || !$l['le'] instanceof \DateTimeInterface) {
                continue;
            }
            $quantite = (float) $l['quantite'];
            $surface = self::surfacePiece($l['largeur'], $l['longueur'], $l['formatLargeur'], $l['formatHauteur'], $l['formatUnite']) * $quantite;
            $usage = $machine->usagePour($surface, $quantite);
            $cle = $l['le']->format('Y-m');
            $parMois[(int) $l['machine']][$cle] = ($parMois[(int) $l['machine']][$cle] ?? 0.0) + $usage;
        }

        return $parMois;
    }

    /**
     * Surface d'une pièce (m²) : les dimensions de la ligne de commande, à
     * défaut celles de son format (flyer A5, carte 85 × 55…). Même règle
     * que le compteur de la machine à la fin d'un ordre (ProductionController).
     */
    public static function surfacePiece(mixed $largeur, mixed $longueur, mixed $formatLargeur = null, mixed $formatHauteur = null, ?string $formatUnite = null): float
    {
        $surface = (float) ($largeur ?? 0) * (float) ($longueur ?? 0);

        return $surface > 0 ? $surface : Format::surfaceDe($formatLargeur, $formatHauteur, $formatUnite);
    }

    /**
     * Usage cumulé à la fin du mois « AAAA-MM » : le relevé du mois, ou le
     * compteur actuel moins la production des mois suivants.
     *
     * @param array{releves: array<string, ReleveCompteur>, production: array<string, float>, compteur: float} $suivi
     */
    public static function cumulFin(array $suivi, string $mois): float
    {
        $releve = $suivi['releves'][$mois] ?? null;
        if (null !== $releve && null !== $releve->equivalent()) {
            return $releve->equivalent();
        }
        $apres = 0.0;
        foreach ($suivi['production'] as $m => $usage) {
            if ($m > $mois) {
                $apres += $usage;
            }
        }

        return max(0.0, $suivi['compteur'] - $apres);
    }

    /**
     * Usage du mois : cumul de fin de mois moins celui du mois précédent.
     *
     * @param array{releves: array<string, ReleveCompteur>, production: array<string, float>, compteur: float} $suivi
     */
    public static function duMois(array $suivi, \DateTimeImmutable $mois): float
    {
        $cle = $mois->format('Y-m');
        $precedent = $mois->modify('first day of this month')->modify('-1 month')->format('Y-m');

        return max(0.0, self::cumulFin($suivi, $cle) - self::cumulFin($suivi, $precedent));
    }

    /** La machine se suit par relevé mensuel de compteurs. */
    public static function suitParReleve(Machines $m): bool
    {
        return null !== $m->getReleveCompteurs();
    }

    /**
     * Pages du mois par compteur (écart avec le relevé précédent), pour une
     * machine à compteurs A4 / A3.
     *
     * @param array<string, ReleveCompteur> $releves
     *
     * @return array<string, ?int>|null null sans relevé ce mois-ci
     */
    public static function pagesDuMois(array $releves, \DateTimeImmutable $mois): ?array
    {
        $releve = $releves[$mois->format('Y-m')] ?? null;
        if (null === $releve) {
            return null;
        }
        $avant = $releves[$mois->modify('first day of this month')->modify('-1 month')->format('Y-m')] ?? null;
        $pages = [];
        foreach (array_keys(ReleveCompteur::COMPTEURS) as $cle) {
            $pages[$cle] = null !== $avant && null !== $releve->valeur($cle) && null !== $avant->valeur($cle) ? max(0, (int) $releve->valeur($cle) - (int) $avant->valeur($cle)) : null;
        }

        return $pages;
    }

    /**
     * Enregistre le relevé d'un mois. Si c'est le plus récent, le compteur
     * de la machine est recalé : relevé + production terminée depuis.
     *
     * @param array<string, ?int>|null $a4a3 compteurs A4 / A3, ou null pour un compteur unique
     */
    public function enregistrerReleve(Machines $machine, \DateTimeImmutable $mois, ?array $a4a3, ?float $compteur, ?string $photo, ?\App\Entity\User $par, \DateTimeImmutable $maintenant): ReleveCompteur
    {
        $mois = CalculAmortissement::premierDuMois($mois);
        $releves = $this->releves->deMachine($machine);
        $releve = $releves[$mois->format('Y-m')] ?? new ReleveCompteur($machine, $mois);
        if (null !== $a4a3) {
            $releve->saisirA4A3($a4a3);
        } else {
            $releve->saisirCompteur($compteur);
        }
        if (null !== $photo) {
            $releve->setPhoto($photo);
        }
        $releve->signer($par, $maintenant);
        $this->em->persist($releve);

        $plusRecent = true;
        foreach (array_keys($releves) as $cle) {
            $plusRecent = $plusRecent && $cle <= $mois->format('Y-m');
        }
        if ($plusRecent && null !== $releve->equivalent()) {
            $depuis = $mois->modify('+1 month');
            $production = array_sum($this->productionParMois([$machine], $depuis)[(int) $machine->getId()] ?? []);
            $machine->recalerCompteur($releve->equivalent() + $production);
        }
        $this->em->flush();

        return $releve;
    }
}
