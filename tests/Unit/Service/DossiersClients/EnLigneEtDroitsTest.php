<?php

namespace App\Tests\Unit\Service\DossiersClients;

use App\Entity\Clients;
use App\Entity\Reglage;
use App\Entity\User;
use App\Security\AccesEnLigne;
use App\Security\DossiersClientsVoter;
use App\Service\DossiersClients\AnalyseDossiers;
use App\Service\DossiersClients\ExplorateurDossier;
use App\Service\DossiersClients\JournalDossiers;
use App\Service\DossiersClients\PublicationFichiers;
use App\Service\DossiersClients\ReglagesDossiers;
use App\Service\Parametres\Parametres;
use App\Service\ProduitsClient\ControleFichierLie;
use App\Service\ProduitsClient\DossierClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Le site en ligne ne voit pas le serveur de l'atelier : aucun chemin,
 * aucun écran des dossiers, seulement les fichiers publiés. Au local, les
 * droits suivent la matrice sous-dossier par sous-dossier.
 */
final class EnLigneEtDroitsTest extends TestCase
{
    private string $dossier;

    protected function setUp(): void
    {
        $this->dossier = sys_get_temp_dir().'/dc-enligne-'.bin2hex(random_bytes(4));
        mkdir($this->dossier.'/DEMO-0002 Salon Espoir', 0777, true);
    }

    protected function tearDown(): void
    {
        @rmdir($this->dossier.'/DEMO-0002 Salon Espoir');
        @rmdir($this->dossier);
    }

    private function reglages(bool $enLigne): ReglagesDossiers
    {
        $parametres = (new \ReflectionClass(Parametres::class))->newInstanceWithoutConstructor();
        $tuile = (new Reglage(ReglagesDossiers::TUILE))->modifier(['dossier_racine' => $this->dossier, 'racine_postes' => '\\\\SERVEUR\\Clients', 'dossier_a_classer' => $this->dossier], null, new \DateTimeImmutable());
        (new \ReflectionProperty(Parametres::class, 'reglages'))->setValue($parametres, [ReglagesDossiers::TUILE => $tuile]);
        $acces = (new \ReflectionClass(AccesEnLigne::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(AccesEnLigne::class, 'enLigne'))->setValue($acces, $enLigne);

        return new ReglagesDossiers($parametres, $acces, 'secret', sys_get_temp_dir());
    }

    public function testEnLigneAucunCheminDuServeur(): void
    {
        $local = $this->reglages(false);
        self::assertSame($this->dossier, $local->racine());

        $enLigne = $this->reglages(true);
        self::assertSame('', $enLigne->racine());
        self::assertSame('', $enLigne->racinePostes());
        self::assertSame('', $enLigne->dossierAClasser());

        $client = (new Clients())->setCode('DEMO-0002');
        $controle = (new \ReflectionClass(ControleFichierLie::class))->newInstanceWithoutConstructor();
        $cache = $this->createStub(CacheInterface::class);
        self::assertSame($this->dossier.\DIRECTORY_SEPARATOR.'DEMO-0002 Salon Espoir', (new DossierClient($local, $controle, $cache))->dossierClient($client));
        self::assertNull((new DossierClient($enLigne, $controle, $cache))->dossierClient($client));
    }

    public function testEnLigneLOngletMontreSeulementLesFichiersPublies(): void
    {
        $depot = $this->createStub(EntityRepository::class);
        $depot->method('findBy')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($depot);
        // Ni requête sur les fichiers du dossier, ni lecture du disque.
        $em->expects(self::never())->method('createQueryBuilder');
        $reglages = $this->reglages(true);
        $journal = (new \ReflectionClass(JournalDossiers::class))->newInstanceWithoutConstructor();
        $dossiers = (new \ReflectionClass(DossierClient::class))->newInstanceWithoutConstructor();
        $publications = new PublicationFichiers($em, $reglages, $dossiers, $journal, sys_get_temp_dir());
        $analyse = (new \ReflectionClass(AnalyseDossiers::class))->newInstanceWithoutConstructor();

        $vue = (new ExplorateurDossier($em, $reglages, $publications, $analyse))->pourClient(new Clients(), (new User())->setRoles(['ROLE_ADMIN']));

        self::assertSame(['en_ligne' => true, 'publies' => []], $vue);
    }

    public function testVoteurRefuseToutEnLigne(): void
    {
        $admin = (new User())->setRoles(['ROLE_ADMIN']);
        $jeton = new UsernamePasswordToken($admin, 'main', $admin->getRoles());

        foreach ([DossiersClientsVoter::VOIR, DossiersClientsVoter::CLASSER, DossiersClientsVoter::REGLER] as $attribut) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, (new DossiersClientsVoter($this->reglages(false)))->vote($jeton, null, [$attribut]), $attribut);
            self::assertSame(VoterInterface::ACCESS_DENIED, (new DossiersClientsVoter($this->reglages(true)))->vote($jeton, null, [$attribut]), $attribut);
        }
    }

    public function testDroitsParSousDossier(): void
    {
        $r = $this->reglages(false);
        $operateur = (new User())->setRoles(['ROLE_PRODUCTION']);
        $graphiste = (new User())->setRoles(['ROLE_GRAPHISTE']);
        $livreur = (new User())->setRoles(['ROLE_LIVREUR']);

        self::assertTrue($r->peut($operateur, 'voir', 'prets'));
        self::assertFalse($r->peut($operateur, 'voir', 'conceptions'));
        self::assertFalse($r->peut($operateur, 'ajouter', 'prets'));
        self::assertTrue($r->peut($graphiste, 'ajouter', 'bat'));
        self::assertFalse($r->peut($graphiste, 'supprimer', 'bat'));
        self::assertFalse($r->peut(null, 'voir', 'prets'));

        $jeton = static fn (User $u): UsernamePasswordToken => new UsernamePasswordToken($u, 'main', $u->getRoles());
        $voteur = new DossiersClientsVoter($r);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voteur->vote($jeton($operateur), null, [DossiersClientsVoter::VOIR]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voteur->vote($jeton($operateur), null, [DossiersClientsVoter::CLASSER]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voteur->vote($jeton($livreur), null, [DossiersClientsVoter::VOIR]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voteur->vote($jeton($graphiste), null, [DossiersClientsVoter::REGLER]));
    }

    public function testLienSigneAvecLaCleDesPostes(): void
    {
        $r = $this->reglages(false);
        $lien = $r->lienProtocole()->lien('ouvrir', '\\\\SERVEUR\\Clients\\A\\a.ai', 1000);

        self::assertNotNull($r->lienProtocole()->verifier($lien, $r->racinePostes(), 1000));
        self::assertNull($r->lienProtocole()->verifier($lien, '\\\\SERVEUR\\Compta', 1000));
    }
}
