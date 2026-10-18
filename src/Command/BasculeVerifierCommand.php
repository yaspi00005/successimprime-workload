<?php

namespace App\Command;

use App\Service\Bascule\Analyse;
use App\Service\Bascule\Bascule;
use App\Service\Bascule\BasculeInterrompue;
use App\Service\Bascule\Classement;
use App\Service\Bascule\Constat;
use App\Service\Bascule\Conversion;
use App\Service\Bascule\Ecart;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Bascule de la base de l'imprimerie vers la nouvelle version
 * (docs/migration-de-base.md, docs/mise-en-production.md).
 *
 * Sans option : lecture seule. Compare la base au schéma de la migration
 * de base et dit, écart par écart, si la correction est sans risque. Le
 * rapport est aussi écrit dans var/bascule/ (noms de tables et de colonnes,
 * nombres de lignes : jamais une donnée).
 *
 * Elle annonce aussi les conversions de données à rejouer (anciennes mises
 * à jour qui changeaient le sens des données : remises en pourcentage…),
 * avec des nombres de lignes seulement.
 *
 * --corriger : seulement s'il n'y a rien de bloquant. Sauvegarde, convertit
 * les données, applique, revérifie, puis note la migration de base comme
 * faite. Il reste ensuite à lancer doctrine:migrations:migrate.
 */
#[AsCommand(name: 'app:bascule:verifier', description: 'Compare la base de l\'imprimerie à la migration de base avant la bascule ; --corriger applique les écarts sans risque après une sauvegarde.')]
final class BasculeVerifierCommand extends Command
{
    private const TITRES = [
        Constat::BLOQUANT => 'Bloquant : à voir avec nous avant de continuer',
        Constat::SANS_PERTE => 'Changements sans perte (vérifiés sur les données)',
        Constat::AJOUT => 'Ajouts sans risque',
    ];

    private const TITRE_CONVERSIONS = 'Conversions de données à faire (par --corriger, après la sauvegarde)';

    public function __construct(
        private readonly Bascule $bascule,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('corriger', null, InputOption::VALUE_NONE, 'Applique les écarts sans risque après une sauvegarde, puis note la migration de base comme faite')
            ->addOption('confirmer', null, InputOption::VALUE_REQUIRED, 'Nom de la base, pour confirmer la correction sans question')
            ->addOption('garder-orphelins', null, InputOption::VALUE_NONE, 'Seulement après en avoir parlé avec nous : les lignes qui pointent déjà vers une ligne absente restent telles quelles et ne bloquent plus');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $base = $this->bascule->nomBase();
        $corriger = (bool) $input->getOption('corriger');
        $garderOrphelins = (bool) $input->getOption('garder-orphelins');
        $io->title(\sprintf('Bascule de la base « %s » vers la nouvelle version', $base));

        $etat = $this->bascule->etat();
        if (Bascule::BASCULEE === $etat) {
            $io->success('Cette base a déjà la migration de base : rien à basculer. Pour la mettre à jour : php bin/console doctrine:migrations:migrate -n, puis php bin/console doctrine:schema:validate');

            return Command::SUCCESS;
        }
        if (Bascule::VIDE === $etat) {
            $io->note('Base vide : pas de bascule, la créer avec php bin/console doctrine:migrations:migrate -n');

            return Command::SUCCESS;
        }

        $version = $this->bascule->controleVersion();
        if (null !== $version) {
            if ($corriger) {
                $io->error($version);

                return Command::FAILURE;
            }
            $io->warning($version);
        }

        $io->writeln('Construction du schéma de référence (migration de base dans une base provisoire, supprimée ensuite)…');
        try {
            $reference = $this->bascule->schemaReference();
            $analyse = $this->bascule->analyser($reference, $garderOrphelins);
        } catch (\Throwable $e) {
            $io->error('Vérification impossible : '.$e->getMessage());

            return Command::FAILURE;
        }

        $comptes = $this->bascule->compterLignes();
        $rapport = $this->rapport($base, $analyse, $comptes, $version);
        $fichier = $this->ecrire($rapport);
        $this->afficher($io, $analyse, $comptes);
        $io->writeln(\sprintf('Rapport complet : <info>%s</info> (noms de tables et nombres seulement, à nous envoyer si besoin).', $fichier));

        $bloquants = $analyse->bloquants();
        if ([] !== $bloquants) {
            $io->error(\sprintf('%d écart(s) bloquant(s) : ne pas basculer. La base n\'a pas été modifiée. Envoyez-nous le rapport.', \count($bloquants)));

            return Command::FAILURE;
        }
        if (!$corriger) {
            $io->success($analyse->identique()
                ? 'La base est identique au schéma de la migration de base. Étape suivante : php bin/console app:bascule:verifier --corriger'
                : 'Rien de bloquant. Étape suivante : php bin/console app:bascule:verifier --corriger (sauvegarde d\'abord, puis correction'.([] === $analyse->conversions ? '' : ' et conversions de données').').');

            return Command::SUCCESS;
        }

        $confirmation = $input->getOption('confirmer') ?? ($input->isInteractive() ? $io->ask(\sprintf('Une sauvegarde est faite d\'abord. Tapez le nom de la base (%s) pour corriger', $base)) : null);
        if ($confirmation !== $base) {
            $io->error('Confirmation incorrecte, rien n\'a été fait.');

            return Command::FAILURE;
        }

        try {
            $r = $this->bascule->corriger($reference, static fn (string $etape) => $io->writeln(' - '.$etape), $garderOrphelins);
        } catch (BasculeInterrompue $e) {
            $io->error(null === $e->sauvegarde
                ? ['Correction arrêtée : '.$e->getMessage(), 'La base n\'a pas été modifiée.']
                : [
                    'Correction interrompue : '.$e->getMessage(),
                    'La base est peut-être à moitié modifiée. Remettre la sauvegarde faite juste avant :',
                    \sprintf('php bin/console app:sauvegarde:restaurer %s --base', $e->sauvegarde),
                    'puis remettre l\'ancienne version du code et nous envoyer ce message.',
                ]);

            return Command::FAILURE;
        }

        $lignes = [];
        foreach (Bascule::TABLES_PRINCIPALES as $table) {
            $lignes[] = [$table, $r['avant'][$table] ?? '—', $r['apres'][$table] ?? '—'];
        }
        $io->table(['Table', 'Lignes avant', 'Lignes après'], $lignes);
        if ([] !== $r['remises']) {
            $io->section('Remises converties (chaque ligne recalculée, comparée à son total enregistré)');
            $io->listing(array_map(static fn (string $table, string $bilan): string => $table.' : '.$bilan, array_keys($r['remises']), $r['remises']));
        }
        $io->success([
            \sprintf('Bascule faite (sauvegarde %s). La base est au niveau de la migration de base.', $r['sauvegarde']),
            'Étapes suivantes : php bin/console doctrine:migrations:migrate -n',
            'puis : php bin/console doctrine:schema:validate',
        ]);

        return Command::SUCCESS;
    }

