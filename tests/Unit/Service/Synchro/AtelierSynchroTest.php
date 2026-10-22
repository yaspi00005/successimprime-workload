<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Entity\CreationAtelier;
use App\Entity\ModeleAtelier;
use App\Service\Synchro\CatalogueSynchro;
use PHPUnit\Framework\TestCase;

/** Atelier 3D (docs/atelier-3d.md) : articles réglés au local, créations écrites en ligne. */
final class AtelierSynchroTest extends TestCase
{
    public function testLesArticlesDescendentSeulement(): void
    {
        $type = CatalogueSynchro::types()['atelier_modele'];
        self::assertSame(CatalogueSynchro::DESCENDANT, $type['sens']);
        self::assertSame('atelier_modele', CatalogueSynchro::typeDe(new ModeleAtelier()));
        self::assertTrue(CatalogueSynchro::envoye('atelier_modele', false));
        self::assertFalse(CatalogueSynchro::envoye('atelier_modele', true));
        self::assertFalse(CatalogueSynchro::creationAcceptee('atelier_modele', false), 'un article créé en ligne ne se crée pas au local');

        self::assertSame(['forme', 'couleurs', 'tailles', 'zoneLargeurCm', 'zoneHauteurCm', 'actif', 'ordre'], $type['champs']);
        self::assertSame(['configuration' => 'configuration'], $type['relations']);
    }

    public function testLesCreationsMontentSansRedescendre(): void
    {
        $type = CatalogueSynchro::types()['atelier_creation'];
        self::assertSame(CatalogueSynchro::ASCENDANT, $type['sens']);
        self::assertSame('atelier_creation', CatalogueSynchro::typeDe(new CreationAtelier()));
        self::assertTrue(CatalogueSynchro::envoye('atelier_creation', true));
        self::assertTrue(CatalogueSynchro::accepte('atelier_creation', false));
        self::assertTrue(CatalogueSynchro::creationAcceptee('atelier_creation', false));
        self::assertFalse(CatalogueSynchro::envoye('atelier_creation', false));
        self::assertFalse(CatalogueSynchro::accepte('atelier_creation', true));

        // Liste blanche : aucun prix ni montant ne voyage avec la création.
        self::assertSame(
            ['numero', 'auteur', 'designation', 'forme', 'couleur', 'couleurVisiere', 'tailles', 'quantite', 'reglages', 'nombreCommandes', 'creeLe', 'derniereCommandeLe'],
            $type['champs'],
        );
        foreach ($type['champs'] as $champ) {
            self::assertDoesNotMatchRegularExpression('/prix|montant|total|remise/i', $champ);
        }
        self::assertSame([
            'client' => 'client', 'configuration' => 'configuration', 'commande' => 'commande',
            'renduDevant' => 'fichier', 'renduDos' => 'fichier', 'imageDevant' => 'fichier', 'imageDos' => 'fichier',
        ], $type['relations']);
    }

    public function testSeulsLeNumeroEtLaDerniereCommandeChangentEnLigne(): void
    {
        self::assertTrue(CatalogueSynchro::remonteePermise('atelier_creation', 'numero', null, 'W-CR-0001'));
        self::assertTrue(CatalogueSynchro::remonteePermise('atelier_creation', 'nombreCommandes', '1', '2'));
        self::assertTrue(CatalogueSynchro::remonteePermise('atelier_creation', 'commande', 'a', 'b'));
        self::assertFalse(CatalogueSynchro::remonteePermise('atelier_creation', 'reglages', '{}', '{"devant":{}}'));
        self::assertFalse(CatalogueSynchro::remonteePermise('atelier_creation', 'quantite', '10', '500'));
    }

    public function testLaDemandeDuVisiteurAttendSaCreation(): void
    {
        $types = array_keys(CatalogueSynchro::types());
        // La demande vise la création (non facultative) : celle-ci passe avant, au chargement comme au fil de l'eau.
        self::assertLessThan(array_search('demande_devis', $types, true), array_search('atelier_creation', $types, true));
        self::assertNotContains('creation', CatalogueSynchro::types()['demande_devis']['facultatives'] ?? []);
    }

    public function testLeSiteRecoitLesPaliersEtLaSecondeFace(): void
    {
        $publics = CatalogueSynchro::reglagesPublics('atelier_3d', []);
        self::assertSame(['palier1_quantite' => 25, 'palier1_remise' => 10, 'palier2_quantite' => 50, 'palier2_remise' => 15, 'face_en_plus' => 1500, 'paliers_b2b' => false], $publics);
    }
}
