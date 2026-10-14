<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\ProduitClient;
use App\Service\CommandeProduitClient;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\MesProduits;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\Synchro\Synchro;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class MesProduitsTest extends TestCase
{
    private int $enAttente = 0;

    /** @var list<object> */
    private array $persistes = [];

    public function testFaconsDeSaisir(): void
    {
        self::assertSame(['piece', 'm2'], MesProduits::saisies($this->bache()));
        self::assertSame(['m2'], MesProduits::saisies($this->vinyle()));
        self::assertSame(['lot'], MesProduits::saisies($this->flyers()));
        self::assertSame(['piece'], MesProduits::saisies($this->produit('Tote bag', ProduitClient::UNITE_PIECE, 3500)));
        // Au m² mais sans format : rien à convertir, on compte des pièces.
        self::assertSame(['piece'], MesProduits::saisies($this->produit('Sticker', ProduitClient::UNITE_PIECE_OU_M2, 500)));
    }

    public function testFormatFixeEnM2AvecLeChoixAuDessous(): void
    {
        $service = $this->service();

        // Maquette P02 : 20 m² de bâches 400 × 150 → 4 bâches (24 m²) recommandées, ou 3 (18 m²).
        $r = $service->calculer($this->bache(), 20, 'm2');
        self::assertSame([4, 24.0, 116000, '4 pièces', '= 24 m²'], [$r['pieces'], $r['surface'], $r['montant'], $r['resume'], $r['detail']]);
        self::assertSame(['pieces' => 3, 'surface' => 18.0, 'montant' => 87000], $r['alternative']);
        self::assertFalse($r['inferieur']);

        $dessous = $service->calculer($this->bache(), 20, 'm2', true);
        self::assertSame([3, 87000, true], [$dessous['pieces'], $dessous['montant'], $dessous['inferieur']]);

        // En pièces, la surface suit.
        $pieces = $service->calculer($this->bache(), 3, 'piece');
        self::assertSame(['3 pièces', '= 18 m²', 87000], [$pieces['resume'], $pieces['detail'], $pieces['montant']]);
    }

    public function testRouleauEtLots(): void
    {
        $service = $this->service();

        // Maquette P03 : 6 m² de vinyle sur laize 1,37 m → 4,4 m linéaires, 45 000 F.
        $r = $service->calculer($this->vinyle(), 6, 'm2');
        self::assertSame(['6 m²', '= 4,4 m linéaires', 45000], [$r['resume'], $r['detail'], $r['montant']]);

        // 600 flyers → 2 lots de 500.
        $lots = $service->calculer($this->flyers(), 600, 'lot');
        self::assertSame(['1 000 ex.', 60000], [$lots['resume'], $lots['montant']]);

        // Une saisie non permise retombe sur celle du produit.
        self::assertSame('m2', $service->calculer($this->vinyle(), 6, 'piece')['saisie']);
    }

    public function testQuantiteRefusee(): void
    {
        $service = $this->service();
        foreach ([0.0, -2.0, MesProduits::QUANTITE_MAX + 1.0] as $quantite) {
            try {
                $service->calculer($this->bache(), $quantite, 'piece');
                self::fail('Quantité '.$quantite.' acceptée.');
            } catch (\DomainException) {
            }
        }
        $this->expectExceptionMessage('Minimum de commande : 5.');
        $service->calculer($this->bache()->setQuantiteMinimum(5), 2, 'piece');
    }

    public function testCommandeAConfirmerAvecLePrixDeLaFiche(): void
    {
        $client = $this->client(7);
        $bache = $this->bache($client);
        $tshirt = $this->produit('T-shirt logo', ProduitClient::UNITE_PIECE, 3500, $client);
        $bache->setBatValide(new ControlePrePresse());

        $commande = $this->service()->commander($client, [
            ['produit' => $bache, 'quantite' => 20.0, 'saisie' => 'm2', 'inferieur' => false],
            ['produit' => $tshirt, 'quantite' => 50.0, 'saisie' => 'piece', 'inferieur' => false],
        ], new \DateTimeImmutable('2026-10-13 10:00'));

        self::assertFalse($commande->isStatut()); // à confirmer par l'atelier
        self::assertSame(Commandes::CANAL_EN_LIGNE, $commande->getCanal());
        self::assertSame(116000 + 175000, $commande->getTotalTtc());
        self::assertSame([$commande], $this->persistes);
        [$l1, $l2] = $commande->getCommandesDetails()->toArray();
        self::assertSame([$bache, 20.0, 'm2', 4, false], [$l1->getProduitClient(), $l1->getQuantiteDemandee(), $l1->getUniteDemandee(), (int) $l1->getQuantite(), $l1->isPrePresseNecessaire()]);
        // Sans BAT validé sur la fiche, la ligne repasse par la PAO.
        self::assertSame([$tshirt, true], [$l2->getProduitClient(), $l2->isPrePresseNecessaire()]);
    }

    public function testSelectionRefusee(): void
    {
        $client = $this->client(7);
        $service = $this->service();
        $bache = $this->bache($client);
        $cas = [
            'vide' => [],
            'produit d\'un autre client' => [['produit' => $this->bache($this->client(8)), 'quantite' => 1.0, 'saisie' => 'piece', 'inferieur' => false]],
            'deux fois le même' => array_fill(0, 2, ['produit' => $bache, 'quantite' => 1.0, 'saisie' => 'piece', 'inferieur' => false]),
            'trop de produits' => array_fill(0, MesProduits::SELECTION_MAX + 1, ['produit' => $bache, 'quantite' => 1.0, 'saisie' => 'piece', 'inferieur' => false]),
        ];
        foreach ($cas as $nom => $selection) {
            try {
                $service->commander($client, $selection, new \DateTimeImmutable());
                self::fail('Sélection acceptée : '.$nom);
            } catch (\DomainException) {
            }
        }
        self::assertSame([], $this->persistes);

        $this->enAttente = NouvelleCommande::EN_ATTENTE_MAX;
        $this->expectException(\DomainException::class);
        $service->commander($client, [['produit' => $bache, 'quantite' => 1.0, 'saisie' => 'piece', 'inferieur' => false]], new \DateTimeImmutable());
    }

    public function testLigneRecueAuLocalVerifiee(): void
    {
        $client = $this->client(7);
        $commande = (new Commandes())->setClients($client);

        // Produit d'un autre client : le lien tombe et la PAO revient.
        $autre = $this->bache($this->client(8))->setBatValide(new ControlePrePresse());
        $ligne = (new CommandesDetails())->setCommande($commande)->setProduitClient($autre)->setPrePresseNecessaire(false)->setBatValide(true);
        Synchro::verifierLigneEnLigne($ligne);
        self::assertSame([null, true, false], [$ligne->getProduitClient(), $ligne->isPrePresseNecessaire(), $ligne->isBatValide()]);

        // Son produit avec un BAT validé : la ligne part sans PAO.
        $sien = $this->bache($client)->setBatValide(new ControlePrePresse());
        $ligne = (new CommandesDetails())->setCommande($commande)->setProduitClient($sien)->setPrePresseNecessaire(false)->setBatValide(true);
        Synchro::verifierLigneEnLigne($ligne);
        self::assertSame([$sien, false, true], [$ligne->getProduitClient(), $ligne->isPrePresseNecessaire(), $ligne->isBatValide()]);
    }

    private function service(): MesProduits
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('count')->willReturnCallback(fn (): int => $this->enAttente);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);
        $em->method('persist')->willReturnCallback(function (object $o): void { $this->persistes[] = $o; });
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $f): mixed => $f($em));

        return new MesProduits($em, new CommandeProduitClient(), new NouvelleCommande($em), (new \ReflectionClass(EspaceClient::class))->newInstanceWithoutConstructor());
    }

    private function client(int $id): Clients
    {
        $client = new Clients();
        (new \ReflectionProperty(Clients::class, 'id'))->setValue($client, $id);

        return $client;
    }

    private function produit(string $nom, string $unite, int $prix, ?Clients $client = null): ProduitClient
    {
        static $id = 0;
        $p = (new ProduitClient())->setClient($client ?? $this->client(7))->setReference('PC-T'.++$id)->setNom($nom)->setUniteVente($unite)->setPrixUnitaire($prix);
        (new \ReflectionProperty(ProduitClient::class, 'id'))->setValue($p, $id);

        return $p;
    }

    private function bache(?Clients $client = null): ProduitClient
    {
        return $this->produit('Bâche façade', ProduitClient::UNITE_PIECE_OU_M2, 29000, $client)->setFormat(4.0, 1.5);
    }

    private function vinyle(): ProduitClient
    {
        return $this->produit('Vinyle vitrine', ProduitClient::UNITE_M2, 7500)->setFormat(1.37, null);
    }

    private function flyers(): ProduitClient
    {
        return $this->produit('Flyers A5', ProduitClient::UNITE_LOT, 30000)->setTailleLot(500)->setPosesParFeuille(8);
    }
}
