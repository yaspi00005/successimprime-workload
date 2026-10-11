<?php

namespace App\Command;

use App\Service\Planning\Planning;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * À lancer chaque heure par le cron du serveur : prolonge les échéances
 * sur 13 mois et envoie les rappels du planning dans l'application.
 */
#[AsCommand(name: 'app:planning:rappels', description: 'Prolonge les échéances du planning et envoie les rappels.')]
final class PlanningRappelsCommand extends Command
{
    public function __construct(private readonly Planning $planning)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maintenant = new \DateTimeImmutable();
        $output->writeln(\sprintf('Tâches ajoutées : %d.', $this->planning->prolonger($maintenant)));
        $output->writeln(\sprintf('Rappels envoyés : %d.', $this->planning->rappels($maintenant)));

        return Command::SUCCESS;
    }
}
