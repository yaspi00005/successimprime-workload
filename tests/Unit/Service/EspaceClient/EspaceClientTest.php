<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Service\EspaceClient\EspaceClient;
use App\Service\SuiviCommandeService;
use PHPUnit\Framework\TestCase;

final class EspaceClientTest extends TestCase
{
    private function ligne(string $designation, ?string $largeur, ?string $longueur, int $quantite): CommandesDetails
    {
        return (new CommandesDetails())->setDesignation($designation)->setLargeur($largeur)->setLongueur($longueur)->setQuantite($quantite);
    }

    public function testTitreAvecTaille(): void
    {
        self::assertSame('Banderole 300 × 100 × 2', EspaceClient::titreLigne($this->ligne('Banderole', '300', '100', 2)));
        self::assertSame('Roll-up 0,9 × 2 × 5', EspaceClient::titreLigne($this->ligne('Roll-up', '0.90', '2.00', 5)));
        self::assertSame('Cartes de visite × 500', EspaceClient::titreLigne($this->ligne('Cartes de visite', null, null, 500)));
    }

    public function testTitreSansRepeterLaTailleDejaDansLaDesignation(): void
    {
        self::assertSame('Bâche (400 x 150 cm) × 2', EspaceClient::titreLigne($this->ligne('Bâche (400 x 150 cm)', '4', '1.5', 2)));
    }

    public function testTitreDeCommandeAvecPlusieursLignes(): void
    {
        $c = new Commandes();
        $c->addCommandesDetail($this->ligne('Flyers A5', null, null, 1000));
        $c->addCommandesDetail($this->ligne('Roll-up', '85', '200', 1));
        $c->addCommandesDetail($this->ligne('Bâche', '300', '100', 1));

        self::assertSame('Flyers A5 × 1 000 + 2 autres', EspaceClient::titre($c));
    }

    public function testEtapesClientPreteARetirer(): void
    {
        $c = (new Commandes())->setModeLivraison(Commandes::LIVRAISON_RETRAIT);
        $c->addCommandesDetail($this->ligne('Cartes', null, null, 500)->setStatutProduction(CommandesDetails::PRODUCTION_NON_REQUISE)->setPrePresseNecessaire(false));
        $etapes = (new SuiviCommandeService())->etapesClient($c);

        self::assertSame(['recue', 'bat', 'impression', 'finition', 'prete', 'livree'], array_column($etapes, 'cle'));
        self::assertSame('Prête à retirer', $etapes[4]['libelle']);
        self::assertSame('faite', $etapes[4]['etat']);
        self::assertSame('en_cours', $etapes[5]['etat']);
        self::assertSame('Retirée', $etapes[5]['libelle']);
    }
}
