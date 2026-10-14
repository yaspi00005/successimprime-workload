<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\Clients;
use App\Entity\DemandeDevis;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Entity\Finition;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\Site\PreparationDemande;
use PHPUnit\Framework\TestCase;

final class PreparationDemandeTest extends TestCase
{
    private \DateTimeImmutable $le;

    protected function setUp(): void
    {
        $this->le = new \DateTimeImmutable('2026-10-14 10:30');
    }

    public function testLigneAuTarifPublicCommeLeCalculateur(): void
    {
        [$bache, $oeillets] = $this->bache();
        $bache->setPrixB2B(5000);

        $ligne = PreparationDemande::ligneDevis($bache, 2, 300, 100, [$oeillets->getId()]);
        $estimation = NouvelleCommande::estimer($bache, 2, 300, 100, [$oeillets->getId()], false);

        self::assertSame($estimation['total'], $ligne->getTotalTtc(), 'même total que NouvelleCommande::estimer, prix public');
        self::assertSame(54000, $ligne->getTotalTtc());
        self::assertSame(DevisDetails::TYPE_PRODUIT, $ligne->getTypeLigne());
        self::assertNull($ligne->getProduitConfiguration(), 'ligne manuelle : la taille demandée est gardée');
        self::assertEqualsWithDelta(3.0, (float) $ligne->getLargeur(), 0.001, 'en mètres, à la taille demandée');
        self::assertCount(1, $ligne->getFinitions());
        self::assertSame(6000, $ligne->getFinitions()->first()->getMontant());
    }

    public function testInconnuDevientProspectAvecFicheEtDevisBrouillon(): void
    {
        [$bache, $oeillets] = $this->bache();
        $demande = $this->demande($bache)->addFinition($oeillets->getFinition());

        $r = PreparationDemande::construire($demande, null, $this->le);

        self::assertTrue($r['nouveauClient']);
        $client = $r['client'];
        self::assertInstanceOf(Clients::class, $client);
        self::assertSame('Traoré', $client->getNom());
        self::assertSame('Moussa', $client->getPrenom());
        self::assertSame('76123456', $client->getTelephone());
        self::assertSame('site', $client->getOrigine());
        self::assertStringStartsWith('TMP-', (string) $client->getCode());

        $devis = $r['devis'];
        self::assertInstanceOf(Devis::class, $devis);
        self::assertSame(Devis::STATUT_BROUILLON, $devis->getStatut(), 'rien n\'est envoyé ni confirmé');
        self::assertSame($client, $devis->getClients());
        self::assertCount(1, $devis->getDevisDetails());
        self::assertSame(54000, $devis->getTotalTtc());
        self::assertStringContainsString('W-DD-0001', (string) $devis->getObservation());
        self::assertStringContainsString('non vérifié', (string) $devis->getObservation());
        self::assertStringContainsString('Finitions : Œillets', (string) $devis->getObservation());

        $prospect = $r['prospect'];
        self::assertNotNull($prospect);
        self::assertSame('site', $prospect->getSource());
        self::assertSame($client, $prospect->getClient());
        self::assertSame(54000, $prospect->getMontantEstime());
        self::assertSame('2026-10-14 00:00', $prospect->getProchaineRelance()?->format('Y-m-d H:i'), 'à rappeler le jour même');
        self::assertNull($r['aChiffrer']);
    }

    public function testClientConnuGardeSaFicheSansProspect(): void
    {
        [$bache] = $this->bache();
        $client = (new Clients())->setCode('CLI-000042')->setNom('Diallo')->setPrenom('Fanta')->setTelephone('76123456');

        $r = PreparationDemande::construire($this->demande($bache), $client, $this->le);

        self::assertFalse($r['nouveauClient']);
        self::assertSame($client, $r['client']);
        self::assertSame($client, $r['devis']?->getClients());
        self::assertNull($r['prospect']);
        self::assertSame('Diallo', $client->getNom(), 'la fiche existante n\'est pas changée par le visiteur');
    }

    public function testHorsLimitesLeDevisResteAChiffrer(): void
    {
        [$bache] = $this->bache();
        $bache->setQuantiteMaximale(10);
        $demande = $this->demande($bache)->setQuantite(50);

        $r = PreparationDemande::construire($demande, null, $this->le);

        self::assertNotNull($r['aChiffrer']);
        self::assertCount(0, $r['devis']?->getDevisDetails() ?? []);
        self::assertSame(0, $r['devis']?->getTotalTtc());
        self::assertStringContainsString('À chiffrer', (string) $r['devis']?->getObservation());
        self::assertNull($r['prospect']?->getMontantEstime());
    }

    public function testProjetSansProduitDonneUnDevisVide(): void
    {
        $demande = DemandeDevis::nouvelle('devis', 'Awa', '22366000001', $this->le)->setMessage('Une enseigne lumineuse de 2 m');
        $demande->setNumero('W-DD-0002');

        $r = PreparationDemande::construire($demande, null, $this->le);

        self::assertSame('Awa', $r['client']?->getNom());
        self::assertSame('-', $r['client']?->getPrenom());
        self::assertCount(0, $r['devis']?->getDevisDetails() ?? []);
        self::assertSame('Une enseigne lumineuse de 2 m', $r['prospect']?->getBesoin());
    }

    public function testMessageDeContact(): void
    {
        $question = DemandeDevis::nouvelle('contact', 'Awa Keita', '22366000001', $this->le)->setObjet('commande')->setMessage('Ma commande est prête ?');
        $devis = DemandeDevis::nouvelle('contact', 'Awa Keita', '22366000001', $this->le)->setObjet('devis')->setMessage('Une enseigne lumineuse');
        $client = (new Clients())->setCode('CLI-000042')->setTelephone('66000001');

        $r = PreparationDemande::construire($question, null, $this->le);
        self::assertNull($r['devis']);
        self::assertNull($r['prospect']);
        self::assertNull($r['client']);

        $r = PreparationDemande::construire($devis, null, $this->le);
        self::assertNull($r['devis'], 'pas de devis sans produit ni taille');
        self::assertSame('site', $r['prospect']?->getSource());
        self::assertNull($r['prospect']?->getClient());

        $r = PreparationDemande::construire($devis, $client, $this->le);
        self::assertSame($client, $r['client']);
        self::assertNull($r['prospect'], 'déjà client');
    }

    public function testFinitionsRetrouveesParLeurFinitionDuCatalogue(): void
    {
        [$bache, $oeillets, $ourlet] = $this->bache();
        $demande = $this->demande($bache)->addFinition($ourlet->getFinition())->addFinition(new Finition());

        self::assertSame([$ourlet->getId()], PreparationDemande::finitionsChoisies($demande));
        unset($oeillets);
    }

    private function demande(ProduitConfiguration $config): DemandeDevis
    {
        $d = DemandeDevis::nouvelle('devis', 'Moussa Traoré', '22376123456', $this->le)
            ->setConfiguration($config)->setDesignation('Bâche')->setTaille(300, 100)->setQuantite(2)
            ->setMessage('Pour la boutique');
        $d->setNumero('W-DD-0001');

        return $d;
    }

    /** @return array{ProduitConfiguration, ProduitConfigurationFinition, ProduitConfigurationFinition} */
    private function bache(): array
    {
        $bache = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Bâche'))
            ->setModeDimension('mesure')->setModeCalcul('metre_carre')->setPrixBase(8000);
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
