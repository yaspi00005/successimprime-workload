<?php

namespace App\Command;

use App\Service\Corbeille\Corbeille;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Corbeille (docs/corbeille.md) : supprime pour de bon, avec leurs
 * fichiers, les entrées plus vieilles que la durée réglée (30 jours par
 * défaut). À lancer chaque nuit, après la sauvegarde de 02:00, sur l'ERP
 * local (scripts\windows\installer_tache_corbeille.bat) et sur le site en
 * ligne (crontab) :
 *
 *   30 3 * * * cd /srv/successimprim && php bin/console app:corbeille:purger -q >> var/log/corbeille.log 2>&1
 *
 * Chaque suppression définitive est notée dans le journal d'activité.
 */
#[AsCommand(name: 'app:corbeille:purger', description: 'Supprime pour de bon ce qui est dans la corbeille depuis plus longtemps que la durée réglée.')]
final class CorbeillePurgerCommand extends Command
{
    public function __construct(private readonly Corbeille $corbeille)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('date', null, InputOption::VALUE_REQUIRED, 'Date du jour à prendre (AAAA-MM-JJ), pour essayer');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $maintenant = new \DateTimeImmutable();
        if (null !== ($date = $input->getOption('date'))) {
            $lue = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $date);
            if (false === $lue) {
                $output->writeln('<error>Date attendue : AAAA-MM-JJ.</error>');

                return Command::INVALID;
            }
            $maintenant = $lue->setTime((int) $maintenant->format('H'), (int) $maintenant->format('i'));
        }

        $n = $this->corbeille->purger($maintenant);
        $output->writeln(0 === $n
            ? \sprintf('Rien à supprimer : la corbeille garde %s.', $this->corbeille->dureeTexte())
            : \sprintf('%d entrée%s supprimée%s pour de bon (plus de %s dans la corbeille).', $n, $n > 1 ? 's' : '', $n > 1 ? 's' : '', $this->corbeille->dureeTexte()));

        return Command::SUCCESS;
    }
}
