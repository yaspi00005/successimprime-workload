<?php

namespace App\Command;

use App\Service\Prepresse\DossierPartage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Tâche planifiée de l'ERP local, chaque minute (docs/fichiers-lourds.md,
 * docs/prepresse.md) : les fichiers du dossier partagé nommés avec un
 * numéro de commande (« CMD-000378_5,5x7.pdf ») ou de ligne
 * (« 123_affiche.pdf ») sont rattachés à la commande, qui passe en
 * « À contrôler ». Les fichiers pas reconnus restent où ils sont et
 * s'affichent dans « Fichiers à classer ». Un fichier encore en cours de
 * copie (taille qui bouge, modifié il y a moins de 30 s) attend la minute
 * suivante. Relancer la tâche ne rattache jamais deux fois : un fichier
 * rattaché a quitté le dossier.
 */
#[AsCommand(name: 'app:dossier-partage:importer', description: 'Prépresse : rattache aux commandes les fichiers du dossier partagé nommés avec un numéro de commande.')]
final class DossierPartageImporterCommand extends Command
{
    public function __construct(
        private readonly DossierPartage $partage,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        // Une seule tâche à la fois : un gros fichier peut prendre plus d'une minute à déplacer.
        $verrou = @fopen($this->projectDir.'/var/dossier-partage-import.lock', 'c');
        if (false === $verrou || !flock($verrou, \LOCK_EX | \LOCK_NB)) {
            $io->writeln('Import déjà en cours, rien à faire.');

            return Command::SUCCESS;
        }
        try {
            $bilan = $this->partage->importer(new \DateTimeImmutable());
        } finally {
            flock($verrou, \LOCK_UN);
            fclose($verrou);
        }
        foreach ($bilan['importes'] as $ligne) {
            $io->writeln('Rattaché : '.$ligne);
        }
        $io->writeln(\sprintf('%d rattaché(s), %d à classer, %d en cours de copie.', \count($bilan['importes']), \count($bilan['ignores']), $bilan['enCours']));

        return Command::SUCCESS;
    }
}
