<?php

namespace App\Tests\Unit\Service;

use App\Service\AlertesEcranTv;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Alertes sonores de l'écran TV : des phrases fixes (jamais un nom, un
 * montant ni un numéro), regroupées, et des heures de silence.
 */
final class AlertesEcranTvTest extends TestCase
{
    public function testPhrasesDemandeesParYaya(): void
    {
        self::assertSame('Nouvelle commande en ligne disponible', AlertesEcranTv::phrase(AlertesEcranTv::COMMANDE_EN_LIGNE, 1));
        self::assertSame('Nouvelle commande disponible', AlertesEcranTv::phrase(AlertesEcranTv::COMMANDE, 1));
        self::assertSame('Nouvelle demande de devis', AlertesEcranTv::phrase(AlertesEcranTv::DEMANDE_DEVIS, 1));
    }

    public function testUneRafaleEstRegroupee(): void
    {
        self::assertSame('Trois nouvelles commandes disponibles', AlertesEcranTv::phrase(AlertesEcranTv::COMMANDE, 3));
        self::assertSame('Deux nouvelles commandes en ligne disponibles', AlertesEcranTv::phrase(AlertesEcranTv::COMMANDE_EN_LIGNE, 2));
        self::assertSame('Deux nouveaux paiements à valider', AlertesEcranTv::phrase(AlertesEcranTv::PAIEMENT, 2));
        self::assertSame('Dix devis acceptés par des clients', AlertesEcranTv::phrase(AlertesEcranTv::DEVIS_ACCEPTE, 10));
        self::assertSame('Plus de dix nouvelles demandes de conception', AlertesEcranTv::phrase(AlertesEcranTv::DEMANDE_CONCEPTION, 25));
        self::assertSame('Quatre commandes sont prêtes à retirer', AlertesEcranTv::phrase(AlertesEcranTv::COMMANDE_PRETE, 4));

        $phrases = AlertesEcranTv::phrases([AlertesEcranTv::RECLAMATION]);
        self::assertSame(['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', 'plus'], array_map('strval', array_keys($phrases[AlertesEcranTv::RECLAMATION])));
        self::assertSame('Nouvelle réclamation d\'un client', $phrases[AlertesEcranTv::RECLAMATION]['1']);
        self::assertSame('Cinq nouvelles réclamations de clients', $phrases[AlertesEcranTv::RECLAMATION]['5']);
    }

    public function testJamaisDeChiffreDeNomNiDeMontantDansLesPhrases(): void
    {
        foreach (array_keys(AlertesEcranTv::TYPES) as $type) {
            foreach (AlertesEcranTv::phrases([$type])[$type] as $phrase) {
                self::assertDoesNotMatchRegularExpression('/\d|%|\{|F\b|FCFA/', $phrase, $phrase);
            }
        }
    }

    public function testEvenementsParDefaut(): void
    {
        $defaut = AlertesEcranTv::evenementsParDefaut();
        self::assertContains(AlertesEcranTv::COMMANDE_EN_LIGNE, $defaut);
        self::assertContains(AlertesEcranTv::COMMANDE, $defaut);
        self::assertContains(AlertesEcranTv::PAIEMENT, $defaut);
        // L'équipe met elle-même une commande « prête » : pas annoncé par défaut.
        self::assertNotContains(AlertesEcranTv::COMMANDE_PRETE, $defaut);
    }

    public function testHeuresDeSilence(): void
    {
        $a = static fn (string $heure): \DateTimeImmutable => new \DateTimeImmutable('2026-10-15 '.$heure);

        // Par-dessus minuit : 20:00 à 07:30.
        self::assertTrue(AlertesEcranTv::enSilence('20:00', '07:30', $a('20:00')));
        self::assertTrue(AlertesEcranTv::enSilence('20:00', '07:30', $a('23:59')));
        self::assertTrue(AlertesEcranTv::enSilence('20:00', '07:30', $a('03:10')));
        self::assertFalse(AlertesEcranTv::enSilence('20:00', '07:30', $a('07:30')));
        self::assertFalse(AlertesEcranTv::enSilence('20:00', '07:30', $a('12:00')));
        self::assertFalse(AlertesEcranTv::enSilence('20:00', '07:30', $a('19:59')));
        // Dans la journée : 12:00 à 14:00.
        self::assertTrue(AlertesEcranTv::enSilence('12:00', '14:00', $a('13:00')));
        self::assertFalse(AlertesEcranTv::enSilence('12:00', '14:00', $a('14:00')));
        // Heure vide ou heures égales : jamais.
        self::assertFalse(AlertesEcranTv::enSilence(null, '07:30', $a('03:00')));
        self::assertFalse(AlertesEcranTv::enSilence('08:00', '08:00', $a('08:00')));
    }

    public function testJetonsOpaquesEtStables(): void
    {
        $alertes = new AlertesEcranTv($this->createStub(EntityManagerInterface::class), 'secret-de-test');
        $autre = new AlertesEcranTv($this->createStub(EntityManagerInterface::class), 'autre-secret');

        $jeton = $alertes->jeton(AlertesEcranTv::COMMANDE, 42);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $jeton);
        self::assertSame($jeton, $alertes->jeton(AlertesEcranTv::COMMANDE, 42));
        self::assertStringNotContainsString('42', $jeton);
        self::assertNotSame($jeton, $alertes->jeton(AlertesEcranTv::COMMANDE, 43));
        self::assertNotSame($jeton, $alertes->jeton(AlertesEcranTv::PAIEMENT, 42));
        self::assertNotSame($jeton, $autre->jeton(AlertesEcranTv::COMMANDE, 42));
    }

    public function testRienAInterrogerSansEvenement(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('createQueryBuilder');
        $etat = (new AlertesEcranTv($em, 's'))->etat(['evenements' => [], 'silenceDebut' => '20:00', 'silenceFin' => '07:30'], new \DateTimeImmutable('2026-10-15 21:00'));

        self::assertSame(['silence' => true, 'jetons' => []], $etat);
    }
}
