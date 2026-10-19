<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\DemandeDevis;
use App\Entity\DemandeDevisLigne;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Entity\Finition;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Service\Site\DemandesSite;
use App\Service\Site\FormulaireDemande;
use App\Service\Site\PanierDevis;
use App\Service\Site\PreparationDemande;
use App\Service\Synchro\CatalogueSynchro;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Panier de devis du site (docs/site-vitrine.md) : plusieurs produits, une demande, un devis brouillon. */
final class PanierDevisTest extends TestCase
{
    private const COORDONNEES = ['nom' => 'Awa Exemple', 'telephone' => '70 00 00 01', 'email' => '', 'message' => ''];

    public function testPanierATroisLignesValide(): void
    {
        [$bache, $oeillets] = $this->bache();
        $flyers = $this->flyers();

        ['valeurs' => $v, 'erreurs' => $e] = FormulaireDemande::validerPanier(self::COORDONNEES + ['lignes' => [
            0 => ['produit' => '', 'description' => '100 badges ronds 58 mm', 'quantite' => '100'],
            1 => ['produit' => '7', 'largeur' => '300', 'hauteur' => '100', 'quantite' => '2', 'finitions' => [(string) $oeillets->getId()]],
            3 => ['produit' => '8', 'quantite' => '500'],
            4 => ['produit' => '', 'description' => '', 'quantite' => ''], // laissée vide : ignorée
        ]], [$bache, $flyers]);

        self::assertSame([], $e);
        self::assertSame('22370000001', $v['telephone']);
        self::assertCount(3, $v['lignes']);
        self::assertSame([0, 1, 3], array_column($v['lignes'], 'cle'), 'les clés d\'origine nomment les fichiers et les erreurs');
        self::assertNull($v['lignes'][0]['produit']);
        self::assertSame('100 badges ronds 58 mm', $v['lignes'][0]['description']);
        self::assertSame(100, $v['lignes'][0]['quantite']);
        self::assertSame($bache, $v['lignes'][1]['produit']);
        self::assertSame([$oeillets->getId()], $v['lignes'][1]['finitions']);
        self::assertSame(500, $v['lignes'][2]['quantite']);
    }

    public function testErreursRangeesParLigne(): void
    {
        [$bache] = $this->bache();

        $e = FormulaireDemande::validerPanier(self::COORDONNEES + ['lignes' => [
            0 => ['produit' => '7', 'largeur' => '', 'hauteur' => '100', 'quantite' => '2'],
            2 => ['produit' => '999', 'quantite' => '1'],
            5 => ['produit' => '', 'description' => 'abc'],
        ]], [$bache])['erreurs'];

        self::assertArrayHasKey('lignes.0.largeur', $e);
        self::assertSame('Choisissez un produit de la liste.', $e['lignes.2.produit']);
        self::assertArrayHasKey('lignes.5.description', $e, 'un « Autre projet » parmi d\'autres doit être décrit');
    }

    public function testPlusDeDixLignesRefusees(): void
    {
        $flyers = $this->flyers();
        $lignes = array_fill(0, 12, ['produit' => '8', 'quantite' => '100']);

        ['valeurs' => $v, 'erreurs' => $e] = FormulaireDemande::validerPanier(self::COORDONNEES + ['lignes' => $lignes], [$flyers]);

        self::assertArrayHasKey('lignes', $e);
        self::assertCount(PanierDevis::LIGNES_MAX, $v['lignes']);
    }

    public function testAncienFormulaireAUnProduitToujoursLu(): void
    {
        [$bache] = $this->bache();

        ['valeurs' => $v, 'erreurs' => $e] = FormulaireDemande::validerPanier(self::COORDONNEES + ['produit' => '7', 'largeur' => '200', 'hauteur' => '100', 'quantite' => '1'], [$bache]);

        self::assertSame([], $e);
        self::assertCount(1, $v['lignes']);
        self::assertSame($bache, $v['lignes'][0]['produit']);
    }

