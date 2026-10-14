<?php

namespace App\Tests\Unit\Service\Promotions;

use App\Entity\AnniversaireClient;
use App\Entity\Clients;
use App\Entity\ModeleAnniversaire;
use App\Entity\Produits;
use App\Entity\Promotion;
use App\Service\Promotions\CalculAnniversaire;
use App\Service\Promotions\CalculPromotion;
use App\Service\Promotions\ContexteLigne;
use PHPUnit\Framework\TestCase;

final class CalculAnniversaireTest extends TestCase
{
    public function testDateDeDepartLaPlusAncienneDeLaFicheEtDeLaPremiereCommande(): void
    {
        $fiche = new \DateTime('2024-03-10 15:20');
        self::assertSame('2024-03-10', CalculAnniversaire::dateDepart($fiche, null)?->format('Y-m-d'));
        // Client repris de l'ancien système : sa première commande est plus ancienne que la fiche.
        self::assertSame('2021-06-01', CalculAnniversaire::dateDepart($fiche, new \DateTimeImmutable('2021-06-01 09:00'))?->format('Y-m-d'));
        self::assertSame('2024-03-10', CalculAnniversaire::dateDepart($fiche, new \DateTimeImmutable('2025-01-01'))?->format('Y-m-d'));
        self::assertNull(CalculAnniversaire::dateDepart(null, null));
    }

