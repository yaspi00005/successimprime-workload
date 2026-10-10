<?php

namespace App\Command;

use App\Service\RappelPaiementService;
use App\Service\RelanceRetraitService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:executer-rappels-paiement',
    description: 'Envoie les rappels de paiement dus et les relances des commandes prêtes à retirer.'
)]
final class ExecuterRappelsPaiementCommand extends Command
{
    public function __construct(
        private readonly RappelPaiementService $rappelPaiementService,
        private readonly RelanceRetraitService $relanceRetraitService
    ) {
        parent::__construct();
    }

    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $resultat = $this->rappelPaiementService->executerRappelsDus();
        $retraits = $this->relanceRetraitService->executerRelancesDues(new \DateTimeImmutable());

        $resultat['envoyes'] += $retraits['envoyes'];
        $resultat['echecs'] += $retraits['echecs'];
        $resultat['details'] = array_merge($resultat['details'], $retraits['details']);

        foreach ($resultat['details'] as $ligne) {
            $output->writeln($ligne);
        }

        $output->writeln(
            sprintf(
                '<info>%d rappel(s) envoyé(s).</info>',
                $resultat['envoyes']
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
