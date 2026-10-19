<?php

namespace App\Tests\Unit\Service\NotificationsEquipe;

use App\Entity\Notification;
use App\Service\NotificationsEquipe\ReglagesNotifications;
use App\Service\NotificationsEquipe\ReglesNotifications;
use App\Service\NotificationsEquipe\TypesNotification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Niveaux, heures calmes, petites fenêtres, escalade et report des
 * notifications de l'équipe (maquettes NT01, NT02, NT04).
 */
final class ReglesNotificationsTest extends TestCase
{
    private const CALME = ['debut' => '19:00', 'fin' => '07:30', 'jours' => [1, 2, 3, 4, 5, 6], 'jours_entiers' => [0]];
    private const ESCALADE = ['urgent_minutes' => 30, 'critique_minutes' => 60, 'direction_minutes' => 60];

    public static function notification(string $type, string $creee = '2026-10-19 10:00'): Notification
    {
        $n = (new Notification())->setType($type)->setNiveau(TypesNotification::niveau($type))->setMessage('Test')->setEmpreinte('test:'.$type);
        \Closure::bind(function (Notification $n) use ($creee): void {
            $n->dateCreation = new \DateTimeImmutable($creee);
        }, null, Notification::class)($n);

        return $n;
    }

    public function testQuatreNiveauxDansLOrdre(): void
    {
        self::assertSame([Notification::CRITIQUE, Notification::URGENT, Notification::A_FAIRE, Notification::INFO], array_keys(Notification::NIVEAUX));
        self::assertSame(Notification::CRITIQUE, TypesNotification::niveau('machine_panne'));
        self::assertSame(Notification::CRITIQUE, TypesNotification::niveau('caisse_non_cloturee'));
        self::assertSame(Notification::URGENT, TypesNotification::niveau('client_sans_reponse'));
        self::assertSame(Notification::URGENT, TypesNotification::niveau('bat_retard'));
        self::assertSame(Notification::URGENT, TypesNotification::niveau('plafond_credit'));
        self::assertSame(Notification::A_FAIRE, TypesNotification::niveau('acompte_recu'));
        self::assertSame(Notification::A_FAIRE, TypesNotification::niveau('devis_relancer'));
        self::assertSame(Notification::A_FAIRE, TypesNotification::niveau('stock_bas'));
        self::assertSame(Notification::INFO, TypesNotification::niveau('commande_prete'));
    }

