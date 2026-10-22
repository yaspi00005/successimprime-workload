<?php

namespace App\Service\Atelier;

use App\Entity\AtelierMachine;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\User;
use App\Repository\MachinesRepository;
use App\Repository\PointageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Qui peut agir sur un travail de l'atelier : la règle de Ma file
 * (MaFileController::raisonRefus, docs/atelier-production.md), reprise
 * telle quelle pour « Refaire une pièce » du contrôle final (PD06,
 * docs/production-colis.md). Droit « Statuts de production », puis le chef
 * d'atelier, le poste de la machine (adresse IP reconnue), un opérateur
 * affecté à la machine, ou, pour une machine sans opérateur affecté, la
 * personne pointée à l'atelier aujourd'hui. Aussi pour les gestes sur un
 * rouleau en impression (PD04, raisonRefusMachine).
 */
final class RegleAtelier
{
    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requetes,
        private readonly MachinesRepository $machines,
        private readonly PointageRepository $pointages,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** null : la personne peut agir sur ce travail ; sinon la raison, à afficher. */
    public function raisonRefus(OrdreProduction $ordre, User $moi): ?string
    {
        return $this->refus($ordre->getMachine(), $moi, true);
    }

    /**
     * La même règle pour un geste sur une machine : le rouleau en
     * impression (PD04, ImpressionEnCoursController).
     */
    public function raisonRefusMachine(?Machines $machine, User $moi): ?string
    {
        return $this->refus($machine, $moi, false);
    }

    private function refus(?Machines $machine, User $moi, bool $reprise): ?string
    {
        if (!$this->security->isGranted('ROLE_PRODUCTION_GERER')) {
            return 'Il faut le droit « Statuts de production ».';
        }
        if ($this->security->isGranted('ROLE_ATELIER_CHEF')) {
            return null;
        }
        $poste = $this->machineDuPoste();
        if (null !== $poste && (null === $machine || $poste === $machine)) {
            return null;
        }
        $atelier = null !== $machine ? $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine]) : null;
        if (null !== $atelier && $atelier->getOperateurs()->contains($moi)) {
            return null;
        }
        if (null !== $poste) {
            return $reprise
                ? \sprintf('Ce poste est celui de %s : demandez la reprise depuis %s.', $poste->getNom(), $machine?->getNom() ?? 'sa machine')
                : \sprintf('Ce poste est celui de %s : agissez depuis %s.', $poste->getNom(), $machine?->getNom() ?? 'sa machine');
        }
        if (null !== $atelier && !$atelier->getOperateurs()->isEmpty()) {
            return \sprintf($reprise ? 'Seuls les opérateurs affectés à %s, le chef d\'atelier ou le poste de la machine peuvent demander une reprise.' : 'Seuls les opérateurs affectés à %s, le chef d\'atelier ou le poste de la machine peuvent agir.', $machine?->getNom());
        }
        $dernier = $this->pointages->dernier($moi);
        $pointe = null !== $dernier && null === $dernier->getDepartLe() && $dernier->getArriveeLe()->format('Ymd') === (new \DateTimeImmutable())->format('Ymd');

        return $pointe ? null : ($reprise
            ? 'Pointez votre arrivée à l\'atelier pour demander une reprise depuis le téléphone (ou utilisez le poste de la machine).'
            : 'Pointez votre arrivée à l\'atelier pour agir depuis le téléphone (ou utilisez le poste de la machine).');
    }

    /** La machine dont c'est le poste (adresse IP reconnue), sinon null. */
    public function machineDuPoste(): ?Machines
    {
        $ip = $this->requetes->getMainRequest()?->getClientIp();

        return null !== $ip ? $this->machines->findOneByAdresseIp($ip) : null;
    }
}
