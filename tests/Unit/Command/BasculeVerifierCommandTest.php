<?php

namespace App\Tests\Unit\Command;

use App\Command\BasculeVerifierCommand;
use App\Service\Bascule\Constat;
use App\Service\Bascule\Conversion;
use App\Service\Bascule\Ecart;
use PHPUnit\Framework\TestCase;

final class BasculeVerifierCommandTest extends TestCase
{
    private static function ajout(string $genre, string $table, ?string $objet = null): Constat
    {
        return new Constat(new Ecart($genre, $table, $objet), Constat::AJOUT, '');
    }

    public function testLesAjoutsSontResumesParTable(): void
    {
        self::assertSame([
            'achats : table à créer',
            'clients : 2 colonne(s), 1 index, 1 lien(s)',
        ], BasculeVerifierCommand::resumeAjouts([
            self::ajout(Ecart::COLONNE_AJOUTEE, 'clients', 'public_id'),
            self::ajout(Ecart::TABLE_AJOUTEE, 'achats'),
            self::ajout(Ecart::COLONNE_AJOUTEE, 'clients', 'societe_id'),
            self::ajout(Ecart::INDEX_AJOUTE, 'clients', 'UNIQ_X'),
            self::ajout(Ecart::CLE_AJOUTEE, 'clients', 'FK_X'),
        ]));
    }

    public function testLePireCasDevientUneQuestion(): void
    {
        $colonne = static fn (string $type, ?int $longueur): array => ['type' => $type, 'longueur' => $longueur, 'precision' => null, 'echelle' => 0, 'nonSigne' => false, 'obligatoire' => true, 'defaut' => null];
        $modifiee = new Constat(new Ecart(Ecart::COLONNE_MODIFIEE, 'paiements', 'mode', $colonne('string', 100), $colonne('string', 30)), Constat::BLOQUANT, '');
        $lien = new Constat(new Ecart(Ecart::CLE_AJOUTEE, 'paiements', 'FK_X', [], ['colonnes' => ['commande_id'], 'parente' => 'commandes', 'colonnesParentes' => ['id']]), Constat::BLOQUANT, '');

        self::assertSame('paiements.mode : string(100) obligatoire → string(30) obligatoire, les valeurs y tiennent-elles ?', BasculeVerifierCommand::aRevoir($modifiee));
        self::assertSame('paiements.FK_X : lien vers commandes, des lignes pointent-elles vers une ligne absente ?', BasculeVerifierCommand::aRevoir($lien));
    }

    public function testUneConversionSeDecritParDesNombres(): void
    {
        $c = new Conversion(Conversion::VENDABLE, 'articles', 'vendable', Conversion::A_FAIRE, '3 article(s) non vendable(s) rendus vendables.', lignes: 3);
        self::assertSame('articles.vendable : 3 article(s) non vendable(s) rendus vendables.', BasculeVerifierCommand::conversion($c));

        // Conversion impossible : un constat bloquant comme les autres, sa question telle quelle.
        $bloquant = new Constat(new Ecart(Ecart::DONNEES, 'commandes_details', 'remise'), Constat::BLOQUANT, 'remise en nombre : pourcentage ou F par unité ?');
        self::assertSame('commandes_details.remise : remise en nombre : pourcentage ou F par unité ?', BasculeVerifierCommand::aRevoir($bloquant));
    }
}
