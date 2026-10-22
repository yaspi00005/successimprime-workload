<?php

namespace App\Service\EspaceClient;

use App\Entity\CompteClient;

/**
 * « Comment je suis prévenu » (maquette EC03) : pour chaque personne d'un
 * compte, les canaux (application, WhatsApp, SMS, e-mail) par catégorie
 * de notification. Règles fixes, quelles que soient les cases cochées :
 *
 *  - les factures et paiements ne vont qu'aux payeurs ;
 *  - un compte « Lecture · suivi » ne reçoit que la production et la
 *    livraison ;
 *  - pas de SMS ni de WhatsApp entre 21 h et 7 h, sauf pour une livraison
 *    du jour : ils partent à 7 h (NotificationsClient) ;
 *  - WhatsApp se règle déjà mais ne part pas tant que l'atelier ne l'a
 *    pas activé ;
 *  - « Offres » décochée par défaut : la cocher vaut accord du client pour
 *    les offres (fiche client, consentementMarketingLe).
 */
final class PreferencesNotifications
{
    public const APP = 'app';
    public const WHATSAPP = 'whatsapp';
    public const SMS = 'sms';
    public const EMAIL = 'email';

    public const CANAUX = [self::APP => 'App', self::WHATSAPP => 'WhatsApp', self::SMS => 'SMS', self::EMAIL => 'E-mail'];

    public const CATEGORIES = [
        'bat' => 'BAT',
        'production' => 'Production',
        'livraison' => 'Livraison',
        'factures' => 'Factures',
        'devis' => 'Devis',
        'messages' => 'Messages',
        'offres' => 'Offres',
    ];

    /** Type d'événement du fil (EspaceClient::notifications) → catégorie. */
    public const CATEGORIE_DU_TYPE = [
        'bat' => 'bat',
        'impression' => 'production',
        'prete' => 'production',
        'livraison_prevue' => 'livraison',
        'route' => 'livraison',
        'livree' => 'livraison',
        'paiement' => 'factures',
        'facture' => 'factures',
        'echeance' => 'factures',
        'devis' => 'devis',
        'message' => 'messages',
        'reclamation' => 'messages',
        // Demande de conception prise en charge, terminée ou annulée (docs/demande-conception.md).
        'conception' => 'production',
    ];

    /** Heures calmes : ni SMS ni WhatsApp de 21 h à 7 h. */
    public const CALME_DEBUT = 21;
    public const CALME_FIN = 7;

    /** Canaux qui réveillent : soumis aux heures calmes. */
    public const CANAUX_BRUYANTS = [self::SMS, self::WHATSAPP];

    /**
     * Catégories que cette personne a le droit de recevoir (et de voir dans
     * son fil), dans l'ordre de la grille.
     *
     * @return list<string>
     */
    public static function categoriesPermises(CompteClient $compte): array
    {
        if ($compte->estLectureSeule()) {
            return ['production', 'livraison'];
        }

        return array_values(array_filter(
            array_keys(self::CATEGORIES),
            static fn (string $c): bool => 'factures' !== $c || $compte->peut(CompteClient::DROIT_PAYEUR),
        ));
    }

    public static function permise(CompteClient $compte, string $categorie): bool
    {
        return \in_array($categorie, self::categoriesPermises($compte), true);
    }

    /**
     * Réglages de départ. Le SMS (payant) part seulement pour le titulaire
     * de la fiche, et pour ce qui attend une réponse ou un déplacement :
     * BAT, livraison, réponse de l'atelier. Les autres personnes choisissent.
     *
     * @return array<string, list<string>>
     */
    public static function parDefaut(CompteClient $compte): array
    {
        $titulaire = null === $compte->getAjoutePar();
        $sms = $titulaire ? [self::SMS] : [];
        $consentement = $titulaire && null !== $compte->getClient()?->getConsentementMarketingLe();
        $grille = [
            'bat' => [self::APP, self::WHATSAPP, ...$sms],
            'production' => [self::APP],
            'livraison' => [self::APP, self::WHATSAPP, ...$sms],
            'factures' => [self::APP, self::WHATSAPP, self::EMAIL],
            'devis' => [self::APP, self::WHATSAPP, self::EMAIL],
            'messages' => [self::APP, self::WHATSAPP, ...$sms],
            // Accord déjà donné à l'imprimerie : il apparaît coché.
            'offres' => $consentement ? [self::WHATSAPP, self::SMS] : [],
        ];

        return self::filtrer($grille, $compte);
    }

    /**
     * Réglages de la personne, catégories interdites vidées.
     *
     * @return array<string, list<string>>
     */
    public static function de(CompteClient $compte): array
    {
        $enregistrees = $compte->getPreferencesNotifications();

        return null === $enregistrees ? self::parDefaut($compte) : self::filtrer($enregistrees + self::parDefaut($compte), $compte);
    }

