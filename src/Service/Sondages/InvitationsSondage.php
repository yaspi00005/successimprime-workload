<?php

namespace App\Service\Sondages;

use App\Controller\UtilisateursController;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\InvitationSondage;
use App\Entity\ReponseSondage;
use App\Service\EspaceClient\NotificationsClient;
use App\Service\OrangeSmsService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sondage de commande envoyé à la livraison (docs/sondages.md). Au local,
 * quand la dernière ligne d'une commande passe « livrée », une invitation
 * est créée en fin de requête : son lien ouvre le sondage sans compte sur
 * le site. Le SMS ne part que si Orange SMS et le site sont réglés et que
 * le client accepte les SMS ; jamais pour une fiche de démonstration, et
 * jamais de montant dans le message. L'atelier a aussi le lien WhatsApp
 * prêt dans Clients › Sondages et avis.
 */
final class InvitationsSondage implements EventSubscriberInterface
{
    /** @var array<int, true> ids des commandes dont une ligne vient d'être livrée */
    private array $aVerifier = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Sondages $sondages,
        private readonly OrangeSmsService $sms,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
        #[Autowire('%env(SYNC_URL)%')] private readonly string $urlSite,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'inviterEnAttente', ConsoleEvents::TERMINATE => 'inviterEnAttente'];
    }

    /** Commande dont une ligne vient d'être livrée (LivraisonSondageListener). */
    public function aVerifier(int $commande): void
    {
        if (!$this->enLigne) {
            $this->aVerifier[$commande] = true;
        }
    }

    public function inviterEnAttente(): void
    {
        $ids = array_keys($this->aVerifier);
        $this->aVerifier = [];
        foreach ($ids as $id) {
            try {
                $commande = $this->em->find(Commandes::class, $id);
                if ($commande instanceof Commandes) {
                    $this->inviter($commande, new \DateTimeImmutable(), true);
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Invitation au sondage de la commande {id} non créée : {erreur}', ['id' => $id, 'erreur' => $e->getMessage()]);
            }
        }
    }

    /**
     * Invitation de la commande livrée (créée au besoin), ou null quand
     * elle n'est pas livrée, n'a pas de client ou a déjà son avis.
     */
    public function inviter(Commandes $commande, \DateTimeImmutable $maintenant, bool $sms): ?InvitationSondage
    {
        if ($this->enLigne || null === $commande->getClients() || !Sondages::estLivree($commande)) {
            return null;
        }
        $existante = $this->em->getRepository(InvitationSondage::class)->findOneBy(['commande' => $commande]);
        if (null !== $existante) {
            return $existante;
        }
        if (null !== $this->em->getRepository(ReponseSondage::class)->findOneBy(['commande' => $commande])) {
            return null;
        }
        $invitation = InvitationSondage::pour($commande, $maintenant);
        $this->em->persist($invitation);
        $this->em->flush();
        if ($sms) {
            $this->envoyerSms($invitation, $maintenant);
        }

        return $invitation;
    }

    /** SMS d'invitation, quand tout le permet. */
    public function envoyerSms(InvitationSondage $invitation, \DateTimeImmutable $maintenant): bool
    {
        $client = $invitation->getClient();
        if (null === $client || !self::peutRecevoir($client) || !$client->isRecevoirSms() || !$this->sms->estConfigure() || !$this->siteRegle() || null !== $invitation->getSmsEnvoyeLe()) {
            return false;
        }
        $resultat = $this->sms->envoyerSms((string) $client->getTelephone(), self::message((string) $invitation->getCommande()?->getNumero(), $this->lien($invitation)), type: 'sondage');
        if (!($resultat['succes'] ?? false)) {
            $this->logger->warning('SMS d\'invitation au sondage non envoyé : {erreur}', ['erreur' => $resultat['erreur'] ?? '?']);

            return false;
        }
        $invitation->noterSms($maintenant);
        $this->em->flush();

        return true;
    }

    /** Texte du SMS ou du message WhatsApp : le numéro et le lien, pas de montant. */
    public static function message(string $numero, string $lien): string
    {
        return \sprintf('Succès Imprim : merci pour votre commande %s ! Votre avis nous aide à progresser (1 minute) : %s', $numero, $lien);
    }

    public function lien(InvitationSondage $invitation): string
    {
        return $this->sondages->lienPublic('app_avis_commande', ['jeton' => $invitation->getJeton()]);
    }

    /** Lien WhatsApp prêt pour l'agent, ou null (pas de téléphone, fiche de démonstration). */
    public function whatsapp(InvitationSondage $invitation): ?string
    {
        $client = $invitation->getClient();
        if (null === $client || !self::peutRecevoir($client)) {
            return null;
        }

        return 'https://wa.me/'.UtilisateursController::numeroWhatsApp((string) $client->getTelephone()).'?text='.rawurlencode(self::message((string) $invitation->getCommande()?->getNumero(), $this->lien($invitation)));
    }

    public function noterWhatsapp(InvitationSondage $invitation, \DateTimeImmutable $maintenant): void
    {
        $invitation->noterWhatsapp($maintenant);
        $this->em->flush();
    }

    /** Un vrai client avec un téléphone : jamais une fiche de démonstration (DEMO-…). */
    public static function peutRecevoir(Clients $client): bool
    {
        return !NotificationsClient::estDemo($client) && '' !== trim((string) $client->getTelephone());
    }

    /** Le lien doit mener au site en ligne, où le client répond. */
    private function siteRegle(): bool
    {
        return (bool) preg_match('#^https?://#', $this->urlSite);
    }
}
