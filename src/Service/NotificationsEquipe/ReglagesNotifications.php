<?php

namespace App\Service\NotificationsEquipe;

use App\Entity\Notification;
use App\Entity\Reglage;
use App\Entity\User;
use App\Repository\ReglageRepository;
use App\Repository\RoleUtilisateurRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réglages des notifications de l'équipe (maquette NT04, Réglages ›
 * Notifications), dans la ligne « notifications_equipe » de la table
 * reglage : pour chaque rôle et chaque sorte, application, son, WhatsApp,
 * SMS, e-mail ; heures calmes ; remontée au responsable du rôle puis à la
 * direction ; nombre maximal d'envois payants par jour.
 *
 * Les réglages se font sur l'ERP local (comme les tuiles de Paramètres) :
 * en ligne, l'écran les montre sans permettre de les changer.
 *
 * Le son coupé par une personne (bouton du centre de notifications) est
 * gardé à part, dans la ligne « notifications_son_coupe ».
 */
final class ReglagesNotifications
{
    public const CLE = 'notifications_equipe';
    public const CLE_SON = 'notifications_son_coupe';

    public const CALME_DEBUT = '19:00';
    public const CALME_FIN = '07:30';
    /** Jours (0 dimanche … 6 samedi) avec heures calmes le soir et la nuit. */
    public const CALME_JOURS = [1, 2, 3, 4, 5, 6];
    /** Jours calmes toute la journée. */
    public const CALME_JOURS_ENTIERS = [0];

    public const URGENT_MINUTES = 30;
    public const CRITIQUE_MINUTES = 60;
    public const DIRECTION_MINUTES = 60;
    public const RAPPEL_MINUTES = 30;
    public const PAYANTS_PAR_JOUR = 10;
    public const HEURE_CAISSE = '19:00';

    /** @var array<string, mixed>|null */
    private ?array $valeurs = null;
    /** @var array<string, list<string>>|null */
    private ?array $roles = null;

