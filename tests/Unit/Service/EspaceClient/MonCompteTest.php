<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\AdresseLivraison;
use App\Entity\AppareilClient;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\MessageClient;
use App\Entity\PropositionClient;
use App\Service\EspaceClient\MonCompte;
use App\Service\EspaceClient\PropositionsClient;
use PHPUnit\Framework\TestCase;

final class MonCompteTest extends TestCase
{
    private Clients $client;
    private Clients $autre;

    protected function setUp(): void
    {
        $this->client = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE)->setRaisonSociale('Hôtel Niger')->setNif('084512377K')
            ->setNom('Maïga')->setPrenom('Ibrahim')->setDelaiPaiement('30_jours_fdm');
        $this->autre = (new Clients())->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE)->setRaisonSociale('Autre');
    }

    public function testExportDuPayeur(): void
    {
        $payeur = self::compte($this->client, [CompteClient::DROIT_PAYEUR]);

        $export = MonCompte::construire($payeur, $this->sources($payeur), new \DateTimeImmutable('2026-10-14 10:00'));

        self::assertSame(['CMD-1'], array_column($export['commandes'], 'numero'), 'jamais la commande d\'un autre client');
        self::assertSame(150000, $export['commandes'][0]['totalTtc']);
        self::assertSame(['F-1'], array_column($export['factures'], 'numero'));
        self::assertSame(['DV-1'], array_column($export['devis'], 'numero'));
        self::assertArrayHasKey('totalTtc', $export['devis'][0]);
        self::assertSame('30 jours fin de mois', $export['client']['conditions']);
        self::assertSame(['Bonjour'], array_column($export['messages'], 'texte'));
        self::assertSame(['Entrée de service'], array_column($export['adressesLivraison'], 'libelle'), 'adresse retirée exclue');
        // Appareils : le type et les dates, jamais l'empreinte du cookie.
        self::assertSame([['appareil' => 'iPhone', 'premiereFois' => '2026-10-01T09:00:00+00:00', 'derniereActivite' => '2026-10-01T09:00:00+00:00']], $export['personne']['appareils']);
        self::assertStringNotContainsString(str_repeat('e', 64), (string) json_encode($export));
    }

    public function testExportDuValideurSansFactureNiMontantDeCommande(): void
    {
        $valideur = self::compte($this->client, [CompteClient::DROIT_VALIDEUR]);

        $export = MonCompte::construire($valideur, $this->sources($valideur), new \DateTimeImmutable('2026-10-14 10:00'));

        self::assertArrayNotHasKey('factures', $export);
        self::assertArrayNotHasKey('totalTtc', $export['commandes'][0]);
        self::assertArrayHasKey('totalTtc', $export['devis'][0], 'il accepte les devis');
        self::assertArrayNotHasKey('conditions', $export['client']);
    }

    public function testExportDuDemandeurEtDeLaLectureSeule(): void
    {
        $demandeur = MonCompte::construire($d = self::compte($this->client, []), $this->sources($d), new \DateTimeImmutable());
        self::assertArrayNotHasKey('totalTtc', $demandeur['devis'][0]);
        self::assertArrayNotHasKey('factures', $demandeur);

        $lecture = MonCompte::construire($l = self::compte($this->client, [CompteClient::DROIT_LECTURE]), $this->sources($l), new \DateTimeImmutable());
        self::assertSame(['CMD-1'], array_column($lecture['commandes'], 'numero'));
        foreach (['devis', 'factures', 'messages', 'reclamations'] as $cle) {
            self::assertArrayNotHasKey($cle, $lecture, $cle);
        }
        self::assertSame('Lecture · suivi', $lecture['personne']['droits']);
    }

    public function testConditionsEtTelephoneMasque(): void
    {
        self::assertSame('30 jours fin de mois', MonCompte::conditions($this->client));
        self::assertSame('30 jours fin de mois · remise 7,5 %', MonCompte::conditions($this->client->setRemiseHabituelle(7.5)));
        self::assertNull(MonCompte::conditions(new Clients()));
        self::assertTrue(MonCompte::voitConditions(self::compte($this->client, [CompteClient::DROIT_ADMINISTRATEUR])));
        self::assertTrue(MonCompte::voitConditions(self::compte($this->client, [CompteClient::DROIT_PAYEUR])));
        self::assertFalse(MonCompte::voitConditions(self::compte($this->client, [CompteClient::DROIT_VALIDEUR])));
        self::assertFalse(MonCompte::voitConditions(self::compte($this->client, [CompteClient::DROIT_LECTURE])));

        self::assertSame('+223 7X XX XX XX', MonCompte::telephoneMasque('22376123456'));
        self::assertSame('+223 6X XX XX XX', MonCompte::telephoneMasque('60 44 51 01'));
    }

    public function testChangementsProposes(): void
    {
        self::assertSame([], MonCompte::changements($this->client, ['raison_sociale' => 'x', 'raisonSociale' => '  Hôtel   Niger ', 'nif' => '084512377K']), 'rien ne change');
        self::assertSame(
            ['raisonSociale' => ['avant' => 'Hôtel Niger', 'apres' => 'Hôtel Niger SARL'], 'nif' => ['avant' => '084512377K', 'apres' => null]],
            MonCompte::changements($this->client, ['raisonSociale' => 'Hôtel Niger SARL', 'nif' => '']),
        );
        $this->expectException(\DomainException::class);
        MonCompte::changements($this->client, ['raisonSociale' => '   ']);
    }

    public function testChangementTropLong(): void
    {
        $this->expectExceptionMessage('NIF : 30 caractères au plus.');
        MonCompte::changements($this->client, ['nif' => str_repeat('9', 31)]);
    }

    public function testLAtelierRelitLaDemandeAvantDeLAppliquer(): void
    {
        $proposition = PropositionClient::nouvelle($this->client, [
            'raisonSociale' => ['avant' => 'Hôtel Niger', 'apres' => ' Hôtel  Niger SA '],
            'nif' => ['avant' => '084512377K', 'apres' => '084512377K'],
            'telephone' => ['avant' => null, 'apres' => '70000000'],
        ], 'Ibrahim', new \DateTimeImmutable());

        // Champ inconnu écarté, NIF inchangé ignoré, texte nettoyé.
        self::assertSame(['raisonSociale' => ['Hôtel Niger', 'Hôtel Niger SA']], PropositionsClient::champsValables($proposition));

        $vide = PropositionClient::nouvelle($this->client, ['raisonSociale' => ['avant' => 'Hôtel Niger', 'apres' => '']], null, new \DateTimeImmutable());
        self::assertSame([], PropositionsClient::champsValables($vide), 'la raison sociale ne se vide jamais');
    }

    /** @return array<string, list<object>> */
    private function sources(CompteClient $compte): array
    {
        $commande = (new Commandes())->setClients($this->client)->setNumero('CMD-1')->setTotalTtc(150000);
        $etrangere = (new Commandes())->setClients($this->autre)->setNumero('CMD-9')->setTotalTtc(999000);
        $facture = (new Factures())->setCommande($commande)->setNumero('F-1');
        $appareil = new AppareilClient($compte, str_repeat('e', 64), 'iPhone', new \DateTimeImmutable('2026-10-01 09:00', new \DateTimeZone('UTC')));
        $autreAppareil = new AppareilClient(new CompteClient(), str_repeat('f', 64), 'Android', new \DateTimeImmutable());

        return [
            'appareils' => [$appareil, $autreAppareil],
            'adresses' => [
                (new AdresseLivraison())->setClient($this->client)->setLibelle('Entrée de service')->setAdresse('Quartier du Fleuve')->setActif(true),
                (new AdresseLivraison())->setClient($this->client)->setLibelle('Ancienne')->setAdresse('Hamdallaye')->setActif(false),
            ],
            'commandes' => [$commande, $etrangere],
            'devis' => [(new Devis())->setClients($this->client)->setNumero('DV-1')->setTotalTtc(80000), (new Devis())->setClients($this->autre)->setNumero('DV-9')],
            'factures' => [$facture, (new Factures())->setCommande($etrangere)->setNumero('F-9')],
            'messages' => [MessageClient::nouveau($this->client, MessageClient::CLIENT, 'Bonjour', 'Ibrahim', null, new \DateTimeImmutable()), MessageClient::nouveau($this->autre, MessageClient::CLIENT, 'Secret', 'X', null, new \DateTimeImmutable())],
        ];
    }

    /** @param list<string> $droits */
    private static function compte(Clients $client, array $droits): CompteClient
    {
        return (new CompteClient())->setClient($client)->setTelephone('76123456')->setDroits($droits);
    }
}
