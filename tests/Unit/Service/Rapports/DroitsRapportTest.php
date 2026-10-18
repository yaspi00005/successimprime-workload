<?php

namespace App\Tests\Unit\Service\Rapports;

use App\Service\Rapports\DestinatairesRapports;
use App\Service\Rapports\DroitsRapport;
use App\Service\Rapports\PeriodeRapport;
use App\Service\Rapports\RapportPeriodique;
use PHPUnit\Framework\TestCase;

/**
 * Ce que chaque destinataire reçoit (docs/rapports-mail.md) : « toute
 * info sensible doit tenir compte des droits et rôles ». Une partie sans
 * son droit n'est pas calculée ; sans le droit du rapport, rien.
 */
final class DroitsRapportTest extends TestCase
{
    private const DIRECTION = ['ROLE_ADMIN', 'ROLE_STATS_GLOBAL', 'ROLE_STATS_VENTES', 'ROLE_PAIEMENT_VOIR', 'ROLE_TRESORERIE_VOIR', 'ROLE_COUT_MARGE', 'ROLE_CLIENT', 'ROLE_RH'];

    public function testDirectionTout(): void
    {
        $droits = DroitsRapport::depuisRoles(self::DIRECTION);

        self::assertSame([] , array_keys(array_filter($droits, static fn (bool $v): bool => !$v)));
        self::assertSame('Complète', DroitsRapport::version($droits));
        self::assertSame(
            ['activite' => true, 'ventes' => true, 'encaissements' => true, 'creances' => true, 'tresorerie' => true, 'marge' => true, 'equipe' => true, 'stock' => true, 'noms_clients' => true],
            RapportPeriodique::parties($droits, PeriodeRapport::MOIS),
        );
        // Un taux de marge sur un jour ou une semaine ne dit rien : rapport du mois seulement.
        self::assertFalse(RapportPeriodique::parties($droits, PeriodeRapport::JOUR)['marge']);
        self::assertFalse(RapportPeriodique::parties($droits, PeriodeRapport::SEMAINE)['marge']);
    }

    public function testActiviteSeuleSansDroitsDArgent(): void
    {
        $droits = DroitsRapport::depuisRoles(['ROLE_CAISSE_COMMANDE', 'ROLE_STATS_GLOBAL']);
        $parties = RapportPeriodique::parties($droits, PeriodeRapport::MOIS);

        self::assertTrue($parties['activite']);
        foreach (['ventes', 'encaissements', 'creances', 'tresorerie', 'marge', 'equipe', 'stock', 'noms_clients'] as $partie) {
            self::assertFalse($parties[$partie], $partie);
        }
        self::assertSame('activité', DroitsRapport::contenu($droits));
        self::assertStringStartsWith('Sans chiffre d’affaires, encaissements et créances, dépenses et trésorerie, marge, noms des clients', DroitsRapport::version($droits));
    }

    public function testPaiementsSansNomsDeClients(): void
    {
        $droits = DroitsRapport::depuisRoles(['ROLE_STATS_GLOBAL', 'ROLE_PAIEMENT_VOIR', 'ROLE_STATS_VENTES']);
        $parties = RapportPeriodique::parties($droits, PeriodeRapport::SEMAINE);

        self::assertTrue($parties['ventes']);
        self::assertTrue($parties['encaissements']);
        self::assertTrue($parties['creances']);
        self::assertFalse($parties['tresorerie']);
        self::assertFalse($parties['noms_clients']);
        self::assertSame('activité, chiffre d’affaires, encaissements et créances', DroitsRapport::contenu($droits));
    }

    public function testSansLeDroitDuRapportRienDuTout(): void
    {
        // Paiements et trésorerie ne suffisent pas : il faut « Chiffre d'affaires global ».
        $droits = DroitsRapport::depuisRoles(['ROLE_CAISSE_COMMANDE', 'ROLE_PAIEMENT_VOIR', 'ROLE_TRESORERIE_VOIR', 'ROLE_CLIENT']);

        self::assertSame([], array_filter($droits));
        self::assertSame([], array_filter(RapportPeriodique::parties($droits, PeriodeRapport::MOIS)));
        self::assertStringStartsWith('Aucune', DroitsRapport::version($droits));
    }

