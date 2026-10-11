<?php

namespace App\Service;

use App\Entity\Campagne;
use App\Entity\CampagneDestinataire;
use App\Entity\Clients;
use App\Entity\ModeleMessage;
use App\Entity\User;
use App\Repository\CampagneDestinataireRepository;
use App\Repository\CampagneRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Crée une campagne (promo, vœux de fêtes...) et l'envoie par lots :
 * la création persiste immédiatement un CampagneDestinataire "en
 * attente" par client choisi, puis envoyerLotEnAttente() -- appelée
 * par la commande app:envoyer-campagnes (tâche planifiée) -- traite
 * un petit nombre de destinataires à chaque passage, pour ne jamais
 * dépasser les limites de débit des API SMS/WhatsApp/email et ne pas
 * bloquer l'écran de création le temps d'envoyer à tout le monde.
 */
class CampagneService
{
    public function __construct(
        private readonly CampagneRepository $campagneRepository,
        private readonly CampagneDestinataireRepository $destinataireRepository,
        private readonly OrangeSmsService $orangeSmsService,
        private readonly WhatsAppService $whatsAppService,
        private readonly MailerInterface $mailer,
        private readonly NotificationService $notificationService,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $emailExpediteur,
        private readonly string $whatsappTemplateCampagne
    ) {
    }

    /**
     * @param array<int, Clients> $clients
     */
    public function creerEtLancer(
        string $nom,
        ModeleMessage $modele,
        array $clients,
        ?User $creePar,
        ?string $senderName = null
    ): Campagne {
        $campagne = new Campagne();
        $campagne
            ->setNom($nom)
            ->setModeleMessage($modele)
            ->setCreePar($creePar)
            ->setSenderName($senderName)
            ->setNombreTotal(count($clients));

        $this->entityManager->persist($campagne);

        foreach ($clients as $client) {
            $destinataire = new CampagneDestinataire();
            $destinataire
                ->setCampagne($campagne)
                ->setClient($client);

            $this->entityManager->persist($destinataire);
        }

        $this->entityManager->flush();

        return $campagne;
    }

    /**
     * Campagne de la maquette 35 : texte libre envoyé à un segment, tout
     * de suite ou à la date choisie.
     *
     * @param list<Clients> $clients
     */
    public function programmer(
        string $nom,
        string $canal,
        string $message,
        ?string $segment,
        ?\DateTimeImmutable $envoiPrevuLe,
        array $clients,
        ?User $creePar
    ): Campagne {
        $campagne = (new Campagne())
            ->setNom($nom)
            ->setCanal($canal)
            ->setMessage($message)
            ->setSegment($segment)
            ->setEnvoiPrevuLe($envoiPrevuLe)
            ->setCreePar($creePar)
            ->setNombreTotal(count($clients));
        $this->entityManager->persist($campagne);
        foreach ($clients as $client) {
            $this->entityManager->persist((new CampagneDestinataire())->setCampagne($campagne)->setClient($client));
        }
        $this->entityManager->flush();

        return $campagne;
    }

    /**
     * @return array{envoyes: int, echecs: int, total: int}
     */
    public function envoyerLotEnAttente(int $limite = 30): array
    {
        $destinataires = $this->destinataireRepository->findEnAttente($limite, $this->whatsAppService->estConfigure());

        $envoyes = 0;
        $echecs = 0;

        $campagnesTouchees = [];

        foreach ($destinataires as $destinataire) {
            $resultat = $this->envoyerUnDestinataire($destinataire);

            if ($resultat['succes']) {
                $envoyes++;
            } else {
                $echecs++;
            }

            $campagne = $destinataire->getCampagne();

            if ($campagne !== null && $campagne->getId() !== null) {
                $campagnesTouchees[$campagne->getId()] = $campagne;
            }
        }

        $this->entityManager->flush();

        foreach ($campagnesTouchees as $campagne) {
            if ($this->destinataireRepository->compteEnAttente($campagne) === 0) {
                $campagne->setStatut(Campagne::STATUT_TERMINEE);

                $compteurs = $this->destinataireRepository->compterParStatut($campagne);

                $this->notificationService->notifierRoles(
                    ['ROLE_ADMIN'],
                    sprintf(
                        'Campagne « %s » terminée : %d envoyé(s), %d échec(s).',
                        $campagne->getNom(),
                        $compteurs[CampagneDestinataire::STATUT_ENVOYE],
                        $compteurs[CampagneDestinataire::STATUT_ECHEC]
                    ),
                    'app_campagne_show',
                    ['id' => $campagne->getId()]
                );
            }
        }

        $this->entityManager->flush();

        return [
            'envoyes' => $envoyes,
            'echecs' => $echecs,
            'total' => count($destinataires),
        ];
    }

