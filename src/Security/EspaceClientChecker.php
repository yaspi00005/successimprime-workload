<?php

namespace App\Security;

use App\Entity\CompteClient;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/** Un compte client fermé par la boutique ne peut plus se connecter. */
final class EspaceClientChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof CompteClient && !$user->isActif()) {
            throw new CustomUserMessageAccountStatusException('Ce compte est fermé. Contactez Succès Imprim.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
