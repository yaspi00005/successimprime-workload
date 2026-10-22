<?php

namespace App\Service\Messagerie;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ActionsCaisse\HeuresTravail;
use App\Service\Parametres\Parametres;

/**
 * Règles de réponse aux messages des clients (maquette MS04, tuile
 * « Règles de réponse aux messages ») : délais cibles en minutes ouvrées,
 * qui répond, escalade, pénalité, clôture automatique, réouverture. Les
 * heures ouvrées sont celles de l'atelier (tuile Horaires, pause et jours
 * fériés compris) : hors de ces heures, le chronomètre est en pause.
 */
final class ReglesReponse
{
    public const TUILE = 'messagerie';

    private ?HeuresTravail $heures = null;

    public function __construct(
        private readonly Parametres $parametres,
        private readonly UserRepository $utilisateurs,
    ) {
    }

    private function nombre(string $cle, int $defaut): int
    {
        $v = $this->parametres->valeur(self::TUILE, $cle);

        return is_numeric($v) ? (int) $v : $defaut;
    }

    public function premiere(): int { return $this->nombre('premiere_minutes', 30); }
    public function suivantes(): int { return $this->nombre('suivantes_minutes', 60); }
    public function escalade(): int { return $this->nombre('escalade_minutes', 120); }
    public function penalite(): int { return $this->nombre('penalite_points', 5); }
    public function clotureAutoHeures(): int { return $this->nombre('cloture_auto_heures', 72); }
    public function reouvertureJours(): int { return $this->nombre('reouverture_jours', 7); }

    /** Trésorière choisie (paiement, facture) ; null : la caisse répond. */
    public function tresoriere(): ?User
    {
        $id = $this->nombre('tresoriere', 0);
        $u = $id > 0 ? $this->utilisateurs->find($id) : null;

        return $u instanceof User && $u->isActif() ? $u : null;
    }

    public function heures(): HeuresTravail
    {
        return $this->heures ??= $this->parametres->heuresTravail();
    }

    /** « lun – sam · 8 h – 18 h » */
    public function heuresOuvrees(): string
    {
        return self::texteHeures((string) $this->parametres->valeur('horaires', 'jours'), (string) $this->parametres->valeur('horaires', 'ouverture'), (string) $this->parametres->valeur('horaires', 'fermeture'));
    }

    /**
     * Phrase sous la saisie du client : « Réponse en général sous 30 min, du
     * lundi au samedi de 8 h à 18 h. »
     */
    public function texteClient(): string
    {
        $jours = match ((string) $this->parametres->valeur('horaires', 'jours')) {
            'lun-ven' => 'du lundi au vendredi',
            'lun-dim' => 'tous les jours',
            default => 'du lundi au samedi',
        };

        return \sprintf('Réponse en général sous %s, %s de %s à %s.', self::duree($this->premiere()), $jours, self::heure((string) $this->parametres->valeur('horaires', 'ouverture')), self::heure((string) $this->parametres->valeur('horaires', 'fermeture')));
    }

    /**
     * Le chronomètre et le délai cible d'une attente.
     *
     * @return array{attente: int, cible: int, retard: int}
     */
    public function mesurer(\DateTimeImmutable $depuis, \DateTimeImmutable $maintenant, bool $premiere): array
    {
        $attente = $this->heures()->ecoule($depuis, $maintenant);
        $cible = $premiere ? $this->premiere() : $this->suivantes();

        return ['attente' => $attente, 'cible' => $cible, 'retard' => max(0, $attente - $cible)];
    }

    /** 4 → « 4 min », 72 → « 1 h 12 », 120 → « 2 h ». */
    public static function duree(int $minutes): string
    {
        $minutes = max(0, $minutes);
        if ($minutes < 60) {
            return $minutes.' min';
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $h.' h'.($m > 0 ? ' '.str_pad((string) $m, 2, '0', \STR_PAD_LEFT) : '');
    }

    public static function texteHeures(string $jours, string $ouverture, string $fermeture): string
    {
        $j = ['lun-ven' => 'lun – ven', 'lun-dim' => 'lun – dim'][$jours] ?? 'lun – sam';

        return \sprintf('%s · %s – %s', $j, self::heure($ouverture), self::heure($fermeture));
    }

    /** « 08:00 » → « 8 h », « 17:30 » → « 17 h 30 ». */
    public static function heure(string $hhmm): string
    {
        if (1 !== preg_match('/^(\d{1,2}):(\d{2})/', $hhmm, $m)) {
            return $hhmm;
        }

        return (int) $m[1].' h'.('00' !== $m[2] ? ' '.$m[2] : '');
    }
}
