<?php

namespace App\Service\Alertes;

use App\Entity\AlerteSysteme;
use App\Entity\SynchroJournal;
use App\Entity\User;
use App\Repository\AlerteSystemeRepository;
use App\Repository\UserRepository;
use App\Service\Messagerie\JournalEnvois;
use App\Service\NotificationService;
use App\Service\Synchro\CatalogueSynchro;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;
use Twig\Environment;

/**
 * Alertes système (docs/alertes-systeme.md) : note une panne, regroupe les
 * répétitions et prévient vite les bonnes personnes, par e-mail et dans
 * l'application (cloche, et téléphone quand les notifications y sont
 * activées : EnvoiPush envoie chaque notification).
 *
 * 1. signaler() écrit tout de suite, directement en base (DBAL) : la même
 *    panne non résolue fait monter son compteur, une nouvelle crée une
 *    alerte « à prévenir ». Le gestionnaire d'entités n'est pas touché (il
 *    peut être cassé par l'erreur qu'on signale). En ligne, l'alerte part
 *    dans la file de synchronisation et monte au local.
 * 2. À la fin de la requête ou de la commande (et chaque 5 minutes par
 *    app:alertes), traiterEnAttente() envoie ce qui attend : un seul
 *    e-mail par sorte et par délai réglé (15 min par défaut), qui liste les
 *    alertes nouvelles ; ce qui arrive pendant le délai part au suivant.
 *
 * Rien ne casse si l'alerte elle-même échoue : tout est rattrapé, et un
 * verrou empêche une alerte de naître pendant qu'on en écrit ou en envoie
 * une (pas de boucle quand l'envoi de l'e-mail échoue à son tour).
 *
 * Né en ligne : l'e-mail part du serveur en ligne (ses destinataires, plus
 * ALERTES_EMAIL) ; au local, l'alerte arrive dans la cloche et sur le
 * téléphone, sans second e-mail.
 */
#[AsEntityListener(event: Events::postPersist, entity: AlerteSysteme::class)]
#[AsEntityListener(event: Events::postUpdate, entity: AlerteSysteme::class)]
final class AlertesSysteme implements EventSubscriberInterface
{
    public const TYPE_SYNCHRO = 'alerte_systeme';

    /** Type de l'e-mail dans l'historique des envois (EnvoiMessage::TYPES). */
    public const TYPE_EMAIL = 'alerte_systeme';

    /** Rôles qui reçoivent et ouvrent les alertes : la direction et le droit « Alertes système ». */
    public const DESTINATAIRES = ['ROLE_ADMIN', 'ROLE_ALERTES_SYSTEME'];

    public const ROUTE_LISTE = 'app_alertes_systeme_index';
    public const ROUTE_VOIR = 'app_alertes_systeme_voir';

    /** Alertes résolues gardées 90 jours. */
    public const GARDE_JOURS = 90;

    /** Verrou : une alerte en cours d'écriture ou d'envoi n'en fait pas naître une autre. */
    private static bool $enCours = false;

    private bool $aTraiter = false;

    public function __construct(
        private readonly Connection $connexion,
        private readonly ManagerRegistry $doctrine,
        private readonly ReglagesAlertes $reglages,
        private readonly NotificationService $notifications,
        private readonly UserRepository $utilisateurs,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urls,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')] private readonly string $expediteur,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $messagerie,
        #[Autowire('%env(default::ALERTES_EMAIL)%')] private readonly ?string $alertesEmail = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Avant EnvoiPush (priorité 0) : les notifications créées ici partent sur les téléphones dans la foulée.
        return [KernelEvents::TERMINATE => ['finDeRequete', 16], ConsoleEvents::TERMINATE => ['finDeRequete', 16]];
    }

    public function estEnLigne(): bool
    {
        return $this->enLigne;
    }

    /** Pas d'alerte pendant les tests automatiques (ni pendant qu'on en écrit une). */
    public function estActif(): bool
    {
        return 'test' !== $this->environnement && !self::$enCours;
    }

