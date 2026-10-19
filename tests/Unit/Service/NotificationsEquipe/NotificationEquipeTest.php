<?php

namespace App\Tests\Unit\Service\NotificationsEquipe;

use App\Entity\Notification;
use App\Repository\NotificationRepository;
use App\Service\NotificationsEquipe\AccordsPlafond;
use App\Service\NotificationsEquipe\CanauxExternes;
use App\Service\NotificationsEquipe\NotificationsEquipe;
use App\Service\NotificationsEquipe\TypesNotification;
use PHPUnit\Framework\TestCase;

/**
 * Regroupement, droits sur les montants, résolution automatique et
 * fenêtre bloquante du plafond de crédit.
 */
final class NotificationEquipeTest extends TestCase
{
    public function testLaMemeAlerteEstRegroupeeSansNouvelleLigne(): void
    {
        $n = ReglesNotificationsTest::notification('acompte_recu');
        self::assertSame(1, $n->getCompteur());
        $n->regrouper('Acompte reçu · Salon Espoir', 'Orange Money', new \DateTimeImmutable('2026-10-19 10:05'));
        $n->regrouper('Acompte reçu · Salon Espoir', 'Orange Money', new \DateTimeImmutable('2026-10-19 10:10'));
        self::assertSame(3, $n->getCompteur());
        self::assertEquals(new \DateTimeImmutable('2026-10-19 10:10'), $n->getDerniereFois());

        // Une alerte qui dure (source relue) : texte à jour, sans compter une fois de plus.
        $n->actualiserTexte('Acompte reçu · Salon Espoir', 'Espèces', null, new \DateTimeImmutable('2026-10-19 10:15'));
        self::assertSame(3, $n->getCompteur());
        self::assertSame('Espèces', $n->getDetail());
    }

    public function testMontantSeulementPourQuiALeDroit(): void
    {
        $n = (new Notification())->setMessage('Acompte reçu · Salon Espoir')->setMontant(30000, 'ROLE_PAIEMENT_VOIR');
        self::assertSame("Acompte reçu · Salon Espoir · 30\u{202F}000 F", $n->titrePour(['ROLE_USER', 'ROLE_PAIEMENT_VOIR']));
        self::assertSame("Acompte reçu · Salon Espoir · 30\u{202F}000 F", $n->titrePour(['ROLE_ADMIN']), 'la direction voit tout');
        self::assertSame('Acompte reçu · Salon Espoir', $n->titrePour(['ROLE_USER', 'ROLE_GRAPHISTE']));
        self::assertSame('Acompte reçu · Salon Espoir', $n->titrePour(['ROLE_USER', 'ROLE_TRESORERIE_VOIR']), 'un autre droit ne suffit pas');

        // Sans droit précisé : direction seulement.
        $solde = (new Notification())->setMessage('Facture échue')->setMontant(60000, null);
        self::assertSame('ROLE_ADMIN', $solde->getDroitMontant());
        self::assertSame('Facture échue', $solde->titrePour(['ROLE_USER', 'ROLE_PAIEMENT_VOIR']));
    }

    public function testTexteDesSmsEtWhatsAppSansMontantEtCourt(): void
    {
        $n = (new Notification())->setNiveau(Notification::CRITIQUE)->setMessage('Machine en panne : Konica C6100')->setDetail(str_repeat('Arrêt signalé. ', 20))->setMontant(30000, 'ROLE_PAIEMENT_VOIR');
        $texte = CanauxExternes::texte($n);
        self::assertStringStartsWith('Succès Imprim · CRITIQUE : Machine en panne', $texte);
        self::assertLessThanOrEqual(160, mb_strlen($texte));
        self::assertStringNotContainsString('30', $texte);
    }

    public function testResolueElleDisparaitEtLInfoLueAussi(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-19 10:00');
        $urgent = ReglesNotificationsTest::notification('bat_retard');
        self::assertTrue($urgent->estActive());
        $urgent->marquerLue();
        self::assertTrue($urgent->estActive(), 'lue ne suffit pas : il faut faire l\'action');
        $urgent->resoudre(Notification::RESOLUE_AUTO, $maintenant);
        self::assertFalse($urgent->estActive());

        $info = ReglesNotificationsTest::notification('commande_prete')->marquerLue();
        self::assertFalse($info->estActive());

        $reportee = ReglesNotificationsTest::notification('acompte_recu')->reporter($maintenant->modify('+1 hour'));
        self::assertTrue($reportee->estReportee($maintenant));
        self::assertFalse($reportee->estReportee($maintenant->modify('+61 minutes')));
        self::assertContains('mention', TypesNotification::RESOLUS_A_L_OUVERTURE);
    }

    public function testResolutionAutomatiqueQuandLaSourceNeLaVoitPlus(): void
    {
        $autre = ReglesNotificationsTest::notification('stock_bas')->setEmpreinte('stock:1');
        $reglee = ReglesNotificationsTest::notification('stock_bas')->setEmpreinte('stock:2');
        $depot = $this->createStub(NotificationRepository::class);
        $depot->method('activesDuType')->willReturn([$autre, $reglee]);
        $depot->method('parEmpreinte')->willReturn([]);

        $equipe = (new \ReflectionClass(NotificationsEquipe::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($depot): void {
            $this->depot = $depot;
        }, $equipe, NotificationsEquipe::class)();

        // Plus rien à commander : la source ne renvoie aucune alerte.
        $bilan = $equipe->synchroniser('stock_bas', [], new \DateTimeImmutable('2026-10-19 10:00'));
        self::assertSame(['creees' => 0, 'resolues' => 2], $bilan);
        self::assertSame(Notification::RESOLUE_AUTO, $reglee->getResolution());
        self::assertFalse($autre->estActive());
    }

    public function testFenetreBloquanteDuPlafondMontantsSelonLesDroits(): void
    {
        $analyse = ['autorise' => false, 'encoursAnterieur' => 285000, 'totalCommande' => 40000, 'plafondCredit' => 300000];
        $visible = AccordsPlafond::texte($analyse, 'Salon Espoir', true);
        self::assertStringContainsString("285\u{202F}000 F + 40\u{202F}000 F > 300\u{202F}000 F", $visible['phrase']);
        self::assertStringContainsString('Demandez un acompte ou l\'accord de la direction', $visible['phrase']);
        self::assertCount(3, $visible['chiffres']);

        $cache = AccordsPlafond::texte($analyse, 'Salon Espoir', false);
        self::assertSame('Salon Espoir dépasse son plafond de crédit. Demandez un acompte ou l\'accord de la direction.', $cache['phrase']);
        self::assertSame([], $cache['chiffres']);
        self::assertDoesNotMatchRegularExpression('/\d/', $cache['phrase']);
    }
}
