<?php

namespace App\Tests\Unit\Service\Import;

use App\Entity\Clients;
use App\Service\Import\ImportArticles;
use App\Service\Import\ImportClients;
use App\Service\Import\ImportProduits;
use App\Service\Import\Imports;
use App\Service\Import\ImportSoldesComptes;
use App\Service\Import\IndexClients;
use App\Service\Import\LigneImport;
use App\Service\Import\ModeleImport;
use App\Service\Import\References;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Doublons de clients, lecture des types, unités et grilles, lignes
 * d'exemple du modèle (docs/import-donnees.md).
 */
final class DoublonsEtAidesTest extends TestCase
{
    private function index(): IndexClients
    {
        $index = new IndexClients();
        $index->ajouterFiche(1, 'CLI-000001', 'Assétou Samaké', ['60311592', null]);
        $index->ajouterFiche(2, 'CLI-000002', 'Hôtel du Fleuve SARL', ['+223 20 22 33 44', '66 00 11 22']);
        $index->ajouterFiche(3, 'CLI-000003', 'Moussa Diarra', ['70000001', null]);
        $index->ajouterFiche(4, 'CLI-000004', 'Moussa Diarra', ['70000002', null]);
        $index->ajouterTelephone(2, '76 55 44 33'); // contact de l'hôtel

        return $index;
    }

    public function testDoublonParCodeAvantTout(): void
    {
        $trouve = $this->index()->chercher('cli-000003', ['60311592'], 'Assétou Samaké');

        self::assertSame(3, $trouve['id']);
        self::assertSame('code', $trouve['par']);
    }

    public function testDoublonParTelephoneQuelQueSoitSonEcriture(): void
    {
        $index = $this->index();

        self::assertSame(['id' => 2, 'par' => 'telephone'], array_intersect_key($index->chercher(null, ['00223 20223344'], 'Autre nom'), ['id' => 0, 'par' => 0]));
        // Second numéro de la fiche, numéro d'un contact.
        self::assertSame(2, $index->chercher('', ['66.00.11.22'], null)['id']);
        self::assertSame(2, $index->chercher('', [null, '76554433'], null)['id']);
    }

    public function testDoublonParNomSansAccentsNiOrdre(): void
    {
        $index = $this->index();

        $trouve = $index->chercher(null, ['79999999'], 'SAMAKE assetou');
        self::assertSame(1, $trouve['id']);
        self::assertSame('nom', $trouve['par']);
        self::assertFalse($trouve['ambigu']);

        self::assertTrue($index->chercher(null, [], 'Diarra Moussa')['ambigu']);
        self::assertNull($index->chercher('ANCIEN-77', ['79999999'], 'Inconnu Total'));
        self::assertSame('CLI-000001 Assétou Samaké (60 31 15 92)', IndexClients::decrire($trouve));
    }

    public function testDoublonDansLeFichier(): void
    {
        $index = $this->index();
        $index->retenirLigne(2, ['76123450'], 'Traoré Mariam');

        self::assertSame(['ligne' => 2, 'par' => 'telephone'], $index->dejaDansFichier(['+223 76 12 34 50'], 'Autre'));
        self::assertSame(['ligne' => 2, 'par' => 'nom'], $index->dejaDansFichier(['65000000'], 'Mariam TRAORÉ'));
        self::assertNull($index->dejaDansFichier(['65000000'], 'Seydou Diarra'));
        self::assertFalse($index->codeConnu('OLD-77'));
        self::assertTrue($index->codeConnu('cli-000002'));
    }

    public function testTypesGrillesEtDelais(): void
    {
        self::assertSame(Clients::TYPE_COMPTE_PARTICULIER, ImportClients::typeCompte(''));
        self::assertSame(Clients::TYPE_COMPTE_ENTREPRISE, ImportClients::typeCompte('SARL'));
        self::assertSame(Clients::TYPE_COMPTE_ENTREPRISE, ImportClients::typeCompte('Entreprise'));
        self::assertSame(Clients::TYPE_COMPTE_ONG, ImportClients::typeCompte('association'));
        self::assertSame(Clients::TYPE_COMPTE_ADMINISTRATION, ImportClients::typeCompte('Mairie'));
        self::assertNull(ImportClients::typeCompte('Martien'));

        self::assertSame('B2C', ImportClients::grille(''));
        self::assertSame('B2B', ImportClients::grille('Revendeur (B2B)'));
        self::assertNull(ImportClients::grille('VIP'));

        self::assertSame('', ImportClients::delai(''));
        self::assertSame('comptant', ImportClients::delai('cash'));
        self::assertSame('30_jours', ImportClients::delai('30 jours'));
        self::assertSame('30_jours_fdm', ImportClients::delai('30 j fin de mois'));
        self::assertNull(ImportClients::delai('90 jours'));
    }

