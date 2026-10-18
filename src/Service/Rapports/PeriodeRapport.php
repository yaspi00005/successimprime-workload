<?php

namespace App\Service\Rapports;

use App\Service\Finances\Periode;
use App\Service\Statistiques\SemaineIso;

/**
 * La période d'un rapport par e-mail (docs/rapports-mail.md) : un jour
 * (0 h → 24 h), une semaine ISO (lundi 0 h → lundi suivant 0 h) ou un
 * mois (le 1er 0 h → le 1er du mois suivant 0 h). La fin est exclue.
 *
 * Codes : « 2026-10-17 », « 2026-W42 », « 2026-10 » (historique,
 * option --periode de app:rapports-mail, adresse de l'aperçu).
 */
final class PeriodeRapport
{
    public const JOUR = 'jour';
    public const SEMAINE = 'semaine';
    public const MOIS = 'mois';

    /** Les trois rapports, dans l'ordre de l'écran. */
    public const TYPES = [
        self::JOUR => 'Rapport du jour',
        self::SEMAINE => 'Rapport de la semaine',
        self::MOIS => 'Rapport du mois',
    ];

    public const ADJECTIFS = [
        self::JOUR => 'journalier',
        self::SEMAINE => 'hebdomadaire',
        self::MOIS => 'mensuel',
    ];

    public const JOURS = [1 => 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];

    private function __construct(
        public readonly string $type,
        public readonly \DateTimeImmutable $debut,
        public readonly \DateTimeImmutable $fin,
    ) {
    }

    /** La période de ce type qui contient ce moment. */
    public static function contenant(string $type, \DateTimeImmutable $moment): self
    {
        $jour = $moment->setTime(0, 0);

        return match ($type) {
            self::JOUR => new self(self::JOUR, $jour, $jour->modify('+1 day')),
            self::SEMAINE => self::deSemaine(SemaineIso::contenant($jour)),
            self::MOIS => new self(self::MOIS, $jour->modify('first day of this month'), $jour->modify('first day of next month')),
            default => throw new \InvalidArgumentException('Type de rapport inconnu : '.$type),
        };
    }

    public static function deSemaine(SemaineIso $semaine): self
    {
        return new self(self::SEMAINE, $semaine->debut, $semaine->fin());
    }

    /** Lit un code (« 2026-10-17 », « 2026-W42 », « 2026-10 ») ; null s'il est faux. */
    public static function lire(string $type, string $code, ?\DateTimeZone $fuseau = null): ?self
    {
        $code = trim($code);
        $fuseau ??= new \DateTimeZone(date_default_timezone_get());

        switch ($type) {
            case self::JOUR:
                $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', $code, $fuseau);

                return false !== $jour && $jour->format('Y-m-d') === $code ? self::contenant(self::JOUR, $jour) : null;
            case self::SEMAINE:
                $semaine = SemaineIso::lire($code, $fuseau);

                return null === $semaine ? null : self::deSemaine($semaine);
            case self::MOIS:
                $mois = \DateTimeImmutable::createFromFormat('!Y-m', $code, $fuseau);

                return false !== $mois && $mois->format('Y-m') === $code ? self::contenant(self::MOIS, $mois) : null;
            default:
                return null;
        }
    }

    public static function estType(string $type): bool
    {
        return isset(self::TYPES[$type]);
    }

    public function code(): string
    {
        return match ($this->type) {
            self::JOUR => $this->debut->format('Y-m-d'),
            self::SEMAINE => $this->semaine()->code(),
            default => $this->debut->format('Y-m'),
        };
    }

    /** La période juste avant (la veille, la semaine d'avant, le mois d'avant). */
    public function avant(): self
    {
        return self::contenant($this->type, $this->debut->modify('-1 day'));
    }

    /** La période juste après. */
    public function apres(): self
    {
        return self::contenant($this->type, $this->fin);
    }

    /**
     * La période à laquelle on compare : le même jour de la semaine
     * précédente (un samedi avec le samedi d'avant, pas avec le vendredi),
     * la semaine ou le mois d'avant.
     */
    public function comparaison(): self
    {
        return self::JOUR === $this->type ? self::contenant(self::JOUR, $this->debut->modify('-7 days')) : $this->avant();
    }

    /** La période est-elle terminée à ce moment ? */
    public function terminee(\DateTimeImmutable $maintenant): bool
    {
        return $maintenant >= $this->fin;
    }

    /** Dernier jour de la période. */
    public function dernierJour(): \DateTimeImmutable
    {
        return $this->fin->modify('-1 day');
    }

    /** « Rapport du jour ». */
    public function titre(): string
    {
        return self::TYPES[$this->type];
    }

    /**
     * « samedi 17 octobre 2026 », « semaine 42, du 12 au 18 octobre 2026 »,
     * « octobre 2026 ».
     */
    public function libelle(): string
    {
        return match ($this->type) {
            self::JOUR => self::jourLong($this->debut),
            self::SEMAINE => 'semaine '.$this->semaine()->numero.', '.$this->semaine()->libelle(),
            default => Periode::libelle($this->debut),
        };
    }

    /** Ce à quoi on compare, pour les écarts : « vs samedi 10/10 », « vs semaine 41 », « vs septembre ». */
    public function libelleComparaison(): string
    {
        $c = $this->comparaison();

        return match ($this->type) {
            self::JOUR => self::JOURS[(int) $c->debut->format('N')].' '.$c->debut->format('d/m'),
            self::SEMAINE => 'semaine '.$c->semaine()->numero,
            default => Periode::MOIS[(int) $c->debut->format('n')],
        };
    }

    /** « Rapport du jour · samedi 17 octobre 2026 ». */
    public function sujet(): string
    {
        return match ($this->type) {
            self::SEMAINE => 'Rapport de la semaine '.$this->semaine()->numero.' · '.$this->semaine()->libelle(),
            default => $this->titre().' · '.$this->libelle(),
        };
    }

    /** « samedi 17 octobre 2026 », « 1er octobre ». */
    public static function jourLong(\DateTimeImmutable $jour, bool $annee = true): string
    {
        $numero = '1' === $jour->format('j') ? '1er' : $jour->format('j');

        return self::JOURS[(int) $jour->format('N')].' '.$numero.' '.Periode::MOIS[(int) $jour->format('n')].($annee ? ' '.$jour->format('Y') : '');
    }

    private function semaine(): SemaineIso
    {
        return SemaineIso::contenant($this->debut);
    }
}
