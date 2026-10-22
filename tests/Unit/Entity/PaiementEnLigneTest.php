<?php

namespace App\Tests\Unit\Entity;

use App\Entity\CompteTresorerie;
use App\Entity\Paiements;
use PHPUnit\Framework\TestCase;

/** Paiement saisi en ligne : sans compte tant qu'il attend, compte exigé à la validation. */
final class PaiementEnLigneTest extends TestCase
{
    public function testEnAttenteSansCompteAccepte(): void
    {
        $paiement = (new Paiements())->setMontant(15000)->setMode(Paiements::MODE_ORANGE_MONEY);
        $paiement->verifierAvantEnregistrement();
        self::assertNull($paiement->getCompteTresorerie());
    }

    public function testValideSansCompteRefuse(): void
    {
        $paiement = (new Paiements())->setMontant(15000)->setMode(Paiements::MODE_ORANGE_MONEY);
        self::forcerStatut($paiement, Paiements::STATUT_VALIDE);
        $this->expectExceptionMessage('Le compte de trésorerie est obligatoire.');
        $paiement->verifierAvantEnregistrement();
    }

    public function testCopieSynchroniseeSansCompte(): void
    {
        // Le site reçoit les paiements validés au local sans leur compte.
        $paiement = (new Paiements())->setMontant(15000)->setMode(Paiements::MODE_ORANGE_MONEY);
        self::forcerStatut($paiement, Paiements::STATUT_VALIDE);
        $paiement->marquerCopieSynchronisee();
        $paiement->verifierAvantEnregistrement();
        self::assertSame(Paiements::STATUT_VALIDE, $paiement->getStatut());
    }

    public function testComptesPossiblesSelonLeMode(): void
    {
        self::assertSame([CompteTresorerie::TYPE_ORANGE_MONEY], Paiements::typesComptesPour(Paiements::MODE_ORANGE_MONEY));
        self::assertSame([CompteTresorerie::TYPE_BANQUE], Paiements::typesComptesPour(Paiements::MODE_CHEQUE));
        self::assertSame([], Paiements::typesComptesPour(Paiements::MODE_SOLDE_CLIENT));
    }

    private static function forcerStatut(Paiements $paiement, string $statut): void
    {
        (new \ReflectionProperty(Paiements::class, 'statut'))->setValue($paiement, $statut);
    }
}
