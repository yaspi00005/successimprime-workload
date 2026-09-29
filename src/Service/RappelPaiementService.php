<?php

namespace App\Service;

use App\Entity\Commandes;
use App\Entity\ModeleMessage;
use App\Entity\RappelPaiementEnvoye;
use App\Entity\RegleRappelPaiement;
use App\Repository\CommandesRepository;
use App\Repository\RappelPaiementEnvoyeRepository;
use App\Repository\RegleRappelPaiementRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Envoie automatiquement un rappel de paiement (SMS, email ou
 * WhatsApp) aux clients dont une commande non soldée atteint
 * l'ancienneté définie par une RegleRappelPaiement active. Une règle
 * de délai N jours se redéclenche indéfiniment tant que la commande
 * n'est pas soldée : J+N, puis J+2N, J+3N... (voir estDue() ci-
 * dessous). Appelée par la commande app:executer-rappels-paiement
 * (tâche planifiée).
 */
class RappelPaiementService
{
    public function __construct(
        private readonly RegleRappelPaiementRepository $regleRepository,
        private readonly CommandesRepository $commandesRepository,
        private readonly RappelPaiementEnvoyeRepository $rappelRepository,
        private readonly OrangeSmsService $orangeSmsService,
        private readonly WhatsAppService $whatsAppService,
        private readonly MailerInterface $mailer,
        private readonly NotificationService $notificationService,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $emailExpediteur,
        private readonly string $whatsappTemplateRappel
    ) {
    }

    /**
     * @return array{envoyes: int, echecs: int, details: string[]}
     */
    public function executerRappelsDus(?\DateTimeImmutable $reference = null): array
    {
        $reference = $reference ?? new \DateTimeImmutable('today');

        $regles = $this->regleRepository->findActives();

        if ($regles === []) {
            return ['envoyes' => 0, 'echecs' => 0, 'details' => []];
        }

        /*
         * Une seule requete pour toutes les regles : chaque regle
         * "tous les N jours" est ensuite testee en PHP contre
         * l'anciennete de chaque commande (voir estDue() plus bas).
         */
        $commandes = $this->commandesRepository->findToutesNonSoldees();

        $envoyes = 0;
        $echecs = 0;
        $details = [];

        foreach ($commandes as $commande) {
            /*
             * getStatutTravaux() est calculé (pas une colonne) : le
             * filtre "annulée" ne peut se faire qu'en PHP, comme pour
             * le PDF des impayés (ClientsController).
             */
            if ($commande->getStatutTravaux() === 'annulee') {
                continue;
            }

            foreach ($regles as $regle) {
                if (!$this->estDue($commande, $regle, $reference)) {
                    continue;
                }

                if ($this->rappelRepository->dejaEnvoyeAujourdHui($commande, $regle, $reference)) {
                    continue;
                }

                $resultat = $this->envoyerRappel($commande, $regle);

                $details[] = $resultat['message'];

                if ($resultat['succes']) {
                    $envoyes++;
                } else {
                    $echecs++;
                }
            }
        }

        return [
            'envoyes' => $envoyes,
            'echecs' => $echecs,
            'details' => $details,
        ];
    }

    /**
     * true si l'ancienneté de la commande (en jours pleins depuis sa
     * date de création) tombe pile sur un multiple du délai de la
     * règle : J+N, puis J+2N, J+3N... la règle se redéclenche donc
     * indéfiniment tant que la commande reste non soldée.
     */
    private function estDue(
        Commandes $commande,
        RegleRappelPaiement $regle,
        \DateTimeImmutable $reference
    ): bool {
        $dateCommande = $commande->getDateCommande();

        if ($dateCommande === null) {
            return false;
        }

        $jourCommande = $reference::createFromInterface($dateCommande)->setTime(0, 0, 0);

        if ($jourCommande > $reference) {
            return false;
        }

        $ageJours = (int) $reference->diff($jourCommande)->days;
        $delai = $regle->getDelaiJours();

        return $ageJours >= $delai && $ageJours % $delai === 0;
    }

