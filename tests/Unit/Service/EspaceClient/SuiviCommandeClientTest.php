<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\ControlePrePresse;
use App\Entity\Paiements;
use App\Entity\User;
use App\Service\EspaceClient\SuiviCommandeClient;
use PHPUnit\Framework\TestCase;

/**
 * « Où en est ma commande ? » (maquettes CO05 et CO06) : chaque étape vient
 * des données de la commande ; les montants et les paiements ne sont que
 * pour qui paie ; une étape sans donnée reste à venir.
 */
final class SuiviCommandeClientTest extends TestCase
{
    private static function a(string $quand): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-15 '.$quand);
    }

    /** Commande CM-0168 de la maquette : bâche 23 000 F, livrée samedi 17/10. */
    private static function commande(bool $confirmee = true): Commandes
    {
        return (new Commandes())->setDateCommande(self::a('10:20'))->setDateLivraison(new \DateTimeImmutable('2026-10-17 09:00'))
            ->setModeLivraison(Commandes::LIVRAISON_DOMICILE)->setTotalTtc(23000)->setStatut($confirmee);
    }

    private static function paiement(int $montant, string $statut, string $quand, string $mode = Paiements::MODE_ORANGE_MONEY): Paiements
    {
        $p = (new Paiements())->setMontant($montant)->setMode($mode)->setDate(self::a($quand))->setReference('MP251015.1022.C48812');
        (new \ReflectionProperty(Paiements::class, 'statut'))->setValue($p, $statut);

        return $p;
    }

    /** Production de SuiviCommandeService::etapesClient, BAT envoyé au client. */
    private static function productionAuBat(): array
    {
        return [
            ['cle' => 'recue', 'libelle' => 'Commande reçue', 'date' => self::a('10:20'), 'detail' => null, 'etat' => 'faite'],
            ['cle' => 'bat', 'libelle' => 'BAT', 'date' => null, 'detail' => null, 'etat' => 'en_cours'],
            ['cle' => 'impression', 'libelle' => 'Impression', 'date' => null, 'detail' => null, 'etat' => 'a_venir'],
            ['cle' => 'finition', 'libelle' => 'Finition', 'date' => null, 'detail' => null, 'etat' => 'a_venir'],
            ['cle' => 'prete', 'libelle' => 'Prête à livrer', 'date' => null, 'detail' => 'prévue le 17/10', 'etat' => 'a_venir'],
            ['cle' => 'livree', 'libelle' => 'Livrée', 'date' => null, 'detail' => null, 'etat' => 'a_venir'],
        ];
    }

    /** @param array<string, string> $etats */
    private static function production(array $etats): array
    {
        return array_map(static fn (array $e): array => ['etat' => $etats[$e['cle']] ?? $e['etat']] + $e, self::productionAuBat());
    }

    private static function batEnAttente(): array
    {
        return ['etat' => 'attente', 'version' => 1, 'envoyeLe' => self::a('11:05'), 'correctionLe' => null, 'valideLe' => null];
    }

    private static function fichierControle(): array
    {
        return ['statut' => ControlePrePresse::STATUT_EN_ATTENTE_BAT, 'fait' => true, 'le' => self::a('10:40'), 'par' => 'Seydou (graphiste PAO)'];
    }

    /** @return array<string, array<string, mixed>> */
    private static function parCle(array $etapes): array
    {
        return array_column($etapes, null, 'cle');
    }

    public function testMaquetteCo05PourQuiPaie(): void
    {
        $c = self::commande();
        $c->addPaiement(self::paiement(11500, Paiements::STATUT_VALIDE, '10:22'));

        $etapes = SuiviCommandeClient::etapes($c, self::productionAuBat(), [self::fichierControle()], [self::batEnAttente()], null, true, false, self::a('11:30'));
        $e = self::parCle($etapes);

        self::assertSame(['recue', 'paiement', 'fichier', 'bat', 'impression', 'prete', 'livree'], array_column($etapes, 'cle'));
        self::assertSame(['faite', 'faite', 'faite', 'action', 'a_venir', 'a_venir', 'a_venir'], array_column($etapes, 'etat'));
        self::assertSame('15/10 · 10:20', $e['recue']['detail']);
        self::assertSame('Acompte payé', $e['paiement']['libelle']);
        self::assertSame('11 500 F · 10:22 · Orange Money · réf. MP251015.1022.C48812', $e['paiement']['detail']);
        self::assertSame('11 500 F', $e['paiement']['court'], 'sur téléphone : le montant seul (maquette CO06)');
        self::assertSame('par Seydou (graphiste PAO) · 10:40', $e['fichier']['detail']);
        self::assertSame('envoyé à 11:05 · en attente de vous', $e['bat']['detail']);
        self::assertSame('après validation du BAT', $e['impression']['detail']);
        self::assertSame('prévue samedi 17/10', $e['prete']['detail']);
        self::assertSame('reste 11 500 F à payer au livreur', $e['livree']['detail']);
    }

    public function testUnAutreJourLaDateEstDonnee(): void
    {
        $etapes = self::parCle(SuiviCommandeClient::etapes(self::commande(), self::productionAuBat(), [self::fichierControle()], [self::batEnAttente()], null, false, false, new \DateTimeImmutable('2026-10-16 08:00')));

        self::assertSame('envoyé le 15/10 à 11:05 · en attente de vous', $etapes['bat']['detail']);
        self::assertSame('par Seydou (graphiste PAO) · 15/10 · 10:40', $etapes['fichier']['detail']);
    }

    public function testSansDroitDePayerNiPaiementNiMontant(): void
    {
        $c = self::commande();
        $c->addPaiement(self::paiement(11500, Paiements::STATUT_VALIDE, '10:22'));

        $etapes = SuiviCommandeClient::etapes($c, self::productionAuBat(), [self::fichierControle()], [self::batEnAttente()], null, false, false, self::a('11:30'));

        self::assertNotContains('paiement', array_column($etapes, 'cle'));
        foreach ($etapes as $e) {
            self::assertDoesNotMatchRegularExpression('/\d\s?F\b/u', (string) $e['detail'], $e['cle'].' ne montre aucun montant');
        }
    }

    public function testLectureSeuleVoitLeBatSansAvoirLaMain(): void
    {
        $etapes = self::parCle(SuiviCommandeClient::etapes(self::commande(), self::productionAuBat(), [self::fichierControle()], [self::batEnAttente()], null, false, true, self::a('11:30')));

        self::assertSame('en_cours', $etapes['bat']['etat']);
        self::assertSame('envoyé à 11:05 · en attente de votre entreprise', $etapes['bat']['detail']);
    }

    public function testPaiementOrangeMoneyEnValidationNEstPasPaye(): void
    {
        $c = self::commande();
        $c->addPaiement(self::paiement(11500, Paiements::STATUT_EN_ATTENTE, '10:22'));

        $etapes = self::parCle(SuiviCommandeClient::etapes($c, self::productionAuBat(), [], [], null, true, false, self::a('11:30')));

        self::assertSame('Paiement en validation', $etapes['paiement']['libelle']);
        self::assertSame('en_cours', $etapes['paiement']['etat']);
        self::assertSame('reste 11 500 F à payer au livreur', $etapes['livree']['detail'], 'le paiement en validation n\'est plus à payer au livreur');
    }

    public function testCommandeEnLigneAConfirmer(): void
    {
        $etapes = self::parCle(SuiviCommandeClient::etapes(self::commande(false), self::productionAuBat(), [['statut' => ControlePrePresse::STATUT_A_CONTROLER, 'fait' => false, 'le' => null, 'par' => null]], [], null, true, false, self::a('11:30')));

        self::assertSame('15/10 · 10:20 · à confirmer par l\'atelier', $etapes['recue']['detail']);
        self::assertSame('a_venir', $etapes['fichier']['etat'], 'pas de contrôle avant la confirmation');
        self::assertNull($etapes['prete']['detail'], 'pas de date promise avant la confirmation');
    }

    public function testControleCommenceMaisPasFini(): void
    {
        $fichier = ['statut' => ControlePrePresse::STATUT_EN_COURS, 'fait' => false, 'le' => self::a('10:40'), 'par' => null];
        $etapes = self::parCle(SuiviCommandeClient::etapes(self::commande(), self::productionAuBat(), [$fichier], [], null, false, false, self::a('11:30')));

        self::assertSame('en_cours', $etapes['fichier']['etat']);
        self::assertSame('l\'atelier vérifie votre fichier', $etapes['fichier']['detail']);
    }

    public function testCorrectionDemandee(): void
    {
        $bat = ['etat' => 'correction', 'version' => 1, 'envoyeLe' => self::a('11:05'), 'correctionLe' => self::a('11:20'), 'valideLe' => null];
        $etapes = self::parCle(SuiviCommandeClient::etapes(self::commande(), self::productionAuBat(), [self::fichierControle()], [$bat], null, false, false, self::a('11:30')));

        self::assertSame('Correction du BAT', $etapes['bat']['libelle']);
        self::assertSame('demandée à 11:20 · version 2 en préparation', $etapes['bat']['detail']);
    }

    public function testBatValideAvantLImpression(): void
    {
        $bat = ['etat' => 'valide', 'version' => 1, 'envoyeLe' => self::a('11:05'), 'correctionLe' => null, 'valideLe' => self::a('11:40')];
        $etapes = self::parCle(SuiviCommandeClient::etapes(self::commande(), self::productionAuBat(), [self::fichierControle()], [$bat], null, false, false, self::a('12:00')));

        self::assertSame(['BAT validé', 'faite', '11:40'], [$etapes['bat']['libelle'], $etapes['bat']['etat'], $etapes['bat']['detail']]);
        self::assertSame('l\'atelier lance l\'impression', $etapes['impression']['detail']);
    }

    public function testEnImpressionPuisPrete(): void
    {
        $production = self::production(['bat' => 'faite', 'impression' => 'en_cours']);
        $etapes = self::parCle(SuiviCommandeClient::etapes(self::commande(), $production, [self::fichierControle()], [], null, false, false, self::a('12:00')));
        self::assertSame(['En impression', 'en_cours', 'dans la file d\'impression'], [$etapes['impression']['libelle'], $etapes['impression']['etat'], $etapes['impression']['detail']]);

        $production = self::production(['bat' => 'faite', 'impression' => 'faite', 'finition' => 'faite', 'prete' => 'faite']);
        $production[3]['date'] = $production[4]['date'] = self::a('16:40');
        $etapes = self::parCle(SuiviCommandeClient::etapes(self::commande(), $production, [self::fichierControle()], [], null, false, false, self::a('17:00')));
        self::assertSame(['Imprimée', 'faite'], [$etapes['impression']['libelle'], $etapes['impression']['etat']]);
        self::assertSame(['faite', '16:40'], [$etapes['prete']['etat'], $etapes['prete']['detail']]);
        self::assertSame('a_venir', $etapes['livree']['etat']);
    }

    public function testRienDeDevinePourUneCommandeSansControleEnregistre(): void
    {
        // Ligne passée en prépresse mais aucun contrôle ni BAT au système, commande déjà livrée.
        $production = self::production(['bat' => 'faite', 'impression' => 'faite', 'finition' => 'faite', 'prete' => 'faite', 'livree' => 'faite']);
        $etapes = SuiviCommandeClient::etapes(self::commande(), $production, [['statut' => null, 'fait' => false, 'le' => null, 'par' => null]], [], null, false, false, self::a('17:00'));

        self::assertSame(['recue', 'impression', 'prete', 'livree'], array_column($etapes, 'cle'));
    }

    public function testLivraisonReporteeSansRaisonNiLivreur(): void
    {
        $bl = (new BonLivraison())->setPrevuLe(new \DateTimeImmutable('2026-10-17'))->setCreneau('09:00-10:00');
        (new \ReflectionProperty(BonLivraison::class, 'statut'))->setValue($bl, BonLivraison::STATUT_VALIDE);
        $bl->echouer(new User(), 'Client absent', new \DateTimeImmutable('2026-10-17 09:40'));
        $production = self::production(['bat' => 'faite', 'impression' => 'faite', 'finition' => 'faite', 'prete' => 'faite', 'livree' => 'en_cours']);

        $etapes = self::parCle(SuiviCommandeClient::etapes(self::commande(), $production, [self::fichierControle()], [], $bl, true, false, new \DateTimeImmutable('2026-10-17 10:00')));

        self::assertSame(['Livraison reportée', 'en_cours'], [$etapes['livree']['libelle'], $etapes['livree']['etat']]);
        self::assertSame('pas pu être livrée le 17/10 · l\'atelier vous recontacte pour une nouvelle date', $etapes['livree']['detail']);
        self::assertStringNotContainsString('absent', $etapes['livree']['detail']);
    }

    public function testEnRouteAvecLeCreneau(): void
    {
        $bl = (new BonLivraison())->setPrevuLe(new \DateTimeImmutable('2026-10-17'))->setCreneau('09:00-10:00');
        (new \ReflectionProperty(BonLivraison::class, 'enRouteLe'))->setValue($bl, new \DateTimeImmutable('2026-10-17 08:50'));
        $production = self::production(['bat' => 'faite', 'impression' => 'faite', 'finition' => 'faite', 'prete' => 'faite', 'livree' => 'en_cours']);

        $etapes = self::parCle(SuiviCommandeClient::etapes(self::commande(), $production, [], [], $bl, false, false, new \DateTimeImmutable('2026-10-17 09:00')));

        self::assertSame(['En route', 'livreur parti à 08:50 · créneau 09:00-10:00'], [$etapes['livree']['libelle'], $etapes['livree']['detail']]);
    }

    public function testEtatDuBat(): void
    {
        $ctl = (new ControlePrePresse())->setStatut(ControlePrePresse::STATUT_EN_ATTENTE_BAT);
        self::assertSame('attente', SuiviCommandeClient::etatBat($ctl));
        self::assertSame('correction', SuiviCommandeClient::etatBat($ctl->setStatut(ControlePrePresse::STATUT_CORRECTION_REQUISE)));
        self::assertSame('valide', SuiviCommandeClient::etatBat($ctl->setStatut(ControlePrePresse::STATUT_EN_COURS)->setBatNecessaire(true)->setBatValide(true)));
        self::assertSame('preparation', SuiviCommandeClient::etatBat((new ControlePrePresse())->setStatut(ControlePrePresse::STATUT_EN_COURS)));
    }
}
