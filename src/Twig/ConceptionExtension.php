<?php

namespace App\Twig;

use App\Entity\DemandeConception;
use App\Entity\User;
use App\Service\Conception\TarifConception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Demande de conception dans les gabarits (docs/demande-conception.md) :
 * montants « 5 000 F » de l'espace client, et le bandeau de l'atelier
 * (demandes reçues à prendre en charge), pour qui a le droit de les voir.
 */
final class ConceptionExtension extends AbstractExtension
{
    private ?int $aPrendre = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('francs_conception', static fn (?int $montant): string => TarifConception::francs((int) $montant))];
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('demandes_conception_a_prendre', $this->aPrendre(...))];
    }

    /** Demandes reçues ou impressions commandées depuis l'IA, pas encore prises en charge. */
    public function aPrendre(): int
    {
        if (null !== $this->aPrendre) {
            return $this->aPrendre;
        }
        if (!$this->security->getUser() instanceof User || !($this->security->isGranted('ROLE_PREPRESSE') || $this->security->isGranted('ROLE_COMMANDE'))) {
            return $this->aPrendre = 0;
        }

        return $this->aPrendre = (int) $this->em->createQueryBuilder()
            ->select('COUNT(d.id)')->from(DemandeConception::class, 'd')
            ->where('d.statut IN (:statuts) AND d.priseEnChargeLe IS NULL')
            ->setParameter('statuts', [DemandeConception::RECUE, DemandeConception::COMMANDEE])
            ->getQuery()->getSingleScalarResult();
    }
}
