<?php

namespace App\Tests\Unit\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\ContactClient;
use App\Entity\ModificationClient;
use App\Entity\SuspensionEspace;
use App\Entity\User;
use App\Repository\SuspensionEspaceRepository;
use App\Security\EspaceClientChecker;
use App\Service\EspaceClient\SuspensionsEspace;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

final class SuspensionsEspaceTest extends TestCase
{
    /** @var list<SuspensionEspace> suspensions « en base » */
    private array $base = [];

    /** @var list<object> objets enregistrés pendant le test */
    private array $enregistres = [];

    public function testUnePersonneSuspendueLesAutresNon(): void
    {
        $entreprise = $this->entreprise();
        [$awa, $mariam] = $this->comptes($entreprise);
        $service = $this->service();

        $this->base[] = (new SuspensionEspace())->setClient($entreprise)->setTelephone('66 00 01 03')->setMotif('Ne travaille plus chez eux');

        self::assertTrue($service->compteSuspendu($mariam));
        self::assertFalse($service->compteSuspendu($awa));
        self::assertTrue($service->numeroSuspendu($entreprise, '+223 66000103'));
        self::assertSame([$awa], $service->sansSuspendus([$awa, $mariam]), 'ni SMS, ni notification, ni e-mail pour Mariam');

        // Un autre client avec le même numéro n'est pas visé.
        $autre = $this->entreprise(8);
        self::assertFalse($service->numeroSuspendu($autre, '66000103'));
    }

    public function testToutLeCompteSuspendu(): void
    {
        $entreprise = $this->entreprise();
        [$awa, $mariam] = $this->comptes($entreprise);
        $this->base[] = (new SuspensionEspace())->setClient($entreprise)->setMotif('Impayés');

        $service = $this->service();
        self::assertTrue($service->compteSuspendu($awa));
        self::assertTrue($service->compteSuspendu($mariam));
        self::assertSame([], $service->sansSuspendus([$awa, $mariam]));

        // Levée : chacun retrouve son accès, la ligne reste.
        $this->base[0]->lever(new \DateTimeImmutable(), null, 'Réglé');
        $service = $this->service();
        self::assertFalse($service->compteSuspendu($awa));
        self::assertCount(1, $this->base);
    }

    public function testLaConnexionEstRefuseeApresLeMotDePasse(): void
    {
        $entreprise = $this->entreprise();
        [$awa, $mariam] = $this->comptes($entreprise);
        $this->base[] = (new SuspensionEspace())->setClient($entreprise)->setTelephone('66000103')->setMotif('Départ');
        $checker = new EspaceClientChecker($this->service());

        // Avant le mot de passe : rien ne dit qu'elle est suspendue.
        $checker->checkPreAuth($mariam);
        try {
            $checker->checkPostAuth($mariam);
            self::fail('Mariam est suspendue');
        } catch (CustomUserMessageAccountStatusException $e) {
            self::assertSame('Votre accès à l\'espace client est suspendu. Contactez Succès Imprim.', $e->getMessageKey());
            self::assertStringNotContainsString('Départ', $e->getMessageKey(), 'le motif reste à l\'atelier');
        }
        $checker->checkPostAuth($awa);
        $this->addToAssertionCount(1);
    }

    public function testSuspendreEtRetablirSontNotesAuJournal(): void
    {
        $entreprise = $this->entreprise();
        $service = $this->service();
        $par = (new User())->setUsername('fatou');
        $le = new \DateTimeImmutable('2026-10-15 10:00');

        $s = $service->suspendre($entreprise, '66 00 01 03', 'Mariam Dembélé', '  Ne travaille   plus chez eux ', $par, $le);
        self::assertSame('22366000103', $s->getTelephone());
        self::assertSame('Ne travaille plus chez eux', $s->getMotif());
        self::assertSame($par, $s->getSuspenduPar());
        $journal = $this->journal();
        self::assertCount(1, $journal);
        self::assertSame('Accès à l\'espace client', $journal[0]->getChamp());
        self::assertSame('Suspendu, Mariam Dembélé (66 00 01 03) : Ne travaille plus chez eux', $journal[0]->getApres());
        self::assertSame('fatou', $journal[0]->getAuteur());

        $this->base[] = $s;
        $service->retablir($s, $par, 'Revenue', $le->modify('+1 day'));
        self::assertFalse($s->estActive());
        self::assertSame('Rétabli : Revenue', $this->journal()[1]->getApres());
        self::assertSame('Suspendu, Mariam Dembélé (66 00 01 03)', $this->journal()[1]->getAvant());

        $this->expectExceptionMessage('déjà rétabli');
        $service->retablir($s, $par, null, $le);
    }

