<?php

namespace App\Security;

use App\Entity\Commandes;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

class CommandeVoter extends Voter
{
    public const VOIR = 'COMMANDE_VOIR';
    public const MODIFIER = 'COMMANDE_MODIFIER';
    public const SUPPRIMER = 'COMMANDE_SUPPRIMER';

    public function __construct(
        private readonly Security $security
    ) {
    }

    protected function supports(
        string $attribute,
        mixed $subject
    ): bool {
        return in_array(
            $attribute,
            [
                self::VOIR,
                self::MODIFIER,
                self::SUPPRIMER,
            ],
            true
        ) && $subject instanceof Commandes;
    }

 protected function voteOnAttribute(
    string $attribute,
    mixed $subject,
    TokenInterface $token,
    ?Vote $vote = null
): bool {
        if (!$subject instanceof Commandes) {
            return false;
        }

        $utilisateur = $token->getUser();

        if (!is_object($utilisateur)) {
            return false;
        }

        /*
         * L’administrateur possède tous les droits.
         */
        if ($this->security->isGranted('ROLE_ADMIN')) {
            return true;
        }

        return match ($attribute) {
            self::VOIR => $this->security->isGranted('ROLE_USER'),

            self::MODIFIER => $this->security->isGranted(
                'ROLE_COMMERCIAL'
            ),

            self::SUPPRIMER => false,

            default => false,
        };
    }
}