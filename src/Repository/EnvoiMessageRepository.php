<?php

namespace App\Repository;

use App\Entity\EnvoiMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<EnvoiMessage>
 */
class EnvoiMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EnvoiMessage::class);
    }

    /** Messages partis sur ce canal depuis cette date (SMS : lignes, pas unités). */
    public function compterEnvoyes(string $canal, \DateTimeImmutable $depuis): int
    {
        return (int) $this->createQueryBuilder('e')->select('COUNT(e.id)')
            ->where('e.canal = :canal')->andWhere('e.statut = :envoye')->andWhere('e.envoyeLe >= :depuis')
            ->setParameter('canal', $canal)->setParameter('envoye', EnvoiMessage::ENVOYE)->setParameter('depuis', $depuis)
            ->getQuery()->getSingleScalarResult();
    }

    /** Unités consommées sur ce canal depuis cette date (messages partis). */
    public function unitesDepuis(string $canal, \DateTimeImmutable $depuis): int
    {
        return (int) $this->createQueryBuilder('e')->select('COALESCE(SUM(e.unites), 0)')
            ->where('e.canal = :canal')->andWhere('e.statut = :envoye')->andWhere('e.envoyeLe >= :depuis')
            ->setParameter('canal', $canal)->setParameter('envoye', EnvoiMessage::ENVOYE)->setParameter('depuis', $depuis)
            ->getQuery()->getSingleScalarResult();
    }

    /** Messages en échec sur ce canal depuis cette date. */
    public function compterEchecs(string $canal, \DateTimeImmutable $depuis): int
    {
        return (int) $this->createQueryBuilder('e')->select('COUNT(e.id)')
            ->where('e.canal = :canal')->andWhere('e.statut = :echec')->andWhere('e.envoyeLe >= :depuis')
            ->setParameter('canal', $canal)->setParameter('echec', EnvoiMessage::ECHEC)->setParameter('depuis', $depuis)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Une page de l'historique, les plus récents d'abord.
     *
     * @param array{canal?: string, statut?: string, type?: string, origine?: string, du?: ?\DateTimeImmutable, au?: ?\DateTimeImmutable, q?: string, parDestinataire?: bool, personnel?: bool} $filtres
     *
     * @return array{lignes: list<EnvoiMessage>, total: int}
     */
    public function rechercher(array $filtres, int $page, int $parPage): array
    {
        $total = (int) (clone $this->filtrer($filtres))->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();
        /** @var list<EnvoiMessage> $lignes */
        $lignes = $this->filtrer($filtres)
            ->orderBy('e.envoyeLe', 'DESC')->addOrderBy('e.id', 'DESC')
            ->setFirstResult(max(0, $page - 1) * $parPage)->setMaxResults($parPage)
            ->getQuery()->getResult();

        return ['lignes' => $lignes, 'total' => $total];
    }

    /**
     * Pour l'export : au plus $max lignes, les plus récentes d'abord.
     *
     * @param array{canal?: string, statut?: string, type?: string, origine?: string, du?: ?\DateTimeImmutable, au?: ?\DateTimeImmutable, q?: string, parDestinataire?: bool, personnel?: bool} $filtres
     *
     * @return iterable<EnvoiMessage>
     */
    public function pourExport(array $filtres, int $max): iterable
    {
        return $this->filtrer($filtres)
            ->orderBy('e.envoyeLe', 'DESC')->addOrderBy('e.id', 'DESC')
            ->setMaxResults($max)
            ->getQuery()->toIterable();
    }

    /**
     * @param array{canal?: string, statut?: string, type?: string, origine?: string, du?: ?\DateTimeImmutable, au?: ?\DateTimeImmutable, q?: string, parDestinataire?: bool, personnel?: bool} $filtres
     */
    private function filtrer(array $filtres): QueryBuilder
    {
        $qb = $this->createQueryBuilder('e');
        foreach (['canal', 'statut', 'type', 'origine'] as $champ) {
            if ('' !== ($filtres[$champ] ?? '')) {
                $qb->andWhere('e.'.$champ.' = :'.$champ)->setParameter($champ, $filtres[$champ]);
            }
        }
        if (null !== ($filtres['du'] ?? null)) {
            $qb->andWhere('e.envoyeLe >= :du')->setParameter('du', $filtres['du']);
        }
        if (null !== ($filtres['au'] ?? null)) {
            $qb->andWhere('e.envoyeLe < :au')->setParameter('au', $filtres['au']->modify('+1 day'));
        }
        // Recherche dans l'objet, et dans le destinataire (chiffres ou adresse) seulement si le contrôleur le permet (droit Clients) ;
        // celui d'un message au personnel, seulement pour la direction (sinon on devinerait ses connexions).
        $q = trim((string) ($filtres['q'] ?? ''));
        if ('' !== $q) {
            $chiffres = preg_replace('/\D+/', '', $q) ?? '';
            $parDestinataire = (bool) ($filtres['parDestinataire'] ?? false);
            $personnel = (bool) ($filtres['personnel'] ?? false);
            $dans = static fn (string $condition): string => $personnel ? $condition : '('.$condition.' AND e.type NOT IN (:typesPersonnel))';
            $or = $parDestinataire ? $qb->expr()->orX($dans('e.destinataire LIKE :q'), 'e.sujet LIKE :q') : $qb->expr()->orX('e.sujet LIKE :q');
            if ($parDestinataire && \strlen($chiffres) >= 4) {
                $or->add($dans('e.destinataire LIKE :chiffres'));
                $qb->setParameter('chiffres', '%'.$chiffres.'%');
            }
            if ($parDestinataire && !$personnel) {
                $qb->setParameter('typesPersonnel', EnvoiMessage::TYPES_PERSONNEL);
            }
            $qb->andWhere($or)->setParameter('q', '%'.addcslashes(mb_strtolower($q), '%_').'%');
        }

        return $qb;
    }
}
