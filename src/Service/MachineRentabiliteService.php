<?php

namespace App\Service;

use App\Entity\CommandesDetails;
use App\Entity\Machines;
use App\Repository\MachinesRepository;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Calcule, pour chaque machine, ce qu'elle rapporte réellement :
 * revenu facturé (commandes liées + recap historique éventuel)
 * moins charges d'amortissement et de maintenance.
 */
class MachineRentabiliteService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MachinesRepository $machinesRepository,
        private readonly ?Parametres $parametres = null,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function calculerToutes(): array
    {
        $machines = $this->machinesRepository->findBy([], ['nom' => 'ASC']);

        return array_map(
            fn (Machines $machine): array => $this->calculer($machine),
            $machines
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function calculer(Machines $machine): array
    {
        $revenuCommandes = $this->revenuCommandes($machine);
        $revenuTotal = $machine->getRevenuAvantSuivi() + $revenuCommandes;

        $chargeMaintenance = $this->chargeMaintenance($machine);
        $amortissement = AmortissementMachine::cumul($machine, (string) ($this->parametres?->valeur('machines', 'amortissement') ?? AmortissementMachine::LINEAIRE), new \DateTimeImmutable());
        $chargeAmortissement = $amortissement['charge'];

        return [
            'machine' => $machine,
            'revenuAvantSuivi' => $machine->getRevenuAvantSuivi(),
            'revenuCommandes' => $revenuCommandes,
            'revenuTotal' => $revenuTotal,
            'chargeAmortissement' => $chargeAmortissement,
            'methodeAmortissement' => $amortissement['methode'],
            'chargeMaintenance' => $chargeMaintenance,
            'margeNette' => $revenuTotal - $chargeAmortissement - $chargeMaintenance,
        ];
    }

    /**
     * Somme des lignes de commande facturées sur cette machine, à
     * partir de la date de début de suivi si elle est renseignée
     * (pour ne pas doubler ce qui est déjà couvert par le recap
     * historique).
     */
    private function revenuCommandes(Machines $machine): int
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COALESCE(SUM(cd.totalHt), 0)')
            ->from(CommandesDetails::class, 'cd')
            ->join('cd.commande', 'c')
            ->where('cd.machine = :machine')
            ->setParameter('machine', $machine);

        if ($machine->getDateDebutSuivi() !== null) {
            $qb
                ->andWhere('c.dateCommande >= :dateDebutSuivi')
                ->setParameter('dateDebutSuivi', $machine->getDateDebutSuivi());
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function chargeMaintenance(Machines $machine): float
    {
        $total = 0.0;

        foreach ($machine->getMaintenances() as $maintenance) {
            $total += $this->nettoyerMontant($maintenance->getCout());
        }

        return $total;
    }

    private function nettoyerMontant(?string $valeur): float
    {
        if ($valeur === null) {
            return 0.0;
        }

        $nettoye = str_replace(
            [' ', "\xC2\xA0", 'F CFA', 'FCFA', ','],
            ['', '', '', '', '.'],
            $valeur
        );

        return is_numeric($nettoye) ? (float) $nettoye : 0.0;
    }
}
