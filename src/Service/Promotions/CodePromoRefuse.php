<?php

namespace App\Service\Promotions;

/**
 * Code promo refusé dans l'espace client : le message est celui du client
 * (CalculCodePromo::message), la raison reste pour le compte des essais.
 */
final class CodePromoRefuse extends \DomainException
{
    public function __construct(public readonly string $refus)
    {
        parent::__construct(CalculCodePromo::message($refus, false));
    }
}
