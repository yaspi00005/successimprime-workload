<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Codes promo tapés par le client dans son espace (docs/promotions.md,
 * « Codes promo ») : un code de lot fait 6 caractères tirés au hasard ; un
 * compte ou une connexion ne peut pas en essayer indéfiniment. Seuls les
 * codes refusés comptent (comme EssaisCodeFacture) : un client qui tape un
 * bon code n'est jamais freiné.
 */
final class EssaisCodePromo
{
    public function __construct(
        #[Autowire(service: 'limiter.code_promo_client')] private readonly RateLimiterFactoryInterface $parClient,
        #[Autowire(service: 'limiter.code_promo_ip')] private readonly RateLimiterFactoryInterface $parConnexion,
    ) {
    }

    /** Trop de codes refusés pour ce compte ou cette connexion : plus d'essai jusqu'à la fin de la fenêtre. */
    public function bloque(int $client, string $ip): bool
    {
        return 0 === $this->parClient->create('client-'.$client)->consume(0)->getRemainingTokens()
            || 0 === $this->parConnexion->create($ip)->consume(0)->getRemainingTokens();
    }

    public function noterRefus(int $client, string $ip): void
    {
        $this->parClient->create('client-'.$client)->consume(1);
        $this->parConnexion->create($ip)->consume(1);
    }
}
