<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * Le thème Workload (ThemeForest) est sous licence : il n'est pas versionné.
 * Cette commande copie dans public/theme les seuls fichiers utilisés par l'application,
 * à partir du dossier « xhtml » de l'archive achetée.
 *
 *   php bin/console app:installer-theme ~/Downloads/WorkLoad-v1.2-28-Octuber-2022/xhtml
 *
 * Le CSS du thème demande ses polices à Google (« @import » vers
 * fonts.googleapis.com) : ces lignes sont retirées à l'installation, les
 * polices utilisées (Poppins, Roboto) sont servies par le site depuis
 * public/vendor (docs/securite.md : rien d'un autre site à l'exécution).
 * Pour un thème déjà installé, sans l'archive :
 *
 *   php bin/console app:installer-theme --polices-locales
 */
#[AsCommand(name: 'app:installer-theme', description: 'Installe les fichiers du thème Workload dans public/theme')]
final class InstallerThemeCommand extends Command
{
    private const FICHIERS = [
        'css/style.css', 'css/perfect-scrollbar.css',
        'js/custom.min.js', 'js/dlabnav-init.js',
        'images/favicon.png', 'images/logo.png', 'images/circles.png', 'images/ellipse.png', 'images/ellipse2.png', 'images/profile/cover.jpg',
        'vendor/perfect-scrollbar/css/perfect-scrollbar.css', 'vendor/metismenu/css/metisMenu.min.css',
        'vendor/aos/css/aos.min.css', 'vendor/animate/animate.min.css',
        'vendor/fullcalendar/lib/locales/fr.js',
    ];
    private const DOSSIERS = [
        'vendor/global', 'vendor/chart.js', 'vendor/select2', 'vendor/toastr', 'vendor/jquery-nice-select',
        'vendor/fullcalendar/css', 'vendor/fullcalendar/js', 'vendor/datatables', 'icons',
    ];

    public function __construct(#[Autowire('%kernel.project_dir%/public/theme')] private readonly string $cible)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('source', InputArgument::OPTIONAL, 'Chemin du dossier « xhtml » du thème Workload');
        $this->addOption('polices-locales', null, InputOption::VALUE_NONE, 'Retirer seulement les polices Google du thème déjà installé');
    }

    /**
     * Retire les « @import » vers un autre site (polices Google du thème),
     * avec ou sans url(), guillemets ou « https: ». L'adresse peut contenir
     * des « ; » (css2?family=…wght@0,200;0,300) : elle est lue jusqu'à son
     * guillemet ou sa parenthèse.
     */
    public static function sansImportsExternes(string $css, ?int &$retires = null): string
    {
        $adresse = '(?:"(?:https?:)?//[^"]*"|\'(?:https?:)?//[^\']*\')';

        return (string) preg_replace('~[ \t]*@import\s+(?:url\(\s*(?:'.$adresse.'|(?:https?:)?//[^)\s]*)\s*\)|'.$adresse.')[^;]*;[ \t]*\R?~i', '', $css, -1, $retires);
    }

    /** Nombre de « @import » vers un autre site encore présents. */
    public static function importsExternes(string $css): int
    {
        self::sansImportsExternes($css, $retires);

        return (int) $retires;
    }

    private function retirerPolicesExternes(SymfonyStyle $io): bool
    {
        $fichier = $this->cible.'/css/style.css';
        if (!is_file($fichier)) {
            $io->error('Thème absent : installez-le d\'abord avec le dossier « xhtml » du thème Workload.');

            return false;
        }
        $css = self::sansImportsExternes((string) file_get_contents($fichier), $retires);
        if ($retires > 0) {
            (new Filesystem())->dumpFile($fichier, $css);
        }
        $io->writeln($retires > 0 ? "Polices Google retirées du thème ($retires lignes) : les polices viennent de public/vendor." : 'Le thème ne demande déjà plus rien à un autre site.');

        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (null === $input->getArgument('source')) {
            if ($input->getOption('polices-locales')) {
                return $this->retirerPolicesExternes($io) ? Command::SUCCESS : Command::FAILURE;
            }
            $io->error('Indiquez le dossier « xhtml » du thème Workload, ou --polices-locales pour corriger un thème déjà installé.');

            return Command::FAILURE;
        }
        $source = rtrim($input->getArgument('source'), '/');
        if (!is_file($source.'/css/style.css') || !is_file($source.'/vendor/global/global.min.js')) {
            $io->error('Dossier invalide : indiquez le dossier « xhtml » du thème Workload (il contient css/, js/, vendor/, icons/).');

            return Command::FAILURE;
        }

        $fs = new Filesystem();
        $fs->remove($this->cible);
        foreach (self::FICHIERS as $f) {
            if (is_file($source.'/'.$f)) {
                // La locale FullCalendar est rangée à plat côté application.
                $fs->copy($source.'/'.$f, $this->cible.'/'.('vendor/fullcalendar/lib/locales/fr.js' === $f ? 'vendor/fullcalendar/lib/fr.js' : $f), true);
            } else {
                $io->warning("Fichier absent du thème : $f");
            }
        }
        foreach (self::DOSSIERS as $d) {
            $fs->mirror($source.'/'.$d, $this->cible.'/'.$d);
        }

        // Allège les polices d'icônes : les sources SVG/SCSS ne servent pas au navigateur.
        $inutiles = (new Finder())->in($this->cible.'/icons')->directories()->name(['svg', 'svgs', 'icons', 'scss', 'less', 'sprites', 'metadata', 'js']);
        $fs->remove(iterator_to_array($inutiles));
        $fs->remove($this->cible.'/icons/feather');
        $fs->remove(iterator_to_array((new Finder())->in($this->cible.'/icons')->files()->name(['*.svg', '*.eot', '*.html'])));
        $this->retirerPolicesExternes($io);

        $io->success('Thème installé dans public/theme.');

        return Command::SUCCESS;
    }
}
