<?php

namespace App\Service\Bascule;

use App\Service\Sauvegarde\Sauvegardes;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connection\StaticServerVersionProvider;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Comparator;
use Doctrine\DBAL\Schema\ComparatorConfig;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\SchemaDiff;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Bascule de la base de la boutique (ancienne version de l'application)
 * vers la migration de base, sans rien perdre (docs/migration-de-base.md).
 *
 * 1. Le schéma de référence est construit en jouant la seule migration de
 *    base dans une base provisoire, aussitôt supprimée.
 * 2. Doctrine compare la base de la boutique à cette référence ; chaque
 *    écart est classé d'après les données (Classement).
 * 3. La structure dit aussi quelles anciennes mises à jour de DONNÉES
 *    manquent (remises en pourcentage, articles non vendables… :
 *    ConversionsDonnees) ; elles sont rejouées pendant la correction.
 * 4. Sans écart bloquant, la correction fait une sauvegarde, convertit les
 *    données, applique les écarts, revérifie que la base est identique à
 *    la référence, que chaque table a gardé ses lignes et que chaque ligne
 *    convertie retombe sur son total enregistré, marque celles qui gardent
 *    leur total d'origine (total_fige), puis remplace l'historique des
 *    migrations par la migration de base. Les migrations suivantes se
 *    lancent ensuite normalement (doctrine:migrations:migrate).
 */
final class Bascule
{
    public const MIGRATION_DE_BASE = 'DoctrineMigrations\Version20261010202721';

    /** Tables dont les comptes sont montrés avant et après (nombres seulement). */
    public const TABLES_PRINCIPALES = ['clients', 'commandes', 'devis', 'factures', 'paiements', 'articles'];

    public const A_BASCULER = 'a_basculer';
    public const BASCULEE = 'basculee';
    public const VIDE = 'vide';

    /** Table de Doctrine Migrations : son contenu est remplacé, sa forme gérée par Doctrine. */
    private const HISTORIQUE = 'doctrine_migration_versions';

    /** Mode strict : une valeur qui ne tiendrait pas fait échouer l'instruction au lieu d'être coupée. */
    private const MODE_STRICT = "SET SESSION sql_mode = 'STRICT_ALL_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'";

    public function __construct(
        private readonly Connection $connexion,
        #[Autowire(service: 'doctrine.migrations.dependency_factory')] private readonly DependencyFactory $migrations,
        private readonly Sauvegardes $sauvegardes,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    public function nomBase(): string
    {
        return (string) $this->connexion->getDatabase();
    }

    /**
     * a_basculer, basculee (la migration de base est notée dans l'historique :
     * elle n'y entre que par la bascule ou en créant une base neuve) ou vide.
     */
    public function etat(): string
    {
        $tables = $this->tables();
        if (\in_array(self::HISTORIQUE, $tables, true)
            && false !== $this->connexion->fetchOne('SELECT 1 FROM '.self::HISTORIQUE.' WHERE version = ?', [self::MIGRATION_DE_BASE])) {
            return self::BASCULEE;
        }

        return [] === array_diff($tables, [self::HISTORIQUE]) ? self::VIDE : self::A_BASCULER;
    }

    /**
     * La version du serveur notée dans DATABASE_URL (serverVersion) doit
     * correspondre au vrai serveur : sinon Doctrine écrirait des
     * instructions que le serveur ne comprend pas (XAMPP a MariaDB 10.4).
     */
    public function controleVersion(): ?string
    {
        $reelle = $this->connexion->getServerVersion();
        $plateforme = $this->connexion->getDatabasePlatform();
        if (!$plateforme instanceof AbstractMySQLPlatform) {
            return 'La base n\'est pas MariaDB ou MySQL.';
        }
        $driver = $this->connexion->getDriver();
        if (!$driver instanceof \Doctrine\DBAL\Driver\AbstractMySQLDriver) {
            return null;
        }
        $attendue = $driver->getDatabasePlatform(new StaticServerVersionProvider($reelle));
        if ($attendue::class === $plateforme::class) {
            return null;
        }

        return \sprintf('Le serveur est « %s », mais DATABASE_URL (.env.local) indique une autre version : mettre serverVersion=%s dans DATABASE_URL avant de continuer.', $reelle, self::versionPourUrl($reelle));
    }

    /** « 10.4.28-MariaDB » depuis « 10.4.28-MariaDB-log » ou « 5.5.5-10.4.28-MariaDB ». */
    public static function versionPourUrl(string $reelle): string
    {
        if (preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $reelle, $m)) {
            return $m[1].'-MariaDB';
        }

        return preg_match('/^(\d+\.\d+\.\d+)/', $reelle, $m) ? $m[1] : $reelle;
    }

    /**
     * @param list<Conversion> $faites conversions déjà rejouées pendant la correction en cours
     */
    public function analyser(?Schema $reference = null, bool $garderOrphelins = false, array $faites = []): Analyse
    {
        $reference ??= $this->schemaReference();
        $boutique = $this->schemaBoutique();
        $ecarts = LectureDiff::ecarts($this->comparer($boutique, $reference));
        $sansDonnees = 0 === array_sum($this->compterLignes());

        // Anciennes mises à jour de données qui manquent : la colonne remise convertie
        // d'abord change ensuite de type sans être classée comme un simple changement.
        $conversions = (new ConversionsDonnees($this->connexion))->detecter($boutique, $sansDonnees, array_map(static fn (Conversion $c): string => $c->cible(), $faites));
        $converties = [];
        foreach ($faites as $c) {
            if (Conversion::REMISE === $c->code) {
                $converties[$c->cible()] = 'valeurs déjà converties juste avant, en francs entiers par unité.';
            }
        }
        foreach ($conversions as $c) {
            if (Conversion::REMISE === $c->code && Conversion::A_REVOIR !== $c->etat) {
                $converties[$c->cible()] = 'le pourcentage est d\'abord converti en F par unité (voir « Conversions de données »).';
            }
        }
        $constats = Classement::classer($ecarts, new SondeBase($this->connexion), $garderOrphelins, $converties);
        $aRevoir = [];
        foreach ($conversions as $c) {
            $constat = new Constat(new Ecart(Ecart::DONNEES, $c->table, $c->colonne), Constat::BLOQUANT, $c->message);
            if (Conversion::BLOQUANT === $c->etat) {
                $constats[] = $constat;
            } elseif (Conversion::A_REVOIR === $c->etat) {
                $aRevoir[] = $constat;
            } elseif ($sansDonnees && Conversion::REMISE === $c->code) {
                $aRevoir[] = new Constat($constat->ecart, Constat::BLOQUANT, 'remise en pourcentage : les valeurs sont-elles toutes entre 0 et 100, et chaque ligne retombe-t-elle sur son total enregistré après conversion (à 1 F près par unité) ?');
            }
        }

        // Copie de la structure sans aucune ligne : ce qui bloquerait si les tables étaient remplies.
        $pireCas = $sansDonnees
            ? [...array_values(array_filter(Classement::classer($ecarts, new SondePessimiste(), $garderOrphelins, $converties), static fn (Constat $c): bool => $c->estBloquant())), ...$aRevoir]
            : [];

        return new Analyse(
            $constats, $this->orphelinsExistants($boutique), \count($boutique->getTables()), \count($reference->getTables()), $pireCas, $sansDonnees,
            array_values(array_filter($conversions, static fn (Conversion $c): bool => $c->aFaire())),
        );
    }

    /**
     * Joue la seule migration de base dans une base provisoire du même
     * serveur, lit son schéma, puis supprime la base provisoire.
     */
    public function schemaReference(): Schema
    {
        $params = array_diff_key($this->connexion->getParams(), ['url' => 1]);
        $base = $this->nomBase();
        $provisoire = substr($base, 0, 48).'_ref_bascule';
        $serveur = DriverManager::getConnection(array_diff_key($params, ['dbname' => 1]));
        try {
            $jeu = $serveur->fetchAssociative('SELECT DEFAULT_CHARACTER_SET_NAME AS jeu, DEFAULT_COLLATION_NAME AS inter FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$base])
                ?: ['jeu' => 'utf8mb4', 'inter' => 'utf8mb4_unicode_ci'];
            $this->libererProvisoire($serveur, $provisoire);
            // Même jeu de caractères que la base de la boutique : les tables créées sans précision le prennent.
            $serveur->executeStatement(\sprintf('CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s', $provisoire, $jeu['jeu'], $jeu['inter']));
            try {
                $ref = DriverManager::getConnection(['dbname' => $provisoire] + $params);
                require_once $this->projet.'/migrations/Version20261010202721.php';
                $classe = self::MIGRATION_DE_BASE;
                $migration = new $classe($ref, new NullLogger());
                $migration->up(new Schema());
                foreach ($migration->getSql() as $requete) {
                    $ref->executeStatement($requete->getStatement());
                }
                $schema = $ref->createSchemaManager()->introspectSchema();
                $ref->close();
            } finally {
                $serveur->executeStatement(\sprintf('DROP DATABASE IF EXISTS `%s`', $provisoire));
            }
        } finally {
            $serveur->close();
        }
        if ($schema->hasTable(self::HISTORIQUE)) {
            $schema->dropTable(self::HISTORIQUE);
        }

        return $schema;
    }

    public function schemaBoutique(): Schema
    {
        $schema = $this->connexion->createSchemaManager()->introspectSchema();
        if ($schema->hasTable(self::HISTORIQUE)) {
            $schema->dropTable(self::HISTORIQUE);
        }

        return $schema;
    }

    public function comparer(Schema $boutique, Schema $reference): SchemaDiff
    {
        return $this->comparateur()->compareSchemas($boutique, $reference);
    }

    /** @return array<string, int> nombre de lignes de chaque table */
    public function compterLignes(): array
    {
        $comptes = [];
        foreach ($this->tables() as $table) {
            if (self::HISTORIQUE !== $table) {
                $comptes[$table] = (int) $this->connexion->fetchOne('SELECT COUNT(*) FROM `'.str_replace('`', '``', $table).'`');
            }
        }

        return $comptes;
    }

    /**
     * Applique les écarts après une sauvegarde, vérifie, puis note la
     * migration de base comme faite. $note reçoit chaque étape (texte).
     * En cas d'arrêt, BasculeInterrompue dit quelle sauvegarde remettre
     * (aucune si rien n'a été modifié).
     *
     * @param callable(string): void $note
     *
     * @return array{sauvegarde: string, avant: array<string, int>, apres: array<string, int>, instructions: int, remises: array<string, string>}
     *
     * @throws BasculeInterrompue
     */
    public function corriger(Schema $reference, callable $note, bool $garderOrphelins = false): array
    {
        try {
            $analyse = $this->analyser($reference, $garderOrphelins);
            if ([] !== $analyse->bloquants()) {
                throw new BasculeInterrompue('Des écarts bloquants restent.');
            }
            $sauvegarde = $this->sauvegardes->lancer(false, 'avant-bascule');
        } catch (BasculeInterrompue $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BasculeInterrompue('Sauvegarde ou vérification impossible : '.$e->getMessage(), null, $e);
        }
        $note(\sprintf('Sauvegarde de la base faite : %s (%s).', $sauvegarde['nom'], Sauvegardes::taille($sauvegarde['base']['octets'])));

        try {
            return ['sauvegarde' => (string) $sauvegarde['nom']] + $this->appliquer($reference, $analyse, $note, $garderOrphelins);
        } catch (\Throwable $e) {
            throw new BasculeInterrompue($e->getMessage(), (string) $sauvegarde['nom'], $e);
        }
    }

    /**
     * @param callable(string): void $note
     *
     * @return array{avant: array<string, int>, apres: array<string, int>, instructions: int, remises: array<string, string>}
     */
    private function appliquer(Schema $reference, Analyse $analyse, callable $note, bool $garderOrphelins): array
    {
        $avant = $this->compterLignes();

        $this->connexion->executeStatement(self::MODE_STRICT);
        $plateforme = $this->connexion->getDatabasePlatform();
        $instructions = 0;

        // 0. Conversions de données qui lisent l'ancien sens d'une colonne (remise en
        //    pourcentage…), avant tout changement de type. Les totaux enregistrés ne changent pas.
        $donnees = new ConversionsDonnees($this->connexion);
        $aRejouer = $analyse->conversions;
        $suiviRemises = $donnees->appliquer($aRejouer, Conversion::AVANT, $this->schemaBoutique(), $note);

        // 1. Valeurs choisies pour les lignes existantes : colonne obligatoire ajoutée
        //    (d'abord facultative, remplie, puis rendue obligatoire à l'étape 2) et
        //    identifiants publics jamais tirés.
        $boutique = $this->schemaBoutique();
        foreach ($analyse->constats as $constat) {
            if (null === $constat->reprise) {
                continue;
            }
            $nomTable = $constat->ecart->table;
            $nomColonne = Ecart::INDEX_AJOUTE === $constat->ecart->genre ? 'public_id' : (string) $constat->ecart->objet;
            if (Ecart::COLONNE_AJOUTEE === $constat->ecart->genre) {
                $table = $boutique->getTable($nomTable);
                $colonne = $reference->getTable($nomTable)->getColumn($nomColonne)->edit()->setNotNull(false)->setDefaultValue(null)->create();
                foreach ($plateforme->getAlterTableSQL($this->comparateur()->compareTables($table, $table->edit()->addColumn($colonne)->create())) as $sql) {
                    $this->connexion->executeStatement($sql);
                    ++$instructions;
                }
            }
            $cible = '`'.str_replace('`', '``', $nomColonne).'`';
            $this->connexion->executeStatement(\sprintf(
                'UPDATE `%s` SET %s = %s WHERE %s',
                str_replace('`', '``', $nomTable), $cible, $constat->reprise,
                Constat::IDENTIFIANT_VIDE === $constat->repriseLignes ? SondeBase::conditionIdentifiantVide($cible) : $cible.' IS NULL',
            ));
            ++$instructions;
            $note(\sprintf('%s : %s', $constat->ecart->cible(), $constat->message));
        }

        // 2. Tout le reste, dans l'ordre choisi par Doctrine, après une nouvelle lecture.
        $analyse = $this->analyser($reference, $garderOrphelins, $aRejouer);
        if ([] !== $analyse->bloquants()) {
            throw new \RuntimeException('Écart bloquant apparu pendant la correction : '.$analyse->bloquants()[0]->ecart->cible());
        }
        // Liens gardés avec leurs lignes orphelines (--garder-orphelins) : créés sans contrôle.
        $sansControle = [];
        foreach ($analyse->constats as $constat) {
            if ($constat->lienNonVerifie) {
                $sansControle[] = (string) $constat->ecart->objet;
            }
        }
        $parTable = [];
        // Index retenu par une clé étrangère (erreur 1553) : retiré à la fin, quand le
        // nouvel index ou la nouvelle clé est en place. Clé ou index déjà retiré (1091,
        // instruction en double de Doctrine) : rien à faire. Le contrôle de l'étape 3
        // vérifie de toute façon que la base finit identique à la référence.
        $aLaFin = [];
        foreach (array_values(array_unique(self::creerAvantDeRetirer($plateforme->getAlterSchemaSQL($this->comparer($this->schemaBoutique(), $reference))))) as $sql) {
            $liberer = [] !== $sansControle && preg_match('/ADD CONSTRAINT `?('.implode('|', array_map('preg_quote', $sansControle)).')`? FOREIGN KEY/i', $sql);
            if ($liberer) {
                $this->connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
            }
            try {
                $this->connexion->executeStatement($sql);
            } catch (DriverException $e) {
                $retrait = (bool) preg_match('/^(?:DROP INDEX|ALTER TABLE \S+ DROP (?:FOREIGN KEY|INDEX))\s/i', $sql);
                if ($retrait && 1553 === $e->getCode()) {
                    $aLaFin[] = $sql;
                    continue;
                }
                if (!$retrait || 1091 !== $e->getCode()) {
                    throw $e;
                }
                continue;
            } finally {
                if ($liberer) {
                    $this->connexion->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                }
            }
            ++$instructions;
            if (preg_match('/^(?:CREATE TABLE|ALTER TABLE|CREATE (?:UNIQUE )?INDEX \S+ ON|DROP TABLE)\s+`?([A-Za-z0-9_]+)/i', $sql, $m)) {
                $parTable[$m[1]] = ($parTable[$m[1]] ?? 0) + 1;
            }
        }
        foreach ($aLaFin as $sql) {
            $this->connexion->executeStatement($sql);
            ++$instructions;
        }
        // Avant MariaDB 10.5.2, Doctrine retire puis remet les clés étrangères qui
        // s'appuient sur un index renommé, y compris une clé qu'il retire par ailleurs :
        // une deuxième lecture retire ce qui reste en trop.
        foreach ($plateforme->getAlterSchemaSQL($this->comparer($this->schemaBoutique(), $reference)) as $sql) {
            $this->connexion->executeStatement($sql);
            ++$instructions;
        }
        $note(\sprintf('%d instruction(s) appliquée(s) sur %d table(s).', $instructions, \count($parTable)));

        // 2 bis. Conversions de données qui remplissent une colonne ajoutée à l'étape 2.
        $donnees->appliquer($aRejouer, Conversion::APRES, $this->schemaBoutique(), $note);

        // 3. Contrôles : identique à la référence, aucune ligne perdue, remises converties
        //    qui redonnent le total enregistré de chaque ligne avec le nouveau calcul.
        $reste = LectureDiff::ecarts($this->comparer($this->schemaBoutique(), $reference));
        if ([] !== $reste) {
            throw new \RuntimeException(\sprintf('La base n\'est pas encore identique à la référence (%d écart(s), par exemple %s).', \count($reste), $reste[0]->cible()));
        }
        $apres = $this->compterLignes();
        foreach ($avant as $table => $n) {
            if (($apres[$table] ?? 0) !== $n) {
                throw new \RuntimeException(\sprintf('La table %s avait %d ligne(s), elle en a %d.', $table, $n, $apres[$table] ?? 0));
            }
        }
        $note('Base identique au schéma de la migration de base ; chaque table a gardé ses lignes.');
        $remises = [];
        foreach ($donnees->controlerRemises($suiviRemises, $this->schemaBoutique()) as $table => $bilan) {
            $remises[$table] = \sprintf('%d ligne(s) recalculée(s) avec le nouveau calcul : %s', $bilan->lignes(), $bilan->texte());
            $note(\sprintf('Contrôle %s.remise : %s.', $table, $remises[$table]));
        }

        // 4. « Garder le total » : les lignes converties qui ne retombent pas exactement sur
        //    leur total enregistré le gardent tant que rien de ce qui le fait ne change.
        foreach ($donnees->figerTotaux($suiviRemises, $this->schemaBoutique()) as $table => $n) {
            $note(\sprintf('%s : %d ligne(s) gardent leur total d\'origine tant qu\'elles ne sont pas modifiées.', $table, $n));
        }

        $this->marquerMigrationDeBase();
        $note('Historique des migrations remplacé par la migration de base.');

        return ['avant' => $avant, 'apres' => $apres, 'instructions' => $instructions, 'remises' => $remises];
    }

    /**
     * Avant MariaDB 10.5.2 (XAMPP 8.2 : MariaDB 10.4), Doctrine renomme un index en
     * « DROP INDEX ancien ON t » suivi de « CREATE INDEX nouveau ON t ». MariaDB refuse
     * de retirer l'ancien s'il sert à une clé étrangère (erreur 1553) : le nouvel index
     * est donc créé d'abord, puis l'ancien retiré.
     *
     * @param list<string> $instructions
     *
     * @return list<string>
     */
    public static function creerAvantDeRetirer(array $instructions): array
    {
        $resultat = [];
        $n = \count($instructions);
        for ($i = 0; $i < $n; ++$i) {
            $sql = $instructions[$i];
            $suivante = $instructions[$i + 1] ?? null;
            if (
                null !== $suivante
                && preg_match('/^DROP INDEX \S+ ON (\S+)$/i', $sql, $retrait)
                && preg_match('/^CREATE (?:UNIQUE )?INDEX \S+ ON (\S+) /i', $suivante, $creation)
                && strtolower(trim($retrait[1], '`')) === strtolower(trim($creation[1], '`'))
            ) {
                $resultat[] = $suivante;
                $resultat[] = $sql;
                ++$i;
                continue;
            }
            $resultat[] = $sql;
        }

        return $resultat;
    }

    /** Vide l'historique des anciennes migrations et note la migration de base comme faite. */
    public function marquerMigrationDeBase(): void
    {
        $stockage = $this->migrations->getMetadataStorage();
        $stockage->ensureInitialized();
        $stockage->reset();
        $stockage->complete(new ExecutionResult(new Version(self::MIGRATION_DE_BASE), Direction::UP, new \DateTimeImmutable()));
    }

    /**
     * Lignes qui pointent déjà vers une ligne absente (liens existants).
     * Signalées seulement : la bascule ne les touche pas.
     *
     * @return list<array{table: string, colonnes: list<string>, parente: string, lignes: int}>
     */
    private function orphelinsExistants(Schema $boutique): array
    {
        $sonde = new SondeBase($this->connexion);
        $liste = [];
        foreach ($boutique->getTables() as $table) {
            foreach ($table->getForeignKeys() as $fk) {
                $cle = LectureDiff::cle($fk);
                if (!$boutique->hasTable($cle['parente'])) {
                    continue;
                }
                $n = $sonde->orphelins(LectureDiff::nomTable($table), $cle['colonnes'], $cle['parente'], $cle['colonnesParentes']);
                if ($n > 0) {
                    $liste[] = ['table' => LectureDiff::nomTable($table), 'colonnes' => $cle['colonnes'], 'parente' => $cle['parente'], 'lignes' => $n];
                }
            }
        }

        return $liste;
    }

    /** @return list<string> */
    private function tables(): array
    {
        return array_map('strval', $this->connexion->fetchFirstColumn("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"));
    }

    /**
     * Pas de détection des colonnes renommées : une colonne en trop et une
     * colonne manquante restent deux écarts, jamais un renommage deviné.
     * Un index modifié est vu comme retiré puis ajouté.
     */
    public static function configurationComparaison(): ComparatorConfig
    {
        return new ComparatorConfig(false, true, false);
    }

    private function comparateur(): Comparator
    {
        return $this->connexion->createSchemaManager()->createComparator(self::configurationComparaison());
    }

    /**
     * Une base provisoire restée d'un essai interrompu est supprimée, mais
     * seulement si elle ne contient que des tables de la migration de base.
     */
    private function libererProvisoire(Connection $serveur, string $provisoire): void
    {
        $existe = $serveur->fetchOne('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$provisoire]);
        if (false === $existe) {
            return;
        }
        $tables = $serveur->fetchFirstColumn('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?', [$provisoire]);
        $migration = (string) file_get_contents($this->projet.'/migrations/Version20261010202721.php');
        foreach ($tables as $table) {
            if (!str_contains($migration, 'CREATE TABLE '.$table.' (')) {
                throw new \RuntimeException(\sprintf('La base %s existe déjà et contient d\'autres tables : supprimez-la ou renommez-la avant de recommencer.', $provisoire));
            }
        }
        $serveur->executeStatement(\sprintf('DROP DATABASE `%s`', $provisoire));
    }
}
