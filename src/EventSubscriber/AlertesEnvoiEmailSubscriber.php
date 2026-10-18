<?php

namespace App\EventSubscriber;

use App\Entity\AlerteSysteme;
use App\Service\Alertes\AlertesSysteme;
use App\Service\Alertes\NettoyageAlerte;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mailer\Event\FailedMessageEvent;

/**
 * E-mail refusé par la messagerie (docs/alertes-systeme.md) : une alerte
 * système « Envoi d'e-mail ou de SMS ». La même cause d'échec ne fait
 * qu'une alerte, dont le compteur monte. Ni destinataire ni objet dans
 * l'alerte : la ligne de l'historique des envois (Clients › Crédits SMS et
 * e-mail) les garde, masqués.
 *
 * L'échec de l'e-mail d'une alerte n'en fait pas naître une autre
 * (AlertesSysteme est alors en cours d'envoi).
 */
final class AlertesEnvoiEmailSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AlertesSysteme $alertes)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [FailedMessageEvent::class => ['signaler', -16]];
    }

    public function signaler(FailedMessageEvent $event): void
    {
        if (!$this->alertes->estActif()) {
            return;
        }
        $erreur = $event->getError();
        $this->alertes->signaler(
            AlerteSysteme::ENVOI,
            'E-mail non parti · '.(new \ReflectionClass($erreur))->getShortName(),
            implode("\n", [
                'Erreur : '.$erreur::class,
                'Message : '.NettoyageAlerte::texte($erreur->getMessage(), 1500),
                'Serveur : '.($this->alertes->estEnLigne() ? 'site en ligne' : 'ERP de la boutique'),
                'Voir aussi : Clients › Crédits SMS et e-mail › Historique des envois (lignes « Échec »).',
            ]),
            ['email', $erreur::class, NettoyageAlerte::forme($erreur->getMessage())],
        );
    }
}
