<?php

namespace App\Repository;

use App\Entity\MouvementTresorerie;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\CompteTresorerie;
use App\Entity\LigneRapprochementBancaire;

/**
 * @extends ServiceEntityRepository<MouvementTresorerie>
 */
class MouvementTresorerieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct(
            $registry,
            MouvementTresorerie::class
        );
    }

    public function referenceExiste(string $reference): bool
    {
        return (int) $this->createQueryBuilder('mouvement')
            ->select('COUNT(mouvement.id)')
            ->andWhere('mouvement.reference = :reference')
            ->setParameter(
                'reference',
                strtoupper(trim($reference))
            )
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * @return MouvementTresorerie[]
     */
    public function findDerniers(int $limite = 20): array
    {
        return $this->createQueryBuilder('mouvement')
            ->leftJoin(
                'mouvement.compteSource',
                'compteSource'
            )
            ->addSelect('compteSource')
            ->leftJoin(
                'mouvement.compteDestination',
                'compteDestination'
            )
            ->addSelect('compteDestination')
            ->orderBy('mouvement.dateOperation', 'DESC')
            ->addOrderBy('mouvement.id', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return MouvementTresorerie[]
     */
    public function rechercherAvecFiltres(
        array $filtres,
        ?int $limite = null
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->leftJoin('m.compteSource', 'source')
            ->addSelect('source')
            ->leftJoin('m.compteDestination', 'destination')
            ->addSelect('destination')
            ->leftJoin('m.agent', 'agent')
            ->addSelect('agent');

        if (!empty($filtres['compte'])) {
            $qb
                ->andWhere(
                    '(source.id = :compte
                OR destination.id = :compte)'
                )
                ->setParameter(
                    'compte',
                    (int) $filtres['compte']
                );
        }

        if (!empty($filtres['agent'])) {
            $qb
                ->andWhere('agent.id = :agent')
                ->setParameter(
                    'agent',
                    (int) $filtres['agent']
                );
        }

        if (!empty($filtres['type'])) {
            $qb
                ->andWhere('m.type = :type')
                ->setParameter(
                    'type',
                    trim((string) $filtres['type'])
                );
        }

        if (!empty($filtres['statut'])) {
            $qb
                ->andWhere('m.statut = :statut')
                ->setParameter(
                    'statut',
                    trim((string) $filtres['statut'])
                );
        }

        if (!empty($filtres['modePaiement'])) {
            $qb
                ->andWhere('m.modePaiement = :modePaiement')
                ->setParameter(
                    'modePaiement',
                    trim((string) $filtres['modePaiement'])
                );
        }

        if (!empty($filtres['recherche'])) {
            $recherche = '%'
                . mb_strtolower(
                    trim((string) $filtres['recherche'])
                )
                . '%';

            $qb
                ->andWhere(
                    '(LOWER(m.reference) LIKE :recherche
                OR LOWER(m.referenceExterne) LIKE :recherche
                OR LOWER(m.libelle) LIKE :recherche)'
                )
                ->setParameter('recherche', $recherche);
        }

        if (!empty($filtres['dateDebut'])) {
            try {
                $dateDebut = new \DateTimeImmutable(
                    (string) $filtres['dateDebut'] . ' 00:00:00'
                );

                $qb
                    ->andWhere('m.dateOperation >= :dateDebut')
                    ->setParameter('dateDebut', $dateDebut);
            } catch (\Throwable) {
                // Une date invalide est ignorée.
            }
        }

        if (!empty($filtres['dateFin'])) {
            try {
                $dateFin = new \DateTimeImmutable(
                    (string) $filtres['dateFin'] . ' 23:59:59'
                );

                $qb
                    ->andWhere('m.dateOperation <= :dateFin')
                    ->setParameter('dateFin', $dateFin);
            } catch (\Throwable) {
                // Une date invalide est ignorée.
            }
        }

        $qb
            ->orderBy('m.dateOperation', 'DESC')
            ->addOrderBy('m.id', 'DESC');

        if ($limite !== null && $limite > 0) {
            $qb->setMaxResults($limite);
        }

        return $qb
            ->getQuery()
            ->getResult();
    }
    /**
     * @param MouvementTresorerie[] $mouvements
     *
     * @return array{
     *     encaissements: int,
     *     decaissements: int,
     *     transferts: int,
     *     transfertsEntrants: int,
     *     transfertsSortants: int,
     *     soldeNet: int,
     *     nombre: int
     * }
     */


    /**
     * Retourne les mouvements validés d’une caisse
     * pour une période donnée.
     *
     * @return MouvementTresorerie[]
     */
    public function findJournalCompte(
        int $compteId,
        \DateTimeImmutable $dateDebut,
        \DateTimeImmutable $dateFin
    ): array {
        return $this->createQueryBuilder('m')
            ->leftJoin('m.compteSource', 'source')
            ->addSelect('source')
            ->leftJoin('m.compteDestination', 'destination')
            ->addSelect('destination')
            ->leftJoin('m.agent', 'agent')
            ->addSelect('agent')
            ->andWhere('m.statut = :statut')
            ->andWhere(
                '(source.id = :compteId
            OR destination.id = :compteId)'
            )
            ->andWhere('m.dateOperation >= :dateDebut')
            ->andWhere('m.dateOperation <= :dateFin')
            ->setParameter(
                'statut',
                MouvementTresorerie::STATUT_VALIDE
            )
            ->setParameter('compteId', $compteId)
            ->setParameter('dateDebut', $dateDebut)
            ->setParameter('dateFin', $dateFin)
            ->orderBy('m.dateOperation', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calcule tous les mouvements validés antérieurs
     * au début de la période.
     *
     * Le résultat permet de calculer le solde d’ouverture.
     *
     * @return array{
     *     entrees: int,
     *     sorties: int
     * }
     */
    public function calculerMouvementsAvantDate(
        int $compteId,
        \DateTimeImmutable $dateDebut
    ): array {
        $resultat = $this->createQueryBuilder('m')
            ->select(
                '
            COALESCE(
                SUM(
                    CASE
                        WHEN destination.id = :compteId
                        THEN m.montant
                        ELSE 0
                    END
                ),
                0
            ) AS entrees,
            COALESCE(
                SUM(
                    CASE
                        WHEN source.id = :compteId
                        THEN m.montant
                        ELSE 0
                    END
                ),
                0
            ) AS sorties
            '
            )
            ->leftJoin('m.compteSource', 'source')
            ->leftJoin('m.compteDestination', 'destination')
            ->andWhere('m.statut = :statut')
            ->andWhere(
                '(source.id = :compteId
            OR destination.id = :compteId)'
            )
            ->andWhere('m.dateOperation < :dateDebut')
            ->setParameter(
                'statut',
                MouvementTresorerie::STATUT_VALIDE
            )
            ->setParameter('compteId', $compteId)
            ->setParameter('dateDebut', $dateDebut)
            ->getQuery()
            ->getSingleResult();

        return [
            'entrees' => (int) $resultat['entrees'],
            'sorties' => (int) $resultat['sorties'],
        ];
    }

    /**
     * Prépare toutes les lignes du journal avec
     * le solde progressif.
     *
     * @return array{
     *     lignes: array<int, array{
     *         mouvement: MouvementTresorerie,
     *         entree: int,
     *         sortie: int,
     *         solde: int
     *     }>,
     *     soldeOuverture: int,
     *     totalEntrees: int,
     *     totalSorties: int,
     *     soldeCloture: int,
     *     nombre: int
     * }
     */
    public function construireJournalCompte(
        CompteTresorerie $compte,
        \DateTimeImmutable $dateDebut,
        \DateTimeImmutable $dateFin
    ): array {
        if ($compte->getId() === null) {
            throw new \InvalidArgumentException(
                'Le compte de trésorerie doit être enregistré.'
            );
        }

        $avantPeriode = $this->calculerMouvementsAvantDate(
            $compte->getId(),
            $dateDebut
        );

        $soldeOuverture =
            $compte->getSoldeInitial()
            + $avantPeriode['entrees']
            - $avantPeriode['sorties'];

        $mouvements = $this->findJournalCompte(
            $compte->getId(),
            $dateDebut,
            $dateFin
        );

        $lignes = [];
        $soldeProgressif = $soldeOuverture;
        $totalEntrees = 0;
        $totalSorties = 0;

        foreach ($mouvements as $mouvement) {
            $entree = 0;
            $sortie = 0;
            $montant = (int) $mouvement->getMontant();

            $sourceId = $mouvement
                ->getCompteSource()
                ?->getId();

            $destinationId = $mouvement
                ->getCompteDestination()
                ?->getId();

            /*
         * Un encaissement ou un transfert entrant
         * crédite le compte.
         */
            if ($destinationId === $compte->getId()) {
                $entree = $montant;
            }

            /*
         * Un décaissement ou un transfert sortant
         * débite le compte.
         */
            if ($sourceId === $compte->getId()) {
                $sortie = $montant;
            }

            $totalEntrees += $entree;
            $totalSorties += $sortie;

            $soldeProgressif += $entree - $sortie;

            $lignes[] = [
                'mouvement' => $mouvement,
                'entree' => $entree,
                'sortie' => $sortie,
                'solde' => $soldeProgressif,
            ];
        }

        return [
            'lignes' => $lignes,
            'soldeOuverture' => $soldeOuverture,
            'totalEntrees' => $totalEntrees,
            'totalSorties' => $totalSorties,
            'soldeCloture' => $soldeProgressif,
            'nombre' => count($lignes),
        ];
    }

    public function calculerTotaux(
        array $mouvements,
        ?int $compteId = null
    ): array {
        $totaux = [
            'encaissements' => 0,
            'decaissements' => 0,
            'transferts' => 0,
            'transfertsEntrants' => 0,
            'transfertsSortants' => 0,
            'soldeNet' => 0,
            'nombre' => count($mouvements),
        ];

        foreach ($mouvements as $mouvement) {
            // Les mouvements annulés ou en attente
            // ne doivent pas influencer les montants.
            if ($mouvement->getStatut() !== 'valide') {
                continue;
            }

            $montant = (int) $mouvement->getMontant();

            if ($mouvement->getType() === 'encaissement') {
                $totaux['encaissements'] += $montant;

                continue;
            }

            if ($mouvement->getType() === 'decaissement') {
                $totaux['decaissements'] += $montant;

                continue;
            }

            if ($mouvement->getType() !== 'transfert') {
                continue;
            }

            $totaux['transferts'] += $montant;

            if ($compteId === null) {
                continue;
            }

            $sourceId = $mouvement
                ->getCompteSource()
                ?->getId();

            $destinationId = $mouvement
                ->getCompteDestination()
                ?->getId();

            if ($destinationId === $compteId) {
                $totaux['transfertsEntrants'] += $montant;
            }

            if ($sourceId === $compteId) {
                $totaux['transfertsSortants'] += $montant;
            }
        }

        if ($compteId !== null) {
            $totaux['soldeNet'] =
                $totaux['encaissements']
                + $totaux['transfertsEntrants']
                - $totaux['decaissements']
                - $totaux['transfertsSortants'];
        } else {
            // Un transfert interne n’affecte pas
            // le solde global de la trésorerie.
            $totaux['soldeNet'] =
                $totaux['encaissements']
                - $totaux['decaissements'];
        }

        return $totaux;
    }
    /**
     * Retourne les mouvements bancaires validés,
     * compris dans la période et non encore rapprochés.
     *
     * @return MouvementTresorerie[]
     */
    public function findMouvementsARapprocher(
        CompteTresorerie $compte,
        \DateTimeImmutable $dateDebut,
        \DateTimeImmutable $dateFin
    ): array {
        if ($compte->getId() === null) {
            throw new \InvalidArgumentException(
                'Le compte bancaire doit être enregistré.'
            );
        }

        if ($compte->getType() !== CompteTresorerie::TYPE_BANQUE) {
            throw new \InvalidArgumentException(
                'Le compte sélectionné doit être un compte bancaire.'
            );
        }

        if ($dateFin < $dateDebut) {
            throw new \InvalidArgumentException(
                'La date de fin doit être postérieure ou égale à la date de début.'
            );
        }

        /*
     * Sous-requête contenant les identifiants
     * des mouvements déjà associés à un rapprochement.
     */
        $mouvementsDejaRapproches = $this
            ->getEntityManager()
            ->createQueryBuilder()
            ->select(
                'IDENTITY(ligne.mouvementTresorerie)'
            )
            ->from(
                LigneRapprochementBancaire::class,
                'ligne'
            );

        return $this->createQueryBuilder('m')
            ->leftJoin('m.compteSource', 'source')
            ->addSelect('source')
            ->leftJoin('m.compteDestination', 'destination')
            ->addSelect('destination')
            ->leftJoin('m.agent', 'agent')
            ->addSelect('agent')

            // Seulement les mouvements validés.
            ->andWhere('m.statut = :statut')

            // Le compte est la source ou la destination.
            ->andWhere(
                '(source.id = :compteId
            OR destination.id = :compteId)'
            )

            // Période du rapprochement.
            ->andWhere('m.dateOperation >= :dateDebut')
            ->andWhere('m.dateOperation <= :dateFin')

            // Exclusion des mouvements déjà rapprochés.
            ->andWhere(
                $this->createQueryBuilder('m')
                    ->expr()
                    ->notIn(
                        'm.id',
                        $mouvementsDejaRapproches->getDQL()
                    )
            )

            ->setParameter(
                'statut',
                MouvementTresorerie::STATUT_VALIDE
            )
            ->setParameter(
                'compteId',
                $compte->getId()
            )
            ->setParameter(
                'dateDebut',
                $dateDebut->setTime(0, 0, 0)
            )
            ->setParameter(
                'dateFin',
                $dateFin->setTime(23, 59, 59)
            )
            ->orderBy('m.dateOperation', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
