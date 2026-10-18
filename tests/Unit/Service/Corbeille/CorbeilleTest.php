<?php

namespace App\Tests\Unit\Service\Corbeille;

use App\Entity\Corbeille as EntreeCorbeille;
use App\Entity\JourFerie;
use App\Entity\JournalActivite;
use App\Entity\ModeleMessage;
use App\Entity\User;
use App\Service\Corbeille\CaptureCorbeille;
use App\Service\Corbeille\Corbeille;
use App\Service\Synchro\JournalSynchro;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Repository\RepositoryFactory;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Filesystem\Filesystem;

/**
 * La corbeille de bout en bout, avec le vrai Doctrine (SQLite en mémoire) :
 * une fiche supprimée par l'application entre dans la corbeille, revient
 * avec le même numéro, et la purge de la nuit efface ce qui a dépassé la
 * durée réglée, fichiers compris. Le journal d'activité note chaque geste.
 */
final class CorbeilleTest extends TestCase
{
    private const RACINE = __DIR__.'/../../../..';

    private EntityManagerInterface $em;
    private Corbeille $corbeille;
    private string $projet;
    private object $parametres;

    protected function setUp(): void
    {
        if (!Type::hasType('uuid')) {
            Type::addType('uuid', UuidType::class);
        }
        $config = ORMSetup::createAttributeMetadataConfiguration([self::RACINE.'/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER, true));
        $config->enableNativeLazyObjects(true);
        // Les dépôts de l'application attendent le registre de Symfony : ici, le dépôt de base suffit.
        $config->setRepositoryFactory(new class implements RepositoryFactory {
            /** @var array<string, EntityRepository<object>> */
            private array $depots = [];

            public function getRepository(EntityManagerInterface $entityManager, string $entityName): EntityRepository
            {
                return $this->depots[$entityName] ??= new EntityRepository($entityManager, $entityManager->getClassMetadata($entityName));
            }
        });
        $connexion = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config);
        $this->em = new EntityManager($connexion, $config);
        (new SchemaTool($this->em))->createSchema(array_map($this->em->getClassMetadata(...), [EntreeCorbeille::class, JournalActivite::class, User::class, JourFerie::class]));

        $this->projet = sys_get_temp_dir().'/corbeille-essai-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->projet.'/var');
        $this->projet = (string) realpath($this->projet);

        $capture = new CaptureCorbeille($this->createStub(Security::class), new NullLogger(), $this->projet);
        $this->em->getEventManager()->addEventListener([Events::onFlush, Events::postFlush], $capture);
        $this->parametres = new class {
            public mixed $duree = 30;

            public function valeur(string $tuile, string $cle): mixed
            {
                return 'corbeille' === $tuile && 'duree' === $cle ? $this->duree : null;
            }
        };
        $parametres = $this->parametres;
        $this->corbeille = new Corbeille($this->em, static fn (): object => $parametres, new JournalSynchro(false), $capture, new NullLogger(), $this->projet);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projet);
    }

    public function testUneFicheSupprimeeRevientAvecLeMemeNumero(): void
    {
        $id = $this->supprimerUnFerie();

        $entrees = $this->corbeille->entrees([], null);
        self::assertCount(1, $entrees);
        $entree = $entrees[0]['entree'];
        self::assertSame('Jour férié', $entree->getType());
        self::assertStringContainsString('Fête de l\'indépendance', $entree->getLibelle());
        self::assertSame((string) $id, $entree->getLigneId());
        self::assertSame([], $this->corbeille->entrees([], []), 'rien pour qui ne peut voir aucun type');
        self::assertSame([], $this->corbeille->entrees(['type' => 'clients'], null), 'filtre par type');

        $message = $this->corbeille->restaurer($entree, null);

        self::assertSame($entree->getLibelle().' est de retour.', $message);
        $this->em->clear();
        $ferie = $this->em->find(JourFerie::class, $id);
        self::assertInstanceOf(JourFerie::class, $ferie, 'même numéro qu\'avant');
        self::assertSame('2026-09-22', $ferie->getJour()->format('Y-m-d'));
        self::assertSame(0, $this->compter('corbeille'), 'l\'entrée quitte la corbeille');
        self::assertSame([JournalActivite::ACTION_RESTAURATION], $this->actionsDuJournal());
    }

