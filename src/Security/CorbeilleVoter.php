<?php

namespace App\Security;

use App\Entity\Corbeille;
use App\Entity\User;
use App\Service\Corbeille\TypesCorbeille;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Corbeille (docs/corbeille.md), sur l'ERP local seulement :
 *
 * - CORBEILLE : ouvrir l'écran. La Direction, et qui a le droit
 *   « Corbeille » (Réglages › Rôles et menus, ou sur sa fiche).
 * - CORBEILLE_ENTREE (sujet : l'entrée) : voir, restaurer ou supprimer pour
 *   de bon cette entrée. En plus de la corbeille, le droit qui ouvre ce
 *   genre de fiche (un paiement supprimé demande « Paiements », un produit
 *   la Direction) : la corbeille ne montre pas plus que les autres écrans.
 *
 * Le mode « Voir » (lecture seule) est tenu par LectureSeuleSubscriber.
 *
 * @extends Voter<string, mixed>
 */
final class CorbeilleVoter extends Voter
{
    public const CORBEILLE = 'CORBEILLE';
    public const ENTREE = 'CORBEILLE_ENTREE';

    public function __construct(
        private readonly Security $security,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::CORBEILLE === $attribute || (self::ENTREE === $attribute && $subject instanceof Corbeille);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($this->enLigne || !$token->getUser() instanceof User) {
            return false;
        }
        if (!$this->security->isGranted(TypesCorbeille::DIRECTION) && !$this->security->isGranted(Droits::CORBEILLE)) {
            return false;
        }
        if (self::CORBEILLE === $attribute) {
            return true;
        }

        return $subject instanceof Corbeille && $this->peutVoirTable($subject->getTableNom());
    }

    public function peutVoirTable(string $table): bool
    {
        return $this->security->isGranted(TypesCorbeille::DIRECTION) || $this->security->isGranted(TypesCorbeille::droit($table));
    }

    /**
     * Tables dont la personne voit les entrées (null : toutes, pour la
     * Direction).
     *
     * @return list<string>|null
     */
    public function tablesVisibles(): ?array
    {
        if ($this->security->isGranted(TypesCorbeille::DIRECTION)) {
            return null;
        }

        return array_values(array_filter(array_keys(TypesCorbeille::TYPES), fn (string $t): bool => $this->peutVoirTable($t)));
    }
}
