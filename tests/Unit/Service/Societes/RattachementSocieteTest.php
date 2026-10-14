<?php

namespace App\Tests\Unit\Service\Societes;

use App\Entity\Commandes;
use App\Entity\Devis;
use App\Entity\Entreprise;
use App\Entity\Factures;
use App\Entity\MouvementTresorerie;
use App\Entity\Paiements;
use App\Entity\Societe;
use App\Repository\EntrepriseRepository;
use App\Repository\SocieteRepository;
use App\Service\Societes\RattachementSociete;
use App\Service\Societes\SocieteCourante;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\TestCase;

/**
 * Chaque document enregistré reçoit sa société, quel que soit l'écran ou
 * le service qui le crée (docs/societes.md, étape 1).
 */
final class RattachementSocieteTest extends TestCase
{
    private Societe $madial;
    private Societe $drepa;

    /** @var list<object> données recalculées par l'écouteur */
    private array $recalculees = [];

    protected function setUp(): void
    {
        $this->madial = SocieteCouranteTest::societe(1, 'IMP', 'Madial Group SARL', true);
        $this->drepa = SocieteCouranteTest::societe(2, 'DREPA', 'DREPA TECHNOLOGIE');
        $this->recalculees = [];
    }

    public function testLesNouveauxDocumentsRecoiventUneSociete(): void
    {
        $commande = new Commandes();
        $facture = (new Factures())->setEmetteur('drepa')->setCommande($commande);
        $paiement = (new Paiements())->setCommande($commande)->setFacture($facture);
        $acompte = (new Paiements())->setCommande($commande);
        $mouvement = new MouvementTresorerie();

        $this->enregistrer([$commande, $facture, $paiement, $acompte, $mouvement]);

        self::assertSame($this->drepa, $facture->getSociete(), 'émetteur DREPA');
        self::assertSame($this->drepa, $paiement->getSociete(), 'suit sa facture');
        self::assertSame($this->drepa, $commande->getSociete(), 'la commande suit sa facture');
        self::assertSame($this->drepa, $acompte->getSociete(), 'l\'acompte suit la commande');
        self::assertSame($this->madial, $mouvement->getSociete(), 'sans lien : la société par défaut');
        foreach ([$commande, $facture, $paiement, $acompte, $mouvement] as $document) {
            self::assertContains($document, $this->recalculees, 'la société part dans l\'INSERT');
        }
    }

    /** Société reçue de l'autre côté (synchronisation) : rien ne change. */
    public function testUneSocieteRecueEstGardee(): void
    {
        $paiement = (new Paiements())->setSociete($this->drepa)->setCommande((new Commandes())->setSociete($this->madial));

        $this->enregistrer([$paiement]);

        self::assertSame($this->drepa, $paiement->getSociete());
        self::assertSame([], $this->recalculees);
    }

    /** Devis transformé (DevisCommandeConverter) : la commande créée prend la société du devis. */
    public function testLaCommandeCreeeDepuisUnDevisLeSuit(): void
    {
        $commande = new Commandes();
        $devis = (new Devis())->setEmetteur('drepa')->setSociete($this->drepa)->setCommande($commande);
        $paiement = (new Paiements())->setCommande($commande);

        $this->enregistrer([$commande, $paiement], [$devis]);

        self::assertSame($this->drepa, $commande->getSociete());
        self::assertSame($this->drepa, $paiement->getSociete(), 'le paiement de la nouvelle commande la suit');
    }

    /** Facture DREPA d'une commande du comptoir : la commande et ses paiements sans facture la suivent. */
    public function testLaCommandeExistanteSuitSaPremiereFacture(): void
    {
        $commande = (new Commandes())->setSociete($this->madial);
        $acompte = (new Paiements())->setSociete($this->madial);
        $commande->addPaiement($acompte);
        $facture = (new Factures())->setEmetteur('drepa');
        $commande->addFacture($facture);

        $this->enregistrer([$facture]);

        self::assertSame($this->drepa, $facture->getSociete());
        self::assertSame($this->drepa, $commande->getSociete());
        self::assertSame($this->drepa, $acompte->getSociete());
        self::assertContains($commande, $this->recalculees);
        self::assertContains($acompte, $this->recalculees);
    }

