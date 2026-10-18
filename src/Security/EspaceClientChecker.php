<?php

namespace App\Security;

use App\Entity\CompteClient;
use App\Service\EspaceClient\SuspensionsEspace;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Un compte client fermé ne peut plus se connecter. Un accès suspendu par
 * l'imprimerie non plus (SuspensionsEspace) : vérifié une fois le mot de
 * passe ou le code reconnu, pour que seule la personne elle-même lise
 * qu'elle est suspendue. Vaut aussi pour « rester connecté ».
 */
final class EspaceClientChecker implements UserCheckerInterface
{
    public function __construct(private readonly ?SuspensionsEspace $suspensions = null)
    {
    }

    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof CompteClient && !$user->isActif()) {
            throw new CustomUserMessageAccountStatusException('Ce compte est fermé. Contactez Succès Imprim.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if ($user instanceof CompteClient && null !== $this->suspensions && $this->suspensions->compteSuspendu($user)) {
            throw new CustomUserMessageAccountStatusException(SuspensionsEspace::MESSAGE);
        }
    }
}
