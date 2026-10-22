<?php

namespace App\Tests\Unit\Service;

use App\Service\AlertesEcranTv;
use App\Service\EcranTv;
use App\Service\ProgrammeEcranTv;
use PHPUnit\Framework\TestCase;

final class ProgrammeEcranTvTest extends TestCase
{
    public function testLeProgrammeDeDepartEstStable(): void
    {
        self::assertSame(ProgrammeEcranTv::defaut(), ProgrammeEcranTv::defaut());
        self::assertSame(['chiffres', 'photos', 'video', 'avis', 'partenaires', 'promo'], array_column(ProgrammeEcranTv::defaut()['scenes'], 'type'));
    }

    public function testNormaliserBorneLesValeursSaisies(): void
    {
        $programme = ProgrammeEcranTv::normaliser([
            'scenes' => [
                ['type' => 'inconnu'],
                ['id' => '../x', 'type' => 'video', 'duree' => 999, 'heureDebut' => '25:00', 'heureFin' => '19:00', 'dateDebut' => '2026-10-31', 'dateFin' => '2026-10-01', 'jours' => [6, 6, 9], 'medias' => ['../../etc/passwd', 'a.mp4', 'b.mp4']],
                ['type' => 'photos', 'duree' => 1, 'jours' => [1, 2, 3, 4, 5, 6, 7], 'actif' => '0'],
            ],
            'avis' => [['texte' => '  Super   travail ', 'note' => 9], ['texte' => '']],
            'noteGoogle' => '7',
            'horaires' => [1 => ['ouverture' => '19:00', 'fermeture' => '08:00'], 6 => ['ouverture' => '09:00', 'fermeture' => '13:00']],
            'horsHoraires' => 'nimporte',
        ]);

        self::assertCount(2, $programme['scenes']);
        [$video, $photos] = $programme['scenes'];
        self::assertMatchesRegularExpression('/^[a-z0-9]{8}$/', $video['id']);
        self::assertSame(ProgrammeEcranTv::DUREE_MAX, $video['duree']);
        self::assertNull($video['heureDebut']);
        self::assertSame('19:00', $video['heureFin']);
        self::assertSame(['2026-10-01', '2026-10-31'], [$video['dateDebut'], $video['dateFin']]);
        self::assertSame([6], $video['jours']);
        self::assertSame(['a.mp4'], $video['medias']);
        self::assertSame(ProgrammeEcranTv::DUREE_MIN, $photos['duree']);
        self::assertSame([], $photos['jours'], 'Les 7 jours cochés reviennent à « tous les jours ».');
        self::assertFalse($photos['actif']);

        self::assertSame([['texte' => 'Super travail', 'note' => 5, 'signature' => 'Avis Google · client vérifié']], $programme['avis']);
        self::assertSame(5.0, $programme['noteGoogle']);
        self::assertNull($programme['horaires'][1], 'Une fermeture avant l\'ouverture vaut « fermé ».');
        self::assertSame(['ouverture' => '09:00', 'fermeture' => '13:00'], $programme['horaires'][6]);
        self::assertSame(ProgrammeEcranTv::HORS_HORAIRES_FERME, $programme['horsHoraires']);
    }

    public function testAlertesSonoresParDefautEtBornees(): void
    {
        // Programme enregistré avant les alertes : carillon et voix, 80 %, silence de 20:00 à 07:30.
        self::assertSame([
            'son' => true, 'voix' => true, 'volume' => 80, 'silenceDebut' => '20:00', 'silenceFin' => '07:30',
            'evenements' => AlertesEcranTv::evenementsParDefaut(),
        ], ProgrammeEcranTv::normaliser([])['alertes']);

        $alertes = ProgrammeEcranTv::normaliser(['alertes' => [
            'son' => '0', 'voix' => '1', 'volume' => '250', 'silenceDebut' => '', 'silenceFin' => '31:00',
            'evenements' => ['paiement', 'inconnu', ['x'], 'commande_en_ligne', 'paiement'],
        ]])['alertes'];
        self::assertFalse($alertes['son']);
        self::assertTrue($alertes['voix']);
        self::assertSame(100, $alertes['volume']);
        self::assertNull($alertes['silenceDebut']);
        self::assertNull($alertes['silenceFin']);
        // Dans l'ordre des réglages, sans doublon ni inconnu.
        self::assertSame(['commande_en_ligne', 'paiement'], $alertes['evenements']);
        // Aucune case cochée : rien n'est annoncé.
        self::assertSame([], ProgrammeEcranTv::normaliser(['alertes' => ['evenements' => []]])['alertes']['evenements']);
        self::assertSame(0, ProgrammeEcranTv::normaliser(['alertes' => ['volume' => '-5']])['alertes']['volume']);
    }

