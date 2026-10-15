<?php

namespace App\Service\Recette;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Symfony\Bridge\Monolog\Handler\ConsoleHandler;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Navigateur sans réseau pour la recette des accès (app:recette:acces) :
 * chaque page est demandée à l'application elle-même, dans ce processus,
 * comme le ferait un navigateur connecté avec le compte choisi.
 *
 * Rien ne reste : tout se passe dans une transaction annulée à la fin
 * (terminer()), la fin de requête (kernel.terminate, où partent les
 * notifications différées) n'est jamais jouée, et les sessions sont en
 * mémoire. Le profileur est coupé et le journal ne garde que les
 * avertissements, pour ne pas remplir le disque.
 */
final class NavigateurInterne
{
    /** Adresse de test (RFC 5737) : les limites par adresse des vrais visiteurs ne bougent pas. */
    public const IP = '192.0.2.77';

    /** Statut rendu à la place du contrôleur quand la requête est interceptée (rien n'est exécuté). */
    public const CONTROLEUR_ATTEINT = 299;

    private ?\Throwable $derniereErreur = null;
    private int $ecritures = 0;
    private bool $commence = false;
    private bool $intercepter = false;

    public function __construct(
        #[Autowire(service: 'http_kernel')] private readonly HttpKernelInterface $noyau,
        #[Autowire(service: 'services_resetter')] private readonly ResetInterface $remise,
        private readonly Connection $base,
        private readonly EntityManagerInterface $em,
        private readonly EventDispatcherInterface $evenements,
        #[Autowire(service: 'profiler')] private readonly ?Profiler $profileur = null,
        #[Autowire(service: 'monolog.handler.main')] private readonly ?object $journal = null,
        #[Autowire(service: 'monolog.handler.console')] private readonly ?object $console = null,
    ) {
    }

    public function commencer(): void
    {
        if ($this->commence) {
            return;
        }
        $this->profileur?->disable();
        if ($this->journal instanceof AbstractHandler) {
            $this->journal->setLevel(Level::Warning);
        }
        // Chaque page refusée est une « exception » pour le journal : pas sur l'écran de la commande.
        if ($this->console instanceof ConsoleHandler) {
            $this->console->setOutput(new NullOutput());
        }
        $this->evenements->addListener(KernelEvents::EXCEPTION, function (ExceptionEvent $e): void {
            $this->derniereErreur = $e->getThrowable();
        }, 1000);
        // Après #[IsGranted] (20) : la requête a passé tous les contrôles, le contrôleur n'est pas joué.
        $this->evenements->addListener(KernelEvents::CONTROLLER_ARGUMENTS, function (ControllerArgumentsEvent $e): void {
            if ($this->intercepter && $e->isMainRequest()) {
                $e->setController(static fn (): Response => new Response('', self::CONTROLEUR_ATTEINT));
                $e->setArguments([]);
            }
        }, -1000);
        // Une page ouverte en lecture ne devrait rien enregistrer : on compte ce qu'elle tente.
        $this->em->getEventManager()->addEventListener([Events::onFlush], $this);
        $this->base->beginTransaction();
        $this->commence = true;
    }

