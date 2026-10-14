<?php

namespace App\Tests\Unit\Service\Site;

use App\Service\Site\ChiffresSite;
use PHPUnit\Framework\TestCase;

final class ChiffresSiteTest extends TestCase
{
    public function testProjetsUneFoisParClientEtParMois(): void
    {
        $livrees = [
            // Trois bâches pour un même événement : un projet.
            ['client' => 7, 'date' => new \DateTimeImmutable('2026-09-02')],
            ['client' => 7, 'date' => new \DateTimeImmutable('2026-09-03')],
            ['client' => 7, 'date' => '2026-09-28 17:00:00'],
            ['client' => 7, 'date' => new \DateTimeImmutable('2026-10-01')],
            ['client' => 9, 'date' => new \DateTimeImmutable('2026-09-02')],
            // Sans client (comptoir) : chaque commande compte.
            ['client' => null, 'date' => new \DateTimeImmutable('2026-09-02')],
            ['client' => null, 'date' => new \DateTimeImmutable('2026-09-02')],
        ];
        self::assertSame(5, ChiffresSite::compterProjets($livrees, ChiffresSite::REGLE_CLIENT_MOIS));
        self::assertSame(7, ChiffresSite::compterProjets($livrees, ChiffresSite::REGLE_COMMANDE));
        self::assertSame(0, ChiffresSite::compterProjets([], ChiffresSite::REGLE_CLIENT_MOIS));
    }

    public function testSeulsLesChiffresAuDessusDuSeuilSontMontres(): void
    {
        $chiffres = ['livrees' => 120, 'clients' => 0, 'projets' => 45];
        self::assertSame([
            ['cle' => 'livrees', 'valeur' => 120, 'libelle' => 'commandes livrées'],
            ['cle' => 'projets', 'valeur' => 45, 'libelle' => 'projets réalisés'],
        ], ChiffresSite::visibles($chiffres, 1), 'zéro n\'est jamais montré');
        self::assertSame(['livrees'], array_column(ChiffresSite::visibles($chiffres, 50), 'cle'));
        self::assertSame([], ChiffresSite::visibles([], 1));
    }

    public function testReglagesRamenesDansLesBornes(): void
    {
        self::assertSame(ChiffresSite::DEFAUTS, ChiffresSite::normaliser([]));
        self::assertSame(
            ['afficher' => false, 'projets' => ChiffresSite::REGLE_CLIENT_MOIS, 'seuil' => 1],
            ChiffresSite::normaliser(['afficher' => '0', 'projets' => 'montant', 'seuil' => -4]),
        );
        self::assertSame(
            ['afficher' => true, 'projets' => ChiffresSite::REGLE_COMMANDE, 'seuil' => 1_000_000],
            ChiffresSite::normaliser(['afficher' => '1', 'projets' => 'commande', 'seuil' => '99999999']),
        );
    }

    public function testDesVolumesJamaisDeMontant(): void
    {
        foreach (ChiffresSite::LIBELLES as $cle => $libelle) {
            self::assertDoesNotMatchRegularExpression('/montant|chiffre d.affaires|fcfa|marge/i', $cle.' '.$libelle);
        }
    }
}
