<?php

namespace App\Command;

use App\Service\Messagerie\Conversations;
use App\Service\Site\RappelsSite;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Messages des clients sur l'ERP de l'imprimerie (docs/messagerie-clients.md),
 * lancée avec la synchronisation (chaque minute) : range les anciens
 * messages dans leur conversation, prévient la Direction d'une demande
 * qui attend au-delà de l'escalade, clôture les demandes restées sans
 * réponse du client après la clôture automatique.
 */
#[AsCommand(name: 'app:messagerie:delais', description: 'Messages des clients : escalade à la Direction et clôture automatique.')]
final class MessagerieDelaisCommand extends Command
{
    public function __construct(
        private readonly Conversations $conversations,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
        private readonly ?RappelsSite $rappels = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->enLigne) {
            $io->warning('À lancer sur l\'ERP de l\'imprimerie : le site en ligne ne mesure pas les délais.');

            return Command::SUCCESS;
        }
        $bilan = $this->conversations->actualiser(new \DateTimeImmutable());
        // Rappels promis sur le site (atelier fermé) et non faits : la direction est prévenue.
        $bilan['rappels'] = $this->rappels?->alerterRetards(new \DateTimeImmutable()) ?? 0;
        if ($output->isVerbose() || array_sum($bilan) > 0) {
            $io->writeln(\sprintf('[%s] %d message(s) rangé(s), %d escalade(s), %d clôture(s) automatique(s), %d rappel(s) en retard signalé(s).', date('Y-m-d H:i'), $bilan['rangees'], $bilan['escaladees'], $bilan['cloturees'], $bilan['rappels']));
        }

        return Command::SUCCESS;
    }
}
