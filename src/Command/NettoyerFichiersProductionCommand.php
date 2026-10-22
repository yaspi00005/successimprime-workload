<?php

namespace App\Command;

use App\Service\Fichiers\ConservationFichiers;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Efface du serveur les fichiers de commande arrivés au bout de leur durée
 * de conservation (ConservationFichiers). Une fois par nuit, sur le
 * serveur de l'imprimerie et sur le serveur en ligne :
 *   15 2 * * * php bin/console app:nettoyer-fichiers-production
 */
#[AsCommand(name: 'app:nettoyer-fichiers-production', description: 'Efface les fichiers de commande imprimés depuis plus que la durée de conservation (sauf Mes produits et « Garder 1 an »).')]
final class NettoyerFichiersProductionCommand extends Command
{
    public function __construct(private readonly ConservationFichiers $conservation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('essai', null, InputOption::VALUE_NONE, 'Compter seulement, sans rien effacer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $essai = (bool) $input->getOption('essai');
        $bilan = $this->conservation->nettoyer(new \DateTimeImmutable(), $essai);
        $io->success(\sprintf(
            '%s : %d fichier(s), %s Mo libérés. Conservation %d mois après impression, %d avec « Garder 1 an » (%d gardé(s) en plus).',
            $essai ? 'Essai, rien effacé' : 'Effacés',
            $bilan['effaces'],
            number_format($bilan['octets'] / 1048576, 1, ',', ' '),
            $this->conservation->mois(),
            $this->conservation->moisGarde(),
            $bilan['gardes'],
        ));

        return Command::SUCCESS;
    }
}
