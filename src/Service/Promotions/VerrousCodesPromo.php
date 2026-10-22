<?php

namespace App\Service\Promotions;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Events;

/**
 * Deux caisses qui enregistrent le même code au même instant ne doivent
 * pas dépasser sa limite : avant de compter les utilisations d'un code,
 * PromotionsSaisie prend un verrou nommé de MariaDB (GET_LOCK) sur ce code.
 * L'autre caisse attend que le document soit enregistré, puis compte avec
 * lui. Les verrous sont rendus après l'enregistrement (postFlush), et de
 * toute façon à la fin de la requête (fin de la connexion).
 */
#[AsDoctrineListener(event: Events::postFlush)]
final class VerrousCodesPromo
{
    /** Secondes d'attente au plus. */
    private const ATTENTE = 8;

    /** @var array<string, true> */
    private array $tenus = [];

    public function __construct(private readonly Connection $connexion)
    {
    }

    /** @throws \DomainException si l'autre caisse ne rend pas la main à temps */
    public function prendre(int $code): void
    {
        $nom = 'si_code_promo_'.$code;
        if (isset($this->tenus[$nom])) {
            return;
        }
        if (1 !== (int) $this->connexion->fetchOne('SELECT GET_LOCK(?, ?)', [$nom, self::ATTENTE])) {
            throw new \DomainException('Code promo : il est en cours d\'enregistrement sur un autre poste. Réessayez dans un instant.');
        }
        $this->tenus[$nom] = true;
    }

    public function postFlush(): void
    {
        foreach (array_keys($this->tenus) as $nom) {
            try {
                $this->connexion->fetchOne('SELECT RELEASE_LOCK(?)', [$nom]);
            } catch (\Throwable) {
                // La connexion fermée rend aussi le verrou.
            }
        }
        $this->tenus = [];
    }
}
