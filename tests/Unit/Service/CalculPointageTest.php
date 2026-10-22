<?php

namespace App\Tests\Unit\Service;

use App\Service\Pointage\CalculPointage as C;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalculPointageTest extends TestCase
{
    private const REGLES = [
        'ouverture' => '08:00', 'fermeture' => '18:00', 'tolerance_minutes' => 10, 'anti_doublon_minutes' => 2,
        'pause_debut' => '13:00', 'pause_fin' => '14:00', 'alerte_depart' => '20:00',
        'points_heure' => 10, 'points_retard' => 10, 'points_soiree' => 30, 'points_nuit' => 100, 'points_oubli' => 20,
    ];

    private static function t(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s);
    }

    public function testArriveeDepartEtDoublon(): void
    {
        self::assertSame(C::ARRIVEE, C::decider(null, null, self::t('2026-10-10 07:52'), 2));
        self::assertSame(C::DOUBLON, C::decider(self::t('2026-10-10 07:52'), self::t('2026-10-10 07:52'), self::t('2026-10-10 07:53:30'), 2));
        self::assertSame(C::DEPART, C::decider(self::t('2026-10-10 07:52'), self::t('2026-10-10 07:52'), self::t('2026-10-10 18:06'), 2));
        // Nuit au bureau : le pointage du lendemain matin ferme la présence de la veille.
        self::assertSame(C::DEPART, C::decider(self::t('2026-10-10 07:52'), self::t('2026-10-10 07:52'), self::t('2026-10-11 07:40'), 2));
        // Au-delà de 26 h, la présence reste à régulariser et une nouvelle commence.
        self::assertSame(C::ARRIVEE, C::decider(self::t('2026-10-09 07:52'), self::t('2026-10-09 07:52'), self::t('2026-10-10 10:00'), 2));
    }

    public function testExempleDeLaMaquette(): void
    {
        $r = C::presence(self::t('2026-10-10 07:52'), self::t('2026-10-10 18:06'), self::REGLES, self::t('2026-10-10 18:06'));

        self::assertSame(554, $r['presence'], '10 h 14 − 1 h de pause = 9 h 14');
        self::assertSame('9 h 14', C::duree($r['presence']));
        self::assertSame(60, $r['pause']);
        self::assertSame(0, $r['retard']);
        self::assertSame(C::STATUT_COMPLET, $r['statut']);
        self::assertSame(10, $r['total']);
    }

    public function testRetardAuDelaDeLaTolerance(): void
    {
        $r = C::presence(self::t('2026-10-10 08:18'), self::t('2026-10-10 18:02'), self::REGLES, self::t('2026-10-10 19:00'));
        self::assertSame(18, $r['retard']);
        self::assertSame(C::STATUT_RETARD, $r['statut']);
        self::assertSame(-10, $r['total']);

        $tolere = C::presence(self::t('2026-10-10 08:09'), self::t('2026-10-10 18:00'), self::REGLES, self::t('2026-10-10 19:00'));
        self::assertSame(0, $tolere['retard']);
        self::assertSame(10, $tolere['total']);
    }

    public function testBonusSoireeSeulementApresValidation(): void
    {
        $sansValidation = C::presence(self::t('2026-10-10 07:40'), self::t('2026-10-10 19:12'), self::REGLES, self::t('2026-10-10 20:00'));
        self::assertSame(72, $sansValidation['sup']);
        self::assertSame(C::STATUT_HEURES_SUP, $sansValidation['statut']);
        self::assertSame(10, $sansValidation['total']);
        self::assertNotNull($sansValidation['bonusEnAttente']);

        $valide = C::presence(self::t('2026-10-10 07:40'), self::t('2026-10-10 19:12'), self::REGLES, self::t('2026-10-10 20:00'), true);
        self::assertSame(40, $valide['total']);
    }

    public function testNuitAuBureauUnSeulBonus(): void
    {
        $r = C::presence(self::t('2026-10-10 07:50'), self::t('2026-10-11 08:10'), self::REGLES, self::t('2026-10-11 09:00'), true);
        self::assertSame(110, $r['total'], '+10 à l\'heure, +100 nuit, pas de +30 en plus');
    }

    public function testDepartNonPointe(): void
    {
        $avant = C::presence(self::t('2026-10-10 08:00'), null, self::REGLES, self::t('2026-10-10 17:00'));
        self::assertSame(C::STATUT_EN_COURS, $avant['statut']);
        self::assertSame(10, $avant['total']);

        $apres = C::presence(self::t('2026-10-10 08:00'), null, self::REGLES, self::t('2026-10-10 20:01'));
        self::assertSame(C::STATUT_NON_POINTE, $apres['statut']);
        self::assertSame(-10, $apres['total'], '+10 − 20');

        $corrige = C::presence(self::t('2026-10-10 08:00'), null, self::REGLES, self::t('2026-10-10 20:01'), false, 20);
        self::assertSame(10, $corrige['total']);
    }

    public function testNoteSur100(): void
    {
        self::assertSame(100, C::note(220, 22));
        self::assertSame(91, C::note(200, 22));
        self::assertSame(95, C::note(210, 22));
        self::assertSame(100, C::note(400, 22));
        self::assertSame(0, C::note(-50, 22));
        self::assertNull(C::note(0, 0));
    }

    public function testJourDeReposSansRetardEtToutEnHeuresSup(): void
    {
        // Dimanche 11/10/2026, semaine lun-sam.
        $r = C::presence(self::t('2026-10-11 10:00'), self::t('2026-10-11 12:30'), self::REGLES + ['jours' => 'lun-sam'], self::t('2026-10-11 13:00'));

        self::assertSame(0, $r['retard']);
        self::assertSame(150, $r['sup']);
        self::assertSame(C::STATUT_HEURES_SUP, $r['statut']);
        self::assertSame(0, $r['total'], 'pas de point de ponctualité, bonus à valider');
        self::assertNotNull($r['bonusEnAttente']);
    }

    public function testPosteDeNuit(): void
    {
        // Gardien (E03) : 19 h – 7 h, la pause de midi ne le concerne pas.
        $regles = ['ouverture' => '19:00', 'fermeture' => '07:00', 'alerte_depart' => '09:00'] + self::REGLES;
        $r = C::presence(self::t('2026-10-12 18:50'), self::t('2026-10-13 07:04'), $regles, self::t('2026-10-13 08:00'));

        self::assertSame(0, $r['retard']);
        self::assertSame(0, $r['pause']);
        self::assertSame(734, $r['presence'], '12 h 14');
        self::assertSame(4, $r['sup']);
        self::assertSame(C::STATUT_COMPLET, $r['statut']);

        // Encore en poste à 3 h du matin : pas d'alerte « départ non pointé ».
        $r = C::presence(self::t('2026-10-12 18:50'), null, $regles, self::t('2026-10-13 03:00'));
        self::assertSame(C::STATUT_EN_COURS, $r['statut']);
    }

    /** Nouveau barème « heures faites » (tuile Points du pointage, valeurs par défaut). */
    private const POINTS = [
        'accueil_debut' => '09:00', 'accueil_fin' => '17:00', 'atelier_debut' => '09:00', 'atelier_fin' => '18:00',
        'points_heures_faites' => 10, 'points_retard' => 15, 'points_heure_en_plus' => 5, 'longue_heures' => 20, 'points_longue' => 50,
        'pause_deduite' => false, 'valider_en_plus' => true, 'debut' => '2026-10-01',
    ];

    /**
     * Règles d'une journée du nouveau barème : horaire atelier (9 h – 18 h)
     * ou accueil (9 h – 17 h).
     *
     * @param array<string, mixed> $points
     *
     * @return array<string, mixed>
     */
    private static function v2(string $debut = '09:00', string $fin = '18:00', array $points = []): array
    {
        return ['ouverture' => $debut, 'fermeture' => $fin, 'jours' => 'lun-sam', 'points' => $points + self::POINTS] + self::REGLES;
    }

    /** @param array<string, mixed> $regles */
    private static function jour(string $arrivee, ?string $depart, array $regles, bool $valide = false, string $maintenant = '2026-10-17 23:00', int $ajustement = 0): array
    {
        return C::presence(self::t($arrivee), $depart !== null ? self::t($depart) : null, $regles, self::t($maintenant), $valide, $ajustement);
    }

    public function testHeuresFaitesPileAtelierEtAccueil(): void
    {
        // Vendredi 16/10/2026, atelier 9 h – 18 h : 9 h dues, faites pile.
        $atelier = self::jour('2026-10-16 09:00', '2026-10-16 18:00', self::v2());
        self::assertSame('heures', $atelier['bareme']);
        self::assertSame(540, $atelier['dues']);
        self::assertSame(540, $atelier['faites']);
        self::assertSame(10, $atelier['total']);
        self::assertSame('À l\'heure, heures faites (9 h 00 sur 9 h 00).', $atelier['raison']);
        self::assertNull($atelier['bonusEnAttente']);

        // Accueil et caisse 9 h – 17 h : 8 h dues.
        $accueil = self::jour('2026-10-16 09:00', '2026-10-16 17:00', self::v2('09:00', '17:00'));
        self::assertSame(480, $accueil['dues']);
        self::assertSame(10, $accueil['total']);

        // Même journée 9 h – 17 h pour l'atelier : à l'heure mais parti avant la fin, −15 (Yaya, 18/10/2026).
        $tot = self::jour('2026-10-16 09:00', '2026-10-16 17:00', self::v2());
        self::assertSame(-15, $tot['total']);
        self::assertSame('Parti avant la fin, heures pas faites', $tot['points'][0]['libelle']);
        self::assertStringStartsWith('À l\'heure mais parti avant la fin (8 h 00 sur 9 h 00)', $tot['raison']);
    }

    public function testRetardRattrapeOuPas(): void
    {
        // 30 min de retard, parti à 18 h 30 : les 9 h sont faites, +10.
        $rattrape = self::jour('2026-10-16 09:30', '2026-10-16 18:30', self::v2());
        self::assertSame(30, $rattrape['retard']);
        self::assertSame(C::STATUT_RETARD, $rattrape['statut']);
        self::assertSame(10, $rattrape['total']);
        self::assertSame('Retard de 30 min rattrapé : heures faites (9 h 00 sur 9 h 00).', $rattrape['raison']);

        // 30 min de retard, parti à 18 h : 8 h 30 sur 9 h, −15.
        $pasFait = self::jour('2026-10-16 09:30', '2026-10-16 18:00', self::v2());
        self::assertSame(-15, $pasFait['total']);
        self::assertSame('Retard de 30 min et heures pas faites (8 h 30 sur 9 h 00).', $pasFait['raison']);
    }

    public function testToleranceSertDeMargeSurLesHeures(): void
    {
        // 8 min de retard (tolérance 10 min) et départ à 18 h : 8 h 52, compté fait.
        $r = self::jour('2026-10-16 09:08', '2026-10-16 18:00', self::v2());
        self::assertSame(0, $r['retard']);
        self::assertSame(10, $r['total']);

        // Parti 11 min avant la fin : au-delà de la marge, −15.
        self::assertSame(-15, self::jour('2026-10-16 09:00', '2026-10-16 17:49', self::v2())['total']);
    }

    public function testHeuresEnPlusParHeureEntiere(): void
    {
        // 59 min en plus : pas d'heure entière, pas de points en plus.
        $r = self::jour('2026-10-16 09:00', '2026-10-16 18:59', self::v2());
        self::assertSame(10, $r['total']);
        self::assertNull($r['bonusEnAttente']);

        // 2 h 10 en plus : +10 à valider par la RH, puis acquis.
        $r = self::jour('2026-10-16 08:50', '2026-10-16 20:00', self::v2());
        self::assertSame(10, $r['total']);
        self::assertSame('2 h en plus (+10) à valider', $r['bonusEnAttente']);
        self::assertStringContainsString('2 h en plus : +10 une fois les heures validées par la RH', $r['raison']);

        $valide = self::jour('2026-10-16 08:50', '2026-10-16 20:00', self::v2(), true);
        self::assertSame(20, $valide['total']);
        self::assertSame(['Heures faites', '2 h en plus'], array_column($valide['points'], 'libelle'));

        // Sans validation demandée (réglage), acquis tout de suite.
        $direct = self::jour('2026-10-16 08:50', '2026-10-16 20:00', self::v2(points: ['valider_en_plus' => false]));
        self::assertSame(20, $direct['total']);
        self::assertNull($direct['bonusEnAttente']);
    }

    public function testDepartApresMinuit(): void
    {
        // 9 h → 1 h 30 le lendemain : 16 h 30 faites, 7 h entières en plus.
        $r = self::jour('2026-10-16 09:00', '2026-10-17 01:30', self::v2(), true);
        self::assertSame(990, $r['faites']);
        self::assertSame(45, $r['total'], '+10 et 7 × 5');
    }

    public function testGardeDe24hEtLongueJournee(): void
    {
        $avant = self::jour('2026-10-16 09:00', '2026-10-17 09:00', self::v2());
        self::assertSame(10, $avant['total'], 'heures faites acquises, la longue journée attend la validation');
        self::assertSame('Longue journée de 24 h 00 : 50 points pour la journée (au lieu de 10) à valider', $avant['bonusEnAttente']);

        $garde = self::jour('2026-10-16 09:00', '2026-10-17 09:00', self::v2(), true);
        self::assertSame(50, $garde['total'], '50 remplace le calcul du jour');
        self::assertSame([['libelle' => 'Longue journée de 24 h 00', 'points' => 50]], $garde['points']);
        self::assertSame('À l\'heure, heures faites (24 h 00 sur 9 h 00) ; longue journée de 24 h 00 : 50 points pour la journée.', $garde['raison']);

        // Pile 20 h : longue journée ; 19 h 59 : heures en plus, plafonnées
        // au total d'une longue journée (jamais plus que 20 h).
        self::assertSame(50, self::jour('2026-10-16 09:00', '2026-10-17 05:00', self::v2(), true)['total']);
        $presque = self::jour('2026-10-16 09:00', '2026-10-17 04:59', self::v2(), true);
        self::assertSame(50, $presque['total'], '+10 et 10 h en plus plafonnées à +40');
        self::assertSame([['libelle' => 'Heures faites', 'points' => 10], ['libelle' => '10 h en plus (plafond de la journée)', 'points' => 40]], $presque['points']);

        // À 10 points l'heure en plus, 10 h donneraient +100 : la journée
        // reste à 50.
        $plafond = self::jour('2026-10-16 09:00', '2026-10-17 04:59', self::v2(points: ['points_heure_en_plus' => 10]), true);
        self::assertSame(50, $plafond['total']);
    }

    public function testPosteDeNuitAvecLeNouveauBareme(): void
    {
        // Gardien 19 h – 7 h (horaires propres) : 12 h dues, la pause de midi ne le concerne pas.
        $r = self::jour('2026-10-12 18:55', '2026-10-13 07:05', ['alerte_depart' => '09:00'] + self::v2('19:00', '07:00'), maintenant: '2026-10-13 08:00');
        self::assertSame(720, $r['dues']);
        self::assertSame(725, $r['faites'], 'compté à partir de 19 h');
        self::assertSame(5, $r['avance']);
        self::assertSame(10, $r['total']);
    }

    public function testArriveeAvantLeDebutSignaleeSansTempsEnPlus(): void
    {
        // 7 h – 18 h (atelier 9 h – 18 h) : le temps compte à partir de 9 h, pas d'heures en plus.
        $r = self::jour('2026-10-16 07:00', '2026-10-16 18:00', self::v2(), true);
        self::assertSame(540, $r['faites']);
        self::assertSame(120, $r['avance']);
        self::assertSame('09:00', $r['debutPrevu']);
        self::assertSame(10, $r['total']);
        self::assertStringStartsWith('Arrivé à 7 h, avant 9 h : le temps compte à partir de 9 h. À l\'heure, heures faites', $r['raison']);

        // 8 h – 17 h : arrivé tôt mais parti avant la fin, −15.
        self::assertSame(-15, self::jour('2026-10-16 08:00', '2026-10-16 17:00', self::v2())['total']);

        // À l'heure pile : pas de mention.
        self::assertSame(0, self::jour('2026-10-16 09:00', '2026-10-16 18:00', self::v2())['avance']);
    }

    public function testSansHeureFixe(): void
    {
        // Livreur : ni retard ni départ anticipé, la journée pointée vaut +10, pas d'heures en plus.
        $regles = ['libre' => true] + self::v2();
        $tard = self::jour('2026-10-16 11:20', '2026-10-16 15:00', $regles, true);
        self::assertSame(0, $tard['retard']);
        self::assertSame(0, $tard['dues']);
        self::assertSame(10, $tard['total']);
        self::assertSame('Sans heure fixe', $tard['horaire']);
        self::assertSame(0, $tard['avance']);

        $long = self::jour('2026-10-16 07:00', '2026-10-16 21:00', $regles, true);
        self::assertSame(10, $long['total'], 'pas d\'heures en plus sans heure fixe');
        self::assertSame(0, $long['avance']);
        self::assertSame(0, $long['sup']);
    }

    public function testPauseRetireeDesHeures(): void
    {
        $regles = self::v2(points: ['pause_deduite' => true]);
        $r = self::jour('2026-10-16 09:00', '2026-10-16 18:00', $regles);
        self::assertSame(480, $r['dues'], '9 h – 18 h moins la pause de 13 h à 14 h');
        self::assertSame(480, $r['faites']);
        self::assertSame(10, $r['total']);

        // Arrivé après la pause : rien à retirer de ses heures.
        $apres = self::jour('2026-10-16 14:00', '2026-10-16 22:00', $regles);
        self::assertSame(480, $apres['faites']);
    }

    public function testDepartNonPointeSansPoints(): void
    {
        $enCours = self::jour('2026-10-16 09:20', null, self::v2(), maintenant: '2026-10-16 15:00');
        self::assertSame(C::STATUT_EN_COURS, $enCours['statut']);
        self::assertSame(0, $enCours['total']);
        self::assertSame('Retard de 20 min, en cours : les points sont comptés au départ.', $enCours['raison']);

        $oubli = self::jour('2026-10-16 09:00', null, self::v2(), maintenant: '2026-10-16 20:01');
        self::assertSame(C::STATUT_NON_POINTE, $oubli['statut']);
        self::assertSame(0, $oubli['total'], 'pas de −20 : la journée est à corriger');
        self::assertStringStartsWith('Départ non pointé', $oubli['raison']);

        // L'ajustement de la RH reste possible.
        self::assertSame(10, self::jour('2026-10-16 09:00', null, self::v2(), maintenant: '2026-10-16 20:01', ajustement: 10)['total']);
    }

    public function testJourDeReposAvecLeNouveauBareme(): void
    {
        // Dimanche : pas d'heures dues, seules les heures en plus comptent.
        $r = self::jour('2026-10-18 10:00', '2026-10-18 13:30', self::v2(), true);
        self::assertSame(0, $r['dues']);
        self::assertSame(15, $r['total'], '3 h × 5');
        self::assertSame('Jour de repos travaillé ; 3 h en plus : +15.', $r['raison']);

        $court = self::jour('2026-10-18 10:00', '2026-10-18 10:45', self::v2(), true);
        self::assertSame(0, $court['total']);
        self::assertSame('Jour de repos travaillé ; moins d\'une heure, pas de points.', $court['raison']);
    }

    public function testLesReglagesChangentLeCalcul(): void
    {
        $regles = self::v2(points: ['points_heures_faites' => 20, 'points_retard' => 5, 'points_heure_en_plus' => 10, 'longue_heures' => 16, 'points_longue' => 80]);

        self::assertSame(20, self::jour('2026-10-16 09:00', '2026-10-16 18:00', $regles)['total']);
        self::assertSame(-5, self::jour('2026-10-16 09:30', '2026-10-16 18:00', $regles)['total']);
        self::assertSame(40, self::jour('2026-10-16 09:00', '2026-10-16 20:00', $regles, true)['total'], '+20 et 2 × 10');
        self::assertSame(80, self::jour('2026-10-16 09:00', '2026-10-17 01:00', $regles, true)['total'], '16 h : longue journée');

        // Horaire atelier avancé à 8 h : 9 h 00 devient un retard d'une heure.
        $huit = self::jour('2026-10-16 09:00', '2026-10-16 18:00', self::v2('08:00', '18:00'));
        self::assertSame(60, $huit['retard']);
        self::assertSame(-15, $huit['total']);
    }

    public function testJoursComptesDansLaNote(): void
    {
        $fini = self::jour('2026-10-16 09:00', '2026-10-16 18:00', self::v2());
        $enCours = self::jour('2026-10-16 09:00', null, self::v2(), maintenant: '2026-10-16 12:00');
        $oubli = self::jour('2026-10-16 09:00', null, self::v2(), maintenant: '2026-10-16 21:00');
        $ancien = C::presence(self::t('2026-10-10 08:00'), null, self::REGLES, self::t('2026-10-10 12:00'));

        self::assertTrue(C::compteDansLaNote(true, [$fini]));
        self::assertTrue(C::compteDansLaNote(true, [$oubli]), 'départ non pointé : la journée compte, sans points');
        self::assertFalse(C::compteDansLaNote(true, [$enCours]), 'la journée en cours n\'abaisse pas la note');
        self::assertFalse(C::compteDansLaNote(false, [$fini]), 'jour de repos travaillé : ses points s\'ajoutent seulement');
        self::assertFalse(C::compteDansLaNote(true, []));
        self::assertTrue(C::compteDansLaNote(false, [$ancien]), 'ancien barème : toute journée pointée, comme avant');

        // 21 journées faites (+10) et une journée à −15 : (210 − 15) ÷ 220 = 89 ; un dimanche de 3 h en plus (+15) remonte à 95.
        self::assertSame(89, C::note(195, 22));
        self::assertSame(95, C::note(210, 22));
    }

    /**
     * Présences d'une journée (sortie à midi puis retour), heures validées.
     *
     * @param list<array{0: string, 1: ?string}> $presences
     * @param array<string, mixed>               $regles
     *
     * @return list<array<string, mixed>>
     */
    private static function journee(array $presences, array $regles, string $maintenant = '2026-10-17 23:00'): array
    {
        return C::journee(array_map(static fn (array $p): array => ['arrivee' => self::t($p[0]), 'depart' => $p[1] !== null ? self::t($p[1]) : null, 'valide' => true], $presences), $regles, self::t($maintenant));
    }

    public function testSortieAMidiPuisRetourUneSeuleJournee(): void
    {
        // Atelier : 9 h – 13 h, sortie pour la pause, 14 h – 18 h. La pause compte dans les heures : 9 h faites, +10.
        [$matin, $apres] = self::journee([['2026-10-16 09:00', '2026-10-16 13:00'], ['2026-10-16 14:00', '2026-10-16 18:00']], self::v2());
        self::assertSame(0, $matin['total']);
        self::assertFalse($matin['journee']);
        self::assertSame(240, $matin['faites']);
        self::assertSame('Sortie à 13 h, puis retour : les heures de la journée s\'additionnent et les points se comptent au dernier départ.', $matin['raison']);
        self::assertSame(0, $apres['retard'], 'le retour n\'est pas un retard');
        self::assertSame(C::STATUT_COMPLET, $apres['statut']);
        self::assertTrue($apres['journee']);
        self::assertSame(540, $apres['faites']);
        self::assertSame(10, $apres['total']);
        self::assertSame('À l\'heure, heures faites (9 h 00 sur 9 h 00, présences du jour additionnées).', $apres['raison']);

        // Pause retirée des heures : 8 h dues, 4 h + 4 h faites.
        [, $retiree] = self::journee([['2026-10-16 09:00', '2026-10-16 13:00'], ['2026-10-16 14:00', '2026-10-16 18:00']], self::v2(points: ['pause_deduite' => true]));
        self::assertSame([480, 480, 10], [$retiree['faites'], $retiree['dues'], $retiree['total']]);

        // Retard de 40 min le matin, pas rattrapé le soir : −15 une seule fois, sur la journée.
        [$tard, $soir] = self::journee([['2026-10-16 09:40', '2026-10-16 13:00'], ['2026-10-16 14:00', '2026-10-16 18:00']], self::v2());
        self::assertSame(40, $tard['retard']);
        self::assertSame(0, $tard['total']);
        self::assertSame(-15, $soir['total']);
        self::assertSame('Retard de 40 min et heures pas faites (8 h 20 sur 9 h 00, présences du jour additionnées).', $soir['raison']);

        // Partie de 11 h à 15 h : seule l'heure de pause dehors compte, 6 h sur 9 h, à l'heure mais parti avant la fin : −15.
        [, $longue] = self::journee([['2026-10-16 09:00', '2026-10-16 11:00'], ['2026-10-16 15:00', '2026-10-16 18:00']], self::v2());
        self::assertSame([360, -15], [$longue['faites'], $longue['total']]);

        // Heures en plus et longue journée sur le total du jour.
        [, , $nuit] = self::journee([['2026-10-16 09:00', '2026-10-16 13:00'], ['2026-10-16 13:30', '2026-10-16 20:00'], ['2026-10-16 20:30', '2026-10-17 05:30']], self::v2());
        self::assertSame(1200, $nuit['faites'], '4 h + 30 min de pause + 6 h 30 + 9 h = 20 h');
        self::assertSame(50, $nuit['total'], '20 h dans la journée : longue journée');
    }

    public function testRetourEnCoursEtNote(): void
    {
        // Revenu à 14 h, encore là à 15 h : rien n'est compté, la journée n'abaisse pas la note.
        $jour = self::journee([['2026-10-16 09:00', '2026-10-16 13:00'], ['2026-10-16 14:00', null]], self::v2(), '2026-10-16 15:00');
        self::assertSame([0, 0], array_column($jour, 'total'));
        self::assertSame(C::STATUT_EN_COURS, $jour[1]['statut']);
        self::assertFalse(C::compteDansLaNote(true, $jour));

        // Pas revenu : la présence du matin porte la journée (parti avant la fin), la journée compte.
        $seul = self::journee([['2026-10-16 09:00', '2026-10-16 13:00']], self::v2());
        self::assertTrue($seul[0]['journee']);
        self::assertTrue(C::compteDansLaNote(true, $seul));
    }

    public function testAncienBaremePresenceParPresence(): void
    {
        // Avant la date de début, chaque présence garde son calcul : le retour à 14 h est un retard.
        [$matin, $apres] = self::journee([['2026-10-10 08:00', '2026-10-10 13:00'], ['2026-10-10 14:00', '2026-10-10 18:00']], self::REGLES);
        self::assertSame(10, $matin['total']);
        self::assertSame(-10, $apres['total']);
        self::assertTrue($matin['journee']);
    }

    public function testAncienBaremeSansLaTuile(): void
    {
        $r = C::presence(self::t('2026-10-10 08:18'), self::t('2026-10-10 18:02'), self::REGLES, self::t('2026-10-10 19:00'));
        self::assertSame('ancien', $r['bareme']);
        self::assertNull($r['dues']);
        self::assertSame('Retard de 18 min', $r['raison']);
    }

    /**
     * Le tableau d'exemples de docs/pointage.md, « Calcul des points »
     * (heures validées par la RH).
     *
     * @return iterable<string, array{string, string, ?string, string, int, ?int, int}>
     */
    public static function exemplesDeLaDoc(): iterable
    {
        yield 'à l\'heure' => ['09:00-18:00', '2026-10-16 08:55', '2026-10-16 18:05', 'Heures faites', 545, 540, 10];
        yield 'retard rattrapé' => ['09:00-18:00', '2026-10-16 09:40', '2026-10-16 18:45', 'Retard de 40 min rattrapé', 545, 540, 10];
        yield 'retard pas rattrapé' => ['09:00-18:00', '2026-10-16 09:40', '2026-10-16 18:00', 'heures pas faites', 500, 540, -15];
        yield 'parti avant la fin' => ['09:00-18:00', '2026-10-16 08:58', '2026-10-16 16:00', 'Parti avant la fin', 420, 540, -15];
        yield '59 min en plus' => ['09:00-18:00', '2026-10-16 09:00', '2026-10-16 18:59', 'Heures faites', 599, 540, 10];
        yield 'accueil, 2 h en plus' => ['09:00-17:00', '2026-10-16 09:00', '2026-10-16 19:30', '2 h en plus', 630, 480, 20];
        yield 'après minuit' => ['09:00-18:00', '2026-10-16 09:00', '2026-10-17 02:30', '8 h en plus', 1050, 540, 50];
        yield 'garde de 24 h' => ['09:00-18:00', '2026-10-16 08:00', '2026-10-17 08:00', 'Longue journée', 1380, 540, 50];
        yield 'gardien de nuit' => ['19:00-07:00', '2026-10-16 19:00', '2026-10-17 07:05', 'Heures faites', 725, 720, 10];
        yield 'dimanche de repos' => ['09:00-18:00', '2026-10-18 09:00', '2026-10-18 13:30', '4 h en plus', 270, 0, 20];
        yield 'départ non pointé' => ['09:00-18:00', '2026-10-16 09:00', null, '', 0, null, 0];
    }

    #[DataProvider('exemplesDeLaDoc')]
    public function testExemplesDeLaDocumentation(string $horaire, string $arrivee, ?string $depart, string $libelle, int $faites, ?int $dues, int $total): void
    {
        [$debut, $fin] = explode('-', $horaire);
        $r = self::jour($arrivee, $depart, self::v2($debut, $fin), true, '2026-10-19 12:00');

        self::assertSame($total, $r['total']);
        if ($depart === null) {
            self::assertSame(C::STATUT_NON_POINTE, $r['statut']);
            self::assertNull($r['faites']);

            return;
        }
        self::assertSame($faites, $r['faites']);
        self::assertSame($dues, $r['dues']);
        self::assertStringContainsString($libelle, implode(' · ', array_column($r['points'], 'libelle')));
    }

    public function testJoursPropresAUnEmploye(): void
    {
        self::assertSame([1, 3, 4, 5, 6, 7], C::joursOuvres('1,3,4,5,6,7'));
        self::assertSame([1, 2, 3, 4, 5, 6], C::joursOuvres('lun-sam'));
    }
}
