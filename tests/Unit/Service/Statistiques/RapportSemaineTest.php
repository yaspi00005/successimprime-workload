<?php

namespace App\Tests\Unit\Service\Statistiques;

use App\Command\RapportHebdomadaireCommand;
use App\EventSubscriber\StatistiquesHorsLigneSubscriber;
use App\Service\Statistiques\DroitsStatistiques;
use App\Service\Statistiques\RapportSemaine;
use PHPUnit\Framework\TestCase;

final class RapportSemaineTest extends TestCase
{
    private const DIRECTION = ['ROLE_STATS_GLOBAL', 'ROLE_STATS_VENTES', 'ROLE_PAIEMENT_VOIR', 'ROLE_STATS_PERSO'];

    /** Une semaine où tout va mal, pour voir quelles alertes chacun reçoit. */
    private static function entreprise(): array
    {
        return [
            'en_retard' => 3,
            'ca' => 1000000,
            'objectif_semaine' => 2000000,
            'mois' => ['etat' => 'en_retard', 'part_projetee' => 70],
            'taux_encaissement' => 55.0,
            'ca_30_jours' => 6000000,
            'creances' => 2500000,
            'factures_echues' => 1,
            'devis_a_relancer' => 4,
        ];
    }

    private static function perso(array $valeurs = []): array
    {
        return $valeurs + [
            'ca' => 0, 'ca_avant' => 0, 'objectif_semaine' => null, 'mois' => null, 'commandes' => 0,
            'devis_emis' => 0, 'devis_signes' => 0, 'encaisse' => 0, 'devis_a_relancer' => 0, 'travaux' => 0, 'surface' => 0.0,
        ];
    }

    public function testSectionsSelonLesDroits(): void
    {
        self::assertSame(['entreprise' => true, 'montants' => true, 'encaissement' => true, 'perso' => true], RapportSemaine::sections(DroitsStatistiques::depuisRoles(self::DIRECTION)));
        self::assertSame(['entreprise' => true, 'montants' => false, 'encaissement' => false, 'perso' => false], RapportSemaine::sections(DroitsStatistiques::depuisRoles(['ROLE_STATS_GLOBAL'])));
        // Un agent avec le droit Paiements ne voit pas pour autant l'encaissement de l'entreprise.
        self::assertSame(['entreprise' => false, 'montants' => false, 'encaissement' => false, 'perso' => true], RapportSemaine::sections(DroitsStatistiques::depuisRoles(['ROLE_STATS_PERSO', 'ROLE_STATS_VENTES', 'ROLE_PAIEMENT_VOIR'])));
        self::assertSame(['entreprise' => false, 'montants' => false, 'encaissement' => false, 'perso' => false], RapportSemaine::sections(DroitsStatistiques::aucun()));
    }

    public function testLaDirectionRecoitToutesLesAlertes(): void
    {
        $alertes = RapportSemaine::alertes(self::entreprise(), null, RapportSemaine::sections(DroitsStatistiques::depuisRoles(self::DIRECTION)));
        $textes = array_column($alertes, 'texte');

        self::assertCount(7, $alertes);
        self::assertContains('3 commandes en retard (date promise dépassée)', $textes);
        self::assertContains('CA facturé de la semaine à 50 % de l’objectif de la semaine', $textes);
        self::assertContains('Au rythme actuel, le CA du mois finirait à 70 % de l’objectif', $textes);
        self::assertContains('Taux d’encaissement de la semaine à 55 % (objectif 80 %)', $textes);
        self::assertContains('Créances au-dessus de 20 % du CA des 30 derniers jours', $textes);
        self::assertContains('1 facture échue à relancer', $textes);
        self::assertContains('4 devis envoyés sans réponse depuis 3 jours', $textes);
    }

    public function testSansMontantsNiPaiementsLesAlertesChiffreesDisparaissent(): void
    {
        $alertes = RapportSemaine::alertes(self::entreprise(), null, RapportSemaine::sections(DroitsStatistiques::depuisRoles(['ROLE_STATS_GLOBAL'])));
        $textes = implode(' | ', array_column($alertes, 'texte'));

        self::assertCount(2, $alertes);
        self::assertStringContainsString('commandes en retard', $textes);
        self::assertStringContainsString('devis envoyés', $textes);
        self::assertStringNotContainsString('CA', $textes);
        self::assertStringNotContainsString('encaissement', $textes);
        self::assertStringNotContainsString('Créances', $textes);
        self::assertStringNotContainsString('facture', $textes);
    }

    public function testUnAgentNeRecoitQueSesAlertes(): void
    {
        $sections = RapportSemaine::sections(DroitsStatistiques::depuisRoles(['ROLE_STATS_PERSO', 'ROLE_STATS_VENTES', 'ROLE_PAIEMENT_VOIR']));
        // Même si des chiffres de l'entreprise traînaient, la section fermée ne donne rien.
        $alertes = RapportSemaine::alertes(self::entreprise(), self::perso(['ca' => 300000, 'objectif_semaine' => 500000, 'devis_a_relancer' => 1]), $sections);

        self::assertSame([
            'Vos ventes facturées à 60 % de votre objectif de la semaine',
            'Vous avez 1 devis à relancer',
        ], array_column($alertes, 'texte'));
        self::assertSame([], RapportSemaine::alertes(null, self::perso(['ca' => 480000, 'objectif_semaine' => 500000]), $sections));
    }

    public function testActivitePersonnelle(): void
    {
        self::assertFalse(RapportSemaine::aActivite(self::perso()));
        self::assertTrue(RapportSemaine::aActivite(self::perso(['travaux' => 2])));
        self::assertTrue(RapportSemaine::aActivite(self::perso(['devis_emis' => 1])));
        // Un objectif suffit : l'agent voit qu'il n'a rien vendu.
        self::assertTrue(RapportSemaine::aActivite(self::perso(['objectif_semaine' => 100000])));
    }

    public function testVersionAnnonceeParLaTache(): void
    {
        self::assertSame('Direction (entreprise et montants)', RapportHebdomadaireCommand::version(DroitsStatistiques::depuisRoles(self::DIRECTION)));
        self::assertSame('Entreprise sans montants de vente', RapportHebdomadaireCommand::version(DroitsStatistiques::depuisRoles(['ROLE_STATS_GLOBAL', 'ROLE_STATS_PERSO'])));
        self::assertSame('Ses propres chiffres', RapportHebdomadaireCommand::version(DroitsStatistiques::depuisRoles(['ROLE_STATS_PERSO'])));
    }

    public function testRoutesFermeesEnLigne(): void
    {
        foreach (['app_statistiques_objectifs', 'app_statistiques_objectifs_enregistrer', 'app_statistiques_objectifs_export', 'app_rapport_semaine', 'app_rapport_semaine_pdf', 'app_statistiques_export', 'app_statistiques_globales_export', 'app_statistiques_tresorerie_export', 'app_mes_statistiques_export'] as $route) {
            self::assertTrue(StatistiquesHorsLigneSubscriber::estLocale($route), $route);
        }
        foreach (['app_statistiques_index', 'app_statistiques_globales_index', 'app_mes_statistiques', 'app_finances_export', 'app_home'] as $route) {
            self::assertFalse(StatistiquesHorsLigneSubscriber::estLocale($route), $route);
        }
    }
}
