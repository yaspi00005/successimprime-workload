<?php

namespace App\Tests\Unit\Command;

use App\Command\DemoCommand;
use PHPUnit\Framework\TestCase;

final class DemoCommandTest extends TestCase
{
    public function testDecoupeLeFichierEnInstructions(): void
    {
        $sql = "-- commentaire\nSET NAMES utf8mb4;\nSTART TRANSACTION;\n\nINSERT INTO t (a) VALUES ('x;y');\nSET @c = LAST_INSERT_ID();\nCOMMIT;\n";

        self::assertSame(["SET NAMES utf8mb4", "INSERT INTO t (a) VALUES ('x;y')", 'SET @c = LAST_INSERT_ID()'], DemoCommand::instructions($sql));
    }

    public function testLeFichierLivreEstLisible(): void
    {
        $liste = DemoCommand::instructions((string) file_get_contents(__DIR__.'/../../../donnees-demo/demo.sql'));
        $tables = array_unique(array_map(static fn (string $s): string => preg_match('/^INSERT (?:IGNORE )?INTO `([a-z_]+)`/', $s, $m) ? $m[1] : '', $liste));

        self::assertGreaterThan(85, \count(array_filter($tables)));
        self::assertSame('SET FOREIGN_KEY_CHECKS = 1', end($liste));
        self::assertCount(1, array_filter($liste, static fn (string $s): bool => str_starts_with($s, 'INSERT INTO `clients`')));
        self::assertSame(200, substr_count((string) current(array_filter($liste, static fn (string $s): bool => str_starts_with($s, 'INSERT INTO `clients`'))), "\n"));
    }
}
