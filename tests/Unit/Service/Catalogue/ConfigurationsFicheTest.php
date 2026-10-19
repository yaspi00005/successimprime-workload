<?php

namespace App\Tests\Unit\Service\Catalogue;

use App\Entity\CategorieProduit;
use App\Entity\Format;
use App\Entity\PrixHistorique;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use App\Service\Catalogue\EditionProduit;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

/**
 * Configurations saisies dans la fiche produit (docs/produits.md,
 * « Configurations : tout dans la fiche ») : ajout limité aux
 * compatibilités du produit et du support, modification historisée.
 */
final class ConfigurationsFicheTest extends TestCase
{
    /** @var list<object> */
    private array $persistes = [];

    /** @var array<string, object> */
    private array $entites = [];

    private static function avecId(object $e, int $id): object
    {
        $r = new \ReflectionProperty($e, 'id');
        $r->setValue($e, $id);

        return $e;
    }

    private function edition(): EditionProduit
    {
        $repo = $this->createStub(EntityRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $repo->method('findBy')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);
        $em->method('find')->willReturnCallback(fn (string $classe, mixed $id): ?object => $this->entites[$classe.'#'.(int) $id] ?? null);
        $em->method('persist')->willReturnCallback(function (object $e): void { $this->persistes[] = $e; });

        return new EditionProduit($em);
    }

    /** @return array{Produits, TypesImpression, Supports, Format, Format} */
    private function produit(): array
    {
        $numerique = self::avecId((new TypesImpression())->setNom('Numérique'), 1);
        $papier = self::avecId((new Supports())->setNom('Couché 350 g'), 2);
        $a5 = self::avecId((new Format())->setNom('A5'), 3);
        $a3 = self::avecId((new Format())->setNom('A3'), 4);
        $papier->addTypeImpression($numerique)->addFormat($a5);
        $categorie = self::avecId((new CategorieProduit())->setNom('Papeterie'), 9);
        $p = self::avecId((new Produits())->setNom('Flyers')->setCode('FLY')->setModeCalcul('unite')->setCategorieProduit($categorie), 7);
        $p->addTypeImpression($numerique)->addSupport($papier)->addFormat($a5)->addFormat($a3);
        foreach ([$numerique, $papier, $a5, $a3, $categorie] as $e) {
            $this->entites[$e::class.'#'.$e->getId()] = $e;
        }

        return [$p, $numerique, $papier, $a5, $a3];
    }

    /** @param array<string, mixed> $plus */
    private static function envoi(array $plus): array
    {
        return ['nom' => 'Flyers', 'code' => 'FLY', 'categorie' => 9, 'actif' => '1', 'en_ligne' => '1', 'configurations_envoyees' => '1'] + $plus;
    }

    public function testUneConfigurationCompatibleSAjouteDansLaFiche(): void
    {
        [$p] = $this->produit();
        $erreurs = $this->edition()->appliquer($p, self::envoi(['configuration_nouvelle' => ['type' => '1', 'support' => '2', 'format' => '3', 'mode' => 'unite', 'prixBase' => '75', 'prixB2B' => '60', 'quantiteMinimale' => '50']]), false, null);

        self::assertSame([], $erreurs);
        self::assertCount(1, $p->getConfigurations());
        $c = $p->getConfigurations()->first();
        self::assertSame(75, $c->getPrixBase());
        self::assertSame(60, $c->getPrixB2B());
        self::assertSame(50, $c->getQuantiteMinimale());
        self::assertSame('format', $c->getModeDimension());
        self::assertTrue($c->isActive());
        self::assertSame($p, $c->getProduit());
        self::assertContains($c, $this->persistes, 'persistée par Doctrine : le journal de synchronisation la voit');
    }

    public function testLeSupportAffineLesFormatsEtLeProduitSesCompatibilites(): void
    {
        [$p] = $this->produit();
        $edition = $this->edition();

        // A3 est compatible avec le produit, pas avec le support.
        $erreurs = $edition->appliquer($p, self::envoi(['configuration_nouvelle' => ['type' => '1', 'support' => '2', 'format' => '4', 'prixBase' => '75']]), false, null);
        self::assertContains('Nouvelle configuration : ce format n\'est pas compatible avec le support.', $erreurs);

        // Type absent des compatibilités cochées dans le même envoi.
        $erreurs = $edition->appliquer($p, self::envoi(['compatibilites' => '1', 'types' => [], 'supports' => ['2'], 'formats' => ['3'], 'configuration_nouvelle' => ['type' => '1', 'support' => '2', 'format' => '3', 'prixBase' => '75']]), false, null);
        self::assertStringContainsString('type d\'impression n\'est pas compatible avec le produit', implode(' ', $erreurs));

        // Revendeur au-dessus du prix, prix manquant.
        self::assertContains('Nouvelle configuration : le prix revendeur dépasse le prix public.', $edition->appliquer($p, self::envoi(['configuration_nouvelle' => ['type' => '1', 'support' => '2', 'format' => '3', 'prixBase' => '75', 'prixB2B' => '80']]), false, null));
        self::assertContains('Nouvelle configuration : donnez son prix.', $edition->appliquer($p, self::envoi(['configuration_nouvelle' => ['type' => '1', 'support' => '2']]), false, null));
        self::assertCount(0, $p->getConfigurations());
    }

    public function testUneCombinaisonExistanteNeSeRecreePas(): void
    {
        [$p, $numerique, $papier, $a5] = $this->produit();
        $p->addConfiguration(self::avecId((new ProduitConfiguration())->setTypeImpression($numerique)->setSupport($papier)->setFormat($a5)->setPrixBase(75)->setActive(false), 30));

        $erreurs = $this->edition()->appliquer($p, self::envoi(['configuration_nouvelle' => ['type' => '1', 'support' => '2', 'format' => '3', 'prixBase' => '80']]), false, null);

        self::assertSame(['Nouvelle configuration : « Flyers · Couché 350 g · A5 » existe déjà (désactivée : réactivez-la).'], $erreurs);
    }

    public function testLaModificationEtLaDesactivationVontDansLHistorique(): void
    {
        [$p, $numerique, $papier, $a5] = $this->produit();
        $c = self::avecId((new ProduitConfiguration())->setTypeImpression($numerique)->setSupport($papier)->setFormat($a5)->setPrixBase(75)->setPrixB2B(60), 30);
        $p->addConfiguration($c);

        $erreurs = $this->edition()->appliquer($p, self::envoi(['configurations' => [30 => ['prixBase' => '80', 'prixB2B' => '60', 'quantiteMinimale' => '1']]]), false, null);

        self::assertSame([], $erreurs);
        self::assertSame(80, $c->getPrixBase());
        self::assertFalse($c->isActive(), 'case « En vente » décochée');
        $champs = array_map(static fn (PrixHistorique $h): string => $h->getChamp(), array_values(array_filter($this->persistes, static fn (object $e): bool => $e instanceof PrixHistorique)));
        self::assertSame(['prixBase', 'active'], $champs);

        // Revendeur au-dessus du prix : rien n'est changé.
        $erreurs = $this->edition()->appliquer($p, self::envoi(['configurations' => [30 => ['prixBase' => '50', 'prixB2B' => '60', 'active' => '1']]]), false, null);
        self::assertSame(['Flyers · Couché 350 g · A5 : le prix revendeur dépasse le prix public.'], $erreurs);
        self::assertSame(80, $c->getPrixBase());
    }
}
