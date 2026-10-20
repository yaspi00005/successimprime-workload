<?php

namespace App\Tests\Unit\Service;

use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Service\SuiviCommandeService;
use App\Service\Synchro\CatalogueSynchro;
use App\Service\TableauDeBordService;
use PHPUnit\Framework\TestCase;

/**
 * « Prête » arrive au bon moment (PD06, docs/production-colis.md) : une
 * ligne imprimée (terminee) est encore en finition / contrôle ; seule une
 * ligne « prête pour la livraison » (contrôle final fait, colis scannés)
 * est « Prête » pour le client, datée de ce passage ; un bon validé pour
 * demain ne dit pas « en route » avant le départ du livreur.
 */
final class SuiviPreteTest extends TestCase
{
    private static function commande(string $statut, ?\DateTimeImmutable $preteLe = null): Commandes
    {
        $ligne = (new CommandesDetails())->setDesignation('Bâche')->setQuantite(2)->setPrePresseNecessaire(false)->setStatutProduction($statut);
        (new \ReflectionProperty(CommandesDetails::class, 'productionTermineeLe'))->setValue($ligne, new \DateTimeImmutable('2026-10-20 10:40'));
        if (null !== $preteLe) {
            $ligne->setPreteLivraisonLe($preteLe);
        }
        $c = (new Commandes())->setModeLivraison(Commandes::LIVRAISON_DOMICILE);
        $c->addCommandesDetail($ligne);

        return $c;
    }

    public function testImprimeeNEstPasEncorePrete(): void
    {
        $etapes = array_column((new SuiviCommandeService())->etapesClient(self::commande(CommandesDetails::PRODUCTION_TERMINEE)), null, 'cle');

        self::assertSame('faite', $etapes['impression']['etat']);
        self::assertSame('en_cours', $etapes['finition']['etat']);
        self::assertSame('a_venir', $etapes['prete']['etat']);
        self::assertSame('finition', TableauDeBordService::colonnePourLigne(CommandesDetails::PRODUCTION_TERMINEE, false, false, null));
    }

    public function testPreteDateeDuPassagePrete(): void
    {
        $etapes = array_column((new SuiviCommandeService())->etapesClient(self::commande(CommandesDetails::PRODUCTION_PRETE_LIVRAISON, new \DateTimeImmutable('2026-10-20 11:03'))), null, 'cle');

        self::assertSame('faite', $etapes['prete']['etat']);
        self::assertSame('11:03', $etapes['prete']['date']?->format('H:i'));
        self::assertSame('en_cours', $etapes['livree']['etat']);
    }

    public function testLeSiteRecoitLaDatePreteMaisPasLesColis(): void
    {
        $types = CatalogueSynchro::types();

        self::assertContains('preteLivraisonLe', $types['commande_ligne']['champs']);
        // Colis, contrôle final et photo restent à l'ERP local.
        foreach ($types as $type) {
            self::assertNotSame('App\\Entity\\ColisProduction', $type['classe'] ?? null);
            self::assertNotSame('App\\Entity\\ControleFinal', $type['classe'] ?? null);
        }
    }

    public function testPasEnRouteAvantLeDepartDuLivreur(): void
    {
        $bon = (new BonLivraison())->setPrevuLe(new \DateTimeImmutable('2026-10-21'));

        self::assertSame('prévue le 21/10', SuiviCommandeService::detailEnLivraison($bon, null));

        (new \ReflectionProperty(BonLivraison::class, 'enRouteLe'))->setValue($bon, new \DateTimeImmutable('2026-10-21 09:40'));
        self::assertSame('en route', SuiviCommandeService::detailEnLivraison($bon, null));
        self::assertSame('en route', SuiviCommandeService::detailEnLivraison(null, null), 'sans bon connu : comme avant');
    }
}
