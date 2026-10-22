<?php

namespace App\Tests\Unit\Entity;

use App\Entity\ColisProduction;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControleFinal;
use App\Entity\ControlePrePresse;
use App\Entity\OrdreProduction;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

/**
 * Colis étiquetés et « Refaire une pièce » (PD05, PD06,
 * docs/production-colis.md) : code court, lecture d'un scan, scan
 * idempotent, étiquette remplacée ; la pièce à refaire repart en
 * production, garde ce qui a été produit et sort sa matière sous sa propre
 * référence de stock.
 */
final class ColisEtRepriseTest extends TestCase
{
    public function testCodeCourtEtLectureDUnScan(): void
    {
        self::assertSame('CMD-000183-C2', ColisProduction::codeDe('CMD-000183', 1, 2));
        self::assertSame('CMD-000183-S2-C1', ColisProduction::codeDe('CMD-000183', 2, 1));

        $jeton = str_repeat('ab12', 16);
        self::assertSame(['jeton' => $jeton], ColisProduction::lireSaisie('http://192.168.1.10:8000/c/'.$jeton));
        self::assertSame(['jeton' => $jeton], ColisProduction::lireSaisie(strtoupper($jeton)));
        self::assertSame(['code' => 'CMD-000183-C2'], ColisProduction::lireSaisie(' cmd-000183-c2 '));
        self::assertNull(ColisProduction::lireSaisie('https://exemple.com/autre'));
        self::assertNull(ColisProduction::lireSaisie(''));
    }

    public function testScanIdempotentEtEtiquetteRemplacee(): void
    {
        $commande = (new Commandes())->setNumero('CMD-000183');
        $colis = new ColisProduction($commande, 1, 2, 2, ColisProduction::ROULEAU, [['ordre' => 1, 'ligne' => 1, 'libelle' => 'Bâche 300 × 100 cm × 1', 'quantite' => 1]], null, new \DateTimeImmutable('2026-10-20 10:50'));
        $oumar = new User();

        self::assertSame(64, \strlen($colis->getJeton()));
        self::assertSame('CMD-000183-C2', $colis->getCode());
        self::assertTrue($colis->scanner($oumar, new \DateTimeImmutable('2026-10-20 11:02')));
        self::assertFalse($colis->scanner($oumar, new \DateTimeImmutable('2026-10-20 11:30')), 'déjà scanné');
        self::assertSame('11:02', $colis->getScanneLe()?->format('H:i'));

        $ancien = new ColisProduction($commande, 1, 1, 2, ColisProduction::ROULEAU, [], null, new \DateTimeImmutable());
        $ancien->annuler(new \DateTimeImmutable());
        $this->expectException(\LogicException::class);
        $ancien->scanner($oumar, new \DateTimeImmutable());
    }

    public function testUnColisDontUnePieceEstRefaiteSeRescanne(): void
    {
        $commande = (new Commandes())->setNumero('CMD-000183');
        $oumar = new User();
        $bache = new ColisProduction($commande, 1, 1, 2, ColisProduction::ROULEAU, [['ordre' => 7, 'ligne' => 1, 'libelle' => 'Bâche 300 × 100 cm × 1', 'quantite' => 1]], null, new \DateTimeImmutable('2026-10-20 10:50'));
        $flyers = new ColisProduction($commande, 1, 2, 2, ColisProduction::CARTON, [['ordre' => 8, 'ligne' => 2, 'libelle' => 'Flyers A5 × 1000', 'quantite' => 1000]], null, new \DateTimeImmutable('2026-10-20 10:50'));
        $bache->scanner($oumar, new \DateTimeImmutable('2026-10-20 11:02'));
        $flyers->scanner($oumar, new \DateTimeImmutable('2026-10-20 11:03'));

        self::assertTrue($bache->rouvrirPourReprise(7));
        self::assertFalse($bache->estScanne(), 'la bâche refaite se rescanne');
        self::assertNull($bache->getScannePar());
        self::assertFalse($flyers->rouvrirPourReprise(7));
        self::assertTrue($flyers->estScanne(), 'le carton de flyers reste scanné');
        self::assertTrue($bache->scanner($oumar, new \DateTimeImmutable('2026-10-21 09:15')));
    }

    public function testRefaireUnePiece(): void
    {
        $oumar = new User();
        $ligne = (new CommandesDetails())->setQuantite(2);
        $ordre = (new OrdreProduction())->setCommandeDetail($ligne)->setControlePrePresse(new ControlePrePresse())->setQuantite(2);
        (new \ReflectionProperty(OrdreProduction::class, 'id'))->setValue($ordre, 123);

        $this->poser($ligne, 'statutProduction', CommandesDetails::PRODUCTION_EN_COURS);
        $ordre->demarrer($oumar);
        $ligne->terminerProduction($oumar, 2, 0);
        $ordre->terminer($oumar, 2, 0);
        self::assertSame('PROD-000123', $ordre->referenceStock());

        // Contrôle final : une bâche rayée, à refaire.
        $ordre->refaire(1);
        $ligne->rouvrirPourRefaire(1);
        self::assertTrue($ordre->estADemarrer());
        self::assertSame(CommandesDetails::PRODUCTION_A_PRODUIRE, $ligne->getStatutProduction());
        self::assertSame(1, $ordre->getQuantiteConforme());
        self::assertSame('PROD-000123-R1', $ordre->referenceStock(), 'la reprise sort sa matière sous sa propre référence');

        // Reprise : rien n'est remis à zéro, la pièce s'ajoute.
        $ligne->demarrerReprise($oumar);
        $ordre->demarrer($oumar);
        $ligne->terminerProduction($oumar, 1 + $ligne->getQuantiteProduite(), 0 + $ligne->getQuantiteRebut());
        $ordre->terminer($oumar, 1, 0);

        self::assertTrue($ordre->estTermine());
        self::assertSame(3, $ordre->getQuantiteProduite());
        self::assertSame(1, $ordre->getQuantiteRebut());
        self::assertSame(2, $ordre->getQuantiteConforme());
        self::assertSame(2, $ligne->getQuantiteProduite() - $ligne->getQuantiteRebut());
        self::assertNull($ordre->getPiecesARefaire());
        self::assertSame('PROD-000123', $ordre->referenceStock());
        self::assertSame('PROD-000123-R2', OrdreProduction::referenceStockDe(123, 2));
    }

    public function testOnNeRefaitPasUneCommandeDejaPrete(): void
    {
        $oumar = new User();
        $ordre = (new OrdreProduction())->setControlePrePresse(new ControlePrePresse())->setQuantite(1);
        $ordre->demarrer($oumar);
        $ordre->terminer($oumar, 1, 0);
        $ordre->etiqueterColis(1);
        $ordre->marquerPretLivraison($oumar);

        $this->expectException(\LogicException::class);
        $ordre->refaire(1);
    }

    public function testLeControleSeRouvreAvecUnePieceARefaire(): void
    {
        $controle = new ControleFinal(new Commandes());
        $controle->valider(['1' => ['textes' => true]], new User(), new \DateTimeImmutable());
        self::assertTrue($controle->estValide());

        $controle->rouvrir(new \DateTimeImmutable());
        self::assertFalse($controle->estValide());
        self::assertSame(1, $controle->getReprises());
    }

    private function poser(object $objet, string $champ, mixed $valeur): void
    {
        (new \ReflectionProperty($objet, $champ))->setValue($objet, $valeur);
    }
}
