<?php

namespace App\EventSubscriber;

use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Envoi d'une note vocale : l'enregistreur de la page (public/js/note-vocale.js)
 * attend du JSON avec « erreur ». Sans ce relais, une page d'erreur Symfony
 * revenait et la personne ne lisait que « Envoi impossible : vérifiez la
 * connexion », quelle que soit la cause (base pas à jour, page expirée,
 * droits). Le message dit maintenant quoi faire.
 */
final class NotesVocalesErreursSubscriber implements EventSubscriberInterface
{
    /** Routes d'envoi (pas l'écoute, qui répond en audio). */
    public const ROUTES = ['app_messages_clients_vocale', 'app_messages_clients_vocale_commande', 'app_espace_message_vocale'];

    public function __construct(
        private readonly Security $security,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Avant le pare-feu (priorité 1), qui changerait un refus en redirection.
        return [KernelEvents::EXCEPTION => ['surErreur', 2]];
    }

    public function surErreur(ExceptionEvent $event): void
    {
        $route = (string) $event->getRequest()->attributes->get('_route');
        if (!\in_array($route, self::ROUTES, true)) {
            return;
        }
        [$message, $code] = $this->traduire($event->getThrowable(), 'app_espace_message_vocale' === $route);
        $event->setResponse(new JsonResponse(['erreur' => $message], $code));
    }

    /**
     * @return array{0: string, 1: int}
     */
    public function traduire(\Throwable $e, bool $client): array
    {
        if ($e instanceof AccessDeniedException) {
            if (null === $this->security->getUser()) {
                return ['Session expirée : reconnectez-vous, puis réenregistrez la note.', Response::HTTP_UNAUTHORIZED];
            }
            if (str_contains($e->getMessage(), 'Jeton')) {
                return ['Page expirée : rechargez la page, puis réenregistrez la note.', Response::HTTP_FORBIDDEN];
            }
            if (str_contains($e->getMessage(), 'ERP')) {
                return [$e->getMessage(), Response::HTTP_FORBIDDEN];
            }

            return ['Vous n\'avez pas le droit d\'envoyer une note dans cette conversation.', Response::HTTP_FORBIDDEN];
        }
        if ($e instanceof HttpExceptionInterface && Response::HTTP_NOT_FOUND === $e->getStatusCode()) {
            return ['Conversation introuvable : rechargez la page.', Response::HTTP_NOT_FOUND];
        }
        if ($e instanceof HttpExceptionInterface && Response::HTTP_TOO_MANY_REQUESTS === $e->getStatusCode()) {
            return ['Trop de messages envoyés : réessayez dans un moment.', Response::HTTP_TOO_MANY_REQUESTS];
        }

        $this->logger->error('Note vocale non enregistrée : {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        if (self::baseAncienne($e)) {
            return [$client
                ? 'Les notes vocales ne sont pas encore disponibles : l\'imprimerie est prévenue.'
                : 'La base de données n\'est pas à jour : lancez « php bin/console doctrine:migrations:migrate -n », puis réessayez.', Response::HTTP_INTERNAL_SERVER_ERROR];
        }

        return [$client
            ? 'Note vocale non enregistrée : réessayez dans un moment.'
            : 'Note vocale non enregistrée (erreur du serveur, notée dans var/log). Message : '.mb_substr($e->getMessage(), 0, 200), Response::HTTP_INTERNAL_SERVER_ERROR];
    }

    private static function baseAncienne(\Throwable $e): bool
    {
        for (; null !== $e; $e = $e->getPrevious()) {
            if ($e instanceof InvalidFieldNameException || $e instanceof TableNotFoundException) {
                return true;
            }
        }

        return false;
    }
}
