<?php

namespace App\Service\Paiement;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\DemandeConception;
use App\Entity\Paiements;
use App\Entity\ParametresPaiement;
use App\Entity\ReglementEnLigne;
use App\Entity\TransactionOrangeMoney;
use App\Repository\ParametresPaiementRepository;
use App\Service\Conception\CommandesConception;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Paiement Orange Money depuis l'espace client (API Orange Money Web
 * Payment). Le client paie sur la page d'Orange ; Orange prévient le
 * site (notif_url, authentifiée par le notif_token) et l'état se vérifie
 * aussi au retour du client. L'argent reçu devient un paiement « à
 * valider » : la caisse le valide au local sur le compte Orange Money,
 * jamais automatiquement.
 *
 * Il paie aussi l'acompte d'une demande de conception et les packs de
 * crédit IA (docs/demande-conception.md) : la commande est alors créée à
 * la réception de l'argent, par CommandesConception. Et un règlement de
 * plusieurs factures et acomptes (ReglementsEnLigne) : un paiement par
 * commande à la réception de l'argent, par PaiementsDuReglement ; le même
 * règlement sert à l'acompte payé à la commande (PaiementALaCommande).
 *
 * Chaque paiement porte le fonds de soutien et le frais de retrait
 * (FraisOrangeMoney, taux de Réglages › Paiements, 1 % et 1 % tant que le
 * local n'a rien envoyé) : calculés ici, gardés sur la transaction, et
 * Orange reçoit le total. Le client les voit dans le récapitulatif avant
 * de partir chez Orange.
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
        private readonly ?CommandesConception $conception = null,
        private readonly ?ParametresPaiementRepository $parametresPaiement = null,
        #[Autowire('%kernel.project_dir%/var/orange-money/refus.json')] private readonly ?string $fichierRefus = null,
        private readonly ?PaiementsDuReglement $reglements = null,
    ) {
    }

    /** Taux des frais réglés (Réglages › Paiements). */
    public function parametres(): ParametresPaiement
    {
        return $this->parametresPaiement?->recuperer() ?? new ParametresPaiement();
    }

    /** Récapitulatif d'un paiement : montant, fonds de soutien, frais de retrait, total. */
    public function frais(int $montant): FraisOrangeMoney
    {
        return FraisOrangeMoney::calculer($montant, $this->parametres());
    }

    public function estConfigure(): bool
    {
        return '' !== $this->clientId && '' !== $this->clientSecret && '' !== $this->merchantKey && str_starts_with($this->api, 'https://');
    }

    /**
     * Le total montré dans le récapitulatif n'est plus celui d'aujourd'hui
     * (paiement reçu entre-temps, taux changés) : le client le revoit avant
     * de partir chez Orange. Le total envoyé à Orange est toujours celui du
     * serveur ; la valeur de la page ne sert qu'à cette comparaison.
     */
    public static function totalChange(string $totalVu, FraisOrangeMoney $frais): bool
    {
        return '' !== $totalVu && (int) $totalVu !== $frais->total();
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

    /** Avoir utilisé en ligne pour la commande (« Factures et paiements »), que la boutique n'a pas encore validé. */
    public static function avoirEnValidation(Commandes $commande): int
    {
        $total = 0;
        foreach ($commande->getPaiements() as $p) {
            if (Paiements::MODE_SOLDE_CLIENT === $p->getMode() && Paiements::STATUT_EN_ATTENTE === $p->getStatut()) {
                $total += (int) $p->getMontant();
            }
        }

        return $total;
    }

    /** Ce qui reste à payer en ligne : le reste dû, moins ce qui attend la caisse (Orange Money, avoir). */
    public static function aPayer(Commandes $commande): int
    {
        return max(0, $commande->getResteAPayer() - self::enValidation($commande) - self::avoirEnValidation($commande));
    }

    /**
     * Ouvre le paiement chez Orange et rend le lien de sa page de
     * paiement. Un lien encore valable pour le même total est repris.
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
        $frais = $this->frais($montant);
        foreach ($this->em->getRepository(TransactionOrangeMoney::class)->findBy(['commande' => $commande, 'statut' => TransactionOrangeMoney::INITIEE], ['id' => 'DESC'], 1) as $ouverte) {
            if (self::reprendre($ouverte, $frais, $maintenant)) {
                return $ouverte;
            }
        }

        $transaction = (new TransactionOrangeMoney($commande, $compte, $montant, self::nouvelOrderId($maintenant), $maintenant))
            ->avecFrais($frais->fondsSoutien, $frais->fraisRetrait);
        $id = (string) $commande->getPublicId();

        return $this->ouvrirChezOrange(
            $transaction,
            $this->urls->generate('app_espace_payer_retour', ['id' => $id, 'o' => $transaction->getOrderId()], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->urls->generate('app_espace_payer', ['id' => $id, 'annule' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    /**
     * Acompte d'une demande de conception (maquette DC03). Un lien encore
     * valable pour la même demande et le même total est repris.
     *
     * @throws \DomainException message à montrer au client
     */
    public function demarrerAcompte(DemandeConception $demande, CompteClient $compte, int $montant, \DateTimeImmutable $maintenant): TransactionOrangeMoney
    {
        if (!$this->estConfigure()) {
            throw new \DomainException('Le paiement Orange Money en ligne n\'est pas encore ouvert.');
        }
        if ($montant <= 0 || !$demande->estOuverte()) {
            throw new \DomainException('Rien à payer en ligne pour cette demande.');
        }
        $frais = $this->frais($montant);
        foreach ($this->em->getRepository(TransactionOrangeMoney::class)->findBy(['demandeConception' => $demande, 'statut' => TransactionOrangeMoney::INITIEE], ['id' => 'DESC'], 1) as $ouverte) {
            if (self::reprendre($ouverte, $frais, $maintenant)) {
                return $ouverte;
            }
        }
        $transaction = TransactionOrangeMoney::pourAcompte($demande, $compte, $montant, self::nouvelOrderId($maintenant), $maintenant)
            ->avecFrais($frais->fondsSoutien, $frais->fraisRetrait);

        return $this->ouvrirChezOrange(
            $transaction,
            $this->urls->generate('app_espace_conception_retour', ['o' => $transaction->getOrderId()], UrlGeneratorInterface::ABSOLUTE_URL),
            $this->urls->generate('app_espace_conception_informations', ['id' => (string) $demande->getPublicId(), 'annule' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    /**
     * Pack de crédit IA. Un lien encore valable pour le même pack et le
     * même total est repris.
     *
     * @throws \DomainException message à montrer au client
     */
    public function demarrerCredit(Clients $client, CompteClient $compte, int $images, int $montant, \DateTimeImmutable $maintenant, ?string $demande = null): TransactionOrangeMoney
    {
        if (!$this->estConfigure()) {
            throw new \DomainException('Le paiement Orange Money en ligne n\'est pas encore ouvert.');
        }
        if ($montant <= 0 || $images <= 0) {
            throw new \DomainException('Choisissez un pack de crédit.');
        }
        $frais = $this->frais($montant);
        foreach ($this->em->getRepository(TransactionOrangeMoney::class)->findBy(['client' => $client, 'objet' => TransactionOrangeMoney::OBJET_CREDIT_IA, 'packImages' => $images, 'statut' => TransactionOrangeMoney::INITIEE], ['id' => 'DESC'], 1) as $ouverte) {
            if (self::reprendre($ouverte, $frais, $maintenant)) {
                return $ouverte;
            }
        }
        $transaction = TransactionOrangeMoney::pourCreditIa($client, $compte, $images, $montant, self::nouvelOrderId($maintenant), $maintenant)
            ->avecFrais($frais->fondsSoutien, $frais->fraisRetrait);
        $retour = ['o' => $transaction->getOrderId()] + (null !== $demande ? ['demande' => $demande] : []);

        return $this->ouvrirChezOrange(
            $transaction,
            $this->urls->generate('app_espace_conception_retour', $retour, UrlGeneratorInterface::ABSOLUTE_URL),
            $this->urls->generate('app_espace_conception_credit', ['annule' => 1] + (null !== $demande ? ['demande' => $demande] : []), UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    /**
     * Règlement de plusieurs factures et acomptes (« Factures et paiements ») :
     * la part Orange Money et ses frais viennent du règlement, calculés par
     * le serveur (ReglementsEnLigne). Orange renvoie le client sur la page
     * « Paiement reçu » du règlement.
     *
     * @throws \DomainException message à montrer au client
     */
    public function demarrerReglement(ReglementEnLigne $reglement, CompteClient $compte, \DateTimeImmutable $maintenant): TransactionOrangeMoney
    {
        if (!$this->estConfigure()) {
            throw new \DomainException('Le paiement Orange Money en ligne n\'est pas encore ouvert.');
        }
        if ($reglement->getMontantOm() <= 0 || !$reglement->estEnCours()) {
            throw new \DomainException('Rien à payer par Orange Money.');
        }
        $transaction = TransactionOrangeMoney::pourReglement($reglement, $compte, $maintenant);
        // Payé à la commande (commande en ligne encore « à confirmer ») : annulé, le client revient à son récapitulatif.
        $parts = $reglement->getParts();
        $brouillon = 1 === \count($parts) && !$parts[0]->getCommande()->isStatut() ? $parts[0]->getCommande() : null;

        return $this->ouvrirChezOrange(
            $transaction,
            $this->urls->generate('app_espace_payer_recu', ['reference' => $reglement->getReference()], UrlGeneratorInterface::ABSOLUTE_URL),
            null !== $brouillon
                ? $this->urls->generate('app_espace_nouvelle_commande_paiement', ['id' => (string) $brouillon->getPublicId(), 'annule' => 1], UrlGeneratorInterface::ABSOLUTE_URL)
                : $this->urls->generate('app_espace_factures', ['annule' => 1], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }

    /**
     * Lien encore valable pour le même montant et les mêmes frais : le
     * client y retourne. Taux changés entre-temps : un autre paiement.
     */
    private static function reprendre(TransactionOrangeMoney $ouverte, FraisOrangeMoney $frais, \DateTimeImmutable $maintenant): bool
    {
        return $ouverte->estOuverte($maintenant) && $ouverte->getMontant() === $frais->montant && $ouverte->getTotal() === $frais->total();
    }

    /** Notre référence chez Orange : la forme attendue par la route de notification. */
    public static function nouvelOrderId(\DateTimeImmutable $maintenant): string
    {
        return \sprintf('SI%s%s', $maintenant->format('ymdHis'), strtoupper(bin2hex(random_bytes(3))));
    }

    /**
     * Ouvre le paiement chez Orange et garde la transaction (jetons,
     * lien de paiement vérifié).
     *
     * @throws \DomainException
     */
    private function ouvrirChezOrange(TransactionOrangeMoney $transaction, string $retour, string $annulation): TransactionOrangeMoney
    {
        if (self::adresseLocale($retour)) {
            // Seulement sur un poste de développement : Orange refuse de renvoyer vers localhost.
            throw new \DomainException('Orange Money refuse les adresses localhost : ouvrez l\'espace client par une adresse publique (tunnel ou site en ligne) pour payer.');
        }
        try {
            $reponse = $this->demanderOuverture($transaction, $retour, $annulation, false);
            // 401 ou 403 : le jeton gardé peut dater d'avant un changement chez
            // Orange (API ajoutée à l'application, clés changées). Un seul
            // nouvel essai, avec un jeton neuf.
            if (\in_array($reponse->getStatusCode(), [401, 403], true)) {
                $reponse = $this->demanderOuverture($transaction, $retour, $annulation, true);
            }
            $donnees = $reponse->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Orange Money : paiement non ouvert ({erreur}).', ['erreur' => $e->getMessage()]);
            $this->noterRefus($transaction->getOrderId(), 'Orange injoignable ou réponse illisible : '.mb_substr($e->getMessage(), 0, 160));
            throw new \DomainException('Orange Money ne répond pas. Réessayez dans un instant.');
        }
        $lien = (string) ($donnees['payment_url'] ?? '');
        if (!\in_array($reponse->getStatusCode(), [200, 201], true) || '' === (string) ($donnees['pay_token'] ?? '') || '' === (string) ($donnees['notif_token'] ?? '') || !self::lienOrange($lien)) {
            $raison = self::raisonRefus($reponse->getStatusCode(), $donnees, $lien, $this->api);
            $this->logger->error('Orange Money : paiement non ouvert, {raison}.', ['raison' => $raison]);
            $this->noterRefus($transaction->getOrderId(), $raison);
            throw new \DomainException('Orange Money a refusé d\'ouvrir le paiement. Réessayez ou payez en boutique.');
        }
        $transaction->ouvrir((string) $donnees['pay_token'], (string) $donnees['notif_token'], $lien);
        $this->em->persist($transaction);
        $this->em->flush();

        return $transaction;
    }

    private function demanderOuverture(TransactionOrangeMoney $transaction, string $retour, string $annulation, bool $jetonNeuf): ResponseInterface
    {
        return $this->http->request('POST', rtrim($this->api, '/').'/webpayment', [
            'headers' => ['Authorization' => 'Bearer '.$this->jeton($jetonNeuf), 'Accept' => 'application/json'],
            'json' => [
                'merchant_key' => $this->merchantKey,
                'currency' => $this->devise,
                'order_id' => $transaction->getOrderId(),
                // Frais compris : c'est ce que le client a vu dans le récapitulatif.
                'amount' => $transaction->getTotal(),
                'return_url' => $retour,
                'cancel_url' => $annulation,
                'notif_url' => $this->urls->generate('app_orange_money_notification', ['o' => $transaction->getOrderId()], UrlGeneratorInterface::ABSOLUTE_URL),
                'lang' => 'fr',
                'reference' => 'Succès Imprim',
            ],
            'timeout' => 15,
        ]);
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
                'json' => ['order_id' => $transaction->getOrderId(), 'amount' => $transaction->getTotal(), 'pay_token' => $transaction->getPayToken()],
                'timeout' => 15,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('Orange Money : état non lu ({erreur}).', ['erreur' => $e->getMessage()]);

            return $transaction->getStatut();
        }
        $this->appliquer($transaction, (string) ($donnees['status'] ?? ''), \is_string($donnees['txnid'] ?? null) ? $donnees['txnid'] : null, $maintenant);

        return $transaction->getStatut();
    }

    /**
     * Essai des clés (app:orange-money:tester) sans rien enregistrer ni
     * montrer de secret : chaque étape dit si elle passe et, sinon, la
     * raison donnée par Orange. Le paiement d'essai n'est ouvert qu'avec
     * l'API d'essai (dev) ; sa notification, sans transaction, est ignorée.
     * Les frais d'un paiement de 100 sont montrés avec les taux réglés, et
     * le paiement d'essai s'ouvre frais compris, comme celui d'un client.
     *
     * @return list<array{etape: string, ok: bool, detail: string}>
     */
    /** API d'essai (dev) : seul cas où le testeur ouvre un paiement. */
    public function apiDEssai(): bool
    {
        return str_contains($this->api, '/dev/');
    }

    public function diagnostic(bool $ouvrirPaiement, \DateTimeImmutable $maintenant): array
    {
        $cles = ['ORANGE_MONEY_CLIENT_ID' => $this->clientId, 'ORANGE_MONEY_CLIENT_SECRET' => $this->clientSecret, 'ORANGE_MONEY_MERCHANT_KEY' => $this->merchantKey];
        $vides = array_keys(array_filter($cles, static fn (string $v): bool => '' === $v));
        $etapes = [['etape' => 'Clés dans .env.local', 'ok' => [] === $vides, 'detail' => [] === $vides ? 'les trois clés sont renseignées ; Client ID commençant par « '.mb_substr(trim($this->clientId), 0, 6).' » (à comparer avec l\'application de developer.orange.com)' : 'vide : '.implode(', ', $vides)]];
        foreach ($cles as $nom => $valeur) {
            $souci = match (true) {
                $valeur !== trim($valeur, " \t\n\r\"'") => 'espace ou guillemet en trop au début ou à la fin',
                str_starts_with($valeur, 'Basic ') || str_starts_with($valeur, 'Bearer ') => 'contient un en-tête Authorization : mettre seulement la clé',
                default => null,
            };
            if (null !== $souci) {
                $etapes[] = ['etape' => $nom, 'ok' => false, 'detail' => $souci];
            }
        }
        $essai = str_contains($this->api, '/dev/');
        $deviseAttendue = $essai ? 'OUV' : 'XOF';
        $etapes[] = [
            'etape' => 'Adresse de l\'API',
            'ok' => str_starts_with($this->api, 'https://') && $this->devise === $deviseAttendue,
            'detail' => \sprintf('%s, devise %s%s', $essai ? 'essai (dev)' : 'production', $this->devise, $this->devise === $deviseAttendue ? '' : ' (attendue : '.$deviseAttendue.')'),
        ];
        $frais = $this->frais(100);
        $etapes[] = ['etape' => 'Frais Orange Money', 'ok' => true, 'detail' => self::detailFrais($frais, $this->devise)];
        if ('' === $this->clientId || '' === $this->clientSecret) {
            return $etapes;
        }

        try {
            $reponse = $this->http->request('POST', self::OAUTH, [
                'headers' => ['Authorization' => 'Basic '.base64_encode($this->clientId.':'.$this->clientSecret), 'Content-Type' => 'application/x-www-form-urlencoded'],
                'body' => 'grant_type=client_credentials',
                'timeout' => 15,
            ]);
            $code = $reponse->getStatusCode();
            $donnees = self::corps($reponse->getContent(false));
        } catch (\Throwable $e) {
            $etapes[] = ['etape' => 'Jeton Orange', 'ok' => false, 'detail' => 'Orange injoignable : '.mb_substr($e->getMessage(), 0, 160)];

            return $etapes;
        }
        $jeton = (string) ($donnees['access_token'] ?? '');
        $etapes[] = ['etape' => 'Jeton Orange', 'ok' => 200 === $code && '' !== $jeton, 'detail' => '' !== $jeton ? 'obtenu : Client ID et Client Secret acceptés' : self::refus($code, $donnees, $this->api)];
        if (!$ouvrirPaiement || '' === $jeton || '' === $this->merchantKey) {
            return $etapes;
        }
        if (!$essai) {
            $etapes[] = ['etape' => 'Paiement d\'essai', 'ok' => false, 'detail' => 'seulement avec l\'API d\'essai (dev), jamais en production'];

            return $etapes;
        }

        $orderId = \sprintf('SI%s%s', $maintenant->format('ymdHis'), strtoupper(bin2hex(random_bytes(3))));
        // Orange refuse localhost : depuis un poste de développement, l'essai
        // renvoie vers example.com (la notification d'un essai est ignorée).
        $accueil = $this->urls->generate('app_home', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $notification = $this->urls->generate('app_orange_money_notification', ['o' => $orderId], UrlGeneratorInterface::ABSOLUTE_URL);
        $local = self::adresseLocale($accueil);
        if ($local) {
            $accueil = 'https://example.com/';
            $notification = 'https://example.com/om/notification/'.$orderId;
        }
        try {
            $reponse = $this->http->request('POST', rtrim($this->api, '/').'/webpayment', [
                'headers' => ['Authorization' => 'Bearer '.$jeton, 'Accept' => 'application/json'],
                'json' => [
                    'merchant_key' => $this->merchantKey,
                    'currency' => $this->devise,
                    'order_id' => $orderId,
                    'amount' => $frais->total(),
                    'return_url' => $accueil,
                    'cancel_url' => $accueil,
                    'notif_url' => $notification,
                    'lang' => 'fr',
                    'reference' => 'Essai Succès Imprim',
                ],
                'timeout' => 15,
            ]);
            $code = $reponse->getStatusCode();
            $donnees = self::corps($reponse->getContent(false));
        } catch (\Throwable $e) {
            $etapes[] = ['etape' => 'Paiement d\'essai', 'ok' => false, 'detail' => 'Orange injoignable : '.mb_substr($e->getMessage(), 0, 160)];

            return $etapes;
        }
        $lien = (string) ($donnees['payment_url'] ?? '');
        $etapes[] = \in_array($code, [200, 201], true) && self::lienOrange($lien)
            ? ['etape' => 'Paiement d\'essai', 'ok' => true, 'detail' => \sprintf('clé marchand acceptée, page de paiement de %d %s (frais compris) : ', $frais->total(), $this->devise).$lien.($local ? ' (retour vers example.com : Orange refuse localhost)' : '')]
            : ['etape' => 'Paiement d\'essai', 'ok' => false, 'detail' => self::refus($code, $donnees, $this->api)];

        return $etapes;
    }

    /** « 100 OUV + fonds de soutien 1 % (1) + frais de retrait 1 % (1) = 102 OUV payés par le client ». */
    public static function detailFrais(FraisOrangeMoney $frais, string $devise): string
    {
        $lignes = [\sprintf('%d %s', $frais->montant, $devise)];
        if ($frais->tauxFondsSoutien > 0) {
            $lignes[] = \sprintf('fonds de soutien %s (%d)', FraisOrangeMoney::taux($frais->tauxFondsSoutien), $frais->fondsSoutien);
        }
        if ($frais->tauxFraisRetrait > 0) {
            $lignes[] = \sprintf('frais de retrait %s (%d)', FraisOrangeMoney::taux($frais->tauxFraisRetrait), $frais->fraisRetrait);
        }

        return \sprintf('%s = %d %s payés par le client', implode(' + ', $lignes), $frais->total(), $devise);
    }

    /** Adresse que seul ce poste peut ouvrir, refusée par Orange. */
    public static function adresseLocale(string $url): bool
    {
        $hote = strtolower(trim((string) parse_url($url, \PHP_URL_HOST), '[]'));

        return '' === $hote || 'localhost' === $hote || str_ends_with($hote, '.localhost') || '::1' === $hote || str_starts_with($hote, '127.');
    }

    /** @return array<string, mixed> */
    private static function corps(string $contenu): array
    {
        $donnees = json_decode($contenu, true);

        return \is_array($donnees) ? $donnees : [];
    }

    /**
     * Raison d'un paiement non ouvert : le refus d'Orange, ou ce qui manque
     * dans une réponse acceptée (jamais un jeton ni une clé).
     *
     * @param array<string, mixed> $donnees
     */
    public static function raisonRefus(int $code, array $donnees, string $lien, string $api = ''): string
    {
        if (!\in_array($code, [200, 201], true)) {
            return self::refus($code, $donnees, $api);
        }
        $manque = array_values(array_filter([
            '' === (string) ($donnees['pay_token'] ?? '') ? 'pay_token absent' : null,
            '' === (string) ($donnees['notif_token'] ?? '') ? 'notif_token absent' : null,
            self::lienOrange($lien) ? null : ('' === $lien ? 'payment_url absente' : 'payment_url hors d\'Orange Money ('.mb_substr((string) parse_url($lien, \PHP_URL_SCHEME).'://'.(string) parse_url($lien, \PHP_URL_HOST), 0, 80).')'),
        ]));

        return \sprintf('réponse d\'Orange incomplète (HTTP %d) : %s', $code, implode(', ', $manque));
    }

    /**
     * Garde les 5 derniers paiements non ouverts (date, référence, raison)
     * pour app:orange-money:tester : rien d'autre, ni clé ni jeton ni
     * montant. Un fichier illisible ou impossible à écrire ne gêne pas le
     * client.
     */
    private function noterRefus(string $orderId, string $raison): void
    {
        if (null === $this->fichierRefus) {
            return;
        }
        try {
            $refus = array_slice($this->derniersRefus(), 0, 4);
            array_unshift($refus, ['le' => (new \DateTimeImmutable())->format(\DATE_ATOM), 'reference' => mb_substr($orderId, 0, 40), 'raison' => mb_substr($raison, 0, 300)]);
            if (!is_dir(\dirname($this->fichierRefus))) {
                mkdir(\dirname($this->fichierRefus), 0770, true);
            }
            file_put_contents($this->fichierRefus, json_encode($refus, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT), \LOCK_EX);
        } catch (\Throwable $e) {
            $this->logger->warning('Orange Money : refus non noté ({erreur}).', ['erreur' => $e->getMessage()]);
        }
    }

    /**
     * Derniers paiements qu'Orange n'a pas ouverts, du plus récent au plus ancien.
     *
     * @return list<array{le: string, reference: string, raison: string}>
     */
    public function derniersRefus(): array
    {
        if (null === $this->fichierRefus || !is_file($this->fichierRefus)) {
            return [];
        }
        $refus = json_decode((string) @file_get_contents($this->fichierRefus), true);
        if (!\is_array($refus)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $r): ?array => \is_array($r) && \is_string($r['le'] ?? null) && \is_string($r['reference'] ?? null) && \is_string($r['raison'] ?? null)
                ? ['le' => $r['le'], 'reference' => $r['reference'], 'raison' => $r['raison']]
                : null,
            $refus,
        )));
    }

    /**
     * Raison d'un refus d'Orange, sans rien de ce qui a été envoyé.
     *
     * @param array<string, mixed> $donnees
     */
    private static function refus(int $code, array $donnees, string $api = ''): string
    {
        $raison = implode(' : ', array_filter(array_map(
            static fn (string $champ): string => \is_scalar($donnees[$champ] ?? null) ? (string) $donnees[$champ] : '',
            ['error', 'error_description', 'message', 'description'],
        )));

        // 403 « insufficient scope » : le jeton est bon, mais l'application Orange de ces clés n'a pas
        // cette API. Le cas vécu : des clés de production au Mali avec l'adresse de l'API d'essai.
        $aide = match (true) {
            403 !== $code || !str_contains(strtolower($raison), 'scope') => '',
            str_contains($api, '/dev/') => ' : ces clés n\'ont pas l\'API d\'essai ; avec des clés de production au Mali, mettre ORANGE_MONEY_API=https://api.orange.com/orange-money-webpay/ml/v1 et ORANGE_MONEY_DEVISE=XOF',
            default => ' : l\'application Orange de ce Client ID n\'a pas l\'API Orange Money Web Payment de cette adresse ; l\'ajouter dans My apps sur developer.orange.com, avec la clé marchand de la même application',
        };

        return \sprintf('refusé par Orange (HTTP %d%s)%s', $code, '' !== $raison ? ', '.mb_substr($raison, 0, 200) : '', $aide);
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
        if (TransactionOrangeMoney::OBJET_REGLEMENT === $transaction->getObjet()) {
            $this->appliquerReglement($transaction, $statut, $txnid, $maintenant);

            return;
        }
        if ('SUCCESS' === $statut) {
            // Acompte de conception, pack de crédit IA : la commande naît avec l'argent reçu.
            $commande = $transaction->getCommande() ?? $this->conception?->commandePour($transaction, $maintenant);
            if (null === $commande) {
                $this->logger->error('Orange Money : argent reçu sans commande pour {order}.', ['order' => $transaction->getOrderId()]);

                return;
            }
            $transaction->rattacher($commande);
            // Le montant va à la commande ; les frais restent sur le paiement (comme à la caisse).
            $paiement = (new Paiements())
                ->setCommande($commande)
                ->setMontant($transaction->getMontant())
                ->setFondsSoutienInclus($transaction->getFondsSoutien() > 0)
                ->setMontantFondsSoutien($transaction->getFondsSoutien())
                ->setFraisRetraitInclus($transaction->getFraisRetrait() > 0)
                ->setMontantFraisRetrait($transaction->getFraisRetrait())
                ->setMode(Paiements::MODE_ORANGE_MONEY)
                ->setDate($maintenant)
                ->setReference(mb_substr('OM '.($txnid ?? $transaction->getOrderId()), 0, 100))
                ->setObservation(\sprintf(
                    'Payé en ligne depuis l\'espace client%s (Orange Money, %s%s).',
                    null !== $transaction->getCompte() ? ' par '.$transaction->getCompte()->getNomAffiche() : '',
                    $transaction->getOrderId(),
                    $transaction->getTotal() > $transaction->getMontant() ? \sprintf(', %s F reçus frais compris', number_format($transaction->getTotal(), 0, ',', ' ')) : '',
                ));
            $commande->addPaiement($paiement);
            $this->em->persist($paiement);
            $transaction->reussir($txnid, $paiement, $maintenant);
            $this->conception?->apresPaiement($transaction, $paiement, $maintenant);
        } elseif ('FAILED' === $statut) {
            $transaction->terminer(TransactionOrangeMoney::ECHOUEE, $maintenant);
        } elseif ('EXPIRED' === $statut) {
            $transaction->terminer(TransactionOrangeMoney::EXPIREE, $maintenant);
        }
        $this->em->flush();
    }

    /**
     * Règlement de plusieurs factures : l'argent reçu devient un paiement
     * « en attente » par commande (PaiementsDuReglement), et l'avoir utilisé
     * un paiement « solde client » en attente. Refusé ou expiré : le
     * règlement est abandonné, son avoir n'est plus réservé.
     */
    private function appliquerReglement(TransactionOrangeMoney $transaction, string $statut, ?string $txnid, \DateTimeImmutable $maintenant): void
    {
        $reglement = $transaction->getReglement();
        if ('SUCCESS' === $statut) {
            if (null === $reglement || null === $this->reglements) {
                $this->logger->error('Orange Money : argent reçu sans règlement pour {order}.', ['order' => $transaction->getOrderId()]);

                return;
            }
            $premier = $this->reglements->creer($reglement, $txnid, $maintenant);
            if (null === $premier) {
                $this->logger->error('Orange Money : règlement {order} sans paiement créé.', ['order' => $transaction->getOrderId()]);

                return;
            }
            $transaction->reussir($txnid, $premier, $maintenant);
        } elseif ('FAILED' === $statut || 'EXPIRED' === $statut) {
            $transaction->terminer('FAILED' === $statut ? TransactionOrangeMoney::ECHOUEE : TransactionOrangeMoney::EXPIREE, $maintenant);
            $reglement?->abandonner();
        }
        $this->em->flush();
    }

    private function jeton(bool $neuf = false): string
    {
        // La clé du cache suit l'adresse et les clés : un changement dans
        // .env.local prend effet sans vider le cache.
        $cle = 'orange_money_jeton_'.substr(hash('sha256', $this->api."\n".$this->clientId."\n".$this->clientSecret), 0, 16);
        if ($neuf) {
            $this->cache->delete($cle);
        }

        return $this->cache->get($cle, function (ItemInterface $item): string {
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