    public function testMemesDroitsMemeSignature(): void
    {
        $a = DroitsRapport::depuisRoles(['ROLE_STATS_GLOBAL', 'ROLE_CLIENT']);
        $b = DroitsRapport::depuisRoles(['ROLE_CLIENT', 'ROLE_STATS_GLOBAL', 'ROLE_DEVIS']);

        self::assertSame(DroitsRapport::signature($a), DroitsRapport::signature($b));
        self::assertNotSame(DroitsRapport::signature($a), DroitsRapport::signature(DroitsRapport::depuisRoles(['ROLE_STATS_GLOBAL'])));
    }

    public function testQuiRecoitLesRapports(): void
    {
        $direction = ['ROLE_ADMIN', 'ROLE_STATS_GLOBAL'];
        $caisse = ['ROLE_CAISSE_COMMANDE', 'ROLE_STATS_GLOBAL'];

        // Sans choix : la direction qui a une adresse.
        self::assertTrue(DestinatairesRapports::retenu(1, $direction, true, true, null));
        self::assertFalse(DestinatairesRapports::retenu(1, $direction, true, false, null));
        self::assertFalse(DestinatairesRapports::retenu(2, $caisse, true, true, null));
        // Liste choisie : seulement les comptes cochés, et de la direction (Yaya, 18/10/2026).
        self::assertTrue(DestinatairesRapports::retenu(1, $direction, true, true, [1, 5]));
        self::assertFalse(DestinatairesRapports::retenu(2, $caisse, true, true, [2, 5]), 'hors de la direction, même coché');
        self::assertFalse(DestinatairesRapports::retenu(1, $direction, true, true, [2, 5]));
        self::assertFalse(DestinatairesRapports::retenu(1, $direction, true, true, []));
        // Coché puis privé du droit : ne reçoit plus rien.
        self::assertFalse(DestinatairesRapports::retenu(2, ['ROLE_CAISSE_COMMANDE'], false, true, [2]));
    }

    public function testAlertesSelonLesDroits(): void
    {
        $r = [
            'en_cours' => false,
            'activite' => ['en_retard' => 2, 'bat' => ['sans_reponse' => 1], 'reclamations' => ['anciennes' => 0], 'devis' => ['a_relancer' => 3]],
            'taches' => ['nombre' => 1],
            'ventes' => ['facture' => 500000, 'objectif' => 1000000, 'mois' => ['etat' => 'en_retard', 'part_projetee' => 70]],
            'encaissements' => ['taux' => 50.0, 'taux_objectif' => 80, 'attente' => ['nombre' => 2]],
            'creances' => ['echues' => 1250000, 'nb_echues' => 4],
            'tresorerie' => ['total' => 100000, 'seuil' => 2000000, 'depenses' => ['budget' => 100, 'hors_salaires' => 150]],
            'marge' => ['taux' => 20],
            'stock' => [['designation' => 'Encre', 'reste' => 1.0, 'unite' => 'litre']],
            'equipe' => ['absences_non_justifiees' => 2],
        ];
        $tout = array_column(RapportPeriodique::alertes($r, DroitsRapport::depuisRoles(self::DIRECTION)), 'texte');

        self::assertCount(14, $tout);
        self::assertContains('Créances échues : 1 250 000 F (4 documents)', $tout);
        self::assertContains('CA facturé à 50 % de l’objectif de la période', $tout);

        // Sans droit d'argent, aucune alerte ne donne un montant ni un taux d'argent.
        $activite = array_column(RapportPeriodique::alertes($r, DroitsRapport::depuisRoles(['ROLE_STATS_GLOBAL'])), 'texte');
        self::assertSame([
            '2 commandes en retard (date promise dépassée)',
            '1 BAT sans réponse du client depuis plus de 48 h',
            '3 devis envoyés sans réponse depuis 3 jours',
            '1 tâche du planning en retard',
        ], $activite);
        foreach ($activite as $texte) {
            self::assertStringNotContainsString(' F', $texte);
        }

        // Période pas finie : l'objectif de la période n'est pas encore jugé.
        $enCours = array_column(RapportPeriodique::alertes(['en_cours' => true] + $r, DroitsRapport::depuisRoles(self::DIRECTION)), 'texte');
        self::assertNotContains('CA facturé à 50 % de l’objectif de la période', $enCours);
    }

    public function testEcart(): void
    {
        self::assertSame(25, RapportPeriodique::ecart(125, 100));
        self::assertSame(-50, RapportPeriodique::ecart(50, 100));
        self::assertNull(RapportPeriodique::ecart(50, 0));
        self::assertNull(RapportPeriodique::ecart(null, 10));
    }
}
