<?php

namespace App\Command;

use App\Entity\Corbeille as EntreeCorbeille;
use App\Repository\CorbeilleRepository;
use App\Service\Corbeille\Corbeille;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Corbeille (docs/corbeille.md) en ligne de commande, surtout pour le site
 * en ligne, qui n'a pas d'écran Corbeille : ce que les clients ont retiré
 * de leur espace (adresse, fichier, accès, brouillon) y reste le temps
 * réglé. Sans numéro, la commande liste les dernières entrées ; avec un
 * numéro, elle remet l'entrée en place, avec les mêmes contrôles que
 * l'écran (rien n'est écrit s'il manque une fiche liée).
 *
 *   php bin/console app:corbeille:restaurer          (liste)
 *   php bin/console app:corbeille:restaurer 42       (restaure l'entrée 42)
 */
#[AsCommand(name: 'app:corbeille:restaurer', description: 'Liste les dernières entrées de la corbeille, ou en restaure une par son numéro.')]
final class CorbeilleRestaurerCommand extends Command
{
    public function __construct(
        private readonly Corbeille $corbeille,
        private readonly CorbeilleRepository $depot,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('numero', InputArgument::OPTIONAL, 'Numéro de l\'entrée à restaurer (colonne « N° » de la liste)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $numero = $input->getArgument('numero');
        if (null === $numero) {
            $entrees = $this->corbeille->entrees([], null, 50);
            if ([] === $entrees) {
                $output->writeln('La corbeille est vide.');

                return Command::SUCCESS;
            }
            foreach ($entrees as ['entree' => $e, 'elements' => $n]) {
                $output->writeln(\sprintf('%6d  %s  %s%s  (%s)', $e->getId(), $e->getSupprimeLe()->format('d/m/Y H:i'), $e->getLibelle(), $n > 0 ? ' + '.$n.' élément'.($n > 1 ? 's' : '') : '', $e->getSupprimeParNom() ?? 'tâche automatique'));
            }

            return Command::SUCCESS;
        }

        $entree = ctype_digit((string) $numero) ? $this->depot->find((int) $numero) : null;
        if (!$entree instanceof EntreeCorbeille || !$entree->isRacine()) {
            $output->writeln('<error>Pas d\'entrée '.$numero.' dans la corbeille.</error>');

            return Command::FAILURE;
        }
        try {
            $output->writeln($this->corbeille->restaurer($entree, null));
        } catch (\DomainException $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