    public function testRestaurerDeuxFoisEstRefuse(): void
    {
        $id = $this->supprimerUnFerie();
        $entree = $this->corbeille->entrees([], null)[0]['entree'];
        // Quelqu'un a recréé la fiche entre-temps sous le même numéro.
        $this->em->getConnection()->insert('jour_ferie', ['id' => $id, 'jour' => '2026-09-22', 'libelle' => 'Refait à la main']);

        try {
            $this->corbeille->restaurer($entree, null);
            self::fail('la restauration aurait dû être refusée');
        } catch (\DomainException $e) {
            self::assertStringContainsString('est déjà de nouveau en place', $e->getMessage());
        }
        self::assertSame(1, $this->compter('corbeille'), 'l\'entrée reste dans la corbeille');
        self::assertSame([], $this->actionsDuJournal());
    }

    public function testLaPurgeEffaceCeQuiADepasseLaDuree(): void
    {
        $this->supprimerUnFerie();
        $recent = $this->supprimerUnFerie('2026-05-25', 'Fête de l\'Afrique');
        $this->em->getConnection()->executeStatement('UPDATE corbeille SET supprime_le = ? WHERE ligne_id <> ?', ['2026-09-01 08:00:00', (string) $recent]);

        $n = $this->corbeille->purger(new \DateTimeImmutable('2026-10-18 02:30:00'));

        self::assertSame(1, $n, 'seule l\'entrée de plus de 30 jours part');
        self::assertSame([(string) $recent], $this->em->getConnection()->fetchFirstColumn('SELECT ligne_id FROM corbeille'));
        self::assertSame([JournalActivite::ACTION_PURGE], $this->actionsDuJournal());
        $journal = $this->em->getConnection()->fetchAssociative('SELECT entite, donnees_avant, utilisateur_id FROM journal_activite');
        self::assertSame('JourFerie', $journal['entite']);
        self::assertNull($journal['utilisateur_id'], 'tâche de la nuit');
        $resume = json_decode((string) $journal['donnees_avant'], true);
        self::assertSame('Jour férié « Fête de l\'indépendance »', $resume['corbeille']);
        self::assertSame(1, $resume['elements']);

        self::assertSame(0, $this->corbeille->purger(new \DateTimeImmutable('2026-10-18 02:30:00')), 'rien de plus le lendemain');
    }

    public function testLaPurgeRetireLeFichierQuiNAppartenaitQuAElle(): void
    {
        $uuid = '0192aabb-ccdd-7eff-8011-2233445566ff';
        $original = $this->projet.'/var/uploads/site/originaux/'.$uuid.'.jpg';
        $autre = $this->projet.'/var/uploads/site/originaux/0192aabb-ccdd-7eff-8011-000000000000.jpg';
        (new Filesystem())->dumpFile($original, 'image');
        (new Filesystem())->dumpFile($autre, 'autre image');
        $this->em->getConnection()->insert('corbeille', [
            'groupe' => '0192aabb-0000-7000-8000-000000000001', 'racine' => 1, 'ordre' => 0, 'table_nom' => 'site_image', 'ligne_id' => '12',
            'type' => 'Image du site', 'libelle' => 'Image « vitrine »', 'mode' => EntreeCorbeille::MODE_LIGNE,
            'donnees' => json_encode(['id' => 12, 'public_id' => ['__base64' => base64_encode((string) hex2bin(str_replace('-', '', $uuid)))]]),
            'supprime_le' => '2026-08-01 10:00:00',
        ]);

        self::assertSame(1, $this->corbeille->purger(new \DateTimeImmutable('2026-10-18')));

        self::assertFileDoesNotExist($original);
        self::assertFileExists($autre, 'seul le fichier de l\'entrée part');
    }

    public function testLaDureeSuitLeReglage(): void
    {
        self::assertSame(30, $this->corbeille->duree());
        self::assertSame('30 jours', $this->corbeille->dureeTexte());

        $this->parametres->duree = '7';
        self::assertSame('7 jours', $this->corbeille->dureeTexte());
        $this->supprimerUnFerie();
        $this->em->getConnection()->executeStatement('UPDATE corbeille SET supprime_le = ?', ['2026-10-10 09:00:00']);
        self::assertSame('2026-10-17', $this->corbeille->expireLe($this->corbeille->entrees([], null)[0]['entree'])->format('Y-m-d'));
        self::assertSame(1, $this->corbeille->purger(new \DateTimeImmutable('2026-10-18')), 'huit jours : au-delà de 7');

        $this->parametres->duree = 0;
        self::assertSame('1 jour', $this->corbeille->dureeTexte(), 'jamais moins d\'un jour');
        $this->parametres->duree = 9999;
        self::assertSame(365, $this->corbeille->duree());
        $this->parametres->duree = null;
        self::assertSame(Corbeille::DUREE_PAR_DEFAUT, $this->corbeille->duree());
    }