    /** @param array<string, int> $comptes */
    private function afficher(SymfonyStyle $io, Analyse $analyse, array $comptes): void
    {
        $io->section('Résumé');
        $io->table(['', 'Nombre'], [
            ['Tables dans la base / attendues', $analyse->tables.' / '.$analyse->tablesAttendues],
            ['Ajouts sans risque', \count($analyse->niveau(Constat::AJOUT))],
            ['Changements sans perte', \count($analyse->niveau(Constat::SANS_PERTE))],
            ['Conversions de données', \count($analyse->conversions)],
            ['Bloquants', \count($analyse->bloquants())],
        ]);

        foreach ([Constat::BLOQUANT, Constat::SANS_PERTE] as $niveau) {
            $liste = $analyse->niveau($niveau);
            if ([] !== $liste) {
                $io->section(self::TITRES[$niveau]);
                $io->listing(array_map(static fn (Constat $c): string => $c->ecart->cible().' : '.$c->message, $liste));
            }
        }
        if ([] !== $analyse->conversions) {
            $io->section(self::TITRE_CONVERSIONS);
            $io->listing(array_map(self::conversion(...), $analyse->conversions));
        }
        $ajouts = $analyse->niveau(Constat::AJOUT);
        if ([] !== $ajouts) {
            $io->section(self::TITRES[Constat::AJOUT].' (détail dans le rapport)');
            $io->listing(self::resumeAjouts($ajouts));
        }
        if ($analyse->sansDonnees) {
            $io->section('Base sans aucune ligne (copie de la structure ?) : à revérifier sur la vraie base');
            $io->writeln('Ces écarts passent ici parce que les tables sont vides. Sur la vraie base, ils bloqueraient si la table contient des lignes :');
            $io->listing([] === $analyse->pireCas ? ['aucun'] : array_map(self::aRevoir(...), $analyse->pireCas));
        }
        if ([] !== $analyse->orphelins) {
            $io->section('À signaler (déjà le cas aujourd\'hui, la bascule n\'y touche pas)');
            $io->listing(array_map(static fn (array $o): string => \sprintf('%s : %d ligne(s) pointent vers une ligne de %s qui n\'existe plus', $o['table'], $o['lignes'], $o['parente']), $analyse->orphelins));
        }

        $io->section('Lignes des tables principales');
        $io->table(Bascule::TABLES_PRINCIPALES, [array_map(static fn (string $t) => $comptes[$t] ?? '—', Bascule::TABLES_PRINCIPALES)]);
    }

    /** « commandes_details.remise : 12 ligne(s) avec une remise… » (des nombres, jamais une valeur). */
    public static function conversion(Conversion $c): string
    {
        return $c->cible().' : '.$c->message;
    }

