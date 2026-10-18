<?php

namespace App\Service\Messagerie;

use App\Entity\Reglage;
use App\Repository\ReglageRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Alertes des crédits SMS et e-mail : solde SMS sous le seuil, pack SMS
 * ou abonnement e-mail qui finit bientôt. Une notification (la cloche) à
 * la direction et à qui a le droit « Crédits SMS et e-mail », une seule
 * fois par situation : rien de plus tant qu'elle dure ; quand elle cesse
 * (pack racheté, nouvelle date), la suivante sera de nouveau signalée.
 *
 * Ce qui a déjà été signalé est gardé dans la ligne de réglage
 * « credits_messages_alertes » (pas une tuile). Jamais de montant.
 */
final class AlertesMessages
{
    public const CLE_ETAT = 'credits_messages_alertes';

    public const ROUTE = 'app_credits_messages_index';

    public const DESTINATAIRES = ['ROLE_ADMIN', 'ROLE_CREDITS_MESSAGES'];

    public function __construct(
        private readonly CreditsMessages $credits,
        private readonly NotificationService $notifications,
        private readonly ReglageRepository $reglages,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Compare la situation à ce qui a déjà été signalé, prévient pour ce
     * qui est nouveau et enregistre.
     *
     * @return list<string> les messages envoyés
     */
    public function verifier(\DateTimeImmutable $maintenant): array
    {
        $seuils = $this->credits->seuils();
        $alertes = CreditsMessages::alertes($this->credits->sms($maintenant), $this->credits->email($maintenant), $seuils['seuil_sms'], $seuils['alerte_jours'], $maintenant);

        $etat = $this->reglages->findOneBy(['cle' => self::CLE_ETAT]);
        [$nouvelles, $garde] = self::aSignaler($alertes, $etat?->getValeurs() ?? []);
        foreach ($nouvelles as $message) {
            $this->notifications->notifierRoles(self::DESTINATAIRES, $message, self::ROUTE);
        }
        if ($garde !== ($etat?->getValeurs() ?? [])) {
            if (null === $etat) {
                $etat = new Reglage(self::CLE_ETAT);
                $this->em->persist($etat);
            }
            $etat->modifier($garde, null, $maintenant);
        }
        $this->em->flush();

        return $nouvelles;
    }

    /**
     * Messages à envoyer et état à garder : une alerte n'est signalée
     * qu'une fois pour la même valeur ; celles qui ont cessé sont oubliées.
     *
     * @param array<string, array{valeur: string, message: string}> $alertes
     * @param array<string, mixed>                                  $dejaSignalees
     *
     * @return array{0: list<string>, 1: array<string, string>}
     */
    public static function aSignaler(array $alertes, array $dejaSignalees): array
    {
        $messages = [];
        $garde = [];
        foreach ($alertes as $cle => $alerte) {
            if (($dejaSignalees[$cle] ?? null) !== $alerte['valeur']) {
                $messages[] = $alerte['message'];
            }
            $garde[$cle] = $alerte['valeur'];
        }

        return [$messages, $garde];
    }
}
