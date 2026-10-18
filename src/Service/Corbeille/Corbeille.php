<?php

namespace App\Service\Corbeille;

use App\Entity\Corbeille as EntreeCorbeille;
use App\Entity\JournalActivite;
use App\Entity\User;
use App\Service\Parametres\Parametres;
use App\Service\Synchro\JournalSynchro;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Corbeille de 30 jours (docs/corbeille.md) : liste, détail, restauration
 * et suppression définitive des fiches supprimées.
 *
 * Les copies sont prises par CaptureCorbeille à chaque suppression ; ce
 * service les relit. Restaurer remet les lignes avec leurs identifiants
 * d'origine, dans une seule transaction, et marque les fiches suivies par
 * la synchronisation pour le prochain envoi. Supprimer définitivement
 * retire l'entrée et les fichiers qui n'appartenaient qu'à elle.
 */
final class Corbeille
{
    public const DUREE_PAR_DEFAUT = 30;

    private ?SchemaCorbeille $schema = null;

    /** @var array<string, class-string>|null */
    private ?array $classes = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        /** @var \Closure(): Parametres (différé : la fenêtre de confirmation de chaque page lit seulement la durée) */
        #[AutowireServiceClosure(Parametres::class)] private readonly \Closure $parametres,
        private readonly JournalSynchro $journalSynchro,
        private readonly CaptureCorbeille $capture,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    /** Jours pendant lesquels une fiche supprimée reste dans la corbeille (Réglages › Paramètres). */
    public function duree(): int
    {
        $valeur = ($this->parametres)()->valeur('corbeille', 'duree');

        return max(1, min(365, is_numeric($valeur) ? (int) $valeur : self::DUREE_PAR_DEFAUT));
    }

    /** « 30 jours », « 1 jour ». */
    public function dureeTexte(): string
    {
        $jours = $this->duree();

        return $jours.' jour'.($jours > 1 ? 's' : '');
    }

    public function expireLe(EntreeCorbeille $entree): \DateTimeImmutable
    {
        return $entree->getSupprimeLe()->modify('+'.$this->duree().' days');
    }

    /**
     * Suppression douce : la fiche reste dans sa table mais $champ change au
     * prochain enregistrement ; la restauration lui rendra $valeurRestauree.
     */
    public function masquer(object $entite, string $champ, mixed $valeurRestauree, ?string $libelle = null): void
    {
        $this->capture->masquer($this->em, $entite, $champ, $valeurRestauree, $libelle);
    }

