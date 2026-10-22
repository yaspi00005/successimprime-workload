<?php

namespace App\Tests\Unit\Service\Societes;

use DoctrineMigrations\Version20261014160000;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../../migrations/Version20261014160000.php';

/**
 * Sociétés créées par la migration à partir des entreprises émettrices
 * (docs/societes.md, étape 1) : une par NIF, à défaut par raison sociale.
 */
final class RegroupementSocietesTest extends TestCase
{
    /** Les trois émetteurs actuels : MDG Success et MDG partagent le NIF de Madial Group, DREPA a le sien. */
    public function testLesEmetteursDuMemeNifFontUneSociete(): void
    {
        $societes = Version20261014160000::societes([
            self::entreprise('mdg_success', 'Madial Group SARL', '081148957D', 'MA.BKO.2026.B.1404'),
            self::entreprise('mdg', 'Madial Group SARL', '081148957D', 'MA.BKO.2026.B.1404'),
            self::entreprise('drepa', 'DREPA TECHNOLOGIE', '081137682V', 'MA.BKO.2021.A.911'),
        ]);

        self::assertCount(2, $societes);
        self::assertSame(['code' => 'IMP', 'raison_sociale' => 'Madial Group SARL', 'rccm' => 'MA.BKO.2026.B.1404', 'nif' => '081148957D', 'adresse' => 'Bamako, Sotuba ACI', 'emetteurs' => ['mdg_success', 'mdg']], $societes[0]);
        self::assertSame('DREPA', $societes[1]['code']);
        self::assertSame('DREPA TECHNOLOGIE', $societes[1]['raison_sociale']);
        self::assertSame(['drepa'], $societes[1]['emetteurs']);
    }

    /** Le NIF se compare sans espaces ni casse ; sans NIF, la raison sociale décide. */
    public function testNifNormaliseEtRaisonSocialeADefaut(): void
    {
        $societes = Version20261014160000::societes([
            self::entreprise('si', 'Madial Group SARL', '081148957d', null),
            self::entreprise('mdg', 'MADIAL GROUP SARL', ' 081148957D ', 'MA.BKO.2026.B.1404'),
            self::entreprise('boutique', 'Boutique  Consommables', null, null),
            self::entreprise('boutique_bis', 'boutique consommables', '', null),
        ]);

        self::assertCount(2, $societes);
        self::assertSame(['si', 'mdg'], $societes[0]['emetteurs']);
        self::assertSame('081148957d', $societes[0]['nif'], 'le NIF du premier émetteur');
        self::assertSame('MA.BKO.2026.B.1404', $societes[0]['rccm'], 'le premier RCCM renseigné du groupe');
        self::assertSame(['boutique', 'boutique_bis'], $societes[1]['emetteurs']);
        self::assertSame('BOUTIQUE', $societes[1]['code']);
        self::assertNull($societes[1]['nif']);
    }

    /** Base sans entreprise (rien n'a été saisi) : la société principale seule, avec les valeurs d'origine. */
    public function testSansEntrepriseLaSocietePrincipaleSeule(): void
    {
        $societes = Version20261014160000::societes([]);

        self::assertCount(1, $societes);
        self::assertSame('IMP', $societes[0]['code']);
        self::assertSame('Madial Group SARL', $societes[0]['raison_sociale']);
        self::assertSame('081148957D', $societes[0]['nif']);
        self::assertSame('MA.BKO.2026.B.1404', $societes[0]['rccm']);
        self::assertSame([], $societes[0]['emetteurs']);
    }

    /** Deux codes identiques sont départagés, le code reste court. */
    public function testCodesUniquesEtCourts(): void
    {
        $societes = Version20261014160000::societes([
            self::entreprise('mdg_success', 'Madial Group SARL', '1', null),
            self::entreprise('imp', 'Imprimerie du Fleuve', '2', null),
            self::entreprise('consommables_bamako', 'Consommables SA', '3', null),
            self::entreprise('consommables_bamako_2', 'Consommables Kati SA', '4', null),
        ]);

        self::assertSame(['IMP', 'IMP2', 'CONSOMMA', 'CONSOMMA2'], array_column($societes, 'code'));
    }

    /**
     * @return array{code: string, raison_sociale: string, nif: ?string, rccm: ?string, adresse: ?string}
     */
    private static function entreprise(string $code, string $raison, ?string $nif, ?string $rccm): array
    {
        return ['code' => $code, 'raison_sociale' => $raison, 'nif' => $nif, 'rccm' => $rccm, 'adresse' => 'Bamako, Sotuba ACI'];
    }
}
