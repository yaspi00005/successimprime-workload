<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Service\AnalyseFichierImpression as A;
use App\Service\EspaceClient\FichiersClient;
use PHPUnit\Framework\TestCase;

final class FichiersClientTest extends TestCase
{
    public function testResolutionTropFaibleARefaire(): void
    {
        $resume = FichiersClient::resume([
            ['libelle' => 'Résolution réelle à 85 × 200 cm', 'valeur' => '30 dpi (conseillé : 72)', 'etat' => A::ERREUR],
            ['libelle' => 'Mode colorimétrique', 'valeur' => 'RVB', 'etat' => A::ATTENTION],
        ]);

        self::assertSame(FichiersClient::A_REFAIRE, $resume['etat']);
        self::assertSame('Résolution réelle à 85 × 200 cm : 30 dpi (conseillé : 72)', $resume['texte']);
    }

    public function testFondsPerdusAbsentsAttention(): void
    {
        $resume = FichiersClient::resume([
            ['libelle' => 'Format de page', 'valeur' => '14,8 × 21 cm', 'etat' => A::A_VERIFIER],
            ['libelle' => 'Fonds perdus', 'valeur' => 'absents (3 mm conseillés)', 'etat' => A::ATTENTION],
        ]);

        self::assertSame(['etat' => FichiersClient::ATTENTION, 'libelle' => 'Attention', 'texte' => 'Fonds perdus : absents (3 mm conseillés)'], $resume);
    }

    public function testCeQuiNeSeLitPasNeBloquePas(): void
    {
        $resume = FichiersClient::resume([
            ['libelle' => 'Format de page', 'valeur' => '14,8 × 21 cm', 'etat' => A::A_VERIFIER],
            ['libelle' => 'Fonds perdus', 'valeur' => '3 mm', 'etat' => A::OK],
            ['libelle' => 'Mode colorimétrique', 'valeur' => 'CMJN', 'etat' => A::OK],
        ]);
        self::assertSame(FichiersClient::OK, $resume['etat']);
        self::assertSame('Fonds perdus : 3 mm · Mode colorimétrique : CMJN', $resume['texte']);

        self::assertSame(FichiersClient::A_VERIFIER, FichiersClient::resume([['libelle' => 'Type de fichier', 'valeur' => 'Non lisible', 'etat' => A::A_VERIFIER]])['etat']);
        self::assertSame(FichiersClient::A_VERIFIER, FichiersClient::resume(null)['etat']);
    }

    public function testNomPropre(): void
    {
        self::assertSame('logo.pdf', FichiersClient::nomPropre('C:\\Users\\moi\\logo.pdf'));
        self::assertSame('passwd', FichiersClient::nomPropre('../../etc/passwd'));
        self::assertSame('fichier', FichiersClient::nomPropre("\x00"));
    }
}
