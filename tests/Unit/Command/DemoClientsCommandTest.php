<?php

namespace App\Tests\Unit\Command;

use App\Command\DemoClientsCommand;
use PHPUnit\Framework\TestCase;

final class DemoClientsCommandTest extends TestCase
{
    public function testDecoupeLeFichierEnInstructions(): void
    {
        $sql = "-- commentaire\nSET NAMES utf8mb4;\nSTART TRANSACTION;\n\nINSERT INTO t (a) VALUES ('x;y');\nSET @c = LAST_INSERT_ID();\nCOMMIT;\n";

        self::assertSame(["SET NAMES utf8mb4", "INSERT INTO t (a) VALUES ('x;y')", 'SET @c = LAST_INSERT_ID()'], DemoClientsCommand::instructions($sql));
    }

    public function testLeFichierLivreEstLisible(): void
    {
        $liste = DemoClientsCommand::instructions((string) file_get_contents(__DIR__.'/../../../donnees-demo/clients-demo.sql'));

        self::assertGreaterThan(1500, \count($liste));
        self::assertCount(200, array_filter($liste, fn (string $s) => str_starts_with($s, 'INSERT INTO clients ')));
    }
}
