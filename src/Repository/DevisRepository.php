<?php

namespace App\Repository;

use App\Entity\Devis;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Devis>
 */
class DevisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Devis::class);
    }
 /**
     * Sans recherche :
     * - statut = true : paiement en attente ;
     * - ou etat = true : travaux en cours.
     *
     * Avec une recherche active, toutes les commandes peuvent être retrouvées.
     */
    public function rechercherPourIndex(array $filtres): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.clients', 'cl')
            ->addSelect('cl')
            ->leftJoin('c.devisDetails', 'd')
            ->addSelect('d')
            ->andWhere('c.deleted = false')
            ->distinct();

        $rechercheActive = $this->rechercheEstActive($filtres);
        $affichage = $filtres['affichage'] ?? 'actives';

        /*
         * Affichage par défaut :
         * paiement en attente OU travaux en cours.
         */
        if (!$rechercheActive && $affichage !== 'toutes') {
            $qb
                ->andWhere(
                    $qb->expr()->orX(
                        'c.statut = :statutActif',
                        'c.etat = :etatActif'
                    )
                )
                ->setParameter('statutActif', true)
                ->setParameter('etatActif', true);
        }

        /*
         * Recherche par numéro de devis,
         * nom du client ou téléphone.
         */
        $q = trim((string) ($filtres['q'] ?? ''));

        if ($q !== '') {
            $recherche = $qb->expr()->orX(
                'LOWER(cl.nom) LIKE LOWER(:q)',
                'cl.telephone LIKE :q'
            );

            if (ctype_digit($q)) {
                $recherche->add('c.id = :devisId');
                $qb->setParameter('devisId', (int) $q);
            }

            $qb
                ->andWhere($recherche)
                ->setParameter('q', '%' . $q . '%');
        }

        /*
         * Filtre par client.
         */
        if (!empty($filtres['client'])) {
            $qb
                ->andWhere('cl.id = :client')
                ->setParameter('client', (int) $filtres['client']);
        }

        /*
         * Filtre par statut de paiement.
         *
         * 1 = paiement en attente
         * 0 = paiement terminé
         */
        if (($filtres['statut'] ?? '') !== '') {
            $qb
                ->andWhere('c.statut = :statut')
                ->setParameter(
                    'statut',
                    (string) $filtres['statut'] === '1'
                );
        }

        /*
         * Filtre par état des travaux.
         *
         * 1 = travaux en cours
         * 0 = travaux terminés
         */
        if (($filtres['etat'] ?? '') !== '') {
            $qb
                ->andWhere('c.etat = :etat')
                ->setParameter(
                    'etat',
                    (string) $filtres['etat'] === '1'
                );
        }

        /*
         * Filtre par situation réelle du paiement.
         */
        match ($filtres['paiement'] ?? '') {
            'impayee' => $qb->andWhere(
                'COALESCE(c.montantAPayer, 0) = 0'
            ),

            'partielle' => $qb->andWhere(
                'COALESCE(c.montantAPayer, 0) > 0
                 AND COALESCE(c.montantAPayer, 0) < c.totalTtc'
            ),

            'payee' => $qb->andWhere(
                'COALESCE(c.montantAPayer, 0) >= c.totalTtc'
            ),

            default => null,
        };

        /*
         * Période.
         *
         * Remplace createdAt si ta propriété de date
         * possède un autre nom dans Commandes.
         */
        if (!empty($filtres['date_debut'])) {
            try {
                $dateDebut = new \DateTimeImmutable(
                    $filtres['date_debut'] . ' 00:00:00'
                );

                $qb
                    ->andWhere('c.createdAt >= :dateDebut')
                    ->setParameter('dateDebut', $dateDebut);
            } catch (\Exception) {
                // La date invalide est ignorée.
            }
        }

        if (!empty($filtres['date_fin'])) {
            try {
                $dateFin = new \DateTimeImmutable(
                    $filtres['date_fin'] . ' 23:59:59'
                );

                $qb
                    ->andWhere('c.createdAt <= :dateFin')
                    ->setParameter('dateFin', $dateFin);
            } catch (\Exception) {
                // La date invalide est ignorée.
            }
        }

        /*
         * Montants.
         */
        if (
            isset($filtres['montant_min'])
            && $filtres['montant_min'] !== ''
        ) {
            $qb
                ->andWhere('c.totalTtc >= :montantMin')
                ->setParameter(
                    'montantMin',
                    (int) $filtres['montant_min']
                );
        }

        if (
            isset($filtres['montant_max'])
            && $filtres['montant_max'] !== ''
        ) {
            $qb
                ->andWhere('c.totalTtc <= :montantMax')
                ->setParameter(
                    'montantMax',
                    (int) $filtres['montant_max']
                );
        }

        /*
         * Tri des résultats.
         */
        match ($filtres['tri'] ?? 'recent') {
            'ancien' => $qb->orderBy('c.id', 'ASC'),

            'montant_desc' => $qb
                ->orderBy('c.totalTtc', 'DESC'),

            'reste_desc' => $qb
                ->addSelect(
                    '(c.totalTtc - COALESCE(c.montantAPayer, 0))
                     AS HIDDEN resteAPayer'
                )
                ->orderBy('resteAPayer', 'DESC'),

            default => $qb->orderBy('c.id', 'DESC'),
        };

        return $qb->getQuery()->getResult();
    }

    private function rechercheEstActive(array $filtres): bool
    {
        $champsRecherche = [
            'q',
            'client',
            'statut',
            'etat',
            'paiement',
            'date_debut',
            'date_fin',
            'montant_min',
            'montant_max',
        ];

        foreach ($champsRecherche as $nom) {
            $valeur = $filtres[$nom] ?? null;

            /*
             * Attention : la chaîne "0" est une valeur valide
             * pour statut et etat.
             */
            if ($valeur !== null && $valeur !== '') {
                return true;
            }
        }

        return false;
    }
}