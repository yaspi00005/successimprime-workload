<?php

namespace App\Tests\Unit\Service\Rapports;

use App\Entity\Employes;
use App\Entity\EnvoiRapport;
use App\Entity\User;
use App\Service\Rapports\CourrielRapport;
use App\Service\Rapports\DestinatairesRapports;
use App\Service\Rapports\EnvoiRapports;
use App\Service\Rapports\PeriodeRapport;
use App\Service\Rapports\RapportPeriodique;
use App\Service\Rapports\ReglagesRapportsMail;
use App\Service\Statistiques\Export\ExportPdf;
use Doctrine\DBAL\Driver\PDO\Exception as ExceptionPdo;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Envoi des rapports par e-mail (docs/rapports-mail.md) : jamais deux
 * fois la même période, le résultat de chaque destinataire noté, les
 * reprises limitées, aucune donnée secrète dans l'historique.
 */
final class EnvoiRapportsTest extends TestCase
{
    private const HIERARCHIE = ['ROLE_ADMIN' => ['ROLE_STATS_GLOBAL', 'ROLE_STATS_VENTES', 'ROLE_PAIEMENT_VOIR', 'ROLE_TRESORERIE_VOIR', 'ROLE_COUT_MARGE', 'ROLE_CLIENT', 'ROLE_RH']];

    /** @var list<object> */
    private array $persistes = [];

    private static function utilisateur(int $id, ?string $email, bool $actif = true): User
    {
        $u = (new User())->setRoles(['ROLE_ADMIN'])->setUsername('demo.'.$id)->setEmploye((new Employes())->setPrenom('Awa')->setNom('Test'.$id)->setEmail((string) $email));
        $u->setActif($actif);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }

    /**
     * @param list<User>        $comptes  comptes actifs au moment de préparer l'envoi
     * @param array<int, ?User> $relus    compte relu au moment d'envoyer (désactivé entre-temps…)
     */
    private function envois(?EnvoiRapport $deja, array $comptes, array $relus, TransportInterface $transport, ?\Throwable $auFlush = null): EnvoiRapports
    {
        $depotEnvois = $this->createStub(EntityRepository::class);
        $depotEnvois->method('findOneBy')->willReturn($deja);
        $depotComptes = $this->createStub(EntityRepository::class);
        $depotComptes->method('findBy')->willReturn($comptes);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static fn (string $classe): EntityRepository => EnvoiRapport::class === $classe ? $depotEnvois : $depotComptes);
        $em->method('find')->willReturnCallback(static fn (string $classe, int $id): ?User => $relus[$id] ?? null);
        $em->method('persist')->willReturnCallback(function (object $o): void { $this->persistes[] = $o; });
        if (null !== $auFlush) {
            $em->method('flush')->willThrowException($auFlush);
        }

        $reglages = ReglagesRapportsMailTest::reglages([ReglagesRapportsMail::TUILE => [
            'destinataires_choisis' => true,
            'destinataires' => implode(',', array_map(static fn (User $u): int => (int) $u->getId(), $comptes)),
        ]], 'null://null', 'rapports@succesimprim.com');
        $courriel = new CourrielRapport(
            (new \ReflectionClass(RapportPeriodique::class))->newInstanceWithoutConstructor(),
            $reglages,
            (new \ReflectionClass(ExportPdf::class))->newInstanceWithoutConstructor(),
            new Environment(new ArrayLoader()),
            $this->createStub(UrlGeneratorInterface::class),
            new RequestStack(),
        );

