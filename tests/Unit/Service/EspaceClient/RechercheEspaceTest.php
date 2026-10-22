<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteClient;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Entity\Factures;
use App\Entity\Produits;
use App\Service\EspaceClient\EspaceClient;
use PHPUnit\Framework\TestCase;

/** Recherche de la barre du haut : les documents de la fiche seulement, selon les droits. */
final class RechercheEspaceTest extends TestCase
{
    private Clients $client;
    private Clients $autre;

    /** @var array<string, list<object>> */
    private array $sources;

    protected function setUp(): void
    {
        $this->client = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $this->autre = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE);
        $rollup = self::commande($this->client, 'CMD-D00770', 'Roll-up accueil 85 × 200 cm');
        $kakemono = self::commande($this->client, 'CMD-D00771', 'Kakémonos du hall');
        $etranger = self::commande($this->autre, 'CMD-D00999', 'Roll-up concurrent');
        $this->sources = [
            'commandes' => [$rollup, $kakemono, $etranger],
            'devis' => [self::devis($this->client, 'DV-D00110', 'Roll-up salle des fêtes'), self::devis($this->autre, 'DV-D00999', 'Roll-up')],
            'fichiers' => [(new CommandeDetailFichier())->setClient($this->client)->setNomOriginal('rollup-accueil.pdf'), (new CommandeDetailFichier())->setClient($this->autre)->setNomOriginal('rollup.pdf')],
            'factures' => [(new Factures())->setCommande($rollup)->setNumero('F-D00580'), (new Factures())->setCommande($etranger)->setNumero('F-D00999')],
        ];
    }

    public function testRienDUnAutreClient(): void
    {
        $r = EspaceClient::resultatsRecherche(self::compte($this->client, [CompteClient::DROIT_ADMINISTRATEUR]), 'roll', $this->sources);

        self::assertSame(['CMD-D00770'], array_map(static fn (Commandes $c): string => $c->getNumero(), $r['commandes']));
        self::assertSame(['DV-D00110'], array_map(static fn (Devis $d): string => $d->getNumero(), $r['devis']));
        self::assertSame(['rollup-accueil.pdf'], array_map(static fn (CommandeDetailFichier $f): string => $f->getNomOriginal(), $r['fichiers']));
        // Factures : par leur numéro ou celui de leur commande, jamais celles d'un autre.
        $numeros = static fn (array $r): array => array_map(static fn (Factures $f): string => $f->getNumero(), $r['factures']);
        $admin = self::compte($this->client, [CompteClient::DROIT_ADMINISTRATEUR]);
        self::assertSame(['F-D00580'], $numeros(EspaceClient::resultatsRecherche($admin, 'F-D00', $this->sources)));
        self::assertSame(['F-D00580'], $numeros(EspaceClient::resultatsRecherche($admin, 'CMD-D00770', $this->sources)));
        self::assertSame([], $numeros(EspaceClient::resultatsRecherche($admin, 'D00999', $this->sources)));
    }

    public function testSelonLesDroits(): void
    {
        $valideur = EspaceClient::resultatsRecherche(self::compte($this->client, [CompteClient::DROIT_VALIDEUR]), 'roll', $this->sources);
        self::assertCount(1, $valideur['devis']);
        self::assertSame([], $valideur['factures'], 'factures aux payeurs seulement');

        $lecture = EspaceClient::resultatsRecherche(self::compte($this->client, [CompteClient::DROIT_LECTURE]), 'roll', $this->sources);
        self::assertCount(1, $lecture['commandes']);
        self::assertSame([], $lecture['devis']);
        self::assertSame([], $lecture['factures']);
    }

    public function testSansAccentsEtTexteTropCourt(): void
    {
        $admin = self::compte($this->client, [CompteClient::DROIT_ADMINISTRATEUR]);

        self::assertCount(1, EspaceClient::resultatsRecherche($admin, 'kakemono', $this->sources)['commandes']);
        self::assertCount(1, EspaceClient::resultatsRecherche($admin, '85 x 200', $this->sources)['commandes']);
        self::assertSame(['commandes' => [], 'devis' => [], 'fichiers' => [], 'factures' => []], EspaceClient::resultatsRecherche($admin, 'r', $this->sources));
    }

    public function testParLeNomCommercialOuTechniqueDuProduit(): void
    {
        $admin = self::compte($this->client, [CompteClient::DROIT_ADMINISTRATEUR]);
        $vinyle = (new Produits())->setNom('Vinyle adhésif')->setNomCommercial('Autocollant');
        $commande = self::commande($this->client, 'CMD-D00800', 'Vitrine boutique 2 m');
        $commande->getCommandesDetails()->first()->setProduit($vinyle);
        $devis = self::devis($this->client, 'DV-D00120', 'Logo portière');
        $devis->getDevisDetails()->first()->setProduit($vinyle);
        $sources = ['commandes' => [$commande], 'devis' => [$devis]];

        foreach (['autocollant', 'AUTOCOLLANTS', 'vinyle', 'adhesif'] as $mot) {
            $r = EspaceClient::resultatsRecherche($admin, $mot, $sources);
            self::assertCount(1, $r['commandes'], $mot);
            self::assertCount(1, $r['devis'], $mot);
        }
        self::assertSame([], EspaceClient::resultatsRecherche($admin, 'banderole', $sources)['commandes']);
    }

    private static function commande(Clients $client, string $numero, string $designation): Commandes
    {
        return (new Commandes())->setClients($client)->setNumero($numero)->addCommandesDetail((new CommandesDetails())->setDesignation($designation));
    }

    private static function devis(Clients $client, string $numero, string $designation): Devis
    {
        return (new Devis())->setClients($client)->setNumero($numero)->addDevisDetail((new DevisDetails())->setDesignation($designation));
    }

    /** @param list<string> $droits */
    private static function compte(Clients $client, array $droits): CompteClient
    {
        return (new CompteClient())->setClient($client)->setDroits($droits);
    }
}
