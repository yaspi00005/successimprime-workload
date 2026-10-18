<?php

namespace App\Tests\Unit\Service\Bascule;

use App\Service\Bascule\Bascule;
use PHPUnit\Framework\TestCase;

/** MariaDB < 10.5.2 : un renommage d'index devient DROP + CREATE, à inverser quand une clé étrangère s'appuie sur l'index (erreur 1553). */
final class RenommageIndexTest extends TestCase
{
    public function testLaCreationPasseAvantLeRetraitSurLaMemeTable(): void
    {
        $sql = [
            'ALTER TABLE journal_activite DROP FOREIGN KEY FK_1',
            'DROP INDEX IDX_journal_activite_utilisateur ON journal_activite',
            'CREATE INDEX IDX_8F2B1A ON journal_activite (utilisateur_id)',
            'ALTER TABLE journal_activite ADD CONSTRAINT FK_1 FOREIGN KEY (utilisateur_id) REFERENCES user (id)',
        ];

        self::assertSame([$sql[0], $sql[2], $sql[1], $sql[3]], Bascule::creerAvantDeRetirer($sql));
    }

    public function testDeuxTablesDifferentesNeSontPasInversees(): void
    {
        $sql = ['DROP INDEX IDX_A ON clients', 'CREATE UNIQUE INDEX UNIQ_B ON factures (numero)'];

        self::assertSame($sql, Bascule::creerAvantDeRetirer($sql));
    }
}
