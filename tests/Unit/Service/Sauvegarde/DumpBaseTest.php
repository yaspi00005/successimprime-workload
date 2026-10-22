<?php

namespace App\Tests\Unit\Service\Sauvegarde;

use App\Service\Sauvegarde\DumpBase;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

final class DumpBaseTest extends TestCase
{
    private string $fichier;

    protected function setUp(): void
    {
        $this->fichier = sys_get_temp_dir().'/si-dump-'.bin2hex(random_bytes(4)).'.sql.gz';
    }

    protected function tearDown(): void
    {
        @unlink($this->fichier);
    }

    public function testImporteInstructionParInstruction(): void
    {
        file_put_contents($this->fichier, gzencode(implode("\n", [
            '-- Sauvegarde',
            '/*!40101 SET NAMES utf8mb4 */;',
            'DROP TABLE IF EXISTS `a`;',
            'CREATE TABLE `a` (',
            '  `id` int NOT NULL,',
            "  `note` varchar(10) COMMENT 'x;'",
            ') ENGINE=InnoDB;',
            "INSERT INTO `a` VALUES (1,'ligne\\nsuite;'),(2,NULL);",
            'DELIMITER ;;',
            'CREATE TRIGGER t BEFORE INSERT ON a FOR EACH ROW BEGIN',
            '  SET NEW.id = NEW.id + 1;',
            'END ;;',
            'DELIMITER ;',
            '',
            DumpBase::FIN_PHP,
            '',
        ])));

        $executees = [];
        $connexion = $this->createStub(Connection::class);
        $connexion->method('fetchAllAssociative')->willReturn([['Tables_in_x' => 'ancienne', 'Table_type' => 'BASE TABLE']]);
        $connexion->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$executees): int {
            $executees[] = $sql;

            return 0;
        });

        $n = (new DumpBase($connexion))->importer($this->fichier);

        self::assertSame(5, $n);
        self::assertSame('SET FOREIGN_KEY_CHECKS=0', $executees[0]);
        self::assertSame('DROP TABLE IF EXISTS `ancienne`', $executees[1], 'les tables absentes de la sauvegarde sont supprimées');
        self::assertStringStartsWith('CREATE TABLE `a` (', $executees[4]);
        self::assertStringEndsWith(') ENGINE=InnoDB', $executees[4]);
        self::assertSame("INSERT INTO `a` VALUES (1,'ligne\\nsuite;'),(2,NULL)", $executees[5]);
        self::assertStringContainsString('SET NEW.id = NEW.id + 1;', $executees[6]);
        self::assertStringEndsWith('END ', $executees[6]);
        self::assertSame('SET FOREIGN_KEY_CHECKS=1', end($executees));
    }

    public function testRefuseUnExportSansMarqueDeFin(): void
    {
        $dump = new DumpBase($this->createStub(Connection::class));
        file_put_contents($this->fichier, gzencode("CREATE TABLE a (id int);\n-- Dump completed on 2026-10-12 18:06:57\n"));
        $dump->verifierExport($this->fichier);

        file_put_contents($this->fichier, gzencode("CREATE TABLE a (id int);\nINSERT INTO a VALUES (1);\n"));
        $this->expectException(\RuntimeException::class);
        $dump->verifierExport($this->fichier);
    }
}