    public function testProjetDecritDansLeSeulMessage(): void
    {
        // Comme avant le panier : aucun produit, le message suffit ; sans message, il est demandé.
        $ok = FormulaireDemande::validerPanier(['message' => 'Un panneau pour la boutique de mon frère'] + self::COORDONNEES + ['lignes' => [0 => ['produit' => '']]], []);
        self::assertSame([], $ok['erreurs']);
        self::assertSame([], $ok['valeurs']['lignes']);

        $vide = FormulaireDemande::validerPanier(self::COORDONNEES + ['lignes' => [0 => ['produit' => '']]], []);
        self::assertSame('Choisissez un produit ou décrivez votre projet.', $vide['erreurs']['message']);

        // Une ligne qui n'a qu'un fichier compte : avec un message, elle passe sans description.
        $fichier = FormulaireDemande::validerPanier(['message' => 'Logo à imprimer en t-shirt'] + self::COORDONNEES + ['lignes' => [0 => ['produit' => '']]], [], 1.0, [0]);
        self::assertSame([], $fichier['erreurs']);
        self::assertCount(1, $fichier['valeurs']['lignes']);
    }

    public function testLimitesDesFichiers(): void
    {
        self::assertSame(8 * 1024 * 1024, PanierDevis::octets('8M'));
        self::assertSame(512 * 1024, PanierDevis::octets('512K'));
        self::assertSame(0, PanierDevis::octets('-1'));
        self::assertSame(PanierDevis::LIGNES_MAX, PanierDevis::fichiersMax(20));
        self::assertSame(3, PanierDevis::fichiersMax(3));
        self::assertLessThanOrEqual(PanierDevis::TOTAL_FICHIERS_MAX, PanierDevis::tailleMaxTotale(1024 ** 3));
        self::assertLessThan(8 * 1024 * 1024, PanierDevis::tailleMaxTotale(8 * 1024 * 1024), 'post_max_size moins la place des champs');

        $dossier = sys_get_temp_dir();
        $a = tempnam($dossier, 'pd');
        $b = tempnam($dossier, 'pd');
        file_put_contents($a, '%PDF-'.str_repeat('a', 3000));
        file_put_contents($b, '%PDF-'.str_repeat('b', 3000));
        $fichiers = [new UploadedFile($a, 'a.pdf', 'application/pdf', null, true), new UploadedFile($b, 'b.pdf', 'application/pdf', null, true)];

        self::assertNull(PanierDevis::verifierEnsemble($fichiers, 10000, 10));
        self::assertStringContainsString('Mo au plus par demande', (string) PanierDevis::verifierEnsemble($fichiers, 5000, 10));
        self::assertStringContainsString('1 fichiers au plus', (string) PanierDevis::verifierEnsemble($fichiers, 10000, 1));
        @unlink($a);
        @unlink($b);
    }

    public function testPrixIndicatifEgalAuDevisBrouillon(): void
    {
        [$bache, $oeillets] = $this->bache();
        $bache->setPrixB2B(5000);

        $prix = PanierDevis::prixIndicatif($bache, 2, 300, 100, [$oeillets->getId()], null);
        self::assertSame(54000, $prix, 'tarif public, jamais le prix B2B');
        self::assertSame(PreparationDemande::ligneDevis($bache, 2, 300, 100, [$oeillets->getId()])->getTotalTtc(), $prix);
        self::assertNull(PanierDevis::prixIndicatif($bache, 2, 9000, 100, [], null), 'taille hors limites : l\'imprimerie chiffre');
        self::assertNull(PanierDevis::prixIndicatif($bache, null, 300, 100, [], null));

        self::assertSame(['total' => 90000, 'complet' => false], PanierDevis::total([54000, null, 36000]));
        self::assertSame(['total' => null, 'complet' => false], PanierDevis::total([null]));
        self::assertSame(['total' => 10, 'complet' => true], PanierDevis::total([10]));
    }