    public function testProchainAnniversaireEtNombreDAnnees(): void
    {
        $depart = new \DateTimeImmutable('2023-10-20');
        $p = CalculAnniversaire::prochain($depart, new \DateTimeImmutable('2026-10-14'));
        self::assertSame(['2026-10-20', 3], [$p['date']->format('Y-m-d'), $p['annees']]);

        // Le jour même compte.
        $p = CalculAnniversaire::prochain($depart, new \DateTimeImmutable('2026-10-20'));
        self::assertSame(['2026-10-20', 3], [$p['date']->format('Y-m-d'), $p['annees']]);

        $p = CalculAnniversaire::prochain($depart, new \DateTimeImmutable('2026-10-21'));
        self::assertSame(['2027-10-20', 4], [$p['date']->format('Y-m-d'), $p['annees']]);

        // Fiche créée cette année : premier anniversaire l'an prochain.
        $p = CalculAnniversaire::prochain(new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14'));
        self::assertSame(['2027-10-14', 1], [$p['date']->format('Y-m-d'), $p['annees']]);

        // Départ en décembre, regardé en janvier.
        $p = CalculAnniversaire::prochain(new \DateTimeImmutable('2020-12-30'), new \DateTimeImmutable('2027-01-02'));
        self::assertSame(['2027-12-30', 7], [$p['date']->format('Y-m-d'), $p['annees']]);
    }

    public function testUn29FevrierSeFeteLe28LesAnneesNonBissextiles(): void
    {
        $depart = new \DateTimeImmutable('2024-02-29');
        self::assertSame('2025-02-28', CalculAnniversaire::dateAnniversaire($depart, 1)->format('Y-m-d'));
        self::assertSame('2028-02-29', CalculAnniversaire::dateAnniversaire($depart, 4)->format('Y-m-d'));
        $p = CalculAnniversaire::prochain($depart, new \DateTimeImmutable('2026-02-01'));
        self::assertSame(['2026-02-28', 2], [$p['date']->format('Y-m-d'), $p['annees']]);
    }

    public function testModeleDuPalierLePlusProcheAtteint(): void
    {
        $un = (new ModeleAnniversaire())->setAnnees(1)->setNom('1 an');
        $trois = (new ModeleAnniversaire())->setAnnees(3)->setNom('3 ans');
        $cinq = (new ModeleAnniversaire())->setAnnees(5)->setNom('5 ans')->setActif(false);
        $modeles = [$cinq, $trois, $un];

        self::assertSame($un, CalculAnniversaire::modelePour($modeles, 1));
        self::assertSame($un, CalculAnniversaire::modelePour($modeles, 2));
        self::assertSame($trois, CalculAnniversaire::modelePour($modeles, 4));
        // Le modèle 5 ans est désactivé : 3 ans s'applique.
        self::assertSame($trois, CalculAnniversaire::modelePour($modeles, 6));
        self::assertNull(CalculAnniversaire::modelePour([$trois], 2));
    }

    public function testTexteDuSmsSansMontant(): void
    {
        $texte = CalculAnniversaire::texte(ModeleAnniversaire::TEXTE_PAR_DEFAUT, 'Awa', 'Traoré', 3, new \DateTimeImmutable('2026-11-19'));
        self::assertSame('Succès Imprim : joyeux anniversaire Awa ! Cela fait 3 ans que vous nous faites confiance. Un cadeau vous attend en boutique jusqu\'au 19/11/2026.', $texte);
        self::assertStringContainsString('1 an que', CalculAnniversaire::texte('{prenom} : {annees} que', 'Awa', null, 1, new \DateTimeImmutable()));
        self::assertStringStartsWith('Traoré', CalculAnniversaire::texte('{prénom}', '', 'Traoré', 1, new \DateTimeImmutable()));

        self::assertFalse(CalculAnniversaire::contientMontant(ModeleAnniversaire::TEXTE_PAR_DEFAUT));
        self::assertFalse(CalculAnniversaire::contientMontant($texte));
        self::assertFalse(CalculAnniversaire::contientMontant(CalculAnniversaire::TEXTE_RAPPEL));
        self::assertFalse(CalculAnniversaire::contientMontant('Rendez-vous le 12 février, 5 fois merci !'));
        foreach (['−10 % sur vos cartes', 'Remise de 5000 F', 'Remise de 5 000F !', '2 500 FCFA offerts', 'mille francs', 'Prix -20', '10 F CFA'] as $avecMontant) {
            self::assertTrue(CalculAnniversaire::contientMontant($avecMontant), $avecMontant);
        }
    }

    public function testLeJourJLaTacheNeRefaitRienUneFoisPassee(): void
    {
        $jourJ = new \DateTimeImmutable('2026-10-20');
        $a = new AnniversaireClient(new Clients(), 3, $jourJ);

        self::assertSame('pas_encore', CalculAnniversaire::actionDuJour($a, $jourJ, new \DateTimeImmutable('2026-10-19'), false));
        self::assertSame('creer', CalculAnniversaire::actionDuJour(null, $jourJ, $jourJ, false));
        self::assertSame('attente_validation', CalculAnniversaire::actionDuJour(null, $jourJ, $jourJ, true));
        self::assertSame('attente_validation', CalculAnniversaire::actionDuJour($a, $jourJ, $jourJ, true));

        $a->decider(AnniversaireClient::VALIDE, null, null, new \DateTimeImmutable('2026-10-10'));
        self::assertSame('creer', CalculAnniversaire::actionDuJour($a, $jourJ, $jourJ, true));
        // Validé en retard : encore fêté pendant le rattrapage, plus après.
        self::assertSame('creer', CalculAnniversaire::actionDuJour($a, $jourJ, new \DateTimeImmutable('2026-10-27'), true));
        self::assertSame('trop_tard', CalculAnniversaire::actionDuJour($a, $jourJ, new \DateTimeImmutable('2026-10-28'), true));

        $a->marquerTraite(new Promotion(), null, new \DateTimeImmutable('2026-10-20 08:15'));
        self::assertSame('deja_traite', CalculAnniversaire::actionDuJour($a, $jourJ, $jourJ, true));
        self::assertSame('deja_traite', CalculAnniversaire::actionDuJour($a, $jourJ, new \DateTimeImmutable('2026-10-21'), false));

        $this->expectException(\DomainException::class);
        $a->decider(AnniversaireClient::EXCLU, null, null, new \DateTimeImmutable());
    }

    public function testUnAnniversaireExcluNEstPasFete(): void
    {
        $jourJ = new \DateTimeImmutable('2026-10-20');
        $a = (new AnniversaireClient(new Clients(), 2, $jourJ))->decider(AnniversaireClient::EXCLU, null, null, new \DateTimeImmutable('2026-10-15'));
        self::assertSame('exclu', CalculAnniversaire::actionDuJour($a, $jourJ, $jourJ, false));
    }

    public function testRappelUneFoisAvantLaFinSiLaPromotionNAPasServi(): void
    {
        $fin = new \DateTimeImmutable('2026-11-18');
        $a = (new AnniversaireClient(new Clients(), 3, new \DateTimeImmutable('2026-10-20')))
            ->marquerTraite(new Promotion(), null, new \DateTimeImmutable('2026-10-20 08:00'))
            ->marquerEnvoi(AnniversaireClient::CANAL_SMS, new \DateTimeImmutable('2026-10-20 08:00'));

        self::assertFalse(CalculAnniversaire::rappelDu($a, $fin, new \DateTimeImmutable('2026-11-10'), 7, false));
        self::assertTrue(CalculAnniversaire::rappelDu($a, $fin, new \DateTimeImmutable('2026-11-11'), 7, false));
        self::assertTrue(CalculAnniversaire::rappelDu($a, $fin, new \DateTimeImmutable('2026-11-15'), 7, false), 'tâche manquée le 11');
        self::assertFalse(CalculAnniversaire::rappelDu($a, $fin, new \DateTimeImmutable('2026-11-11'), 7, true), 'déjà utilisée');
        self::assertFalse(CalculAnniversaire::rappelDu($a, $fin, new \DateTimeImmutable('2026-11-19'), 7, false), 'terminée');
        self::assertFalse(CalculAnniversaire::rappelDu($a, $fin, new \DateTimeImmutable('2026-11-11'), 0, false), 'rappel coupé');

        $a->marquerRappel(new \DateTimeImmutable('2026-11-11 08:00'));
        self::assertFalse(CalculAnniversaire::rappelDu($a, $fin, new \DateTimeImmutable('2026-11-12'), 7, false));
    }

    public function testLaPromotionDAnniversaireEstPourLeClientSeulEtUneFois(): void
    {
        $produit = (new Produits())->setNom('Cartes de visite');
        (new \ReflectionProperty($produit, 'id'))->setValue($produit, 21);
        $client = (new Clients())->setPrenom('Awa')->setNom('Traoré');
        (new \ReflectionProperty($client, 'id'))->setValue($client, 31);
        (new \ReflectionProperty($client, 'code'))->setValue($client, 'CLI-000031');
        $modele = (new ModeleAnniversaire())->setAnnees(1)->setNom('Anniversaire : −10 % cartes')->setValeur(10)->setValiditeJours(30);
        $modele->remplacerProduits([$produit]);

        $p = $modele->creerPromotion($client, 1, new \DateTimeImmutable('2026-10-20'), new \DateTimeImmutable('2026-10-20'), null);
        self::assertTrue($p->estAnniversaire());
        self::assertSame(Promotion::SEGMENT_LISTE, $p->getSegment());
        self::assertSame('2026-11-18', $p->getFin()->format('Y-m-d'));
        self::assertSame(1, $p->getUtilisationsParClient());

        (new \ReflectionProperty($p, 'id'))->setValue($p, 40);
        $ligne = static fn (Clients $c, array $utilisations = []): ContexteLigne => new ContexteLigne($c, $produit, 5000, 0, 100, 5000, new \DateTimeImmutable('2026-10-25'), ['tous'], null, $utilisations);
        self::assertNull(CalculPromotion::refus($p, $ligne($client)));
        self::assertSame('utilisations', CalculPromotion::refus($p, $ligne($client, [40 => 1])));
        $autre = (new Clients())->setNom('Autre');
        (new \ReflectionProperty($autre, 'id'))->setValue($autre, 32);
        self::assertSame('segment', CalculPromotion::refus($p, $ligne($autre)));
    }
}
