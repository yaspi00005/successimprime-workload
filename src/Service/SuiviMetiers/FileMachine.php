<?php

namespace App\Service\SuiviMetiers;

use App\Entity\JournalMachine;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\User;
use App\Repository\JournalMachineRepository;
use App\Service\Parametres\Parametres;
use App\Service\TableauProduction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * File d'une machine et travail en cours (maquette W03) : les ordres de
 * production du jour dans l'ordre, les m² produits, la gâche, l'entretien
 * des têtes et les points du barème « Opérateur production ».
 */
final class FileMachine
{
    /** Heure limite de l'entretien des têtes pour le bonus. */
    public const HEURE_TETES = '08:30';
    /** Seuil de gâche du jour pour le bonus (%). */
    public const SEUIL_GACHE = 3.0;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly JournalMachineRepository $journal,
        private readonly Parametres $parametres,
    ) {
    }

    /** m² d'un ordre : largeur × longueur × quantité de la ligne. */
    public static function m2(OrdreProduction $ordre): ?float
    {
        $ligne = $ordre->getCommandeDetail();
        $largeur = (float) ($ligne?->getLargeur() ?? 0);
        $longueur = (float) ($ligne?->getLongueur() ?? 0);
        if ($largeur <= 0 || $longueur <= 0) {
            return null;
        }

        return round($largeur * $longueur * ($ordre->getQuantite() ?? $ligne->getQuantite()), 2);
    }

    /**
     * Points d'un ordre terminé : sorti avant l'heure prévue, plus 1 point
     * par 10 m² produits.
     *
     * @return array<string, int|float>
     */
    public static function faitsOrdre(OrdreProduction $ordre): array
    {
        $faits = [];
        $prevue = $ordre->getFinPrevueLe() ?? $ordre->getDateLimite();
        if ($ordre->getTermineLe() !== null && $prevue !== null && $ordre->getTermineLe() <= $prevue) {
            $faits['p_avant_heure'] = 1;
        }
        $m2 = self::m2($ordre);
        if ($m2 !== null && $m2 >= 10) {
            $faits['p_10m2'] = floor($m2 / 10);
        }

        return $faits;
    }

    /**
     * La journée d'une machine.
     *
     * @return array<string, mixed>
     */
    public function journee(Machines $machine, \DateTimeImmutable $maintenant): array
    {
        $jour = $maintenant->setTime(0, 0);
        /** @var list<OrdreProduction> $ordres */
        $ordres = $this->em->createQueryBuilder()
            ->select('o', 'd', 'c', 'cli')->from(OrdreProduction::class, 'o')
            ->join('o.commandeDetail', 'd')->join('d.commande', 'c')->leftJoin('c.clients', 'cli')
            ->where('o.machine = :m')
            ->andWhere('(o.statut IN (:actifs)) OR (o.statut = :termine AND o.termineLe >= :jour)')
            ->setParameter('m', $machine)
            ->setParameter('actifs', [OrdreProduction::STATUT_A_PRODUIRE, OrdreProduction::STATUT_EN_COURS, OrdreProduction::STATUT_EN_PAUSE])
            ->setParameter('termine', OrdreProduction::STATUT_TERMINE)
            ->setParameter('jour', $jour)
            ->getQuery()->getResult();

        $rang = static fn (OrdreProduction $o): int => match ($o->getStatut()) {
            OrdreProduction::STATUT_TERMINE => 0,
            OrdreProduction::STATUT_EN_COURS, OrdreProduction::STATUT_EN_PAUSE => 1,
            default => 2,
        };
        usort($ordres, static fn (OrdreProduction $a, OrdreProduction $b): int => [$rang($a), $a->getTermineLe() ?? $a->getFinPrevueLe() ?? $a->getDateLimite() ?? $a->getCreeLe()] <=> [$rang($b), $b->getTermineLe() ?? $b->getFinPrevueLe() ?? $b->getDateLimite() ?? $b->getCreeLe()]);

        $bareme = $this->parametres->bareme();
        $cartes = [];
        $total = 0.0;
        $produit = 0.0;
        $points = 0;
        foreach ($ordres as $o) {
            $m2 = self::m2($o) ?? 0.0;
            $total += $m2;
            $pts = null;
            if ($o->estTermine()) {
                $produit += $m2;
                $pts = $bareme->points(self::faitsOrdre($o));
                $points += $pts;
            }
            $cartes[] = ['ordre' => $o, 'titre' => TableauProduction::titre($o->getCommandeDetail()), 'numero' => $o->getCommandeDetail()?->getCommande()?->getNumero(), 'client' => $o->getCommandeDetail()?->getCommande()?->getClients()?->getNomComplet(), 'm2' => $m2, 'points' => $pts];
        }

        $evenements = $this->journal->duJour($machine, $maintenant);
        $gache = array_sum(array_map(static fn (JournalMachine $j): float => $j->getType() === JournalMachine::GACHE ? (float) $j->getQuantite() : 0.0, $evenements));
        $tetes = null;
        foreach ($evenements as $j) {
            if ($j->getType() === JournalMachine::TETES) {
                $tetes ??= $j;
            }
            if ($j->getType() === JournalMachine::REIMPRESSION) {
                $points += $bareme->points(['p_reimpression' => 1]);
            }
        }
        if ($tetes !== null && $tetes->getLe()->format('H:i') <= self::HEURE_TETES) {
            $points += $bareme->points(['p_tetes' => 1]);
        }
        $tauxGache = $produit > 0 ? round($gache * 100 / ($produit + $gache), 1) : null;
        if ($tauxGache !== null && $tauxGache < self::SEUIL_GACHE) {
            $points += $bareme->points(['p_gache' => 1]);
        }

        return [
            'cartes' => $cartes,
            'produit' => round($produit, 1),
            'total' => round($total, 1),
            'gache' => round($gache, 1),
            'tauxGache' => $tauxGache,
            'tetes' => $tetes,
            'points' => $points,
            'evenements' => $evenements,
        ];
    }

    /**
     * Progression estimée d'un ordre en cours d'après sa durée prévue. Une
     * pièce à refaire (contrôle final, PD06) compte son propre passage :
     * depuis son redémarrage, pour la part de la durée de ses pièces.
     *
     * @return array{secondes: int, m2: ?float, fait: ?float, finEstimee: ?\DateTimeImmutable, debut: ?\DateTimeImmutable, duree: ?int}
     */
    public static function progression(OrdreProduction $o, \DateTimeImmutable $maintenant): array
    {
        $reprise = $o->getRepriseDebuteeLe();
        $debut = $reprise ?? $o->getDemarreLe();
        $m2 = self::m2($o);
        $duree = $o->getDureeEstimeeMinutes();
        if ($reprise !== null && $duree !== null) {
            $quantite = max(1, (int) ($o->getQuantite() ?? 1));
            $duree = max(1, (int) ceil($duree * min($quantite, max(1, (int) $o->getPiecesARefaire())) / $quantite));
            $m2 = $m2 !== null ? round($m2 * min($quantite, max(1, (int) $o->getPiecesARefaire())) / $quantite, 2) : null;
        }
        // Prêt à démarrer (une pièce à refaire garde l'heure du premier démarrage) : rien ne tourne.
        if ($debut === null || $o->estADemarrer()) {
            return ['secondes' => 0, 'm2' => $m2, 'fait' => null, 'finEstimee' => null, 'debut' => null, 'duree' => $duree];
        }
        $pausesFaites = max(0, $o->getDureePauseMinutes() - ($reprise !== null ? $o->getReprisePauseMinutes() : 0));
        $pause = $pausesFaites * 60 + ($o->getPauseDebuteLe() !== null ? max(0, $maintenant->getTimestamp() - $o->getPauseDebuteLe()->getTimestamp()) : 0);
        $secondes = max(0, $maintenant->getTimestamp() - $debut->getTimestamp() - $pause);
        $fait = $duree !== null && $duree > 0 && $m2 !== null ? round(min(0.99, $secondes / ($duree * 60)) * $m2, 1) : null;
        $finEstimee = $duree !== null ? $debut->modify('+'.($duree * 60 + $pause).' seconds') : null;

        return ['secondes' => $secondes, 'm2' => $m2, 'fait' => $fait, 'finEstimee' => $finEstimee, 'debut' => $debut, 'duree' => $duree];
    }

    public function signaler(Machines $machine, User $operateur, string $type, \DateTimeImmutable $le, ?OrdreProduction $ordre, ?float $quantite, ?string $note): JournalMachine
    {
        $j = new JournalMachine($machine, $operateur, $type, $le, $ordre, $quantite, $note);
        $this->em->persist($j);
        $this->em->flush();

        return $j;
    }
}
