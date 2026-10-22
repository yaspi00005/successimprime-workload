<?php

namespace App\Security;

use App\Entity\ConversationClient;
use App\Entity\User;
use App\Service\Messagerie\ReglesReponse;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Qui voit quoi dans les messages des clients (maquettes MS03, MS04) :
 *  - MESSAGERIE : la boîte « Messages » (caisse, commercial, trésorière,
 *    PAO ; la Direction) ;
 *  - MESSAGERIE_VOIR (une conversation) : la Direction voit tout ; les
 *    autres, celles qui leur sont assignées, celles où un collègue les a
 *    ajoutés, celles des commandes et devis dont ils sont l'agent, et les
 *    non assignées de leur rôle (caisse et commercial ; paiement et facture
 *    pour qui encaisse ou facture). La PAO ne voit que celles où on l'a
 *    ajoutée ;
 *  - MESSAGERIE_DIRECTION : supervision, toutes les conversations, règles.
 *
 * @extends Voter<string, mixed>
 */
final class MessagerieVoter extends Voter
{
    public const BOITE = 'MESSAGERIE';
    public const VOIR = 'MESSAGERIE_VOIR';
    public const DIRECTION = 'MESSAGERIE_DIRECTION';

    /** Un de ces droits ouvre la boîte (onglet « Messages » du menu Clients). */
    public const ROLES_BOITE = ['ROLE_CLIENT', 'ROLE_COMMANDE', 'ROLE_DEVIS', 'ROLE_PAIEMENT_ENCAISSER', 'ROLE_FACTURE', 'ROLE_PREPRESSE'];

    public function __construct(private readonly ReglesReponse $regles)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::BOITE, self::DIRECTION], true)
            || (self::VOIR === $attribute && $subject instanceof ConversationClient);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $moi = $token->getUser();
        if (!$moi instanceof User) {
            return false;
        }
        $roles = $moi->getRoles();
        $direction = \in_array(User::ROLE_ADMIN, $roles, true);

        return match ($attribute) {
            self::DIRECTION => $direction,
            self::BOITE => $direction || [] !== array_intersect(self::ROLES_BOITE, $roles),
            default => $direction || self::peutVoir($moi, $subject, $this->regles->tresoriere()),
        };
    }

    public static function peutVoir(User $moi, ConversationClient $c, ?User $tresoriere = null): bool
    {
        $roles = $moi->getRoles();
        if (\in_array(User::ROLE_ADMIN, $roles, true)) {
            return true;
        }
        if ([] === array_intersect(self::ROLES_BOITE, $roles)) {
            return false;
        }
        if ($c->getAssigneA() === $moi || $c->aPourParticipant($moi)) {
            return true;
        }
        $agent = $c->getCommande()?->getAgents() ?? $c->getDevis()?->getAgents();
        if (null !== $agent && $agent === $moi) {
            return true;
        }
        if (null !== $c->getAssigneA()) {
            return false;
        }

        return self::repondNonAssignees($moi, $c->getSujet(), $tresoriere);
    }

    /** Non assignées de son rôle : la caisse et le commercial ; paiement et facture pour qui encaisse, facture, ou la trésorière. */
    public static function repondNonAssignees(User $moi, string $sujet, ?User $tresoriere = null): bool
    {
        $roles = $moi->getRoles();
        if (\in_array('ROLE_PREPRESSE', $roles, true) && !\in_array('ROLE_PAIEMENT_ENCAISSER', $roles, true)) {
            return false;
        }
        if (ConversationClient::SUJET_PAIEMENT === $sujet) {
            return $tresoriere === $moi || [] !== array_intersect(['ROLE_PAIEMENT_ENCAISSER', 'ROLE_FACTURE'], $roles);
        }

        return [] !== array_intersect(['ROLE_COMMANDE', 'ROLE_DEVIS', 'ROLE_PAIEMENT_ENCAISSER', 'ROLE_CLIENT'], $roles);
    }
}
