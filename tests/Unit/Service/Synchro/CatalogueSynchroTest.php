<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Entity\Clients;
use App\Entity\Finition;
use App\Service\Synchro\CatalogueSynchro;
use App\Service\Synchro\Synchro;
use PHPUnit\Framework\TestCase;

final class CatalogueSynchroTest extends TestCase
{
    public function testLesDonneesSensiblesNePartentPas(): void
    {
        $types = CatalogueSynchro::types();
        self::assertNotContains('coutUnitaire', $types['finition']['champs'], 'coût de revient');
        foreach (['observation', 'remiseHabituelle', 'suiviPar', 'etiquettes', 'besoin', 'origine', 'secteur'] as $champ) {
            self::assertNotContains($champ, $types['client']['champs'], $champ);
            self::assertArrayNotHasKey($champ, $types['client']['relations'], $champ);
        }
        foreach ($types as $cle => $t) {
            foreach ($t['champs'] as $champ) {
                self::assertDoesNotMatchRegularExpression('/cout|marge|salaire|password|motDePasse/i', $champ, $cle.'.'.$champ);
            }
        }
    }

    public function testUneDonneeVientApresCellesQuelleReference(): void
    {
        $vus = [];
        foreach (CatalogueSynchro::types() as $cle => $t) {
            foreach ([...array_values($t['relations']), ...array_values($t['listes'])] as $cible) {
                self::assertArrayHasKey($cible, $vus, $cle.' référence '.$cible.' qui doit venir avant');
            }
            $vus[$cle] = true;
            self::assertTrue(property_exists($t['classe'], 'publicId'), $cle.' a besoin d\'un identifiant public');
        }
    }

    public function testSens(): void
    {
        // Le local envoie tout, le site seulement les fiches à double sens.
        self::assertTrue(CatalogueSynchro::envoye('produit', false));
        self::assertFalse(CatalogueSynchro::envoye('produit', true));
        self::assertTrue(CatalogueSynchro::envoye('client', true));
        // Le site accepte tout, le local seulement les fiches à double sens.
        self::assertTrue(CatalogueSynchro::accepte('produit', true));
        self::assertFalse(CatalogueSynchro::accepte('produit', false));
        self::assertTrue(CatalogueSynchro::accepte('client', false));
        self::assertFalse(CatalogueSynchro::accepte('inconnu', true));

        self::assertSame('client', CatalogueSynchro::typeDe(new Clients()));
        self::assertSame('finition', CatalogueSynchro::typeDe(new Finition()));
        self::assertNull(CatalogueSynchro::typeDe(new \stdClass()));
    }

    public function testAlerteApres15Minutes(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-12 12:00');
        self::assertNull(Synchro::alerte(false, null, null, $maintenant, null), 'pas réglée : pas d\'alerte');
        self::assertNull(Synchro::alerte(true, null, null, $maintenant, null), 'l\'agent n\'a jamais tourné');
        self::assertNull(Synchro::alerte(true, new \DateTimeImmutable('2026-10-12 11:50'), null, $maintenant, 'coupure'));
        self::assertSame('Pas de synchronisation réussie depuis 15 min : coupure', Synchro::alerte(true, new \DateTimeImmutable('2026-10-12 11:45'), null, $maintenant, 'coupure'));
        self::assertSame('Pas de synchronisation réussie depuis 3 h.', Synchro::alerte(true, new \DateTimeImmutable('2026-10-12 09:00'), null, $maintenant, null));
        self::assertStringStartsWith('La synchronisation n\'a jamais réussi', (string) Synchro::alerte(true, null, new \DateTimeImmutable('2026-10-12 11:00'), $maintenant, 'clé'));
    }
}
