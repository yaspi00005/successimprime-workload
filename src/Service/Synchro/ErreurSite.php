<?php

namespace App\Service\Synchro;

/** Le site a répondu, mais pas 200 : son code HTTP et la cause à afficher (CauseSynchro). */
final class ErreurSite extends \RuntimeException
{
    public function __construct(string $message, public readonly int $codeHttp, public readonly string $cause)
    {
        parent::__construct($message, $codeHttp);
    }
}