    /** La question à se poser sur la vraie base pour un écart qui dépend des données. */
    public static function aRevoir(Constat $c): string
    {
        return $c->ecart->cible().' : '.match ($c->ecart->genre) {
            Ecart::TABLE_EN_TROP => 'table en trop, est-elle vide ?',
            Ecart::COLONNE_EN_TROP => 'colonne en trop, est-elle vide ?',
            Ecart::COLONNE_AJOUTEE => 'colonne obligatoire sans valeur par défaut, quelle valeur pour les lignes existantes ?',
            Ecart::COLONNE_MODIFIEE => Classement::decrire($c->ecart->avant).' → '.Classement::decrire($c->ecart->apres).', les valeurs y tiennent-elles ?',
            Ecart::INDEX_AJOUTE => 'index unique, y a-t-il des valeurs en double ?',
            Ecart::CLE_AJOUTEE => 'lien vers '.($c->ecart->apres['parente'] ?? '?').', des lignes pointent-elles vers une ligne absente ?',
            default => $c->message,
        };
    }

    /**
     * « clients : 12 colonnes, 2 index » par table.
     *
     * @param list<Constat> $ajouts
     *
     * @return list<string>
     */
    public static function resumeAjouts(array $ajouts): array
    {
        $parTable = [];
        foreach ($ajouts as $c) {
            $genre = match ($c->ecart->genre) {
                Ecart::TABLE_AJOUTEE => 'table à créer',
                Ecart::COLONNE_AJOUTEE => 'colonne(s)',
                Ecart::INDEX_AJOUTE => 'index',
                Ecart::CLE_AJOUTEE => 'lien(s)',
                default => 'autre(s)',
            };
            $parTable[$c->ecart->table][$genre] = ($parTable[$c->ecart->table][$genre] ?? 0) + 1;
        }
        ksort($parTable);
        $lignes = [];
        foreach ($parTable as $table => $genres) {
            $morceaux = [];
            foreach ($genres as $genre => $n) {
                $morceaux[] = 'table à créer' === $genre ? $genre : $n.' '.$genre;
            }
            $lignes[] = $table.' : '.implode(', ', $morceaux);
        }

        return $lignes;
    }

    /**
     * @param array<string, int> $comptes
     *
     * @return list<string>
     */
    private function rapport(string $base, Analyse $analyse, array $comptes, ?string $version): array
    {
        $l = [
            'Vérification avant bascule - base '.$base.' - '.(new \DateTimeImmutable())->format('d/m/Y H:i'),
            'Référence : migration de base '.Bascule::MIGRATION_DE_BASE,
            \sprintf('Tables : %d dans la base, %d attendues', $analyse->tables, $analyse->tablesAttendues),
            \sprintf('Ajouts : %d - Sans perte : %d - Conversions de données : %d - Bloquants : %d', \count($analyse->niveau(Constat::AJOUT)), \count($analyse->niveau(Constat::SANS_PERTE)), \count($analyse->conversions), \count($analyse->bloquants())),
        ];
        if (null !== $version) {
            $l[] = 'ATTENTION : '.$version;
        }
        $l[] = '';
        $l[] = '== '.self::TITRE_CONVERSIONS;
        foreach ($analyse->conversions as $c) {
            $l[] = '- '.self::conversion($c);
        }
        foreach ([Constat::BLOQUANT, Constat::SANS_PERTE, Constat::AJOUT] as $niveau) {
            $l[] = '';
            $l[] = '== '.self::TITRES[$niveau];
            foreach ($analyse->niveau($niveau) as $c) {
                $l[] = '- '.$c->ecart->cible().' : '.$c->message;
            }
        }
        if ($analyse->sansDonnees) {
            $l[] = '';
            $l[] = '== Base sans aucune ligne : bloquerait sur la vraie base si la table contient des lignes';
            foreach ($analyse->pireCas as $c) {
                $l[] = '- '.self::aRevoir($c);
            }
        }
        $l[] = '';
        $l[] = '== À signaler (lignes qui pointent vers une ligne absente, non modifiées)';
        foreach ($analyse->orphelins as $o) {
            $l[] = \sprintf('- %s (%s) -> %s : %d ligne(s)', $o['table'], implode(', ', $o['colonnes']), $o['parente'], $o['lignes']);
        }
        $l[] = '';
        $l[] = '== Lignes par table';
        foreach ($comptes as $table => $n) {
            $l[] = '- '.$table.' : '.$n;
        }

        return $l;
    }

    /** @param list<string> $lignes */
    private function ecrire(array $lignes): string
    {
        $dossier = $this->projet.'/var/bascule';
        if (!is_dir($dossier)) {
            @mkdir($dossier, 0700, true);
        }
        $fichier = $dossier.'/verification-'.(new \DateTimeImmutable())->format('Ymd-His').'.txt';
        file_put_contents($fichier, implode(\PHP_EOL, $lignes).\PHP_EOL);

        return $fichier;
    }
}
