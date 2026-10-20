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
 * personne pointée à l'atelier aujourd'hui.
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
        if (!$this->security->isGranted('ROLE_PRODUCTION_GERER')) {
            return 'Il faut le droit « Statuts de production ».';
        }
        if ($this->security->isGranted('ROLE_ATELIER_CHEF')) {
            return null;
        }
        $machine = $ordre->getMachine();
        $poste = $this->machineDuPoste();
        if (null !== $poste && (null === $machine || $poste === $machine)) {
            return null;
        }
        $atelier = null !== $machine ? $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine]) : null;
        if (null !== $atelier && $atelier->getOperateurs()->contains($moi)) {
            return null;
        }
        if (null !== $poste) {
            return \sprintf('Ce poste est celui de %s : demandez la reprise depuis %s.', $poste->getNom(), $machine?->getNom() ?? 'sa machine');
        }
        if (null !== $atelier && !$atelier->getOperateurs()->isEmpty()) {
            return \sprintf('Seuls les opérateurs affectés à %s, le chef d\'atelier ou le poste de la machine peuvent demander une reprise.', $machine?->getNom());
        }
        $dernier = $this->pointages->dernier($moi);
        $pointe = null !== $dernier && null === $dernier->getDepartLe() && $dernier->getArriveeLe()->format('Ymd') === (new \DateTimeImmutable())->format('Ymd');

        return $pointe ? null : 'Pointez votre arrivée à l\'atelier pour demander une reprise depuis le téléphone (ou utilisez le poste de la machine).';
    }

    private function machineDuPoste(): ?Machines
    {
        $ip = $this->requetes->getMainRequest()?->getClientIp();

        return null !== $ip ? $this->machines->findOneByAdresseIp($ip) : null;
    }
}