    public function __construct(
        private readonly ReglageRepository $reglages,
        private readonly RoleUtilisateurRepository $rolesUtilisateur,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Rôles des réglages, Direction d'abord : code => droits.
     *
     * @return array<string, list<string>>
     */
    public function roles(): array
    {
        if (null === $this->roles) {
            $this->roles = [TypesNotification::DIRECTION => []];
            foreach ($this->rolesUtilisateur->dansLOrdre() as $r) {
                if (!$r->estAdmin()) {
                    $this->roles[$r->getCode()] = $r->getDroits();
                }
            }
        }

        return $this->roles;
    }

    /** @return array<string, string> code => nom affiché */
    public function nomsDesRoles(): array
    {
        $noms = [TypesNotification::DIRECTION => 'Direction'];
        foreach ($this->rolesUtilisateur->dansLOrdre() as $r) {
            if (!$r->estAdmin()) {
                $noms[$r->getCode()] = $r->getNom();
            }
        }

        return $noms;
    }

    /** @return array{roles: array<string, array<string, array<string, bool>>>, calme: array{debut: string, fin: string, jours: list<int>, jours_entiers: list<int>}, escalade: array{urgent_minutes: int, critique_minutes: int, direction_minutes: int, responsables: array<string, int>}, rappel_minutes: int, payants_par_jour: int, heure_caisse: string} */
    public function valeurs(): array
    {
        return $this->valeurs ??= self::lire($this->reglages->findOneBy(['cle' => self::CLE])?->getValeurs() ?? [], $this->roles());
    }

    /** @return array{app: bool, son: bool, whatsapp: bool, sms: bool, email: bool} */
    public function canaux(?string $role, string $type): array
    {
        if (null === $role) {
            // Personne sans rôle : l'application seulement, ce qu'on lui adresse en personne.
            return ['app' => true, 'son' => Notification::INFO !== TypesNotification::niveau($type), 'whatsapp' => false, 'sms' => false, 'email' => false];
        }

        return $this->valeurs()['roles'][$role][$type] ?? TypesNotification::defaut($role, $type, $this->roles()[$role] ?? []);
    }

    /** Code du rôle d'une personne pour ces réglages (Direction pour l'administrateur). */
    public static function roleDe(User $u): ?string
    {
        if (\in_array(User::ROLE_ADMIN, $u->getRoles(), true)) {
            return TypesNotification::DIRECTION;
        }

        return $u->getProfil()?->getCode();
    }

    /**
     * Enregistre la saisie de l'écran. N'appelle pas flush().
     *
     * @param array<string, mixed> $saisie
     */
    public function enregistrer(array $saisie, ?User $par, \DateTimeImmutable $le): void
    {
        $valeurs = self::lire($saisie + ['formulaire' => true], $this->roles());
        $reglage = $this->reglages->findOneBy(['cle' => self::CLE]);
        if (null === $reglage) {
            $reglage = new Reglage(self::CLE);
            $this->em->persist($reglage);
        }
        $reglage->modifier($valeurs, $par, $le);
        $this->valeurs = $valeurs;
    }

    public function sonCoupe(User $u): bool
    {
        return \in_array((int) $u->getId(), $this->sonsCoupes(), true);
    }

    /** @return list<int> */
    private function sonsCoupes(): array
    {
        $ids = $this->reglages->findOneBy(['cle' => self::CLE_SON])?->getValeurs()['ids'] ?? [];

        return array_values(array_map('intval', \is_array($ids) ? $ids : []));
    }

    /** Son coupé ou remis pour une personne (sur tous ses appareils). N'appelle pas flush(). */
    public function couperSon(User $u, bool $coupe, \DateTimeImmutable $le): void
    {
        $ids = array_values(array_diff($this->sonsCoupes(), [(int) $u->getId()]));
        if ($coupe) {
            $ids[] = (int) $u->getId();
        }
        $reglage = $this->reglages->findOneBy(['cle' => self::CLE_SON]);
        if (null === $reglage) {
            $reglage = new Reglage(self::CLE_SON);
            $this->em->persist($reglage);
        }
        $reglage->modifier(['ids' => $ids], $u, $le);
    }

    /**
     * Valeurs nettoyées. Jamais réglé : les valeurs par défaut de la
     * maquette NT04. WhatsApp et SMS ne restent cochés que pour les
     * sortes critiques (envois payants).
     *
     * @param array<string, mixed>        $saisie
     * @param array<string, list<string>> $roles  code => droits
     *
     * @return array{roles: array<string, array<string, array<string, bool>>>, calme: array{debut: string, fin: string, jours: list<int>, jours_entiers: list<int>}, escalade: array{urgent_minutes: int, critique_minutes: int, direction_minutes: int, responsables: array<string, int>}, rappel_minutes: int, payants_par_jour: int, heure_caisse: string}
     */
    public static function lire(array $saisie, array $roles): array
    {
        $formulaire = isset($saisie['formulaire']);
        $saisieRoles = \is_array($saisie['roles'] ?? null) ? $saisie['roles'] : [];
        $matrice = [];
        foreach ($roles as $role => $droits) {
            $r = \is_array($saisieRoles[$role] ?? null) ? $saisieRoles[$role] : null;
            foreach (array_keys(TypesNotification::TYPES) as $type) {
                $defaut = TypesNotification::defaut($role, $type, $droits);
                $t = null !== $r && \is_array($r[$type] ?? null) ? $r[$type] : null;
                $ligne = [];
                foreach (array_keys(TypesNotification::CANAUX) as $canal) {
                    // Une case décochée n'est pas envoyée : dans le formulaire, absente vaut non.
                    $valeur = null !== $t ? (bool) ($t[$canal] ?? false) : ($formulaire && null !== $r ? false : $defaut[$canal]);
                    $ligne[$canal] = $valeur && TypesNotification::canalPermis($type, $canal);
                }
                // Sans l'application, rien ne part (ni son, ni envoi) pour ce rôle.
                if (!$ligne['app']) {
                    $ligne = array_map(static fn (): bool => false, $ligne);
                }
                $matrice[$role][$type] = $ligne;
            }
        }

        $calme = \is_array($saisie['calme'] ?? null) ? $saisie['calme'] : [];
        $escalade = \is_array($saisie['escalade'] ?? null) ? $saisie['escalade'] : [];
        $responsables = [];
        foreach (\is_array($escalade['responsables'] ?? null) ? $escalade['responsables'] : [] as $role => $id) {
            if (isset($roles[$role]) && is_numeric($id) && (int) $id > 0) {
                $responsables[(string) $role] = (int) $id;
            }
        }

        return [
            'roles' => $matrice,
            'calme' => [
                'debut' => self::heure($calme['debut'] ?? null, self::CALME_DEBUT),
                'fin' => self::heure($calme['fin'] ?? null, self::CALME_FIN),
                'jours' => self::jours($calme['jours'] ?? null, self::CALME_JOURS, $formulaire),
                'jours_entiers' => self::jours($calme['jours_entiers'] ?? null, self::CALME_JOURS_ENTIERS, $formulaire),
            ],
            'escalade' => [
                'urgent_minutes' => self::nombre($escalade['urgent_minutes'] ?? null, self::URGENT_MINUTES, 0, 1440),
                'critique_minutes' => self::nombre($escalade['critique_minutes'] ?? null, self::CRITIQUE_MINUTES, 0, 1440),
                'direction_minutes' => self::nombre($escalade['direction_minutes'] ?? null, self::DIRECTION_MINUTES, 0, 1440),
                'responsables' => $responsables,
            ],
            'rappel_minutes' => self::nombre($saisie['rappel_minutes'] ?? null, self::RAPPEL_MINUTES, 5, 240),
            'payants_par_jour' => self::nombre($saisie['payants_par_jour'] ?? null, self::PAYANTS_PAR_JOUR, 0, 200),
            'heure_caisse' => self::heure($saisie['heure_caisse'] ?? null, self::HEURE_CAISSE),
        ];
    }

    private static function heure(mixed $valeur, string $defaut): string
    {
        return \is_string($valeur) && 1 === preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $valeur) ? $valeur : $defaut;
    }

    /**
     * @param list<int> $defaut
     *
     * @return list<int>
     */
    private static function jours(mixed $valeur, array $defaut, bool $formulaire): array
    {
        if (!\is_array($valeur)) {
            return $formulaire ? [] : $defaut;
        }
        $jours = array_values(array_unique(array_filter(array_map('intval', $valeur), static fn (int $j): bool => $j >= 0 && $j <= 6)));
        sort($jours);

        return $jours;
    }

    private static function nombre(mixed $valeur, int $defaut, int $min, int $max): int
    {
        return is_numeric($valeur) ? max($min, min($max, (int) $valeur)) : $defaut;
    }
}