    /** Une commande déjà facturée par une autre société ne bouge pas (sauf si cette facture est annulée). */
    public function testFacturesDePlusieursSocietesLaCommandeGardeLaSienne(): void
    {
        $commande = (new Commandes())->setSociete($this->madial);
        $commande->addFacture((new Factures())->setEmetteur('mdg_success')->setSociete($this->madial));
        $facture = (new Factures())->setEmetteur('drepa');
        $commande->addFacture($facture);

        $this->enregistrer([$facture]);
        self::assertSame($this->drepa, $facture->getSociete());
        self::assertSame($this->madial, $commande->getSociete());

        $annulee = (new Commandes())->setSociete($this->madial);
        $annulee->addFacture((new Factures())->setEmetteur('mdg_success')->setSociete($this->madial)->setEtat(Factures::ETAT_ANNULEE));
        $refaite = (new Factures())->setEmetteur('drepa');
        $annulee->addFacture($refaite);

        $this->enregistrer([$refaite]);
        self::assertSame($this->drepa, $annulee->getSociete(), 'une facture annulée ne compte pas');
    }

    /** Émetteur changé sur une facture : elle, ses paiements et sa commande suivent la nouvelle entreprise. */
    public function testLEmetteurChangeLaSocieteSuit(): void
    {
        $commande = (new Commandes())->setSociete($this->madial);
        $facture = (new Factures())->setTypeDocument(Factures::TYPE_FACTURE)->setComptabilisee(true)->setEmetteur('drepa')->setSociete($this->madial);
        $commande->addFacture($facture);
        $paiement = (new Paiements())->setSociete($this->madial);
        $facture->addPaiement($paiement);
        $commande->addPaiement($paiement);
        $devis = (new Devis())->setEmetteur('mdg')->setSociete($this->madial);

        $this->enregistrer([], [$facture, $devis], ['emetteur' => ['mdg_success', 'drepa']]);

        self::assertSame($this->drepa, $facture->getSociete());
        self::assertSame($this->drepa, $paiement->getSociete());
        self::assertSame($this->drepa, $commande->getSociete());
        self::assertSame($this->madial, $devis->getSociete(), 'mdg reste chez Madial Group');
    }

    /**
     * @param list<object>         $insertions
     * @param list<object>         $modifications
     * @param array<string, array> $changements changements de chaque donnée modifiée
     */
    private function enregistrer(array $insertions, array $modifications = [], array $changements = []): void
    {
        $uow = $this->createStub(UnitOfWork::class);
        $uow->method('getScheduledEntityInsertions')->willReturn($insertions);
        $uow->method('getScheduledEntityUpdates')->willReturn($modifications);
        $uow->method('getEntityChangeSet')->willReturn($changements);
        $uow->method('isScheduledForInsert')->willReturnCallback(static fn (object $e): bool => \in_array($e, $insertions, true));
        $uow->method('isScheduledForDelete')->willReturn(false);
        $uow->method('recomputeSingleEntityChangeSet')->willReturnCallback(function (ClassMetadata $meta, object $e): void {
            $this->recalculees[] = $e;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getClassMetadata')->willReturn($this->createStub(ClassMetadata::class));

        $entreprises = [
            'mdg_success' => (new Entreprise())->setCode('mdg_success')->setSociete($this->madial),
            'mdg' => (new Entreprise())->setCode('mdg')->setSociete($this->madial),
            'drepa' => (new Entreprise())->setCode('drepa')->setSociete($this->drepa),
        ];
        $societes = $this->createStub(SocieteRepository::class);
        $societes->method('parDefaut')->willReturn($this->madial);
        $repoEntreprises = $this->createStub(EntrepriseRepository::class);
        $repoEntreprises->method('parCode')->willReturnCallback(static fn (?string $code): ?Entreprise => $entreprises[$code] ?? null);

        (new RattachementSociete(new SocieteCourante($societes, $repoEntreprises)))->onFlush(new OnFlushEventArgs($em));
    }
}
