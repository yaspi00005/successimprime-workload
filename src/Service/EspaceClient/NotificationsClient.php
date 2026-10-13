<?php

namespace App\Service\EspaceClient;

use App\Entity\AbonnementPushClient;
use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\NotificationClientEnvoyee;
use App\Service\OrangeSmsService;
use App\Service\Push\WebPush;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Prévient le client quand quelque chose bouge sur ses commandes (BAT
 * prêt, commande prête, livreur en route, livrée, paiement reçu, réponse
 * à une réclamation). Sur le site en ligne, quand la synchronisation
 * apporte le changement : d'abord sur l'application installée (gratuit),
 * sinon par SMS s'il les accepte, sinon par e-mail. Aucun montant dans
 * les messages ; chaque événement ne part qu'une fois.
 */
final class NotificationsClient implements EventSubscriberInterface
{
    public const TYPES = ['bat', 'prete', 'route', 'livree', 'paiement', 'reclamation'];

    /** Un événement plus ancien ne part plus (mise en service, synchronisation en retard). */
    public const FRAICHEUR_HEURES = 12;

    /** @var array<int, true> ids des clients à vérifier */
    private array $aVerifier = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[AutowireServiceClosure(EspaceClient::class)] private readonly \Closure $espace,
        private readonly WebPush $push,
        private readonly OrangeSmsService $sms,
        // Paresseux : rien ne casse si l'e-mail n'est pas configuré.
        #[Autowire(lazy: true)] private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $mailerDsn,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')] private readonly string $expediteur,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'envoyerEnAttente', ConsoleEvents::TERMINATE => 'envoyerEnAttente'];
    }

    /** Client dont une fiche vient de changer : vérifié en fin de requête, une fois la page servie. */
    public function aVerifier(?Clients $client): void
    {
        if ($this->enLigne && null !== $client?->getId()) {
            $this->aVerifier[$client->getId()] = true;
        }
    }

    public function envoyerEnAttente(): void
    {
        $ids = array_keys($this->aVerifier);
        $this->aVerifier = [];
        foreach ($ids as $id) {
            try {
                // Relu ici : la réception a pu vider le gestionnaire d'entités entre-temps.
                $client = $this->em->find(Clients::class, $id);
                if ($client instanceof Clients) {
                    $this->notifier($client, new \DateTimeImmutable());
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Notification au client {id} non envoyée : {erreur}', ['id' => $id, 'erreur' => $e->getMessage()]);
            }
        }
    }

    /**
     * Envoie ce qui est nouveau dans le fil du client.
     *
     * @return list<array{type: string, canal: string}>
     */
    public function notifier(Clients $client, \DateTimeImmutable $maintenant): array
    {
        return $this->envoyerFil($client, ($this->espace)()->notifications($client, $maintenant, 30), $maintenant);
    }

    /**
     * @param list<array{type: string, titre: string, texte: string, date: \DateTimeInterface, lien: array{0: string, 1: array<string, mixed>}, numero?: ?string}> $fil fil du client, le plus récent d'abord (EspaceClient::notifications)
     *
     * @return list<array{type: string, canal: string}>
     */
    public function envoyerFil(Clients $client, array $fil, \DateTimeImmutable $maintenant): array
    {
        $deja = array_flip(array_map(
            static fn (NotificationClientEnvoyee $n): string => $n->getCle(),
            $this->em->getRepository(NotificationClientEnvoyee::class)->findBy(['client' => $client]),
        ));
        $comptes = $this->em->getRepository(CompteClient::class)->findBy(['client' => $client, 'actif' => true]);
        $depuis = $maintenant->modify(\sprintf('-%d hours', self::FRAICHEUR_HEURES));
        $envoyees = [];
        foreach (array_reverse($fil) as $n) {
            $cle = self::cle($n);
            if (!\in_array($n['type'], self::TYPES, true) || $n['date'] < $depuis || isset($deja[$cle])) {
                continue;
            }
            $message = self::message($n);
            $url = $this->urls->generate($n['lien'][0], $n['lien'][1]);
            // Le paiement ne concerne que les payeurs d'un compte entreprise.
            $destinataires = array_filter($comptes, static fn (CompteClient $c): bool => 'paiement' !== $n['type'] || $c->peut(CompteClient::DROIT_PAYEUR));
            $canal = match (true) {
                $this->parPush($destinataires, $n['titre'], $message, $url, $cle) => NotificationClientEnvoyee::PUSH,
                $this->parSms($client, $message) => NotificationClientEnvoyee::SMS,
                $this->parEmail($client, $n['titre'], $message, $this->urls->generate($n['lien'][0], $n['lien'][1], UrlGeneratorInterface::ABSOLUTE_URL)) => NotificationClientEnvoyee::EMAIL,
                default => NotificationClientEnvoyee::AUCUN,
            };
            $this->em->persist(new NotificationClientEnvoyee($client, $cle, $n['type'], $canal, $maintenant));
            $deja[$cle] = true;
            $envoyees[] = ['type' => $n['type'], 'canal' => $canal];
        }
        if ([] !== $envoyees) {
            $this->em->flush();
        }

        return $envoyees;
    }

    /** @param array{type: string, lien: array{0: string, 1: array<string, mixed>}, date: \DateTimeInterface} $n */
    public static function cle(array $n): string
    {
        return hash('sha256', $n['type'].'|'.json_encode($n['lien']).'|'.$n['date']->format(\DATE_ATOM));
    }

    /**
     * Phrase courte, sans montant : elle s'affiche sur l'écran verrouillé
     * ou part par SMS.
     *
     * @param array{type: string, texte: string, numero?: ?string} $n
     */
    public static function message(array $n): string
    {
        $numero = (string) ($n['numero'] ?? '');

        return match ($n['type']) {
            'bat' => \sprintf('Votre BAT pour la commande %s est prêt à valider.', $numero),
            'prete' => \sprintf('Votre commande %s est prête%s', $numero, str_contains($n['texte'], 'livraison') ? ' : la livraison arrive bientôt.' : ' à retirer en boutique.'),
            'route' => \sprintf('Le livreur est en route avec votre commande %s.', $numero),
            'livree' => \sprintf('Votre commande %s a été livrée. Merci !', $numero),
            'paiement' => \sprintf('Paiement reçu pour la commande %s. Merci !', $numero),
            'reclamation' => \sprintf('Nous avons répondu à votre réclamation %s.', $numero),
            default => 'Du nouveau dans votre espace client.',
        };
    }

    /** Fiche de démonstration (app:demo) : jamais de SMS ni d'e-mail. */
    public static function estDemo(Clients $client): bool
    {
        return str_starts_with((string) $client->getCode(), 'DEMO-');
    }

    /** @param array<CompteClient> $comptes */
    private function parPush(array $comptes, string $titre, string $message, string $url, string $cle): bool
    {
        if ([] === $comptes || !$this->push->estConfigure()) {
            return false;
        }
        $envois = [];
        foreach ($this->em->getRepository(AbonnementPushClient::class)->findBy(['compte' => array_values($comptes)]) as $a) {
            try {
                $envois[] = [$a, $this->push->envoyer($a, ['titre' => $titre, 'texte' => $message, 'url' => $url, 'tag' => 'ec-'.substr($cle, 0, 16)])];
            } catch (\Throwable $e) {
                $this->logger->warning('Notification push au client non chiffrée : {erreur}', ['erreur' => $e->getMessage()]);
            }
        }
        $recue = false;
        $perimes = [];
        foreach ($envois as [$a, $reponse]) {
            try {
                $statut = $reponse->getStatusCode();
                if (\in_array($statut, [404, 410], true)) {
                    // Le navigateur a retiré l'autorisation : on oublie ce téléphone.
                    $perimes[] = $a->getId();
                } elseif ($statut < 300) {
                    $recue = true;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Notification push au client non envoyée : {erreur}', ['erreur' => $e->getMessage()]);
            }
        }
        if ([] !== $perimes) {
            $this->em->createQuery('DELETE FROM '.AbonnementPushClient::class.' a WHERE a.id IN (:ids)')->setParameter('ids', $perimes)->execute();
        }

        return $recue;
    }

    private function parSms(Clients $client, string $message): bool
    {
        if (self::estDemo($client) || !$client->isRecevoirSms() || '' === trim((string) $client->getTelephone()) || !$this->sms->estConfigure()) {
            return false;
        }

        return (bool) ($this->sms->envoyerSms((string) $client->getTelephone(), 'Succès Imprim : '.$message.' Détails dans votre espace client.')['succes'] ?? false);
    }

    private function parEmail(Clients $client, string $titre, string $message, string $lien): bool
    {
        $email = trim((string) $client->getEmail());
        if (self::estDemo($client) || '' === $email || '' === $this->expediteur || str_starts_with($this->mailerDsn, 'null://')) {
            return false;
        }
        try {
            $this->mailer->send((new Email())->from($this->expediteur)->to($email)->subject($titre.' · Succès Imprim')
                ->text(\sprintf("Bonjour,\n\n%s\n\nVotre espace client : %s\n\nSuccès Imprim", $message, $lien)));

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('E-mail au client non envoyé : {erreur}', ['erreur' => $e->getMessage()]);

            return false;
        }
    }
}
