<?php

namespace App\Service;

use App\Controller\UtilisateursController;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ModeleMessage;
use App\Entity\RegleRappelPaiement;
use App\Entity\RelanceRetrait;
use App\Entity\User;
use App\Repository\RegleRappelPaiementRepository;
use App\Repository\RelanceRetraitRepository;
use App\Service\Messagerie\JournalEnvois;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Relance des clients dont la commande est prête mais pas encore
 * récupérée : liste à relancer à la main (WhatsApp, SMS, appel) et
 * relances automatiques des règles « Commande prête à retirer »,
 * envoyées par app:executer-rappels-paiement.
 */
final class RelanceRetraitService
{
    // Même règle que Commandes::getStatutTravaux() pour « en préparation ».
    private const STATUTS_EN_PREPARATION = [
        CommandesDetails::PRODUCTION_A_PRODUIRE,
        CommandesDetails::PRODUCTION_EN_COURS,
        CommandesDetails::PRODUCTION_TERMINEE,
        CommandesDetails::PRODUCTION_NON_REQUISE,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RelanceRetraitRepository $relances,
        private readonly RegleRappelPaiementRepository $regles,
        private readonly OrangeSmsService $sms,
        // Paresseux : la liste « À retirer » s'ouvre même si l'e-mail n'est pas configuré.
        #[Autowire(lazy: true)]
        private readonly MailerInterface $mailer,
        private readonly NotificationService $notifications,
        private readonly string $emailExpediteur,
    ) {
    }

    /**
     * Commandes prêtes et pas encore récupérées, la plus ancienne d'abord.
     *
     * @return list<array{commande: Commandes, preteDepuis: \DateTimeImmutable, jours: int, nombreRelances: int, derniereRelance: ?RelanceRetrait, message: string, whatsapp: ?string}>
     */
    public function commandesPretes(\DateTimeImmutable $maintenant): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('c AS commande, MAX(d.productionTermineeLe) AS preteDepuis')
            ->from(Commandes::class, 'c')
            ->join('c.commandesDetails', 'd', 'WITH', 'd.statutProduction = :prete')
            ->where('c.deleted = false')
            ->andWhere('c.recupereParClient = false')
            ->andWhere(sprintf('NOT EXISTS (SELECT l2.id FROM %s l2 WHERE l2.commande = c AND l2.statutProduction IN (:enPreparation))', CommandesDetails::class))
            ->groupBy('c.id')
            ->setParameter('prete', CommandesDetails::PRODUCTION_PRETE_LIVRAISON)
            ->setParameter('enPreparation', self::STATUTS_EN_PREPARATION)
            ->getQuery()
            ->getResult();

        $relances = $this->relances->parCommande(array_map(static fn (array $l) => (int) $l['commande']->getId(), $lignes));
        $aujourdhui = $maintenant->setTime(0, 0);
        $pretes = [];

        foreach ($lignes as $ligne) {
            /** @var Commandes $commande */
            $commande = $ligne['commande'];
            $preteDepuis = $ligne['preteDepuis'] !== null
                ? new \DateTimeImmutable((string) $ligne['preteDepuis'])
                : \DateTimeImmutable::createFromInterface($commande->getDateCommande() ?? $maintenant);
            $telephone = trim((string) $commande->getClients()?->getTelephone());

            $pretes[] = [
                'commande' => $commande,
                'preteDepuis' => $preteDepuis,
                'jours' => (int) $aujourdhui->diff($preteDepuis->setTime(0, 0))->days,
                'nombreRelances' => $relances[$commande->getId()]['nombre'] ?? 0,
                'derniereRelance' => $relances[$commande->getId()]['derniere'] ?? null,
                'message' => self::message($commande, $preteDepuis),
                'whatsapp' => $telephone !== '' ? UtilisateursController::numeroWhatsApp($telephone) : null,
            ];
        }

        usort($pretes, static fn (array $a, array $b) => $a['preteDepuis'] <=> $b['preteDepuis']);

