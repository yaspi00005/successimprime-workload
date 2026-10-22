<?php

namespace App\Service\Corbeille;

use App\Entity\CompteClient;
use App\Entity\Corbeille;
use App\Entity\User;
use App\Service\Statistiques\Objectifs;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Met dans la corbeille ce que chaque enregistrement supprime
 * (docs/corbeille.md), sur l'ERP local comme sur le site en ligne.
 *
 * - onFlush : les lignes sont encore en base ; Instantane les copie, avec
 *   ce que la base supprimera avec elles.
 * - postFlush : l'enregistrement a réussi ; les copies sont écrites dans la
 *   table corbeille (par DBAL : rien à suivre pour Doctrine). Si
 *   l'enregistrement échoue, postFlush n'arrive pas et rien n'est écrit.
 *
 * Les enregistrements peuvent s'imbriquer (un postFlush qui enregistre à
 * son tour) : chaque onFlush empile sa copie, chaque postFlush dépile la
 * sienne.
 *
 * Les suppressions « douces » (une fiche cachée sans quitter sa table :
 * devis, commande, adresse ou fichier retiré par le client) passent par
 * masquer(), appelé avant l'enregistrement.
 *
 * Une erreur de la corbeille n'empêche jamais la suppression : elle est
 * notée dans le journal de l'application.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class CaptureCorbeille
{
    /** @var list<list<list<array<string, mixed>>>> */
    private array $pile = [];

    /** @var array<int, array{entite: object, table: string, cle: array<string, mixed>, champ: string, valeur: mixed, donnees: array<string, mixed>, libelle: ?string, par: ?string}> */
    private array $masquages = [];

    /** @var array<string, true>|null */
    private ?array $tablesEntites = null;

    private ?SchemaCorbeille $schema = null;

    public function __construct(
        private readonly Security $security,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    /**
     * Suppression douce : $champ prendra une autre valeur au prochain
     * enregistrement ; la restauration lui rendra $valeurRestauree. $par :
     * qui a supprimé, quand ce n'est pas la personne connectée (geste du
     * client reçu par la synchronisation).
     */
    public function masquer(EntityManagerInterface $em, object $entite, string $champ, mixed $valeurRestauree, ?string $libelle = null, ?string $par = null): void
    {
        try {
            $meta = $em->getClassMetadata($entite::class);
            $table = $meta->getTableName();
            $cle = [];
            foreach ($meta->getIdentifierValues($entite) as $champId => $valeur) {
                $cle[$meta->getColumnName($champId)] = $valeur;
            }
            $connexion = $em->getConnection();
            $donnees = [] === $cle ? false : $connexion->fetchAssociative(
                \sprintf('SELECT * FROM %s WHERE %s', $connexion->quoteSingleIdentifier($table), implode(' AND ', array_map(static fn (string $c): string => $connexion->quoteSingleIdentifier($c).' = ?', array_keys($cle)))),
                array_values($cle),
            );
            if (false === $donnees) {
                return;
            }
            // Déjà cachée (formulaire envoyé deux fois, onglet resté ouvert) : la première entrée suffit.
            $colonne = $meta->getColumnName($champ);
            if (\array_key_exists($colonne, $donnees) && null !== $donnees[$colonne]
                && (string) $donnees[$colonne] !== (string) (\is_bool($valeurRestauree) ? (int) $valeurRestauree : $valeurRestauree)) {
                return;
            }
            $this->masquages[spl_object_id($entite)] = ['entite' => $entite, 'table' => $table, 'cle' => $cle, 'champ' => $champ, 'valeur' => $valeurRestauree, 'donnees' => $donnees, 'libelle' => $libelle, 'par' => $par];
        } catch (\Throwable $e) {
            $this->logger->error('Corbeille : copie impossible avant la suppression.', ['exception' => $e]);
        }
    }

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $supprimees = [];
        foreach ($uow->getScheduledEntityDeletions() as $entite) {
            $meta = $em->getClassMetadata($entite::class);
            $table = $meta->getTableName();
            if (TypesCorbeille::exclue($table)) {
                continue;
            }
            $cle = [];
            foreach ($uow->getEntityIdentifier($entite) as $champ => $valeur) {
                $colonne = $meta->hasAssociation($champ) ? $meta->getSingleAssociationJoinColumnName($champ) : $meta->getColumnName($champ);
                $cle[$colonne] = \is_object($valeur) ? ($em->getUnitOfWork()->getSingleIdentifierValue($valeur)) : $valeur;
            }
            $supprimees[] = ['table' => $table, 'cle' => $cle];
        }

        $groupes = [];
        if ([] !== $supprimees) {
            try {
                $groupes = (new Instantane($em->getConnection(), $this->schema($em), $this->tablesEntites($em)))->capturer($supprimees);
            } catch (\Throwable $e) {
                $this->logger->error('Corbeille : copie impossible avant la suppression.', ['exception' => $e]);
            }
        }
        $this->pile[] = $groupes;
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $groupes = array_pop($this->pile) ?? [];
        $masquages = array_filter($this->masquages, static function (array $m) use ($args): bool {
            // Seulement si le champ a bien changé (l'enregistrement qui le cachait a réussi).
            $meta = $args->getObjectManager()->getClassMetadata($m['entite']::class);

            return $meta->getFieldValue($m['entite'], $m['champ']) !== $m['valeur'];
        });
        $this->masquages = array_diff_key($this->masquages, $masquages);
        if ([] === $groupes && [] === $masquages) {
            return;
        }

        $em = $args->getObjectManager();
        try {
            [$parId, $parNom] = $this->auteur();
            $le = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
            foreach ($groupes as $groupe) {
                $racine = array_values(array_filter($groupe, static fn (array $l): bool => $l['racine']))[0] ?? null;
                if (null !== $racine && TypesCorbeille::ignoree($racine['table'], $racine['donnees'])) {
                    continue;
                }
                $id = Uuid::v7()->toRfc4122();
                foreach ($groupe as $ordre => $ligne) {
                    $this->ecrire($em, $id, $ordre, $ligne['racine'], $ligne['table'], $ligne['cle'], $ligne['donnees'], $ligne['liens'], Corbeille::MODE_LIGNE, null, $le, $parId, $parNom);
                }
            }
            foreach ($masquages as $m) {
                $this->ecrire($em, Uuid::v7()->toRfc4122(), 0, true, $m['table'], $m['cle'], $m['donnees'], [], Corbeille::MODE_MASQUE, [$m['champ'] => $m['valeur']], $le, null === $m['par'] ? $parId : null, $m['par'] ?? $parNom, $m['libelle']);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Corbeille : copie impossible après la suppression.', ['exception' => $e]);
        }
    }

    /**
     * @param array<string, mixed>        $cle
     * @param array<string, mixed>        $donnees
     * @param list<array<string, mixed>>  $liens
     * @param array<string, mixed>|null   $restauration
     */
    private function ecrire(EntityManagerInterface $em, string $groupe, int $ordre, bool $racine, string $table, array $cle, array $donnees, array $liens, string $mode, ?array $restauration, string $le, ?int $parId, ?string $parNom, ?string $libelle = null): void
    {
        $lisible = Instantane::cleLisible($cle);
        $em->getConnection()->insert('corbeille', [
            'groupe' => $groupe,
            'racine' => $racine ? 1 : 0,
            'ordre' => $ordre,
            'table_nom' => $table,
            'ligne_id' => mb_substr($lisible, 0, 190),
            'type' => mb_substr(TypesCorbeille::type($table), 0, 80),
            'libelle' => mb_substr($libelle ?? TypesCorbeille::libelle($table, $donnees, $lisible), 0, 255),
            'donnees' => json_encode(Instantane::encoder($table, $donnees, fn (string $octets): string => $this->rangerBinaire($groupe, $octets)), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION),
            'liens' => [] === $liens ? null : json_encode($liens, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'mode' => $mode,
            'restauration' => null === $restauration ? null : json_encode($restauration, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
            'supprime_le' => $le,
            'supprime_par_id' => $parId,
            'supprime_par_nom' => $parNom,
        ]);
    }

    /** Contenu binaire lourd (image d'origine) : à part, sous var/corbeille. */
    private function rangerBinaire(string $groupe, string $octets): string
    {
        $relatif = $groupe.'/'.hash('sha256', $octets).'.bin';
        $chemin = self::dossier($this->projet).'/'.$relatif;
        if (!is_dir(\dirname($chemin)) && !@mkdir(\dirname($chemin), 0775, true) && !is_dir(\dirname($chemin))) {
            throw new \RuntimeException('Dossier de la corbeille impossible à créer.');
        }
        if (false === file_put_contents($chemin, $octets, \LOCK_EX)) {
            throw new \RuntimeException('Contenu de la corbeille impossible à écrire.');
        }

        return $relatif;
    }

    public static function dossier(string $projet): string
    {
        return $projet.'/var/corbeille';
    }

    /** @return array{0: ?int, 1: ?string} */
    private function auteur(): array
    {
        $qui = $this->security->getUser();

        return match (true) {
            $qui instanceof User => [$qui->getId(), mb_substr(Objectifs::nomAgent($qui), 0, 190)],
            $qui instanceof CompteClient => [null, mb_substr('Espace client : '.$qui->getNomAffiche(), 0, 190)],
            default => [null, null],
        };
    }

    private function schema(EntityManagerInterface $em): SchemaCorbeille
    {
        return $this->schema ??= new SchemaCorbeille($em->getConnection());
    }

    /** @return array<string, true> */
    private function tablesEntites(EntityManagerInterface $em): array
    {
        if (null === $this->tablesEntites) {
            $this->tablesEntites = [];
            foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
                $this->tablesEntites[$meta->getTableName()] = true;
            }
        }

        return $this->tablesEntites;
    }
}
