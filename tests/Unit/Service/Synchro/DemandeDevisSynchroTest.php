<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Entity\DemandeDevis;
use App\Service\Synchro\CatalogueSynchro;
use PHPUnit\Framework\TestCase;

/** Demandes du site (maquette 11) : montent du site au local, jamais l'inverse. */
final class DemandeDevisSynchroTest extends TestCase
{
    public function testMonteSeulementDuSiteAuLocal(): void
    {
        self::assertSame(CatalogueSynchro::ASCENDANT, CatalogueSynchro::types()['demande_devis']['sens']);
        self::assertSame('demande_devis', CatalogueSynchro::typeDe(new DemandeDevis()));

        // Le site envoie, le local reçoit et crée.
        self::assertTrue(CatalogueSynchro::envoye('demande_devis', true));
        self::assertTrue(CatalogueSynchro::accepte('demande_devis', false));
        self::assertTrue(CatalogueSynchro::creationAcceptee('demande_devis', false));

        // Le local ne la renvoie jamais au site (rien de ce qu'il y ajoute ne descend), le site ne l'accepte pas.
        self::assertFalse(CatalogueSynchro::envoye('demande_devis', false));
        self::assertFalse(CatalogueSynchro::accepte('demande_devis', true));
    }

    public function testCeQuiResteAuLocalNeVoyagePas(): void
    {
        $type = CatalogueSynchro::types()['demande_devis'];
        $voyagent = [...$type['champs'], ...array_keys($type['relations']), ...array_keys($type['listes'] ?? [])];

        foreach (['client', 'prospect', 'devis', 'prepareeLe', 'traiteeLe', 'traiteePar'] as $local) {
            self::assertNotContains($local, $voyagent, $local);
        }
        self::assertSame(['numero', 'nature', 'nom', 'telephone', 'email', 'objet', 'numeroCommande', 'rappelLe', 'designation', 'largeurCm', 'hauteurCm', 'quantite', 'quantiteM2', 'message', 'creeLe', 'accuseEnvoyeLe'], $type['champs']);
        self::assertSame(['configuration' => 'configuration', 'fichier' => 'fichier', 'creation' => 'atelier_creation'], $type['relations']);
        self::assertSame(['finitions' => 'finition'], $type['listes']);
    }

    public function testLesAutresSensNeChangentPas(): void
    {
        self::assertTrue(CatalogueSynchro::envoye('produit', false));
        self::assertFalse(CatalogueSynchro::envoye('produit', true));
        self::assertTrue(CatalogueSynchro::accepte('produit', true));
        self::assertFalse(CatalogueSynchro::creationAcceptee('produit', false));
        self::assertTrue(CatalogueSynchro::envoye('client', true));
        self::assertTrue(CatalogueSynchro::envoye('client', false));
        self::assertTrue(CatalogueSynchro::creationAcceptee('client', false));
    }

    public function testNumeroEtAccuseRemontentApresLaCreation(): void
    {
        // Le site donne le numéro et note l'accusé après le premier enregistrement.
        self::assertTrue(CatalogueSynchro::remonteePermise('demande_devis', 'numero', null, 'W-DD-0001'));
        self::assertTrue(CatalogueSynchro::remonteePermise('demande_devis', 'accuseEnvoyeLe', null, '2026-10-14 10:00:00'));
        self::assertFalse(CatalogueSynchro::remonteePermise('demande_devis', 'nom', 'Awa', 'Autre'));
    }
}
