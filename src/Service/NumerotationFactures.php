<?php

namespace App\Service;

use App\Entity\Entreprise;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Numérotation des factures propre à chaque entreprise (maquette I03) :
 * F-SI-{AAAA}-{0000} donne F-SI-2026-0112.
 *
 * Tant qu'une entreprise n'a pas de format, la numérotation actuelle
 * continue : on change de format au moment choisi, de préférence en
 * début d'année.
 */
final class NumerotationFactures
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Prend le numéro suivant de l'entreprise, ou null si elle n'a pas
     * de format. Le compteur est verrouillé pendant la transaction :
     * deux factures simultanées ne peuvent pas avoir le même numéro.
     */
    public function prochain(Entreprise $entreprise, \DateTimeInterface $date): ?string
    {
        if ($entreprise->getFormatNumero() === null) {
            return null;
        }

        return $this->em->wrapInTransaction(function () use ($entreprise, $date): string {
            $this->em->lock($entreprise, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($entreprise);

            [$compteur, $annee] = self::suivant($entreprise->getFormatNumero(), $entreprise->getCompteur(), $entreprise->getCompteurAnnee(), $date);
            $entreprise->avancerCompteur($compteur, $annee);
            $this->em->flush();

            return self::formater($entreprise->getFormatNumero(), $compteur, $date);
        });
    }

    /**
     * Le compteur repart à 1 au changement d'année quand le format
     * contient l'année.
     *
     * @return array{0: int, 1: ?int} compteur et année du compteur
     */
    public static function suivant(string $format, int $compteur, ?int $annee, \DateTimeInterface $date): array
    {
        $anneeDate = (int) $date->format('Y');

        if (self::contientAnnee($format)) {
            return [$annee === $anneeDate ? $compteur + 1 : 1, $anneeDate];
        }

        return [$compteur + 1, null];
    }

    /**
     * {AAAA} année, {AA} année courte, {MM} mois, {0000} compteur sur
     * autant de chiffres que de zéros.
     */
    public static function formater(string $format, int $compteur, \DateTimeInterface $date): string
    {
        $numero = strtr($format, [
            '{AAAA}' => $date->format('Y'),
            '{AA}' => $date->format('y'),
            '{MM}' => $date->format('m'),
        ]);

        return (string) preg_replace_callback('/\{(0+)\}/', static fn (array $m) => str_pad((string) $compteur, \strlen($m[1]), '0', \STR_PAD_LEFT), $numero);
    }

    public static function formatValide(string $format): bool
    {
        return preg_match('/\{0+\}/', $format) === 1 && preg_match('/^[A-Za-z0-9{}\-_\/]{3,60}$/', $format) === 1;
    }

    private static function contientAnnee(string $format): bool
    {
        return str_contains($format, '{AAAA}') || str_contains($format, '{AA}');
    }
}
