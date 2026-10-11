<?php

namespace App\Tests\Unit\Service\Clients;

use App\Controller\ClientsFicheController;
use App\Entity\Clients;
use App\Service\Clients\EditionClient;
use PHPUnit\Framework\TestCase;

final class EditionClientTest extends TestCase
{
    public function testSepareLePrenomDuNom(): void
    {
        self::assertSame(['Dr Fatoumata', 'Konaté'], EditionClient::separer('  Dr Fatoumata   Konaté '));
        self::assertSame(['Awa', 'Awa'], EditionClient::separer('Awa'));
    }

    public function testFormatsDuJournal(): void
    {
        self::assertSame('300 000 F', EditionClient::francs(300000));
        self::assertSame('5 %', EditionClient::pourcent(5.0));
        self::assertSame('7,5 %', EditionClient::pourcent(7.5));
    }

    public function testInstantaneLisible(): void
    {
        $c = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE)->setRaisonSociale('Hôtel Niger')
            ->setTypeClient('B2B')->setRemiseHabituelle(5)->setDelaiPaiement('30_jours_fdm')->setPlafondCredit(300000)->setAcompte(50);
        $i = EditionClient::instantane($c);

        self::assertSame('Entreprise', $i['typeCompte']);
        self::assertSame('Entreprise', $i['typeClient']);
        self::assertSame('5 %', $i['remiseHabituelle']);
        self::assertSame('30 jours fin de mois', $i['delaiPaiement']);
        self::assertSame('300 000 F', $i['plafondCredit']);
        self::assertSame('50 %', $i['acompte']);
        self::assertNull($i['prenom'], 'le prénom d\'une organisation vient du contact, pas de la fiche');
        self::assertSame('Non', $i['marketing']);
    }

    public function testAjoutTerrainOrganisation(): void
    {
        $d = ClientsFicheController::depuisTerrain(['type' => 'entreprise', 'nomTerrain' => 'Garage du Carrefour', 'contactTerrain' => 'Modibo Coulibaly · gérant', 'telephone' => '79 12 34 54', 'whatsapp' => '1', 'besoin' => 'Enseigne']);

        self::assertSame('Garage du Carrefour', $d['raisonSociale']);
        self::assertSame('Modibo Coulibaly', $d['contacts'][0]['nom']);
        self::assertSame('gérant', $d['contacts'][0]['fonction']);
        self::assertSame('principal', $d['contacts'][0]['role']);
        self::assertTrue($d['contacts'][0]['whatsapp']);
    }

    public function testAjoutTerrainParticulier(): void
    {
        $d = ClientsFicheController::depuisTerrain(['type' => 'particulier', 'nomTerrain' => 'Awa Traoré', 'telephone' => '77889900']);

        self::assertSame('Awa Traoré', $d['nomComplet']);
        self::assertArrayNotHasKey('contacts', $d);
        self::assertArrayNotHasKey('raisonSociale', $d);
    }
}
