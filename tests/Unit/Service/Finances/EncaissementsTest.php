<?php

namespace App\Tests\Unit\Service\Finances;

use App\Entity\LigneReleveOrangeMoney;
use App\Entity\Paiements;
use App\Entity\User;
use App\Service\Finances\Encaissements;
use PHPUnit\Framework\TestCase;

final class EncaissementsTest extends TestCase
{
    public function testReleveAvecTitreEnTetesFrancaisEtLignesEcartees(): void
    {
        $csv = "\xEF\xBB\xBFRelevé compte marchand - du 01/10/2026 au 13/10/2026\n"
            ."Date;Heure;N° Transaction;Type;Numéro;Nom;Montant (FCFA);Frais;Statut;Message\n"
            ."13/10/2026;09:31;MP261013.0931.A41207;Paiement marchand;+223 60 11 79 02;BAKARY KEITA;99 545;0;Succès;\n"
            ."13/10/2026;10:12;MP261013.1012.B77310;Transfert reçu;+223 76 55 44 21;SEYDOU KONE;40 000;0;Succès;Acompte F-D00562\n"
            ."11/10/2026;09:15;MP261011.0915.D33001;Retrait;+223 60 00 00 00;;-50 000;500;Succès;\n"
            ."10/10/2026;18:02;MP261010.1802.E90017;Paiement marchand;+223 65 43 21 09;MOUSSA;20 000;0;Échec;\n"
            ."\n"
            ."32/10/2026;18:02;MP261010.1802.E90018;Paiement marchand;;;5 000;0;Succès;\n"
            ."10/10/2026;18:05;;Paiement marchand;;;5 000;0;Succès;\n";

        $lu = Encaissements::lireReleve($csv);

        self::assertCount(2, $lu['lignes']);
        self::assertSame(2, $lu['ignorees']);
        self::assertSame(['Ligne 8 : date illisible.', 'Ligne 9 : référence illisible.'], $lu['erreurs']);
        $premiere = $lu['lignes'][0];
        self::assertSame('MP261013.0931.A41207', $premiere['reference']);
        self::assertSame(99545, $premiere['montant']);
        self::assertSame('2026-10-13 09:31', $premiere['recu_le']->format('Y-m-d H:i'));
        self::assertSame('+223 60 11 79 02', $premiere['expediteur']);
        self::assertSame('BAKARY KEITA', $premiere['nom']);
        self::assertNull($premiere['libelle']);
        self::assertSame('Acompte F-D00562', $lu['lignes'][1]['libelle']);
    }

    public function testReleveEnAnglaisAvecVirgulesEtDateIso(): void
    {
        $csv = "Transaction ID,Transaction date,Sender,Amount,Status\n"
            ."\"MP1\",2026-10-12 08:15:00,22370112233,\"12,500.00\",SUCCESS\n"
            ."MP2,2026-10-12 08:20:00,22370112233,7000,FAILED\n";

        $lu = Encaissements::lireReleve($csv);

        self::assertCount(1, $lu['lignes']);
        self::assertSame(12500, $lu['lignes'][0]['montant']);
        self::assertSame('2026-10-12 08:15', $lu['lignes'][0]['recu_le']->format('Y-m-d H:i'));
        self::assertSame(1, $lu['ignorees']);
    }

