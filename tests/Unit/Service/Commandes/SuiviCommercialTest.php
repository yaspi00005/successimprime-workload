<?php

namespace App\Tests\Unit\Service\Commandes;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Service\Commandes\SuiviCommercial;
use PHPUnit\Framework\TestCase;

final class SuiviCommercialTest extends TestCase
{
    private static function commande(bool $confirmee = true): Commandes
    {
        $commande = (new Commandes())->setStatut($confirmee);
        $commande->addCommandesDetail((new CommandesDetails())->setStatutProduction(CommandesDetails::PRODUCTION_A_PRODUIRE));

        return $commande;
    }

    public function testEtapeSelonLaLigneLaMoinsAvancee(): void
    {
        $commande = self::commande();

        self::assertSame(SuiviCommercial::FICHIER, SuiviCommercial::etape($commande, [1, 4], false));
        self::assertSame(SuiviCommercial::IMPRESSION, SuiviCommercial::etape($commande, [2, 4], false));
        self::assertSame(SuiviCommercial::PRETE, SuiviCommercial::etape($commande, [4, 6], false));
        self::assertSame(SuiviCommercial::LIVRAISON, SuiviCommercial::etape($commande, [5], false));
        self::assertSame(SuiviCommercial::LIVREE, SuiviCommercial::etape($commande, [6, 6], false));
    }

    public function testAcompteAttenduSeulementAvantImpression(): void
    {
        $commande = self::commande();

        self::assertSame(SuiviCommercial::ACOMPTE, SuiviCommercial::etape($commande, [1], true));
        // Déjà à l'impression : l'atelier a lancé sans l'acompte (client à crédit), on ne la bloque pas.
        self::assertSame(SuiviCommercial::IMPRESSION, SuiviCommercial::etape($commande, [2], true));
    }

    public function testCommandeEnLigneNonConfirmee(): void
    {
        self::assertSame(SuiviCommercial::A_CONFIRMER, SuiviCommercial::etape(self::commande(false), [0], true));
    }

    public function testUneLigneSansProductionNeRetientPasLaCommande(): void
    {
        self::assertSame([6], SuiviCommercial::rangs(['livree', 'sans_production']));
        self::assertSame([4], SuiviCommercial::rangs(['sans_production']));
    }

    public function testInitiales(): void
    {
        self::assertSame('HN', SuiviCommercial::initiales('Hôtel Niger'));
        self::assertSame('PF', SuiviCommercial::initiales('Pharmacie du Fleuve'));
        self::assertSame('ÉL', SuiviCommercial::initiales('École Les Étoiles'));
        self::assertSame('AD', SuiviCommercial::initiales('Awa Diarra'));
        self::assertSame('SA', SuiviCommercial::initiales('Sali'));
    }

    public function testFiltresEtCompteurs(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-18 10:00'); // un dimanche
        $ligne = static fn (string $etape, ?string $pour, bool $retard = false): array => [
            'etape' => $etape, 'ouverte' => true, 'enRetard' => $retard, 'pourLe' => null !== $pour ? new \DateTimeImmutable($pour) : null, 'reste' => 1000,
        ];
        $lignes = [
            $ligne(SuiviCommercial::FICHIER, '2026-10-15', true),
            $ligne(SuiviCommercial::IMPRESSION, '2026-10-18'),
            $ligne(SuiviCommercial::ACOMPTE, '2026-10-22'),
            $ligne(SuiviCommercial::PRETE, null),
        ];

        self::assertCount(1, SuiviCommercial::filtrerPour($lignes, 'aujourdhui', $maintenant));
        self::assertCount(3, SuiviCommercial::filtrerPour($lignes, 'semaine', $maintenant));
        self::assertCount(1, SuiviCommercial::filtrerEtape($lignes, 'retard'));
        self::assertCount(1, SuiviCommercial::filtrerEtape($lignes, SuiviCommercial::ACOMPTE));

        $compteurs = SuiviCommercial::compteurs($lignes);
        self::assertSame(4, $compteurs['toutes']);
        self::assertSame(1, $compteurs['retard']);

        $cartes = SuiviCommercial::cartes($lignes, $maintenant, false);
        self::assertSame(4, $cartes['enCours']);
        self::assertSame(1, $cartes['aujourdhui']);
        self::assertSame(1, $cartes['acomptes']);
        self::assertNull($cartes['reste'], 'Sans le droit Paiements, pas de reste à encaisser.');
        self::assertSame(4000, SuiviCommercial::cartes($lignes, $maintenant, true)['reste']);
    }
}
