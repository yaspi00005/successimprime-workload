<?php

namespace App\Security;

use App\Entity\CommandeDetailFichier;
use App\Entity\Employes;
use App\Entity\Etiquette;
use App\Entity\LotEtiquette;
use App\Entity\Message;
use App\Entity\Reclamation;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Qui ouvre un fichier privé (FichiersPrives, docs/securite.md) : les
 * mêmes droits que la page qui le montre. Toujours un compte du
 * personnel connecté ; jamais un compte client ni un visiteur.
 *
 * - CHAT (sujet : Message) : les participants de la conversation.
 * - PREPRESSE (sujet : CommandeDetailFichier) : la prépresse, et la
 *   production qui ouvre le fichier depuis l'ordre de sa machine (comme
 *   la règle de security.yaml sur /pre-presse/fichier/…).
 * - ETIQUETTE (sujet : Etiquette ou LotEtiquette) : la direction, comme
 *   les écrans des lots d'étiquettes.
 * - JUSTIFICATIF (sujet : Reclamation, demande de remboursement) : la
 *   direction, la caisse qui paie, et l'agent pour sa propre demande.
 * - PHOTO_EMPLOYE (sujet : Employes) : tout le personnel connecté (les
 *   photos servent d'avatar dans le chat et les listes de l'équipe).
 *
 * @extends Voter<string, mixed>
 */
final class FichiersPrivesVoter extends Voter
{
    public const CHAT = 'FICHIER_CHAT';
    public const PREPRESSE = 'FICHIER_PREPRESSE';
    public const ETIQUETTE = 'FICHIER_ETIQUETTE';
    public const JUSTIFICATIF = 'FICHIER_JUSTIFICATIF';
    public const PHOTO_EMPLOYE = 'FICHIER_PHOTO_EMPLOYE';

    public function __construct(private readonly AccessDecisionManagerInterface $decisions)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return match ($attribute) {
            self::CHAT => $subject instanceof Message,
            self::PREPRESSE => $subject instanceof CommandeDetailFichier,
            self::ETIQUETTE => $subject instanceof Etiquette || $subject instanceof LotEtiquette,
            self::JUSTIFICATIF => $subject instanceof Reclamation,
            self::PHOTO_EMPLOYE => $subject instanceof Employes,
            default => false,
        };
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $moi = $token->getUser();
        if (!$moi instanceof User || !$moi->isActif()) {
            return false;
        }

        return match ($attribute) {
            self::CHAT => $subject instanceof Message && true === $subject->getConversation()?->aPourParticipant($moi),
            self::PREPRESSE => $this->aUnDe($token, ['ROLE_PREPRESSE', 'ROLE_PRODUCTION_VOIR']),
            self::ETIQUETTE => $this->aUnDe($token, ['ROLE_ADMIN']),
            self::JUSTIFICATIF => $subject instanceof Reclamation
                && ($subject->getAgent() === $moi || $this->aUnDe($token, ['ROLE_ADMIN', 'ROLE_TRESORERIE_SAISIR', 'ROLE_CAISSE_COMMANDE'])),
            self::PHOTO_EMPLOYE => true,
            default => false,
        };
    }

    /** @param list<string> $roles */
    private function aUnDe(TokenInterface $token, array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->decisions->decide($token, [$role])) {
                return true;
            }
        }

        return false;
    }
}
