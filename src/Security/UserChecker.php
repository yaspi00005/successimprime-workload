<?php

namespace App\Security;

use App\Entity\User;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;


final class UserChecker implements UserCheckerInterface
{
    /*
     * ============================================================
     * AVANT AUTHENTIFICATION
     * ============================================================
     *
     * Vérifie que le compte utilisateur est actif.
     * ============================================================
     */
    public function checkPreAuth(
        UserInterface $user
    ): void {

        if (!$user instanceof User) {
            return;
        }


        if (!$user->isActif()) {

            throw new CustomUserMessageAccountStatusException(
                'Votre compte est désactivé. Veuillez contacter l’administrateur.'
            );
        }
    }


    /*
     * ============================================================
     * APRÈS AUTHENTIFICATION
     * ============================================================
     *
     * La version actuelle de Symfony attend également
     * le TokenInterface en second paramètre.
     * ============================================================
     */
    public function checkPostAuth(
        UserInterface $user,
        ?TokenInterface $token = null
    ): void {

        /*
         * Aucun contrôle supplémentaire nécessaire
         * pour le moment.
         */
    }
}