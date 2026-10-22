<?php

namespace App\Security;

use App\Entity\Employes;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Scores et primes (docs/immobilisations-primes.md) : « tout le monde ne
 * doit pas tout voir ».
 *
 * - PRIMES_EQUIPE : la vue d'ensemble (N01), pour l'admin et pour qui est
 *   responsable d'au moins un employé actif (Employes::responsable).
 * - PRIMES_AGENT (sujet : l'agent) : voir et évaluer un agent (N02), pour
 *   l'admin et pour son responsable. Jamais soi-même : l'agent voit sa
 *   propre note dans Mon espace (N04).
 *
 * @extends Voter<string, mixed>
 */
final class PrimesVoter extends Voter
{
    public const EQUIPE = 'PRIMES_EQUIPE';
    public const AGENT = 'PRIMES_AGENT';

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::EQUIPE === $attribute || (self::AGENT === $attribute && $subject instanceof User);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $moi = $token->getUser();
        if (!$moi instanceof User) {
            return false;
        }
        if (self::AGENT === $attribute) {
            return $subject instanceof User && $subject !== $moi
                && ($this->security->isGranted('ROLE_ADMIN') || $subject->getEmploye()?->getResponsable() === $moi);
        }

        return $this->security->isGranted('ROLE_ADMIN') || $this->estResponsable($moi);
    }

    private function estResponsable(User $moi): bool
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(e.id)')->from(Employes::class, 'e')
            ->innerJoin(User::class, 'u', 'WITH', 'u.employe = e')
            ->where('e.responsable = :moi')->andWhere('u.actif = true')
            ->setParameter('moi', $moi)
            ->getQuery()->getSingleScalarResult() > 0;
    }
}