    public function testUnSeulDevisBrouillonAPlusieursLignes(): void
    {
        [$bache, $oeillets] = $this->bache();
        $flyers = $this->flyers();
        $demande = DemandeDevis::nouvelle('devis', 'Awa Exemple', '22370000001', new \DateTimeImmutable('2026-10-19 10:00'))->setMessage('Pour le salon');
        $demande->setNumero('W-DD-0042');
        $demande->addLigne(DemandesSite::ligne(['produit' => null, 'quantite' => 100, 'description' => '100 badges ronds 58 mm']));
        $demande->addLigne(DemandesSite::ligne(['produit' => $bache, 'largeur' => 300.0, 'hauteur' => 100.0, 'quantite' => 2, 'finitions' => [$oeillets->getId()]])->setPrixIndicatif(54000));
        $demande->addLigne(DemandesSite::ligne(['produit' => $flyers, 'quantite' => 500, 'finitions' => []]));

        self::assertTrue($demande->estPanier());
        self::assertSame([1, 2, 3], array_map(static fn (DemandeDevisLigne $l): int => $l->getPosition(), $demande->getLignes()->toArray()));
        self::assertSame('Œillets', $demande->getLignes()[1]->getNomsFinitions());
        self::assertStringStartsWith('3 produits : Autre projet × 100 ; Bâche 300 × 100 cm × 2', $demande->getResume());

        $r = PreparationDemande::construire($demande, null, new \DateTimeImmutable('2026-10-19 10:30'));
        $devis = $r['devis'];
        self::assertInstanceOf(Devis::class, $devis);
        self::assertSame(Devis::STATUT_BROUILLON, $devis->getStatut());
        $lignes = $devis->getDevisDetails()->toArray();
        self::assertCount(3, $lignes, 'un seul devis, une ligne par produit');
        self::assertSame(DevisDetails::TYPE_LIBRE, $lignes[0]->getTypeLigne(), 'autre projet : ligne libre à chiffrer');
        self::assertSame(0, $lignes[0]->getTotalTtc());
        self::assertStringContainsString('100 badges', (string) $lignes[0]->getDesignation());
        self::assertSame(DevisDetails::TYPE_PRODUIT, $lignes[1]->getTypeLigne());
        self::assertSame(54000, $lignes[1]->getTotalTtc());
        self::assertCount(1, $lignes[1]->getFinitions(), 'la finition choisie suit sa ligne');
        self::assertStringContainsString('Ligne 2 de la demande W-DD-0042', (string) $lignes[1]->getObservation());
        self::assertSame(500, $lignes[2]->getQuantite());
        self::assertSame(54000 + $lignes[2]->getTotalTtc(), $devis->getTotalTtc());
        self::assertCount(3, $r['lignes'], 'chaque ligne de la demande retrouve sa ligne de devis');
        self::assertSame($lignes[1], $r['lignes'][1][1]);
        self::assertNotNull($r['aChiffrer']);
        self::assertStringContainsString('Panier de 3 lignes', (string) $devis->getObservation());
    }

    public function testLignesMontentAvecLaDemande(): void
    {
        $type = CatalogueSynchro::types()['demande_devis'];
        $lignes = $type['enfants']['lignes'];

        self::assertSame(DemandeDevisLigne::class, $lignes['classe']);
        self::assertSame('demande', $lignes['parent']);
        self::assertSame(['configuration' => 'configuration', 'fichier' => 'fichier'], $lignes['relations']);
        self::assertSame(['configuration'], $lignes['facultatives'], 'un produit retiré ne bloque pas la demande');
        self::assertNotContains('devisDetail', $lignes['champs'], 'la ligne du devis reste au local');
        self::assertContains('prixIndicatif', $lignes['champs']);
        self::assertSame(DemandeDevisLigne::class, CatalogueSynchro::enfantDe(new DemandeDevisLigne())['classe'] ?? null);
    }

    /** @return array{ProduitConfiguration, ProduitConfigurationFinition} */
    private function bache(): array
    {
        $bache = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Bâche'))
            ->setModeDimension('mesure')->setModeCalcul('metre_carre')->setPrixBase(8000);
        (new \ReflectionProperty($bache, 'id'))->setValue($bache, 7);
        $oeillets = (new ProduitConfigurationFinition())->setFinition((new Finition())->setNom('Œillets'))
            ->setPayante(true)->setPrix(3000)->setModeCalcul('unite');
        (new \ReflectionProperty($oeillets, 'id'))->setValue($oeillets, 1);
        $bache->addConfigurationFinition($oeillets);

        return [$bache, $oeillets];
    }

    private function flyers(): ProduitConfiguration
    {
        $flyers = (new ProduitConfiguration())->setProduit((new Produits())->setNom('Flyers'))
            ->setModeCalcul('unite')->setPrixBase(75);
        (new \ReflectionProperty($flyers, 'id'))->setValue($flyers, 8);

        return $flyers;
    }
}