    public function testUnitesEtCodes(): void
    {
        self::assertSame('metre_carre', ImportProduits::unite('m²'));
        self::assertSame('unite', ImportProduits::unite('pièce'));
        self::assertSame('forfait', ImportProduits::unite('Forfait'));
        self::assertNull(ImportProduits::unite('boisseau'));
        self::assertSame('BACHE-MICROPERFOREE-A5', ImportProduits::codeDepuisNom('Bâche microperforée (A5)'));
        self::assertSame('PRODUIT', ImportProduits::codeDepuisNom('—'));

        self::assertSame('unite', ImportArticles::unite(''));
        self::assertSame('rouleau', ImportArticles::unite('rouleaux'));
        self::assertSame('metre', ImportArticles::unite('ml'));
    }

    public function testLignesDExempleDuModele(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $type = new ImportSoldesComptes($em, new References($em));

        self::assertTrue(ModeleImport::estExemple($type, ['compte' => 'Caisse boutique', 'solde' => '185 000']));
        self::assertTrue(ModeleImport::estExemple($type, ['compte' => 'bdm', 'solde' => '4 750 000']));
        // Une seule colonne pareille ne suffit pas.
        self::assertFalse(ModeleImport::estExemple($type, ['compte' => 'Caisse boutique']));
        self::assertFalse(ModeleImport::estExemple($type, ['compte' => 'Caisse boutique', 'solde' => '190 000']));
        self::assertSame(['Compte *', 'Solde *'], array_map(ModeleImport::entete(...), ModeleImport::colonnes($type, static fn (string $r): bool => true)));
    }

    public function testModeleExcelEcrit(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $chemin = (new ModeleImport())->ecrire(new ImportSoldesComptes($em, new References($em)), static fn (string $r): bool => true);

        try {
            self::assertStringStartsWith('PK', (string) file_get_contents($chemin, false, null, 0, 2));
        } finally {
            @unlink($chemin);
        }
    }

    public function testCompteDesLignesEtDroits(): void
    {
        $lignes = [new LigneImport(2, []), (new LigneImport(3, []))->ignorer('doublon'), (new LigneImport(4, []))->erreur('téléphone'), new LigneImport(5, [])];
        $lignes[3]->statut = LigneImport::MAJ;

        self::assertSame(['lignes' => 4, 'crees' => 1, 'modifies' => 1, 'ignores' => 1, 'erreurs' => 1], LigneImport::compter($lignes));
        self::assertTrue($lignes[0]->aEcrire());
        self::assertFalse($lignes[2]->aEcrire());

        $em = $this->createStub(EntityManagerInterface::class);
        $soldes = new ImportSoldesComptes($em, new References($em));
        self::assertTrue(Imports::accessible($soldes, static fn (string $r): bool => true));
        // Direction sans le droit trésorerie : pas de soldes de comptes.
        self::assertFalse(Imports::accessible($soldes, static fn (string $r): bool => 'ROLE_TRESORERIE_SAISIR' !== $r));
    }

    public function testNomDeFichierEtReferences(): void
    {
        self::assertSame('clients.csv', Imports::nomPropre('C:\\Users\\Awa\\Bureau\\clients.csv'));
        self::assertSame('soldes.xlsx', Imports::nomPropre("../../soldes\x00.xlsx"));
        self::assertSame('fichier', Imports::nomPropre('  '));
        self::assertSame('1 commande, 3 paiements', References::phrase(['commandes' => 1, 'paiements' => 3]));
        self::assertSame('2 soldes d\'ouverture, 1 devis', References::phrase(['soldes d\'ouverture' => 2, 'devis' => 1]));
    }
}
