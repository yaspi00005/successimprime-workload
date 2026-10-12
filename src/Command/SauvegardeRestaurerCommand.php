<?php

namespace App\Command;

use App\Service\Sauvegarde\Sauvegardes;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Remet une sauvegarde. Volontairement en ligne de commande seulement :
 * aucun bouton du site ne peut écraser la base.
 *
 * Avant d'écraser la base en service, une sauvegarde de l'état actuel est
 * faite. --base-cible remet la sauvegarde dans une autre base, pour vérifier
 * qu'elle se restaure sans toucher à rien.
 */
#[AsCommand(name: 'app:sauvegarde:restaurer', description: 'Remet la base et/ou les fichiers d\'une sauvegarde.')]
final class SauvegardeRestaurerCommand extends Command
{
    public function __construct(
        private readonly Sauvegardes $sauvegardes,
        private readonly Connection $connexion,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('sauvegarde', InputArgument::OPTIONAL, 'Nom de la sauvegarde (AAAAMMJJ-HHMMSS) ; « derniere » pour la plus récente')
            ->addOption('base', null, InputOption::VALUE_NONE, 'Remettre seulement la base')
            ->addOption('fichiers', null, InputOption::VALUE_NONE, 'Remettre seulement les fichiers')
            ->addOption('base-cible', null, InputOption::VALUE_REQUIRED, 'Remettre la base dans cette autre base (contrôle), sans toucher à la base en service')
            ->addOption('confirmer', null, InputOption::VALUE_REQUIRED, 'Nom de la base en service, pour confirmer sans question');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $liste = $this->sauvegardes->liste();
        $nom = (string) $input->getArgument('sauvegarde');
        if ($nom === '') {
            $io->writeln('Sauvegardes disponibles (app:sauvegarde --liste pour le détail) :');
            $io->listing(array_map(static fn ($s) => $s['nom'].($s['fichiers'] !== null ? ' (base + fichiers)' : ' (base)'), $liste));

            return Command::SUCCESS;
        }
        if ($nom === 'derniere') {
            $nom = $liste[0]['nom'] ?? '';
        }
        $s = $this->sauvegardes->trouver($nom);
        if ($s === null) {
            $io->error('Sauvegarde introuvable : '.$nom);

            return Command::FAILURE;
        }

        $cibleNom = $input->getOption('base-cible');
        $faireBase = !$input->getOption('fichiers') || $input->getOption('base');
        $faireFichiers = $cibleNom === null && (!$input->getOption('base') || $input->getOption('fichiers')) && $s['fichiers'] !== null;

        if ($cibleNom !== null) {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $cibleNom) || $cibleNom === $this->connexion->getDatabase()) {
                $io->error('--base-cible doit être une autre base que celle en service (lettres, chiffres, _).');

                return Command::FAILURE;
            }
            $params = $this->connexion->getParams();
            $outil = DriverManager::getConnection(array_diff_key($params, ['dbname' => 1, 'url' => 1]));
            $outil->executeStatement('CREATE DATABASE IF NOT EXISTS `'.$cibleNom.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $cible = DriverManager::getConnection(['dbname' => $cibleNom] + array_diff_key($params, ['url' => 1]));
            $n = $this->sauvegardes->restaurerBase($nom, $cible);
            $tables = \count($cible->fetchFirstColumn("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
            $attendu = isset($s['base']['tables']) ? \sprintf(' (attendu %d)', $s['base']['tables']) : '';
            $io->success(\sprintf('Sauvegarde %s remise dans la base %s : %d instructions, %d tables%s. La base en service n\'a pas été touchée.', $nom, $cibleNom, $n, $tables, $attendu));

            return $tables === ($s['base']['tables'] ?? -1) ? Command::SUCCESS : Command::FAILURE;
        }

        $base = (string) $this->connexion->getDatabase();
        $io->warning(\sprintf(
            "Vous allez remettre la sauvegarde du %s.\n%s%s\nTout ce qui a été saisi depuis sera perdu (une sauvegarde de l'état actuel est faite avant).",
            Sauvegardes::dateLisible($nom),
            $faireBase ? 'La base « '.$base.' » sera entièrement remplacée. ' : '',
            $faireFichiers ? 'Les fichiers seront remis par-dessus ceux du site.' : '',
        ));
        $confirmation = $input->getOption('confirmer') ?? ($input->isInteractive() ? $io->ask('Tapez le nom de la base pour confirmer') : null);
        if ($confirmation !== $base) {
            $io->error('Confirmation incorrecte, rien n\'a été fait.');

            return Command::FAILURE;
        }

        try {
            $avant = $this->sauvegardes->lancer($faireFichiers, 'avant-restauration');
            $io->writeln('État actuel sauvegardé dans '.$avant['nom'].'.');
        } catch (\Throwable $e) {
            $io->error('Sauvegarde de sécurité impossible, restauration annulée : '.$e->getMessage());

            return Command::FAILURE;
        }

        if ($faireBase) {
            $n = $this->sauvegardes->restaurerBase($nom);
            $io->writeln(\sprintf('Base remise (%d instructions).', $n));
        }
        if ($faireFichiers) {
            $f = $this->sauvegardes->restaurerFichiers($nom);
            $io->writeln(\sprintf('%d fichiers remis%s.', $f['fichiers'], $f['ignores'] > 0 ? ', '.$f['ignores'].' ignorés (chemin non autorisé)' : ''));
        }
        $io->success('Restauration terminée. Lancez ensuite : php bin/console doctrine:migrations:migrate -n (si le code est plus récent que la sauvegarde).');

        return Command::SUCCESS;
    }
}
