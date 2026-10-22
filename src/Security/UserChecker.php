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


        /*
         * Employé sans accès, invitation pas encore activée, accès
         * suspendu ou contrat terminé (maquettes E02 et E04).
         */
        if (!$user->peutSeConnecter(new \DateTimeImmutable())) {

            throw new CustomUserMessageAccountStatusException(
                $user->getAcces() === User::ACCES_INVITE
                    ? 'Activez d’abord votre accès avec le lien reçu sur WhatsApp.'
                    : 'Votre accès à l’application est fermé. Veuillez contacter le responsable.'
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