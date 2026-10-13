<?php

namespace App\Command;

use App\Entity\Notification;
use App\Service\Finances\PrevisionTresorerie;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * À lancer chaque matin par le cron du serveur : si le solde prévu à
 * 90 jours (scénario prudent) passe sous le seuil d'alerte des
 * Paramètres, la direction reçoit une notification dans l'application
 * avec les créances à relancer. Une fois par semaine au plus, tant que la
 * prévision reste sous le seuil. Aucun message externe (SMS, WhatsApp).
 */
#[AsCommand(name: 'app:finances:alerte-tresorerie', description: 'Prévient la direction quand la trésorerie prévue passe sous le seuil d\'alerte.')]
final class AlerteTresorerieCommand extends Command
{
    public const JOURS_ENTRE_ALERTES = 7;

    public function __construct(
        private readonly PrevisionTresorerie $prevision,
        private readonly NotificationService $notifications,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maintenant = new \DateTimeImmutable();
        $message = PrevisionTresorerie::messageAlerte($this->prevision->donnees($maintenant, 'prudent'));
        if (null === $message) {
            $output->writeln('Prévision au-dessus du seuil : pas d\'alerte.');

            return Command::SUCCESS;
        }

        $recente = (int) $this->em->createQueryBuilder()
            ->select('COUNT(n.id)')
            ->from(Notification::class, 'n')
            ->andWhere('n.route = :route')
            ->andWhere('n.dateCreation >= :depuis')
            ->setParameter('route', 'app_finances_tresorerie')
            ->setParameter('depuis', $maintenant->modify('-'.self::JOURS_ENTRE_ALERTES.' days'))
            ->getQuery()
            ->getSingleScalarResult();
        if ($recente > 0) {
            $output->writeln('Alerte déjà envoyée cette semaine.');

            return Command::SUCCESS;
        }

        $this->notifications->notifierRoles(['ROLE_ADMIN'], $message, 'app_finances_tresorerie');
        $this->em->flush();
        $output->writeln('Alerte envoyée : '.$message);

        return Command::SUCCESS;
    }
}
