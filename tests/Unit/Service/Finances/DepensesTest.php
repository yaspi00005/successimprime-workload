<?php

namespace App\Tests\Unit\Service\Finances;

use App\Entity\CompteTresorerie;
use App\Entity\Depense;
use App\Entity\MouvementTresorerie;
use App\Entity\User;
use App\Service\Finances\Depenses;
use PHPUnit\Framework\TestCase;

final class DepensesTest extends TestCase
{
    public function testStatutALaSaisie(): void
    {
        // Au-delà du seuil, l'accord de la direction est demandé, payée ou non.
        self::assertSame(Depense::A_APPROUVER, Depenses::statutInitial(285000, 100000, false, false));
        self::assertSame(Depense::A_APPROUVER, Depenses::statutInitial(100001, 100000, false, true));
        // La direction approuve d'office ce qu'elle saisit.
        self::assertSame(Depense::A_PAYER, Depenses::statutInitial(285000, 100000, true, false));
        // Sous le seuil : validée si payée sur place, à payer sinon.
        self::assertSame(Depense::VALIDEE, Depenses::statutInitial(12000, 100000, false, true));
        self::assertSame(Depense::A_PAYER, Depenses::statutInitial(100000, 100000, false, false));
    }

    public function testLibellesDeStatut(): void
    {
        $d = (new Depense())->setStatut(Depense::A_PAYER);
        self::assertSame('À payer', $d->libelleStatut());
        $d->setEcheance(new \DateTimeImmutable('2026-11-01'));
        self::assertSame('À payer le 01/11', $d->libelleStatut());
        self::assertSame('bleu', $d->couleurStatut());
        self::assertSame('orange', (new Depense())->setStatut(Depense::A_APPROUVER)->couleurStatut());
        self::assertSame(Depense::A_PAYER, (new Depense())->setStatut('inconnu')->getStatut());
    }

    public function testEtatSuitLeDecaissement(): void
    {
        $mouvement = (new MouvementTresorerie())
            ->setType(MouvementTresorerie::TYPE_DECAISSEMENT)
            ->setCompteSource(new CompteTresorerie())
            ->setMontant(12000);
        $d = (new Depense())->setMontant(12000)->payer(Depense::VALIDEE, new User(), new \DateTimeImmutable(), $mouvement);

        $etat = Depenses::etat($d);
        self::assertSame('Validée', $etat['libelle']);
        self::assertSame('décaissement à valider', $etat['note']);
        self::assertFalse(Depenses::payable($d));

        // Rejeté dans « À valider » : la dépense redevient à payer.
        $mouvement->marquerCommeAnnule('Reçu illisible');
        $etat = Depenses::etat($d);
        self::assertSame('Paiement rejeté', $etat['libelle']);
        self::assertSame('Reçu illisible', $etat['note']);
        self::assertTrue(Depenses::payable($d));
    }

    public function testDepenseRefuseeOuAApprouverNonPayable(): void
    {
        self::assertFalse(Depenses::payable((new Depense())->setStatut(Depense::A_APPROUVER)));
        $refusee = (new Depense())->refuser(new User(), 'Trop cher', new \DateTimeImmutable());
        self::assertSame(Depense::REFUSEE, $refusee->getStatut());
        self::assertSame('Trop cher', Depenses::etat($refusee)['note']);
        self::assertFalse(Depenses::payable($refusee));
        self::assertFalse($refusee->estEngagee());
        self::assertTrue((new Depense())->setStatut(Depense::A_PAYER)->estEngagee());
    }

    public function testEcartDePrixEtTypeDuRecu(): void
    {
        self::assertSame(3, Depenses::ecart(95000, 92000));
        self::assertSame(-10, Depenses::ecart(900, 1000));
        self::assertNull(Depenses::ecart(null, 92000));
        self::assertNull(Depenses::ecart(95000, 0));

        self::assertSame('image/jpeg', Depenses::typeJustificatif('/x/2026/10/abc.jpg'));
        self::assertSame('application/pdf', Depenses::typeJustificatif('/x/2026/10/abc.pdf'));
        self::assertSame('application/octet-stream', Depenses::typeJustificatif('/x/abc.exe'));
    }

    public function testPayeA(): void
    {
        self::assertSame('Station Bakary', (new Depense())->setBeneficiaire('  Station Bakary ')->getPayeA());
        self::assertSame('', (new Depense())->getPayeA());
    }
}
