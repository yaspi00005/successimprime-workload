<?php

namespace App\Service\Bascule;

/**
 * La correction s'est arrêtée. Sans $sauvegarde, rien n'a été modifié ;
 * sinon la base est peut-être à moitié modifiée et cette sauvegarde, faite
 * juste avant, est celle à remettre.
 */
final class BasculeInterrompue extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $sauvegarde = null, ?\Throwable $precedente = null)
    {
        parent::__construct($message, 0, $precedente);
    }
}