        return $pretes;
    }

    /**
     * Message proposé par défaut pour une relance à la main.
     */
    public static function message(Commandes $commande, \DateTimeInterface $preteDepuis): string
    {
        $client = $commande->getClients();
        $reste = $commande->getResteAPayer() ?? 0;

        return trim(sprintf(
            'Bonjour %s, votre commande %s est prête chez Succès Imprim depuis le %s. %sNous vous attendons pour la récupérer. Merci !',
            $client?->getNomComplet() ?? '',
            $commande->getNumero() ?? '#'.$commande->getId(),
            $preteDepuis->format('d/m'),
            $reste > 0 ? sprintf('Reste à payer : %s F. ', number_format($reste, 0, ',', ' ')) : ''
        ));
    }

    public function noter(Commandes $commande, string $canal, ?User $par, ?RegleRappelPaiement $regle = null): RelanceRetrait
    {
        $relance = (new RelanceRetrait())
            ->setCommande($commande)
            ->setCanal($canal)
            ->setPar($par)
            ->setRegle($regle);

        $this->em->persist($relance);
        $this->em->flush();

        return $relance;
    }

    /**
     * @return array{succes: bool, erreur: ?string}
     */
    public function envoyerSms(Commandes $commande, string $message, ?User $par): array
    {
        $telephone = trim((string) $commande->getClients()?->getTelephone());

        if ($telephone === '') {
            return ['succes' => false, 'erreur' => 'Le client n\'a pas de numéro de téléphone.'];
        }

        $resultat = $this->sms->envoyerSms($telephone, $message, type: 'relance_retrait');

        if ($resultat['succes']) {
            $this->noter($commande, RelanceRetrait::CANAL_SMS, $par);
        }

        return ['succes' => (bool) $resultat['succes'], 'erreur' => $resultat['erreur'] ?? null];
    }

    /**
     * Règles actives « Commande prête à retirer » : J+N après le jour où
     * la commande est prête, puis tous les N jours, une fois par jour au plus.
     *
     * @return array{envoyes: int, echecs: int, details: list<string>}
     */
    public function executerRelancesDues(\DateTimeImmutable $maintenant): array
    {
        $regles = array_filter($this->regles->findActives(), static fn (RegleRappelPaiement $r) => $r->isRetrait());
        $resultat = ['envoyes' => 0, 'echecs' => 0, 'details' => []];

        if ($regles === []) {
            return $resultat;
        }

        foreach ($this->commandesPretes($maintenant) as $ligne) {
            foreach ($regles as $regle) {
                if (!self::estDue($ligne['jours'], $regle->getDelaiJours()) || $this->dejaRelanceeAujourdhui($ligne, $maintenant)) {
                    continue;
                }

                [$succes, $detail] = $this->envoyerSelonRegle($ligne, $regle);
                $resultat[$succes ? 'envoyes' : 'echecs']++;
                $resultat['details'][] = $detail;
            }
        }

        return $resultat;
    }

    public static function estDue(int $jours, int $delai): bool
    {
        return $delai > 0 && $jours >= $delai && $jours % $delai === 0;
    }

    /**
     * @param array{derniereRelance: ?RelanceRetrait} $ligne
     */
    private function dejaRelanceeAujourdhui(array $ligne, \DateTimeImmutable $maintenant): bool
    {
        return $ligne['derniereRelance'] !== null
            && $ligne['derniereRelance']->getEnvoyeeLe()->format('Y-m-d') === $maintenant->format('Y-m-d');
    }

    /**
     * @param array{commande: Commandes, preteDepuis: \DateTimeImmutable, jours: int} $ligne
     *
     * @return array{0: bool, 1: string}
     */
    private function envoyerSelonRegle(array $ligne, RegleRappelPaiement $regle): array
    {
        $commande = $ligne['commande'];
        $client = $commande->getClients();
        $modele = $regle->getModeleMessage();
        $numero = $commande->getNumero() ?? '#'.$commande->getId();

        if ($client === null || $modele === null) {
            return [false, sprintf('[ECHEC] %s : client ou modèle de message manquant.', $numero)];
        }

        $variables = [
            'nom' => $client->getNomComplet(),
            'numeroCommande' => $numero,
            'reste' => number_format($commande->getResteAPayer() ?? 0, 0, ',', ' '),
            'montant' => number_format($commande->getTotalTtc(), 0, ',', ' '),
            'dateCommande' => $commande->getDateCommande()?->format('d/m/Y') ?? '',
            'datePrete' => $ligne['preteDepuis']->format('d/m/Y'),
            'jours' => (string) $ligne['jours'],
        ];

        try {
            $envoi = match ($modele->getCanal()) {
                ModeleMessage::CANAL_SMS => trim((string) $client->getTelephone()) !== ''
                    ? $this->sms->envoyerSms((string) $client->getTelephone(), $modele->rendreContenu($variables), type: 'relance_retrait')
                    : ['succes' => false, 'erreur' => 'Le client n\'a pas de numéro de téléphone.'],
                ModeleMessage::CANAL_EMAIL => $this->envoyerEmail($client->getEmail(), $modele->rendreSujet($variables) ?? 'Votre commande est prête', $modele->rendreContenu($variables)),
                // L'envoi WhatsApp automatique arrive avec l'API Meta (J5) ; d'ici là, relance à la main.
                default => ['succes' => false, 'erreur' => 'Envoi WhatsApp automatique pas encore branché : relancez depuis la liste « À retirer ».'],
            };
        } catch (\Throwable $e) {
            $envoi = ['succes' => false, 'erreur' => $e->getMessage()];
        }

        if (!$envoi['succes']) {
            $this->notifications->notifierRoles(
                ['ROLE_ADMIN'],
                sprintf('Relance « %s » non envoyée pour %s (%s) : %s', $regle->getNom(), $client->getNomComplet(), $numero, $envoi['erreur'] ?? 'erreur inconnue'),
                'app_livraisons_a_retirer'
            );

            return [false, sprintf('[ECHEC] %s — %s : %s', $regle->getNom(), $numero, $envoi['erreur'] ?? 'erreur inconnue')];
        }

        $canal = $modele->getCanal() === ModeleMessage::CANAL_EMAIL ? RelanceRetrait::CANAL_EMAIL : RelanceRetrait::CANAL_SMS;
        $this->noter($commande, $canal, null, $regle);

        return [true, sprintf('[OK] %s — %s : relance envoyée à %s.', $regle->getNom(), $numero, $client->getNomComplet())];
    }

    /**
     * @return array{succes: bool, erreur: ?string}
     */
    private function envoyerEmail(?string $email, string $sujet, string $message): array
    {
        if ($email === null || trim($email) === '') {
            return ['succes' => false, 'erreur' => 'Le client n\'a pas d\'adresse e-mail.'];
        }

        $this->mailer->send(JournalEnvois::typer((new Email())->from($this->emailExpediteur)->to($email)->subject($sujet)->text($message), 'relance_retrait'));

        return ['succes' => true, 'erreur' => null];
    }
}