    public function testUnLienEstNommeDApresLaFicheDeLAutreCote(): void
    {
        // Table de liaison sans entité, comme promotion_client : le lien part avec la fiche, l'autre côté reste.
        $c = $this->em->getConnection();
        $c->executeStatement('CREATE TABLE equipe_essai (id INTEGER PRIMARY KEY, nom VARCHAR(60))');
        $c->executeStatement('CREATE TABLE ferie_equipe (jour_ferie_id INTEGER NOT NULL REFERENCES jour_ferie(id) ON DELETE CASCADE, equipe_id INTEGER NOT NULL REFERENCES equipe_essai(id), PRIMARY KEY (jour_ferie_id, equipe_id))');
        $c->insert('equipe_essai', ['id' => 4, 'nom' => 'Atelier']);
        $ferie = new JourFerie(new \DateTimeImmutable('2026-09-22'), 'Fête de l\'indépendance');
        $this->em->persist($ferie);
        $this->em->flush();
        $c->insert('ferie_equipe', ['jour_ferie_id' => $ferie->getId(), 'equipe_id' => 4]);
        $c->insert('ferie_equipe', ['jour_ferie_id' => $ferie->getId(), 'equipe_id' => 99]);
        $this->em->remove($ferie);
        $this->em->flush();
        $entree = $this->corbeille->entrees([], null)[0]['entree'];

        $detail = $this->corbeille->detail($entree, true, static fn (string $table): bool => true);

        self::assertSame([['type' => 'Lien vers une autre fiche', 'libelles' => ['Equipe essai « Atelier »', 'Equipe essai n° 99'], 'nombre' => 2]], $detail['elements']);
        self::assertSame(0, $detail['caches']);

        $cache = $this->corbeille->detail($entree, false, static fn (string $table): bool => 'equipe_essai' !== $table);
        self::assertSame([], $cache['elements'], 'sans le droit de l\'autre fiche, son nom n\'apparaît pas');
        self::assertSame(2, $cache['caches']);
    }

    public function testUneSuppressionQuiEchoueNeLaisseRienDansLaCorbeille(): void
    {
        $ferie = new JourFerie(new \DateTimeImmutable('2026-12-25'), 'Noël');
        $this->em->persist($ferie);
        $this->em->flush();
        $this->em->remove($ferie);
        $this->em->getConnection()->executeStatement('CREATE TRIGGER refus BEFORE DELETE ON jour_ferie BEGIN SELECT RAISE(ABORT, \'refusé\'); END');

        try {
            $this->em->flush();
            self::fail('la suppression aurait dû échouer');
        } catch (\Throwable) {
        }

        self::assertSame(0, $this->compter('corbeille'));
    }

    public function testUneFicheCacheeDeuxFoisNOuvreQuUneEntree(): void
    {
        // Suppression douce (devis, commande, adresse) : le formulaire renvoyé une seconde fois ne double pas l'entrée.
        (new SchemaTool($this->em))->createSchema([$this->em->getClassMetadata(ModeleMessage::class)]);
        $modele = (new ModeleMessage())->setNom('Relance')->setContenu('Bonjour');
        $this->em->persist($modele);
        $this->em->flush();
        foreach ([1, 2] as $fois) {
            $this->corbeille->masquer($modele, 'actif', true, 'Modèle « Relance »');
            $modele->setActif(false);
            $this->em->flush();
        }

        self::assertSame(1, $this->compter('corbeille'));
        $entree = $this->corbeille->entrees([], null)[0]['entree'];
        self::assertTrue($entree->estMasque());
        self::assertSame('Modèle « Relance » est de retour.', $this->corbeille->restaurer($entree, null));
        $this->em->clear();
        self::assertTrue($this->em->find(ModeleMessage::class, $modele->getId())?->isActif());
    }

    private function supprimerUnFerie(string $jour = '2026-09-22', string $libelle = 'Fête de l\'indépendance'): int
    {
        $ferie = new JourFerie(new \DateTimeImmutable($jour), $libelle);
        $this->em->persist($ferie);
        $this->em->flush();
        $id = (int) $ferie->getId();
        $this->em->remove($ferie);
        $this->em->flush();
        $this->em->clear();
        self::assertNull($this->em->find(JourFerie::class, $id));

        return $id;
    }

    private function compter(string $table): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM '.$table);
    }

    /** @return list<string> */
    private function actionsDuJournal(): array
    {
        return array_map('strval', $this->em->getConnection()->fetchFirstColumn('SELECT action FROM journal_activite ORDER BY id'));
    }
}
