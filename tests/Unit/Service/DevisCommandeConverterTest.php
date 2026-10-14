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
}
