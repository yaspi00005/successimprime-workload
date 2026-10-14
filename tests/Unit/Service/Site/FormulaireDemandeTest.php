<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\DemandeDevis;
use App\Entity\Finition;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Service\Site\FormulaireDemande;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormulaireDemandeTest extends TestCase
{
    private const SECRET = 'secret-de-test';

    public function testDemandeDeDevisComplete(): void
    {
        [$bache, $oeillets] = $this->bache();

        ['valeurs' => $v, 'erreurs' => $erreurs] = FormulaireDemande::valider('devis', [
            'nom' => '  Moussa   Traoré ', 'telephone' => '76 12 34 56', 'email' => 'moussa@exemple.ml',
            'produit' => '7', 'largeur' => '300', 'hauteur' => '100,5', 'quantite' => '2',
            'finitions' => [(string) $oeillets->getId(), '999', 'abc'], 'message' => 'Pour la boutique',
        ], [$bache]);

        self::assertSame([], $erreurs);
        self::assertSame('Moussa Traoré', $v['nom']);
        self::assertSame('22376123456', $v['telephone']);
        self::assertSame($bache, $v['produit']);
        self::assertSame(300.0, $v['largeur']);
        self::assertSame(100.5, $v['hauteur']);
        self::assertSame(2, $v['quantite']);
        self::assertSame([$oeillets->getId()], $v['finitions'], 'les finitions inconnues sont ignorées');
    }

    /** @return iterable<string, array{string, string}> */
    public static function telephones(): iterable
    {
        yield 'avec espaces' => ['76 12 34 56', '22376123456'];
        yield 'avec +223' => ['+223 76 12 34 56', '22376123456'];
        yield 'avec 00223' => ['0022376123456', '22376123456'];
        yield 'tirets' => ['66-00-00-01', '22366000001'];
    }

    #[DataProvider('telephones')]
    public function testTelephoneMalienNormalise(string $saisi, string $attendu): void
    {
        ['valeurs' => $v, 'erreurs' => $erreurs] = FormulaireDemande::valider('contact', ['nom' => 'Awa', 'telephone' => $saisi, 'message' => 'Bonjour à vous'], []);

        self::assertArrayNotHasKey('telephone', $erreurs);
        self::assertSame($attendu, $v['telephone']);
    }

    public function testTelephoneFauxRefuse(): void
    {
        foreach (['', '7612345', '761234567', '+33 6 12 34 56 78', 'abcdefgh'] as $saisi) {
            $erreurs = FormulaireDemande::valider('contact', ['nom' => 'Awa', 'telephone' => $saisi, 'message' => 'Bonjour'], [])['erreurs'];
            self::assertSame('Indiquez un numéro malien à 8 chiffres.', $erreurs['telephone'] ?? null, $saisi);
        }
    }

    public function testChampsControlesCoteServeur(): void
    {
        [$bache] = $this->bache();

        $erreurs = FormulaireDemande::valider('devis', [
            'nom' => 'A', 'telephone' => '76123456', 'email' => 'pas-un-email', 'produit' => '999',
            'quantite' => '0', 'message' => str_repeat('x', DemandeDevis::MESSAGE_MAX + 1),
        ], [$bache])['erreurs'];

        self::assertSame(['nom', 'email', 'message', 'produit', 'quantite'], array_keys($erreurs));
    }

    public function testTailleExigeePourUnProduitALaMesure(): void
    {
        [$bache] = $this->bache();

        $erreurs = FormulaireDemande::valider('devis', ['nom' => 'Awa', 'telephone' => '76123456', 'produit' => '7', 'largeur' => '', 'hauteur' => '9000'], [$bache])['erreurs'];

        self::assertArrayHasKey('largeur', $erreurs);
        self::assertArrayHasKey('hauteur', $erreurs);
    }

    public function testQuantiteMinimaleDuProduit(): void
    {
        [$bache] = $this->bache();
        $bache->setQuantiteMinimale(5);
        $saisie = ['nom' => 'Awa', 'telephone' => '76123456', 'produit' => '7', 'largeur' => '200', 'hauteur' => '100'];

        self::assertSame('Minimum pour ce produit : 5.', FormulaireDemande::valider('devis', $saisie + ['quantite' => '2'], [$bache])['erreurs']['quantite'] ?? null);
        self::assertSame(5, FormulaireDemande::valider('devis', $saisie, [$bache])['valeurs']['quantite'], 'vide : le minimum');
    }

    public function testSansProduitIlFautDecrireLeProjet(): void
    {
        $sans = FormulaireDemande::valider('devis', ['nom' => 'Awa', 'telephone' => '76123456'], []);
        $avec = FormulaireDemande::valider('devis', ['nom' => 'Awa', 'telephone' => '76123456', 'message' => 'Une enseigne lumineuse 2 m'], []);

        self::assertSame('Choisissez un produit ou décrivez votre projet.', $sans['erreurs']['message'] ?? null);
        self::assertSame([], $avec['erreurs']);
        self::assertNull($avec['valeurs']['produit']);
    }

    public function testMessageDeContact(): void
    {
        $vide = FormulaireDemande::valider('contact', ['nom' => 'Awa', 'telephone' => '76123456', 'objet' => 'commande', 'message' => ' '], []);
        $ok = FormulaireDemande::valider('contact', ['nom' => 'Awa', 'telephone' => '76123456', 'objet' => '<script>', 'message' => "Bonjour\x07, ma commande ?"], []);

        self::assertSame('Écrivez votre message.', $vide['erreurs']['message'] ?? null);
        self::assertSame('autre', $ok['valeurs']['objet'], 'objet inconnu : autre');
        self::assertSame('Bonjour, ma commande ?', $ok['valeurs']['message'], 'caractères de contrôle retirés');
    }

    public function testChampPiege(): void
    {
        self::assertFalse(FormulaireDemande::estPiege(['nom' => 'Awa']));
        self::assertFalse(FormulaireDemande::estPiege([FormulaireDemande::PIEGE => '  ']));
        self::assertTrue(FormulaireDemande::estPiege([FormulaireDemande::PIEGE => 'https://spam.example']));
    }

    public function testTempsDeSaisieMinimumSigne(): void
    {
        $affiche = 1_760_400_000;
        $jeton = FormulaireDemande::jetonDelai($affiche, self::SECRET);

        self::assertNull(FormulaireDemande::verifierDelai($jeton, $affiche + 30, self::SECRET));
        self::assertStringStartsWith('Envoi trop rapide', (string) FormulaireDemande::verifierDelai($jeton, $affiche + 2, self::SECRET));
        self::assertStringStartsWith('La page a expiré', (string) FormulaireDemande::verifierDelai($jeton, $affiche + 13 * 3600, self::SECRET));
        self::assertStringStartsWith('La page a expiré', (string) FormulaireDemande::verifierDelai($jeton, $affiche + 30, 'autre-secret'), 'signé');
        self::assertStringStartsWith('La page a expiré', (string) FormulaireDemande::verifierDelai(($affiche - 600).substr($jeton, 10), $affiche + 30, self::SECRET), 'heure changée');
        self::assertStringStartsWith('La page a expiré', (string) FormulaireDemande::verifierDelai(null, $affiche, self::SECRET));
    }

    public function testPreremplissageDuCalculateur(): void
    {
        [$bache, $oeillets, $ourlet] = $this->bache();

        $p = FormulaireDemande::preremplir(['produit' => '7', 'largeur' => '300', 'hauteur' => '1,5', 'quantite' => '2', 'finitions' => [(string) $oeillets->getId(), '999']], [$bache]);
        self::assertSame(['produit' => '7', 'largeur' => '300', 'hauteur' => '1,5', 'quantite' => '2', 'finitions' => [$oeillets->getId()]], $p);

        // Liste séparée par des virgules acceptée aussi.
        self::assertSame([$oeillets->getId(), $ourlet->getId()], FormulaireDemande::preremplir(['produit' => '7', 'finitions' => '1,2'], [$bache])['finitions']);

        // Valeurs fausses ignorées, sans erreur.
        self::assertSame(
            ['produit' => '', 'largeur' => '', 'hauteur' => '', 'quantite' => '', 'finitions' => []],
            FormulaireDemande::preremplir(['produit' => '999', 'largeur' => '<b>', 'quantite' => '-3', 'finitions' => ['x' => ['y']]], [$bache]),
        );
        self::assertSame('', FormulaireDemande::preremplir(['produit' => ['7']], [$bache])['produit']);
        self::assertSame('', FormulaireDemande::preremplir(['produit' => '7', 'largeur' => '9000'], [$bache])['largeur'], 'au-delà de 50 m');
    }

    public function testCentimetres(): void
    {
        self::assertSame(120.5, FormulaireDemande::centimetres('120,5'));
        self::assertSame(1000.0, FormulaireDemande::centimetres('1 000'));
        self::assertNull(FormulaireDemande::centimetres('0'));
        self::assertNull(FormulaireDemande::centimetres('5001'));
        self::assertNull(FormulaireDemande::centimetres('1e3'.'x'));
    }

    /** @return array{ProduitConfiguration, ProduitConfigurationFinition, ProduitConfigurationFinition} */
    private function bache(): array
    {
        $bache = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Bâche'))
            ->setModeDimension('mesure')->setModeCalcul('metre_carre')->setPrixBase(8000);
        (new \ReflectionProperty($bache, 'id'))->setValue($bache, 7);
        $oeillets = (new ProduitConfigurationFinition())->setFinition((new Finition())->setNom('Œillets'))
            ->setPayante(true)->setPrix(3000)->setModeCalcul('unite');
        $ourlet = (new ProduitConfigurationFinition())->setFinition((new Finition())->setNom('Ourlet'))
            ->setPayante(true)->setPrix(1000)->setModeCalcul('forfait');
        foreach ([[$oeillets, 1], [$ourlet, 2]] as [$f, $id]) {
            (new \ReflectionProperty($f, 'id'))->setValue($f, $id);
            $bache->addConfigurationFinition($f);
        }

        return [$bache, $oeillets, $ourlet];
    }
}
