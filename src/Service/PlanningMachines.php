<?php

namespace App\Service;

use App\Entity\CommandesDetails;
use App\Entity\Machines;
use App\Entity\Maintenance;
use App\Entity\OrdreProduction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Planning machines d'une journée (maquette 24) : les ordres de
 * production placés sur chaque machine entre l'ouverture et la
 * fermeture, les maintenances prévues et le taux d'occupation.
 */
final class PlanningMachines
{
    public const HEURE_DEBUT = 8;
    public const HEURE_FIN = 19;
    /** Au-delà, une machine est dite saturée. */
    public const SEUIL_SATURATION = 90;
    private const DUREE_PAR_DEFAUT = 60;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function donnees(\DateTimeImmutable $jour, \DateTimeImmutable $maintenant): array
    {
        $jour = $jour->setTime(0, 0);
        $machines = $this->em->getRepository(Machines::class)->findBy([], ['nom' => 'ASC']);
        $ouverture = $jour->setTime(self::HEURE_DEBUT, 0);
        $fermeture = $jour->setTime(self::HEURE_FIN, 0);

        $lignes = [];
        foreach ($machines as $machine) {
            $lignes[$machine->getId()] = ['machine' => $machine, 'blocs' => [], 'minutes' => 0];
        }

        $metres = 0.0;
        $retards = 0;
        foreach ($this->ordres($jour, $jour->modify('+1 day')) as $ordre) {
            $machine = $ordre->getMachine();
            if (null === $machine || !isset($lignes[$machine->getId()])) {
                continue;
            }
            [$debut, $fin] = self::creneau($ordre, $maintenant);
            if (null === $debut || $fin <= $ouverture || $debut >= $fermeture) {
                continue;
            }
            $retard = self::estEnRetard($ordre, $fin, $maintenant);
            $retards += $retard ? 1 : 0;
            $ligne = $ordre->getCommandeDetail();
            $metres += $ligne?->getSurfaceTotale() ?? 0.0;
            $bloc = self::bloc($debut, $fin, $ouverture, $fermeture) + [
                'titre' => null !== $ligne ? TableauProduction::titre($ligne) : $ordre->getNumero(),
                'detail' => trim($ordre->getNumero().' · '.($ligne?->getCommande()?->getClients()?->getNomComplet() ?? '')),
                'couleur' => match (true) {
                    $retard => 'rouge',
                    OrdreProduction::STATUT_TERMINE === $ordre->getStatut() => 'gris',
                    OrdreProduction::STATUT_EN_PAUSE === $ordre->getStatut() => 'orange',
                    default => TableauProduction::couleur($machine, $ligne),
                },
                'statut' => $ordre->getStatut(),
                'ordre' => $ordre,
            ];
            $lignes[$machine->getId()]['blocs'][] = $bloc;
            $lignes[$machine->getId()]['minutes'] += $bloc['minutes'];
        }

        foreach ($this->maintenances($jour, $jour->modify('+1 day')) as $maintenance) {
            $machine = $maintenance->getMachine();
            if (null === $machine || !isset($lignes[$machine->getId()])) {
                continue;
            }
            $debut = \DateTimeImmutable::createFromInterface($maintenance->getDate());
            if ('00:00' === $debut->format('H:i')) {
                $debut = $ouverture;
            }
            $fin = $debut->modify('+'.self::DUREE_PAR_DEFAUT.' minutes');
            if ($fin <= $ouverture || $debut >= $fermeture) {
                continue;
            }
            $bloc = self::bloc($debut, $fin, $ouverture, $fermeture) + [
                'titre' => $maintenance->getType() ?: 'Maintenance',
                'detail' => (string) $maintenance->getDescription(),
                'couleur' => 'maintenance',
                'statut' => 'maintenance',
                'ordre' => null,
            ];
            $lignes[$machine->getId()]['blocs'][] = $bloc;
            $lignes[$machine->getId()]['minutes'] += $bloc['minutes'];
        }

        $duree = (self::HEURE_FIN - self::HEURE_DEBUT) * 60;
        foreach ($lignes as &$ligne) {
            $ligne['occupation'] = (int) round(min(100, 100 * $ligne['minutes'] / $duree));
            usort($ligne['blocs'], static fn (array $a, array $b): int => $a['gauche'] <=> $b['gauche']);
        }
        unset($ligne);

        $demain = $jour->modify('+1 day');

        return [
            'jour' => $jour,
            'lignes' => array_values($lignes),
            'heures' => range(self::HEURE_DEBUT, self::HEURE_FIN),
            'maintenant' => $maintenant >= $ouverture && $maintenant < $fermeture
                ? ['heure' => $maintenant, 'gauche' => self::position($maintenant, $ouverture)]
                : null,
            'metres' => $metres,
            'retards' => $retards,
            'saturees' => $this->saturees($demain, $maintenant),
            'prochaine_maintenance' => $this->prochaineMaintenance($maintenant),
            'sans_creneau' => $this->sansCreneau(),
        ];
    }

