<?php

namespace App\Tests\Unit\Service\Devis;

use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Service\Devis\OptionsDevis;
use App\Service\EspaceClient\DelaisCommande;
use App\Service\EspaceClient\MesDevisClient;
use PHPUnit\Framework\TestCase;

final class OptionsDevisTest extends TestCase
{
    public function testLireEcarteLesMauvaisesSaisies(): void
    {
        $options = OptionsDevis::lire([
            ['quantite' => '2 000', 'prixUnitaire' => '32'],
            ['quantite' => '500', 'prixUnitaire' => '48,5'],
            ['quantite' => '1000', 'prixUnitaire' => '38'], // quantité du devis
            ['quantite' => '500', 'prixUnitaire' => '40'],  // doublon
            ['quantite' => '0', 'prixUnitaire' => '10'],
            ['quantite' => '300', 'prixUnitaire' => 'abc'],
        ], 1000);

        self::assertSame([['quantite' => 500, 'prixUnitaire' => '48.5'], ['quantite' => 2000, 'prixUnitaire' => '32']], $options);
    }

    public function testChoixDuClientRefaitLesMontants(): void
    {
        $devis = $this->devis();
        $flyers = $devis->getDevisDetails()[1];
        $options = OptionsDevis::options($flyers);
        self::assertSame([500, 1000, 2000], array_column($options, 'quantite'));
        self::assertSame([24000, 38000, 64000], array_column($options, 'ttc'));

        self::assertTrue(OptionsDevis::appliquer($devis, [(string) $flyers->getPublicId() => '2000']));
        self::assertSame(2000, $flyers->getQuantite());
        self::assertSame(64000, $flyers->getTotalTtc());
        self::assertSame(21000 + 64000 - 4000, $devis->getTotalTtc());
        // La quantité d'avant reste au choix, on peut y revenir.
        self::assertSame([500, 1000], array_column($flyers->getOptionsQuantite(), 'quantite'));
        self::assertSame([(string) $flyers->getPublicId() => 2000], $devis->getOptionsChoisies());
    }

    public function testQuantiteNonProposeeEcartee(): void
    {
        $devis = $this->devis();
        $flyers = $devis->getDevisDetails()[1];
        self::assertFalse(OptionsDevis::appliquer($devis, [(string) $flyers->getPublicId() => 750, 'inconnue' => 10]));
        self::assertSame(1000, $flyers->getQuantite());
        self::assertSame(55000, $devis->getTotalTtc());
    }

    public function testPromesseEtDuree(): void
    {
        self::assertSame('2026-10-19 13:05', MesDevisClient::promesse(new \DateTimeImmutable('2026-10-19 09:05'))->format('Y-m-d H:i'));
        // Après 14 h : le lendemain à midi ; samedi soir : lundi.
        self::assertSame('2026-10-20 12:00', MesDevisClient::promesse(new \DateTimeImmutable('2026-10-19 15:00'))->format('Y-m-d H:i'));
        self::assertSame('2026-10-19 12:00', MesDevisClient::promesse(new \DateTimeImmutable('2026-10-17 17:00'))->format('Y-m-d H:i'));
        self::assertSame('lun. 19/10 avant 13 h 05', MesDevisClient::jourHeure(new \DateTimeImmutable('2026-10-19 13:05')));
        self::assertSame('2 h 05', MesDevisClient::duree(125));
        self::assertSame('3 h', MesDevisClient::duree(180));
        self::assertSame('2 j', MesDevisClient::duree(2880));
        self::assertSame(72, DelaisCommande::heuresDuTexte('3 jours après le BAT'));
        self::assertSame(48, DelaisCommande::heuresDuTexte('48 h'));
        self::assertNull(DelaisCommande::heuresDuTexte('rapide'));
    }

    private function devis(): Devis
    {
        $devis = new Devis();
        $ligne = static fn (string $nom, int $q, string $pu, string $mode): DevisDetails => (new DevisDetails())->setDesignation($nom)->setModeCalcul($mode)->setQuantite($q)->setPrixUnitaire($pu);
        $devis->addDevisDetail($ligne('Bâche', 1, '21000', 'forfait')->calculerTotaux());
        $devis->addDevisDetail($ligne('Flyers', 1000, '38', 'unite')->setOptionsQuantite([['quantite' => 500, 'prixUnitaire' => '48'], ['quantite' => 2000, 'prixUnitaire' => '32']])->calculerTotaux());
        $devis->setRemise(4000);
        $devis->recalculerTotaux();

        return $devis;
    }
}
