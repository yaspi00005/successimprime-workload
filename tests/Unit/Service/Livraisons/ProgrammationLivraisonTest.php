<?php

namespace App\Tests\Unit\Service\Livraisons;

use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Service\Livraisons\ProgrammationLivraison;
use PHPUnit\Framework\TestCase;

/** Programmer une livraison (maquette DB09) : créneaux pleins ou passés, lecture du formulaire, report sur le bon. */
final class ProgrammationLivraisonTest extends TestCase
{
    public function testCreneauxPleinsOuPassesBarres(): void
    {
        $jeudi = new \DateTimeImmutable('2026-10-22 00:00');
        $vendredi = new \DateTimeImmutable('2026-10-23 00:00');
        $grille = ProgrammationLivraison::grille([$jeudi, $vendredi], ['2026-10-23|16-18' => ProgrammationLivraison::CAPACITE], new \DateTimeImmutable('2026-10-22 09:30'));

        $jeudiCreneaux = array_column($grille[0]['creneaux'], 'plein', 'cle');
        // 9 h 30 : 8 h – 10 h et 10 h – 12 h sont passés (une heure avant au plus tard).
        self::assertSame(['08-10' => true, '10-12' => true, '14-16' => false, '16-18' => false], $jeudiCreneaux);
        self::assertTrue(array_column($grille[1]['creneaux'], 'plein', 'cle')['16-18']);
        self::assertFalse(array_column($grille[1]['creneaux'], 'plein', 'cle')['10-12']);
    }

    public function testLireDemandeUnCreneauLibreEtQuiRecoit(): void
    {
        $jours = ProgrammationLivraison::grille([new \DateTimeImmutable('2026-10-23 00:00')], ['2026-10-23|16-18' => 9], new \DateTimeImmutable('2026-10-22 18:00'));
        $base = ['adresse' => 'nouvelle', 'nouvelle_libelle' => 'Boutique Awa Mode', 'nouvelle_adresse' => 'Hamdallaye ACI, rue 315', 'jour' => '2026-10-23', 'nom' => 'Awa Diarra', 'telephone' => '+223 76 12 34 21'];

        [$plein, $erreurs] = ProgrammationLivraison::lire($base + ['creneau' => '16-18'], [], $jours, new \DateTimeImmutable());
        self::assertNull($plein);
        self::assertContains('Choisissez un jour et un créneau libres.', $erreurs);

        [$choix] = ProgrammationLivraison::lire($base + ['creneau' => '10-12', 'repere' => 'store violet', 'latitude' => '12.63921', 'longitude' => '-8.00291', 'paiement' => 'orange_money'], [], $jours, new \DateTimeImmutable());
        self::assertSame('livraison', $choix['mode']);
        self::assertSame('10-12', $choix['creneau']);
        self::assertSame('orange_money', $choix['paiement']);
        self::assertSame(12.63921, $choix['latitude']);
        self::assertSame('ven. 23/10 · 10 h – 12 h', ProgrammationLivraison::quand($choix));

        [, $sansNom] = ProgrammationLivraison::lire(['nom' => ''] + $base + ['creneau' => '10-12'], [], $jours, new \DateTimeImmutable());
        self::assertContains('Indiquez le nom et le téléphone de la personne qui reçoit.', $sansNom);
    }

    public function testAppliquerReporteSurLeBonPasEncoreParti(): void
    {
        $commande = (new Commandes())->setProgrammationLivraison([
            'mode' => 'livraison', 'libelle' => 'Boutique Awa Mode', 'adresse' => 'Hamdallaye ACI', 'repere' => 'store violet',
            'jour' => '2026-10-23', 'creneau' => '10-12', 'nom' => 'Awa Diarra', 'telephone' => '76123421', 'siAbsent' => 'Fanta (vendeuse)', 'paiement' => 'livreur',
        ]);
        $bon = new BonLivraison();

        ProgrammationLivraison::appliquer($commande, $bon);

        self::assertTrue($commande->estALivrer());
        self::assertSame('2026-10-23', $bon->getPrevuLe()?->format('Y-m-d'));
        self::assertSame('10 h – 12 h', $bon->getCreneau());
        self::assertSame('Boutique Awa Mode · Hamdallaye ACI', $bon->getAdresseLivraison());
        self::assertSame('Fanta (vendeuse)', $bon->getAbsentNom());
        self::assertSame('store violet', $bon->getRepere());
    }
}
