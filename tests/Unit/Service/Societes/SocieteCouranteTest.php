<?php

namespace App\Tests\Unit\Service\Societes;

use App\Entity\Articles;
use App\Entity\Commandes;
use App\Entity\CompteTresorerie;
use App\Entity\Devis;
use App\Entity\DotationAmortissement;
use App\Entity\Entreprise;
use App\Entity\Factures;
use App\Entity\Immobilisation;
use App\Entity\MouvementTresorerie;
use App\Entity\Paiements;
use App\Entity\Societe;
use App\Entity\StockSorties;
use App\Repository\EntrepriseRepository;
use App\Repository\SocieteRepository;
use App\Service\Societes\SocieteCourante;
use PHPUnit\Framework\TestCase;

/**
 * Société d'un nouveau document (docs/societes.md, étape 1) : celle de
 * l'émetteur, sinon celle du document lié, sinon la société par défaut.
 */
final class SocieteCouranteTest extends TestCase
{
    private Societe $madial;
    private Societe $drepa;

    protected function setUp(): void
    {
        $this->madial = self::societe(1, 'IMP', 'Madial Group SARL', true);
        $this->drepa = self::societe(2, 'DREPA', 'DREPA TECHNOLOGIE');
    }

    public function testLEmetteurDonneSaSociete(): void
    {
        $courante = $this->courante();

        self::assertSame($this->drepa, $courante->pour((new Factures())->setEmetteur('drepa')));
        self::assertSame($this->madial, $courante->pour((new Factures())->setEmetteur('mdg')));
        self::assertSame($this->madial, $courante->pour((new Devis())->setEmetteur('mdg_success')));
        self::assertSame($this->drepa, $courante->pourEmetteur('drepa'));
    }

    /** Un émetteur inconnu (entreprise supprimée, code d'avant) ne bloque pas : la société par défaut. */
    public function testEmetteurInconnuSocieteParDefaut(): void
    {
        $courante = $this->courante();

        self::assertSame($this->madial, $courante->pour((new Devis())->setEmetteur('ancien_code')));
        self::assertSame($this->madial, $courante->pourEmetteur(null));
    }

    public function testSansEmetteurNiLienSocieteParDefaut(): void
    {
        $courante = $this->courante();

        self::assertSame($this->madial, $courante->pour(new Commandes()));
        self::assertSame($this->madial, $courante->pour(new CompteTresorerie()));
        self::assertSame($this->madial, $courante->pour(new Articles()));
        self::assertSame($this->madial, $courante->pour(new Paiements()));
    }

    /** Le paiement suit sa facture, sinon sa commande. */
    public function testLePaiementSuitSaFactureSinonSaCommande(): void
    {
        $courante = $this->courante();
        $commande = (new Commandes())->setSociete($this->madial);

        $paiement = (new Paiements())->setCommande($commande);
        self::assertSame($this->madial, $courante->pour($paiement));

        // Facture DREPA pas encore enregistrée : sa société vient de son émetteur.
        $paiement->setFacture((new Factures())->setEmetteur('drepa')->setCommande($commande));
        self::assertSame($this->drepa, $courante->pour($paiement));
    }

    /** Le mouvement suit son compte (source, sinon destination), et son paiement seulement sans compte. */
    public function testLeMouvementSuitSonCompte(): void
    {
        $courante = $this->courante();
        $caisse = (new CompteTresorerie())->setSociete($this->madial);
        $paiementDrepa = (new Paiements())->setSociete($this->drepa);

        $entree = (new MouvementTresorerie())->setCompteDestination($caisse)->setPaiement($paiementDrepa);
        self::assertSame($this->madial, $courante->pour($entree), 'le solde du compte reste dans sa société');

        $sansCompte = (new MouvementTresorerie())->setPaiement($paiementDrepa);
        self::assertSame($this->drepa, $courante->pour($sansCompte));

        $banqueDrepa = (new CompteTresorerie())->setSociete($this->drepa);
        $virement = (new MouvementTresorerie())->setCompteSource($banqueDrepa)->setCompteDestination($caisse);
        self::assertSame($this->drepa, $courante->pour($virement), 'le compte source d\'abord');
    }

    public function testLeStockSuitSonArticleEtLaDotationSonBien(): void
    {
        $courante = $this->courante();
        $article = (new Articles())->setSociete($this->drepa);
        self::assertSame($this->drepa, $courante->pour((new StockSorties())->setArticle($article)));

        $bien = (new Immobilisation('IMM-0001', 'Traceur HP T830', 'machine'))->setSociete($this->drepa);
        $dotation = new DotationAmortissement($bien, new \DateTimeImmutable('2026-09-01'), 30_000, 30_000, 'usage', 100.0, new \DateTimeImmutable());
        self::assertSame($this->drepa, $courante->pour($dotation));
    }

    /** Une société déjà posée (reçue par la synchronisation) est gardée. */
    public function testUneSocieteDejaPoseeEstGardee(): void
    {
        $facture = (new Factures())->setEmetteur('mdg_success')->setSociete($this->drepa);

        self::assertSame($this->drepa, $this->courante()->pour($facture));
    }

    public function testSansSocieteEnBaseUneErreurClaire(): void
    {
        $societes = $this->createStub(SocieteRepository::class);
        $societes->method('parDefaut')->willReturn(null);
        $courante = new SocieteCourante($societes, $this->createStub(EntrepriseRepository::class));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Aucune société en base');
        $courante->pour(new Commandes());
    }

    private function courante(): SocieteCourante
    {
        $entreprises = [
            'mdg_success' => (new Entreprise())->setCode('mdg_success')->setSociete($this->madial),
            'mdg' => (new Entreprise())->setCode('mdg')->setSociete($this->madial),
            'drepa' => (new Entreprise())->setCode('drepa')->setSociete($this->drepa),
        ];
        $societes = $this->createStub(SocieteRepository::class);
        $societes->method('parDefaut')->willReturn($this->madial);
        $repoEntreprises = $this->createStub(EntrepriseRepository::class);
        $repoEntreprises->method('parCode')->willReturnCallback(static fn (?string $code): ?Entreprise => $entreprises[$code] ?? null);

        return new SocieteCourante($societes, $repoEntreprises);
    }

    public static function societe(int $id, string $code, string $raison, bool $parDefaut = false): Societe
    {
        $societe = (new Societe())->setCode($code)->setRaisonSociale($raison)->setParDefaut($parDefaut);
        (new \ReflectionProperty(Societe::class, 'id'))->setValue($societe, $id);

        return $societe;
    }
}
