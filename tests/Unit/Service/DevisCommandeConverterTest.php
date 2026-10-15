<?php

namespace App\Tests\Unit\Service;

use App\Entity\Commandes;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Service\DevisCommandeConverter;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class DevisCommandeConverterTest extends TestCase
{
    public function testLaCommandeIssueDUnDevisAccepteRecoitSonNumero(): void
    {
        $devis = (new Devis())->setStatut(Devis::STATUT_ACCEPTE);
        $devis->addDevisDetail((new DevisDetails())->setDesignation('Bâche 300 × 100 cm')->setQuantite(1));

        $commande = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->createStub(Connection::class));
        $em->method('persist')->willReturnCallback(static function (object $objet) use (&$commande): void {
            if ($objet instanceof Commandes) {
                $commande = $objet;
            }
        });
        // Le premier enregistrement donne son identifiant à la commande.
        $em->method('flush')->willReturnCallback(static function () use (&$commande): void {
            if ($commande instanceof Commandes && null === $commande->getId()) {
                (new \ReflectionProperty(Commandes::class, 'id'))->setValue($commande, 777);
            }
        });

        $resultat = (new DevisCommandeConverter($em))->convertir($devis);

        self::assertSame($commande, $resultat);
        self::assertSame('CMD-000777-'.(new \DateTimeImmutable())->format('m-Y'), $resultat->getNumero());
        self::assertSame($resultat, $devis->getCommande());
        self::assertSame(Devis::STATUT_CONVERTI, $devis->getStatut());
    }

    public function testFraisDeLivraisonEtObjetSuiventLeDevis(): void
    {
        $devis = (new Devis())->setStatut(Devis::STATUT_ACCEPTE)->setFraisLivraison(2000)->setObjet('Impressions');
        $devis->addDevisDetail((new DevisDetails())->setDesignation('Flyers A5')->setQuantite(500)->setTotalHt(50000)->setTotalTtc(50000));
        $devis->recalculerTotaux();

        $commande = (new DevisCommandeConverter($this->em()))->convertir($devis);

        self::assertSame(2000, $commande->getFraisLivraison());
        self::assertSame('Impressions', $commande->getObjet());
        self::assertTrue($commande->estALivrer(), 'des frais de livraison font une commande à livrer');
        self::assertSame(52000, $commande->getTotalTtc());

        $sansFrais = (new Devis())->setStatut(Devis::STATUT_ACCEPTE);
        $sansFrais->addDevisDetail((new DevisDetails())->setDesignation('Flyers A5')->setQuantite(1));
        self::assertFalse((new DevisCommandeConverter($this->em()))->convertir($sansFrais)->estALivrer(), 'sans frais : retrait, comme avant');
    }

    private function em(): EntityManagerInterface
    {
        $commande = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->createStub(Connection::class));
        $em->method('persist')->willReturnCallback(static function (object $objet) use (&$commande): void {
            if ($objet instanceof Commandes) {
                $commande = $objet;
            }
        });
        $em->method('flush')->willReturnCallback(static function () use (&$commande): void {
            if ($commande instanceof Commandes && null === $commande->getId()) {
                (new \ReflectionProperty(Commandes::class, 'id'))->setValue($commande, 778);
            }
        });

        return $em;
    }
}
