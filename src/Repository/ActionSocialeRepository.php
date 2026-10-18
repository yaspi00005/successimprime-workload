<?php

namespace App\Repository;

use App\Entity\ActionSociale;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActionSociale>
 */
class ActionSocialeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionSociale::class);
    }

    /**
     * Actions montrées sur le site (ActionSociale::estVisible) : publiées,
     * avec un titre, un texte et au moins une photo, dans l'ordre choisi
     * puis la plus récente d'abord, avec leurs photos.
     *
     * @return list<ActionSociale>
     */
    public function visibles(?int $nombre = null): array
    {
        $actions = $this->requeteVisibles()
            ->addSelect('i')
            ->orderBy('a.ordre', 'ASC')->addOrderBy('a.dateAction', 'DESC')->addOrderBy('a.id', 'DESC')
            ->addOrderBy('i.ordre', 'ASC')->addOrderBy('i.id', 'ASC')
            ->getQuery()->getResult();

        return null === $nombre ? $actions : \array_slice($actions, 0, $nombre);
    }

    /** Au moins une action montrée : la page, le bloc de l'accueil et le lien du pied de page paraissent. */
    public function existeVisible(): bool
    {
        return [] !== $this->requeteVisibles()->select('a.id')->setMaxResults(1)->getQuery()->getScalarResult();
    }

    /** Date de la dernière modification d'une action montrée (plan du site). */
    public function derniereModification(): ?\DateTimeImmutable
    {
        $date = $this->requeteVisibles()->select('MAX(a.modifieLe)')->getQuery()->getSingleScalarResult();

        return null === $date ? null : new \DateTimeImmutable((string) $date);
    }

    /**
     * @return list<ActionSociale>
     */
    public function dansLOrdre(): array
    {
        return $this->findBy([], ['ordre' => 'ASC', 'dateAction' => 'DESC', 'id' => 'DESC']);
    }

    private function requeteVisibles(): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->innerJoin('a.images', 'i')
            ->where('a.publie = true')
            ->andWhere("a.titre <> ''")
            ->andWhere("a.texte IS NOT NULL AND a.texte <> ''");
    }
}
