<?php

namespace App\Service\Sauvegarde;

use Doctrine\DBAL\Connection;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Export et import de la base en .sql.gz.
 *
 * mysqldump (ou mariadb-dump) est utilisé quand il est trouvé, y compris
 * dans les dossiers de XAMPP ; sinon, ou s'il échoue, l'export est fait en
 * PHP. Le mot de passe passe par la variable MYSQL_PWD, jamais sur la
 * ligne de commande (visible des autres utilisateurs de la machine).
 */
final class DumpBase
{
    /** Dernière ligne écrite par l'export PHP, vérifiée avant de valider. */
    public const FIN_PHP = '-- Fin de la sauvegarde Succès Imprim';

    private const DOSSIERS_OUTILS = [
        '/Applications/XAMPP/xamppfiles/bin',
        '/opt/lampp/bin',
        '/usr/local/mysql/bin',
        '/opt/homebrew/bin',
        '/usr/local/bin',
        'C:\\xampp\\mysql\\bin',
        'C:\\xamppok\\mysql\\bin',
    ];

    public function __construct(
        private readonly Connection $connexion,
        private readonly string $mysqldump = '',
    ) {
    }

    /**
     * @return array{methode: string, tables: int, octets: int, message: ?string}
     */
    public function exporter(string $cheminGz): array
    {
        $message = null;
        $outil = $this->outil(['mariadb-dump', 'mysqldump'], $this->mysqldump);
        if ($outil !== null) {
            try {
                $this->exporterAvecOutil($outil, $cheminGz);
                $this->verifierExport($cheminGz);

                return ['methode' => basename($outil), 'tables' => \count($this->tables()), 'octets' => (int) filesize($cheminGz), 'message' => null];
            } catch (\Throwable $e) {
                $message = basename($outil).' a échoué ('.mb_substr($e->getMessage(), 0, 200).'), export PHP utilisé.';
                @unlink($cheminGz);
            }
        }

        $tables = $this->exporterEnPhp($cheminGz);
        $this->verifierExport($cheminGz);

        return ['methode' => 'php', 'tables' => $tables, 'octets' => (int) filesize($cheminGz), 'message' => $message];
    }

    /**
     * Vide la base puis rejoue le fichier. Les tables absentes de la
     * sauvegarde (créées par une migration plus récente) sont supprimées
     * aussi, sinon les migrations suivantes échoueraient.
     *
     * @return int nombre d'instructions exécutées
     */
    public function importer(string $cheminGz, ?Connection $cible = null): int
    {
        $cible ??= $this->connexion;
        $flux = gzopen($cheminGz, 'rb');
        if ($flux === false) {
            throw new \RuntimeException('Sauvegarde illisible : '.$cheminGz);
        }

        $cible->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($cible->fetchAllAssociative('SHOW FULL TABLES') as $ligne) {
            $valeurs = array_values($ligne);
            $mot = $valeurs[1] === 'VIEW' ? 'VIEW' : 'TABLE';
            $cible->executeStatement('DROP '.$mot.' IF EXISTS `'.str_replace('`', '``', (string) $valeurs[0]).'`');
        }

        $n = 0;
        $tampon = '';
        $delimiteur = ';';
        try {
            while (($ligne = gzgets($flux)) !== false) {
                $propre = rtrim($ligne, "\r\n");
                if ($tampon === '') {
                    $court = ltrim($propre);
                    if ($court === '' || str_starts_with($court, '-- ') || $court === '--') {
                        continue;
                    }
                    if (preg_match('/^DELIMITER\s+(\S+)/i', $court, $m)) {
                        $delimiteur = $m[1];
                        continue;
                    }
                }
                $tampon .= $ligne;
                if (str_ends_with(rtrim($propre), $delimiteur)) {
                    $instruction = substr(rtrim($tampon), 0, -\strlen($delimiteur));
                    if (trim($instruction) !== '') {
                        $cible->executeStatement($instruction);
                        ++$n;
                    }
                    $tampon = '';
                }
            }
            if (trim($tampon) !== '') {
                throw new \RuntimeException('Fin de fichier inattendue : la sauvegarde semble incomplète.');
            }
        } finally {
            gzclose($flux);
            $cible->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $n;
    }

    /**
     * Le fichier se décompresse jusqu'au bout et se termine par la marque de
     * fin de l'outil utilisé : sinon l'export est considéré comme raté.
     */
    public function verifierExport(string $cheminGz): void
    {
        $flux = gzopen($cheminGz, 'rb');
        if ($flux === false) {
            throw new \RuntimeException('Export illisible');
        }
        $derniere = '';
        while (($ligne = gzgets($flux)) !== false) {
            if (trim($ligne) !== '') {
                $derniere = trim($ligne);
            }
        }
        gzclose($flux);
        if ($derniere !== self::FIN_PHP && !str_starts_with($derniere, '-- Dump completed')) {
            throw new \RuntimeException('Export incomplet (pas de marque de fin)');
        }
    }

    /** @return list<string> */
    public function tables(): array
    {
        return array_map(
            static fn (array $l): string => (string) array_values($l)[0],
            $this->connexion->fetchAllAssociative("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"),
        );
    }

    /** Chemin de l'outil, ou null. Le réglage explicite passe en premier. */
    public function outil(array $noms, string $impose = ''): ?string
    {
        if ($impose !== '') {
            return is_file($impose) ? $impose : null;
        }
        $chercheur = new ExecutableFinder();
        foreach ($noms as $nom) {
            $trouve = $chercheur->find($nom, null, self::DOSSIERS_OUTILS);
            if ($trouve !== null) {
                return $trouve;
            }
        }

        return null;
    }

    private function exporterAvecOutil(string $outil, string $cheminGz): void
    {
        $p = $this->connexion->getParams();
        $sql = $cheminGz.'.tmp.sql';
        $commande = [
            $outil,
            '--single-transaction', '--quick', '--routines', '--triggers',
            '--default-character-set=utf8mb4', '--no-tablespaces', '--hex-blob',
            '--result-file='.$sql,
            '--user='.($p['user'] ?? 'root'),
        ];
        if (!empty($p['unix_socket'])) {
            $commande[] = '--socket='.$p['unix_socket'];
        } else {
            $commande[] = '--host='.($p['host'] ?? '127.0.0.1');
            $commande[] = '--port='.($p['port'] ?? 3306);
        }
        $commande[] = (string) ($p['dbname'] ?? '');

        $process = new Process($commande, null, ['MYSQL_PWD' => (string) ($p['password'] ?? '')], null, 3600);
        try {
            $process->run();
            if (!$process->isSuccessful()) {
                throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'code '.$process->getExitCode());
            }
            $entree = fopen($sql, 'rb');
            $sortie = gzopen($cheminGz, 'wb6');
            if ($entree === false || $sortie === false) {
                throw new \RuntimeException('Compression impossible');
            }
            while (!feof($entree)) {
                gzwrite($sortie, (string) fread($entree, 1 << 20));
            }
            fclose($entree);
            gzclose($sortie);
        } finally {
            @unlink($sql);
        }
    }

