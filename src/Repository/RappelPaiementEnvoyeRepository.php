<?php

namespace App\Repository;

use App\Entity\Commandes;
use App\Entity\RappelPaiementEnvoye;
use App\Entity\RegleRappelPaiement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RappelPaiementEnvoye>
 */
class RappelPaiementEnvoyeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RappelPaiementEnvoye::class);
    }

    /**
     * true si un rappel a deja ete envoye AUJOURD'HUI (jour civil de
     * $reference) pour ce couple commande/regle -- evite un doublon
     * si la tache planifiee tournait deux fois le meme jour. Une
     * regle "tous les N jours" doit en revanche pouvoir se
     * redeclencher les jours suivants : ce n'est donc volontairement
     * pas une verification "a-t-on deja envoye un jour quelconque".
     */
    public function dejaEnvoyeAujourdHui(
        Commandes $commande,
        RegleRappelPaiement $regle,
        \DateTimeImmutable $reference
    ): bool {
        $debut = $reference->setTime(0, 0, 0);
        $fin = $reference->setTime(23, 59, 59);

        $resultat = $this->createQueryBuilder('rappel')
            ->select('rappel.id')
            ->andWhere('rappel.commande = :commande')
            ->andWhere('rappel.regle = :regle')
            ->andWhere('rappel.dateEnvoi BETWEEN :debut AND :fin')
            ->setParameter('commande', $commande)
            ->setParameter('regle', $regle)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $resultat !== null;
    }

    /**
     * @return array<int, RappelPaiementEnvoye>
     */
    public function findRecents(int $limite = 100): array
    {
        return $this->createQueryBuilder('rappel')
            ->leftJoin('rappel.commande', 'commande')
            ->addSelect('commande')
            ->leftJoin('rappel.regle', 'regle')
            ->addSelect('regle')
            ->orderBy('rappel.dateEnvoi', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }
}
