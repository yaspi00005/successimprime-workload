<?php

namespace App\Service\Rapports;

use App\Service\Parametres\Parametres;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;

/**
 * Réglages des rapports par e-mail (tuile « Rapports par e-mail » des
 * Paramètres, Direction seulement ; docs/rapports-mail.md) : chaque
 * rapport oui ou non, son jour et son heure, le PDF joint, la liste des
 * destinataires, et si l'envoi d'e-mails est réglé sur cette machine.
 */
final class ReglagesRapportsMail
{
    public const TUILE = 'rapports_mail';

    public function __construct(
        private readonly Parametres $parametres,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $mailerDsn,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')] private readonly string $expediteur,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
    }

    public function actif(string $type): bool
    {
        return PeriodeRapport::estType($type) && (bool) $this->parametres->valeur(self::TUILE, $type.'_actif');
    }

    public function heure(string $type): string
    {
        $heure = (string) $this->parametres->valeur(self::TUILE, $type.'_heure');

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $heure) ? $heure : (PeriodeRapport::JOUR === $type ? '20:00' : '07:00');
    }

    /** 1 (lundi) à 7, jour d'envoi du rapport de la semaine. */
    public function jourSemaine(): int
    {
        $jour = array_search((string) $this->parametres->valeur(self::TUILE, 'semaine_jour'), PeriodeRapport::JOURS, true);

        return false === $jour ? 1 : (int) $jour;
    }

    /** 1 à 28, jour d'envoi du rapport du mois. */
    public function jourMois(): int
    {
        return max(1, min(28, (int) $this->parametres->valeur(self::TUILE, 'mois_jour')));
    }

    public function pieceJointe(): bool
    {
        return (bool) $this->parametres->valeur(self::TUILE, 'pdf');
    }

    /**
     * Adresse de l'ERP sur le réseau de l'atelier, pour le bouton de
     * l'e-mail (« http://192.168.1.10/successImprim/public ») ; null si
     * elle manque ou n'est pas une adresse http(s).
     */
    public function adresseErp(): ?string
    {
        return self::lireAdresse((string) $this->parametres->valeur(self::TUILE, 'adresse_erp'));
    }

    public static function lireAdresse(string $texte): ?string
    {
        $texte = rtrim(trim($texte), '/');
        if ('' === $texte || false === filter_var($texte, \FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $texte)) {
            return null;
        }

        return $texte;
    }

    public function planification(string $type): Planification
    {
        return new Planification($this->heure($type), $this->jourSemaine(), $this->jourMois());
    }

    /**
     * Jours où l'atelier ouvre (tuile Horaires) quand le rapport du jour
     * s'y limite ; null : tous les jours.
     *
     * @return (\Closure(\DateTimeImmutable): bool)|null
     */
    public function joursOuverts(): ?\Closure
    {
        if (!(bool) $this->parametres->valeur(self::TUILE, 'jour_ouvres')) {
            return null;
        }
        $heures = $this->parametres->heuresTravail();

        return static fn (\DateTimeImmutable $jour): bool => $heures->estOuvre($jour);
    }

    /** La période dont le rapport doit être parti à ce moment. */
    public function derniereDue(string $type, \DateTimeImmutable $maintenant): PeriodeRapport
    {
        return $this->planification($type)->derniereDue($type, $maintenant, $this->joursOuverts());
    }

    /** La période que la tâche planifiée doit envoyer maintenant, s'il y en a une. */
    public function aEnvoyer(string $type, \DateTimeImmutable $maintenant): ?PeriodeRapport
    {
        return $this->actif($type) ? $this->planification($type)->aEnvoyer($type, $maintenant, $this->joursOuverts()) : null;
    }

    public function prochainEnvoi(string $type, \DateTimeImmutable $maintenant): \DateTimeImmutable
    {
        return $this->planification($type)->prochain($type, $maintenant, $this->joursOuverts());
    }

    /**
     * « Chaque jour d'ouverture à 20 h », « Le lendemain de chaque jour
     * d'ouverture à 7 h, pour la veille », « Chaque lundi à 7 h », « Le 1er
     * du mois à 7 h ».
     */
    public function libelle(string $type): string
    {
        $heure = Planification::heureLisible($this->heure($type));
        $ouvres = (bool) $this->parametres->valeur(self::TUILE, 'jour_ouvres');

        return match ($type) {
            PeriodeRapport::JOUR => Planification::lendemain($this->heure($type))
                ? ($ouvres ? 'Le lendemain de chaque jour d’ouverture' : 'Chaque jour').' à '.$heure.', pour la veille'
                : ($ouvres ? 'Chaque jour d’ouverture' : 'Chaque jour').' à '.$heure.', pour la journée',
            PeriodeRapport::SEMAINE => 'Chaque '.PeriodeRapport::JOURS[$this->jourSemaine()].' à '.$heure.', pour la semaine passée',
            default => 'Le '.(1 === $this->jourMois() ? '1er' : $this->jourMois()).' du mois à '.$heure.', pour le mois passé',
        };
    }

    /**
     * Comptes choisis comme destinataires ; null tant que la direction n'a
     * pas fait de choix (la direction reçoit alors, voir Destinataires).
     *
     * @return list<int>|null
     */
    public function destinatairesChoisis(): ?array
    {
        if (!(bool) $this->parametres->valeur(self::TUILE, 'destinataires_choisis')) {
            return null;
        }

        return self::lireIds((string) $this->parametres->valeur(self::TUILE, 'destinataires'));
    }

    /** « 12, 5, 12, x » → [5, 12]. */
    public static function lireIds(string $texte): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,;]+/', $texte, -1, \PREG_SPLIT_NO_EMPTY) ?: []), static fn (int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    /** @param list<int> $ids */
    public static function ecrireIds(array $ids): string
    {
        $ids = self::lireIds(implode(',', $ids));

        return implode(', ', $ids);
    }

    /**
     * L'envoi d'e-mails est-il réglé ? Il faut MAILER_FROM_ADDRESS et un
     * MAILER_DSN. En production, « null:// » (rien ne part) vaut « pas
     * réglé » ; en développement et en tests, il laisse tout essayer sans
     * qu'aucun e-mail ne sorte.
     */
    public function courrielConfigure(): bool
    {
        return self::configure($this->mailerDsn, $this->expediteur, $this->environnement);
    }

    /** Transport de test : les e-mails « partent » mais n'arrivent nulle part. */
    public function transportFactice(): bool
    {
        return str_starts_with(trim($this->mailerDsn), 'null://');
    }

    /** L'expéditeur, avec le nom « Succès Imprim » s'il n'en a pas. */
    public function expediteur(): ?Address
    {
        return self::adresseExpediteur($this->expediteur);
    }

    public static function adresseExpediteur(string $texte): ?Address
    {
        try {
            $adresse = Address::create(trim($texte));
        } catch (\Throwable) {
            return null;
        }

        return '' === $adresse->getName() ? new Address($adresse->getAddress(), 'Succès Imprim') : $adresse;
    }

    public static function configure(string $dsn, string $expediteur, string $environnement): bool
    {
        $dsn = trim($dsn);
        if ('' === $dsn || null === self::adresseExpediteur($expediteur)) {
            return false;
        }

        return !str_starts_with($dsn, 'null://') || 'prod' !== $environnement;
    }
}
