<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * LIVREUR : la personne livre elle-même (droit Livraisons coché pour son
 * rôle ou pour elle). La Direction, qui a tous les droits, n'en fait pas
 * partie : elle suit les livraisons de tous dans « Suivi des livraisons ».
 * Ouvre « Mes livraisons ».
 *
 * @extends Voter<string, mixed>
 */
final class LivreurVoter extends Voter
{
    public const LIVREUR = 'LIVREUR';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::LIVREUR === $attribute;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $moi = $token->getUser();

        return $moi instanceof User && self::livre($moi->getRoles());
    }

    /** @param list<string> $roles rôles de la personne (User::getRoles) ou droits d'un rôle */
    public static function livre(array $roles): bool
    {
        return \in_array('ROLE_LIVRAISON', $roles, true) && !\in_array(User::ROLE_ADMIN, $roles, true);
    }
}
