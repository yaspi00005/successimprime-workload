<?php

namespace App\Service\Synchro;

use App\Entity\SynchroEchange;
use App\Entity\SynchroEtat;
use App\Entity\SynchroJournal;
use App\Entity\SynchroRecu;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Cœur de la synchronisation, commun aux deux côtés : file d'envoi,
 * application des données reçues (une seule fois chacune, dans l'ordre),
 * état et historique pour l'écran Synchronisation.
 */
final class Synchro
{
    public const LOT = 100;

    /** Pas de synchro réussie depuis ce délai : alerte. */
    public const ALERTE_MINUTES = 15;

    public const RESULTAT_OK = 'ok';
    public const RESULTAT_CONFLIT = 'conflit';
    public const RESULTAT_DEJA = 'deja';
    public const RESULTAT_IGNORE = 'ignore';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
        private readonly Serialiseur $serialiseur,
        private readonly JournalSynchro $journal,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
        #[Autowire('%env(SYNC_URL)%')] private readonly string $url,
        #[Autowire('%env(SYNC_CLE)%')] private readonly string $cle,
    ) {
    }

    public function estEnLigne(): bool
    {
        return $this->enLigne;
    }

    /** Côté local : adresse du site et clé réglées. Côté site : clé réglée. */
    public function estConfiguree(): bool
    {
        return Signature::cleValable($this->cle) && ($this->enLigne || str_starts_with($this->url, 'https://') || str_starts_with($this->url, 'http://'));
    }

    public function url(): string
    {
        return rtrim($this->url, '/');
    }

    public function cle(): string
    {
        return $this->cle;
    }

    /* ---------- Envoi ---------- */

    /**
     * Prochains éléments à envoyer, dans l'ordre, avec leur contenu actuel.
     * Les éléments bloqués ou écartés ne partent plus.
     *
     * @return list<array{id: int, evenement: string, type: string, publicId: string, operation: string, le: string, donnees: ?array}>
     */
    public function lot(int $apres = 0, int $max = self::LOT): array
    {
        /** @var list<SynchroJournal> $lignes */
        $lignes = $this->em->createQueryBuilder()
            ->select('j')->from(SynchroJournal::class, 'j')
            ->andWhere('j.envoyeLe IS NULL')
            ->andWhere('j.ignore = false')
            ->andWhere('j.tentatives < :max')->setParameter('max', SynchroJournal::TENTATIVES_MAX)
            ->andWhere('j.id > :apres')->setParameter('apres', $apres)
            ->orderBy('j.id', 'ASC')
            ->setMaxResults($max)
            ->getQuery()->getResult();

        $contenus = [];
        $lot = [];
        foreach ($lignes as $l) {
            $cle = $l->getType().$l->getPublicId();
            if (!\array_key_exists($cle, $contenus)) {
                $entite = $l->getOperation() === SynchroJournal::MAJ ? $this->serialiseur->trouver($l->getType(), $l->getPublicId()) : null;
                $contenus[$cle] = $entite === null ? null : $this->serialiseur->versTableau($l->getType(), $entite);
            }
            $lot[] = [
                'id' => (int) $l->getId(),
                'evenement' => $l->getEvenement(),
                'type' => $l->getType(),
                'publicId' => $l->getPublicId(),
                'operation' => $l->getOperation(),
                'le' => $l->getCreeLe()->format(\DATE_ATOM),
                'donnees' => $contenus[$cle],
            ];
        }
        $this->em->clear();

        return $lot;
    }

    /** @param list<int> $ids */
    public function marquerEnvoyes(array $ids, ?\DateTimeImmutable $le = null): void
    {
        if ($ids === []) {
            return;
        }
        $this->em->createQueryBuilder()
            ->update(SynchroJournal::class, 'j')
            ->set('j.envoyeLe', ':le')->set('j.erreur', 'NULL')
            ->where('j.id IN (:ids)')
            ->setParameter('le', $le ?? new \DateTimeImmutable())
            ->setParameter('ids', $ids)
            ->getQuery()->execute();
    }

    /** Marque comme envoyé tout ce qui va jusqu'à $id (accusé de réception de l'agent). */
    public function marquerEnvoyesJusqua(int $id): int
    {
        return $this->em->createQueryBuilder()
            ->update(SynchroJournal::class, 'j')
            ->set('j.envoyeLe', ':le')->set('j.erreur', 'NULL')
            ->where('j.id <= :id')->andWhere('j.envoyeLe IS NULL')
            ->setParameter('le', new \DateTimeImmutable())
            ->setParameter('id', $id)
            ->getQuery()->execute();
    }

    public function noterEchecEnvoi(int $id, string $message): ?SynchroJournal
    {
        $ligne = $this->em->find(SynchroJournal::class, $id);
        $ligne?->noterEchec($message);
        $this->em->flush();

        return $ligne;
    }

    /** Remet en file tout ce qui existe (premier chargement du site). Ligne de commande seulement. */
    public function initialiser(): int
    {
        $n = 0;
        foreach (CatalogueSynchro::types() as $type => $def) {
            if (!CatalogueSynchro::envoye($type, $this->enLigne)) {
                continue;
            }
            $ids = $this->em->createQuery('SELECT e.publicId FROM '.$def['classe'].' e ORDER BY e.id ASC')->getSingleColumnResult();
            foreach ($ids as $publicId) {
                // Lecture brute : la colonne revient en binaire.
                $this->em->persist(new SynchroJournal($type, ($publicId instanceof Uuid ? $publicId : Uuid::fromString((string) $publicId))->toRfc4122(), SynchroJournal::MAJ));
                if (++$n % 500 === 0) {
                    $this->em->flush();
                    $this->em->clear();
                }
            }
        }
        $this->em->flush();
        $this->em->clear();

        return $n;
    }

    /* ---------- Réception ---------- */

    /**
     * Applique les éléments reçus, dans l'ordre, chacun dans sa propre
     * transaction. S'arrête au premier échec : la suite repartira au
     * prochain cycle, dans le même ordre.
     *
     * @param list<array<string, mixed>> $evenements
     *
     * @return array{resultats: list<array{id: int, evenement: string, type: string, publicId: string, titre: string, statut: string, message: ?string}>, erreur: ?array{id: int, evenement: string, type: string, titre: string, message: string, reprise: bool}}
     */
    public function appliquer(array $evenements): array
    {
        $resultats = [];
        foreach ($evenements as $e) {
            $id = (int) ($e['id'] ?? 0);
            $evenement = (string) ($e['evenement'] ?? '');
            $type = (string) ($e['type'] ?? '');
            $publicId = (string) ($e['publicId'] ?? '');
            $titre = isset(CatalogueSynchro::types()[$type]) ? Serialiseur::titre($type, null, \is_array($e['donnees'] ?? null) ? $e['donnees'] : null) : $type;
            try {
                [$statut, $message, $titre] = $this->appliquerUn($evenement, $type, $publicId, (string) ($e['operation'] ?? ''), \is_array($e['donnees'] ?? null) ? $e['donnees'] : null, $titre);
                $resultats[] = ['id' => $id, 'evenement' => $evenement, 'type' => $type, 'publicId' => $publicId, 'titre' => $titre, 'statut' => $statut, 'message' => $message];
                // Sans ce vidage, chaque flush relit toutes les données déjà reçues du lot.
                $this->em->clear();
            } catch (\Throwable $ex) {
                if (!$this->em->isOpen()) {
                    $this->doctrine->resetManager();
                }
                $this->em->clear();

                return ['resultats' => $resultats, 'erreur' => [
                    'id' => $id, 'evenement' => $evenement, 'type' => $type, 'titre' => $titre,
                    'message' => self::messageErreur($ex), 'reprise' => $ex instanceof DependanceManquante,
                ]];
            }
        }

        return ['resultats' => $resultats, 'erreur' => null];
    }

    /** @return array{0: string, 1: ?string, 2: string} statut, message, titre */
    private function appliquerUn(string $evenement, string $type, string $publicId, string $operation, ?array $donnees, string $titre): array
    {
        if ($evenement === '' || $this->em->find(SynchroRecu::class, $evenement) !== null) {
            return [self::RESULTAT_DEJA, null, $titre];
        }
        if (!CatalogueSynchro::accepte($type, $this->enLigne)) {
            $this->noterRecu($evenement);

            return [self::RESULTAT_IGNORE, 'Type non accepté de ce côté', $titre];
        }

        return $this->journal->sansEcho(function () use ($evenement, $type, $publicId, $operation, $donnees, $titre): array {
            return $this->em->wrapInTransaction(function () use ($evenement, $type, $publicId, $operation, $donnees, $titre): array {
                $existante = $this->serialiseur->trouver($type, $publicId);
                $resultat = [self::RESULTAT_OK, null, $titre];

                if (!$this->enLigne && $existante !== null) {
                    // Le local est maître : une modification ou une suppression faite en ligne
                    // d'une fiche qu'il possède n'est pas appliquée, elle est signalée.
                    $resultat = [self::RESULTAT_CONFLIT, $operation === SynchroJournal::SUPPRESSION
                        ? 'Supprimée en ligne : gardée au local'
                        : 'Modifiée en ligne : la fiche du local est gardée', Serialiseur::titre($type, $existante)];
                } elseif ($operation === SynchroJournal::SUPPRESSION) {
                    if ($existante !== null) {
                        $this->em->remove($existante);
                    }
                } elseif ($operation === SynchroJournal::MAJ && $donnees !== null) {
                    [$entite] = $this->serialiseur->depuisTableau($type, $publicId, $donnees);
                    if (!$this->enLigne) {
                        // Création faite en ligne : certaines valeurs ne se décident qu'au local
                        // (un paiement saisi en ligne reste à valider).
                        $meta = $this->em->getClassMetadata($entite::class);
                        foreach (CatalogueSynchro::types()[$type]['imposeAuLocal'] ?? [] as $champ => $valeur) {
                            $meta->setFieldValue($entite, $champ, $valeur);
                        }
                    }
                    $this->em->persist($entite);
                } else {
                    $resultat = [self::RESULTAT_IGNORE, 'Donnée supprimée avant l\'envoi', $titre];
                }

                $this->noterRecu($evenement);
                $this->em->flush();

                return $resultat;
            });
        });
    }

    private function noterRecu(string $evenement): void
    {
        $this->em->persist(new SynchroRecu($evenement));
    }

    private static function messageErreur(\Throwable $e): string
    {
        $message = $e->getMessage();
        if (str_contains($message, 'Duplicate entry')) {
            return 'Doublon : une fiche avec la même valeur unique existe déjà ('.mb_substr(preg_replace('/.*Duplicate entry/s', 'Duplicate entry', $message), 0, 160).')';
        }

        return mb_substr($message, 0, 500);
    }

    /* ---------- État et historique ---------- */

    public function etat(string $cle): ?string
    {
        return $this->em->find(SynchroEtat::class, $cle)?->getValeur();
    }

    public function noterEtat(string $cle, ?string $valeur): void
    {
        $etat = $this->em->find(SynchroEtat::class, $cle) ?? new SynchroEtat($cle);
        $etat->setValeur($valeur);
        $this->em->persist($etat);
        $this->em->flush();
    }

    public function dateEtat(string $cle): ?\DateTimeImmutable
    {
        $v = $this->etat($cle);

        return $v === null ? null : (new \DateTimeImmutable($v));
    }

    /** $enregistrer = false : gardé en attente jusqu'à enregistrerEchanges() (un lot entier). */
    public function noterEchange(string $sens, ?string $type, string $libelle, string $statut, ?string $message = null, bool $enregistrer = true): SynchroEchange
    {
        $echange = new SynchroEchange($sens, $type, $libelle, $statut, $message);
        $this->em->persist($echange);
        if ($enregistrer) {
            $this->em->flush();
        }

        return $echange;
    }

    /** Écrit les échanges notés d'un lot en une fois et libère la mémoire. */
    public function enregistrerEchanges(): void
    {
        $this->em->flush();
        $this->em->clear();
    }

    /** Les erreurs passées sont résolues quand un cycle complet réussit. */
    public function resoudreErreurs(): void
    {
        $this->em->createQueryBuilder()
            ->update(SynchroEchange::class, 'e')
            ->set('e.resolu', 'true')
            ->where('e.statut IN (:statuts)')->andWhere('e.resolu = false')
            ->setParameter('statuts', [SynchroEchange::ERREUR, SynchroEchange::REPRISE])
            ->getQuery()->execute();
    }

    /** @return list<SynchroEchange> */
    public function echanges(int $max = 50, ?string $statut = null, ?string $sens = null): array
    {
        $qb = $this->em->createQueryBuilder()->select('e')->from(SynchroEchange::class, 'e')
            ->orderBy('e.id', 'DESC')->setMaxResults($max);
        if ($statut !== null) {
            $qb->andWhere('e.statut = :statut')->setParameter('statut', $statut);
        }
        if ($sens !== null) {
            $qb->andWhere('e.sens = :sens')->setParameter('sens', $sens);
        }

        return $qb->getQuery()->getResult();
    }

    public function enAttente(): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(j.id)')->from(SynchroJournal::class, 'j')
            ->andWhere('j.envoyeLe IS NULL')->andWhere('j.ignore = false')
            ->getQuery()->getSingleScalarResult();
    }

    /** @return list<SynchroJournal> */
    public function bloques(): array
    {
        return $this->em->createQueryBuilder()
            ->select('j')->from(SynchroJournal::class, 'j')
            ->andWhere('j.envoyeLe IS NULL')->andWhere('j.ignore = false')
            ->andWhere('j.tentatives >= :max')->setParameter('max', SynchroJournal::TENTATIVES_MAX)
            ->orderBy('j.id', 'ASC')->getQuery()->getResult();
    }

    public function ignorer(int $id): bool
    {
        $ligne = $this->em->find(SynchroJournal::class, $id);
        if ($ligne === null || !$ligne->estBloque()) {
            return false;
        }
        $ligne->ignorer(new \DateTimeImmutable());
        $this->em->flush();

        return true;
    }

    public function compterEchanges(string $sens, \DateTimeImmutable $depuis, ?array $statuts = null, bool $nonResolusSeulement = false): int
    {
        $qb = $this->em->createQueryBuilder()->select('COUNT(e.id)')->from(SynchroEchange::class, 'e')
            ->andWhere('e.le >= :depuis')->setParameter('depuis', $depuis);
        if ($sens !== '') {
            $qb->andWhere('e.sens = :sens')->setParameter('sens', $sens);
        }
        if ($statuts !== null) {
            $qb->andWhere('e.statut IN (:statuts)')->setParameter('statuts', $statuts);
        }
        if ($nonResolusSeulement) {
            $qb->andWhere('e.resolu = false');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Texte de l'alerte, ou null. Rien tant que la synchronisation n'est pas
     * réglée.
     */
    public static function alerte(bool $configuree, ?\DateTimeImmutable $derniereReussite, ?\DateTimeImmutable $miseEnRoute, \DateTimeImmutable $maintenant, ?string $derniereErreur): ?string
    {
        if (!$configuree) {
            return null;
        }
        $reference = $derniereReussite ?? $miseEnRoute;
        if ($reference === null) {
            return null; // l'agent n'a encore jamais tourné
        }
        $minutes = intdiv($maintenant->getTimestamp() - $reference->getTimestamp(), 60);
        if ($minutes < self::ALERTE_MINUTES) {
            return null;
        }
        $duree = $minutes >= 120 ? intdiv($minutes, 60).' h' : $minutes.' min';

        return ($derniereReussite === null ? 'La synchronisation n\'a jamais réussi' : 'Pas de synchronisation réussie depuis '.$duree)
            .($derniereErreur ? ' : '.$derniereErreur : '.');
    }

    /** Purge des historiques (une fois par jour). */
    public function purger(\DateTimeImmutable $maintenant): void
    {
        $this->em->createQueryBuilder()->delete(SynchroRecu::class, 'r')->where('r.recuLe < :d')
            ->setParameter('d', $maintenant->modify('-60 days'))->getQuery()->execute();
        $this->em->createQueryBuilder()->delete(SynchroJournal::class, 'j')->where('j.envoyeLe < :d')
            ->setParameter('d', $maintenant->modify('-30 days'))->getQuery()->execute();
        $this->em->createQueryBuilder()->delete(SynchroEchange::class, 'e')->where('e.le < :d')
            ->setParameter('d', $maintenant->modify('-90 days'))->getQuery()->execute();
    }
}
