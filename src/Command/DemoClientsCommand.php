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
 * Charge (ou enlève) les clients fictifs de donnees-demo/ dans la base de
 * l'application : même connexion que le site, sans client mysql à part.
 * Refusé en production.
 */
#[AsCommand(name: 'app:demo:clients', description: 'Charge les 200 clients fictifs de démonstration (ou les enlève avec --purger).')]
final class DemoClientsCommand extends Command
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
        $this->addOption('purger', null, InputOption::VALUE_NONE, 'Enlève les clients fictifs au lieu de les charger.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement) {
            $io->error('Pas de données fictives en production.');

            return Command::FAILURE;
        }
        $fichier = $this->dossier.'/donnees-demo/'.($input->getOption('purger') ? 'clients-demo-purge.sql' : 'clients-demo.sql');
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

        $clients = (int) $this->connexion->fetchOne("SELECT COUNT(*) FROM clients WHERE code LIKE 'DEMO-%'");
        $io->success(sprintf('%d instructions passées sur la base « %s ». Clients de démo présents : %d.', $n, $this->connexion->getDatabase(), $clients));

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
