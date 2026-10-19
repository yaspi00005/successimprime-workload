<?php

namespace App\Tests\Unit\Service\MessagerieInterne;

use App\Entity\Clients;
use App\Entity\PieceJointeMessage;
use App\Entity\User;
use App\Service\FichiersPrives;
use App\Service\MessagerieInterne\FichiersEquipe;
use App\Service\MessagerieInterne\RangementDossierClient;
use PHPUnit\Framework\TestCase;

/**
 * Fichiers de la messagerie de l'équipe (MI03) : plusieurs fichiers par
 * morceaux avec reprise, taille maximale, liens vers le dossier du client
 * jamais en dehors de ce dossier, rangement sans écrasement.
 */
final class FichiersEquipeTest extends TestCase
{
    private string $projet;
    private string $racine;
    private Clients $client;
    private User $moi;

    protected function setUp(): void
    {
        $this->projet = OutilsMessagerieInterne::dossierTemporaire();
        $this->racine = $this->projet.'/clients';
        mkdir($this->racine.'/C-0087 Hôtel Niger/04 Prêts à imprimer', 0775, true);
        file_put_contents($this->racine.'/C-0087 Hôtel Niger/04 Prêts à imprimer/bache-facade_v2.psd', "8BPS\x00\x01".str_repeat("\x00", 64));
        mkdir($this->racine.'/C-0099 Autre client', 0775, true);
        file_put_contents($this->racine.'/C-0099 Autre client/secret.pdf', '%PDF-1.4 autre client');
        file_put_contents($this->projet.'/hors-racine.pdf', '%PDF-1.4 hors racine');
        $this->client = (new Clients())->setCode('C-0087');
        $this->moi = (new User())->setRoles(['ROLE_PRODUCTION']);
        (new \ReflectionProperty(User::class, 'id'))->setValue($this->moi, 7);
    }

    protected function tearDown(): void
    {
        OutilsMessagerieInterne::supprimer($this->projet);
    }

    private function service(int $tailleMaxMo = 50, ?string $racine = null): FichiersEquipe
    {
        return new FichiersEquipe(new FichiersPrives($this->projet), OutilsMessagerieInterne::dossierClient($racine ?? $this->racine), OutilsMessagerieInterne::regles($tailleMaxMo), $this->projet);
    }

    public function testCheminsRefusesHorsDuDossierDuClient(): void
    {
        foreach (['../C-0099 Autre client/secret.pdf', '04 Prêts à imprimer/../../hors-racine.pdf', '/etc/passwd', 'C:\\Windows\\win.ini', '\\\\serveur\\partage\\x.pdf', '.cache/x.pdf', 'a/b/c/d.pdf', '', "x\0.pdf"] as $chemin) {
            self::assertNull(FichiersEquipe::normaliser($chemin), $chemin);
            self::assertNull($this->service()->resoudre($this->client, $chemin), $chemin);
        }
        self::assertSame('04 Prêts à imprimer/bache-facade_v2.psd', FichiersEquipe::normaliser('04 Prêts à imprimer\\bache-facade_v2.psd'));
    }

    public function testLienVersLeServeurSansCopie(): void
    {
        $service = $this->service();
        $piece = $service->lienServeur($this->client, '04 Prêts à imprimer/bache-facade_v2.psd');
        self::assertTrue($piece->estLien());
        self::assertNull($piece->getStockage());
        self::assertSame('04 Prêts à imprimer/bache-facade_v2.psd', $piece->getCheminServeur());
        self::assertSame(realpath($this->racine.'/C-0087 Hôtel Niger/04 Prêts à imprimer/bache-facade_v2.psd'), $service->cheminLien($piece));
        self::assertFileDoesNotExist($this->projet.'/var/uploads/chat');

        $this->expectException(\DomainException::class);
        $service->lienServeur($this->client, '../C-0099 Autre client/secret.pdf');
    }

    public function testLienSymboliqueVersLExterieurRefuse(): void
    {
        if (!\function_exists('symlink') || !@symlink($this->projet.'/hors-racine.pdf', $this->racine.'/C-0087 Hôtel Niger/raccourci.pdf')) {
            self::markTestSkipped('Liens symboliques indisponibles.');
        }
        self::assertNull($this->service()->resoudre($this->client, 'raccourci.pdf'));
    }

    public function testSansDossierRacineRienNEstLisible(): void
    {
        $service = $this->service(50, '');
        self::assertFalse($service->dossiersDisponibles());
        self::assertNull($service->resoudre($this->client, '04 Prêts à imprimer/bache-facade_v2.psd'));
        self::assertNotNull((new RangementDossierClient(OutilsMessagerieInterne::dossierClient('')))->raisonIndisponible($this->client));
    }

