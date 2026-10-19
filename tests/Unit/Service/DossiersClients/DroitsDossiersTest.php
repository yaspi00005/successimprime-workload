<?php

namespace App\Tests\Unit\Service\DossiersClients;

use App\Service\DossiersClients\DroitsDossiers;
use App\Service\DossiersClients\ModeleSousDossiers;
use PHPUnit\Framework\TestCase;

/** Qui voit quoi (DS04), sous-dossier par sous-dossier. */
final class DroitsDossiersTest extends TestCase
{
    private static function droits(?array $matrice = null): DroitsDossiers
    {
        return new DroitsDossiers($matrice, (new ModeleSousDossiers())->cles());
    }

    public function testMatriceParDefautDeLaMaquette(): void
    {
        $d = self::droits();
        $modele = new ModeleSousDossiers();
        // Direction : tout, oui, oui.
        self::assertSame(['voir' => true, 'ajouter' => true, 'supprimer' => true], $d->pour(['ROLE_ADMIN', 'ROLE_USER']));
        // Graphiste PAO : tout, oui, non.
        self::assertTrue($d->peut(['ROLE_GRAPHISTE'], 'voir', 'bat'));
        self::assertTrue($d->peut(['ROLE_GRAPHISTE'], 'ajouter', 'conceptions'));
        self::assertFalse($d->peut(['ROLE_GRAPHISTE'], 'supprimer', 'conceptions'));
        // Caisse et commercial : tout, 02 et 06, non.
        self::assertTrue($d->peut(['ROLE_CAISSE_COMMANDE'], 'ajouter', 'recus'));
        self::assertTrue($d->peut(['ROLE_CAISSE_COMMANDE'], 'ajouter', 'documents'));
        self::assertFalse($d->peut(['ROLE_CAISSE_COMMANDE'], 'ajouter', 'conceptions'));
        self::assertSame('02 et 06', DroitsDossiers::resumer($d->matrice()['ROLE_CAISSE_COMMANDE']['ajouter'], $modele, 'ajouter'));
        // Opérateur machine : « 04 Prêts à imprimer » seulement, non, non.
        self::assertTrue($d->peut(['ROLE_PRODUCTION'], 'voir', 'prets'));
        self::assertFalse($d->peut(['ROLE_PRODUCTION'], 'voir', 'conceptions'));
        self::assertFalse($d->peut(['ROLE_PRODUCTION'], 'voir', ModeleSousDossiers::AUTRES));
        self::assertFalse($d->peutQuelquePart(['ROLE_PRODUCTION'], 'ajouter'));
        self::assertSame('04 Prêts à imprimer seulement', DroitsDossiers::resumer($d->matrice()['ROLE_PRODUCTION']['voir'], $modele, 'voir'));
        // Livreur et rôle inconnu : rien.
        self::assertFalse($d->peutQuelquePart(['ROLE_LIVREUR'], 'voir'));
        self::assertFalse($d->peutQuelquePart(['ROLE_USER'], 'voir'));
    }

    public function testLaDirectionGardeToutMemeSiLaMatriceDitLeContraire(): void
    {
        $d = self::droits(['ROLE_ADMIN' => ['voir' => [], 'ajouter' => [], 'supprimer' => []]]);

        self::assertTrue($d->peut(['ROLE_ADMIN'], 'supprimer', 'bat'));
    }

    public function testAjouterOuSupprimerSupposeDeVoir(): void
    {
        $d = self::droits(['ROLE_PRODUCTION' => ['voir' => ['prets'], 'ajouter' => '*', 'supprimer' => ['conceptions', 'prets']]]);

        self::assertSame(['prets'], $d->matrice()['ROLE_PRODUCTION']['ajouter']);
        self::assertSame(['prets'], $d->matrice()['ROLE_PRODUCTION']['supprimer']);
        self::assertFalse($d->peut(['ROLE_PRODUCTION'], 'supprimer', 'conceptions'));
    }

    public function testPlusieursRolesSAdditionnent(): void
    {
        $d = self::droits();

        self::assertTrue($d->peut(['ROLE_PRODUCTION', 'ROLE_CAISSE_COMMANDE'], 'ajouter', 'documents'));
        self::assertTrue($d->peut(['ROLE_PRODUCTION', 'ROLE_CAISSE_COMMANDE'], 'voir', 'conceptions'));
    }

    public function testDroitParPersonne(): void
    {
        $d = self::droits();

        self::assertTrue($d->peut(['ROLE_LIVREUR', DroitsDossiers::PERSONNE], 'ajouter', 'bat'));
        self::assertFalse($d->peut(['ROLE_LIVREUR', DroitsDossiers::PERSONNE], 'supprimer', 'bat'));
    }

    public function testValeursInvalidesIgnorees(): void
    {
        $m = DroitsDossiers::lire(['pas un role' => ['voir' => '*'], 'ROLE_X' => ['voir' => ['inconnu', 'bat']]], (new ModeleSousDossiers())->cles());

        self::assertArrayNotHasKey('pas un role', $m);
        self::assertSame(['bat'], $m['ROLE_X']['voir']);
    }
}