    /**
     * @return array{succes: bool, message: string}
     */
    private function envoyerRappel(Commandes $commande, RegleRappelPaiement $regle): array
    {
        $client = $commande->getClients();
        $modele = $regle->getModeleMessage();

        if ($client === null || $modele === null) {
            return [
                'succes' => false,
                'message' => sprintf(
                    '[ECHEC] Commande %s : client ou modèle de message manquant.',
                    $commande->getNumero() ?? '#' . $commande->getId()
                ),
            ];
        }

        $variables = [
            'nom' => $client->getNomComplet(),
            'numeroCommande' => $commande->getNumero() ?? ('#' . $commande->getId()),
            'montant' => number_format($commande->getTotalTtc(), 0, ',', ' '),
            'reste' => number_format($commande->getResteAPayer(), 0, ',', ' '),
            'dateCommande' => $commande->getDateCommande()?->format('d/m/Y') ?? '',
        ];

        $message = $modele->rendreContenu($variables);

        try {
            $resultatEnvoi = match ($modele->getCanal()) {
                ModeleMessage::CANAL_SMS => $this->envoyerParSms($client->getTelephone(), $message),
                ModeleMessage::CANAL_WHATSAPP => $this->envoyerParWhatsApp($client->getTelephone(), $variables),
                ModeleMessage::CANAL_EMAIL => $this->envoyerParEmail($client->getEmail(), $modele->rendreSujet($variables), $message),
                default => ['succes' => false, 'erreur' => 'Canal de modèle inconnu : ' . $modele->getCanal()],
            };
        } catch (\Throwable $exception) {
            $resultatEnvoi = ['succes' => false, 'erreur' => $exception->getMessage()];
        }

        if (!$resultatEnvoi['succes']) {
            $this->notificationService->notifierRoles(
                ['ROLE_ADMIN'],
                sprintf(
                    'Échec du rappel de paiement « %s » pour %s (%s) : %s',
                    $regle->getNom(),
                    $client->getNomComplet(),
                    $commande->getNumero() ?? '#' . $commande->getId(),
                    $resultatEnvoi['erreur'] ?? 'erreur inconnue'
                ),
                'app_commandes_show',
                ['id' => $commande->getId()]
            );

            return [
                'succes' => false,
                'message' => sprintf(
                    '[ECHEC] %s — %s : %s',
                    $regle->getNom(),
                    $commande->getNumero() ?? '#' . $commande->getId(),
                    $resultatEnvoi['erreur'] ?? 'erreur inconnue'
                ),
            ];
        }

        $rappel = new RappelPaiementEnvoye();
        $rappel
            ->setCommande($commande)
            ->setRegle($regle)
            ->setCanal($modele->getCanal());

        $this->entityManager->persist($rappel);
        $this->entityManager->flush();

        return [
            'succes' => true,
            'message' => sprintf(
                '[OK] %s — %s (%s) : rappel envoyé à %s.',
                $regle->getNom(),
                $commande->getNumero() ?? '#' . $commande->getId(),
                $modele->getCanalLabel(),
                $client->getNomComplet()
            ),
        ];
    }

    /**
     * @return array{succes: bool, erreur: ?string}
     */
    private function envoyerParSms(?string $telephone, string $message): array
    {
        if ($telephone === null || trim($telephone) === '') {
            return ['succes' => false, 'erreur' => 'Le client n’a pas de numéro de téléphone.'];
        }

        return $this->orangeSmsService->envoyerSms($telephone, $message);
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
            $this->whatsappTemplateRappel,
            'fr',
            [$variables['nom'], $variables['numeroCommande'], $variables['reste']]
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
                ->subject($sujet ?? 'Rappel de paiement')
                ->text($message);

            $this->mailer->send($courriel);

            return ['succes' => true, 'erreur' => null];
        } catch (\Throwable $exception) {
            return ['succes' => false, 'erreur' => $exception->getMessage()];
        }
    }
}
