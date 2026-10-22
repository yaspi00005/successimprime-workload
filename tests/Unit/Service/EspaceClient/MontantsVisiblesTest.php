<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Service\Atelier\CatalogueAtelier;
use App\Service\EspaceClient\CoqueEspace;
use App\Service\EspaceClient\FilNotifications;
use App\Service\EspaceClient\NouvelleCommande;
use PHPUnit\Framework\TestCase;

/**
 * La règle des montants de l'espace client (CompteClient::montantsVisibles) :
 * un particulier voit tout ; dans une entreprise, le payeur et le valideur
 * voient les prix, jamais le demandeur ni la lecture seule.
 */
final class MontantsVisiblesTest extends TestCase
{
    public function testQuiVoitLesMontants(): void
    {
        $entreprise = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $particulier = new Clients();

        $attendus = [
            'particulier' => [self::compte($particulier, []), true],
            'titulaire' => [self::compte($entreprise, [CompteClient::DROIT_VALIDEUR, CompteClient::DROIT_PAYEUR, CompteClient::DROIT_ADMINISTRATEUR]), true],
            'valideur' => [self::compte($entreprise, [CompteClient::DROIT_VALIDEUR]), true],
            'payeur' => [self::compte($entreprise, [CompteClient::DROIT_PAYEUR]), true],
            'demandeur' => [self::compte($entreprise, []), false],
            'lecture' => [self::compte($entreprise, [CompteClient::DROIT_LECTURE]), false],
        ];
        foreach ($attendus as $qui => [$compte, $voit]) {
            self::assertSame($voit, $compte->montantsVisibles(), $qui);
            // Une seule règle : l'atelier 3D et les gabarits de l'espace (coque.montants) la lisent.
            self::assertSame($voit, CatalogueAtelier::montantsVisibles($compte), $qui.' (atelier)');
            self::assertSame($voit, CoqueEspace::droits($compte)['montants'], $qui.' (coque)');
        }

        // Le valideur voit les prix mais pas le solde ni les factures (droit payeur).
        self::assertFalse(CoqueEspace::droits($attendus['valideur'][0])['payeur']);
    }

    public function testEstimationDeNouvelleCommandeSansMontantPourLeDemandeur(): void
    {
        $estimation = ['total' => 54000, 'detail' => [['libelle' => 'Impression', 'montant' => 48000], ['libelle' => 'Œillets', 'montant' => 6000]]];

        self::assertSame(['montants' => true] + $estimation, NouvelleCommande::estimationPourLaPage($estimation, true));
        self::assertSame(['montants' => false], NouvelleCommande::estimationPourLaPage($estimation, false));
    }

    public function testMontantDuDevisDansLesNotifications(): void
    {
        $entreprise = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $devis = ['type' => 'devis', 'montant' => 120000];
        $facture = ['type' => 'facture', 'montant' => 45000];

        self::assertTrue(FilNotifications::montantVisible($devis, self::compte($entreprise, [CompteClient::DROIT_VALIDEUR])));
        self::assertTrue(FilNotifications::montantVisible($devis, self::compte($entreprise, [CompteClient::DROIT_PAYEUR])));
        self::assertFalse(FilNotifications::montantVisible($devis, self::compte($entreprise, [])));
        self::assertFalse(FilNotifications::montantVisible($devis, self::compte($entreprise, [CompteClient::DROIT_LECTURE])));
        // Les factures restent au payeur, même pour un valideur.
        self::assertFalse(FilNotifications::montantVisible($facture, self::compte($entreprise, [CompteClient::DROIT_VALIDEUR])));
        self::assertTrue(FilNotifications::montantVisible($facture, self::compte($entreprise, [CompteClient::DROIT_PAYEUR])));
    }

    /** @param list<string> $droits */
    private static function compte(Clients $client, array $droits): CompteClient
    {
        return (new CompteClient())->setClient($client)->setDroits($droits);
    }
}