    private function exporterEnPhp(string $cheminGz): int
    {
        $sortie = gzopen($cheminGz, 'wb6');
        if ($sortie === false) {
            throw new \RuntimeException('Impossible de créer '.$cheminGz);
        }
        $c = $this->connexion;
        gzwrite($sortie, "-- Sauvegarde Succès Imprim (export PHP) du ".date('Y-m-d H:i:s')."\n"
            ."SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

        $c->executeStatement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $c->executeStatement('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        try {
            $tables = $this->tables();
            foreach ($tables as $table) {
                $nom = '`'.str_replace('`', '``', $table).'`';
                $creation = array_values($c->fetchAssociative('SHOW CREATE TABLE '.$nom) ?: [])[1] ?? '';
                gzwrite($sortie, "DROP TABLE IF EXISTS $nom;\n".$creation.";\n");

                $cle = $this->clePrimaire($table);
                $ordre = $cle !== [] ? ' ORDER BY '.implode(', ', array_map(static fn ($k) => '`'.$k.'`', $cle)) : '';
                for ($decalage = 0; ; $decalage += 500) {
                    $lignes = $c->fetchAllNumeric('SELECT * FROM '.$nom.$ordre.' LIMIT 500 OFFSET '.$decalage);
                    if ($lignes === []) {
                        break;
                    }
                    $valeurs = array_map(fn (array $l): string => '('.implode(',', array_map($this->valeurSql(...), $l)).')', $lignes);
                    gzwrite($sortie, 'INSERT INTO '.$nom.' VALUES '.implode(',', $valeurs).";\n");
                    if (\count($lignes) < 500) {
                        break;
                    }
                }
                gzwrite($sortie, "\n");
            }
            foreach ($c->fetchAllNumeric("SHOW FULL TABLES WHERE Table_type = 'VIEW'") as $vue) {
                $nom = '`'.str_replace('`', '``', (string) $vue[0]).'`';
                $creation = $c->fetchNumeric('SHOW CREATE VIEW '.$nom)[1] ?? '';
                gzwrite($sortie, "DROP VIEW IF EXISTS $nom;\n".preg_replace('/\s+/', ' ', $creation).";\n");
            }
        } finally {
            $c->executeStatement('COMMIT');
        }
        gzwrite($sortie, "SET FOREIGN_KEY_CHECKS=1;\n".self::FIN_PHP."\n");
        gzclose($sortie);

        return \count($tables);
    }

    /** @return list<string> */
    private function clePrimaire(string $table): array
    {
        return array_column($this->connexion->fetchAllAssociative(
            "SHOW KEYS FROM `".str_replace('`', '``', $table)."` WHERE Key_name = 'PRIMARY'"
        ), 'Column_name');
    }

    /**
     * Une valeur sur une seule ligne : les retours à la ligne sont échappés
     * par quote(), et les données binaires passent en hexadécimal.
     */
    private function valeurSql(mixed $v): string
    {
        if ($v === null) {
            return 'NULL';
        }
        if (\is_int($v) || \is_float($v)) {
            return (string) $v;
        }
        $v = (string) $v;
        if (!mb_check_encoding($v, 'UTF-8')) {
            return '0x'.bin2hex($v);
        }

        return $this->connexion->quote($v);
    }
}
