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
    public function testReperesVerifies(): void
    {
        $brut = [
            ['i' => 0, 'x' => 0.5, 'y' => 0.25, 't' => "  Agrandir\n le logo  "],
            ['i' => 1, 'x' => 1.7, 'y' => -2, 't' => 'Hors de l\'image : ramené au bord'],
            ['i' => 2, 'x' => 0.5, 'y' => 0.5, 't' => 'Image qui n\'existe pas'],
            ['i' => 0, 'x' => 0.5, 'y' => 0.5, 't' => '   '],
            ['i' => 0, 'x' => 'abc', 'y' => 0.5, 't' => 'Position illisible'],
            'pas un repère',
            ['i' => 0, 'x' => 0.1, 'y' => 0.1, 't' => str_repeat('a', 300)],
        ];

        $reperes = EspaceClient::reperes($brut, 2);

        self::assertSame([
            ['i' => 0, 'x' => 0.5, 'y' => 0.25, 't' => 'Agrandir le logo'],
            ['i' => 1, 'x' => 1.0, 'y' => 0.0, 't' => 'Hors de l\'image : ramené au bord'],
        ], \array_slice($reperes, 0, 2));
        self::assertSame(EspaceClient::REPERE_TEXTE_MAX, mb_strlen($reperes[2]['t']));
        self::assertCount(3, $reperes);
        self::assertSame([], EspaceClient::reperes(null, 2));
        self::assertSame([], EspaceClient::reperes($brut, 0), 'BAT en PDF : pas de repère');
        self::assertCount(EspaceClient::REPERES_MAX, EspaceClient::reperes(array_fill(0, 30, ['i' => 0, 'x' => 0.1, 'y' => 0.1, 't' => 'x']), 1));
    }

    public function testPointsAVerifierAvantDeValiderLeBat(): void
    {
        self::assertSame('La taille : 20 × 10 cm', EspaceClient::verificationsBat($this->ligne('Plaques', '0.20', '0.10', 40))['taille']);
        self::assertSame('La taille : 3 m × 1 m', EspaceClient::verificationsBat($this->ligne('Bâche', '3', '1', 2))['taille']);
        self::assertSame('La taille : 85 × 200 cm', EspaceClient::verificationsBat($this->ligne('Roll-up', '0.85', '2', 1))['taille']);
        self::assertSame('La taille et le format', EspaceClient::verificationsBat($this->ligne('Cartes', null, null, 500))['taille']);
        self::assertSame(['orthographe', 'coordonnees', 'couleurs', 'taille'], array_keys(EspaceClient::verificationsBat(null)));
    }

    public function testValiderDemandeChaquePointCoche(): void
    {
        $ligne = $this->ligne('Bâche', '3', '1', 2);
        $tout = ['taille', 'couleurs', 'orthographe', 'coordonnees', 'autre chose'];

        self::assertSame(['L\'orthographe des textes', 'Les numéros de téléphone et les adresses', 'Les couleurs et le logo', 'La taille : 3 m × 1 m'], EspaceClient::verificationsCochees($ligne, $tout));
        self::assertNull(EspaceClient::verificationsCochees($ligne, ['orthographe', 'coordonnees', 'couleurs']), 'un point manque');
        self::assertNull(EspaceClient::verificationsCochees($ligne, []));
        self::assertNull(EspaceClient::verificationsCochees($ligne, 'orthographe'), 'pas une liste');
        self::assertNull(EspaceClient::verificationsCochees($ligne, null));
        self::assertNull(EspaceClient::verificationsCochees($ligne, [['orthographe'], ['coordonnees'], ['couleurs'], ['taille']]), 'des listes au lieu des textes');
        self::assertNotNull(EspaceClient::verificationsCochees($ligne, [...$tout, ['x']]), 'une liste en plus est ignorée');
    }

    public function testCorrectionAuDelaDesGratuitesSansMontant(): void
    {
        self::assertSame('au-delà des 2 corrections gratuites : peut être facturée', EspaceClient::auDelaDesGratuites(2));
        self::assertSame('au-delà de la correction gratuite : peut être facturée', EspaceClient::auDelaDesGratuites(1));
        self::assertSame('correction qui peut être facturée', EspaceClient::auDelaDesGratuites(0));
    }
}
