<?php

namespace App\Service\Statistiques;

use App\Service\Finances\Periode;

/**
 * Une semaine ISO (du lundi 0 h au lundi suivant 0 h), notée « 2026-W41 »
 * comme le paramètre --semaine du rapport de la semaine.
 */
final class SemaineIso
{
    private function __construct(
        public readonly int $annee,
        public readonly int $numero,
        public readonly \DateTimeImmutable $debut,
    ) {
    }

    /** « 2026-W41 » (ou « 2026-w41 »), null si la semaine n'existe pas. */
    public static function lire(string $texte, ?\DateTimeZone $fuseau = null): ?self
    {
        if (!preg_match('/^(\d{4})-W(\d{2})$/i', trim($texte), $m)) {
            return null;
        }
        $annee = (int) $m[1];
        $numero = (int) $m[2];
        if ($numero < 1 || $numero > self::semainesDansAnnee($annee)) {
            return null;
        }

        return new self($annee, $numero, (new \DateTimeImmutable('now', $fuseau))->setISODate($annee, $numero, 1)->setTime(0, 0));
    }

    /** La semaine qui contient ce jour. */
    public static function contenant(\DateTimeImmutable $jour): self
    {
        $lundi = $jour->setTime(0, 0)->modify('-'.((int) $jour->format('N') - 1).' days');

        return new self((int) $lundi->format('o'), (int) $lundi->format('W'), $lundi);
    }

    /** La semaine complète qui précède celle de ce jour (le rapport du lundi). */
    public static function precedente(\DateTimeImmutable $maintenant): self
    {
        return self::contenant(self::contenant($maintenant)->debut->modify('-7 days'));
    }

    /** 52, ou 53 quand le 28 décembre tombe en semaine 53. */
    public static function semainesDansAnnee(int $annee): int
    {
        return (int) (new \DateTimeImmutable())->setDate($annee, 12, 28)->format('W');
    }

    public function code(): string
    {
        return \sprintf('%04d-W%02d', $this->annee, $this->numero);
    }

    /** Lundi suivant, 0 h (borne exclue). */
    public function fin(): \DateTimeImmutable
    {
        return $this->debut->modify('+7 days');
    }

    /** Dimanche, dernier jour de la semaine. */
    public function dimanche(): \DateTimeImmutable
    {
        return $this->debut->modify('+6 days');
    }

    public function avant(): self
    {
        return self::contenant($this->debut->modify('-7 days'));
    }

    public function apres(): self
    {
        return self::contenant($this->debut->modify('+7 days'));
    }

    /** « du 5 au 11 octobre 2026 », « du 28 septembre au 4 octobre 2026 ». */
    public function libelle(): string
    {
        $dimanche = $this->dimanche();
        $memeMois = $this->debut->format('Y-m') === $dimanche->format('Y-m');
        $memeAnnee = $this->debut->format('Y') === $dimanche->format('Y');
        $jour = static fn (\DateTimeImmutable $d): string => '1' === $d->format('j') ? '1er' : $d->format('j');

        $debut = $jour($this->debut).($memeMois ? '' : ' '.Periode::MOIS[(int) $this->debut->format('n')].($memeAnnee ? '' : ' '.$this->debut->format('Y')));

        return \sprintf('du %s au %s %s %s', $debut, $jour($dimanche), Periode::MOIS[(int) $dimanche->format('n')], $dimanche->format('Y'));
    }

    /** « Semaine 41 ». */
    public function titre(): string
    {
        return 'Semaine '.$this->numero;
    }
}
