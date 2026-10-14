<?php

namespace App\Service\EspaceClient;

use App\Entity\AbonnementPushClient;
use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\NotificationClientDifferee;
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
 * prêt, impression, commande prête, livraison, paiement, facture,
 * échéance, devis, réponse de l'atelier). Sur le site en ligne, quand la
 * synchronisation apporte le changement (ou au passage de la tâche
 * app:espace:notifications pour les échéances).
 *
 * Chaque personne du compte est prévenue sur les canaux qu'elle a cochés
 * pour la catégorie (« Comment je suis prévenu », PreferencesNotifications)
 * et que ses droits permettent : application (notification sur le
 * téléphone), SMS sur son propre numéro, e-mail à l'adresse de la fiche.
 * WhatsApp se règle mais ne part pas encore. Entre 21 h et 7 h, le SMS
 * attend 7 h, sauf pour une livraison du jour.
 *
 * Aucun montant dans les messages ; chaque événement ne part qu'une fois ;
 * jamais de SMS ni d'e-mail aux fiches de démonstration.
 */
final class NotificationsClient implements EventSubscriberInterface
{
    public const TYPES = ['bat', 'impression', 'prete', 'livraison_prevue', 'route', 'livree', 'paiement', 'facture', 'echeance', 'devis', 'message', 'reclamation', 'conception'];

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
     * @param list<array{type: string, titre: string, texte: string, date: \DateTimeInterface, lien: array{0: string, 1: array<string, mixed>}, numero?: ?string, jour?: ?\DateTimeInterface}> $fil fil du client, le plus récent d'abord (EspaceClient::notifications)
     *
     * @return list<array{type: string, canal: string}> canal : le premier qui a joint quelqu'un (push, sms, email), « differe » si le SMS attend 7 h, sinon « aucun »
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
            $categorie = PreferencesNotifications::CATEGORIE_DU_TYPE[$n['type']] ?? null;
            if (!\in_array($n['type'], self::TYPES, true) || null === $categorie || $n['date'] < $depuis || isset($deja[$cle])) {
                continue;
            }
            $message = self::message($n);
            // Chacun selon ses réglages ; les catégories que ses droits interdisent sont déjà vides.
            $pour = static fn (string $canal): array => array_values(array_filter($comptes, static fn (CompteClient $c): bool => \in_array($canal, PreferencesNotifications::canaux($c, $categorie), true)));
            $canaux = [];
            if ($this->parPush($pour(PreferencesNotifications::APP), $n['titre'], $message, $this->urls->generate($n['lien'][0], $n['lien'][1]), $cle)) {
                $canaux[] = NotificationClientEnvoyee::PUSH;
            }
            $parSms = $pour(PreferencesNotifications::SMS);
            if ([] !== $parSms && $this->smsPossible($client)) {
                if (PreferencesNotifications::heuresCalmes($maintenant) && !PreferencesNotifications::livraisonDuJour($n, $maintenant)) {
                    // Heures calmes : le SMS attend 7 h (envoyerDifferees), il n'est pas perdu.
                    foreach ($parSms as $c) {
                        $this->em->persist(new NotificationClientDifferee($c, $cle, $n['type'], PreferencesNotifications::SMS, $message, \DateTimeImmutable::createFromInterface($n['date']), PreferencesNotifications::finHeuresCalmes($maintenant)));
                    }
                    $canaux[] = NotificationClientEnvoyee::DIFFERE;
                } elseif ($this->parSms($parSms, $message)) {
                    $canaux[] = NotificationClientEnvoyee::SMS;
                }
            }
            if ([] !== $pour(PreferencesNotifications::EMAIL) && $this->parEmail($client, $n['titre'], $message, $this->urls->generate($n['lien'][0], $n['lien'][1], UrlGeneratorInterface::ABSOLUTE_URL))) {
                $canaux[] = NotificationClientEnvoyee::EMAIL;
            }
            $canal = $canaux[0] ?? NotificationClientEnvoyee::AUCUN;
            $this->em->persist(new NotificationClientEnvoyee($client, $cle, $n['type'], $canal, $maintenant));
            $deja[$cle] = true;
            $envoyees[] = ['type' => $n['type'], 'canal' => $canal];
        }
        if ([] !== $envoyees) {
            $this->em->flush();
        }

        return $envoyees;
    }

    /**
     * SMS mis de côté pendant les heures calmes, à 7 h (tâche
     * app:espace:notifications). Revérifiés au départ : la personne veut
     * toujours ce SMS, elle en a toujours le droit, et l'événement a moins
     * de 12 h ; sinon il est noté périmé (il reste dans son fil).
     *
     * @return array{envoyes: int, perimes: int, echecs: int}
     */
    public function envoyerDifferees(\DateTimeImmutable $maintenant): array
    {
        $bilan = ['envoyes' => 0, 'perimes' => 0, 'echecs' => 0];
        if (PreferencesNotifications::heuresCalmes($maintenant)) {
            return $bilan;
        }
        $attente = array_values(array_filter(
            $this->em->getRepository(NotificationClientDifferee::class)->findBy(['envoyeLe' => null], ['id' => 'ASC'], 500),
            static fn (NotificationClientDifferee $d): bool => $d->getPrevuLe() <= $maintenant,
        ));
        $depuis = $maintenant->modify(\sprintf('-%d hours', self::FRAICHEUR_HEURES));
        $partis = [];
        foreach ($attente as $d) {
            $compte = $d->getCompte();
            $client = $compte->getClient();
            $categorie = PreferencesNotifications::CATEGORIE_DU_TYPE[$d->getType()] ?? null;
            $toujours = null !== $client && null !== $categorie && $compte->isActif() && \in_array($d->getCanal(), PreferencesNotifications::canaux($compte, $categorie), true);
            $numero = CompteClient::normaliserTelephone($compte->getTelephone()) ?? $compte->getTelephone();
            if (!$toujours || $d->getEvenementLe() < $depuis || !$this->smsPossible($client) || isset($partis[$numero.'|'.$d->getCle()])) {
                $d->terminer(NotificationClientDifferee::PERIMEE, $maintenant);
                ++$bilan['perimes'];
                continue;
            }
            $partis[$numero.'|'.$d->getCle()] = true;
            if ($this->parSms([$compte], $d->getMessage())) {
                $d->terminer(NotificationClientDifferee::ENVOYEE, $maintenant);
                ++$bilan['envoyes'];
            } else {
                $d->terminer(NotificationClientDifferee::ECHEC, $maintenant);
                ++$bilan['echecs'];
            }
        }
        if ([] !== $attente) {
            $this->em->flush();
        }

        return $bilan;
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
     * @param array{type: string, texte: string, titre?: string, numero?: ?string, jour?: ?\DateTimeInterface} $n
     */
    public static function message(array $n): string
    {
        $numero = (string) ($n['numero'] ?? '');

        return match ($n['type']) {
            'bat' => \sprintf('Votre BAT pour la commande %s est prêt à valider.', $numero),
            'impression' => \sprintf('Votre commande %s est en impression.', $numero),
            'prete' => \sprintf('Votre commande %s est prête%s', $numero, str_contains($n['texte'], 'livraison') ? ' : la livraison arrive bientôt.' : ' à retirer en boutique.'),
            'livraison_prevue' => \sprintf('Livraison de votre commande %s prévue le %s.', $numero, null !== ($n['jour'] ?? null) ? $n['jour']->format('d/m') : 'prochainement'),
            'route' => \sprintf('Le livreur est en route avec votre commande %s.', $numero),
            'livree' => \sprintf('Votre commande %s a été livrée. Merci !', $numero),
            'paiement' => \sprintf('Paiement reçu pour la commande %s. Merci !', $numero),
            'facture' => \sprintf('Votre facture %s est disponible.', $numero),
            'echeance' => \sprintf('Rappel : la facture %s est à régler.', $numero),
            'devis' => \sprintf('Votre devis %s est prêt.', $numero),
            'message' => 'L\'atelier a répondu à votre message.',
            'reclamation' => \sprintf('Nous avons répondu à votre réclamation %s.', $numero),
            // Demande de conception : prise en charge, terminée ou annulée (jamais de montant).
            'conception' => \sprintf('%s : %s.', $n['titre'] ?? 'Demande de conception', $numero),
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

    /** SMS permis pour cette fiche : pas une démonstration, SMS acceptés, service configuré. */
    private function smsPossible(Clients $client): bool
    {
        return !self::estDemo($client) && $client->isRecevoirSms() && $this->sms->estConfigure();
    }

    /**
     * Un SMS par numéro, sur le téléphone de chaque personne.
     *
     * @param list<CompteClient> $comptes
     */
    private function parSms(array $comptes, string $message): bool
    {
        $numeros = array_unique(array_filter(array_map(static fn (CompteClient $c): string => trim($c->getTelephone()), $comptes)));
        $parti = false;
        foreach ($numeros as $numero) {
            $parti = (bool) ($this->sms->envoyerSms($numero, 'Succès Imprim : '.$message.' Détails dans votre espace client.')['succes'] ?? false) || $parti;
        }

        return $parti;
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
