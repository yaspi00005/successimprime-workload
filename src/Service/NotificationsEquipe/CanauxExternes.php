<?php

namespace App\Service\NotificationsEquipe;

use App\Entity\Notification;
use App\Repository\ReglageRepository;
use App\Service\Messagerie\JournalEnvois;
use App\Service\OrangeSmsService;
use App\Service\WhatsAppService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Envois hors de l'application d'une notification de l'équipe : e-mail
 * (gratuit), SMS Orange et WhatsApp (payants, critiques seulement).
 *
 * - SMS : par OrangeSmsService, noté dans l'historique des envois (type
 *   « Alerte de l'équipe ») : il compte dans les crédits SMS.
 * - WhatsApp : par WhatsAppService, seulement si la clé Meta, le numéro et
 *   le modèle WHATSAPP_TEMPLATE_ALERTE_EQUIPE sont posés dans .env.local ;
 *   sinon rien ne part (l'écran des réglages le dit).
 * - Au plus « payants_par_jour » SMS et WhatsApp par jour, tous ensemble
 *   (ligne de réglage « notifications_payants »), pour qu'une panne qui se
 *   répète ne vide pas le crédit.
 *
 * Jamais de montant ni de donnée de client dans ces messages : le titre
 * sans montant et un lien vers l'application.
 */
final class CanauxExternes implements EventSubscriberInterface
{
    public const CLE_COMPTE = 'notifications_payants';
    public const TYPE_ENVOI = 'alerte_equipe';

    /** @var list<array{notification: Notification, canaux: array<string, bool>, plafond: int}> */
    private array $enAttente = [];

    public function __construct(
        private readonly WhatsAppService $whatsapp,
        private readonly OrangeSmsService $sms,
        private readonly MailerInterface $mailer,
        private readonly ReglageRepository $reglages,
        private readonly EntityManagerInterface $em,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM_ADDRESS)%')] private readonly string $expediteur,
        #[Autowire('%env(default::WHATSAPP_TEMPLATE_ALERTE_EQUIPE)%')] private readonly ?string $modeleWhatsApp = null,
        #[Autowire('%env(MAILER_DSN)%')] private readonly string $mailerDsn = 'null://null',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'envoyer', ConsoleEvents::TERMINATE => 'envoyer'];
    }

    /**
     * Ce qui peut partir, pour l'écran des réglages.
     *
     * @return array{whatsapp: bool, sms: bool, email: bool, messages: array<string, string>}
     */
    public function etat(): array
    {
        $whatsapp = $this->whatsapp->estConfigure() && '' !== trim((string) $this->modeleWhatsApp);
        $email = '' !== trim($this->expediteur) && !str_starts_with($this->mailerDsn, 'null://');

        return [
            'whatsapp' => $whatsapp,
            'sms' => $this->sms->estConfigure(),
            'email' => $email,
            'messages' => [
                'whatsapp' => $whatsapp ? 'WhatsApp automatique : prêt (modèle '.trim((string) $this->modeleWhatsApp).').' : (!$this->whatsapp->estConfigure() ? 'WhatsApp automatique : clé Meta non réglée, rien ne part.' : 'WhatsApp automatique : modèle de message non réglé (WHATSAPP_TEMPLATE_ALERTE_EQUIPE), rien ne part.'),
                'sms' => $this->sms->estConfigure() ? 'SMS Orange : prêt, chaque SMS compte dans les crédits SMS.' : 'SMS Orange : identifiants non réglés, rien ne part.',
                'email' => $email ? 'E-mail : prêt.' : 'E-mail : serveur d\'envoi non réglé, rien ne part.',
            ],
        ];
    }

    /**
     * Prévoit les envois d'une notification, faits en fin de requête.
     *
     * @param array<string, bool> $canaux email, sms, whatsapp
     */
    public function prevoir(Notification $n, array $canaux, int $plafondPayants): void
    {
        $canaux = array_filter(['email' => $canaux['email'] ?? false, 'sms' => $canaux['sms'] ?? false, 'whatsapp' => $canaux['whatsapp'] ?? false]);
        if ([] !== $canaux) {
            $this->enAttente[] = ['notification' => $n, 'canaux' => $canaux, 'plafond' => $plafondPayants];
        }
    }

    /** @return list<array{notification: Notification, canaux: array<string, bool>, plafond: int}> */
    public function prevus(): array
    {
        return $this->enAttente;
    }

    public function envoyer(): void
    {
        if ([] === $this->enAttente) {
            return;
        }
        $liste = $this->enAttente;
        $this->enAttente = [];
        foreach ($liste as ['notification' => $n, 'canaux' => $canaux, 'plafond' => $plafond]) {
            $employe = $n->getDestinataire()?->getEmploye();
            if (null === $employe) {
                continue;
            }
            $texte = self::texte($n);
            if (($canaux['email'] ?? false) && '' !== trim((string) $employe->getEmail())) {
                $this->envoyerEmail(trim((string) $employe->getEmail()), $n, $texte);
            }
            $telephone = trim((string) $employe->getTelephone());
            if ('' === $telephone) {
                continue;
            }
            if (($canaux['whatsapp'] ?? false) && $this->etat()['whatsapp'] && $this->reserverPayant($plafond)) {
                try {
                    $this->whatsapp->envoyerTemplate($telephone, trim((string) $this->modeleWhatsApp), 'fr', [$n->getMessage(), $n->getDetail() ?? '—']);
                } catch (\Throwable $e) {
                    $this->logger->warning('Notification de l\'équipe : WhatsApp non parti ({classe}).', ['classe' => $e::class]);
                }
            }
            if (($canaux['sms'] ?? false) && $this->sms->estConfigure() && $this->reserverPayant($plafond)) {
                try {
                    $this->sms->envoyerSms($telephone, $texte, null, self::TYPE_ENVOI);
                } catch (\Throwable $e) {
                    $this->logger->warning('Notification de l\'équipe : SMS non parti ({classe}).', ['classe' => $e::class]);
                }
            }
        }
    }

    /** Texte court (un SMS) : jamais de montant ni de donnée sensible. */
    public static function texte(Notification $n): string
    {
        $texte = 'Succès Imprim · '.mb_strtoupper(Notification::NIVEAUX[$n->getNiveau()]).' : '.$n->getMessage().(null !== $n->getDetail() ? ' · '.$n->getDetail() : '');

        return mb_strlen($texte) > 160 ? rtrim(mb_substr($texte, 0, 159)).'…' : $texte;
    }

    private function envoyerEmail(string $adresse, Notification $n, string $texte): void
    {
        if ('' === trim($this->expediteur)) {
            return;
        }
        $lien = null !== $n->getId() ? $this->urls->generate('app_notification_ouvrir', ['id' => $n->getId()], UrlGeneratorInterface::ABSOLUTE_URL) : $this->urls->generate('app_notification_index', [], UrlGeneratorInterface::ABSOLUTE_URL);
        $email = (new Email())
            ->from(new Address(trim($this->expediteur), 'Succès Imprim'))
            ->to($adresse)
            ->subject('[Succès Imprim] '.Notification::NIVEAUX[$n->getNiveau()].' : '.$n->getMessage())
            ->text($texte."\n\nOuvrir : ".$lien."\n\nCet e-mail suit vos réglages de notifications (Réglages › Notifications).");
        try {
            $this->mailer->send(JournalEnvois::typer($email, self::TYPE_ENVOI));
        } catch (\Throwable $e) {
            $this->logger->warning('Notification de l\'équipe : e-mail non parti ({classe}).', ['classe' => $e::class]);
        }
    }

    /** Compte un envoi payant du jour ; faux si le plafond est atteint. */
    private function reserverPayant(int $plafond): bool
    {
        $jour = (new \DateTimeImmutable())->format('Y-m-d');
        $reglage = $this->reglages->findOneBy(['cle' => self::CLE_COMPTE]);
        $valeurs = $reglage?->getValeurs() ?? [];
        $nombre = ($valeurs['jour'] ?? null) === $jour ? (int) ($valeurs['nombre'] ?? 0) : 0;
        if ($nombre >= $plafond) {
            $this->logger->notice('Notification de l\'équipe : plafond d\'envois payants du jour atteint ({n}).', ['n' => $plafond]);

            return false;
        }
        // Requête directe : ne pas enregistrer par erreur d'autres changements en cours.
        try {
            $json = json_encode(['jour' => $jour, 'nombre' => $nombre + 1], \JSON_THROW_ON_ERROR);
            $c = $this->em->getConnection();
            if (null === $reglage) {
                $c->insert('reglage', ['cle' => self::CLE_COMPTE, 'valeurs' => $json]);
            } else {
                $c->update('reglage', ['valeurs' => $json], ['cle' => self::CLE_COMPTE]);
                $this->em->refresh($reglage);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Notification de l\'équipe : compte des envois payants non noté ({classe}).', ['classe' => $e::class]);
        }

        return true;
    }
}
