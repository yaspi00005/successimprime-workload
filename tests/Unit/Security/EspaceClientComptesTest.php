<?php

namespace App\Tests\Unit\Security;

use App\Entity\AccesEspaceClient;
use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Repository\AccesEspaceClientRepository;
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

    /** @var list<AccesEspaceClient> codes d'accès « en base » */
    private array $acces = [];

    protected function setUp(): void
    {
        $this->fabrique = new FabriqueCodes();
        $this->persistes = [];
        $this->acces = [];
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

        $acces = $this->createStub(AccesEspaceClientRepository::class);
        $acces->method('parEmpreinte')->willReturnCallback(function (string $empreinte): ?AccesEspaceClient {
            foreach ($this->acces as $a) {
                if ($a->getEmpreinte() === $empreinte) {
                    return $a;
                }
            }

            return null;
        });
        // Comme la requête : seulement si ce code n'a pas déjà servi.
        $acces->method('consommer')->willReturnCallback(static function (AccesEspaceClient $a, \DateTimeImmutable $le): bool {
            $libre = !$a->estUtilise();
            $a->utiliser($le);

            return $libre;
        });

        return new EspaceClientComptes($repository, $em, $this->fabrique->codes(), $acces);
    }

    /** Code d'accès donné par la boutique pour cette fiche ; rend le code en clair. */
    private function donnerAcces(Clients $fiche, \DateTimeImmutable $le): string
    {
        $acces = (new AccesEspaceClient())->setClient($fiche);
        $this->acces[] = $acces;

        return $acces->nouveauCode((string) $fiche->getTelephone(), $le, null);
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

    public function testApresUnCodeDAccesDeLaBoutiqueMotDePasseObligatoireEmailFacultatif(): void
    {
        $compte = self::compte(self::fiche('76124212', 'yaya@gmail.com'), '76124212')->setMotDePasse('ancienne');
        self::assertSame(['motDePasse' => EspaceClientComptes::REQUIS, 'email' => null, 'bloquant' => true], EspaceClientComptes::etapes($compte, EspaceClientComptes::PAR_ACCES), 'e-mail déjà connu');

        $sansEmail = self::compte(self::fiche('76124213', null), '76124213');
        self::assertSame(['motDePasse' => EspaceClientComptes::REQUIS, 'email' => EspaceClientComptes::PROPOSE, 'bloquant' => true], EspaceClientComptes::etapes($sansEmail, EspaceClientComptes::PAR_ACCES), 'proposé, pas obligatoire');
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
    public function testPremiereConnexionAvecLeCodeDeLaBoutique(): void
    {
        $fiche = self::fiche('76124212', null);
        (new \ReflectionProperty($fiche, 'id'))->setValue($fiche, 42);
        $le = new \DateTimeImmutable('2026-10-14 10:00');
        $code = $this->donnerAcces($fiche, $le);
        $service = $this->service([], ['22376124212' => $fiche]);

        $compte = $service->ouvrirParAcces('76 12 42 12', strtolower(AccesEspaceClient::afficherCode($code)), $le->modify('+1 hour'));

        self::assertNotNull($compte, 'compte ouvert sur la fiche');
        self::assertSame($fiche, $compte->getClient());
        self::assertSame('22376124212', $compte->getTelephone());
        self::assertTrue($this->acces[0]->estUtilise(), 'code consommé');
        self::assertSame(['motDePasse' => EspaceClientComptes::REQUIS, 'email' => EspaceClientComptes::PROPOSE, 'bloquant' => true], EspaceClientComptes::etapes($compte, EspaceClientComptes::PAR_ACCES));
        self::assertNull($service->ouvrirParAcces('76124212', $code, $le->modify('+2 hours')), 'une seule fois');
    }

    public function testLeCodeDeLaBoutiqueServeDeMotDePasseOublie(): void
    {
        $fiche = self::fiche('76124212', 'yaya@gmail.com');
        (new \ReflectionProperty($fiche, 'id'))->setValue($fiche, 42);
        $existant = self::compte($fiche, '76124212')->setMotDePasse('ancienne-empreinte');
        $le = new \DateTimeImmutable('2026-10-14 10:00');
        $code = $this->donnerAcces($fiche, $le);

        $compte = $this->service(['22376124212' => $existant])->ouvrirParAcces('76124212', $code, $le);

        self::assertSame($existant, $compte);
        self::assertSame(EspaceClientComptes::REQUIS, EspaceClientComptes::etapes($existant, EspaceClientComptes::PAR_ACCES)['motDePasse'], 'nouveau mot de passe obligatoire');
    }

    public function testCodeDAccesRefuse(): void
    {
        $le = new \DateTimeImmutable('2026-10-14 10:00');
        $fiche = self::fiche('76124212', null);
        (new \ReflectionProperty($fiche, 'id'))->setValue($fiche, 42);
        $code = $this->donnerAcces($fiche, $le);
        $service = $this->service();

        self::assertNull($service->ouvrirParAcces('76124212', 'ABCD-EFGH', $le), 'faux');
        self::assertNull($service->ouvrirParAcces('76124213', $code, $le), 'avec le numéro de quelqu\'un d\'autre');
        self::assertNull($service->ouvrirParAcces('76124212', substr($code, 0, 7), $le), 'incomplet');
        self::assertNull($service->ouvrirParAcces('76124212', $code, $le->modify('+49 hours')), 'expiré');
        self::assertFalse($this->acces[0]->estUtilise(), 'un refus ne consomme rien');

        // Un nouveau code annule le précédent.
        $nouveau = $this->acces[0]->nouveauCode('76124212', $le->modify('+1 hour'), null);
        self::assertNull($service->ouvrirParAcces('76124212', $code, $le->modify('+2 hours')));
        self::assertNotNull($service->ouvrirParAcces('76124212', $nouveau, $le->modify('+2 hours')));
    }

    public function testCodeDAccesRefuseSiLeNumeroAChangeOuAppartientAUneAutreFiche(): void
    {
        $le = new \DateTimeImmutable('2026-10-14 10:00');
        $fiche = self::fiche('76124212', null);
        (new \ReflectionProperty($fiche, 'id'))->setValue($fiche, 42);
        $code = $this->donnerAcces($fiche, $le);

        $autre = self::fiche('76124212', null, 'CLI-000043');
        (new \ReflectionProperty($autre, 'id'))->setValue($autre, 43);
        self::assertNull($this->service(['22376124212' => self::compte($autre, '76124212')])->ouvrirParAcces('76124212', $code, $le), 'compte d\'une autre fiche');
        self::assertNull($this->service(['22376124212' => self::compte($fiche, '76124212')->setActif(false)])->ouvrirParAcces('76124212', $code, $le), 'compte fermé');

        $fiche->setTelephone('66000001');
        self::assertNull($this->service()->ouvrirParAcces('76124212', $code, $le), 'numéro de la fiche changé depuis');
        self::assertFalse($this->acces[0]->estUtilise());
    }

    public function testJamaisPourUneFicheDeDemonstration(): void
    {
        $le = new \DateTimeImmutable('2026-10-14 10:00');
        $demo = self::fiche('60697804', null, 'DEMO-0002');
        $code = $this->donnerAcces($demo, $le);

        self::assertNull($this->service()->ouvrirParAcces('60697804', $code, $le));
    }
}