    /** @return list<string> canaux cochés pour cette catégorie */
    public static function canaux(CompteClient $compte, string $categorie): array
    {
        return self::de($compte)[$categorie] ?? [];
    }

    /**
     * Grille envoyée par le formulaire (prefs[categorie][] = canal),
     * contrôlée : canaux et catégories connus seulement.
     *
     * @return array<string, list<string>>
     */
    public static function lire(mixed $saisie, CompteClient $compte): array
    {
        $saisie = \is_array($saisie) ? $saisie : [];
        $grille = [];
        foreach (array_keys(self::CATEGORIES) as $categorie) {
            $coches = \is_array($saisie[$categorie] ?? null) ? $saisie[$categorie] : [];
            $grille[$categorie] = array_values(array_filter(array_keys(self::CANAUX), static fn (string $c): bool => \in_array($c, $coches, true)));
        }

        return self::filtrer($grille, $compte);
    }

    /** La personne veut-elle recevoir les offres (sur un canal au moins) ? */
    public static function veutLesOffres(CompteClient $compte): bool
    {
        return [] !== self::canaux($compte, 'offres');
    }

    /**
     * Accord pour les offres (fiche client, consentementMarketingLe) après
     * qu'une personne a enregistré ses réglages : cocher « Offres » donne
     * l'accord ; le décocher le retire, sauf si une autre personne du
     * compte les reçoit encore. Sans changement de sa part, l'accord reste
     * tel quel (il a pu être donné à l'imprimerie).
     */
    public static function accordOffres(bool $avant, bool $apres, bool $autres, bool $accordActuel): bool
    {
        return match (true) {
            $apres && !$avant => true,
            !$apres && $avant => $autres,
            default => $accordActuel,
        };
    }

    /**
     * « Tout sauf factures », « factures et paiements »… (carte « Qui
     * reçoit quoi », maquette EC03).
     */
    public static function resume(CompteClient $compte): string
    {
        $prefs = self::de($compte);
        $recues = array_values(array_filter(array_keys(self::CATEGORIES), static fn (string $c): bool => 'offres' !== $c && [] !== ($prefs[$c] ?? [])));
        $toutes = array_values(array_diff(array_keys(self::CATEGORIES), ['offres']));
        if ([] === $recues) {
            return 'rien (consulte son espace)';
        }
        if ($recues === $toutes) {
            return 'tout';
        }
        $manquantes = array_values(array_diff($toutes, $recues));
        if (1 === \count($manquantes)) {
            return 'tout sauf '.mb_strtolower(self::CATEGORIES[$manquantes[0]]);
        }
        $libelles = array_map(static fn (string $c): string => 'factures' === $c ? 'factures et paiements' : mb_strtolower(self::CATEGORIES[$c]), $recues);

        return \count($libelles) > 1 ? implode(', ', \array_slice($libelles, 0, -1)).' et '.end($libelles) : $libelles[0];
    }

    public static function heuresCalmes(\DateTimeImmutable $le): bool
    {
        $heure = (int) $le->format('G');

        return $heure >= self::CALME_DEBUT || $heure < self::CALME_FIN;
    }

    /** Prochain 7 h (le même jour avant 7 h, sinon le lendemain). */
    public static function finHeuresCalmes(\DateTimeImmutable $le): \DateTimeImmutable
    {
        $sept = $le->setTime(self::CALME_FIN, 0);

        return $sept > $le ? $sept : $sept->modify('+1 day');
    }

    /**
     * Livraison du jour : le SMS part même pendant les heures calmes
     * (livreur en route, livrée, livraison prévue aujourd'hui).
     *
     * @param array{type: string, jour?: ?\DateTimeInterface} $evenement
     */
    public static function livraisonDuJour(array $evenement, \DateTimeImmutable $maintenant): bool
    {
        return match ($evenement['type']) {
            'route', 'livree' => true,
            'livraison_prevue' => null !== ($evenement['jour'] ?? null) && $evenement['jour']->format('Y-m-d') === $maintenant->format('Y-m-d'),
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $grille
     *
     * @return array<string, list<string>>
     */
    private static function filtrer(array $grille, CompteClient $compte): array
    {
        $permises = self::categoriesPermises($compte);
        $propre = [];
        foreach (array_keys(self::CATEGORIES) as $categorie) {
            $canaux = \is_array($grille[$categorie] ?? null) ? $grille[$categorie] : [];
            $propre[$categorie] = \in_array($categorie, $permises, true)
                ? array_values(array_filter(array_keys(self::CANAUX), static fn (string $c): bool => \in_array($c, $canaux, true)))
                : [];
        }

        return $propre;
    }
}