    public function testNiveauInconnuRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Notification())->setNiveau('grave');
    }

    /** @return iterable<string, array{string, bool}> */
    public static function moments(): iterable
    {
        yield 'lundi 10 h' => ['2026-10-19 10:00', false];
        yield 'lundi 18 h 59' => ['2026-10-19 18:59', false];
        yield 'lundi 19 h' => ['2026-10-19 19:00', true];
        yield 'mardi 2 h (nuit de lundi)' => ['2026-10-20 02:00', true];
        yield 'mardi 7 h 29' => ['2026-10-20 07:29', true];
        yield 'mardi 7 h 30' => ['2026-10-20 07:30', false];
        yield 'samedi 20 h' => ['2026-10-24 20:00', true];
        yield 'dimanche midi' => ['2026-10-25 12:00', true];
        yield 'lundi 7 h (après le dimanche)' => ['2026-10-26 07:00', true];
        yield 'lundi 8 h' => ['2026-10-26 08:00', false];
    }

    #[DataProvider('moments')]
    public function testHeuresCalmes(string $moment, bool $calme): void
    {
        self::assertSame($calme, ReglesNotifications::estCalme(new \DateTimeImmutable($moment), self::CALME));
    }

    public function testHorsHeuresSeulesLesCritiquesDerangent(): void
    {
        self::assertTrue(ReglesNotifications::peutDeranger(Notification::CRITIQUE, true));
        self::assertFalse(ReglesNotifications::peutDeranger(Notification::URGENT, true));
        self::assertFalse(ReglesNotifications::peutDeranger(Notification::A_FAIRE, true));
        self::assertTrue(ReglesNotifications::peutDeranger(Notification::URGENT, false));
        self::assertTrue(ReglesNotifications::peutDeranger(Notification::A_FAIRE, false));
        // L'info ne dérange jamais : seulement la liste.
        self::assertFalse(ReglesNotifications::peutDeranger(Notification::INFO, false));
    }

    public function testPetiteFenetreALArriveePuisToutesLes30MinPourLUrgent(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-19 10:00');
        $urgent = self::notification('client_sans_reponse');
        self::assertTrue(ReglesNotifications::petiteFenetre($urgent, $maintenant, false, 30));
        self::assertFalse(ReglesNotifications::petiteFenetre($urgent, $maintenant, true, 30), 'heures calmes');

        $urgent->marquerAlertee($maintenant);
        self::assertFalse(ReglesNotifications::petiteFenetre($urgent, $maintenant->modify('+29 minutes'), false, 30));
        self::assertTrue(ReglesNotifications::petiteFenetre($urgent, $maintenant->modify('+30 minutes'), false, 30));

        $aFaire = self::notification('acompte_recu')->marquerAlertee($maintenant);
        self::assertFalse(ReglesNotifications::petiteFenetre($aFaire, $maintenant->modify('+2 hours'), false, 30), 'l\'à faire ne revient pas');

        self::assertFalse(ReglesNotifications::petiteFenetre(self::notification('commande_prete'), $maintenant, false, 30), 'jamais pour l\'info');
        self::assertFalse(ReglesNotifications::petiteFenetre(self::notification('machine_panne'), $maintenant, false, 30), 'le critique a sa bannière');
    }

    public function testSonDuCritiqueUneSeuleFois(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-19 10:00');
        $critique = self::notification('machine_panne');
        self::assertTrue(ReglesNotifications::sonCritique($critique, $maintenant));
        $critique->marquerAlertee($maintenant);
        self::assertFalse(ReglesNotifications::sonCritique($critique, $maintenant));
    }

    public function testEscaladeUrgentResponsablePuisDirection(): void
    {
        $n = self::notification('bat_retard', '2026-10-19 10:00');
        self::assertNull(ReglesNotifications::remontee($n, new \DateTimeImmutable('2026-10-19 10:29'), self::ESCALADE));
        self::assertSame(1, ReglesNotifications::remontee($n, new \DateTimeImmutable('2026-10-19 10:30'), self::ESCALADE));

        $n->escalader(1, new \DateTimeImmutable('2026-10-19 10:30'));
        self::assertNull(ReglesNotifications::remontee($n, new \DateTimeImmutable('2026-10-19 11:29'), self::ESCALADE));
        self::assertSame(2, ReglesNotifications::remontee($n, new \DateTimeImmutable('2026-10-19 11:30'), self::ESCALADE));

        $n->escalader(2, new \DateTimeImmutable('2026-10-19 11:30'));
        self::assertNull(ReglesNotifications::remontee($n, new \DateTimeImmutable('2026-10-19 18:00'), self::ESCALADE), 'une seule fois');
    }

    public function testEscaladeCritiqueDirectionApresUneHeure(): void
    {
        $n = self::notification('machine_panne', '2026-10-19 10:00');
        self::assertNull(ReglesNotifications::remontee($n, new \DateTimeImmutable('2026-10-19 10:59'), self::ESCALADE));
        self::assertSame(2, ReglesNotifications::remontee($n, new \DateTimeImmutable('2026-10-19 11:00'), self::ESCALADE));
    }

    public function testPasDEscaladePourAFaireInfoReporteeOuResolue(): void
    {
        $tard = new \DateTimeImmutable('2026-10-19 18:00');
        self::assertNull(ReglesNotifications::remontee(self::notification('acompte_recu'), $tard, self::ESCALADE));
        self::assertNull(ReglesNotifications::remontee(self::notification('commande_prete'), $tard, self::ESCALADE));

        $reportee = self::notification('bat_retard')->reporter(new \DateTimeImmutable('2026-10-19 19:00'));
        self::assertNull(ReglesNotifications::remontee($reportee, $tard, self::ESCALADE));
        // À la fin du report, le délai repart de zéro.
        self::assertNull(ReglesNotifications::remontee($reportee, new \DateTimeImmutable('2026-10-19 19:20'), self::ESCALADE));
        self::assertSame(1, ReglesNotifications::remontee($reportee, new \DateTimeImmutable('2026-10-19 19:30'), self::ESCALADE));

        $resolue = self::notification('bat_retard')->resoudre(Notification::RESOLUE_AUTO, new \DateTimeImmutable('2026-10-19 10:05'));
        self::assertNull(ReglesNotifications::remontee($resolue, $tard, self::ESCALADE));
    }

    public function testEscaladeCoupeeAZero(): void
    {
        self::assertNull(ReglesNotifications::remontee(self::notification('bat_retard'), new \DateTimeImmutable('2026-10-20 10:00'), ['urgent_minutes' => 0, 'critique_minutes' => 0, 'direction_minutes' => 0]));
    }

    public function testFinDuReport(): void
    {
        $samedi = new \DateTimeImmutable('2026-10-24 16:00');
        self::assertEquals(new \DateTimeImmutable('2026-10-24 17:00'), ReglesNotifications::finReport('1h', $samedi));
        self::assertEquals(new \DateTimeImmutable('2026-10-24 19:00'), ReglesNotifications::finReport('3h', $samedi));
        self::assertEquals(new \DateTimeImmutable('2026-10-26 09:00'), ReglesNotifications::finReport('demain', $samedi), 'fermé le dimanche');
        self::assertEquals(new \DateTimeImmutable('2026-10-20 09:00'), ReglesNotifications::finReport('demain', new \DateTimeImmutable('2026-10-19 16:00')));
    }

    public function testOrdreCritiqueUrgentAFaireInfo(): void
    {
        $liste = [self::notification('commande_prete'), self::notification('acompte_recu'), self::notification('machine_panne'), self::notification('bat_retard')];
        usort($liste, ReglesNotifications::comparer(...));
        self::assertSame([Notification::CRITIQUE, Notification::URGENT, Notification::A_FAIRE, Notification::INFO], array_map(static fn (Notification $n): string => $n->getNiveau(), $liste));
    }

    public function testReglagesParDefautSmsCoupeWhatsAppCritiqueSeulement(): void
    {
        $r = ReglagesNotifications::lire([], ['ROLE_ADMIN' => [], 'ROLE_CAISSE_COMMANDE' => ['ROLE_PAIEMENT_VOIR'], 'ROLE_LIVREUR' => []]);
        foreach ($r['roles'] as $role => $types) {
            foreach ($types as $type => $canaux) {
                self::assertFalse($canaux['sms'], $role.' '.$type.' : SMS coupé par défaut');
                if (Notification::CRITIQUE !== TypesNotification::niveau($type)) {
                    self::assertFalse($canaux['whatsapp'], $role.' '.$type.' : WhatsApp pour le critique seulement');
                }
            }
        }
        self::assertTrue($r['roles']['ROLE_ADMIN']['machine_panne']['whatsapp']);
        self::assertTrue($r['roles']['ROLE_ADMIN']['machine_panne']['son']);
        self::assertTrue($r['roles']['ROLE_CAISSE_COMMANDE']['acompte_recu']['app']);
        self::assertFalse($r['roles']['ROLE_LIVREUR']['acompte_recu']['app'], 'chacun ne reçoit que les alertes de son rôle');
        self::assertSame(['debut' => '19:00', 'fin' => '07:30', 'jours' => [1, 2, 3, 4, 5, 6], 'jours_entiers' => [0]], $r['calme']);
        self::assertSame(30, $r['escalade']['urgent_minutes']);
        self::assertSame(60, $r['escalade']['critique_minutes']);
    }

    public function testSaisieCanauxPayantsRefusesHorsCritiqueEtSansApplicationRienNePart(): void
    {
        $r = ReglagesNotifications::lire([
            'formulaire' => '1',
            'roles' => ['ROLE_CAISSE_COMMANDE' => [
                '_' => '1',
                'acompte_recu' => ['app' => '1', 'whatsapp' => '1', 'sms' => '1'],
                'caisse_non_cloturee' => ['son' => '1', 'sms' => '1'],
                'synchro_coupee' => ['app' => '1', 'sms' => '1'],
            ]],
            'calme' => ['debut' => '20:00', 'fin' => '25:00', 'jours' => ['1', '9']],
            'escalade' => ['urgent_minutes' => '5000', 'responsables' => ['ROLE_CAISSE_COMMANDE' => '12', 'ROLE_INCONNU' => '3']],
        ], ['ROLE_CAISSE_COMMANDE' => []]);
        $caisse = $r['roles']['ROLE_CAISSE_COMMANDE'];
        self::assertSame(['app' => true, 'son' => false, 'whatsapp' => false, 'sms' => false, 'email' => false], $caisse['acompte_recu']);
        self::assertSame(['app' => false, 'son' => false, 'whatsapp' => false, 'sms' => false, 'email' => false], $caisse['caisse_non_cloturee']);
        self::assertTrue($caisse['synchro_coupee']['sms']);
        self::assertFalse($caisse['devis_relancer']['app'], 'case absente du formulaire : non');
        self::assertSame('20:00', $r['calme']['debut']);
        self::assertSame('07:30', $r['calme']['fin'], 'heure invalide : valeur par défaut');
        self::assertSame([1], $r['calme']['jours']);
        self::assertSame([], $r['calme']['jours_entiers']);
        self::assertSame(1440, $r['escalade']['urgent_minutes']);
        self::assertSame(['ROLE_CAISSE_COMMANDE' => 12], $r['escalade']['responsables']);
    }
}
