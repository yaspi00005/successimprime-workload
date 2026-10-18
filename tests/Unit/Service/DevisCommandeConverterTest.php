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
        $devis = (new Devis())->setStatut(Devis::STATUT_ACCEPTE)->setInterlocuteur('  Awa   Koné ');
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
        // La personne à qui s'adressait le devis a commandé (« Commandé par »).
        self::assertSame('Awa Koné', $resultat->getDemandeePar());
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

    public function testUneLigneAuTotalDOrigineLeGardeDansLaCommande(): void
    {
        $ligne = (new DevisDetails())->setDesignation('Flyers A5')->setModeCalcul('exemplaire')->setQuantite(1000)->setPrixUnitaire(25)
            ->setRemise(3)->setTotalHt(21875)->setTotalTtc(21875)->figerTotal();
        $devis = (new Devis())->setStatut(Devis::STATUT_ACCEPTE);
        $devis->addDevisDetail($ligne);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($this->createStub(Connection::class));

        $copie = (new DevisCommandeConverter($em))->convertir($devis)->getCommandesDetails()->first();

        self::assertTrue($copie->isTotalFige());
        self::assertSame(21875, $copie->calculerTotaux()->getTotalHt(), 'réenregistrée sans changement, la ligne de la commande garde le total du devis');
    }

    public function testUneLigneEnPiecesAuM2GardeSonCalculDansLaCommande(): void
    {
        // 1 000 autocollants de 5 × 5 cm à 6 500 F le m², en pièces au m² : 2,78 m² facturés.
        $ligne = (new DevisDetails())->setDesignation('Autocollants')->setModeCalcul('metre_carre')->setLargeur(0.05)->setLongueur(0.05)
            ->setQuantite(1000)->setPrixUnitaire(6500)->setEspacementPiecesM2(1)->calculerTotaux();
        $devis = (new Devis())->setStatut(Devis::STATUT_ACCEPTE);
        $devis->addDevisDetail($ligne);

        $copie = (new DevisCommandeConverter($this->em()))->convertir($devis)->getCommandesDetails()->first();

        self::assertSame('metre_carre', $copie->getModeCalcul(), 'avant, la ligne au m² devenait « unité »');
        self::assertSame(1.0, $copie->getEspacementPiecesM2());
        self::assertSame(18070, $copie->getTotalHt());
        self::assertSame(18070, $copie->calculerTotaux()->getTotalHt(), 'réenregistrée, la commande garde le total du devis');
    }
}