    /**
     * Note une panne. Même empreinte encore ouverte : le compteur monte et
     * personne n'est prévenu à nouveau. Rend l'id de l'alerte, ou null si
     * elle n'a pas pu être notée (jamais d'exception).
     *
     * @param list<string> $reconnaissance ce qui reconnaît la même panne (classe, fichier, route…)
     */
    public function signaler(string $type, string $titre, ?string $detail, array $reconnaissance, string $gravite = AlerteSysteme::CRITIQUE, ?\DateTimeImmutable $le = null): ?int
    {
        if (!$this->estActif() || !isset(AlerteSysteme::TYPES[$type])) {
            return null;
        }
        self::$enCours = true;
        try {
            $le ??= new \DateTimeImmutable();
            $empreinte = NettoyageAlerte::empreinte($type, ...$reconnaissance);
            $titre = NettoyageAlerte::titre($titre);
            $detail = null === $detail ? null : mb_substr($detail, 0, AlerteSysteme::LONGUEUR_DETAIL);
            $gravite = isset(AlerteSysteme::GRAVITES[$gravite]) ? $gravite : AlerteSysteme::CRITIQUE;

            $id = $this->connexion->transactional(function (Connection $c) use ($type, $titre, $detail, $empreinte, $gravite, $le): int {
                $quand = $le->format('Y-m-d H:i:s');
                $existante = $c->fetchAssociative('SELECT id, public_id FROM alerte_systeme WHERE empreinte = ? AND etat <> ? ORDER BY id DESC LIMIT 1', [$empreinte, AlerteSysteme::RESOLUE]);
                if (false !== $existante) {
                    $c->executeStatement('UPDATE alerte_systeme SET occurrences = occurrences + 1, derniere_le = ?, detail = COALESCE(detail, ?) WHERE id = ?', [$quand, $detail, (int) $existante['id']], [ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER]);
                    $this->noterSynchro($c, Uuid::fromBinary($existante['public_id']));

                    return (int) $existante['id'];
                }
                $publicId = Uuid::v7();
                $c->insert('alerte_systeme', [
                    'public_id' => $publicId->toBinary(),
                    'reference' => '',
                    'type' => $type,
                    'gravite' => $gravite,
                    'titre' => $titre,
                    'detail' => $detail,
                    'empreinte' => $empreinte,
                    'origine' => $this->enLigne ? AlerteSysteme::EN_LIGNE : AlerteSysteme::LOCAL,
                    'occurrences' => 1,
                    'premiere_le' => $quand,
                    'derniere_le' => $quand,
                    'etat' => AlerteSysteme::OUVERTE,
                    'a_prevenir' => 1,
                ], ['public_id' => ParameterType::BINARY, 'occurrences' => ParameterType::INTEGER, 'a_prevenir' => ParameterType::INTEGER]);
                $id = (int) $c->lastInsertId();
                $c->update('alerte_systeme', ['reference' => self::reference($id, $this->enLigne)], ['id' => $id]);
                $this->noterSynchro($c, $publicId);

                return $id;
            });
            $this->aTraiter = true;

            return $id;
        } catch (\Throwable $e) {
            // Jamais d'exception ni de nouvelle alerte : une ligne au journal du serveur, sans détail.
            $this->logger->warning('Alertes système : alerte non notée ({classe}).', ['classe' => $e::class]);

            return null;
        } finally {
            self::$enCours = false;
        }
    }

    /** « AL-000042 » au local, « W-AL-000042 » en ligne. */
    public static function reference(int $id, bool $enLigne): string
    {
        return ($enLigne ? 'W-' : '').'AL-'.str_pad((string) $id, 6, '0', \STR_PAD_LEFT);
    }

    /**
     * La situation est revenue à la normale (sauvegarde refaite, disque
     * libéré, synchronisation repartie) : les alertes non résolues de la
     * sorte (et de l'empreinte, si donnée) sont résolues toutes seules.
     *
     * @param list<string>|null $reconnaissance
     */
    public function resoudreAutomatiquement(string $type, ?array $reconnaissance, string $texte, ?\DateTimeImmutable $le = null): int
    {
        if (!$this->estActif()) {
            return 0;
        }
        try {
            $em = $this->em();
            $empreinte = null === $reconnaissance ? null : NettoyageAlerte::empreinte($type, ...$reconnaissance);
            $n = 0;
            foreach ($this->depot($em)->nonResoluesDuType($type) as $alerte) {
                if (null === $empreinte || $alerte->getEmpreinte() === $empreinte) {
                    $alerte->resoudre(null, $texte, $le ?? new \DateTimeImmutable());
                    ++$n;
                }
            }
            if ($n > 0) {
                $em->flush();
            }

            return $n;
        } catch (\Throwable $e) {
            $this->logger->warning('Alertes système : résolution automatique impossible ({classe}).', ['classe' => $e::class]);

            return 0;
        }
    }

    public function postPersist(AlerteSysteme $alerte): void
    {
        // Copie d'une alerte née en ligne, reçue par la synchronisation : la cloche du local la montre.
        $this->aTraiter = $this->aTraiter || $alerte->isAPrevenir();
    }

    public function postUpdate(AlerteSysteme $alerte): void
    {
        // Rouverte (la panne est revenue en ligne) : à prévenir de nouveau.
        $this->aTraiter = $this->aTraiter || $alerte->isAPrevenir();
    }