    /**
     * Entrées de la corbeille (une par suppression), les plus récentes
     * d'abord.
     *
     * @param array{type?: string, du?: string, au?: string, par?: string} $filtres
     * @param list<string>|null                                             $tables  tables que la personne peut voir (null : toutes)
     *
     * @return list<array{entree: EntreeCorbeille, elements: int}>
     */
    public function entrees(array $filtres, ?array $tables, int $limite = 200): array
    {
        if ([] === $tables) {
            return [];
        }
        $qb = $this->em->createQueryBuilder()->select('c')->from(EntreeCorbeille::class, 'c')
            ->where('c.racine = true')->orderBy('c.supprimeLe', 'DESC')->addOrderBy('c.id', 'DESC')->setMaxResults($limite);
        if (null !== $tables) {
            $qb->andWhere('c.tableNom IN (:tables)')->setParameter('tables', $tables);
        }
        if ('' !== ($type = (string) ($filtres['type'] ?? ''))) {
            $qb->andWhere('c.tableNom = :type')->setParameter('type', $type);
        }
        if (false !== ($du = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($filtres['du'] ?? '')))) {
            $qb->andWhere('c.supprimeLe >= :du')->setParameter('du', $du);
        }
        if (false !== ($au = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($filtres['au'] ?? '')))) {
            $qb->andWhere('c.supprimeLe < :au')->setParameter('au', $au->modify('+1 day'));
        }
        if ('' !== ($par = trim((string) ($filtres['par'] ?? '')))) {
            $qb->andWhere('c.supprimeParNom = :par')->setParameter('par', $par);
        }
        /** @var list<EntreeCorbeille> $racines */
        $racines = $qb->getQuery()->getResult();
        $nombres = [];
        if ([] !== $racines) {
            foreach ($this->em->createQueryBuilder()->select('c.groupe, COUNT(c.id) AS n')->from(EntreeCorbeille::class, 'c')
                ->where('c.groupe IN (:g)')->setParameter('g', array_map(static fn (EntreeCorbeille $e): string => $e->getGroupe(), $racines))
                ->groupBy('c.groupe')->getQuery()->getArrayResult() as $l) {
                $nombres[$l['groupe']] = (int) $l['n'];
            }
        }

        return array_map(static fn (EntreeCorbeille $e): array => ['entree' => $e, 'elements' => max(0, ($nombres[$e->getGroupe()] ?? 1) - 1)], $racines);
    }

    /**
     * Choix des filtres : types présents et personnes qui ont supprimé.
     *
     * @param list<string>|null $tables
     *
     * @return array{types: array<string, string>, par: list<string>}
     */
    public function choixFiltres(?array $tables): array
    {
        $qb = $this->em->createQueryBuilder()->select('DISTINCT c.tableNom, c.type')->from(EntreeCorbeille::class, 'c')->where('c.racine = true');
        $qp = $this->em->createQueryBuilder()->select('DISTINCT c.supprimeParNom')->from(EntreeCorbeille::class, 'c')->where('c.racine = true')->andWhere('c.supprimeParNom IS NOT NULL');
        if (null !== $tables) {
            $qb->andWhere('c.tableNom IN (:t)')->setParameter('t', [] === $tables ? [''] : $tables);
            $qp->andWhere('c.tableNom IN (:t)')->setParameter('t', [] === $tables ? [''] : $tables);
        }
        $types = [];
        foreach ($qb->getQuery()->getArrayResult() as $l) {
            $types[(string) $l['tableNom']] = (string) $l['type'];
        }
        asort($types);
        $par = array_map(static fn (array $l): string => (string) $l['supprimeParNom'], $qp->getQuery()->getArrayResult());
        sort($par);

        return ['types' => $types, 'par' => $par];
    }

    /**
     * Toutes les lignes d'une entrée, racine comprise, dans l'ordre de
     * remise en place.
     *
     * @return list<EntreeCorbeille>
     */
    public function lignes(EntreeCorbeille $racine): array
    {
        return $this->em->getRepository(EntreeCorbeille::class)->findBy(['groupe' => $racine->getGroupe()], ['ordre' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Remet l'entrée en place. Rien n'est écrit si une ligne est déjà
     * revenue ou si une fiche liée n'existe plus.
     *
     * @throws \DomainException message pour l'écran
     */
    public function restaurer(EntreeCorbeille $racine, ?User $par): string
    {
        $lignes = $this->lignes($racine);
        if ($racine->estMasque()) {
            return $this->restaurerMasque($racine, $par);
        }
        $schema = $this->schema();
        $connexion = $this->em->getConnection();
        $decodees = [];
        $liens = [];
        foreach ($lignes as $ligne) {
            $table = $ligne->getTableNom();
            $donnees = Instantane::decoder($table, $ligne->getDonnees(), fn (string $relatif): string => $this->lireBinaire($relatif));
            $cle = [];
            foreach ($schema->clePrimaire($table) as $colonne) {
                $cle[$colonne] = $donnees[$colonne] ?? null;
            }
            $decodees[] = ['table' => $table, 'cle' => $cle, 'donnees' => $donnees, 'libelle' => $ligne->getLibelle()];
            array_push($liens, ...($ligne->getLiens() ?? []));
        }

        $restauration = new Restauration($connexion, $schema);
        $empechement = $restauration->empechement($decodees, fn (string $table, string $numero): ?string => $this->entreeQuiContient($table, $numero, $racine->getGroupe()));
        if (null !== $empechement) {
            throw new \DomainException($empechement);
        }

        $connexion->beginTransaction();
        try {
            $restauration->inserer($decodees);
            $restauration->rebrancher($liens);
            $this->apresRestauration([...$decodees, ...$liens], $racine, $par, \count($lignes));
            $connexion->executeStatement('DELETE FROM corbeille WHERE groupe = ?', [$racine->getGroupe()]);
            $connexion->commit();
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }
            if ($e instanceof \DomainException) {
                throw $e;
            }
            $this->logger->error('Corbeille : restauration impossible.', ['exception' => $e, 'groupe' => $racine->getGroupe()]);

            throw new \DomainException(\sprintf('%s n\'a pas pu revenir (erreur de la base). Rien n\'a été restauré.', $racine->getLibelle()));
        }
        $this->retirerBinaires($racine->getGroupe());

        $autres = \count($lignes) - 1;

        return \sprintf('%s est de retour%s.', $racine->getLibelle(), $autres > 0 ? \sprintf(', avec %d élément%s lié%s', $autres, $autres > 1 ? 's' : '', $autres > 1 ? 's' : '') : '');
    }

    /** Supprime l'entrée pour de bon, avec les fichiers qui n'appartenaient qu'à elle. */
    public function supprimerDefinitivement(EntreeCorbeille $racine, ?User $par): void
    {
        $lignes = $this->lignes($racine);
        $fichiers = $this->fichiersARetirer($lignes);
        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            $connexion->executeStatement('DELETE FROM corbeille WHERE groupe = ?', [$racine->getGroupe()]);
            $this->journaliser($racine, JournalActivite::ACTION_PURGE, $par, \count($lignes));
            $this->em->flush();
            $connexion->commit();
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $e;
        }
        foreach ($fichiers as $chemin) {
            @unlink($chemin);
        }
        $this->retirerBinaires($racine->getGroupe());
    }

    /**
     * Suppression définitive des entrées plus vieilles que la durée réglée
     * (commande app:corbeille:purger, chaque nuit).
     *
     * @return int entrées supprimées
     */
    public function purger(\DateTimeImmutable $maintenant): int
    {
        $limite = $maintenant->modify('-'.$this->duree().' days');
        $groupes = $this->em->getConnection()->fetchFirstColumn('SELECT groupe FROM corbeille GROUP BY groupe HAVING MIN(supprime_le) < ?', [$limite->format('Y-m-d H:i:s')]);
        $n = 0;
        foreach ($groupes as $groupe) {
            $racine = $this->em->getRepository(EntreeCorbeille::class)->findOneBy(['groupe' => $groupe], ['racine' => 'DESC', 'ordre' => 'ASC']);
            if (null === $racine) {
                continue;
            }
            $this->supprimerDefinitivement($racine, null);
            $this->em->clear();
            ++$n;
        }

        return $n;
    }

    /**
     * Ce que montre le détail : les champs de la fiche (sans les montants
     * pour qui n'est pas la Direction) et les éléments qui reviennent avec
     * elle, regroupés par nature.
     *
     * @param callable(string): bool $peutVoirTable
     *
     * @return array{champs: list<array{libelle: string, valeur: string}>, elements: list<array{type: string, libelles: list<string>, nombre: int}>, caches: int, liens: int, fichiers: int}
     */
    public function detail(EntreeCorbeille $racine, bool $direction, callable $peutVoirTable): array
    {
        $schema = $this->schema();
        $references = [];
        foreach ($schema->referencesDe($racine->getTableNom()) as $fk) {
            if (1 === \count($fk['colonnes'])) {
                $references[$fk['colonnes'][0]] = ['cible' => $fk['cible'], 'colonne' => $fk['colonnes'][0], 'colonneCible' => $fk['colonnesCible'][0]];
            }
        }
        // Type Doctrine de chaque colonne (oui/non, dates), quand la table porte une entité.
        $types = [];
        if (null !== ($classe = $this->classeDe($racine->getTableNom()))) {
            $meta = $this->em->getClassMetadata($classe);
            foreach ($meta->getFieldNames() as $champ) {
                $types[$meta->getColumnName($champ)] = (string) $meta->getTypeOfField($champ);
            }
        }
        $champs = [];
        foreach ($racine->getDonnees() as $colonne => $valeur) {
            $colonne = (string) $colonne;
            if (\in_array($colonne, ['id', 'public_id', 'deleted'], true) || \is_array($valeur) || null === $valeur || '' === $valeur) {
                continue;
            }
            $reference = $references[$colonne] ?? null;
            $libelle = null !== $reference ? TypesCorbeille::nomLien($colonne, $reference['cible']) : TypesCorbeille::nomColonne($colonne);
            if (!$direction && TypesCorbeille::estMontant($colonne)) {
                $champs[] = ['libelle' => $libelle, 'valeur' => 'masqué (montant réservé à la Direction)'];
                continue;
            }
            $texte = null !== $reference
                ? $this->ficheLiee($reference, $valeur, $peutVoirTable)
                : TypesCorbeille::valeurLisible($valeur, $types[$colonne] ?? null, TypesCorbeille::estMontant($colonne));
            $champs[] = ['libelle' => $libelle, 'valeur' => mb_strlen($texte) > 300 ? mb_substr($texte, 0, 299).'…' : $texte];
        }

        $elements = [];
        $caches = 0;
        $liens = \count($racine->getLiens() ?? []);
        $fichiers = 0;
        foreach ($this->lignes($racine) as $ligne) {
            $fichiers += \count(TypesCorbeille::fichiers($ligne->getTableNom(), $ligne->getDonnees()));
            if ($ligne->isRacine()) {
                continue;
            }
            $liens += \count($ligne->getLiens() ?? []);
            // Table de liaison (client visé par une promotion, finition d'un produit) : le lien, nommé
            // d'après la fiche de l'autre côté, qui reste en place ; visible avec le droit de cette fiche.
            $autre = null === $this->classeDe($ligne->getTableNom()) ? $this->autreCote($ligne->getTableNom(), $racine->getTableNom()) : null;
            if (!$peutVoirTable($autre['cible'] ?? $ligne->getTableNom())) {
                ++$caches;
                continue;
            }
            $type = null === $autre ? $ligne->getType() : 'Lien vers une autre fiche';
            $elements[$type] ??= ['type' => $type, 'libelles' => [], 'nombre' => 0];
            ++$elements[$type]['nombre'];
            if (\count($elements[$type]['libelles']) < 5) {
                $elements[$type]['libelles'][] = null === $autre ? $ligne->getLibelle() : $this->libelleAutreCote($autre, $ligne);
            }
        }

        return ['champs' => $champs, 'elements' => array_values($elements), 'caches' => $caches, 'liens' => $liens, 'fichiers' => $fichiers];
    }

    /**
     * Fiche vers laquelle pointe un champ : « Hôtel Lumière (n° 7) » si la
     * personne a le droit de la voir, « n° 7 » sinon, et « n° 7, n'existe
     * plus » quand elle a disparu (la restauration sera refusée).
     *
     * @param array{cible: string, colonne: string, colonneCible: string} $reference
     * @param callable(string): bool                                       $peutVoirTable
     */
    private function ficheLiee(array $reference, mixed $valeur, callable $peutVoirTable): string
    {
        if (!\is_scalar($valeur)) {
            return '';
        }
        $connexion = $this->em->getConnection();
        $fiche = $connexion->fetchAssociative(
            \sprintf('SELECT * FROM %s WHERE %s = ?', $connexion->quoteSingleIdentifier($reference['cible']), $connexion->quoteSingleIdentifier($reference['colonneCible'])),
            [$valeur],
        );
        if (false === $fiche) {
            return \sprintf('n° %s, n\'existe plus', $valeur);
        }
        if (!$peutVoirTable($reference['cible'])) {
            return 'n° '.$valeur;
        }
        $titre = TypesCorbeille::titre($reference['cible'], $fiche);

        return null === $titre ? 'n° '.$valeur : \sprintf('%s (n° %s)', $titre, $valeur);
    }

    /**
     * Clé étrangère d'une table de liaison vers l'autre fiche (pas celle de
     * l'entrée), ou null si elle n'en a pas.
     *
     * @return array{cible: string, colonne: string, colonneCible: string}|null
     */
    private function autreCote(string $table, string $tableRacine): ?array
    {
        foreach ($this->schema()->referencesDe($table) as $fk) {
            if ($fk['cible'] !== $tableRacine && 1 === \count($fk['colonnes'])) {
                return ['cible' => $fk['cible'], 'colonne' => $fk['colonnes'][0], 'colonneCible' => $fk['colonnesCible'][0]];
            }
        }

        return null;
    }

    /**
     * « Client « Hôtel Lumière » » : la fiche de l'autre côté du lien, lue
     * en base ; « client n° 7 » si elle n'y est plus.
     *
     * @param array{cible: string, colonne: string, colonneCible: string} $autre
     */
    private function libelleAutreCote(array $autre, EntreeCorbeille $ligne): string
    {
        $valeur = $ligne->getDonnees()[$autre['colonne']] ?? null;
        if (!\is_scalar($valeur)) {
            return $ligne->getLibelle();
        }
        $connexion = $this->em->getConnection();
        $fiche = $connexion->fetchAssociative(
            \sprintf('SELECT * FROM %s WHERE %s = ?', $connexion->quoteSingleIdentifier($autre['cible']), $connexion->quoteSingleIdentifier($autre['colonneCible'])),
            [$valeur],
        );

        return false === $fiche
            ? \sprintf('%s n° %s', TypesCorbeille::type($autre['cible']), $valeur)
            : TypesCorbeille::libelle($autre['cible'], $fiche, (string) $valeur);
    }

    /**
     * Suppression douce : la fiche est toujours là, on lui rend son champ
     * (par Doctrine, pour que le journal et la synchronisation suivent).
     */
    private function restaurerMasque(EntreeCorbeille $racine, ?User $par): string
    {
        $classe = $this->classeDe($racine->getTableNom());
        $entite = null === $classe ? null : $this->em->find($classe, $this->identifiant($racine));
        if (null === $entite) {
            throw new \DomainException(\sprintf('%s n\'existe plus : rien n\'a été restauré.', $racine->getLibelle()));
        }
        $meta = $this->em->getClassMetadata($classe);
        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            foreach ($racine->getRestauration() ?? [] as $champ => $valeur) {
                if ($meta->hasField((string) $champ)) {
                    $meta->setFieldValue($entite, (string) $champ, $valeur);
                }
            }
            $this->journaliser($racine, JournalActivite::ACTION_RESTAURATION, $par, 1);
            $this->em->flush();
            $connexion->executeStatement('DELETE FROM corbeille WHERE groupe = ?', [$racine->getGroupe()]);
            $connexion->commit();
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }

            throw $e;
        }

        return \sprintf('%s est de retour.', $racine->getLibelle());
    }

    /**
     * Synchronisation et journal, dans la transaction de la restauration.
     * Les fiches rebranchées (un paiement retrouve sa facture, une page son
     * image) repartent aussi : de l'autre côté, elles ont perdu ce lien.
     *
     * @param list<array{table: string, cle: array<string, mixed>, ...}> $lignes lignes remises et fiches rebranchées
     */
    private function apresRestauration(array $lignes, EntreeCorbeille $racine, ?User $par, int $nombre): void
    {
        $entites = [];
        foreach ($lignes as $ligne) {
            $classe = $this->classeDe((string) ($ligne['table'] ?? ''));
            $cle = (array) ($ligne['cle'] ?? []);
            if (null === $classe || 1 !== \count($cle)) {
                continue;
            }
            $entite = $this->em->find($classe, reset($cle));
            if (null !== $entite) {
                $entites[] = $entite;
            }
        }
        $this->journalSynchro->noterRestauration($this->em, $entites);
        $this->journaliser($racine, JournalActivite::ACTION_RESTAURATION, $par, $nombre);
        $this->em->flush();
    }

    private function journaliser(EntreeCorbeille $racine, string $action, ?User $par, int $nombre): void
    {
        $classe = $this->classeDe($racine->getTableNom());
        $journal = (new JournalActivite())
            ->setEntite(null === $classe ? $racine->getTableNom() : substr((string) strrchr('\\'.$classe, '\\'), 1))
            ->setEntiteId(is_numeric($racine->getLigneId()) ? (int) $racine->getLigneId() : 0)
            ->setAction($action);
        $resume = ['corbeille' => $racine->getLibelle(), 'elements' => $nombre, 'supprime_le' => $racine->getSupprimeLe()->format(\DATE_ATOM)];
        JournalActivite::ACTION_PURGE === $action ? $journal->setDonneesAvant($resume) : $journal->setDonneesApres($resume);
        if (null !== $par?->getId()) {
            $journal->setUtilisateur($this->em->getReference(User::class, $par->getId()));
        }
        $this->em->persist($journal);
    }

    /**
     * Fichiers sur le disque qui ne servent qu'à cette entrée : ni une fiche
     * en place ni une autre entrée de la corbeille ne les utilise.
     *
     * @param list<EntreeCorbeille> $lignes
     *
     * @return list<string> chemins absolus
     */
    private function fichiersARetirer(array $lignes): array
    {
        $connexion = $this->em->getConnection();
        $chemins = [];
        foreach ($lignes as $ligne) {
            $table = $ligne->getTableNom();
            $donnees = $ligne->getDonnees();
            // Fiche cachée : ses fichiers ne partent que si elle l'est toujours.
            if ($ligne->estMasque() && !$this->toujoursMasquee($ligne)) {
                continue;
            }
            foreach (TypesCorbeille::fichiers($table, $donnees) as $fichier) {
                $colonne = $fichier['colonne'];
                if (null !== $colonne && $this->schema()->existe($table)) {
                    $q = $connexion->quoteSingleIdentifier(...);
                    $parametres = [$donnees[$colonne]];
                    $condition = $q($colonne).' = ?';
                    // La ligne cachée elle-même ne compte pas.
                    if ($ligne->estMasque()) {
                        $condition .= ' AND NOT ('.implode(' AND ', array_map(static fn (string $c): string => $q($c).' = ?', array_keys($this->cleDe($ligne)))).')';
                        array_push($parametres, ...array_values($this->cleDe($ligne)));
                    }
                    if (false !== $connexion->fetchOne(\sprintf('SELECT 1 FROM %s WHERE %s', $q($table), $condition), $parametres)) {
                        continue;
                    }
                    if ($this->autreEntreeUtilise($ligne, $colonne, $donnees[$colonne])) {
                        continue;
                    }
                }
                foreach (glob($this->projet.'/'.$fichier['chemin']) ?: [] as $chemin) {
                    $reel = realpath($chemin);
                    if (false !== $reel && str_starts_with($reel, $this->projet.'/var/') && is_file($reel)) {
                        $chemins[] = $reel;
                    }
                }
            }
        }

        return array_values(array_unique($chemins));
    }

    private function toujoursMasquee(EntreeCorbeille $ligne): bool
    {
        $classe = $this->classeDe($ligne->getTableNom());
        $entite = null === $classe ? null : $this->em->find($classe, $this->identifiant($ligne));
        if (null === $entite) {
            return true;
        }
        $meta = $this->em->getClassMetadata($classe);
        foreach ($ligne->getRestauration() ?? [] as $champ => $valeur) {
            if ($meta->hasField((string) $champ) && $meta->getFieldValue($entite, (string) $champ) === $valeur) {
                return false;
            }
        }

        return true;
    }

    private function autreEntreeUtilise(EntreeCorbeille $ligne, string $colonne, mixed $valeur): bool
    {
        foreach ($this->em->getRepository(EntreeCorbeille::class)->findBy(['tableNom' => $ligne->getTableNom()]) as $autre) {
            if ($autre->getGroupe() !== $ligne->getGroupe() && ($autre->getDonnees()[$colonne] ?? null) === $valeur) {
                return true;
            }
        }

        return false;
    }

    /** Entrée de la corbeille (autre que $sauf) qui contient cette ligne : son libellé, ou null. */
    private function entreeQuiContient(string $table, string $numero, string $sauf): ?string
    {
        $ligne = $this->em->createQueryBuilder()->select('c')->from(EntreeCorbeille::class, 'c')
            ->where('c.tableNom = :t')->andWhere('c.ligneId = :n')->andWhere('c.groupe <> :g')
            ->setParameter('t', $table)->setParameter('n', $numero)->setParameter('g', $sauf)
            ->setMaxResults(1)->getQuery()->getOneOrNullResult();
        if (!$ligne instanceof EntreeCorbeille) {
            return null;
        }
        $racine = $this->em->getRepository(EntreeCorbeille::class)->findOneBy(['groupe' => $ligne->getGroupe(), 'racine' => true]);

        return ($racine ?? $ligne)->getLibelle();
    }

    /** @return array<string, mixed> */
    private function cleDe(EntreeCorbeille $ligne): array
    {
        $cle = [];
        foreach ($this->schema()->clePrimaire($ligne->getTableNom()) as $colonne) {
            $cle[$colonne] = $ligne->getDonnees()[$colonne] ?? null;
        }

        return $cle;
    }

    private function identifiant(EntreeCorbeille $ligne): mixed
    {
        $cle = $this->cleDe($ligne);

        return 1 === \count($cle) ? reset($cle) : $cle;
    }

    /** @return class-string|null */
    private function classeDe(string $table): ?string
    {
        if (null === $this->classes) {
            $this->classes = [];
            foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
                if (!$meta->isMappedSuperclass && !$meta->isEmbeddedClass) {
                    $this->classes[$meta->getTableName()] = $meta->getName();
                }
            }
        }

        return $this->classes[$table] ?? null;
    }

    private function lireBinaire(string $relatif): string
    {
        $chemin = CaptureCorbeille::dossier($this->projet).'/'.$relatif;
        if (1 !== preg_match('#^[0-9a-f-]{36}/[0-9a-f]{64}\.bin$#', $relatif) || !is_file($chemin)) {
            throw new \DomainException('Une partie du contenu gardé dans la corbeille a disparu du disque : restauration impossible.');
        }

        return (string) file_get_contents($chemin);
    }

    private function retirerBinaires(string $groupe): void
    {
        if (1 !== preg_match('/^[0-9a-f-]{36}$/', $groupe)) {
            return;
        }
        $dossier = CaptureCorbeille::dossier($this->projet).'/'.$groupe;
        foreach (glob($dossier.'/*.bin') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dossier);
    }

    private function schema(): SchemaCorbeille
    {
        return $this->schema ??= new SchemaCorbeille($this->em->getConnection());
    }
}
