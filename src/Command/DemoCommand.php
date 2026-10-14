<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
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
    /**
     * Ce que le fichier de purge ne connaît pas : lignes rattachées aux clients
     * et produits de démonstration par les modules venus après lui. La purge
     * coupe les clés étrangères, donc les « ON DELETE CASCADE » ne jouent pas.
     */
    private const PURGE_MODULES = [
        "DELETE m FROM mouvement_fidelite m JOIN clients c ON c.id = m.client_id WHERE c.code LIKE 'DEMO-%' OR c.id >= 900000",
        "DELETE f FROM compte_fidelite f JOIN clients c ON c.id = f.client_id WHERE c.code LIKE 'DEMO-%' OR c.id >= 900000",
        "DELETE r FROM promo_reveil r JOIN clients c ON c.id = r.client_id WHERE c.code LIKE 'DEMO-%' OR c.id >= 900000",
        "DELETE r FROM regle_fidelite r JOIN produits p ON p.id = r.produit_id WHERE p.id >= 900000 OR p.nom LIKE '%(démo)%'",
        // Promotions (app:promotions --demo) : exceptions et anniversaires des clients fictifs, promotions repérées par leur note.
        "DELETE e FROM promotion_exception e JOIN clients c ON c.id = e.client_id WHERE c.code LIKE 'DEMO-%' OR c.id >= 900000",
        "DELETE a FROM promotion_anniversaire a JOIN clients c ON c.id = a.client_id WHERE c.code LIKE 'DEMO-%' OR c.id >= 900000",
        "DELETE p FROM promotion p JOIN promotion_client pc ON pc.promotion_id = p.id JOIN clients c ON c.id = pc.clients_id WHERE p.origine = 'anniversaire' AND (c.code LIKE 'DEMO-%' OR c.id >= 900000)",
        "DELETE FROM promotion WHERE note LIKE 'Démo : %'",
        // Patrimoine et primes : dotations et évaluations calculées après le chargement.
        'DELETE FROM dotation_amortissement WHERE immobilisation_id >= 900000',
        'DELETE FROM evaluation_prime WHERE agent_id >= 900000',
        'DELETE FROM prime_agent WHERE agent_id >= 900000',
    ];

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

        $purger = (bool) $input->getOption('purger');
        if ($purger) {
            // Les avis fictifs d'abord : leur commande les enlève proprement.
            $this->lancer('app:sondages:demo', ['--purger' => true], $output);
        }

        $n = 0;
        try {
            $this->connexion->beginTransaction();
            foreach ([...self::PURGE_MODULES, ...self::instructions((string) file_get_contents($fichier))] as $sql) {
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
        if (!$purger) {
            // Relevés de fidélité et avis des clients fictifs, calculés sur ce qui vient d'être chargé.
            $this->lancer('app:fidelite', ['--demo' => true], $output);
            $this->lancer('app:promotions', ['--demo' => true], $output);
            $this->lancer('app:sondages:demo', [], $output);
            // Dotations aux amortissements et évaluations de primes des derniers mois.
            $this->lancer('app:immobilisations:dotations', ['--demo' => true], $output);
            $this->lancer('app:primes', ['--demo' => true], $output);
            $io->text('Connexion possible avec demo.kadiatou (direction), demo.aminata (caisse), demo.seydou (graphiste), demo.oumar (machine), demo.bakary (livreur) ou demo.awa (entretien), mot de passe demo1234.');
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function lancer(string $commande, array $options, OutputInterface $output): void
    {
        $application = $this->getApplication();
        if (null === $application || !$application->has($commande)) {
            return;
        }
        $application->find($commande)->run(new ArrayInput($options), $output);
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