    public function testSuspendreRefuse(): void
    {
        $entreprise = $this->entreprise();
        $service = $this->service();
        $le = new \DateTimeImmutable();

        foreach ([
            [null, 'ok', 'motif'],
            ['12', 'Impayés', 'invalide'],
        ] as [$numero, $motif, $attendu]) {
            try {
                $service->suspendre($entreprise, $numero, null, $motif, null, $le);
                self::fail($attendu);
            } catch (\DomainException $e) {
                self::assertStringContainsStringIgnoringCase($attendu, $e->getMessage());
            }
        }

        $this->base[] = (new SuspensionEspace())->setClient($entreprise)->setTelephone('66000103')->setMotif('Départ');
        try {
            $service->suspendre($entreprise, '66000103', null, 'Encore', null, $le);
            self::fail('déjà suspendue');
        } catch (\DomainException $e) {
            self::assertStringContainsString('déjà suspendu', $e->getMessage());
        }
        // Tout le compte par-dessus une personne : possible ; une personne par-dessus tout le compte : non.
        $this->base[] = $service->suspendre($entreprise, null, null, 'Impayés', null, $le);
        try {
            $service->suspendre($entreprise, '66000101', null, 'Départ', null, $le);
            self::fail('tout le compte déjà suspendu');
        } catch (\DomainException $e) {
            self::assertSame('Tout le compte est déjà suspendu.', $e->getMessage());
        }

        // En ligne : l'écran est fermé, l'imprimerie décide.
        $enLigne = $this->service(true);
        self::assertSame(SuspensionsEspace::FERME_EN_LIGNE, $enLigne->empechement());
        $this->expectException(\DomainException::class);
        $enLigne->suspendre($entreprise, null, null, 'Impayés', null, $le);
    }

    public function testPersonnesProposeesDepuisLaFiche(): void
    {
        $entreprise = $this->entreprise()->setTelephone('60445103')->setTelephone2('76 00 00 09');
        $oumar = (new ContactClient($entreprise, 'Oumar Traoré'))->setTelephone('60445103')->setRole('principal')->setFonction('Gérant');
        $awa = (new ContactClient($entreprise, 'Awa Koné'))->setTelephone('66000101')->setFonction('Comptable');
        $sansNumero = new ContactClient($entreprise, 'Moussa');

        $personnes = SuspensionsEspace::personnes($entreprise, [$oumar, $awa, $sansNumero]);

        self::assertSame(['22360445103', '22366000101', '22376000009'], array_column($personnes, 'telephone'), 'un numéro une fois, sans les contacts sans numéro');
        self::assertSame('Oumar Traoré', $personnes[0]['nom'], 'le titulaire du numéro de la fiche');
        self::assertSame('Oumar Traoré · numéro de la fiche · 60 44 51 03', $personnes[0]['libelle']);
        self::assertSame('Awa Koné · Comptable · 66 00 01 01', $personnes[1]['libelle']);
    }

    private function service(bool $enLigne = false): SuspensionsEspace
    {
        $repository = $this->createStub(SuspensionEspaceRepository::class);
        $repository->method('actives')->willReturnCallback(fn (Clients $c): array => array_values(array_filter($this->base, static fn (SuspensionEspace $s): bool => $s->getClient() === $c && $s->estActive())));
        $repository->method('pour')->willReturnCallback(function (Clients $c, string $tel): ?SuspensionEspace {
            foreach ($this->base as $s) {
                if ($s->getClient() === $c && $s->vise($tel)) {
                    return $s;
                }
            }

            return null;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->enregistres[] = $o;
        });

        return new SuspensionsEspace($em, $repository, $enLigne);
    }

    /** @return list<ModificationClient> */
    private function journal(): array
    {
        return array_values(array_filter($this->enregistres, static fn (object $o): bool => $o instanceof ModificationClient));
    }

    private function entreprise(int $id = 7): Clients
    {
        $client = (new Clients())->setTypeClient('B2B')->setTypeCompte(Clients::TYPE_COMPTE_ENTREPRISE)->setRaisonSociale('Salon Espoir');
        (new \ReflectionProperty(Clients::class, 'id'))->setValue($client, $id);

        return $client;
    }

    /** @return array{CompteClient, CompteClient} Awa (valideur), Mariam (demandeur) */
    private function comptes(Clients $entreprise): array
    {
        return [
            (new CompteClient())->setClient($entreprise)->setNom('Awa')->setTelephone('22366000101')->setDroits([CompteClient::DROIT_VALIDEUR]),
            (new CompteClient())->setClient($entreprise)->setNom('Mariam')->setTelephone('22366000103')->setDroits([]),
        ];
    }
}
