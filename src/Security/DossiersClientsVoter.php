<?php

namespace App\Security;

use App\Entity\User;
use App\Service\DossiersClients\ReglagesDossiers;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Dossiers clients (docs/dossiers-clients.md, maquettes DS01 à DS04) :
 *
 * - DOSSIERS_VOIR : voir au moins un sous-dossier des clients (matrice
 *   « Qui voit quoi » de DS04) ;
 * - DOSSIERS_CLASSER : ajouter dans au moins un sous-dossier, donc classer
 *   les fichiers « à classer » (DS03) : graphiste et caisse par défaut ;
 * - DOSSIERS_REGLER : lier les dossiers existants (DS02) et ouvrir les
 *   réglages (DS04) : Direction et Prépresse, comme la tuile de
 *   Paramètres ; la matrice des droits reste à la Direction.
 *
 * Tout est refusé sur le site en ligne : ces écrans lisent le disque de
 * l'atelier, que la version en ligne ne voit pas.
 *
 * @extends Voter<string, mixed>
 */
final class DossiersClientsVoter extends Voter
{
    public const VOIR = 'DOSSIERS_VOIR';
    public const CLASSER = 'DOSSIERS_CLASSER';
    public const REGLER = 'DOSSIERS_REGLER';

    public function __construct(private readonly ReglagesDossiers $reglages)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VOIR, self::CLASSER, self::REGLER], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $u = $token->getUser();
        if (!$u instanceof User || $this->reglages->enLigne()) {
            return false;
        }
        $roles = $u->getRoles();

        return match ($attribute) {
            self::VOIR => $this->reglages->droits()->peutQuelquePart($roles, 'voir'),
            self::CLASSER => $this->reglages->droits()->peutQuelquePart($roles, 'ajouter'),
            self::REGLER => \in_array('ROLE_ADMIN', $roles, true) || \in_array('ROLE_PREPRESSE', $roles, true),
            default => false,
        };
    }
}
