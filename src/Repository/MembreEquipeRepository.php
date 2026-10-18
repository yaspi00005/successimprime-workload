<?php

namespace App\Repository;

use App\Entity\MembreEquipe;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MembreEquipe>
 */
class MembreEquipeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MembreEquipe::class);
    }

    /**
     * Personnes montrées sur le site : visibles et avec leur accord.
     *
     * @return list<MembreEquipe>
     */
    public function visibles(): array
    {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.photo', 'p')->addSelect('p')
            ->where('m.visible = true')->andWhere('m.accordPersonne = true')->andWhere("m.nom <> ''")
            ->orderBy('m.ordre', 'ASC')->addOrderBy('m.nom', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Le gérant dont le mot paraît en haut de « Qui sommes-nous ? », parmi
     * les personnes montrées (le premier dans l'ordre, s'il y en avait
     * deux par erreur).
     *
     * @param list<MembreEquipe> $visibles
     */
    public static function gerantDe(array $visibles): ?MembreEquipe
    {
        foreach ($visibles as $membre) {
            if ($membre->estMotVisible()) {
                return $membre;
            }
        }

        return null;
    }

    /**
     * La grille « Notre équipe » : le gérant n'y paraît pas une seconde
     * fois quand son mot est déjà montré, sauf si « aussi dans l'équipe »
     * est coché.
     *
     * @param list<MembreEquipe> $visibles
     *
     * @return list<MembreEquipe>
     */
    public static function grille(array $visibles, ?MembreEquipe $gerant): array
    {
        return array_values(array_filter($visibles, static fn (MembreEquipe $m): bool => $m !== $gerant || $gerant->isAussiDansEquipe()));
    }

    /**
     * @return list<MembreEquipe>
     */
    public function dansLOrdre(): array
    {
        return $this->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']);
    }
}
