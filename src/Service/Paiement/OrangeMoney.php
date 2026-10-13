<?php

namespace App\Service\Paiement;

use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\Paiements;
use App\Entity\TransactionOrangeMoney;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Paiement Orange Money depuis l'espace client (API Orange Money Web
 * Payment). Le client paie sur la page d'Orange ; Orange prévient le
 * site (notif_url, authentifiée par le notif_token) et l'état se vérifie
 * aussi au retour du client. L'argent reçu devient un paiement « à
 * valider » : la caisse le valide au local sur le compte Orange Money,
 * jamais automatiquement.
 *
 * Clés dans .env.local du serveur en ligne (jamais dans le dépôt). Essai :
 * ORANGE_MONEY_API=…/orange-money-webpay/dev/v1 et la devise OUV ;
 * production au Mali : …/orange-money-webpay/ml/v1 et XOF.
 */
final class OrangeMoney
{
    private const OAUTH = 'https://api.orange.com/oauth/v3/token';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $http,
        private readonly CacheInterface $cache,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(ORANGE_MONEY_API)%')] private readonly string $api,
        #[Autowire('%env(ORANGE_MONEY_DEVISE)%')] private readonly string $devise,
        #[Autowire('%env(ORANGE_MONEY_CLIENT_ID)%')] private readonly string $clientId,
        #[Autowire('%env(ORANGE_MONEY_CLIENT_SECRET)%')] private readonly string $clientSecret,
        #[Autowire('%env(ORANGE_MONEY_MERCHANT_KEY)%')] private readonly string $merchantKey,
    ) {
    }

    public function estConfigure(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret && '' !== $this->merchantKey && str_starts_with($this->api, 'https://');
    }

    /** Montant Orange Money déjà reçu en ligne pour la commande, que la caisse n'a pas encore validé. */
    public static function enValidation(Commandes $commande): int
    {
        $total = 0;
        foreach ($commande->getPaiements() as $p) {
            if (Paiements::MODE_ORANGE_MONEY === $p->getMode() && Paiements::STATUT_EN_ATTENTE === $p->getStatut()) {
                $total += (int) $p->getMontant();
            }
        }

        return $total;
    }

    /** Ce qui reste à payer en ligne : le reste dû, moins ce qui attend la caisse. */
    public static function aPayer(Commandes $commande): int
    {
        return max(0, $commande->getResteAPayer() - self::enValidation($commande));
    }

    /**
     * Ouvre le paiement chez Orange et rend le lien de sa page de
     * paiement. Un lien encore valable pour le même montant est repris.
     *
     * @throws \DomainException message à montrer au client
     */
    public function demarrer(Commandes $commande, CompteClient $compte, \DateTimeImmutable $maintenant): TransactionOrangeMoney
    {
        if (!$this->estConfigure()) {
            throw new \DomainException('Le paiement Orange Money en ligne n\'est pas encore ouvert.');
        }
        $montant = self::aPayer($commande);
        if (!$commande->isStatut() || $montant <= 0) {
            throw new \DomainException('Rien à payer en ligne pour cette commande.');
        }
        foreach ($this->em->getRepository(TransactionOrangeMoney::class)->findBy(['commande' => $commande, 'statut' => TransactionOrangeMoney::INITIEE], ['id' => 'DESC'], 1) as $ouverte) {
            if ($ouverte->estOuverte($maintenant) && $ouverte->getMontant() === $montant) {
                return $ouverte;
            }
        }

        $transaction = new TransactionOrangeMoney($commande, $compte, $montant, \sprintf('SI%s%s', $maintenant->format('ymdHis'), strtoupper(bin2hex(random_bytes(3)))), $maintenant);
        $id = (string) $commande->getPublicId();
        try {
            $reponse = $this->http->request('POST', rtrim($this->api, '/').'/webpayment', [
                'headers' => ['Authorization' => 'Bearer '.$this->jeton(), 'Accept' => 'application/json'],
                'json' => [
                    'merchant_key' => $this->merchantKey,
                    'currency' => $this->devise,
                    'order_id' => $transaction->getOrderId(),
                    'amount' => $montant,
                    'return_url' => $this->urls->generate('app_espace_payer_retour', ['id' => $id, 'o' => $transaction->getOrderId()], UrlGeneratorInterface::ABSOLUTE_URL),
                    'cancel_url' => $this->urls->generate('app_espace_payer', ['id' => $id, 'annule' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
                    'notif_url' => $this->urls->generate('app_orange_money_notification', ['o' => $transaction->getOrderId()], UrlGeneratorInterface::ABSOLUTE_URL),
                    'lang' => 'fr',
                    'reference' => 'Succès Imprim',
                ],
                'timeout' => 15,
            ]);
            $donnees = $reponse->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Orange Money : paiement non ouvert ({erreur}).', ['erreur' => $e->getMessage()]);
            throw new \DomainException('Orange Money ne répond pas. Réessayez dans un instant.');
        }
        $lien = (string) ($donnees['payment_url'] ?? '');
        if (!\in_array($reponse->getStatusCode(), [200, 201], true) || '' === (string) ($donnees['pay_token'] ?? '') || '' === (string) ($donnees['notif_token'] ?? '') || !self::lienOrange($lien)) {
            $this->logger->error('Orange Money : réponse inattendue ({statut}, {message}).', ['statut' => $reponse->getStatusCode(), 'message' => (string) ($donnees['message'] ?? '')]);
            throw new \DomainException('Orange Money a refusé d\'ouvrir le paiement. Réessayez ou payez en boutique.');
        }
        $transaction->ouvrir((string) $donnees['pay_token'], (string) $donnees['notif_token'], $lien);
        $this->em->persist($transaction);
        $this->em->flush();

        return $transaction;
    }

    /**
     * Notification d'Orange (notif_url) : n'est crue que si son
     * notif_token est celui reçu à l'ouverture.
     *
     * @param array<string, mixed> $donnees
     */
    public function notification(string $orderId, array $donnees, \DateTimeImmutable $maintenant): bool
    {
        $transaction = $this->em->getRepository(TransactionOrangeMoney::class)->findOneBy(['orderId' => $orderId]);
        if (null === $transaction || !\is_string($donnees['notif_token'] ?? null) || !$transaction->notifTokenValide($donnees['notif_token'])) {
            $this->logger->warning('Orange Money : notification refusée pour {order}.', ['order' => mb_substr($orderId, 0, 40)]);

            return false;
        }
        $this->appliquer($transaction, (string) ($donnees['status'] ?? ''), \is_string($donnees['txnid'] ?? null) ? $donnees['txnid'] : null, $maintenant);

        return true;
    }

    /**
     * État chez Orange (au retour du client, ou si la notification
     * tarde) : rend le statut de la transaction après mise à jour.
     */
    public function verifier(TransactionOrangeMoney $transaction, \DateTimeImmutable $maintenant): string
    {
        if (TransactionOrangeMoney::INITIEE !== $transaction->getStatut() || !$this->estConfigure()) {
            return $transaction->getStatut();
        }
        try {
            $donnees = $this->http->request('POST', rtrim($this->api, '/').'/transactionstatus', [
                'headers' => ['Authorization' => 'Bearer '.$this->jeton(), 'Accept' => 'application/json'],
                'json' => ['order_id' => $transaction->getOrderId(), 'amount' => $transaction->getMontant(), 'pay_token' => $transaction->getPayToken()],
                'timeout' => 15,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Orange Money : état non lu ({erreur}).', ['erreur' => $e->getMessage()]);

            return $transaction->getStatut();
        }
        $this->appliquer($transaction, (string) ($donnees['status'] ?? ''), \is_string($donnees['txnid'] ?? null) ? $donnees['txnid'] : null, $maintenant);

        return $transaction->getStatut();
    }

    /** Lien de la page de paiement : seulement chez Orange Money, en https. */
    public static function lienOrange(string $lien): bool
    {
        $hote = (string) parse_url($lien, \PHP_URL_HOST);

        return str_starts_with($lien, 'https://') && (bool) preg_match('/(^|\.)orange-money\.com$/', $hote);
    }

    private function appliquer(TransactionOrangeMoney $transaction, string $statut, ?string $txnid, \DateTimeImmutable $maintenant): void
    {
        if (TransactionOrangeMoney::INITIEE !== $transaction->getStatut()) {
            return; // déjà traitée : une notification rejouée ne crée rien de plus
        }
        if ('SUCCESS' === $statut) {
            $commande = $transaction->getCommande();
            $paiement = (new Paiements())
                ->setCommande($commande)
                ->setMontant($transaction->getMontant())
                ->setMode(Paiements::MODE_ORANGE_MONEY)
                ->setDate($maintenant)
                ->setReference(mb_substr('OM '.($txnid ?? $transaction->getOrderId()), 0, 100))
                ->setObservation(\sprintf('Payé en ligne depuis l\'espace client%s (Orange Money, %s).', null !== $transaction->getCompte() ? ' par '.$transaction->getCompte()->getNomAffiche() : '', $transaction->getOrderId()));
            $commande->addPaiement($paiement);
            $this->em->persist($paiement);
            $transaction->reussir($txnid, $paiement, $maintenant);
        } elseif ('FAILED' === $statut) {
            $transaction->terminer(TransactionOrangeMoney::ECHOUEE, $maintenant);
        } elseif ('EXPIRED' === $statut) {
            $transaction->terminer(TransactionOrangeMoney::EXPIREE, $maintenant);
        }
        $this->em->flush();
    }

    private function jeton(): string
    {
        return $this->cache->get('orange_money_jeton', function (ItemInterface $item): string {
            $donnees = $this->http->request('POST', self::OAUTH, [
                'headers' => ['Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret), 'Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => 'grant_type=client_credentials',
                'timeout' => 15,
            ])->toArray();
            $jeton = (string) ($donnees['access_token'] ?? '');
            if ('' === $jeton) {
                throw new \RuntimeException('Réponse Orange sans access_token.');
            }
            $item->expiresAfter(max(60, (int) ($donnees['expires_in'] ?? 3600) - 60));

            return $jeton;
        });
    }
}
