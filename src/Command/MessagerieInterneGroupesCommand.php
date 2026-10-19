<?php

namespace App\Command;

use App\Service\MessagerieInterne\Groupes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Crée les groupes par défaut de la messagerie de l'équipe qui manquent
 * (Général, Atelier, Prépresse, Caisse et finances) et y ajoute les
 * personnes de leurs rôles. Relancer ne crée rien en double
 * (docs/messagerie-interne.md).
 */
#[AsCommand(name: 'app:messagerie-interne:groupes', description: 'Crée les groupes par défaut de la messagerie de l\'équipe qui manquent.')]
final class MessagerieInterneGroupesCommand extends Command
{
    public function __construct(private readonly Groupes $groupes)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $crees = $this->groupes->creerParDefaut();
        $io->success([] === $crees ? 'Les groupes par défaut existent déjà.' : 'Groupes créés : '.implode(', ', $crees).'.');

        return Command::SUCCESS;
    }
}
