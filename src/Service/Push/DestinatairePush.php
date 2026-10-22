<?php

namespace App\Service\Push;

/**
 * Navigateur abonné aux notifications : adresse de son service de
 * notification et ses deux clés de chiffrement (personnel ou client).
 */
interface DestinatairePush
{
    public function getAdresse(): string;

    public function getClePublique(): string;

    public function getSecret(): string;
}
