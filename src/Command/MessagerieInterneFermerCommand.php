<?php

namespace App\Command;

use App\Service\MessagerieInterne\FichiersEquipe;
use App\Service\MessagerieInterne\Groupes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tâche planifiée de la messagerie de l'équipe (docs/messagerie-interne.md) :
 * ferme les groupes de projet dont la commande est livrée (rattrapage de la
 * fermeture faite à la livraison) et retire les envois de fichiers
 * commencés il y a plus d'un jour et jamais terminés.
 */
#[AsCommand(name: 'app:messagerie-interne:fermer', description: 'Ferme les groupes de projet livrés et nettoie les envois de fichiers abandonnés.')]
final class MessagerieInterneFermerCommand extends Command
{
    public function __construct(
        private readonly Groupes $groupes,
        private readonly FichiersEquipe $fichiers,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $n = $this->groupes->fermerLivres();
        $this->fichiers->nettoyer();
        $io->success(\sprintf('%d groupe%s de projet fermé%s.', $n, $n > 1 ? 's' : '', $n > 1 ? 's' : ''));

        return Command::SUCCESS;
    }
}
