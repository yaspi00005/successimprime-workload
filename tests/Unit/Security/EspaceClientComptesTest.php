<?php

namespace App\Tests\Unit\Security;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use App\Security\CodeDeConnexion;
use App\Security\DestinationCode;
use App\Security\EspaceClientComptes;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Où part le code de l'espace client, et ce qu'il reste à faire avant
 * d'entrer (EspaceClientComptes).
 */
final class EspaceClientComptesTest extends TestCase
{
    private FabriqueCodes $fabrique;

    /** @var list<object> */
    private array $persistes = [];

    protected function setUp(): void
    {
        $this->fabrique = new FabriqueCodes();
        $this->persistes = [];
    }

    private static function fiche(string $telephone, ?string $email, string $code = 'CLI-000042'): Clients
    {
        return (new Clients())->setCode($code)->setNom('Traoré')->setTelephone($telephone)->setEmail($email);
    }

    private static function compte(Clients $client, string $telephone, ?string $email = null): CompteClient
    {
        return (new CompteClient())->setClient($client)->setTelephone($telephone)->setEmail($email);
    }

    /**
     * @param array<string, CompteClient> $comptes par numéro normalisé
     * @param array<string, Clients>      $fiches  par numéro normalisé
     */
    private function service(array $comptes = [], array $fiches = []): EspaceClientComptes
    {
        $repository = $this->createStub(CompteClientRepository::class);
        $repository->method('parTelephone')->willReturnCallback(static fn (string $t): ?CompteClient => $comptes[CompteClient::normaliserTelephone($t)] ?? null);
        $repository->method('clientDuTelephone')->willReturnCallback(static fn (string $t): ?Clients => $fiches[CompteClient::normaliserTelephone($t)] ?? null);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persistes[] = $o;
        });
        // Le flush donne un id, comme la base.
        $em->method('flush')->willReturnCallback(function (): void {
            foreach ($this->persistes as $i => $o) {
                if (null === $o->getId()) {
                    (new \ReflectionProperty($o, 'id'))->setValue($o, 500 + $i);
                }
            }
        });

        return new EspaceClientComptes($repository, $em, $this->fabrique->codes());
    }

    public function testCompteDuTitulaireLEmailDeLaFiche(): void
    {
        $fiche = self::fiche('76124212', 'Contact@Hotel-Niger.ml');
        $service = $this->service(['22376124212' => self::compte($fiche, '76124212')]);

        $destination = $service->destination('76124212');

        self::assertSame('contact@hotel-niger.ml', $destination->email);
        self::assertSame(DestinationCode::NORMALE, $destination->type);
    }

    public function testFicheSansEmailParSms(): void
    {
        $fiche = self::fiche('76124212', null);
        $service = $this->service(['22376124212' => self::compte($fiche, '76124212')], ['22376124212' => $fiche]);

        self::assertNull($service->destination('76124212')->email);
        self::assertNull($service->destination('76124212', true, 'pirate@gmail.com')->email);
        self::assertSame(CodeDeConnexion::SMS, $this->fabrique->codes()->canal($service->destination('76124212')));
    }

    public function testLEmailDeLaPersonnePasseAvantCeluiDeLaFiche(): void
    {
        $fiche = self::fiche('76124212', 'contact@hotel-niger.ml');
        $service = $this->service(['22376124212' => self::compte($fiche, '76124212', 'awa@gmail.com')]);

        self::assertSame('awa@gmail.com', $service->destination('76124212')->email);
    }

    public function testUnePersonneAjouteeNeRecoitJamaisSesCodesALAdresseDeLEntreprise(): void
    {
        $fiche = self::fiche('76124212', 'contact@hotel-niger.ml');
        $service = $this->service([
            '22366000001' => self::compte($fiche, '66000001'),
            '22366000002' => self::compte($fiche, '66000002', 'ibrahim@gmail.com'),
        ]);

        self::assertNull($service->destination('66000001')->email, 'sans e-mail à elle : par SMS');
        self::assertSame('ibrahim@gmail.com', $service->destination('66000002')->email);
    }

    public function testFicheSansCompteLEmailDeLaFiche(): void
    {
        $service = $this->service([], ['22376124212' => self::fiche('76124212', 'yaya@gmail.com')]);

        self::assertSame('yaya@gmail.com', $service->destination('76124212')->email);
    }

    public function testCreerUnCompteAvecLeNumeroDUneFicheLeCodeVaALaFicheJamaisALAdresseSaisie(): void
    {
        $fiche = self::fiche('76124212', 'yaya@gmail.com');
        $service = $this->service([], ['22376124212' => $fiche]);

        $envoi = $service->envoyerCode($session = new Session(new MockArraySessionStorage()), '76124212', '10.0.0.1', ['nom' => 'Pirate', 'prenom' => '', 'email' => 'pirate@gmail.com']);

        self::assertSame('y•••@gmail.com', $envoi['destination']);
        self::assertSame('yaya@gmail.com', $this->fabrique->emails[0]->getTo()[0]->getAddress());
        self::assertArrayNotHasKey('email', $this->fabrique->codes()->donnees($session, EspaceClientComptes::SESSION_CODE), 'l\'adresse saisie n\'est pas gardée');
    }

    public function testNouveauClientLeCodeVaALAdresseSaisieQuiEstNoteeSurSaNouvelleFiche(): void
    {
        $service = $this->service();
        $session = new Session(new MockArraySessionStorage());

        $envoi = $service->envoyerCode($session, '76999999', '10.0.0.1', ['nom' => 'Koné', 'prenom' => 'Fanta', 'email' => 'Fanta.Kone@Gmail.com']);

        self::assertTrue($envoi['envoye']);
        self::assertSame('email', $envoi['canal']);
        self::assertSame('fanta.kone@gmail.com', $this->fabrique->emails[0]->getTo()[0]->getAddress());
        $donnees = $this->fabrique->codes()->donnees($session, EspaceClientComptes::SESSION_CODE);
        self::assertSame('fanta.kone@gmail.com', $donnees['email']);

        $compte = $service->ouvrir($donnees);
        self::assertNotNull($compte);
        self::assertSame('fanta.kone@gmail.com', $compte->getClient()?->getEmail());
        self::assertSame('76999999', $compte->getClient()?->getTelephone());
        self::assertTrue($compte->estTitulaire());
        self::assertSame('fanta.kone@gmail.com', $compte->emailDesCodes());
    }

    public function testNouveauClientSansEmailParSmsEtRienNEstNote(): void
    {
        $service = $this->service();
        $session = new Session(new MockArraySessionStorage());

        $envoi = $service->envoyerCode($session, '76999999', '10.0.0.1', ['nom' => 'Koné', 'prenom' => '', 'email' => '']);

        self::assertSame('sms', $envoi['canal']);
        self::assertArrayNotHasKey('email', $this->fabrique->codes()->donnees($session, EspaceClientComptes::SESSION_CODE));
    }

    public function testNumeroInconnuSansCreationRienNePart(): void
    {
        $envoi = $this->service()->envoyerCode(new Session(new MockArraySessionStorage()), '76999999', '10.0.0.1');

        self::assertTrue($envoi['envoye'], 'même réponse que pour un client');
        self::assertSame('sms', $envoi['canal']);
        self::assertSame([], $this->fabrique->emails);
        self::assertSame(DestinationCode::INCONNUE, $this->service()->destination('76999999')->type);
    }

    public function testCompteFermeOuFicheDeDemonstration(): void
    {
        $fiche = self::fiche('76124212', 'yaya@gmail.com');
        $ferme = self::compte($fiche, '76124212')->setActif(false);
        self::assertSame(DestinationCode::INCONNUE, $this->service(['22376124212' => $ferme])->destination('76124212')->type);

        $demo = self::fiche('60697804', 'contact@salon-espoir.ml', 'DEMO-0002');
        $service = $this->service(['22360697804' => self::compte($demo, '60697804')], ['22360697804' => $demo]);
        self::assertSame(DestinationCode::DEMONSTRATION, $service->destination('60697804')->type);
        $service->envoyerCode(new Session(new MockArraySessionStorage()), '60697804', '10.0.0.1');
        self::assertSame([], $this->fabrique->emails, 'jamais d\'e-mail à une fiche de démonstration');
    }

    public function testMotDePasseObligatoireTantQuIlNyEnAPas(): void
    {
        $compte = self::compte(self::fiche('76124212', 'yaya@gmail.com'), '76124212');

        foreach ([null, EspaceClientComptes::PAR_CODE] as $par) {
            $etapes = EspaceClientComptes::etapes($compte, $par);
            self::assertSame(EspaceClientComptes::REQUIS, $etapes['motDePasse']);
            self::assertTrue($etapes['bloquant']);
            self::assertNull($etapes['email'], 'l\'e-mail de la fiche suffit');
        }

        $compte->setMotDePasse('empreinte');
        self::assertSame(['motDePasse' => null, 'email' => null, 'bloquant' => false], EspaceClientComptes::etapes($compte, null), 'connexion par mot de passe : rien à faire');
        self::assertSame(['motDePasse' => EspaceClientComptes::PROPOSE, 'email' => null, 'bloquant' => false], EspaceClientComptes::etapes($compte, EspaceClientComptes::PAR_CODE), 'mot de passe oublié : un nouveau est proposé');
    }

    public function testSansEmailConnuLAdresseEstProposeeApresUnCode(): void
    {
        $compte = self::compte(self::fiche('76124212', null), '76124212');

        self::assertSame(EspaceClientComptes::PROPOSE, EspaceClientComptes::etapes($compte, EspaceClientComptes::PAR_CODE)['email']);
        $compte->setMotDePasse('empreinte');
        self::assertNull(EspaceClientComptes::etapes($compte, null)['email']);
    }

    public function testApresUnCodeDAccesDeLaBoutiqueEmailEtMotDePasseObligatoires(): void
    {
        $compte = self::compte(self::fiche('76124212', 'yaya@gmail.com'), '76124212')->setMotDePasse('ancienne');

        self::assertSame(['motDePasse' => EspaceClientComptes::REQUIS, 'email' => EspaceClientComptes::REQUIS, 'bloquant' => true], EspaceClientComptes::etapes($compte, EspaceClientComptes::PAR_ACCES));
    }

    public function testLEmailDuTitulaireVaSurUneFicheQuiNEnAPas(): void
    {
        $service = $this->service();
        $fiche = self::fiche('76124212', null);
        $titulaire = self::compte($fiche, '76124212');
        $service->noterEmail($titulaire, 'yaya@gmail.com');
        self::assertSame('yaya@gmail.com', $titulaire->getEmail());
        self::assertSame('yaya@gmail.com', $fiche->getEmail(), 'proposé à la fiche, qui monte au local');

        $entreprise = self::fiche('76124212', 'contact@hotel-niger.ml');
        $service->noterEmail(self::compte($entreprise, '76124212'), 'gerant@gmail.com');
        $service->noterEmail(self::compte($entreprise, '66000001'), 'awa@gmail.com');
        self::assertSame('contact@hotel-niger.ml', $entreprise->getEmail(), 'l\'adresse de la fiche ne change pas');
    }
}
