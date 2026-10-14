<?php

namespace App\Tests\Unit\Service\Promotions;

use App\Entity\Clients;
use App\Entity\CommandesDetails;
use App\Entity\DemandeExceptionPromotion;
use App\Entity\Promotion;
use App\Entity\User;
use App\Service\Promotions\SuiviPromotions;
use PHPUnit\Framework\TestCase;

final class SuiviPromotionsTest extends TestCase
{
    public function testCoutRapporteAuCaDeLaPeriodeAvecAlerteAuDelaDe5Pourcent(): void
    {
        $i = SuiviPromotions::indicateurs(['remises' => 60000, 'ca_periode' => 1000000]);
        self::assertSame(6.0, $i['taux_cout']);
        self::assertTrue($i['alerte_cout']);

        $i = SuiviPromotions::indicateurs(['remises' => 50000, 'ca_periode' => 1000000]);
        self::assertSame(5.0, $i['taux_cout']);
        self::assertFalse($i['alerte_cout'], '5 % pile : pas d\'alerte');

        // Des remises sans CA facturé sur la période : alerte.
        $i = SuiviPromotions::indicateurs(['remises' => 1000, 'ca_periode' => 0]);
        self::assertNull($i['taux_cout']);
        self::assertTrue($i['alerte_cout']);

        // Seuil réglable.
        self::assertFalse(SuiviPromotions::indicateurs(['remises' => 60000, 'ca_periode' => 1000000], 8.0)['alerte_cout']);
    }

    public function testMargeSurLesLignesChiffreesAvecAlerteSous35Pourcent(): void
    {
        $i = SuiviPromotions::indicateurs(['ca_chiffre' => 100000, 'cout' => 70000, 'ca_marges' => 125000]);
        self::assertSame(30000, $i['marge']);
        self::assertSame(30.0, $i['taux_marge']);
        self::assertTrue($i['alerte_marge']);
        self::assertSame(80, $i['part_chiffree']);

        $i = SuiviPromotions::indicateurs(['ca_chiffre' => 100000, 'cout' => 60000, 'ca_marges' => 100000]);
        self::assertSame(40.0, $i['taux_marge']);
        self::assertFalse($i['alerte_marge']);

        $i = SuiviPromotions::indicateurs(['ca_chiffre' => 0, 'cout' => 0, 'ca_marges' => 50000]);
        self::assertNull($i['taux_marge']);
        self::assertFalse($i['alerte_marge']);
        self::assertSame(0, $i['part_chiffree']);
    }

    public function testEffetSurLeSegmentParRapportALaMemeDureeAvant(): void
    {
        self::assertSame(25.0, SuiviPromotions::indicateurs(['ca_segment' => 500000, 'ca_segment_avant' => 400000])['effet']);
        self::assertSame(-50.0, SuiviPromotions::indicateurs(['ca_segment' => 200000, 'ca_segment_avant' => 400000])['effet']);
        self::assertNull(SuiviPromotions::indicateurs(['ca_segment' => 200000, 'ca_segment_avant' => 0])['effet']);
    }

    public function testSansDroitAucunMontantNEstCalcule(): void
    {
        $i = SuiviPromotions::indicateurs([]);
        self::assertSame(['taux_cout' => null, 'alerte_cout' => false, 'marge' => null, 'taux_marge' => null, 'alerte_marge' => false, 'part_chiffree' => null, 'effet' => null], $i);
    }

    public function testDecisionDUneExceptionB2b(): void
    {
        $promotion = (new Promotion())->setNom('Rentrée');
        $demande = new DemandeExceptionPromotion($promotion, (new Clients())->setTypeClient('B2B'), new CommandesDetails(), 1500, 1000, null, new \DateTimeImmutable('2026-11-02 10:00'));
        self::assertTrue($demande->estEnAttente());

        try {
            $demande->decider(false, '  ', new User(), new \DateTimeImmutable());
            self::fail('Un refus sans motif doit être refusé.');
        } catch (\DomainException) {
            self::assertTrue($demande->estEnAttente());
        }

        $demande->decider(true, null, new User(), new \DateTimeImmutable('2026-11-02 11:00'));
        self::assertTrue($demande->estAcceptee());

        $this->expectException(\DomainException::class);
        $demande->decider(false, 'trop tard', new User(), new \DateTimeImmutable());
    }

    public function testLExceptionAccepteeSuitLaLigneDuDevisTransformeEnCommande(): void
    {
        $demande = new DemandeExceptionPromotion(new Promotion(), new Clients(), new \App\Entity\DevisDetails(), 1500, 1000, null, new \DateTimeImmutable('2026-11-02'));
        $demande->decider(true, 'client historique', new User(), new \DateTimeImmutable('2026-11-03'));
        $ligne = new CommandesDetails();

        $copie = $demande->reprisePour($ligne);
        self::assertSame($ligne, $copie->getLigneCommande());
        self::assertNull($copie->getLigneDevis());
        self::assertTrue($copie->estAcceptee());
        self::assertSame('client historique', $copie->getReponse());
    }
}