    /**
     * Début et fin d'un ordre : réels s'il a démarré, prévus sinon.
     *
     * @return array{0: ?\DateTimeImmutable, 1: ?\DateTimeImmutable}
     */
    public static function creneau(OrdreProduction $ordre, \DateTimeImmutable $maintenant): array
    {
        $debut = $ordre->getDemarreLe() ?? $ordre->getDebutPrevuLe();
        if (null === $debut) {
            return [null, null];
        }
        $duree = $ordre->getDureeEstimeeMinutes() ?? self::dureeEstimee($ordre);
        $fin = $ordre->getTermineLe()
            ?? $ordre->getFinPrevueLe()
            ?? $debut->modify('+'.$duree.' minutes');
        // Un travail en cours qui dépasse sa fin prévue occupe la machine jusqu'à maintenant.
        if (\in_array($ordre->getStatut(), [OrdreProduction::STATUT_EN_COURS, OrdreProduction::STATUT_EN_PAUSE], true) && $fin < $maintenant) {
            $fin = $maintenant;
        }

        return [$debut, max($fin, $debut->modify('+15 minutes'))];
    }

    private static function dureeEstimee(OrdreProduction $ordre): int
    {
        $vitesse = $ordre->getMachine()?->getVitesseM2Heure();
        $surface = $ordre->getCommandeDetail()?->getSurfaceTotale() ?? 0.0;

        return null !== $vitesse && $vitesse > 0 && $surface > 0
            ? max(15, (int) ceil(60 * $surface / $vitesse))
            : self::DUREE_PAR_DEFAUT;
    }

    private static function estEnRetard(OrdreProduction $ordre, \DateTimeImmutable $fin, \DateTimeImmutable $maintenant): bool
    {
        if (\in_array($ordre->getStatut(), [OrdreProduction::STATUT_TERMINE, OrdreProduction::STATUT_ANNULE], true)) {
            return false;
        }
        $limite = $ordre->getDateLimite();

        return (null !== $limite && ($limite < $maintenant || $fin > $limite))
            || (OrdreProduction::STATUT_A_PRODUIRE === $ordre->getStatut() && $fin < $maintenant);
    }

    /** @return array{gauche: float, largeur: float, minutes: int, debut: \DateTimeImmutable, fin: \DateTimeImmutable} */
    private static function bloc(\DateTimeImmutable $debut, \DateTimeImmutable $fin, \DateTimeImmutable $ouverture, \DateTimeImmutable $fermeture): array
    {
        $debutVisible = max($debut, $ouverture);
        $finVisible = min($fin, $fermeture);
        $gauche = self::position($debutVisible, $ouverture);

        return [
            'gauche' => $gauche,
            'largeur' => max(1.5, self::position($finVisible, $ouverture) - $gauche),
            'minutes' => intdiv($finVisible->getTimestamp() - $debutVisible->getTimestamp(), 60),
            'debut' => $debut,
            'fin' => $fin,
        ];
    }

    /** Position en % de la journée affichée. */
    private static function position(\DateTimeImmutable $moment, \DateTimeImmutable $ouverture): float
    {
        $total = (self::HEURE_FIN - self::HEURE_DEBUT) * 3600;

        return round(100 * ($moment->getTimestamp() - $ouverture->getTimestamp()) / $total, 2);
    }

