<?php

namespace App\Service\Livraisons;

use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Où en sont les livraisons (docs/documents-livraisons.md) : livrée ou
 * pas, à quel moment, par qui, reçue par qui. Pour le bureau (Direction,
 * caisse, prépresse) : bloc « Livraison » de la commande et « Suivi des
 * livraisons ». Pour le livreur : « Mes livraisons », les siennes
 * seulement.
 */
final class SuiviLivraisons
{
    /** Au-delà, la liste demande de resserrer les filtres. */
    public const LIMITE = 300;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Bons d'une commande (sauf annulés), le plus récent d'abord.
     *
     * @return list<BonLivraison>
     */
    public function deLaCommande(Commandes $commande): array
    {
        return $this->em->getRepository(BonLivraison::class)->createQueryBuilder('b')
            ->addSelect('l', 'lp', 'ep')->leftJoin('b.livreur', 'l')->leftJoin('b.livrePar', 'lp')->leftJoin('b.echecPar', 'ep')
            ->where('b.commande = :c')->andWhere('b.statut != :annule')
            ->setParameter('c', $commande)->setParameter('annule', BonLivraison::STATUT_ANNULE)
            ->orderBy('b.id', 'DESC')->getQuery()->getResult();
    }

    /**
     * Suivi des livraisons : bons dont le jour (prévu, de remise ou
     * d'échec ; à défaut de création) tombe entre $du et $au, pour un
     * livreur (prévu, qui a remis ou qui a noté l'échec) et un état.
     *
     * @return list<BonLivraison>
     */
    public function rechercher(\DateTimeImmutable $du, \DateTimeImmutable $au, ?User $livreur = null, ?string $etat = null): array
    {
        $qb = $this->base($du, $au, $livreur);
        if (null !== $etat && isset(BonLivraison::ETATS_SUIVI[$etat])) {
            self::filtrerEtat($qb, $etat);
        }

        return $qb->orderBy('b.prevuLe', 'DESC')->addOrderBy('b.ordreTournee', 'ASC')->addOrderBy('b.id', 'DESC')
            ->setMaxResults(self::LIMITE)->getQuery()->getResult();
    }

    /**
     * Nombre de bons par état pour la même période et le même livreur.
     *
     * @return array<string, int>
     */
    public function compter(\DateTimeImmutable $du, \DateTimeImmutable $au, ?User $livreur = null): array
    {
        $n = [];
        foreach (array_keys(BonLivraison::ETATS_SUIVI) as $etat) {
            if (BonLivraison::ETAT_ANNULEE === $etat) {
                continue;
            }
            $qb = $this->base($du, $au, $livreur)->select('COUNT(b.id)');
            self::filtrerEtat($qb, $etat);
            $n[$etat] = (int) $qb->getQuery()->getSingleScalarResult();
        }

        return $n;
    }

    /**
     * Mes livraisons : celles que le livreur a remises et celles où il a
     * noté un échec, depuis $depuis, la plus récente d'abord.
     *
     * @return list<BonLivraison>
     */
    public function duLivreur(User $livreur, \DateTimeImmutable $depuis): array
    {
        return $this->em->getRepository(BonLivraison::class)->createQueryBuilder('b')
            ->addSelect('c', 'cli')->join('b.commande', 'c')->leftJoin('c.clients', 'cli')
            ->where('(b.livrePar = :moi AND b.statut = :livre AND b.livreLe >= :depuis) OR (b.echecPar = :moi AND b.echecLe >= :depuis)')
            ->setParameter('moi', $livreur)->setParameter('livre', BonLivraison::STATUT_LIVRE)->setParameter('depuis', $depuis)
            ->orderBy('b.id', 'DESC')->setMaxResults(self::LIMITE)->getQuery()->getResult();
    }

    /**
     * Pour « Mes livraisons » : ce que la livraison a été pour ce livreur
     * (remise par lui, ou son échec même si un collègue a livré ensuite),
     * avec son moment. Rangées de la plus récente à la plus ancienne.
     *
     * @param list<BonLivraison> $bons
     *
     * @return list<array{bon: BonLivraison, etat: string, le: \DateTimeImmutable}>
     */
    public static function vuesDuLivreur(array $bons, User $livreur): array
    {
        $vues = [];
        foreach ($bons as $b) {
            if (BonLivraison::STATUT_LIVRE === $b->getStatut() && $b->getLivrePar() === $livreur && null !== $b->getLivreLe()) {
                $vues[] = ['bon' => $b, 'etat' => BonLivraison::ETAT_LIVREE, 'le' => $b->getLivreLe()];
            } elseif ($b->getEchecPar() === $livreur && null !== $b->getEchecLe()) {
                $vues[] = ['bon' => $b, 'etat' => BonLivraison::ETAT_ECHEC, 'le' => $b->getEchecLe()];
            }
        }
        usort($vues, static fn (array $a, array $b): int => $b['le'] <=> $a['le']);

        return $vues;
    }

    private function base(\DateTimeImmutable $du, \DateTimeImmutable $au, ?User $livreur): QueryBuilder
    {
        $du = $du->setTime(0, 0);
        $apres = $au->setTime(0, 0)->modify('+1 day');
        $qb = $this->em->getRepository(BonLivraison::class)->createQueryBuilder('b')
            ->addSelect('c', 'cli')->join('b.commande', 'c')->leftJoin('c.clients', 'cli')
            ->where('b.statut != :annule')
            ->andWhere('(b.prevuLe >= :du AND b.prevuLe < :apres) OR (b.livreLe >= :du AND b.livreLe < :apres) OR (b.echecLe >= :du AND b.echecLe < :apres) OR (b.prevuLe IS NULL AND b.livreLe IS NULL AND b.creeLe >= :du AND b.creeLe < :apres)')
            ->setParameter('annule', BonLivraison::STATUT_ANNULE)->setParameter('du', $du)->setParameter('apres', $apres);
        if (null !== $livreur) {
            $qb->andWhere('b.livreur = :l OR b.livrePar = :l OR b.echecPar = :l')->setParameter('l', $livreur);
        }

        return $qb;
    }

    private static function filtrerEtat(QueryBuilder $qb, string $etat): void
    {
        match ($etat) {
            BonLivraison::ETAT_LIVREE => $qb->andWhere('b.statut = :etat')->setParameter('etat', BonLivraison::STATUT_LIVRE),
            BonLivraison::ETAT_ECHEC => $qb->andWhere('b.statut = :etat AND b.echecEnCours = true')->setParameter('etat', BonLivraison::STATUT_VALIDE),
            BonLivraison::ETAT_EN_TOURNEE => $qb->andWhere('b.statut = :etat AND b.echecEnCours = false AND b.chargeLe IS NOT NULL')->setParameter('etat', BonLivraison::STATUT_VALIDE),
            BonLivraison::ETAT_A_LIVRER => $qb->andWhere('b.statut = :brouillon OR (b.statut = :etat AND b.echecEnCours = false AND b.chargeLe IS NULL)')
                ->setParameter('brouillon', BonLivraison::STATUT_BROUILLON)->setParameter('etat', BonLivraison::STATUT_VALIDE),
            default => $qb->andWhere('1 = 0'),
        };
    }
}
