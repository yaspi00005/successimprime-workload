<?php

namespace App\Command;

use App\Service\Catalogue\ProduitsDemo;
use App\Service\Achats\AchatsDemo;
use App\Service\Commandes\HorsImprimerieDemo;
use App\Service\NotificationsEquipe\NotificationsDemo;
use App\Service\Site\EquipeDemo;
use App\Service\Societes\RattachementSql;
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
        // Avoirs (FD04) sur les factures fictives, et ceux restés sans facture après un rechargement.
        'DELETE FROM avoir WHERE facture_id >= 900000 OR facture_id NOT IN (SELECT id FROM factures)',
        // Patrimoine et primes : dotations et évaluations calculées après le chargement.
        'DELETE FROM dotation_amortissement WHERE immobilisation_id >= 900000',
        'DELETE FROM evaluation_prime WHERE agent_id >= 900000',
        'DELETE FROM prime_agent WHERE agent_id >= 900000',
        // Demandes de conception, images et crédit IA des clients fictifs.
        "DELETE e FROM demande_conception_element e JOIN demande_conception d ON d.id = e.demande_conception_id JOIN clients c ON c.id = d.client_id WHERE c.code LIKE 'DEMO-%' OR c.id >= 900000",
        "DELETE i FROM image_ia i JOIN clients c ON c.id = i.client_id WHERE c.code LIKE 'DEMO-%' OR c.id >= 900000",
        "DELETE t FROM transaction_orange_money t JOIN clients c ON c.id = t.client_id WHERE c.code LIKE 'DEMO-%' OR c.id >= 900000",
        "DELETE d FROM demande_conception d JOIN clients c ON c.id = d.client_id WHERE c.code LIKE 'DEMO-%' OR c.id >= 900000",
        "DELETE m FROM mouvement_credit_ia m JOIN clients c ON c.id = m.client_id WHERE c.code LIKE 'DEMO-%' OR c.id >= 900000",
        "DELETE k FROM credit_ia k JOIN clients c ON c.id = k.client_id WHERE c.code LIKE 'DEMO-%' OR c.id >= 900000",
        // Compte du client (app:solde-client:demo) : relevé et dépôts en ligne des clients fictifs (après les transactions Orange Money).
        "DELETE m FROM mouvement_solde_client m JOIN clients c ON c.id = m.client_id WHERE c.code LIKE 'DEMO-%' OR c.id >= 900000",
        "DELETE d FROM depot_solde_client d JOIN clients c ON c.id = d.client_id WHERE c.code LIKE 'DEMO-%' OR c.id >= 900000",
        // Atelier de production (app:atelier:demo) : réglages, incidents et entretien des machines fictives.
        'DELETE o FROM atelier_machine_operateur o JOIN atelier_machine a ON a.id = o.atelier_machine_id WHERE a.machine_id >= 900000',
        'DELETE FROM atelier_machine WHERE machine_id >= 900000',
        'DELETE FROM incident_machine WHERE machine_id >= 900000',
        'DELETE FROM entretien_machine WHERE machine_id >= 900000',
        // Réservations de stock faites à la validation des commandes fictives (poste machiniste, prépresse) : avant leurs articles.
        'DELETE FROM stock_reservation WHERE commande_detail_id >= 900000',
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
        // Messagerie de l'équipe : ses fichiers sur le disque avant ses lignes (docs/messagerie-interne.md).
        $this->lancer('app:messagerie-interne:demo', ['--purger' => true], $output);
        if ($purger) {
            // Les avis fictifs d'abord : leur commande les enlève proprement.
            $this->lancer('app:sondages:demo', ['--purger' => true], $output);
            // Dossiers clients de démonstration (var/dossiers-demo) et leurs lignes.
            $this->lancer('app:dossiers-clients:demo', ['--purger' => true], $output);
        }

        $n = 0;
        try {
            $this->connexion->beginTransaction();
            foreach ([...MessagerieInterneDemoCommand::PURGE, ...MessagerieDemoCommand::PURGE, ...PrepresseDemoCommand::PURGE, ...PosteMachinisteDemoCommand::PURGE, ...DossiersClientsDemoCommand::PURGE, ...self::PURGE_MODULES, ...EquipeDemo::PURGE, ...ProduitsDemo::PURGE, ...AchatsDemo::PURGE, ...self::instructions((string) file_get_contents($fichier))] as $sql) {
                $this->connexion->executeStatement($sql);
                ++$n;
            }
            if (!$purger) {
                // Chargés avec la société principale, les documents d'un autre émetteur (DREPA) prennent la leur (docs/societes.md).
                foreach (RattachementSql::instructions(900000) as $sql) {
                    $this->connexion->executeStatement($sql);
                }
                // « Notre équipe » de la page « Qui sommes-nous ? » : avatars dessinés, une personne sans accord.
                EquipeDemo::charger($this->connexion);
                // Produits (docs/produits.md) : délais, machines, un produit masqué en ligne, prix négociés.
                ProduitsDemo::charger($this->connexion);
                // Notifications de l'équipe (docs/notifications-equipe.md) : mention, commande prête, accord de plafond, reportée, historique.
                NotificationsDemo::charger($this->connexion);
                // Ventes hors imprimerie (docs/hors-imprimerie.md) : tablettes et ordinateurs facturés, comptés à part.
                HorsImprimerieDemo::charger($this->connexion);
                // Achats (docs/achats.md) : fournisseurs des maquettes SU01 à SU03, un an de commandes d'achat, retards, casse, dettes.
                AchatsDemo::charger($this->connexion);
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
            // Comptes clients : monnaie gardée, dépôts, argent rendu, dépôts en ligne à valider.
            $this->lancer('app:solde-client:demo', [], $output);
            // Suivi des devis : envoi, validité, conditions, versions, ouvertures, refus et relances (docs/devis.md).
            $this->lancer('app:devis:demo', [], $output);
            // Atelier de production : une journée en cours sur les machines (docs/atelier-production.md).
            $this->lancer('app:atelier:demo', [], $output);
            // Dotations aux amortissements et évaluations de primes des derniers mois.
            // Conversations avec les clients (délais et retards comptent dans les primes qui suivent).
            $this->lancer('app:messagerie:demo', [], $output);
            // Poste machiniste : huit travaux de demo.oumar, du fichier à vérifier au colis prêt (docs/poste-machiniste.md).
            $this->lancer('app:poste-machiniste:demo', [], $output);
            // Prépresse : Fofana 2 fichiers sur 6, relances, BAT chez Samaké, vinyle prêt (docs/prepresse.md).
            $this->lancer('app:prepresse:demo', [], $output);
            // Fiche client de Salon Espoir : conditions, charte, contact « Factures », notes internes (docs/clients.md).
            $this->lancer('app:fiche-client:demo', [], $output);
            // Dossiers clients : faux serveur sous var/dossiers-demo, liés, proposés et à classer (docs/dossiers-clients.md).
            $this->lancer('app:dossiers-clients:demo', [], $output);
            // Messagerie de l'équipe : groupes, fil de l'Atelier, projets, annonces, messages privés (docs/messagerie-interne.md).
            $this->lancer('app:messagerie-interne:demo', [], $output);
            $this->lancer('app:immobilisations:dotations', ['--demo' => true], $output);
            $this->lancer('app:primes', ['--demo' => true], $output);
            $io->text('Connexion possible avec demo.kadiatou (direction), demo.aminata (caisse), demo.seydou (graphiste), demo.oumar (machine), demo.bakary (livreur), demo.ibrahim (responsable d\'atelier) ou demo.awa (entretien), mot de passe demo1234.');
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
