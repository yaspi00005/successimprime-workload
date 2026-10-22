<?php

namespace App\Command;

use App\Service\ActionsCaisse\ActionsCaisse;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * À lancer toutes les 15 minutes par le cron du serveur : crée les lignes
 * d'action de la caisse, applique les pénalités de retard et réattribue
 * les lignes d'une personne absente.
 */
#[AsCommand(name: 'app:actions-caisse', description: 'Crée et met à jour les lignes d\'action de la caisse.')]
final class ActionsCaisseCommand extends Command
{
    public function __construct(private readonly ActionsCaisse $actions)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maintenant = new \DateTimeImmutable();
        $output->writeln(\sprintf('Lignes créées : %d.', $this->actions->generer($maintenant)));
        $output->writeln(\sprintf('Pénalités de retard : %d.', $this->actions->actualiser($maintenant)));

        return Command::SUCCESS;
    }
}
