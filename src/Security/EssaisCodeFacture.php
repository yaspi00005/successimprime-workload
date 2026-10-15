<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Suivi de facture par QR (/f/{code}, page publique) : le code de 6
 * caractères suffit, donc une même connexion ne peut pas en essayer au
 * hasard indéfiniment (nom du client, total, reste à payer). Seuls les
 * codes faux comptent : les clients qui partagent l'adresse d'un
 * opérateur mobile ne se gênent pas en scannant leurs vraies factures.
 */
final class EssaisCodeFacture
{
    public function __construct(
        #[Autowire(service: 'limiter.facture_suivi_ip')] private readonly RateLimiterFactoryInterface $limite,
    ) {
    }

    /** Trop de codes faux depuis cette connexion : plus de réponse jusqu'à la fin de la fenêtre. */
    public function bloque(string $ip): bool
    {
        return 0 === $this->limite->create($ip)->consume(0)->getRemainingTokens();
    }

    public function noterCodeFaux(string $ip): void
    {
        $this->limite->create($ip)->consume(1);
    }
}
