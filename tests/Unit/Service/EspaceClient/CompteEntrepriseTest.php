<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\AdresseLivraison;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\CompteEntreprise;
use App\Service\OrangeSmsService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class CompteEntrepriseTest extends TestCase
{
    /** @var list<string> SMS partis pendant le test */
    private array $sms = [];

    public function testDemandeurAttendUnValideur(): void
    {
        $entreprise = $this->entreprise();
        [$admin, $valideur, $payeur, $demandeur] = $this->personnes($entreprise);
        $service = $this->service([$admin, $valideur, $payeur, $demandeur]);

        $commande = (new Commandes())->setClients($entreprise);
        $service->preparerCommande($demandeur, $commande, '  BC-118  ', null);

        self::assertSame('BC-118', $commande->getBonCommande());
        self::assertSame('Mariam', $commande->getDemandeePar());
        self::assertSame($demandeur, $commande->getCompteDemandeur());
        self::assertTrue($commande->attendValidationEntreprise());

        // Le valideur la voit, le payeur non.
        self::assertTrue($valideur->peut(CompteClient::DROIT_VALIDEUR));
        self::assertFalse($payeur->peut(CompteClient::DROIT_VALIDEUR));
        self::assertTrue($payeur->peut(CompteClient::DROIT_PAYEUR));
        self::assertFalse($demandeur->peut(CompteClient::DROIT_PAYEUR));

        // Une commande du valideur part directement.
        $directe = (new Commandes())->setClients($entreprise);
        $service->preparerCommande($valideur, $directe, null, null);
        self::assertFalse($directe->attendValidationEntreprise());
        self::assertSame('Awa', $directe->getDemandeePar());
    }

    public function testSansValideurLaDemandePartQuandMeme(): void
    {
        $entreprise = $this->entreprise();
        [$admin, , , $demandeur] = $this->personnes($entreprise);
        // Le seul administrateur est retire : personne pour valider.
        $admin->setActif(false);
        $service = $this->service([$admin, $demandeur]);

        $commande = (new Commandes())->setClients($entreprise);
        $service->preparerCommande($demandeur, $commande, null, null);

        self::assertFalse($commande->attendValidationEntreprise());
    }

    public function testParticulierSansValidation(): void
    {
        $particulier = (new Clients())->setTypeClient('B2C')->setTypeCompte(Clients::TYPE_COMPTE_PARTICULIER);
        $compte = (new CompteClient())->setClient($particulier)->setDroits([]);
        $service = $this->service([$compte]);

        $commande = (new Commandes())->setClients($particulier);
        $service->preparerCommande($compte, $commande, null, null);

        self::assertNull($commande->getDemandeePar());
        self::assertFalse($commande->attendValidationEntreprise());
        // Un particulier fait tout lui-meme.
        self::assertTrue($compte->peut(CompteClient::DROIT_PAYEUR));
    }

    public function testAdresseDUneAutreEntrepriseIgnoree(): void
    {
        $entreprise = $this->entreprise();
        [$admin] = $this->personnes($entreprise);
        $service = $this->service([$admin]);

        $autre = (new AdresseLivraison())->setClient($this->entreprise())->setLibelle('Ailleurs')->setAdresse('Kati');
        $commande = (new Commandes())->setClients($entreprise);
        $service->preparerCommande($admin, $commande, null, $autre);
        self::assertFalse($commande->estALivrer());

        $sienne = (new AdresseLivraison())->setClient($entreprise)->setLibelle('Siège')->setAdresse('ACI 2000');
        $service->preparerCommande($admin, $commande, null, $sienne);
        self::assertTrue($commande->estALivrer());
        self::assertStringContainsString('ACI 2000', (string) $commande->getAdresseLivraison());
    }

    public function testValiderEtRefuser(): void
    {
        $entreprise = $this->entreprise();
        [$admin, $valideur, $payeur, $demandeur] = $this->personnes($entreprise);
        $service = $this->service([$admin, $valideur, $payeur, $demandeur]);

        $commande = (new Commandes())->setClients($entreprise)->setValidationEntreprise(Commandes::VALIDATION_A_FAIRE);
        try {
            $service->valider($payeur, $commande);
            self::fail('Un payeur ne valide pas.');
        } catch (\DomainException) {
        }
        $service->refuser($valideur, $commande, '   '.str_repeat('x', 300));
        self::assertTrue($commande->estRefuseeParEntreprise());
        self::assertSame('Awa', $commande->getValideePar());
        self::assertSame(255, mb_strlen((string) $commande->getMotifRefusEntreprise()));

        $this->expectExceptionMessage('n\'attend plus de validation');
        $service->valider($valideur, $commande);
    }

    public function testCommandeDUneAutreEntrepriseRefusee(): void
    {
        [, $valideur] = $this->personnes($this->entreprise());
        $service = $this->service([$valideur]);
        $ailleurs = (new Commandes())->setClients($this->entreprise())->setValidationEntreprise(Commandes::VALIDATION_A_FAIRE);

        $this->expectException(\DomainException::class);
        $service->valider($valideur, $ailleurs);
    }

    public function testIlResteToujoursUnAdministrateur(): void
    {
        $entreprise = $this->entreprise();
        [$admin, $valideur] = $this->personnes($entreprise);
        $second = (new CompteClient())->setClient($entreprise)->setNom('Kadi')->setTelephone('22366000104')
            ->setDroits([CompteClient::DROIT_ADMINISTRATEUR])->setAjoutePar($admin);
        $service = $this->service([$admin, $valideur, $second]);

        // Deux administrateurs : l'un peut perdre ce droit.
        $service->changerDroits($admin, $second, [CompteClient::DROIT_VALIDEUR]);
        self::assertSame([CompteClient::DROIT_VALIDEUR], $second->getDroits());

        // Plus qu'un : impossible de le lui retirer, ni de le retirer.
        try {
            $service->changerDroits($admin, $admin, []);
            self::fail('On ne change pas ses propres droits.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('propres droits', $e->getMessage());
        }
        $service->changerDroits($admin, $second, [CompteClient::DROIT_ADMINISTRATEUR]);
        $service->retirer($admin, $second);
        self::assertFalse($second->isActif());
        try {
            $service->changerDroits($second, $admin, [CompteClient::DROIT_ADMINISTRATEUR]);
            self::fail('Un compte retire ne gere plus rien.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('Seule une personne qui gère le compte', $e->getMessage());
        }
    }

    public function testLeValideurNeGerePasLeCompte(): void
    {
        $entreprise = $this->entreprise();
        [$admin, $valideur, $payeur] = $this->personnes($entreprise);
        $service = $this->service([$admin, $valideur, $payeur]);

        $this->expectExceptionMessage('Seule une personne qui gère le compte');
        $service->changerDroits($valideur, $payeur, [CompteClient::DROIT_ADMINISTRATEUR]);
    }

    public function testSmsAuxValideursSansMontant(): void
    {
        $entreprise = $this->entreprise();
        [$admin, $valideur, $payeur, $demandeur] = $this->personnes($entreprise);
        $service = $this->service([$admin, $valideur, $payeur, $demandeur], true);

        $commande = (new Commandes())->setClients($entreprise)->setNumero('W-CMD-000900')->setTotalTtc(125000);
        $service->prevenirValideurs($commande, $demandeur);

        // L'administrateur et le valideur, pas le payeur.
        self::assertCount(2, $this->sms);
        foreach ($this->sms as $texte) {
            self::assertStringContainsString('Mariam demande la commande W-CMD-000900', $texte);
            self::assertStringNotContainsString('125', $texte);
            self::assertDoesNotMatchRegularExpression('/\d F\b|FCFA/', $texte);
        }
    }

    private function entreprise(): Clients
    {
        return (new Clients())->setTypeClient('B2B')->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE)->setRaisonSociale('Salon Espoir');
    }

    /** @return array{CompteClient, CompteClient, CompteClient, CompteClient} administrateur, valideur, payeur, demandeur */
    private function personnes(Clients $entreprise): array
    {
        $admin = (new CompteClient())->setClient($entreprise)->setNom('Oumar')->setTelephone('22360697804');
        $personne = static fn (string $nom, string $tel, array $droits): CompteClient => (new CompteClient())
            ->setClient($entreprise)->setNom($nom)->setTelephone($tel)->setDroits($droits)->setAjoutePar($admin);

        return [
            $admin,
            $personne('Awa', '22366000101', [CompteClient::DROIT_VALIDEUR]),
            $personne('Ibrahim', '22366000102', [CompteClient::DROIT_PAYEUR]),
            $personne('Mariam', '22366000103', []),
        ];
    }

    /** @param list<CompteClient> $comptes */
    private function service(array $comptes, bool $smsActifs = false): CompteEntreprise
    {
        $repository = $this->createStub(CompteClientRepository::class);
        $repository->method('findBy')->willReturn($comptes);

        $http = new MockHttpClient(function (string $methode, string $url, array $options): MockResponse {
            if (str_contains($url, '/oauth/')) {
                return new MockResponse((string) json_encode(['access_token' => 'jeton', 'expires_in' => 3600]));
            }
            $corps = json_decode((string) ($options['body'] ?? '{}'), true);
            $this->sms[] = (string) ($corps['outboundSMSMessageRequest']['outboundSMSTextMessage']['message'] ?? '');

            return new MockResponse('{}', ['http_code' => 201]);
        });
        $sms = $smsActifs
            ? new OrangeSmsService($http, new ArrayAdapter(), new NullLogger(), 'id', 'secret', 'tel:+22300000000', 'SuccesImprim')
            : new OrangeSmsService(new MockHttpClient(), new ArrayAdapter(), new NullLogger(), '', '', '', '');

        return new CompteEntreprise($this->createStub(EntityManagerInterface::class), $repository, $sms);
    }
}
