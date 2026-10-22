<?php

namespace App\Command;

use App\Service\Documentation\GuideTechnique;
use App\Service\Documentation\SecretsGuide;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Guide technique (docs/technique/README.md) : écrit docs/technique/guide.json,
 * tiré du code à chaque lancement (tables, routes et droits, contrôleurs,
 * services, commandes et tâches planifiées, sécurité, synchronisation,
 * paramètres, chapitres et points d'audit).
 *
 * Sans serveur web et en lecture seule : seulement les métadonnées des
 * entités, aucune requête sur les données. Le fichier est refusé s'il
 * contient quelque chose qui ressemble à un secret (SecretsGuide).
 */
#[AsCommand(name: 'app:doc:technique', description: 'Guide technique : écrit docs/technique/guide.json depuis le code (tables, routes, droits, sécurité, chapitres, audit).')]
final class DocTechniqueCommand extends Command
{
    /** Au-delà, le guide devient lourd à ouvrir : la commande prévient. */
    private const TAILLE_MAX = 8 * 1024 * 1024;

    public function __construct(
        private readonly GuideTechnique $guide,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('sortie', null, InputOption::VALUE_REQUIRED, 'Fichier JSON à écrire (relatif au projet)', 'docs/technique/guide.json')
            ->addOption('copie', null, InputOption::VALUE_REQUIRED, 'Copier aussi le fichier ici (dossier ou fichier)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $application = $this->getApplication();
        $commandes = null === $application ? [] : array_values(array_filter($application->all(), static fn (Command $c): bool => str_starts_with((string) $c->getName(), 'app:')));
        // Une même commande peut être listée sous ses alias : une seule fois.
        $commandes = array_values(array_reduce($commandes, static function (array $vues, Command $c): array {
            $vues[(string) $c->getName()] = $c;

            return $vues;
        }, []));

        $guide = $this->guide->generer($commandes);
        $json = json_encode($guide, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);

        $soupcons = SecretsGuide::chercher($json, SecretsGuide::valeursSensiblesDeLEnvironnement());
        if ([] !== $soupcons) {
            $io->error(array_merge(['Guide non écrit : il contient quelque chose qui ressemble à un secret.'], $soupcons));

            return Command::FAILURE;
        }

        $sortie = (string) $input->getOption('sortie');
        $chemin = str_starts_with($sortie, '/') ? $sortie : $this->projet.'/'.$sortie;
        if (!is_dir(\dirname($chemin)) && !mkdir(\dirname($chemin), 0775, true) && !is_dir(\dirname($chemin))) {
            $io->error('Dossier impossible à créer : '.\dirname($chemin));

            return Command::FAILURE;
        }
        file_put_contents($chemin, $json."\n");

        $copie = $input->getOption('copie');
        if (\is_string($copie) && '' !== $copie) {
            $cible = is_dir($copie) || str_ends_with($copie, '/') ? rtrim($copie, '/').'/'.basename($chemin) : $copie;
            if (!is_dir(\dirname($cible))) {
                mkdir(\dirname($cible), 0775, true);
            }
            copy($chemin, $cible);
            $io->writeln('Copie : '.$cible);
        }

        $stats = $guide['stats'];
        $io->title('Guide technique');
        $io->definitionList(
            ['Fichier' => $sortie],
            ['Taille' => number_format(\strlen($json) / 1024, 0, ',', ' ').' Ko'],
            ['Commit' => ($guide['genere_le']['commit'] ?? '?').' ('.($guide['genere_le']['branche'] ?? '?').')'],
            ['Entités' => $stats['entites']],
            ['Routes' => \sprintf('%d (dont %d qui modifient, %d publiques)', $stats['routes'], $stats['routes_modifiant_des_donnees'], $stats['routes_publiques'])],
            ['Routes sans jeton CSRF signalées' => $stats['routes_sans_csrf_signalees']],
            ['Contrôleurs' => $stats['controleurs']],
            ['Services' => $stats['services']],
            ['Commandes' => \sprintf('%d (dont %d planifiées)', $stats['commandes'], $stats['commandes_planifiees'])],
            ['Migrations' => $stats['migrations']],
            ['Tests unitaires' => $stats['tests_unitaires']],
            ['Chapitres' => $stats['chapitres']],
            ['Points d\'audit' => $stats['audit'].' ('.implode(', ', array_map(static fn (string $t, int $n): string => $t.' '.$n, array_keys($stats['audit_par_type']), $stats['audit_par_type'])).')'],
        );
        if (\strlen($json) > self::TAILLE_MAX) {
            $io->warning('Le guide dépasse 8 Mo : alléger les listes avant de le partager.');
        }

        return Command::SUCCESS;
    }
}
