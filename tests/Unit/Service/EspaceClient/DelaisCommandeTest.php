<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Service\ActionsCaisse\HeuresTravail;
use App\Service\EspaceClient\DelaisCommande;
use PHPUnit\Framework\TestCase;

/** Date « prête le… », créneaux de livraison et acompte de la nouvelle commande (maquettes CO02, CO04). */
final class DelaisCommandeTest extends TestCase
{
    public function testLeDelaiPartAujourdhuiAvantLHeureLimite(): void
    {
        $heures = new HeuresTravail('lun-sam', feries: '');

        // Vendredi 16/10 à 10 h : 48 h = deux jours ouvrés (samedi, puis lundi, le dimanche est fermé).
        $standard = DelaisCommande::calculerPretLe($heures, 48, '16:00', new \DateTimeImmutable('2026-10-16 10:00'));
        self::assertSame('2026-10-16', $standard['depart']->format('Y-m-d'));
        self::assertSame('2026-10-19', $standard['pret']->format('Y-m-d'));
        // Express 24 h : samedi.
        self::assertSame('2026-10-17', DelaisCommande::calculerPretLe($heures, 24, '16:00', new \DateTimeImmutable('2026-10-16 10:00'))['pret']->format('Y-m-d'));
    }

    public function testApresLHeureLimiteOuUnJourFermeLeDelaiPartLeJourOuvreSuivant(): void
    {
        $heures = new HeuresTravail('lun-sam', feries: '');

        $tard = DelaisCommande::calculerPretLe($heures, 48, '16:00', new \DateTimeImmutable('2026-10-16 16:00'));
        self::assertSame('2026-10-17', $tard['depart']->format('Y-m-d'));
        self::assertSame('2026-10-20', $tard['pret']->format('Y-m-d'));

        $dimanche = DelaisCommande::calculerPretLe($heures, 24, '16:00', new \DateTimeImmutable('2026-10-18 09:00'));
        self::assertSame('2026-10-19', $dimanche['depart']->format('Y-m-d'));
        self::assertSame('2026-10-20', $dimanche['pret']->format('Y-m-d'));

        // Un jour férié ne compte pas : lundi 19/10 férié, prête le mardi.
        $ferie = DelaisCommande::calculerPretLe(new HeuresTravail('lun-sam', feries: '19/10'), 48, '16:00', new \DateTimeImmutable('2026-10-16 10:00'));
        self::assertSame('2026-10-20', $ferie['pret']->format('Y-m-d'));
    }

    public function testCreneauxDesTroisPremiersJoursOuvres(): void
    {
        $heures = new HeuresTravail('lun-sam', feries: '');

        // Prête un dimanche (jour fermé) : les créneaux commencent lundi.
        $creneaux = DelaisCommande::creneaux($heures, new \DateTimeImmutable('2026-10-18'));
        self::assertCount(6, $creneaux);
        self::assertSame('2026-10-19|matin', $creneaux[0]['valeur']);
        self::assertSame('lundi 19/10 · matin', $creneaux[0]['libelle']);
        self::assertSame('lundi 19/10 · après-midi', $creneaux[1]['libelle']);
        self::assertSame('mercredi 21/10 · après-midi', $creneaux[5]['libelle']);

        self::assertSame($creneaux[2], DelaisCommande::creneauChoisi('2026-10-20|matin', $creneaux));
        // Un créneau qui n'a pas été proposé (date passée, texte libre) est refusé.
        self::assertNull(DelaisCommande::creneauChoisi('2026-10-17|matin', $creneaux));
        self::assertNull(DelaisCommande::creneauChoisi('n\'importe quoi', $creneaux));
    }

    public function testQuandEnClair(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-16 10:00');

        self::assertSame('aujourd\'hui', DelaisCommande::quand(new \DateTimeImmutable('2026-10-16'), $maintenant));
        self::assertSame('demain', DelaisCommande::quand(new \DateTimeImmutable('2026-10-17'), $maintenant));
        self::assertSame('lundi 19/10', DelaisCommande::quand(new \DateTimeImmutable('2026-10-19'), $maintenant));
        self::assertSame('samedi 17/10', DelaisCommande::jour(new \DateTimeImmutable('2026-10-17')));
    }

    public function testAcompteArrondiAuFrancSuperieur(): void
    {
        self::assertSame(11500, DelaisCommande::acompte(23000, 50));
        self::assertSame(11501, DelaisCommande::acompte(23001, 50));
        self::assertSame(6900, DelaisCommande::acompte(23000, 30));
        self::assertSame(0, DelaisCommande::acompte(23000, 0));
        self::assertSame(23000, DelaisCommande::acompte(23000, 150), 'jamais plus que le total');
        self::assertSame(0, DelaisCommande::acompte(-500, 50));
    }
}
