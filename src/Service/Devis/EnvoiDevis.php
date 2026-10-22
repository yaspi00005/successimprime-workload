<?php

namespace App\Service\Devis;

use App\Controller\UtilisateursController;
use App\Entity\Devis;
use App\Entity\User;
use App\Service\EspaceClient\NotificationsClient;
use App\Service\Messagerie\Conversations;
use App\Service\Messagerie\JournalEnvois;
use App\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Envoi d'un devis au client et relances (maquettes GC03, GC04, DT03).
 *
 * - Espace client : le devis y apparaît (synchronisation), avec le
 *   message facultatif de la personne qui l'envoie dans la conversation
 *   du devis.
 * - WhatsApp : règle de la messagerie (docs/messagerie-marketing.md).
 *   Clé Meta vide dans .env.local : un lien wa.me avec le message déjà
 *   écrit, que la personne ouvre d'un clic. Clé posée : l'application
 *   envoie elle-même le modèle « document » avec le PDF.
 * - E-mail : le PDF en pièce jointe, si la fiche a une adresse.
 *
 * Les fiches de démonstration (DEMO-) ne reçoivent jamais rien
 * automatiquement ; le lien wa.me reste proposé (c'est une personne qui
 * l'ouvre).
 */
final class EnvoiDevis
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WhatsAppService $whatsApp,
        private readonly Conversations $conversations,
        private readonly PdfDevis $pdf,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire(lazy: true)] private readonly MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')] private readonly string $expediteur,
        #[Autowire('%env(WHATSAPP_TEMPLATE_DOCUMENT)%')] private readonly string $modeleDocument,
        #[Autowire('%env(SYNC_URL)%')] private readonly string $urlSite,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    /**
     * « Envoyer au client » : le devis passe « Envoyé » et part par les
     * canaux cochés.
     *
     * @param list<string> $canaux
     *
     * @return array{whatsapp: ?string, notes: list<string>}
     */
    public function envoyer(Devis $devis, array $canaux, ?string $message, User $par, \DateTimeImmutable $maintenant): array
    {
        SuiviDevis::envoyer($devis, $canaux, SuiviDevis::nomPublic($par), $maintenant);
        $this->em->flush();
        $notes = [];
        $client = $devis->getClients();

        $message = trim((string) $message);
        if ('' !== $message && null !== $client) {
            try {
                // Dans la conversation du devis (docs/messagerie-clients.md), ouverte au besoin.
                $this->conversations->repondre($this->conversations->demarrer($devis, $par, $maintenant), $par, $message, null, null, $maintenant);
            } catch (\DomainException $e) {
                $notes[] = 'Message non envoyé : '.$e->getMessage();
            }
        }

        $whatsapp = null;
        if (\in_array(Devis::CANAL_WHATSAPP, $devis->getCanauxEnvoi(), true)) {
            $resultat = $this->whatsApp($devis, false);
            $whatsapp = $resultat['lien'];
            if (null !== $resultat['note']) {
                $notes[] = $resultat['note'];
            }
        }
        if (\in_array(Devis::CANAL_EMAIL, $devis->getCanauxEnvoi(), true)) {
            $notes[] = $this->email($devis);
        }
        $this->em->flush();

        return ['whatsapp' => $whatsapp, 'notes' => array_values(array_filter($notes))];
    }

    /**
     * Relance WhatsApp à la main (bouton « Relancer ») : rend le lien wa.me
     * à ouvrir (clé Meta vide) ou null si l'application a envoyé elle-même.
     *
     * @return array{lien: ?string, note: ?string}
     */
    public function relancer(Devis $devis, User $par, \DateTimeImmutable $maintenant): array
    {
        $resultat = $this->whatsApp($devis, true);
        if (null !== $resultat['lien'] || $resultat['envoye']) {
            $devis->noterRelance($maintenant, Devis::CANAL_WHATSAPP, false, SuiviDevis::nomPublic($par));
            $this->em->flush();
        }

        return ['lien' => $resultat['lien'], 'note' => $resultat['note']];
    }

    /**
     * Relance automatique (app:devis:relances) : seulement quand la clé
     * Meta est posée ; sinon le devis reste dans « À relancer aujourd'hui »
     * de la liste, pour une relance d'un clic.
     */
    public function relancerAutomatiquement(Devis $devis, \DateTimeImmutable $maintenant): bool
    {
        if (!$this->whatsApp->estConfigure() || null === $devis->getClients() || NotificationsClient::estDemo($devis->getClients())) {
            return false;
        }
        $resultat = $this->whatsApp($devis, true);
        if ($resultat['envoye']) {
            $devis->noterRelance($maintenant, Devis::CANAL_WHATSAPP, true, null);
            $this->em->flush();
        }

        return $resultat['envoye'];
    }

    /** La clé Meta est-elle posée (envoi par l'application) ? */
    public function whatsAppAutomatique(): bool
    {
        return $this->whatsApp->estConfigure();
    }

    /** Lien wa.me avec le message de relance déjà écrit, ou null sans téléphone. */
    public function lienRelance(Devis $devis): ?string
    {
        return $this->lienWaMe($devis, SuiviDevis::messageWhatsApp($devis, $this->lienClient($devis), true));
    }

    /**
     * Lien que le client ouvre (/d/{jeton}) : compte l'ouverture, puis sa
     * page dans l'espace client (connecté) ou le PDF. Depuis le local, il
     * pointe vers le site en ligne (SYNC_URL), où le client se connecte.
     */
    public function lienClient(Devis $devis): string
    {
        if ('' === trim((string) $devis->getTokenAuthenticite())) {
            $devis->genererTokenAuthenticite();
        }

        return $this->absolue('app_devis_lien', ['token' => $devis->getTokenAuthenticite()]);
    }

    /** @return array{lien: ?string, envoye: bool, note: ?string} */
    private function whatsApp(Devis $devis, bool $relance): array
    {
        $client = $devis->getClients();
        $telephone = trim((string) $client?->getTelephone());
        if (null === $client || '' === $telephone) {
            return ['lien' => null, 'envoye' => false, 'note' => 'WhatsApp : la fiche du client n\'a pas de numéro.'];
        }
        $lien = $this->lienClient($devis);
        if (!$this->whatsApp->estConfigure() || NotificationsClient::estDemo($client)) {
            return ['lien' => $this->lienWaMe($devis, SuiviDevis::messageWhatsApp($devis, $lien, $relance)), 'envoye' => false, 'note' => null];
        }
        try {
            $nom = trim((string) ($client->getRaisonSociale() ?: $client->getNomComplet())) ?: 'Client';
            $this->whatsApp->envoyerDocument(
                $telephone,
                $this->modeleDocument,
                $this->absolue('app_devis_lien_pdf', ['token' => $devis->getTokenAuthenticite()]),
                PdfDevis::nomFichier($devis).'.pdf',
                [$nom, 'devis', (string) $devis->getNumero()],
            );

            return ['lien' => null, 'envoye' => true, 'note' => null];
        } catch (\Throwable $e) {
            $this->logger->warning('Devis {numero} : WhatsApp non envoyé ({erreur}).', ['numero' => $devis->getNumero(), 'erreur' => $e->getMessage()]);

            // L'API a refusé : la personne envoie elle-même, message déjà écrit.
            return ['lien' => $this->lienWaMe($devis, SuiviDevis::messageWhatsApp($devis, $lien, $relance)), 'envoye' => false, 'note' => 'WhatsApp : l\'envoi automatique a échoué, ouvrez le message pour l\'envoyer vous-même.'];
        }
    }

    private function email(Devis $devis): ?string
    {
        $client = $devis->getClients();
        $adresse = trim((string) $client?->getEmail());
        if (null === $client || '' === $adresse || !filter_var($adresse, \FILTER_VALIDATE_EMAIL)) {
            return 'E-mail : la fiche du client n\'a pas d\'adresse.';
        }
        if (NotificationsClient::estDemo($client)) {
            return 'E-mail : fiche de démonstration, rien n\'est parti.';
        }
        try {
            $nom = trim((string) ($client->getRaisonSociale() ?: $client->getNomComplet()));
            $email = (new Email())
                ->from($this->expediteur)
                ->to($adresse)
                ->subject(\sprintf('Votre devis %s · Succès Imprim', $devis->getNumero()))
                ->text(\sprintf("Bonjour %s,\n\nVeuillez trouver ci-joint notre devis %s%s.%s\n\nVous pouvez aussi le consulter et l'accepter en ligne : %s\n\nSuccès Imprim", $nom, $devis->getNumero(), $devis->getObjet() ? ' ('.$devis->getObjet().')' : '', $devis->getDateValidite() ? ' Il est valable jusqu\'au '.$devis->getDateValidite()->format('d/m/Y').'.' : '', $this->lienClient($devis)))
                ->attach($this->pdf->contenu($devis), PdfDevis::nomFichier($devis).'.pdf', 'application/pdf');
            $this->mailer->send(JournalEnvois::typer($email, 'devis'));

            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('Devis {numero} : e-mail non envoyé ({erreur}).', ['numero' => $devis->getNumero(), 'erreur' => $e->getMessage()]);

            return 'E-mail : l\'envoi a échoué ('.mb_substr($e->getMessage(), 0, 80).').';
        }
    }

    private function lienWaMe(Devis $devis, string $texte): ?string
    {
        $telephone = trim((string) $devis->getClients()?->getTelephone());

        return '' === $telephone ? null : 'https://wa.me/'.UtilisateursController::numeroWhatsApp($telephone).'?text='.rawurlencode($texte);
    }

    /** @param array<string, mixed> $parametres */
    private function absolue(string $route, array $parametres): string
    {
        if (!$this->enLigne && preg_match('#^https?://#', $this->urlSite)) {
            return rtrim($this->urlSite, '/').$this->urls->generate($route, $parametres);
        }

        return $this->urls->generate($route, $parametres, UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
