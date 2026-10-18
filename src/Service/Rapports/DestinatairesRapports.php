<?php

namespace App\Service\Rapports;

use App\Entity\User;
use App\Service\Statistiques\Objectifs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

/**
 * Qui reçoit les rapports par e-mail (docs/rapports-mail.md).
 *
 * Tant que la direction n'a rien choisi : les comptes actifs de la
 * Direction qui ont une adresse e-mail (fiche employé). Une fois la liste
 * enregistrée (Accueil › Rapports par e-mail) : ces comptes-là. Dans les
 * deux cas, un compte désactivé ou sans le droit « Chiffre d'affaires
 * global » ne reçoit rien, même s'il a été choisi avant de perdre ce droit.
 */
final class DestinatairesRapports
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RoleHierarchyInterface $hierarchie,
        private readonly ReglagesRapportsMail $reglages,
    ) {
    }

    /**
     * Les destinataires des prochains rapports, dans l'ordre des comptes.
     *
     * @return list<array{utilisateur: User, roles: list<string>, droits: array<string, bool>, adresse: ?string}>
     */
    public function liste(): array
    {
        $choisis = $this->reglages->destinatairesChoisis();
        $liste = [];
        foreach ($this->comptesActifs() as $u) {
            $roles = $this->roles($u);
            $droits = DroitsRapport::depuisRoles($roles);
            $adresse = self::adresse($u);
            if (!self::retenu((int) $u->getId(), $roles, $droits['rapport'], null !== $adresse, $choisis)) {
                continue;
            }
            $liste[] = ['utilisateur' => $u, 'roles' => $roles, 'droits' => $droits, 'adresse' => $adresse];
        }

        return $liste;
    }

    /**
     * Ce compte reçoit-il les rapports ? Règle pure : avec le droit du
     * rapport, choisi par la direction, ou, sans choix, de la Direction
     * avec une adresse.
     *
     * @param list<string>   $roles
     * @param list<int>|null $choisis
     */
    public static function retenu(int $id, array $roles, bool $droit, bool $aUneAdresse, ?array $choisis): bool
    {
        if (!$droit) {
            return false;
        }
        if (null !== $choisis) {
            return \in_array($id, $choisis, true);
        }

        return $aUneAdresse && \in_array('ROLE_ADMIN', $roles, true);
    }

    public function recoit(User $u): bool
    {
        foreach ($this->liste() as $d) {
            if ($d['utilisateur']->getId() === $u->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tous les comptes actifs, pour la liste à cocher : son droit, son
     * adresse, la version qu'il recevrait, s'il est choisi.
     *
     * @return list<array{utilisateur: User, nom: string, profil: string, droit: bool, adresse: ?string, version: string, choisi: bool}>
     */
    public function candidats(): array
    {
        $choisis = $this->reglages->destinatairesChoisis();
        $candidats = [];
        foreach ($this->comptesActifs() as $u) {
            $roles = $this->roles($u);
            $droits = DroitsRapport::depuisRoles($roles);
            $adresse = self::adresse($u);
            $candidats[] = [
                'utilisateur' => $u,
                'nom' => Objectifs::nomAgent($u),
                'profil' => (string) ($u->getProfil()?->getNom() ?? ''),
                'droit' => $droits['rapport'],
                'adresse' => $adresse,
                'version' => DroitsRapport::version($droits),
                'choisi' => self::retenu((int) $u->getId(), $roles, $droits['rapport'], null !== $adresse, $choisis),
            ];
        }
        // Ceux qui peuvent recevoir d'abord, puis par nom.
        usort($candidats, static fn (array $a, array $b): int => [$b['droit'], $a['nom']] <=> [$a['droit'], $b['nom']]);

        return $candidats;
    }

    /**
     * Garde de la liste envoyée par l'écran les seuls comptes actifs qui
     * ont le droit du rapport.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function filtrer(array $ids): array
    {
        $permis = [];
        foreach ($this->comptesActifs() as $u) {
            if (\in_array((int) $u->getId(), $ids, true) && DroitsRapport::depuisRoles($this->roles($u))['rapport']) {
                $permis[] = (int) $u->getId();
            }
        }
        sort($permis);

        return $permis;
    }

    /**
     * Rôles atteignables, hiérarchie comprise, comme à la connexion.
     *
     * @return list<string>
     */
    public function roles(User $u): array
    {
        return array_values(array_unique($this->hierarchie->getReachableRoleNames($u->getRoles())));
    }

    /** @return array<string, bool> */
    public function droits(User $u): array
    {
        return DroitsRapport::depuisRoles($this->roles($u));
    }

    /** Adresse e-mail valable de la fiche employé, sinon null. */
    public static function adresse(User $u): ?string
    {
        $email = trim((string) $u->getEmploye()?->getEmail());

        return false !== filter_var($email, \FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /** @return list<User> */
    private function comptesActifs(): array
    {
        return $this->em->getRepository(User::class)->findBy(['actif' => true], ['id' => 'ASC']);
    }
}
