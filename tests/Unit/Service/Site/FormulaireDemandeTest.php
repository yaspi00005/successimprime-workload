<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\CommandesDetails;
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

    public function testQuantiteDonneeEnMetresCarres(): void
    {
        [$bache] = $this->bache();
        $saisie = ['nom' => 'Awa', 'telephone' => '76123456', 'produit' => '7', 'largeur' => '5', 'hauteur' => '5', 'unite_quantite' => 'm2'];

        // 3 m² d'autocollants de 5 × 5 cm, espacement 1 mm : 361 au m², 1 083 pièces ; la quantité envoyée par la page est ignorée.
        ['valeurs' => $v, 'erreurs' => $erreurs] = FormulaireDemande::valider('devis', $saisie + ['quantite_m2' => '3', 'quantite' => '99999'], [$bache]);
        self::assertSame([], $erreurs);
        self::assertSame(1083, $v['quantite']);
        self::assertSame(3.0, $v['quantiteM2']);

        // Avec l'espacement du réglage (3 mm : 324 au m²) et une virgule.
        self::assertSame(810, FormulaireDemande::valider('devis', $saisie + ['quantite_m2' => '2,5'], [$bache], 3.0)['valeurs']['quantite']);

        // En pièces (choix par défaut) : la surface tapée ne compte pas.
        $enPieces = FormulaireDemande::valider('devis', ['unite_quantite' => 'piece', 'quantite' => '1000', 'quantite_m2' => '3'] + $saisie, [$bache])['valeurs'];
        self::assertSame([1000, null], [$enPieces['quantite'], $enPieces['quantiteM2']]);
    }

    public function testSurfaceEnMetresCarresControlee(): void
    {
        [$bache] = $this->bache();
        $saisie = ['nom' => 'Awa', 'telephone' => '76123456', 'produit' => '7', 'largeur' => '5', 'hauteur' => '5', 'unite_quantite' => 'm2'];
        $erreur = static fn (array $plus): ?string => FormulaireDemande::valider('devis', $plus + $saisie, [$bache])['erreurs']['quantite_m2'] ?? null;

        self::assertSame('Surface en m², entre 0,01 et 10 000.', $erreur(['quantite_m2' => '']));
        self::assertSame('Surface en m², entre 0,01 et 10 000.', $erreur(['quantite_m2' => 'beaucoup']));
        self::assertSame('Surface en m², entre 0,01 et 10 000.', $erreur(['quantite_m2' => '20000']));
        self::assertSame('Moins d\'une pièce sur cette surface : augmentez-la.', $erreur(['quantite_m2' => '0,01', 'largeur' => '50', 'hauteur' => '50']));
        self::assertSame('Pièce plus grande que 1 m : indiquez la quantité en pièces.', $erreur(['quantite_m2' => '5', 'largeur' => '120']));
        self::assertSame('Cela fait plus de 100 000 pièces : réduisez la surface.', $erreur(['quantite_m2' => '500', 'largeur' => '1', 'hauteur' => '1']));

        $bache->setQuantiteMinimale(500);
        self::assertSame('Cela fait 361 pièces ; minimum pour ce produit : 500.', $erreur(['quantite_m2' => '1']));

        // Taille fausse : son erreur suffit.
        $r = FormulaireDemande::valider('devis', ['quantite_m2' => '3', 'largeur' => ''] + $saisie, [$bache]);
        self::assertArrayHasKey('largeur', $r['erreurs']);
        self::assertArrayNotHasKey('quantite_m2', $r['erreurs']);
    }

    public function testPiecesCompteesCommeLaLigneLesFacturera(): void
    {
        // 5,168 cm : la ligne garde 0,0517 m (51,7 mm), soit 18 colonnes et non 19 (51,68 mm).
        $ligne = (new CommandesDetails())->setTypeLigne('produit')->setModeCalcul('metre_carre')
            ->setLargeur(5.168 / 100)->setLongueur(0.05)->setQuantite(1)->setEspacementPiecesM2(1);
        self::assertSame(342, $ligne->getPiecesAuM2()['parM2']);

        self::assertSame(['pieces' => 342, 'm2' => 1.0], FormulaireDemande::quantiteDepuisM2('1', 5.168, 5.0, 1.0));
    }

    public function testQuantiteEnMetresCarresSeulementPourUnProduitAuMetreCarreALaMesure(): void
    {
        [$bache] = $this->bache();
        self::assertTrue(FormulaireDemande::piecesAuM2Possible($bache));

        $flyers = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Flyers'))->setModeCalcul('unite')->setPrixBase(50);
        (new \ReflectionProperty($flyers, 'id'))->setValue($flyers, 8);
        self::assertFalse(FormulaireDemande::piecesAuM2Possible($flyers));

        // Unité « m² » envoyée pour des flyers : ignorée, la quantité en pièces compte.
        $v = FormulaireDemande::valider('devis', ['nom' => 'Awa', 'telephone' => '76123456', 'produit' => '8', 'quantite' => '500', 'unite_quantite' => 'm2', 'quantite_m2' => '3'], [$flyers])['valeurs'];
        self::assertSame([500, null], [$v['quantite'], $v['quantiteM2']]);
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
