<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Charge (ou enlève) la démonstration de donnees-demo/ : une année
 * d'activité fictive dans toutes les tables, par la même connexion que le
 * site, sans client mysql à part. Refusé en production.
 */
#[AsCommand(name: 'app:demo', description: 'Charge une année d\'activité fictive dans toutes les tables (ou l\'enlève avec --purger).', aliases: ['app:demo:clients'])]
final class DemoCommand extends Command
{
    public function __construct(
        private readonly Connection $connexion,
        #[Autowire('%kernel.project_dir%')] private readonly string $dossier,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('purger', null, InputOption::VALUE_NONE, 'Enlève la démonstration au lieu de la charger.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement) {
            $io->error('Pas de données fictives en production.');

            return Command::FAILURE;
        }
        $fichier = $this->dossier.'/donnees-demo/'.($input->getOption('purger') ? 'demo-purge.sql' : 'demo.sql');
        if (!is_file($fichier)) {
            $io->error('Fichier introuvable : '.$fichier);

            return Command::FAILURE;
        }

        $n = 0;
        try {
            $this->connexion->beginTransaction();
            foreach (self::instructions((string) file_get_contents($fichier)) as $sql) {
                $this->connexion->executeStatement($sql);
                ++$n;
            }
            $this->connexion->commit();
        } catch (\Throwable $e) {
            if ($this->connexion->isTransactionActive()) {
                $this->connexion->rollBack();
            }
            $io->error('Arrêt à l\'instruction '.($n + 1).' : '.$e->getMessage());

            return Command::FAILURE;
        }

        $compte = fn (string $table): int => (int) $this->connexion->fetchOne('SELECT COUNT(*) FROM `'.$table.'` WHERE id >= 900000');
        $io->success(sprintf(
            '%d instructions passées sur la base « %s ». Démo présente : %d clients, %d commandes, %d paiements, %d mouvements de trésorerie.',
            $n, $this->connexion->getDatabase(), $compte('clients'), $compte('commandes'), $compte('paiements'), $compte('mouvement_tresorerie'),
        ));
        if (!$input->getOption('purger')) {
            $io->text('Connexion possible avec demo.kadiatou (direction), demo.aminata (caisse), demo.seydou (graphiste), demo.oumar (machine), demo.bakary (livreur) ou demo.awa (entretien), mot de passe demo1234.');
        }

        return Command::SUCCESS;
    }

    /**
     * Une instruction par ligne terminée par « ; » (format des fichiers générés).
     *
     * @return list<string>
     */
    public static function instructions(string $contenu): array
    {
        $liste = [];
        $courante = '';
        foreach (preg_split('/\R/', $contenu) ?: [] as $ligne) {
            if ('' === trim($ligne) || str_starts_with(ltrim($ligne), '--')) {
                continue;
            }
            $courante .= ('' === $courante ? '' : "\n").$ligne;
            if (str_ends_with(rtrim($ligne), ';')) {
                $sql = rtrim(trim($courante), ';');
                if (!preg_match('/^(START TRANSACTION|COMMIT)$/i', $sql)) {
                    $liste[] = $sql;
                }
                $courante = '';
            }
        }

        return $liste;
    }
}
