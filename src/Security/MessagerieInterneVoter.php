<?php

namespace App\Security;

use App\Entity\Conversation;
use App\Entity\User;
use App\Service\MessagerieInterne\ReglesMessagerieInterne;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Qui fait quoi dans la messagerie de l'équipe (docs/messagerie-interne.md).
 *
 * - ACCES : un compte du personnel actif avec le droit « Messagerie de
 *   l'équipe » (tous les rôles par défaut). Jamais un compte client
 *   (pare-feu « espace » à part, et ce n'est pas un User).
 * - VOIR (Conversation) : ses membres. La Direction lit aussi les groupes
 *   quand le réglage le permet, sans en être membre ; jamais les messages
 *   privés des autres.
 * - ECRIRE : un membre, groupe ouvert ; dans un groupe d'annonces, seuls
 *   les responsables du groupe.
 * - FICHIERS : écrire, et le groupe permet à tous d'envoyer des fichiers
 *   (sinon les responsables seulement).
 * - GERER : un responsable du groupe (membres, fermeture, réglages).
 * - CREER_GROUPE : le droit « Créer des groupes de l'équipe » (Direction et
 *   responsables par défaut).
 *
 * @extends Voter<string, mixed>
 */
final class MessagerieInterneVoter extends Voter
{
    public const ACCES = 'MESSAGERIE_INTERNE_ACCES';
    public const VOIR = 'MESSAGERIE_INTERNE_VOIR';
    public const ECRIRE = 'MESSAGERIE_INTERNE_ECRIRE';
    public const FICHIERS = 'MESSAGERIE_INTERNE_FICHIERS';
    public const GERER = 'MESSAGERIE_INTERNE_GERER';
    public const CREER_GROUPE = 'MESSAGERIE_INTERNE_CREER_GROUPE';

    public function __construct(private readonly ReglesMessagerieInterne $regles)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::ACCES, self::CREER_GROUPE => true,
            self::VOIR, self::ECRIRE, self::FICHIERS, self::GERER => $subject instanceof Conversation,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $moi = $token->getUser();
        if (!$moi instanceof User || !self::aAcces($moi)) {
            return false;
        }

        return match ($attribute) {
            self::ACCES => true,
            self::CREER_GROUPE => self::aLeDroit($moi, Droits::MESSAGERIE_GROUPES),
            self::VOIR => $subject instanceof Conversation && $this->peutLire($moi, $subject),
            self::ECRIRE => $subject instanceof Conversation && self::peutEcrire($moi, $subject),
            self::FICHIERS => $subject instanceof Conversation && self::peutEcrire($moi, $subject) && ($subject->estPrive() || $subject->isFichiersPourTous() || $subject->estAdmin($moi)),
            self::GERER => $subject instanceof Conversation && $subject->estGroupe() && $subject->estAdmin($moi),
            default => false,
        };
    }

    public function peutLire(User $moi, Conversation $c): bool
    {
        if (!self::aAcces($moi)) {
            return false;
        }
        if ($c->aPourParticipant($moi)) {
            return true;
        }

        return self::lectureDirection($moi, $c) && $this->regles->directionLitGroupes();
    }

    /** La Direction (pas membre) lit ce groupe : jamais un message privé. */
    public static function lectureDirection(User $moi, Conversation $c): bool
    {
        return $c->estGroupe() && \in_array(User::ROLE_ADMIN, $moi->getRoles(), true) && !$c->aPourParticipant($moi);
    }

    public static function peutEcrire(User $moi, Conversation $c): bool
    {
        return self::aAcces($moi) && $c->aPourParticipant($moi) && !$c->estFerme() && (!$c->estAnnonces() || $c->estAdmin($moi));
    }

    public static function aAcces(User $u): bool
    {
        return $u->isActif() && self::aLeDroit($u, Droits::MESSAGERIE_INTERNE);
    }

    /** La Direction a tous les droits (role_hierarchy) ; les autres, ceux de leur rôle et de leur fiche. */
    public static function aLeDroit(User $u, string $droit): bool
    {
        $roles = $u->getRoles();

        return \in_array(User::ROLE_ADMIN, $roles, true) || \in_array($droit, $roles, true);
    }
}
