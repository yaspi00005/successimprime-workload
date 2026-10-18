<?php

namespace App\Tests\Unit\Entity;

use App\Entity\CommandeDetailFichier;
use App\Entity\Conception;
use App\Entity\ControlePrePresse;
use App\Entity\Employes;
use App\Entity\User;
use App\Service\SuiviMetiers\Bareme;
use PHPUnit\Framework\TestCase;

final class ConceptionTest extends TestCase
{
    private function conception(string $echeance = '2026-10-15 12:00'): Conception
    {
        $c = new Conception(new ControlePrePresse(), new \DateTimeImmutable('2026-10-15 08:40'), new \DateTimeImmutable($echeance));
        $c->regler('moyen', null, null);

        return $c;
    }

    private static function a(string $heure): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-15 '.$heure);
    }

    public function testExempleW02ChangementClientNeCouteRien(): void
    {
        $c = $this->conception();
        self::assertSame(90, $c->getTempsPrevu());
        $c->demarrer(new User(), self::a('09:58'));
        $c->envoyerBat(self::a('11:24'));
        $c->retour(Conception::RETOUR_CLIENT, 'Mettre le numéro en plus gros', self::a('11:52'));
        $c->demarrer(new User(), self::a('11:55'));
        $c->envoyerBat(self::a('12:06'));
        $c->valider(new Bareme(), self::a('12:15'));

        self::assertSame(['g_bat_a_temps' => 1, 'g_sans_erreur' => 1, 'g_avis_client' => 1], $c->getFaits());
        self::assertSame(22, $c->getPoints());
        self::assertSame(22, $c->getPointsPossibles());
        self::assertSame(2, $c->getVersions());
        self::assertSame((86 + 11) * 60, $c->getSecondes(), 'le chronomètre ne compte pas l\'attente du client');
        self::assertTrue($c->dansLesDelais());
        self::assertFalse($c->valideAuPremierEnvoi());
        self::assertSame(['brief', 'prise', 'bat', 'retour', 'bat', 'valide'], array_column($c->getEvenements(), 'type'));
        self::assertStringContainsString('36 min avant l\'échéance', (string) $c->getEvenements()[2]['detail']);
    }

    public function testRetardEtErreurDuGraphiste(): void
    {
        $c = $this->conception();
        $c->demarrer(new User(), self::a('10:00'));
        $c->envoyerBat(self::a('12:40'));
        $c->retour(Conception::RETOUR_ERREUR, 'faute', self::a('13:00'));
        $c->envoyerBat(self::a('13:20'));
        $c->valider(new Bareme(), self::a('13:30'));

        // (−2 × 2 − 10) × 1,5 = −21
        self::assertSame(['g_retard_30' => 2, 'g_erreur' => 1], $c->getFaits());
        self::assertSame(-21, $c->getPoints());
        self::assertFalse($c->dansLesDelais());
    }

    public function testValidationUneSeuleFois(): void
    {
        $c = $this->conception();
        $c->envoyerBat(self::a('10:00'));
        $c->valider(new Bareme(), self::a('10:30'));
        $c->valider(new Bareme(['g_bat_a_temps' => 50]), self::a('10:40'));
        self::assertSame(22, $c->getPoints());
        self::assertTrue($c->valideAuPremierEnvoi());
    }

    public function testEtapeSuitLeStatutPrepresse(): void
    {
        self::assertSame(Conception::A_FAIRE, Conception::etapeDe(ControlePrePresse::STATUT_A_CONTROLER));
        self::assertSame(Conception::BAT_ENVOYE, Conception::etapeDe(ControlePrePresse::STATUT_EN_ATTENTE_BAT));
        self::assertSame(Conception::CORRECTIONS, Conception::etapeDe(ControlePrePresse::STATUT_CORRECTION_REQUISE));
        self::assertSame(Conception::VALIDE, Conception::etapeDe(ControlePrePresse::STATUT_ENVOYE_PRODUCTION));
    }

    public function testDifficulteChangeLeTempsPrevuParDefaut(): void
    {
        $c = $this->conception();
        $c->regler('complexe', null, null);
        self::assertSame(180, $c->getTempsPrevu());
        $c->regler('simple', null, 50);
        self::assertSame(50, $c->getTempsPrevu());
        $c->regler('complexe', null, null);
        self::assertSame(50, $c->getTempsPrevu(), 'un temps saisi à la main est gardé');
    }
    public function testReperesDuClientGardesEtCorrigesEnVersionSuivante(): void
    {
        $c = $this->conception();
        $c->demarrer(new User(), self::a('09:00'));
        $c->envoyerBat(self::a('10:00'));
        self::assertNull($c->derniersReperes());

        $reperes = [['i' => 0, 'x' => 0.12, 'y' => 0.4, 't' => 'Agrandir le logo de 20 %']];
        $c->retour(Conception::RETOUR_CLIENT, '', self::a('10:30'), $reperes);
        self::assertSame(['reperes' => $reperes, 'version' => 1, 'corrigeeEn' => null], $c->derniersReperes());

        $c->envoyerBat(self::a('11:00'));
        self::assertSame(2, $c->derniersReperes()['corrigeeEn'] ?? null);

        // Le client voit ses repères en ligne ; une demande sans repère les remplace.
        $publics = Conception::evenementsPublics(array_map(static fn (array $e): array => ['le' => $e['le']->format(\DATE_ATOM)] + $e, $c->getEvenements()));
        self::assertSame($reperes, array_values(array_filter(array_column($publics, 'reperes')))[0]);
        $c->retour(Conception::RETOUR_CLIENT, 'Changer la couleur', self::a('11:20'));
        self::assertNull($c->derniersReperes());
    }

    private static function employe(string $prenom, string $fonction): User
    {
        return (new User())->setEmploye((new Employes())->setPrenom($prenom)->setNom('Diarra')->setFonction($fonction));
    }

    /** Ce que la synchronisation lit : les événements bruts, la date en texte. */
    private static function bruts(Conception $c): array
    {
        return array_map(static fn (array $e): array => ['le' => $e['le']->format(\DATE_ATOM)] + $e, $c->getEvenements());
    }

    public function testValidationGardeLesPointsVerifiesParLeClient(): void
    {
        $c = $this->conception();
        $c->demarrer(new User(), self::a('09:00'));
        $c->envoyerBat(self::a('10:00'));
        $c->valider(new Bareme(), self::a('10:30'), 'par le client', ['L\'orthographe des textes', str_repeat('a', 200), ...array_fill(0, 12, 'x')]);

        $valide = $c->getEvenements()[array_key_last($c->getEvenements())];
        self::assertSame('valide', $valide['type']);
        self::assertCount(10, $valide['verifications'], '10 points au plus');
        self::assertSame('L\'orthographe des textes', $valide['verifications'][0]);
        self::assertSame(120, mb_strlen($valide['verifications'][1]));
    }

    public function testCorrectionsDemandeesParLeClientSeulement(): void
    {
        $c = $this->conception();
        $c->demarrer(new User(), self::a('09:00'));
        $c->envoyerBat(self::a('10:00'));
        $c->retour(Conception::RETOUR_CLIENT, 'Logo plus grand', self::a('10:20'));
        $c->envoyerBat(self::a('10:40'));
        $c->retour(Conception::RETOUR_ERREUR, 'faute du graphiste', self::a('10:50'));
        $c->envoyerBat(self::a('11:00'));
        $c->retour(Conception::RETOUR_CLIENT, '', self::a('11:10'), [['i' => 0, 'x' => 0.5, 'y' => 0.5, 't' => 'Plus foncé']]);

        self::assertSame(2, $c->correctionsDemandees(), 'une erreur du graphiste n\'use pas les corrections gratuites du client');
    }

    public function testHistoriquePublicAvecPrenomEtMetierSeulement(): void
    {
        $ctl = (new ControlePrePresse())->addFichier(new CommandeDetailFichier());
        $c = new Conception($ctl, self::a('08:40'), self::a('12:00'));
        $c->regler('moyen', null, null);
        $ctl->commencerControle(self::employe('Awa', 'Contrôleuse prépresse'));
        (new \ReflectionProperty(ControlePrePresse::class, 'controleLe'))->setValue($ctl, self::a('08:50')); // commencerControle date de maintenant
        $c->demarrer(self::employe('Seydou', 'Graphiste PAO'), self::a('09:00'));
        $c->envoyerBat(self::a('11:05'));

        // Contrôle commencé, pas fini : pas encore « Fichier contrôlé ».
        $publics = Conception::evenementsPublics(self::bruts($c), $c);
        self::assertSame(['bat'], array_column($publics, 'type'));
        self::assertSame('Seydou (graphiste PAO)', $publics[0]['par']);

        $ctl->setStatut(ControlePrePresse::STATUT_EN_ATTENTE_BAT);
        $c->valider(new Bareme(), self::a('11:40'), 'par le client', ['La taille : 3 m × 1 m']);
        $publics = Conception::evenementsPublics(self::bruts($c), $c);

        self::assertSame(['controle', 'bat', 'valide'], array_column($publics, 'type'), 'le contrôle d\'abord, puis dans l\'ordre du temps');
        self::assertSame('Awa (contrôleuse prépresse)', $publics[0]['par']);
        self::assertSame(['La taille : 3 m × 1 m'], $publics[2]['verifications']);
        self::assertNull($publics[2]['detail']);
        $texte = json_encode($publics, \JSON_UNESCAPED_UNICODE);
        self::assertStringNotContainsString('Diarra', $texte, 'jamais le nom de famille');
        self::assertStringNotContainsString('chronomètre', $texte, 'rien de la vie interne de l\'atelier');

        // Le site refiltre ce qu'il a reçu pour comparer : même résultat, même sans graphiste ni contrôleur chez lui.
        $enLigne = new Conception((new ControlePrePresse())->setStatut(ControlePrePresse::STATUT_EN_ATTENTE_BAT), self::a('08:40'), null);
        self::assertSame($publics, Conception::evenementsPublics($publics, $enLigne));
        self::assertSame($publics, Conception::evenementsPublics($publics, $c));
    }
}
