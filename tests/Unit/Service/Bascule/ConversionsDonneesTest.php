<?php

namespace App\Tests\Unit\Service\Bascule;

use App\Service\Bascule\Conversion;
use App\Service\Bascule\ConversionsDonnees;
use App\Service\Bascule\LigneRemise;
use App\Service\Bascule\Remises;
use PHPUnit\Framework\TestCase;

final class ConversionsDonneesTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function colonne(string $type, ?int $precision = null, int $echelle = 0): array
    {
        return ['type' => $type, 'longueur' => null, 'precision' => $precision, 'echelle' => $echelle, 'nonSigne' => false, 'obligatoire' => true, 'defaut' => '0'];
    }

    /** Ligne de 1 000 exemplaires à 25 F, total enregistré avec l'ancien calcul (pourcentage). */
    private static function enPourcentage(int $id, string $p, string $prix = '25', int $quantite = 1000): LigneRemise
    {
        $brut = Remises::brut($prix, (float) $quantite, 0);

        return new LigneRemise($id, $p, Remises::totalAncien($brut, (float) $p), $prix, $quantite, modeCalcul: 'exemplaire');
    }

    /** Ligne dont le total suit le calcul en F par unité. */
    private static function parUnite(int $id, int $remise, string $prix = '25', int $quantite = 1000): LigneRemise
    {
        $brut = Remises::brut($prix, (float) $quantite, 0);

        return new LigneRemise($id, (string) $remise, Remises::totalNouveau($brut, $remise, (float) $quantite), $prix, $quantite, modeCalcul: 'exemplaire');
    }

    public function testTypeDecimalDeLAncienneVersion(): void
    {
        $lignes = [self::enPourcentage(1, '12.50'), self::enPourcentage(2, '33.33'), self::enPourcentage(3, '100.00')];
        $c = ConversionsDonnees::deciderRemise('commandes_details', self::colonne('decimal', 5, 2), $lignes, false);
        self::assertSame(Conversion::A_FAIRE, $c?->etat);
        self::assertSame(3, $c->lignes);
        self::assertStringContainsString('3 ligne(s) avec une remise', $c->message);
        self::assertStringContainsString('0 au-delà', $c->message);

        // DECIMAL(7,4) des devis, aucune ligne remisée : seul le type change.
        $c = ConversionsDonnees::deciderRemise('devis_details', self::colonne('decimal', 7, 4), [], false);
        self::assertSame(Conversion::A_FAIRE, $c?->etat);
        self::assertStringContainsString('seul le type', $c->message);
    }

    public function testPourcentageHorsBornesBloque(): void
    {
        $c = ConversionsDonnees::deciderRemise('commandes_details', self::colonne('decimal', 5, 2), [self::enPourcentage(1, '10.00'), new LigneRemise(2, '150.00', 0)], false);
        self::assertSame(Conversion::BLOQUANT, $c?->etat);
        self::assertStringContainsString('1 ligne(s) ont une valeur hors de 0 à 100', $c->message);
    }

    public function testLigneQuiNePeutPasRetomberSurSonTotalBloque(): void
    {
        // Bâche au m² sans dimensions : seules les finitions comptent, la remise ne s'exprime pas par unité.
        $brut = Remises::brut('4500', 0.0, 5000);
        $ligne = new LigneRemise(1, '10.00', Remises::totalAncien($brut, 10), '4500', 1, null, null, 1, 'metre_carre', 5000);
        $c = ConversionsDonnees::deciderRemise('commandes_details', self::colonne('decimal', 5, 2), [$ligne], false);
        self::assertSame(Conversion::BLOQUANT, $c?->etat);
        self::assertStringContainsString('1 au-delà', $c->message);
    }

    public function testLigneDejaIncoherenteSignaleeSansBloquer(): void
    {
        $changee = new LigneRemise(2, '10.00', 9500, '1000', 10); // total changé à la main dans l'ancienne version
        $c = ConversionsDonnees::deciderRemise('commandes_details', self::colonne('decimal', 5, 2), [self::enPourcentage(1, '10.00'), $changee], false);
        self::assertSame(Conversion::A_FAIRE, $c?->etat);
        self::assertStringContainsString('1 déjà différente(s) avant la bascule', $c->message);
    }

    public function testEntierEnPourcentageDUneVersionPlusAncienne(): void
    {
        $lignes = [self::enPourcentage(1, '10'), self::enPourcentage(2, '13'), self::enPourcentage(3, '33', '1500', 3)];
        $c = ConversionsDonnees::deciderRemise('commandes_details', self::colonne('integer'), $lignes, false);
        self::assertSame(Conversion::A_FAIRE, $c?->etat);
        self::assertStringContainsString('en pourcentage (ancienne version)', $c->message);
    }

    public function testEntierDejaEnFrancsParUnite(): void
    {
        $lignes = [self::parUnite(1, 3), self::parUnite(2, 7), self::parUnite(3, 400, '1500', 3)];
        self::assertNull(ConversionsDonnees::deciderRemise('commandes_details', self::colonne('integer'), $lignes, false));
        // Aucune ligne remisée : rien à décider.
        self::assertNull(ConversionsDonnees::deciderRemise('commandes_details', self::colonne('integer'), [], false));
    }

    public function testEntierAmbiguBloque(): void
    {
        // Une ligne suit le pourcentage, une autre la remise par unité : la bascule ne devine pas.
        $c = ConversionsDonnees::deciderRemise('commandes_details', self::colonne('integer'), [self::enPourcentage(1, '13'), self::parUnite(2, 13)], false);
        self::assertSame(Conversion::BLOQUANT, $c?->etat);
        self::assertStringContainsString('1 suivent le calcul en pourcentage, 1 le calcul en F par unité', $c->message);

        // 10 % sur 1 000 exemplaires à 100 F = 10 F par exemplaire : les deux calculs donnent le même total.
        $c = ConversionsDonnees::deciderRemise('commandes_details', self::colonne('integer'), [self::enPourcentage(1, '10', '100')], false);
        self::assertSame(Conversion::BLOQUANT, $c?->etat);
        self::assertStringContainsString('1 les deux', $c->message);

        // Plus de lignes qui ne suivent aucun calcul que de lignes en pourcentage.
        $c = ConversionsDonnees::deciderRemise('commandes_details', self::colonne('integer'), [self::enPourcentage(1, '13'), new LigneRemise(2, '10', 123), new LigneRemise(3, '20', 456)], false);
        self::assertSame(Conversion::BLOQUANT, $c?->etat);
    }

    public function testCopieDeLaStructureSansDonnees(): void
    {
        self::assertSame(Conversion::A_FAIRE, ConversionsDonnees::deciderRemise('commandes_details', self::colonne('decimal', 5, 2), [], true)?->etat);
        self::assertSame(Conversion::A_REVOIR, ConversionsDonnees::deciderRemise('commandes_details', self::colonne('integer'), [], true)?->etat);
        // Un DECIMAL(15,4) n'est pas un type de pourcentage connu : décidé sur la vraie base.
        self::assertSame(Conversion::A_REVOIR, ConversionsDonnees::deciderRemise('commandes_details', self::colonne('decimal', 15, 4), [], true)?->etat);
    }

    public function testLesMessagesNeDonnentQueDesNombres(): void
    {
        $c = ConversionsDonnees::deciderRemise('commandes_details', self::colonne('decimal', 5, 2), [self::enPourcentage(1, '12.50')], false);
        self::assertDoesNotMatchRegularExpression('/21 ?875|25 ?000|12[.,]5/', $c->message);
    }
}