    public function testUneSceneSuitSesHeuresSesDatesEtSesJours(): void
    {
        $scene = ProgrammeEcranTv::normaliser(['scenes' => [[
            'type' => 'promo', 'heureDebut' => '08:00', 'heureFin' => '19:00',
            'dateDebut' => '2026-10-01', 'dateFin' => '2026-10-31', 'jours' => [6],
        ]]])['scenes'][0];

        // Le 10/10/2026 est un samedi.
        self::assertTrue(ProgrammeEcranTv::estProgrammee($scene, new \DateTimeImmutable('2026-10-10 10:00')));
        self::assertFalse(ProgrammeEcranTv::estProgrammee($scene, new \DateTimeImmutable('2026-10-10 19:00')));
        self::assertFalse(ProgrammeEcranTv::estProgrammee($scene, new \DateTimeImmutable('2026-10-09 10:00')));
        self::assertFalse(ProgrammeEcranTv::estProgrammee($scene, new \DateTimeImmutable('2026-11-07 10:00')));
        self::assertFalse(ProgrammeEcranTv::estProgrammee(['actif' => false] + $scene, new \DateTimeImmutable('2026-10-10 10:00')));

        self::assertSame('01/10 → 31/10 · Sam. uniquement · 8 h – 19 h', ProgrammeEcranTv::resumeHoraire($scene));
        self::assertSame('Toujours', ProgrammeEcranTv::resumeHoraire(ProgrammeEcranTv::normaliser(['scenes' => [['type' => 'texte']]])['scenes'][0]));
    }

    public function testOuvertureEtReouverture(): void
    {
        $horaires = ProgrammeEcranTv::defaut()['horaires'];

        self::assertTrue(ProgrammeEcranTv::estOuvert($horaires, new \DateTimeImmutable('2026-10-10 08:00')));
        self::assertFalse(ProgrammeEcranTv::estOuvert($horaires, new \DateTimeImmutable('2026-10-10 19:00')));
        self::assertFalse(ProgrammeEcranTv::estOuvert($horaires, new \DateTimeImmutable('2026-10-11 10:00')));

        self::assertSame('Réouverture lundi à 8 h', ProgrammeEcranTv::prochaineOuverture($horaires, new \DateTimeImmutable('2026-10-10 20:00')));
        self::assertSame('Réouverture aujourd\'hui à 8 h', ProgrammeEcranTv::prochaineOuverture($horaires, new \DateTimeImmutable('2026-10-12 06:30')));
        self::assertSame('Réouverture demain à 8 h', ProgrammeEcranTv::prochaineOuverture($horaires, new \DateTimeImmutable('2026-10-12 21:00')));
        self::assertNull(ProgrammeEcranTv::prochaineOuverture(array_fill(1, 7, null), new \DateTimeImmutable('2026-10-12 21:00')));
    }

    public function testDureeDeLaBoucle(): void
    {
        self::assertSame(65, ProgrammeEcranTv::dureeBoucle(ProgrammeEcranTv::defaut()['scenes']), 'La promo de départ est coupée.');
        self::assertSame('8 h 30', ProgrammeEcranTv::heureLisible('08:30'));
    }

    public function testLibelleDuFluxSansNomNiDesignation(): void
    {
        self::assertSame('Nouvelle commande', EcranTv::libelleFlux([]));
        self::assertSame('Roll-up × 5', EcranTv::libelleFlux([['produit' => 'Roll-up', 'quantite' => 5]]));
        self::assertSame('Impression + 2 autres', EcranTv::libelleFlux([['produit' => null, 'quantite' => 1], ['produit' => 'A', 'quantite' => 1], ['produit' => 'B', 'quantite' => 1]]));
    }
}
