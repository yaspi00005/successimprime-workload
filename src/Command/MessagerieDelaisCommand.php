<?php

namespace App\Command;

use App\Service\Messagerie\Conversations;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Messages des clients sur l'ERP de la boutique (docs/messagerie-clients.md),
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
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($this->enLigne) {
            $io->warning('À lancer sur l\'ERP de la boutique : le site en ligne ne mesure pas les délais.');

            return Command::SUCCESS;
        }
        $bilan = $this->conversations->actualiser(new \DateTimeImmutable());
        if ($output->isVerbose() || array_sum($bilan) > 0) {
            $io->writeln(\sprintf('[%s] %d message(s) rangé(s), %d escalade(s), %d clôture(s) automatique(s).', date('Y-m-d H:i'), $bilan['rangees'], $bilan['escaladees'], $bilan['cloturees']));
        }

        return Command::SUCCESS;
    }
}
