<?php

namespace App\Service\Push;

use App\Entity\AbonnementPush;
use App\Entity\Notification;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Chaque notification de la cloche part aussi sur les téléphones de la
 * personne qui ont accepté les notifications. L'envoi se fait à la fin
 * de la requête (ou de la commande), une fois la page servie, pour ne
 * pas la ralentir.
 */
#[AsEntityListener(event: Events::postPersist, entity: Notification::class)]
final class EnvoiPush implements EventSubscriberInterface
{
    /** @var list<Notification> */
    private array $enAttente = [];

    public function __construct(
        private readonly WebPush $push,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'envoyer', ConsoleEvents::TERMINATE => 'envoyer'];
    }

    public function postPersist(Notification $notification): void
    {
        $this->aEnvoyer($notification);
    }

    /**
     * Une notification nouvelle part d'elle-même (postPersist). Une
     * notification remise à jour (alerte de synchronisation, une par panne)
     * est confiée ici par l'appelant ; sur le téléphone elle remplace la
     * précédente, qui porte le même identifiant.
     */
    public function aEnvoyer(Notification $notification): void
    {
        // Notifications de l'équipe : ni l'info, ni ce qui naît pendant les heures calmes (hors critique).
        if ($this->push->estConfigure() && !$notification->isSilencieuse()) {
            $this->enAttente[] = $notification;
        }
    }

    public function envoyer(): void
    {
        if ($this->enAttente === []) {
            return;
        }
        $notifications = $this->enAttente;
        $this->enAttente = [];
        $parPersonne = [];
        foreach ($notifications as $n) {
            if ($n->getDestinataire() !== null) {
                $parPersonne[$n->getDestinataire()->getId()][] = $n;
            }
        }
        $abonnements = $this->em->getRepository(AbonnementPush::class)->findBy(['utilisateur' => array_keys($parPersonne)]);
        $maintenant = new \DateTimeImmutable();
        $envois = [];
        foreach ($abonnements as $a) {
            foreach ($parPersonne[$a->getUtilisateur()->getId()] ?? [] as $n) {
                try {
                    $envois[] = [$a, $this->push->envoyer($a, self::contenu($n, $n->getId() !== null ? $this->urls->generate('app_notification_ouvrir', ['id' => $n->getId()]) : '/'))];
                } catch (\Throwable $e) {
                    $this->logger->warning('Notification push non chiffrée : {erreur}', ['erreur' => $e->getMessage()]);
                }
            }
        }
        $perimes = [];
        $envoyes = [];
        foreach ($envois as [$a, $reponse]) {
            try {
                $statut = $reponse->getStatusCode();
                if (\in_array($statut, [404, 410], true)) {
                    // Le navigateur a retiré l'autorisation : on oublie ce téléphone.
                    $perimes[] = $a->getId();
                } elseif ($statut < 300) {
                    $envoyes[] = $a->getId();
                } else {
                    $this->logger->warning('Notification push refusée ({statut}).', ['statut' => $statut]);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Notification push non envoyée : {erreur}', ['erreur' => $e->getMessage()]);
            }
        }
        // Requêtes directes : ne pas enregistrer par erreur d'autres changements en cours.
        if ($perimes !== []) {
            $this->em->createQuery('DELETE FROM '.AbonnementPush::class.' a WHERE a.id IN (:ids)')->setParameter('ids', array_unique($perimes))->execute();
        }
        if ($envoyes !== []) {
            $this->em->createQuery('UPDATE '.AbonnementPush::class.' a SET a.dernierEnvoiLe = :le WHERE a.id IN (:ids)')->setParameter('ids', array_unique($envoyes))->setParameter('le', $maintenant)->execute();
        }
    }

    /** @return array{titre: string, texte: string, url: string, tag: string} */
    public static function contenu(Notification $n, string $url): array
    {
        if (null === $n->getType()) {
            return ['titre' => 'Succès Imprim', 'texte' => $n->getMessage(), 'url' => $url, 'tag' => 'si-'.($n->getId() ?? uniqid())];
        }
        // Écran de verrouillage (maquette NT05) : le montant seulement si la personne a le droit de le voir.
        $titre = $n->titrePour($n->getDestinataire()?->getRoles() ?? []);

        return [
            'titre' => 'Succès Imprim · équipe',
            'texte' => (Notification::CRITIQUE === $n->getNiveau() ? '⚠ ' : '').$titre.(null !== $n->getDetail() ? "\n".$n->getDetail() : ''),
            'url' => $url,
            // Même alerte, même étiquette : un rappel remplace le précédent sur le téléphone.
            'tag' => 'si-'.($n->getEmpreinte() !== null ? substr(sha1($n->getEmpreinte()), 0, 16) : ($n->getId() ?? uniqid())),
        ];
    }
}
