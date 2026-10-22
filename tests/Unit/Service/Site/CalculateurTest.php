<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\Entreprise;
use App\Entity\Finition;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Entity\TypesImpression;
use App\Repository\AppareilAutoriseRepository;
use App\Repository\EntrepriseRepository;
use App\Security\AccesEnLigne;
use App\Service\NotificationService;
use App\Service\Site\Calculateur;
use App\Service\Site\JetonsSite;
use App\Twig\SiteExtension;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

final class CalculateurTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, ?float}>
     */
    public static function cotesProvider(): iterable
    {
        yield 'entier' => ['300', 300.0];
        yield 'virgule' => ['120,5', 120.5];
        yield 'point et espaces' => [' 1 000.25 ', 1000.25];
        yield 'nombre' => [80, 80.0];
        yield 'zéro' => ['0', null];
        yield 'négatif' => ['-5', null];
        yield 'texte' => ['abc', null];
        yield 'notation scientifique' => ['1e5', null];
        yield 'trop de chiffres' => ['1234567', null];
        yield 'trop de décimales' => ['12.345', null];
        yield 'tableau' => [['300'], null];
        yield 'absent' => [null, null];
    }

    #[DataProvider('cotesProvider')]
    public function testCoteBornee(mixed $saisie, ?float $attendu): void
    {
        self::assertSame($attendu, Calculateur::cote($saisie));
    }

    /**
     * @return iterable<string, array{mixed, ?int}>
     */
    public static function entiersProvider(): iterable
    {
        yield 'chiffres' => ['12', 12];
        yield 'espaces' => ['1 000', 1000];
        yield 'nombre' => [5, 5];
        yield 'négatif' => ['-1', null];
        yield 'décimal' => ['1.5', null];
        yield 'trop long' => ['9999999999', null];
        yield 'tableau' => [['1'], null];
    }

    #[DataProvider('entiersProvider')]
    public function testEntierBorne(mixed $saisie, ?int $attendu): void
    {
        self::assertSame($attendu, Calculateur::entier($saisie));
    }

    public function testLectureDeLaSaisie(): void
    {
        $saisie = Calculateur::lire(['produit' => '7', 'categorie' => 'X', 'largeur' => '300', 'quantite' => '', 'finitions' => ['3', 'x', '3', '-4', '5']]);

        self::assertSame(['produit' => 7, 'categorie' => null, 'precedent' => null, 'largeur' => 300.0, 'hauteur' => null, 'quantite' => 0, 'finitions' => [3, 5]], $saisie);
        // Sans quantité : arrivée sur la page.
        self::assertNull(Calculateur::lire([])['quantite']);
        self::assertSame([], Calculateur::lire(['finitions' => 'abc'])['finitions']);
        self::assertCount(Calculateur::FINITIONS_MAX, Calculateur::lire(['finitions' => range(1, 100)])['finitions']);
        // La catégorie est la clé d'un univers, jamais un identifiant.
        self::assertSame('grand-format', Calculateur::lire(['categorie' => 'grand-format'])['categorie']);
        self::assertNull(Calculateur::lire(['categorie' => ['grand-format']])['categorie']);
    }

    public function testConfigurationDemandeeSinonCelleDeLaCategorie(): void
    {
        [$bache] = $this->bache();
        $cartes = $this->cartes();
        $configs = [$bache, $cartes];
        $groupes = [['nom' => 'Grand format', 'cle' => 'grand-format', 'ids' => [1, 9], 'options' => [['id' => 10]]], ['nom' => 'Petit format', 'cle' => 'petit-format', 'ids' => [2], 'options' => [['id' => 20]]]];

        self::assertSame($cartes, Calculateur::configuration($configs, $groupes, ['produit' => 20, 'categorie' => 'grand-format']));
        self::assertSame($cartes, Calculateur::configuration($configs, $groupes, ['produit' => 999, 'categorie' => 'petit-format']));
        self::assertSame($bache, Calculateur::configuration($configs, $groupes, ['produit' => null, 'categorie' => 'grand-format']));
        self::assertSame($bache, Calculateur::configuration($configs, $groupes, ['produit' => null, 'categorie' => 'inconnu']));
        self::assertSame($bache, Calculateur::configuration($configs, $groupes, ['produit' => null, 'categorie' => null]));
        self::assertNull(Calculateur::configuration([], [], ['produit' => 1, 'categorie' => null]));
    }

    public function testValeursProposeesALArrivee(): void
    {
        [$bache, $oeillets] = $this->bache();

        $valeurs = Calculateur::valeurs($bache, Calculateur::lire(['produit' => '10']));

        // Pas de taille imposée : 1 m × 1 m ; finitions cochées par défaut.
        self::assertSame(['produit' => 10, 'largeur' => 100.0, 'hauteur' => 100.0, 'quantite' => 1, 'finitions' => [$oeillets->getId()]], $valeurs);
    }

    public function testValeursDuVisiteurGardees(): void
    {
        [$bache] = $this->bache();

        $valeurs = Calculateur::valeurs($bache, Calculateur::lire(['produit' => '10', 'precedent' => '10', 'largeur' => '300', 'hauteur' => '', 'quantite' => '3', 'finitions' => ['2', '77']]));

        // Hauteur effacée : le calcul la demandera ; finition d'un autre produit ignorée.
        self::assertSame(['produit' => 10, 'largeur' => 300.0, 'hauteur' => null, 'quantite' => 3, 'finitions' => [2]], $valeurs);
    }

    public function testTailleDuProduitQuandUnCoteEstEfface(): void
    {
        [$bache] = $this->bache();
        $bache->setLargeurDefaut(0.85)->setLongueurDefaut(2);

        $valeurs = Calculateur::valeurs($bache, Calculateur::lire(['produit' => '10', 'precedent' => '10', 'largeur' => '', 'hauteur' => '150', 'quantite' => '1']));

        self::assertSame([85.0, 150.0], [$valeurs['largeur'], $valeurs['hauteur']]);
    }

    public function testChangementDeProduitSansJavaScript(): void
    {
        $cartes = $this->cartes()->setQuantiteMinimale(100);

        // Le formulaire montrait la bâche (precedent=10) : minimum et finitions des cartes.
        $valeurs = Calculateur::valeurs($cartes, Calculateur::lire(['produit' => '20', 'precedent' => '10', 'largeur' => '300', 'hauteur' => '100', 'quantite' => '2', 'finitions' => ['1']]));

        self::assertSame(['produit' => 20, 'largeur' => null, 'hauteur' => null, 'quantite' => 100, 'finitions' => []], $valeurs);
    }

    public function testResultatAuPrixNormalSeulement(): void
    {
        [$bache, $oeillets] = $this->bache();
        $bache->setPrixB2B(6123);

        $resultat = $this->calculateur(true)->resultat($bache, ['produit' => 10, 'largeur' => 300.0, 'hauteur' => 100.0, 'quantite' => 2, 'finitions' => [$oeillets->getId()]]);

        // 3 m² × 2 à 8 000 F + œillets 3 000 F par exemplaire.
        self::assertSame(54000, $resultat['total']);
        self::assertSame("54\u{A0}000\u{A0}FCFA", $resultat['totalTexte']);
        self::assertSame(['produit', 'nom', 'total', 'totalTexte', 'lignes', 'detail', 'erreur', 'pret', 'liens'], array_keys($resultat));
        self::assertNull($resultat['pret'], 'Sans délais réglés, pas de date annoncée.');
        self::assertSame(['commander', 'devis', 'whatsapp'], array_keys($resultat['liens']));
        self::assertSame([
            ['libelle' => 'Surface', 'valeur' => "3,00\u{A0}m² × 2 = 6,00\u{A0}m²"],
            ['libelle' => 'Prix unitaire', 'valeur' => "8\u{A0}000\u{A0}FCFA le m²"],
            ['libelle' => 'Machine', 'valeur' => 'Solvant 3,20 m'],
            ['libelle' => 'Finitions', 'valeur' => 'Œillets'],
            ['libelle' => 'Délai', 'valeur' => Calculateur::DELAI],
        ], $resultat['lignes']);
        self::assertSame(['Impression', 'Œillets'], array_column($resultat['detail'], 'libelle'));

        $json = (string) json_encode($resultat);
        self::assertStringNotContainsString('6123', $json);
        self::assertStringNotContainsStringIgnoringCase('b2b', $json);
        self::assertStringNotContainsStringIgnoringCase('cout', $json);
        self::assertStringNotContainsStringIgnoringCase('marge', $json);

        $j = new JetonsSite('secret-de-test');
        self::assertSame('/espace/commandes/nouvelle?produit='.$j->encoder(JetonsSite::CONFIGURATION, 10).'&largeur=300&hauteur=100&quantite=2&finitions%5B0%5D='.$j->encoder(JetonsSite::FINITION, 1), $resultat['liens']['commander']);
        // Le lien du site porte les jetons du produit et des finitions, jamais leurs identifiants.
        $jetons = new JetonsSite('secret-de-test');
        self::assertSame($jetons->encoder(JetonsSite::CONFIGURATION, 10), $resultat['produit']);
        self::assertStringStartsWith('/demander-un-devis?produit='.$jetons->encoder(JetonsSite::CONFIGURATION, 10).'&largeur=300', (string) $resultat['liens']['devis']);
        self::assertStringContainsString('finitions%5B0%5D='.$jetons->encoder(JetonsSite::FINITION, 1), (string) $resultat['liens']['devis']);
        self::assertStringContainsString(rawurlencode('Estimation du site'), (string) $resultat['liens']['whatsapp']);
    }

    public function testSansPageDevisPasDeLien(): void
    {
        $resultat = $this->calculateur(false)->resultat($this->cartes(), ['produit' => 20, 'largeur' => null, 'hauteur' => null, 'quantite' => 500, 'finitions' => []]);

        self::assertSame(25000, $resultat['total']);
        self::assertSame('Quantité', $resultat['lignes'][0]['libelle']);
        self::assertNull($resultat['liens']['devis']);
        self::assertSame('/espace/commandes/nouvelle?produit='.(new JetonsSite('secret-de-test'))->encoder(JetonsSite::CONFIGURATION, 20).'&quantite=500', $resultat['liens']['commander']);
    }

    public function testMachineNonPublieeCachee(): void
    {
        [$bache] = $this->bache();
        $bache->getTypeImpression()?->setPublie(false);

        $resultat = $this->calculateur(false)->resultat($bache, ['produit' => 10, 'largeur' => 100.0, 'hauteur' => 100.0, 'quantite' => 1, 'finitions' => []]);

        self::assertNotContains('Machine', array_column($resultat['lignes'], 'libelle'));
    }

    /**
     * @return iterable<string, array{int, ?float, string}>
     */
    public static function refusProvider(): iterable
    {
        yield 'quantité vide' => [0, 100.0, 'Indiquez une quantité.'];
        yield 'trop d\'exemplaires' => [Calculateur::QUANTITE_MAX + 1, 100.0, 'demandez un devis'];
        yield 'plus de 50 m' => [1, 6000.0, 'Pour plus de 50 m'];
        yield 'taille manquante' => [1, null, 'Indiquez la largeur et la hauteur'];
    }

    #[DataProvider('refusProvider')]
    public function testSaisieRefuseeSansCommande(int $quantite, ?float $largeur, string $message): void
    {
        [$bache] = $this->bache();

        $resultat = $this->calculateur(true)->resultat($bache, ['produit' => 10, 'largeur' => $largeur, 'hauteur' => 100.0, 'quantite' => $quantite, 'finitions' => []]);

        self::assertStringContainsString($message, (string) $resultat['erreur']);
        self::assertNull($resultat['total']);
        self::assertSame([], $resultat['lignes']);
        // Le devis reste proposé (c'est la bonne porte au-delà des bornes), pas la commande.
        self::assertNull($resultat['liens']['commander']);
        self::assertNotNull($resultat['liens']['devis']);
    }

    public function testCoteDansUneAdresse(): void
    {
        self::assertSame('300', Calculateur::cm(300.0));
        self::assertSame('120.5', Calculateur::cm(120.5));
        self::assertSame('0.25', Calculateur::cm(0.25));
    }

    private function calculateur(bool $pageDevis): Calculateur
    {
        $routes = new RouteCollection();
        $routes->add('app_espace_nouvelle_commande', new Route('/espace/commandes/nouvelle'));
        if ($pageDevis) {
            $routes->add('app_site_devis', new Route('/demander-un-devis'));
        }
        $urls = new UrlGenerator($routes, new RequestContext());

        $acces = new AccesEnLigne(false, $this->createStub(AppareilAutoriseRepository::class), $this->createStub(EntityManagerInterface::class), (new \App\Tests\Unit\Security\FabriqueCodes())->codes(), $this->createStub(NotificationService::class));
        $entreprises = $this->createStub(EntrepriseRepository::class);
        $entreprises->method('parCode')->willReturn((new Entreprise())->setCode('mdg_success')->setTelephone('+223 70 38 69 12'));
        $entreprises->method('dansLOrdre')->willReturn([]);

        return new Calculateur($urls, new SiteExtension($urls, $entreprises, $acces, null, new JetonsSite('secret-de-test')));
    }

    /** @return array{ProduitConfiguration, ProduitConfigurationFinition, ProduitConfigurationFinition} */
    private function bache(): array
    {
        $bache = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Bâche (démo)'))
            ->setModeDimension('mesure')->setModeCalcul('metre_carre')->setPrixBase(8000)
            ->setTypeImpression((new TypesImpression())->setNom('Solvant 3,20 m')->setPublie(true));
        (new \ReflectionProperty($bache, 'id'))->setValue($bache, 10);
        $oeillets = (new ProduitConfigurationFinition())->setFinition((new Finition())->setNom('Œillets'))
            ->setPayante(true)->setPrix(3000)->setModeCalcul('unite')->setSelectionneeParDefaut(true);
        $ourlet = (new ProduitConfigurationFinition())->setFinition((new Finition())->setNom('Ourlet'))
            ->setPayante(true)->setPrix(1000)->setModeCalcul('forfait');
        foreach ([[$oeillets, 1], [$ourlet, 2]] as [$f, $id]) {
            (new \ReflectionProperty($f, 'id'))->setValue($f, $id);
            $bache->addConfigurationFinition($f);
        }

        return [$bache, $oeillets, $ourlet];
    }

    private function cartes(): ProduitConfiguration
    {
        $cartes = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Cartes de visite'))
            ->setModeDimension('aucune')->setModeCalcul('unite')->setPrixBase(50);
        (new \ReflectionProperty($cartes, 'id'))->setValue($cartes, 20);

        return $cartes;
    }
}