    /** @return list<OrdreProduction> */
    private function ordres(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->em->createQueryBuilder()
            ->select('ordre', 'machine', 'detail', 'commande', 'client')
            ->from(OrdreProduction::class, 'ordre')
            ->innerJoin('ordre.machine', 'machine')
            ->leftJoin('ordre.commandeDetail', 'detail')
            ->leftJoin('detail.commande', 'commande')
            ->leftJoin('commande.clients', 'client')
            ->andWhere('ordre.statut != :annule')
            ->andWhere('COALESCE(ordre.demarreLe, ordre.debutPrevuLe) < :fin')
            ->andWhere('ordre.termineLe IS NULL OR ordre.termineLe >= :debut')
            ->andWhere('ordre.finPrevueLe IS NULL OR ordre.finPrevueLe >= :debut OR ordre.statut IN (:actifs)')
            ->setParameter('annule', OrdreProduction::STATUT_ANNULE)
            ->setParameter('actifs', [OrdreProduction::STATUT_EN_COURS, OrdreProduction::STATUT_EN_PAUSE, OrdreProduction::STATUT_A_PRODUIRE])
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Maintenance> */
    private function maintenances(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->em->createQueryBuilder()
            ->select('maintenance', 'machine')
            ->from(Maintenance::class, 'maintenance')
            ->innerJoin('maintenance.machine', 'machine')
            ->andWhere('maintenance.date >= :debut AND maintenance.date < :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();
    }

    private function prochaineMaintenance(\DateTimeImmutable $maintenant): ?Maintenance
    {
        return $this->em->createQueryBuilder()
            ->select('maintenance', 'machine')
            ->from(Maintenance::class, 'maintenance')
            ->innerJoin('maintenance.machine', 'machine')
            ->andWhere('maintenance.date >= :debut')
            ->setParameter('debut', $maintenant->setTime(0, 0))
            ->orderBy('maintenance.date', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Machines déjà presque pleines le lendemain.
     *
     * @return list<array{machine: Machines, occupation: int, travaux: int}>
     */
    private function saturees(\DateTimeImmutable $jour, \DateTimeImmutable $maintenant): array
    {
        $ouverture = $jour->setTime(self::HEURE_DEBUT, 0);
        $fermeture = $jour->setTime(self::HEURE_FIN, 0);
        $parMachine = [];
        foreach ($this->ordres($jour, $jour->modify('+1 day')) as $ordre) {
            [$debut, $fin] = self::creneau($ordre, $maintenant);
            if (null === $debut || $fin <= $ouverture || $debut >= $fermeture) {
                continue;
            }
            $id = $ordre->getMachine()->getId();
            $parMachine[$id] ??= ['machine' => $ordre->getMachine(), 'minutes' => 0, 'travaux' => 0];
            $parMachine[$id]['minutes'] += self::bloc($debut, $fin, $ouverture, $fermeture)['minutes'];
            ++$parMachine[$id]['travaux'];
        }

        $saturees = [];
        foreach ($parMachine as $donnees) {
            $occupation = (int) round(100 * $donnees['minutes'] / ((self::HEURE_FIN - self::HEURE_DEBUT) * 60));
            if ($occupation >= self::SEUIL_SATURATION) {
                $saturees[] = ['machine' => $donnees['machine'], 'occupation' => min(100, $occupation), 'travaux' => $donnees['travaux']];
            }
        }

        return $saturees;
    }

    /**
     * Travaux à imprimer qui n'ont pas encore de créneau.
     *
     * @return list<OrdreProduction>
     */
    private function sansCreneau(): array
    {
        return $this->em->createQueryBuilder()
            ->select('ordre', 'machine', 'detail')
            ->from(OrdreProduction::class, 'ordre')
            ->leftJoin('ordre.machine', 'machine')
            ->leftJoin('ordre.commandeDetail', 'detail')
            ->andWhere('ordre.statut = :aProduire')
            ->andWhere('ordre.debutPrevuLe IS NULL')
            ->setParameter('aProduire', OrdreProduction::STATUT_A_PRODUIRE)
            ->orderBy('ordre.creeLe', 'ASC')
            ->setMaxResults(12)
            ->getQuery()
            ->getResult();
    }
}