    public function testPlusieursFichiersParMorceauxAvecReprise(): void
    {
        $service = $this->service();
        $gros = "%PDF-1.4\n".random_bytes(FichiersEquipe::MORCEAU * 2 + 1000);
        $petit = "%PDF-1.4\npetit fichier\n%%EOF\n";
        $a = $service->debut($this->moi, 'plan v3.pdf', \strlen($gros));
        $b = $service->debut($this->moi, 'bat.pdf', \strlen($petit));
        self::assertSame(3, $a['morceaux']);
        self::assertSame(1, $b['morceaux']);

        // Dans le désordre, entremêlés, un morceau renvoyé après une coupure.
        $service->morceau($this->moi, $a['jeton'], 2, substr($gros, 2 * FichiersEquipe::MORCEAU));
        $service->morceau($this->moi, $b['jeton'], 0, $petit);
        $service->morceau($this->moi, $a['jeton'], 0, substr($gros, 0, FichiersEquipe::MORCEAU));
        $etat = $service->morceau($this->moi, $a['jeton'], 0, substr($gros, 0, FichiersEquipe::MORCEAU));
        self::assertSame([0, 2], $etat['recus']);
        self::assertFalse($etat['termine']);
        self::assertSame([0, 2], $service->etat($this->moi, $a['jeton'])['recus']);
        try {
            $service->rangerEnvoi($this->moi, $a['jeton']);
            self::fail('Un envoi incomplet ne se range pas.');
        } catch (\DomainException) {
        }
        try {
            $service->morceau($this->moi, $a['jeton'], 1, 'trop court');
            self::fail('Un morceau tronqué est refusé.');
        } catch (\DomainException) {
        }
        self::assertTrue($service->morceau($this->moi, $a['jeton'], 1, substr($gros, FichiersEquipe::MORCEAU, FichiersEquipe::MORCEAU))['termine']);

        $pieces = [$service->rangerEnvoi($this->moi, $a['jeton']), $service->rangerEnvoi($this->moi, $b['jeton'])];
        self::assertSame(['plan v3.pdf', 'bat.pdf'], array_map(static fn (PieceJointeMessage $p): string => $p->getNom(), $pieces));
        self::assertSame(\strlen($gros), $pieces[0]->getOctets());
        self::assertSame($gros, file_get_contents((string) $service->chemin($pieces[0])));
        self::assertSame($petit, file_get_contents((string) $service->chemin($pieces[1])));
        self::assertStringStartsWith($this->projet.'/var/uploads/chat/', (string) $service->chemin($pieces[0]));

        $service->terminer($this->moi, [$a['jeton'], $b['jeton']]);
        $this->expectException(\DomainException::class);
        $service->etat($this->moi, $a['jeton']);
    }

    public function testUnEnvoiNAppartientQuASonAuteur(): void
    {
        $service = $this->service();
        $a = $service->debut($this->moi, 'bat.pdf', 20);
        $autre = (new User())->setRoles(['ROLE_PRODUCTION']);
        (new \ReflectionProperty(User::class, 'id'))->setValue($autre, 8);
        $this->expectException(\DomainException::class);
        $service->morceau($autre, $a['jeton'], 0, str_repeat('x', 20));
    }

    public function testTailleMaximaleEtTypeRefuse(): void
    {
        $service = $this->service(1);
        try {
            $service->debut($this->moi, 'enorme.psd', 2 * 1024 * 1024);
            self::fail('Au-delà de la taille maximale, l\'envoi est refusé.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('lien', $e->getMessage());
        }
        $source = $this->projet.'/programme.exe';
        file_put_contents($source, "MZ\x90\x00".random_bytes(200));
        $this->expectException(\DomainException::class);
        $service->rangerFichier($source, 'programme.exe');
    }

    public function testRangementSansEcrasement(): void
    {
        $rangement = new RangementDossierClient(OutilsMessagerieInterne::dossierClient($this->racine));
        $source = $this->projet.'/plan.pdf';
        file_put_contents($source, '%PDF-1.4 version 1');
        self::assertSame('02 Fichiers reçus/plan.pdf', $rangement->ranger($this->client, $source, 'plan.pdf'));
        file_put_contents($source, '%PDF-1.4 version 2');
        self::assertSame('02 Fichiers reçus/plan (2).pdf', $rangement->ranger($this->client, $source, 'plan.pdf'));
        $dossier = $this->racine.'/C-0087 Hôtel Niger/02 Fichiers reçus';
        self::assertSame('%PDF-1.4 version 1', file_get_contents($dossier.'/plan.pdf'));
        self::assertSame('%PDF-1.4 version 2', file_get_contents($dossier.'/plan (2).pdf'));
        self::assertSame('02 Fichiers reçus/x.pdf', $rangement->ranger($this->client, $source, '../../x.pdf'));
        self::assertFileExists($dossier.'/x.pdf');
        self::assertFileDoesNotExist($this->projet.'/x.pdf');
    }
}
