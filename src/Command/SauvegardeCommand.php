<?php

namespace App\Command;

use App\Service\Sauvegarde\Sauvegardes;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sauvegarde de la base et des fichiers (docs/sauvegardes.md).
 *
 * La tâche planifiée la lance chaque heure avec --auto : elle décide seule
 * de faire une sauvegarde complète (une fois par jour, après l'heure de la
 * tuile Sauvegardes), la base seule (toutes les N heures) ou rien.
 */
#[AsCommand(name: 'app:sauvegarde', description: 'Sauvegarde la base et les fichiers, vérifie, fait tourner les anciennes et recopie sur le second disque.')]
final class SauvegardeCommand extends Command
{
    public function __construct(private readonly Sauvegardes $sauvegardes)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('auto', null, InputOption::VALUE_NONE, 'Mode tâche planifiée : ne fait que ce qui est dû')
            ->addOption('base-seulement', null, InputOption::VALUE_NONE, 'Sans les fichiers')
            ->addOption('liste', null, InputOption::VALUE_NONE, 'Affiche les sauvegardes présentes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $maintenant = new \DateTimeImmutable();

        if ($input->getOption('liste')) {
            $lignes = array_map(static fn (array $s): array => [
                $s['nom'],
                $s['base'] !== null ? Sauvegardes::taille($s['base']['octets']) : '—',
                $s['fichiers'] !== null ? Sauvegardes::taille($s['fichiers']['octets']).' ('.$s['fichiers']['nombre'].' fichiers)' : '—',
                $s['declencheur'] ?? '',
                $s['copie'] ?? '',
            ], $this->sauvegardes->liste());
            $io->table(['Sauvegarde', 'Base', 'Fichiers', 'Lancée', 'Copie'], $lignes);
            $alerte = $this->sauvegardes->etat($maintenant)['alerte'];
            $alerte !== null ? $io->warning($alerte) : $io->success('Sauvegardes à jour.');

            return Command::SUCCESS;
        }

        $avecFichiers = !$input->getOption('base-seulement');
        if ($input->getOption('auto')) {
            $du = $this->sauvegardes->aFaireMaintenant($maintenant);
            if ($du === null) {
                $io->writeln('Rien à faire : la dernière sauvegarde est récente.', OutputInterface::VERBOSITY_VERBOSE);

                return Command::SUCCESS;
            }
            $avecFichiers = $du;
        }

        try {
            $m = $this->sauvegardes->lancer($avecFichiers, $input->getOption('auto') ? 'auto' : 'commande');
        } catch (\Throwable $e) {
            $io->error('Sauvegarde échouée : '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(\sprintf(
            'Sauvegarde %s : base %s (%d tables, %s)%s en %s s.%s',
            $m['nom'],
            Sauvegardes::taille($m['base']['octets']),
            $m['base']['tables'],
            $m['base']['methode'],
            $m['fichiers'] !== null ? ', fichiers '.Sauvegardes::taille($m['fichiers']['octets']).' ('.$m['fichiers']['nombre'].')' : '',
            $m['duree'],
            $m['copie'] === null ? '' : ($m['copie'] === 'ok' ? ' Copiée sur le second disque.' : ' COPIE ÉCHOUÉE : '.$m['copie']),
        ));
        if ($m['message'] !== null) {
            $io->note($m['message']);
        }

        return $m['copie'] === null || $m['copie'] === 'ok' ? Command::SUCCESS : Command::FAILURE;
    }
}