    /**
     * @return array{succes: bool}
     */
    private function envoyerUnDestinataire(CampagneDestinataire $destinataire): array
    {
        $campagne = $destinataire->getCampagne();
        $client = $destinataire->getClient();
        $modele = $campagne?->getModeleMessage();

        if ($client === null) {
            $destinataire
                ->setStatut(CampagneDestinataire::STATUT_ECHEC)
                ->setErreur('Client introuvable (peut-être supprimé depuis).')
                ->setDateEnvoi(new \DateTimeImmutable());

            return ['succes' => false];
        }

        $canal = $campagne->getCanal();
        if ($canal === null || ($modele === null && $campagne->getMessage() === null)) {
            $destinataire
                ->setStatut(CampagneDestinataire::STATUT_ECHEC)
                ->setErreur('Campagne sans message ni canal.')
                ->setDateEnvoi(new \DateTimeImmutable());

            return ['succes' => false];
        }

        if ($canal === ModeleMessage::CANAL_SMS && !$client->isRecevoirSms()) {
            $destinataire
                ->setStatut(CampagneDestinataire::STATUT_IGNORE)
                ->setErreur('Ce client a choisi de ne pas recevoir de SMS.')
                ->setDateEnvoi(new \DateTimeImmutable());

            return ['succes' => false];
        }

        $variables = ['nom' => $client->getNomComplet()];

        $message = $campagne->rendreMessage($client);
        $sujet = $modele?->rendreSujet($variables) ?? $campagne->getNom();

        try {
            $resultatEnvoi = match ($canal) {
                ModeleMessage::CANAL_SMS => $this->envoyerParSms($client->getTelephone(), $message, $campagne->getSenderName()),
                ModeleMessage::CANAL_WHATSAPP => $this->envoyerParWhatsApp($client->getTelephone(), $variables),
                ModeleMessage::CANAL_EMAIL => $this->envoyerParEmail($client->getEmail(), $sujet, $message),
                default => ['succes' => false, 'erreur' => 'Canal inconnu : ' . $canal],
            };
        } catch (\Throwable $exception) {
            $resultatEnvoi = ['succes' => false, 'erreur' => $exception->getMessage()];
        }

        if (!$resultatEnvoi['succes']) {
            $destinataire
                ->setStatut(CampagneDestinataire::STATUT_ECHEC)
                ->setErreur($resultatEnvoi['erreur'] ?? 'Erreur inconnue.')
                ->setDateEnvoi(new \DateTimeImmutable());

            return ['succes' => false];
        }

        $destinataire
            ->setStatut(CampagneDestinataire::STATUT_ENVOYE)
            ->setErreur(null)
            ->setDateEnvoi(new \DateTimeImmutable());

        return ['succes' => true];
    }

    /**
     * @return array{succes: bool, erreur: ?string}
     */
    private function envoyerParSms(?string $telephone, string $message, ?string $senderName = null): array
    {
        if ($telephone === null || trim($telephone) === '') {
            return ['succes' => false, 'erreur' => 'Le client n’a pas de numéro de téléphone.'];
        }

        return $this->orangeSmsService->envoyerSms($telephone, $message, $senderName);
    }

    /**
     * @param array<string, string> $variables
     * @return array{succes: bool, erreur: ?string}
     */
    private function envoyerParWhatsApp(?string $telephone, array $variables): array
    {
        if ($telephone === null || trim($telephone) === '') {
            return ['succes' => false, 'erreur' => 'Le client n’a pas de numéro de téléphone.'];
        }

        if (!$this->whatsAppService->estConfigure()) {
            return ['succes' => false, 'erreur' => 'Le compte WhatsApp Business n’est pas configuré.'];
        }

        $reponse = $this->whatsAppService->envoyerTemplate(
            $telephone,
            $this->whatsappTemplateCampagne,
            'fr',
            [$variables['nom']]
        );

        if (!empty($reponse['error'])) {
            return [
                'succes' => false,
                'erreur' => is_array($reponse['error'])
                    ? ($reponse['error']['message'] ?? 'Erreur WhatsApp.')
                    : (string) $reponse['error'],
            ];
        }

        return ['succes' => true, 'erreur' => null];
    }

    /**
     * @return array{succes: bool, erreur: ?string}
     */
    private function envoyerParEmail(?string $email, ?string $sujet, string $message): array
    {
        if ($email === null || trim($email) === '') {
            return ['succes' => false, 'erreur' => 'Le client n’a pas d’adresse email.'];
        }

        try {
            $courriel = (new Email())
                ->from($this->emailExpediteur)
                ->to($email)
                ->subject($sujet ?? 'Information')
                ->text($message);

            $this->mailer->send($courriel);

            return ['succes' => true, 'erreur' => null];
        } catch (\Throwable $exception) {
            return ['succes' => false, 'erreur' => $exception->getMessage()];
        }
    }
}
