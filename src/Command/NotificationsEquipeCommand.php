<?php

namespace App\Command;

use App\Service\NotificationsEquipe\NotificationsEquipe;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Notifications de l'équipe (docs/notifications-equipe.md), lancée avec la
 * synchronisation (chaque minute) sur l'ERP de l'imprimerie : relit les
 * sources (acomptes, messages, BAT, stock, machines, caisse…), retire ce
 * qui est réglé, remonte les urgentes et critiques non traitées au
 * responsable puis à la direction, et rappelle les urgentes toutes les
 * 30 minutes hors heures calmes. Les e-mails, SMS et WhatsApp prévus
 * partent à la fin de la commande.
 */
#[AsCommand(name: 'app:notifications', description: 'Notifications de l\'équipe : sources, escalade et rappels.')]
final class NotificationsEquipeCommand extends Command
{
    public function __construct(
        private readonly NotificationsEquipe $equipe,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->enLigne) {
            $io->warning('À lancer sur l\'ERP de l\'imprimerie : le site en ligne ne surveille pas l\'atelier.');

            return Command::SUCCESS;
        }
        $maintenant = new \DateTimeImmutable();
        $bilan = $this->equipe->actualiser($maintenant, true) ?? [];
        $remontees = $this->equipe->escalader($maintenant);
        $rappels = $this->equipe->rappeler($maintenant);
        $this->em->flush();

        $creees = $resolues = 0;
        foreach ($bilan as $ligne) {
            $creees += (int) ($ligne['creees'] ?? 0);
            $resolues += (int) ($ligne['resolues'] ?? 0);
        }
        if ($output->isVerbose() || $creees + $resolues + $remontees + $rappels > 0) {
            $io->writeln(\sprintf('[%s] %d nouvelle(s), %d réglée(s) d\'elles-mêmes, %d remontée(s), %d rappel(s).', date('Y-m-d H:i'), $creees, $resolues, $remontees, $rappels));
        }

        return Command::SUCCESS;
    }
}
