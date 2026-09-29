<?php

namespace App\Command;

use App\Service\DecaissementRecurrentService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:executer-decaissements-recurrents',
    description: 'Crée les décaissements automatiques (frais bancaires, crédits...) arrivés à échéance.'
)]
final class ExecuterDecaissementsRecurrentsCommand extends Command
{
    public function __construct(
        private readonly DecaissementRecurrentService $decaissementRecurrentService
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $resultat = $this->decaissementRecurrentService->executerDecaissementsDus();

        foreach ($resultat['details'] as $ligne) {
            $output->writeln($ligne);
        }

        $output->writeln(
            sprintf(
                '<info>%d décaissement(s) exécuté(s).</info>',
                $resultat['executes']
            )
        );

        if ($resultat['echecs'] > 0) {
            $output->writeln(
                sprintf(
                    '<comment>%d échec(s) — voir la cloche de notifications (admin).</comment>',
                    $resultat['echecs']
                )
            );
        }

        return Command::SUCCESS;
    }
}
