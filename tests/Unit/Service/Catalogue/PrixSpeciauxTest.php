<?php

namespace App\Tests\Unit\Service\Catalogue;

use App\Entity\CommandesDetails;
use App\Entity\PrixSpecial;
use App\Service\Catalogue\OptionsPartagees;
use App\Service\Catalogue\PrixSpeciaux;
use PHPUnit\Framework\TestCase;

/**
 * Prix négocié appliqué sur une ligne (docs/produits.md) : posé en remise
 * par unité, la facture garde le prix normal.
 */
final class PrixSpeciauxTest extends TestCase
{
    public function testLaRemiseRameneAuPrixNegocie(): void
    {
        self::assertSame(500, PrixSpeciaux::remiseParUnite(5000, 4500));
        self::assertSame(0, PrixSpeciaux::remiseParUnite(5000, 5200), 'un prix négocié plus cher ne monte pas le prix');
        self::assertSame(5000, PrixSpeciaux::remiseParUnite(5000, -10));
    }

    public function testLePrixSpecialEstAppliqueALaLigne(): void
    {
        $ligne = (new CommandesDetails())->setPrixUnitaire(5000)->setQuantite(10)->setTva(0);
        $ligne->calculerTotaux();
        self::assertSame(50000, $ligne->getTotalHt());

        PrixSpeciaux::appliquer($ligne, 4500);

        self::assertSame(5000, (int) $ligne->getPrixUnitaire(), 'le prix normal reste sur la ligne');
        self::assertSame(500, (int) $ligne->getRemise());
        self::assertSame(45000, $ligne->getTotalHt());
    }

    public function testUneRemisePlusForteDuComptoirEstGardee(): void
    {
        $ligne = (new CommandesDetails())->setPrixUnitaire(5000)->setQuantite(2)->setRemise(800)->setTva(0);
        $ligne->calculerTotaux();

        PrixSpeciaux::appliquer($ligne, 4500);

        self::assertSame(800, (int) $ligne->getRemise());
        self::assertSame(8400, $ligne->getTotalHt());
    }

    public function testUnPrixNestValableQueSilEstAccordeEtPasEchu(): void
    {
        $jour = new \DateTimeImmutable('2026-10-18');
        $ps = (new PrixSpecial())->setPrix(4500)->setStatut(PrixSpecial::ACTIF)->setFinLe(new \DateTimeImmutable('2026-10-18'));
        self::assertTrue($ps->estValable($jour));
        self::assertFalse($ps->estValable($jour->modify('+1 day')));
        self::assertTrue($ps->estEchu($jour->modify('+1 day')));

        self::assertFalse((clone $ps)->setStatut(PrixSpecial::ATTENTE)->estValable($jour));
        self::assertTrue((clone $ps)->setFinLe(null)->estValable($jour->modify('+5 years')));

        $ps->setStatut(PrixSpecial::ATTENTE)->decider(false, null, $jour);
        self::assertSame(PrixSpecial::REFUSE, $ps->getStatut());
    }

    public function testLeResumeDuPrixDUneOptionPartagee(): void
    {
        self::assertSame('incluse', OptionsPartagees::resumePrix([['payante' => false, 'prix' => 500, 'mode' => 'metre_carre']])['texte']);
        $unique = OptionsPartagees::resumePrix([['payante' => true, 'prix' => 300, 'mode' => 'metre_carre'], ['payante' => true, 'prix' => 300, 'mode' => 'metre_carre']]);
        self::assertSame(300, $unique['unique']);
        self::assertStringStartsWith('+300', $unique['texte']);
        self::assertStringStartsWith('de 25 à ', OptionsPartagees::resumePrix([['payante' => true, 'prix' => 25, 'mode' => 'unite'], ['payante' => true, 'prix' => 50, 'mode' => 'unite']])['texte']);
        self::assertSame('pas encore proposée', OptionsPartagees::resumePrix([])['texte']);
    }
}
