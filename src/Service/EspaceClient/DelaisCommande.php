<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Service\ActionsCaisse\HeuresTravail;
use App\Service\Parametres\Parametres;

/**
 * Délais de la commande en ligne (docs/espace-client.md, « Nouvelle
 * commande ») : standard ou express après validation du BAT, date
 * « prête le… », créneaux de livraison et acompte. Réglages › Espace
 * client (délais, majoration, heure limite), Horaires (jours ouvrés,
 * fériés) et Paiements (acompte par défaut).
 *
 * Les calculs sont statiques et purs pour être testés sans base.
 */
final class DelaisCommande
{
    public const STANDARD_DEFAUT = 48;
    public const EXPRESS_DEFAUT = 24;
    public const MAJORATION_DEFAUT = 30;
    public const HEURE_LIMITE_DEFAUT = '16:00';
    public const ACOMPTE_DEFAUT = 50;

    public const MATIN = 'matin';
    public const APRES_MIDI = 'apres-midi';

    /** Jours de livraison proposés à partir du jour où la commande est prête. */
    public const JOURS_DE_CRENEAUX = 3;

    private const JOURS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    public function __construct(private readonly Parametres $parametres)
    {
    }

    /**
     * Réglages en vigueur.
     *
     * @return array{standard: int, express: bool, delaiExpress: int, majoration: int, limite: string, corrections: int}
     */
    public function reglages(): array
    {
        $v = fn (string $cle, mixed $defaut): mixed => $this->parametres->valeur('espace_client', $cle) ?? $defaut;
        $limite = (string) $v('heure_limite', self::HEURE_LIMITE_DEFAUT);

        return [
            'standard' => max(1, (int) $v('delai_standard', self::STANDARD_DEFAUT)),
            'express' => (bool) $v('express', true),
            'delaiExpress' => max(1, (int) $v('delai_express', self::EXPRESS_DEFAUT)),
            'majoration' => max(0, (int) $v('majoration_express', self::MAJORATION_DEFAUT)),
            'limite' => 1 === preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $limite) ? $limite : self::HEURE_LIMITE_DEFAUT,
            // Corrections de BAT gratuites (même réglage que le suivi du BAT, EspaceClient::correctionsBat).
            'corrections' => max(0, (int) $v('corrections_bat_gratuites', 2)),
        ];
    }

    /** Délai demandé, en heures après validation du BAT ; null pour une commande du comptoir. */
    public function heures(Commandes $commande): ?int
    {
        $r = $this->reglages();

        return match ($commande->getDelaiProduction()) {
            Commandes::DELAI_EXPRESS => $r['delaiExpress'],
            Commandes::DELAI_STANDARD => $r['standard'],
            default => null,
        };
    }

    /** Délai choisi par le client, ramené à ce qui est proposé (express coupé : standard). */
    public function delai(?string $demande): string
    {
        return Commandes::DELAI_EXPRESS === $demande && $this->reglages()['express'] ? Commandes::DELAI_EXPRESS : Commandes::DELAI_STANDARD;
    }

    /**
     * Date « prête le… » pour un délai, si le BAT est validé avant l'heure
     * limite du jour de départ.
     *
     * @return array{pret: \DateTimeImmutable, depart: \DateTimeImmutable}
     */
    public function pretLe(string $delai, \DateTimeImmutable $maintenant): array
    {
        $r = $this->reglages();

        return self::calculerPretLe($this->parametres->heuresTravail(), Commandes::DELAI_EXPRESS === $delai ? $r['delaiExpress'] : $r['standard'], $r['limite'], $maintenant);
    }

    /**
     * « Prêt le jeu. 23/10 si vous acceptez aujourd'hui » (maquette DB02) :
     * le délai écrit sur le devis (« 3 jours », « 48 h ») s'il se lit, sinon
     * le délai standard.
     *
     * @return array{pret: \DateTimeImmutable, jours: int}
     */
    public function pretSiAccepte(?string $delaiDevis, \DateTimeImmutable $maintenant): array
    {
        $r = $this->reglages();
        $heures = self::heuresDuTexte($delaiDevis) ?? $r['standard'];
        $calcul = self::calculerPretLe($this->parametres->heuresTravail(), $heures, $r['limite'], $maintenant);

        return ['pret' => $calcul['pret'], 'jours' => (int) ceil(max(1, $heures) / 24)];
    }

    /** « 3 jours ouvrés » : 72 ; « 48 h » : 48 ; illisible : null. */
    public static function heuresDuTexte(?string $texte): ?int
    {
        if (null === $texte || !preg_match('/(\d+)\s*(j|h)/iu', $texte, $m)) {
            return null;
        }
        $n = (int) $m[1];
        if ($n <= 0) {
            return null;
        }

        return 'h' === mb_strtolower($m[2]) ? $n : $n * 24;
    }

    /**
     * Le délai part aujourd'hui si c'est un jour ouvré et qu'il est avant
     * l'heure limite, sinon le jour ouvré suivant ; puis un jour ouvré par
     * tranche de 24 h commencée (48 h = 2 jours ouvrés).
     *
     * @return array{pret: \DateTimeImmutable, depart: \DateTimeImmutable}
     */
    public static function calculerPretLe(HeuresTravail $heures, int $delaiHeures, string $limite, \DateTimeImmutable $maintenant): array
    {
        [$h, $m] = array_map('intval', explode(':', $limite) + [1 => '0']);
        $jour = $maintenant->setTime(0, 0);
        $depart = $jour;
        if (!$heures->estOuvre($jour) || $maintenant >= $jour->setTime($h, $m)) {
            $depart = self::jourOuvreSuivant($heures, $jour);
        }
        $pret = $depart;
        for ($n = (int) ceil(max(1, $delaiHeures) / 24); $n > 0; --$n) {
            $pret = self::jourOuvreSuivant($heures, $pret);
        }

        return ['pret' => $pret, 'depart' => $depart];
    }

    /**
     * Créneaux de livraison : matin et après-midi des premiers jours ouvrés
     * à partir du jour où la commande est prête.
     *
     * @return list<array{valeur: string, libelle: string, jour: \DateTimeImmutable}>
     */
    public static function creneaux(HeuresTravail $heures, \DateTimeImmutable $pret): array
    {
        $creneaux = [];
        $jour = $pret->setTime(0, 0);
        for ($i = 0; $i < self::JOURS_DE_CRENEAUX; ++$i) {
            if ($i > 0 || !$heures->estOuvre($jour)) {
                $jour = self::jourOuvreSuivant($heures, $jour);
            }
            foreach ([self::MATIN => 'matin', self::APRES_MIDI => 'après-midi'] as $cle => $moment) {
                $creneaux[] = ['valeur' => $jour->format('Y-m-d').'|'.$cle, 'libelle' => self::jour($jour).' · '.$moment, 'jour' => $jour];
            }
        }

        return $creneaux;
    }

    /** @return list<array{valeur: string, libelle: string, jour: \DateTimeImmutable}> */
    public function creneauxPour(\DateTimeImmutable $pret): array
    {
        return self::creneaux($this->parametres->heuresTravail(), $pret);
    }

    /**
     * Le créneau envoyé par le client, s'il fait partie de ceux proposés.
     *
     * @param list<array{valeur: string, libelle: string, jour: \DateTimeImmutable}> $proposes
     *
     * @return array{valeur: string, libelle: string, jour: \DateTimeImmutable}|null
     */
    public static function creneauChoisi(string $valeur, array $proposes): ?array
    {
        foreach ($proposes as $c) {
            if ($c['valeur'] === $valeur) {
                return $c;
            }
        }

        return null;
    }

    /** « samedi 17/10 » */
    public static function jour(\DateTimeImmutable $jour): string
    {
        return self::JOURS[(int) $jour->format('N')].' '.$jour->format('d/m');
    }

    /** Condition de la date « prête le… » : « aujourd'hui », « demain » ou « lundi 19/10 ». */
    public static function quand(\DateTimeImmutable $depart, \DateTimeImmutable $maintenant): string
    {
        $jours = (int) $maintenant->setTime(0, 0)->diff($depart->setTime(0, 0))->format('%r%a');

        return match ($jours) {
            0 => 'aujourd\'hui',
            1 => 'demain',
            default => self::jour($depart),
        };
    }

    /** Acompte demandé à ce client, en % : celui de sa fiche, sinon celui des Réglages. */
    public function pourcentageAcompte(Clients $client): int
    {
        // Client dispensé d'acompte (fiche client, direction) : rien d'avance, partout.
        if ($client->isSansAcompte()) {
            return 0;
        }
        $pourcentage = $client->getAcompte() ?? (int) ($this->parametres->valeur('paiements', 'acompte') ?? self::ACOMPTE_DEFAUT);

        return max(0, min(100, $pourcentage));
    }

    /** Acompte en F, arrondi au franc supérieur (comme à la caisse). */
    public static function acompte(int $total, int $pourcentage): int
    {
        $total = max(0, $total);

        return (int) min($total, ceil($total * max(0, min(100, $pourcentage)) / 100));
    }

    /**
     * « Validez avant lundi 16:00 pour garder vos dates » (maquettes DB04 et
     * DB06) : le dernier moment où valider le BAT garde la date prévue de
     * la commande ; sinon la date qu'aurait un BAT validé maintenant.
     *
     * @return array{limite: ?\DateTimeImmutable, pret: \DateTimeImmutable, tenue: bool}
     */
    public function limiteBat(Commandes $commande, \DateTimeImmutable $maintenant): array
    {
        $r = $this->reglages();

        return self::calculerLimiteBat($this->parametres->heuresTravail(), $this->heures($commande) ?? $r['standard'], $r['limite'], $commande->getDateLivraison(), $maintenant);
    }

    /** @return array{limite: ?\DateTimeImmutable, pret: \DateTimeImmutable, tenue: bool} */
    public static function calculerLimiteBat(HeuresTravail $heures, int $delaiHeures, string $limite, ?\DateTimeImmutable $prevue, \DateTimeImmutable $maintenant): array
    {
        $siMaintenant = self::calculerPretLe($heures, $delaiHeures, $limite, $maintenant)['pret'];
        if (null === $prevue || $prevue->setTime(0, 0) < $siMaintenant) {
            // Pas de date promise, ou déjà dépassée : la date que donne une validation maintenant.
            return ['limite' => null, 'pret' => $siMaintenant, 'tenue' => null === $prevue];
        }
        [$h, $m] = array_map('intval', explode(':', $limite) + [1 => '0']);
        $jour = $prevue->setTime(0, 0);
        for ($n = (int) ceil(max(1, $delaiHeures) / 24); $n > 0; --$n) {
            $jour = self::jourOuvrePrecedent($heures, $jour);
        }

        return ['limite' => max($jour->setTime($h, $m), $maintenant), 'pret' => $prevue->setTime(0, 0), 'tenue' => true];
    }

    /** « lun. 16:00 » */
    public static function limiteCourte(\DateTimeImmutable $d): string
    {
        return mb_substr(self::JOURS[(int) $d->format('N')], 0, 3).'. '.$d->format('H:i');
    }

    private static function jourOuvrePrecedent(HeuresTravail $heures, \DateTimeImmutable $jour): \DateTimeImmutable
    {
        for ($i = 0; $i < 366; ++$i) {
            $jour = $jour->modify('-1 day');
            if ($heures->estOuvre($jour)) {
                return $jour;
            }
        }

        return $jour;
    }

    private static function jourOuvreSuivant(HeuresTravail $heures, \DateTimeImmutable $jour): \DateTimeImmutable
    {
        // Une année sans jour ouvré n'existe pas : la boucle s'arrête au plus tard après un an.
        for ($i = 0; $i < 366; ++$i) {
            $jour = $jour->modify('+1 day');
            if ($heures->estOuvre($jour)) {
                return $jour;
            }
        }

        return $jour;
    }
}