    /** Annule tout ce que les pages ont pu écrire. */
    public function terminer(): void
    {
        if (!$this->commence) {
            return;
        }
        $this->commence = false;
        while ($this->base->isTransactionActive()) {
            $this->base->rollBack();
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $uow = $args->getObjectManager()->getUnitOfWork();
        $this->ecritures += \count($uow->getScheduledEntityInsertions()) + \count($uow->getScheduledEntityUpdates()) + \count($uow->getScheduledEntityDeletions());
    }

    /**
     * Session d'un compte (personnel : pare-feu « main », client : « espace »).
     * La même session sert à toutes les pages de ce compte.
     *
     * @param array<string, mixed> $valeurs autres clés de session à poser
     */
    public function session(?UserInterface $compte, string $pareFeu = 'main', array $valeurs = []): MockArraySessionStorage
    {
        $stockage = new MockArraySessionStorage();
        $session = new Session($stockage);
        $session->start();
        if (null !== $compte) {
            $session->set('_security_'.$pareFeu, serialize(new UsernamePasswordToken($compte, $pareFeu, $compte->getRoles())));
        }
        foreach ($valeurs as $cle => $valeur) {
            $session->set($cle, $valeur);
        }
        $session->save();

        return $stockage;
    }

    /**
     * @param array<string, string> $entetes
     * @param bool                  $intercepter ne pas jouer le contrôleur (statut CONTROLEUR_ATTEINT s'il est atteint)
     * @param string|null           $adresse     adresse du poste qui demande la page (par défaut IP)
     */
    public function get(string $chemin, ?MockArraySessionStorage $session = null, array $entetes = [], bool $intercepter = false, ?string $adresse = null): Reponse
    {
        return $this->envoyer(Request::create($chemin, 'GET', [], [], [], $this->serveur($entetes, $adresse)), $session, $intercepter);
    }

    /**
     * @param array<string, mixed>  $donnees
     * @param array<string, string> $entetes
     * @param bool                  $intercepter ne pas jouer le contrôleur (statut CONTROLEUR_ATTEINT s'il est atteint)
     */
    public function post(string $chemin, array $donnees, ?MockArraySessionStorage $session = null, array $entetes = [], bool $intercepter = false): Reponse
    {
        return $this->envoyer(Request::create($chemin, 'POST', $donnees, [], [], $this->serveur($entetes)), $session, $intercepter);
    }

    /**
     * Appel JSON d'une page (corps JSON, comme fetch() dans le navigateur).
     *
     * @param array<string, mixed>  $donnees
     * @param array<string, string> $entetes
     */
    public function postJson(string $chemin, array $donnees, ?MockArraySessionStorage $session = null, array $entetes = []): Reponse
    {
        $serveur = $this->serveur($entetes + ['Accept' => 'application/json']) + ['CONTENT_TYPE' => 'application/json'];

        return $this->envoyer(Request::create($chemin, 'POST', [], [], [], $serveur, (string) json_encode($donnees)), $session, false);
    }

    private function envoyer(Request $requete, ?MockArraySessionStorage $stockage, bool $intercepter): Reponse
    {
        if (!$this->commence) {
            throw new \LogicException('Appeler commencer() avant la première page.');
        }
        $this->derniereErreur = null;
        $this->ecritures = 0;
        $this->intercepter = $intercepter;
        $session = new Session($stockage ?? new MockArraySessionStorage());
        $requete->setSession($session);
        if (null !== $stockage && '' !== $stockage->getId()) {
            // Sans le cookie, le pare-feu ne relit pas la session (Request::hasPreviousSession()).
            $requete->cookies->set($session->getName(), $stockage->getId());
        }

        try {
            $reponse = $this->noyau->handle($requete, HttpKernelInterface::MAIN_REQUEST, true);
            $resultat = new Reponse(
                $reponse->getStatusCode(),
                (string) $reponse->headers->get('Location', ''),
                (string) $reponse->headers->get('Content-Type', ''),
                // Fichier envoyé (PDF, image, Excel) : seul l'en-tête compte.
                false === $reponse->getContent() ? '' : (string) $reponse->getContent(),
                self::resumerErreur($this->derniereErreur, $reponse->getStatusCode()),
                $this->ecritures,
                array_map(static fn (array $valeurs): string => (string) ($valeurs[0] ?? ''), $reponse->headers->all()),
            );
        } catch (\Throwable $e) {
            $resultat = new Reponse(500, '', '', '', self::resumerErreur($e, 500), $this->ecritures);
        } finally {
            $this->intercepter = false;
            if ($requete->getSession()->isStarted()) {
                $requete->getSession()->save();
            }
            $this->remise->reset();
            // Une page qui a fermé le gestionnaire d'entités (erreur pendant un enregistrement) ne bloque pas les suivantes.
            if (!$this->base->isTransactionActive()) {
                $this->base->beginTransaction();
            }
        }

        return $resultat;
    }

    /**
     * @param array<string, string> $entetes
     *
     * @return array<string, string>
     */
    private function serveur(array $entetes, ?string $adresse = null): array
    {
        $serveur = ['REMOTE_ADDR' => $adresse ?? self::IP, 'HTTP_HOST' => 'recette.local', 'HTTP_USER_AGENT' => 'Recette des accès (app:recette:acces)', 'HTTP_ACCEPT' => 'text/html,*/*'];
        foreach ($entetes as $nom => $valeur) {
            $serveur['HTTP_'.strtoupper(str_replace('-', '_', $nom))] = $valeur;
        }

        return $serveur;
    }

    private static function resumerErreur(?\Throwable $e, int $statut): ?string
    {
        if (null === $e || $statut < 500) {
            return null;
        }
        while (null !== $e->getPrevious() && str_contains($e::class, 'Twig\\Error\\RuntimeError')) {
            $e = $e->getPrevious();
        }

        return mb_substr((new \ReflectionClass($e))->getShortName().' : '.$e->getMessage().' ('.basename($e->getFile()).':'.$e->getLine().')', 0, 400);
    }
}