    public function finDeRequete(): void
    {
        if ($this->aTraiter) {
            $this->aTraiter = false;
            $this->traiterEnAttente();
        }
    }

    /**
     * Envoie ce qui attend, sorte par sorte, si le délai depuis le dernier
     * envoi de la sorte est passé.
     *
     * @return array{envoyees: int, attente: int, emails: int}
     */
    public function traiterEnAttente(?\DateTimeImmutable $maintenant = null): array
    {
        $bilan = ['envoyees' => 0, 'attente' => 0, 'emails' => 0];
        if (!$this->estActif()) {
            return $bilan;
        }
        self::$enCours = true;
        try {
            $maintenant ??= new \DateTimeImmutable();
            $em = $this->em();
            $parType = [];
            foreach ($this->depot($em)->aPrevenir() as $alerte) {
                if ($alerte->estResolue()) {
                    $alerte->marquerPrevenue($maintenant, false);
                    continue;
                }
                $parType[$alerte->getType()][] = $alerte;
            }
            $envois = $this->reglages->envois();
            foreach ($parType as $type => $alertes) {
                if (!ReglagesAlertes::peutEnvoyer($envois[$type] ?? null, $this->reglages->delaiMinutes(), $maintenant)) {
                    $bilan['attente'] += \count($alertes);
                    continue;
                }
                $emailParti = $this->prevenir($type, $alertes);
                foreach ($alertes as $alerte) {
                    $alerte->marquerPrevenue($maintenant, $emailParti && !($alerte->estEnLigne() && !$this->enLigne));
                }
                $this->reglages->noterEnvoi($type, $maintenant);
                $bilan['envoyees'] += \count($alertes);
                $bilan['emails'] += $emailParti ? 1 : 0;
            }
            $em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('Alertes système : envoi impossible ({classe}).', ['classe' => $e::class]);
        } finally {
            self::$enCours = false;
        }

        return $bilan;
    }

    /**
     * Cloche (et téléphone) des destinataires de la sorte, puis un e-mail
     * qui liste les alertes. Rend vrai si l'e-mail est parti.
     *
     * @param list<AlerteSysteme> $alertes
     */
    private function prevenir(string $type, array $alertes): bool
    {
        $reglage = $this->reglages->pourType($type);
        $personnes = $this->destinataires($type);
        $premiere = $alertes[0];
        $libelle = AlerteSysteme::TYPES[$type]['libelle'];

        if ($reglage['app']) {
            [$message, $route, $parametres] = 1 === \count($alertes)
                ? [\sprintf('Alerte système %s · %s : %s', $premiere->getReference(), $libelle, $premiere->getTitre()), self::ROUTE_VOIR, ['id' => $premiere->getId()]]
                : [\sprintf('%d alertes système · %s : %s%s', \count($alertes), $libelle, implode(', ', array_map(static fn (AlerteSysteme $a): string => $a->getReference(), \array_slice($alertes, 0, 4))), \count($alertes) > 4 ? '…' : ''), self::ROUTE_LISTE, ['type' => $type]];
            foreach ($personnes as $personne) {
                $this->notifications->notifierUtilisateur($personne, mb_substr($message, 0, 255), $route, $parametres);
            }
        }

        // Une alerte née en ligne a déjà eu son e-mail, parti du serveur en ligne.
        $aEcrire = array_values(array_filter($alertes, fn (AlerteSysteme $a): bool => $this->enLigne || !$a->estEnLigne()));
        if (!$reglage['email'] || [] === $aEcrire) {
            return false;
        }

        return $this->ecrire($type, $aEcrire, $this->adresses($personnes));
    }

    /**
     * Personnes actives qui reçoivent la sorte : la direction et le droit
     * « Alertes système », ou seulement celles cochées dans les réglages.
     *
     * @return list<User>
     */
    public function destinataires(string $type): array
    {
        $choisies = $this->reglages->pourType($type)['destinataires'];
        $personnes = $this->possibles();
        if ([] !== $choisies) {
            $personnes = array_values(array_filter($personnes, static fn (User $u): bool => \in_array($u->getId(), $choisies, true)));
        }

        return $personnes;
    }

    /**
     * Personnes qui peuvent recevoir les alertes (et ouvrir l'écran).
     *
     * @return list<User>
     */
    public function possibles(): array
    {
        return array_values(array_filter(
            $this->utilisateurs->findBy(['actif' => true], ['username' => 'ASC']),
            static fn (User $u): bool => [] !== array_intersect(self::DESTINATAIRES, $u->getRoles()),
        ));
    }

