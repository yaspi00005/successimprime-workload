<?php

namespace App\Command;

use App\Security\AccesEnLigne;
use App\Service\Installation\VerificationInstallation;
use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Contrôle d'une installation avant sa mise en service (docs/deploiement.md).
 * N'affiche aucun secret : seulement s'il est renseigné.
 */
#[AsCommand(name: 'app:verifier-installation', description: 'Vérifie PHP, .env.local, la base et les dossiers avant la mise en service (aucun secret affiché).')]
final class VerifierInstallationCommand extends Command
{
    /** Variables lues ; leurs valeurs ne sortent jamais de la commande. */
    private const VARIABLES = [
        'APP_ENV', 'APP_SECRET', 'DEFAULT_URI', 'MAILER_DSN', 'SYNC_URL', 'SYNC_CLE',
        'SAUVEGARDE_COPIE_DIR', 'SAUVEGARDE_CLE_HORS_SITE', 'ORANGE_SMS_CLIENT_ID', 'ORANGE_SMS_CLIENT_SECRET',
        'ORANGE_MONEY_CLIENT_ID', 'ORANGE_MONEY_CLIENT_SECRET', 'ORANGE_MONEY_MERCHANT_KEY', 'VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY',
    ];

    private const LIBELLES = [
        VerificationInstallation::OK => '<info>OK</info>',
        VerificationInstallation::A_VOIR => '<comment>À voir</comment>',
        VerificationInstallation::ERREUR => '<error>Erreur</error>',
    ];

    public function __construct(
        private readonly AccesEnLigne $acces,
        private readonly Connection $base,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')] private readonly DependencyFactory $migrations,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
        #[Autowire('%env(default:app.sauvegarde_dir:SAUVEGARDE_DIR)%')] private readonly string $dossierSauvegardes,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $enLigne = $this->acces->estEnLigne();
        $io->title($enLigne ? 'Vérification du site en ligne' : 'Vérification de l\'ERP local');

        $env = [];
        foreach (self::VARIABLES as $cle) {
            $env[$cle] = (string) ($_SERVER[$cle] ?? $_ENV[$cle] ?? getenv($cle) ?: '');
        }
        $gd = \function_exists('gd_info') ? gd_info() : [];
        $resultats = VerificationInstallation::verifier($env, [
            'version' => \PHP_VERSION,
            'extensions' => get_loaded_extensions(),
            'webp' => (bool) ($gd['WebP Support'] ?? false),
            'jpeg' => (bool) ($gd['JPEG Support'] ?? false),
        ], $enLigne);

        foreach ([...$this->base(), ...$this->dossiers($enLigne)] as $resultat) {
            $resultats[] = $resultat;
        }

        $io->table(['Statut', 'Point', 'Détail'], array_map(static fn (array $r): array => [self::LIBELLES[$r['statut']], $r['point'], $r['detail']], $resultats));

        if (VerificationInstallation::aDesErreurs($resultats)) {
            $io->error('À corriger avant la mise en service.');

            return Command::FAILURE;
        }
        $io->success('Rien de bloquant. Les points « À voir » limitent une fonction précise.');

        return Command::SUCCESS;
    }

    /** @return list<array{statut: string, point: string, detail: string}> */
    private function base(): array
    {
        try {
            $version = (string) $this->base->fetchOne('SELECT VERSION()');
        } catch (\Throwable $e) {
            return [['statut' => VerificationInstallation::ERREUR, 'point' => 'Base de données', 'detail' => 'connexion impossible : vérifier DATABASE_URL']];
        }
        $resultats = [['statut' => VerificationInstallation::OK, 'point' => 'Base de données', 'detail' => 'connectée ('.$version.')']];
        try {
            $nouvelles = \count($this->migrations->getMigrationStatusCalculator()->getNewMigrations());
            $resultats[] = 0 === $nouvelles
                ? ['statut' => VerificationInstallation::OK, 'point' => 'Migrations', 'detail' => 'à jour']
                : ['statut' => VerificationInstallation::ERREUR, 'point' => 'Migrations', 'detail' => \sprintf('%d à passer (voir docs/migration-de-base.md)', $nouvelles)];
        } catch (\Throwable) {
            $resultats[] = ['statut' => VerificationInstallation::ERREUR, 'point' => 'Migrations', 'detail' => 'état illisible : lancer doctrine:migrations:status'];
        }

        return $resultats;
    }

    /** @return list<array{statut: string, point: string, detail: string}> */
    private function dossiers(bool $enLigne): array
    {
        $dossiers = ['var' => $this->projet.'/var', 'public/uploads' => $this->projet.'/public/uploads'];
        if (!$enLigne) {
            $dossiers['sauvegardes'] = $this->dossierSauvegardes;
        }
        $resultats = [];
        foreach ($dossiers as $nom => $chemin) {
            $ok = is_dir($chemin) && is_writable($chemin);
            $resultats[] = ['statut' => $ok ? VerificationInstallation::OK : VerificationInstallation::ERREUR, 'point' => 'Dossier '.$nom, 'detail' => $ok ? 'accessible en écriture' : 'absent ou en lecture seule pour le compte du site'];
        }
        if (!$enLigne) {
            $theme = is_file($this->projet.'/public/theme/css/style.css');
            $resultats[] = ['statut' => $theme ? VerificationInstallation::OK : VerificationInstallation::ERREUR, 'point' => 'Thème de l\'administration', 'detail' => $theme ? 'installé' : 'à installer : php bin/console app:installer-theme <dossier du thème>'];
        }

        return $resultats;
    }
}