        return new EnvoiRapports($em, new DestinatairesRapports($em, new RoleHierarchy(self::HIERARCHIE), $reglages), $courriel, $reglages, $transport, new NullLogger());
    }

    private function aucunEnvoi(): TransportInterface
    {
        $transport = $this->createMock(TransportInterface::class);
        $transport->expects(self::never())->method('send');

        return $transport;
    }

    public function testUnePeriodeDejaEnvoyeeNeRepartPas(): void
    {
        $p = PeriodeRapport::lire(PeriodeRapport::JOUR, '2026-10-17');
        $deja = new EnvoiRapport(PeriodeRapport::JOUR, '2026-10-17', $p->debut, EnvoiRapport::AUTO, new \DateTimeImmutable('2026-10-17 20:00'));

        self::assertNull($this->envois($deja, [self::utilisateur(1, 'a@b.ml')], [], $this->aucunEnvoi())->envoyer($p, EnvoiRapport::AUTO, new \DateTimeImmutable('2026-10-17 20:15')));
        self::assertSame([], $this->persistes);
    }

    public function testDeuxTachesEnMemeTempsUneSeulePart(): void
    {
        // La seconde tâche bute sur la clé unique « jour:2026-10-17 » avant le premier e-mail.
        $doublon = new UniqueConstraintViolationException(ExceptionPdo::new(new \PDOException('Duplicate entry')), null);
        $envois = $this->envois(null, [self::utilisateur(1, 'a@b.ml')], [], $this->aucunEnvoi(), $doublon);

        self::assertNull($envois->envoyer(PeriodeRapport::lire(PeriodeRapport::JOUR, '2026-10-17'), EnvoiRapport::AUTO, new \DateTimeImmutable('2026-10-17 20:00')));
    }

    public function testChaqueDestinataireASonResultat(): void
    {
        $sansAdresse = self::utilisateur(1, null);
        $desactive = self::utilisateur(2, 'b@succesimprim.com');
        $envois = $this->envois(null, [$sansAdresse, $desactive], [1 => $sansAdresse, 2 => self::utilisateur(2, 'b@succesimprim.com', false)], $this->aucunEnvoi());

        $envoi = $envois->envoyer(PeriodeRapport::lire(PeriodeRapport::SEMAINE, '2026-W42'), EnvoiRapport::AUTO, new \DateTimeImmutable('2026-10-19 07:00'));

        self::assertNotNull($envoi);
        self::assertSame('semaine:2026-W42', $envoi->getCle());
        self::assertSame([$envoi], $this->persistes);
        $lignes = $envoi->getLignes();
        self::assertSame(EnvoiRapport::LIGNE_SANS_ADRESSE, $lignes[0]['statut']);
        self::assertSame(EnvoiRapport::LIGNE_ECHEC, $lignes[1]['statut']);
        self::assertSame('Compte supprimé ou désactivé', $lignes[1]['erreur']);
        self::assertSame('Complète', $lignes[1]['version']);
        self::assertSame(EnvoiRapport::ECHEC, $envoi->getStatut());
        self::assertNotNull($envoi->getTermineLe());
        // Seul le désactivé est à reprendre : sans adresse, rien à renvoyer.
        self::assertSame([1], $envoi->aReprendre());
    }

    public function testUnEssaiNeCompteJamaisCommeLEnvoiDeLaPeriode(): void
    {
        $moi = self::utilisateur(1, null);
        $essai = $this->envois(null, [], [1 => $moi], $this->aucunEnvoi())->essai($moi, PeriodeRapport::lire(PeriodeRapport::JOUR, '2026-10-17'), new \DateTimeImmutable('2026-10-17 12:00'));

        self::assertNull($essai->getCle());
        self::assertTrue($essai->isEssai());
        self::assertSame($moi, $essai->getDemandePar());
        self::assertSame(EnvoiRapport::LIGNE_SANS_ADRESSE, $essai->getLignes()[0]['statut']);
    }

    public function testCleUniqueParPeriode(): void
    {
        $debut = new \DateTimeImmutable('2026-10-01');

        self::assertSame('mois:2026-10', (new EnvoiRapport(PeriodeRapport::MOIS, '2026-10', $debut, EnvoiRapport::AUTO, $debut))->getCle());
        self::assertSame('mois:2026-10', (new EnvoiRapport(PeriodeRapport::MOIS, '2026-10', $debut, EnvoiRapport::COMMANDE, $debut))->getCle());
        self::assertNull((new EnvoiRapport(PeriodeRapport::MOIS, '2026-10', $debut, EnvoiRapport::ESSAI, $debut))->getCle());
    }

    public function testStatutDEnsemble(): void
    {
        $l = static fn (string ...$statuts): array => array_map(static fn (string $s): array => ['statut' => $s], $statuts);

        self::assertSame(EnvoiRapport::ENVOYE, EnvoiRapport::statutDe($l('envoye', 'envoye')));
        self::assertSame(EnvoiRapport::PARTIEL, EnvoiRapport::statutDe($l('envoye', 'sans_adresse')));
        self::assertSame(EnvoiRapport::PARTIEL, EnvoiRapport::statutDe($l('envoye', 'echec')));
        self::assertSame(EnvoiRapport::ECHEC, EnvoiRapport::statutDe($l('echec', 'sans_adresse')));
        self::assertSame(EnvoiRapport::ECHEC, EnvoiRapport::statutDe([]));
    }

    public function testReprisesEspaceesEtLimitees(): void
    {
        $envoi = (new EnvoiRapport(PeriodeRapport::JOUR, '2026-10-17', new \DateTimeImmutable('2026-10-17'), EnvoiRapport::AUTO, new \DateTimeImmutable('2026-10-17 20:00')))
            ->preparer([
                ['utilisateur' => 1, 'nom' => 'A', 'adresse' => 'a@b.ml', 'version' => 'Complète'],
                ['utilisateur' => 2, 'nom' => 'B', 'adresse' => 'b@b.ml', 'version' => 'Complète'],
            ], true)
            ->noter(0, EnvoiRapport::LIGNE_ENVOYE, new \DateTimeImmutable('2026-10-17 20:00'))
            ->noter(1, EnvoiRapport::LIGNE_ECHEC, new \DateTimeImmutable('2026-10-17 20:00'), 'Connexion refusée')
            ->terminer(new \DateTimeImmutable('2026-10-17 20:01'));

        self::assertSame(EnvoiRapport::PARTIEL, $envoi->getStatut());
        self::assertSame([1], $envoi->aReprendre());
        self::assertFalse(EnvoiRapports::aReprendre($envoi, new \DateTimeImmutable('2026-10-17 20:20')));
        self::assertTrue(EnvoiRapports::aReprendre($envoi, new \DateTimeImmutable('2026-10-17 20:31')));

        // Envoi interrompu (resté « en cours ») : repris 30 minutes après son début.
        $interrompu = (new EnvoiRapport(PeriodeRapport::JOUR, '2026-10-18', new \DateTimeImmutable('2026-10-18'), EnvoiRapport::AUTO, new \DateTimeImmutable('2026-10-18 20:00')))
            ->preparer([['utilisateur' => 1, 'nom' => 'A', 'adresse' => 'a@b.ml', 'version' => 'Complète']], false);
        self::assertSame([0], $interrompu->aReprendre());
        self::assertTrue(EnvoiRapports::aReprendre($interrompu, new \DateTimeImmutable('2026-10-18 20:30')));

        for ($i = 0; $i < EnvoiRapports::REPRISES_MAX; ++$i) {
            $envoi->rouvrir()->terminer(new \DateTimeImmutable('2026-10-17 20:01'));
        }
        self::assertSame(1 + EnvoiRapports::REPRISES_MAX, $envoi->getTentatives());
        self::assertFalse(EnvoiRapports::aReprendre($envoi, new \DateTimeImmutable('2026-10-18 09:00')));
    }

    public function testErreurSansSecret(): void
    {
        $erreur = EnvoiRapports::erreurLisible(new \RuntimeException("Connection could not be established with host \"smtps://contact%40succesimprim.com:MotDePasse@mail.succesimprim.com:465\":\n stream_socket_client(): unable to connect password=abc123"));

        self::assertStringNotContainsString('MotDePasse', $erreur);
        self::assertStringNotContainsString('abc123', $erreur);
        self::assertStringContainsString('smtps://***@mail.succesimprim.com', $erreur);
        self::assertSame('erreur inconnue', EnvoiRapports::erreurLisible(new \RuntimeException('  ')));
        self::assertLessThanOrEqual(240, mb_strlen(EnvoiRapports::erreurLisible(new \RuntimeException(str_repeat('é', 500)))));
    }

    public function testSujetEtPieceJointeSansMontant(): void
    {
        $p = PeriodeRapport::lire(PeriodeRapport::JOUR, '2026-10-17');

        self::assertSame('Rapport du jour · samedi 17 octobre 2026 · Succès Imprim', CourrielRapport::sujet($p));
        self::assertSame('[Essai] Rapport du jour · samedi 17 octobre 2026 · Succès Imprim', CourrielRapport::sujet($p, true));
        self::assertSame('rapport-semaine-2026-w42.pdf', CourrielRapport::nomPdf(PeriodeRapport::lire(PeriodeRapport::SEMAINE, '2026-W42')));
    }
}