    /**
     * Adresses de l'e-mail : celles des fiches employé des personnes, les
     * adresses en plus des réglages et ALERTES_EMAIL (.env.local).
     *
     * @param list<User> $personnes
     *
     * @return list<string>
     */
    private function adresses(array $personnes): array
    {
        $adresses = [];
        foreach ($personnes as $personne) {
            $adresses[] = trim((string) $personne->getEmploye()?->getEmail());
        }
        array_push($adresses, ...$this->reglages->autresEmails(), ...(preg_split('/[\s,;]+/', (string) $this->alertesEmail) ?: []));
        $adresses = array_map('mb_strtolower', array_filter($adresses, static fn (string $a): bool => '' !== $a && false !== filter_var($a, \FILTER_VALIDATE_EMAIL)));

        return array_values(array_unique($adresses));
    }

    /**
     * L'e-mail : référence, sorte, titre, nombre de fois, dates, lien vers
     * l'écran. Jamais le détail technique, ni donnée de client.
     *
     * @param list<AlerteSysteme> $alertes
     * @param list<string>        $adresses
     */
    private function ecrire(string $type, array $alertes, array $adresses): bool
    {
        // Messagerie non réglée (null://, développement) : rien ne partirait, l'alerte reste dans l'application.
        if ([] === $adresses || '' === trim($this->expediteur) || '' === trim($this->messagerie) || str_starts_with(trim($this->messagerie), 'null://')) {
            return false;
        }
        $lignes = array_map(fn (AlerteSysteme $a): array => [
            'alerte' => $a,
            'lien' => $this->urls->generate(self::ROUTE_VOIR, ['id' => $a->getId()], UrlGeneratorInterface::ABSOLUTE_URL),
        ], $alertes);
        $libelle = AlerteSysteme::TYPES[$type]['libelle'];
        $sujet = 1 === \count($alertes)
            ? \sprintf('Alerte système %s : %s', $alertes[0]->getReference(), $libelle)
            : \sprintf('%d alertes système : %s', \count($alertes), $libelle);
        $contexte = [
            'sujet' => $sujet,
            'libelle' => $libelle,
            'lignes' => $lignes,
            'liste' => $this->urls->generate(self::ROUTE_LISTE, [], UrlGeneratorInterface::ABSOLUTE_URL),
            'serveur' => $this->enLigne ? 'site en ligne' : 'ERP de la boutique',
            'delai' => $this->reglages->delaiMinutes(),
        ];
        $email = (new Email())
            ->from(new Address(trim($this->expediteur), 'Succès Imprim'))
            ->subject('[Succès Imprim] '.$sujet)
            ->text($this->twig->render('alertes_systeme/email.txt.twig', $contexte))
            ->html($this->twig->render('alertes_systeme/email.html.twig', $contexte));
        // Une adresse par message : personne ne voit les autres destinataires.
        $parti = false;
        foreach ($adresses as $adresse) {
            try {
                $this->mailer->send(JournalEnvois::typer((clone $email)->to($adresse), self::TYPE_EMAIL));
                $parti = true;
            } catch (\Throwable $e) {
                $this->logger->warning('Alertes système : e-mail non parti ({classe}).', ['classe' => $e::class]);
            }
        }

        return $parti;
    }

    /** En ligne, l'alerte écrite ou mise à jour part dans la file de synchronisation (comme JournalEnvois). */
    private function noterSynchro(Connection $c, Uuid $publicId): void
    {
        if (!$this->enLigne || !CatalogueSynchro::envoye(self::TYPE_SYNCHRO, true)) {
            return;
        }
        $c->insert('synchro_journal', [
            'evenement' => Uuid::v7()->toRfc4122(),
            'type' => self::TYPE_SYNCHRO,
            'public_id' => $publicId->toRfc4122(),
            'operation' => SynchroJournal::MAJ,
            'cree_le' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'tentatives' => 0,
            'ignore_manuel' => 0,
        ], ['tentatives' => ParameterType::INTEGER, 'ignore_manuel' => ParameterType::INTEGER]);
    }

    /** Le gestionnaire d'entités, rouvert s'il a été fermé par l'erreur qu'on signale. */
    private function em(): EntityManagerInterface
    {
        $em = $this->doctrine->getManager();
        \assert($em instanceof EntityManagerInterface);
        if (!$em->isOpen()) {
            $this->doctrine->resetManager();
            $em = $this->doctrine->getManager();
            \assert($em instanceof EntityManagerInterface);
        }

        return $em;
    }

    private function depot(EntityManagerInterface $em): AlerteSystemeRepository
    {
        $depot = $em->getRepository(AlerteSysteme::class);
        \assert($depot instanceof AlerteSystemeRepository);

        return $depot;
    }
}
