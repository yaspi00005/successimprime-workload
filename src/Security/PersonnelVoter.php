<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * PERSONNEL : la personne a une fiche employé. C'est la condition de
 * Mon espace et du pointage (MonEspaceController, PointerController) ;
 * le guide s'en sert pour les fiches « Mon espace ».
 *
 * @extends Voter<string, mixed>
 */
final class PersonnelVoter extends Voter
{
    public const PERSONNEL = 'PERSONNEL';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::PERSONNEL === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $moi = $token->getUser();

        return $moi instanceof User && null !== $moi->getEmploye();
    }
}
