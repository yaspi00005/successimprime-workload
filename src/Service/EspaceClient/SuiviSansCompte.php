<?php

namespace App\Service\EspaceClient;

use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Suivi d'une commande sans compte : numéro de commande et téléphone du
 * client. Sert à /espace/suivre et à « Suivre ma commande » du site
 * (maquette 10), avec la même limite par adresse IP.
 *
 * Même réponse si la commande n'existe pas ou n'est pas à ce numéro : on
 * ne peut pas deviner les commandes des autres. Le jeton CSRF reste
 * vérifié par la page.
 */
final class SuiviSansCompte
{
    public const INTROUVABLE = 'Aucune commande ne correspond à ce numéro et à ce téléphone.';
    public const TROP_DE_RECHERCHES = 'Trop de recherches. Réessayez plus tard.';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CompteClientRepository $comptes,
        #[Autowire(service: 'limiter.espace_suivi_ip')] private readonly RateLimiterFactoryInterface $limite,
    ) {
    }

    /**
     * @return array{commande: ?Commandes, erreur: ?string}
     */
    public function chercher(string $numero, string $telephone, string $ip): array
    {
        if (!$this->limite->create($ip)->consume()->isAccepted()) {
            return ['commande' => null, 'erreur' => self::TROP_DE_RECHERCHES];
        }
        $numero = self::numeroPropre($numero);
        $normalise = CompteClient::normaliserTelephone($telephone);
        $client = null !== $normalise && '' !== $numero ? $this->comptes->clientDuTelephone($normalise) : null;
        if (null === $client) {
            return ['commande' => null, 'erreur' => self::INTROUVABLE];
        }

        // Le numéro complet d'abord, sinon ses chiffres (« 0142 ») parmi les commandes de ce client.
        $commandes = $this->em->getRepository(Commandes::class)->findBy(['clients' => $client, 'statut' => true, 'deleted' => false], ['id' => 'DESC'], 200);
        foreach ([true, false] as $exact) {
            foreach ($commandes as $c) {
                if ($exact ? 0 === strcasecmp((string) $c->getNumero(), $numero) : self::correspond($numero, (string) $c->getNumero())) {
                    return ['commande' => $c, 'erreur' => null];
                }
            }
        }

        return ['commande' => null, 'erreur' => self::INTROUVABLE];
    }

    public static function numeroPropre(string $saisi): string
    {
        return mb_substr(strtoupper((string) preg_replace('/\s+/', '', $saisi)), 0, 50);
    }

    /**
     * « 142 », « 0142 » ou « CMD-142 » retrouvent CMD-000142-10-2026 :
     * même nombre que le premier groupe de chiffres du numéro.
     */
    public static function correspond(string $saisi, string $numero): bool
    {
        $saisi = (string) preg_replace('/^(W-)?(CMD-?)?D?/i', '', self::numeroPropre($saisi));
        if (!preg_match('/^\d{1,8}$/', $saisi) || !preg_match('/\d+/', $numero, $m)) {
            return false;
        }

        return (int) $saisi > 0 && (int) $saisi === (int) $m[0];
    }
}
