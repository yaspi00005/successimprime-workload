<?php

namespace App\Service\Statistiques;

use App\Service\Parametres\Parametres;

/**
 * Réglages du rapport de la semaine (tuile « Objectifs et rapport de la
 * semaine » des Paramètres, Direction seulement) : envoi ou non, jour et
 * heure, destinataires, e-mail en plus de la notification.
 */
final class ReglagesRapport
{
    public const TUILE = 'statistiques';

    public const JOURS = ['lundi' => 1, 'mardi' => 2, 'mercredi' => 3, 'jeudi' => 4, 'vendredi' => 5, 'samedi' => 6, 'dimanche' => 7];

    public function __construct(private readonly Parametres $parametres)
    {
    }

    public function actif(): bool
    {
        return (bool) $this->parametres->valeur(self::TUILE, 'rapport_actif');
    }

    public function jour(): string
    {
        $jour = (string) $this->parametres->valeur(self::TUILE, 'rapport_jour');

        return isset(self::JOURS[$jour]) ? $jour : 'lundi';
    }

    public function heure(): string
    {
        $heure = (string) $this->parametres->valeur(self::TUILE, 'rapport_heure');

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $heure) ? $heure : '08:00';
    }

    public function pourDirection(): bool
    {
        return (bool) $this->parametres->valeur(self::TUILE, 'pour_direction');
    }

    public function pourAgents(): bool
    {
        return (bool) $this->parametres->valeur(self::TUILE, 'pour_agents');
    }

    public function parCourriel(): bool
    {
        return (bool) $this->parametres->valeur(self::TUILE, 'par_courriel');
    }

    /** Moment de l'envoi dans la semaine qui commence ce lundi. */
    public static function momentEnvoi(\DateTimeImmutable $lundi, string $jour, string $heure): \DateTimeImmutable
    {
        [$h, $m] = array_map('intval', explode(':', $heure) + [1 => '0']);

        return $lundi->setTime(0, 0)->modify('+'.((self::JOURS[$jour] ?? 1) - 1).' days')->setTime($h, $m);
    }

    /** « Chaque lundi à 8 h dans l'application ». */
    public function libelle(): string
    {
        if (!$this->actif()) {
            return 'Envoi désactivé (Réglages)';
        }
        [$h, $m] = array_map('intval', explode(':', $this->heure()));

        return \sprintf('Chaque %s à %d h%s dans l’application', $this->jour(), $h, $m > 0 ? \sprintf(' %02d', $m) : '');
    }
}