    public function testReleveSansLesColonnesUtiles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Encaissements::lireReleve("Nom;Téléphone\nAwa;70000000\n");
    }

    public function testMontantsDatesEtNumeros(): void
    {
        self::assertSame(42000, Encaissements::montant('42 000'));
        self::assertSame(42000, Encaissements::montant("42\u{202F}000 FCFA"));
        self::assertSame(42000, Encaissements::montant('42.000'));
        self::assertSame(42000, Encaissements::montant('42000,00'));
        self::assertSame(-5000, Encaissements::montant('-5 000 F'));
        self::assertNull(Encaissements::montant('quarante'));

        self::assertSame('2026-10-15 09:31', Encaissements::date('15/10/2026 09:31')?->format('Y-m-d H:i'));
        self::assertSame('2026-10-15 00:00', Encaissements::date('15-10-2026')?->format('Y-m-d H:i'));
        self::assertSame('2026-10-15 09:31', Encaissements::date('15/10/26 09:31')?->format('Y-m-d H:i'));
        self::assertNull(Encaissements::date('2026/15/10'));

        self::assertSame('60117902', Encaissements::numeroCourt('+223 60 11 79 02'));
        self::assertSame('60117902', Encaissements::numeroCourt('0022360117902'));
        self::assertSame('60117902', Encaissements::numeroCourt('60117902'));
        self::assertNull(Encaissements::numeroCourt('1234'));
    }

    public function testScoreDUneCreance(): void
    {
        $ligne = ['montant' => 42000, 'expediteur' => '+223 60 11 79 02', 'nom' => 'ONG SAHEL VERT', 'libelle' => null, 'recu_le' => new \DateTimeImmutable('2026-10-15 09:31')];
        $creance = ['reste' => 42000, 'documents' => ['F-SI-2026-0398', 'CMD-0398'], 'client' => 'ONG Sahel Vert', 'telephone' => '60117902', 'jours' => 12, 'relance' => new \DateTimeImmutable('2026-10-13 10:00')];

        // Montant identique 55, même numéro 30, nom proche 15, échue 5, relancée 5 : plafonné à 99.
        $s = Encaissements::scoreCreance($ligne, $creance);
        self::assertSame(99, $s['score']);
        self::assertSame(['42 000 F échus', 'montant identique', 'même numéro que la fiche', 'nom proche', 'relancée le 13/10'], $s['raisons']);

        // Acompte avec le numéro de facture dans le message, autre téléphone.
        $acompte = Encaissements::scoreCreance(['montant' => 20000, 'expediteur' => '+223 76 00 00 00', 'nom' => null, 'libelle' => 'acompte f si 2026/0398'] + $ligne, ['jours' => -3, 'relance' => null] + $creance);
        self::assertSame(60, $acompte['score']);
        self::assertSame(['42 000 F dus', 'acompte', 'numéro du document dans le message'], $acompte['raisons']);

        // Plus que le reste dû : jamais proposé.
        self::assertSame(0, Encaissements::scoreCreance(['montant' => 50000] + $ligne, $creance)['score']);
    }

    public function testScoreDUnPaiementDejaSaisi(): void
    {
        $ligne = ['montant' => 108000, 'expediteur' => '+223 79 08 11 42', 'recu_le' => new \DateTimeImmutable('2026-10-10 12:04'), 'reference' => 'MP261010.1204.F55120'];

        $s = Encaissements::scorePaiement($ligne, ['montant' => 108000, 'date' => new \DateTimeImmutable('2026-10-10 12:00'), 'telephone' => '79081142', 'reference' => null]);
        self::assertSame(99, $s['score']);
        self::assertSame(['déjà saisi', 'montant identique', 'même jour', 'même numéro que la fiche'], $s['raisons']);

        self::assertSame(65, Encaissements::scorePaiement($ligne, ['montant' => 108000, 'date' => new \DateTimeImmutable('2026-10-12 09:00'), 'telephone' => null, 'reference' => null])['score']);
        self::assertSame(0, Encaissements::scorePaiement($ligne, ['montant' => 108000, 'date' => new \DateTimeImmutable('2026-10-15 09:00'), 'telephone' => null, 'reference' => null])['score']);
        self::assertSame(0, Encaissements::scorePaiement($ligne, ['montant' => 100000, 'date' => new \DateTimeImmutable('2026-10-10 12:00'), 'telephone' => null, 'reference' => null])['score']);
    }

    public function testMessageDImport(): void
    {
        self::assertSame(
            'Relevé importé : 19 transactions nouvelles, dont 15 rapprochées d’un paiement déjà saisi (même référence) ; 2 lignes écartées (envois, retraits ou échecs).',
            Encaissements::messageImport(['ajoutees' => 19, 'doublons' => 0, 'rapprochees' => 15, 'ignorees' => 2]),
        );
        self::assertSame('Rien de nouveau dans ce relevé ; 19 déjà importées.', Encaissements::messageImport(['ajoutees' => 0, 'doublons' => 19, 'rapprochees' => 0, 'ignorees' => 0]));
    }

    public function testUneTransactionResteAAssocierSiSonPaiementEstRejete(): void
    {
        $ligne = new LigneReleveOrangeMoney('MP1', new \DateTimeImmutable('2026-10-15 09:31'), 42000, null, new \DateTimeImmutable());
        self::assertTrue($ligne->estAAssocier());

        $paiement = (new Paiements())->setMontant(42000)->setMode(Paiements::MODE_ORANGE_MONEY);
        $ligne->associer($paiement, 97, new User(), new \DateTimeImmutable());
        self::assertFalse($ligne->estAAssocier());
        self::assertSame(97, $ligne->getScore());

        $paiement->rejeter('référence inconnue', new User());
        self::assertTrue($ligne->estAAssocier());

        $ignoree = (new LigneReleveOrangeMoney('MP2', new \DateTimeImmutable(), 1000, null, new \DateTimeImmutable()))->ignorer('apport du gérant', new User(), new \DateTimeImmutable());
        self::assertFalse($ignoree->estAAssocier());
    }
}
