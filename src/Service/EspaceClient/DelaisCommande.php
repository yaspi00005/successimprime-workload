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
     * @return array{standard: int, express: bool, delaiExpress: int, majoration: int, limite: string}
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
        $pourcentage = $client->getAcompte() ?? (int) ($this->parametres->valeur('paiements', 'acompte') ?? self::ACOMPTE_DEFAUT);

        return max(0, min(100, $pourcentage));
    }

    /** Acompte en F, arrondi au franc supérieur (comme à la caisse). */
    public static function acompte(int $total, int $pourcentage): int
    {
        $total = max(0, $total);

        return (int) min($total, ceil($total * max(0, min(100, $pourcentage)) / 100));
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
