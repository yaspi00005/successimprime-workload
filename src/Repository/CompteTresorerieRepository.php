<?php

namespace App\Repository;

use App\Entity\CompteTresorerie;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CompteTresorerie>
 */
class CompteTresorerieRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CompteTresorerie::class);
    }

    /**
     * Requête utilisée par la liste principale du CRUD.
     */
    public function creerQueryBuilderListe(
        ?string $recherche = null,
        ?string $type = null,
        ?bool $actif = null
    ): QueryBuilder {
        $qb = $this->createQueryBuilder('compte')
            ->orderBy('compte.actif', 'DESC')
            ->addOrderBy('compte.nom', 'ASC');

        $recherche = trim((string) $recherche);

        if ($recherche !== '') {
            $qb
                ->andWhere(
                    'LOWER(compte.code) LIKE :recherche
                    OR LOWER(compte.nom) LIKE :recherche
                    OR LOWER(compte.numeroCompte) LIKE :recherche
                    OR LOWER(compte.nomBanque) LIKE :recherche'
                )
                ->setParameter(
                    'recherche',
                    '%' . mb_strtolower($recherche) . '%'
                );
        }

        if (
            $type !== null
            && in_array(
                $type,
                CompteTresorerie::getTypesDisponibles(),
                true
            )
        ) {
            $qb
                ->andWhere('compte.type = :type')
                ->setParameter('type', $type);
        }

        if ($actif !== null) {
            $qb
                ->andWhere('compte.actif = :actif')
                ->setParameter('actif', $actif);
        }

        return $qb;
    }

    /**
     * Comptes actifs proposés lors d'un paiement.
     *
     * @return CompteTresorerie[]
     */
    public function findActifs(): array
    {
        return $this->createQueryBuilder('compte')
            ->andWhere('compte.actif = :actif')
            ->setParameter('actif', true)
            ->orderBy('compte.type', 'ASC')
            ->addOrderBy('compte.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Comptes actifs correspondant à un type précis.
     *
     * @return CompteTresorerie[]
     */
    public function findActifsParType(string $type): array
    {
        if (
            !in_array(
                $type,
                CompteTresorerie::getTypesDisponibles(),
                true
            )
        ) {
            return [];
        }

        return $this->createQueryBuilder('compte')
            ->andWhere('compte.type = :type')
            ->andWhere('compte.actif = :actif')
            ->setParameter('type', $type)
            ->setParameter('actif', true)
            ->orderBy('compte.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Comptes dont la validation est automatique :
     * caisse, Orange Money et Wave.
     *
     * @return CompteTresorerie[]
     */
    public function findComptesValidationAutomatique(): array
    {
        return $this->createQueryBuilder('compte')
            ->andWhere('compte.type IN (:types)')
            ->andWhere('compte.actif = :actif')
            ->setParameter('types', [
                CompteTresorerie::TYPE_CAISSE,
                CompteTresorerie::TYPE_ORANGE_MONEY,
                CompteTresorerie::TYPE_WAVE,
            ])
            ->setParameter('actif', true)
            ->orderBy('compte.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Comptes bancaires nécessitant une validation manuelle.
     *
     * @return CompteTresorerie[]
     */
    public function findComptesBancairesActifs(): array
    {
        return $this->findActifsParType(
            CompteTresorerie::TYPE_BANQUE
        );
    }

    public function codeExiste(
        string $code,
        ?int $idExclu = null
    ): bool {
        $qb = $this->createQueryBuilder('compte')
            ->select('COUNT(compte.id)')
            ->andWhere('compte.code = :code')
            ->setParameter('code', strtoupper(trim($code)));

        if ($idExclu !== null) {
            $qb
                ->andWhere('compte.id != :idExclu')
                ->setParameter('idExclu', $idExclu);
        }

        return (int) $qb
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }

    /**
     * Comptes qu'un utilisateur peut choisir pour un encaissement
     * manuel (ex. ajout au solde client, pour créditer le compte où
     * l'argent est physiquement resté) -- même règle que le
     * formulaire de paiement (PaiementsType) : un admin voit les
     * comptes Admin et partagés ; un agent voit sa caisse
     * personnelle, les comptes partagés et les banques.
     *
     * @return CompteTresorerie[]
     */
    public function trouverDisponiblesPour(
        User $utilisateur,
        bool $estAdmin
    ): array {
        $qb = $this->createQueryBuilder('compte')
            ->andWhere('compte.actif = :actif')
            ->setParameter('actif', true)
            ->orderBy('compte.nom', 'ASC');

        if ($estAdmin) {
            return $qb
                ->andWhere('compte.portee IN (:portees)')
                ->setParameter('portees', [
                    CompteTresorerie::PORTEE_ADMIN,
                    CompteTresorerie::PORTEE_PARTAGEE,
                ])
                ->getQuery()
                ->getResult();
        }

        return $qb
            ->andWhere(
                '
                compte.portee = :partagee
                OR
                (
                    compte.portee = :personnelle
                    AND
                    compte.proprietaire = :utilisateur
                )
                OR
                compte.type = :typeBanque
                '
            )
            ->setParameter('partagee', CompteTresorerie::PORTEE_PARTAGEE)
            ->setParameter('personnelle', CompteTresorerie::PORTEE_PERSONNELLE)
            ->setParameter('utilisateur', $utilisateur)
            ->setParameter('typeBanque', CompteTresorerie::TYPE_BANQUE)
            ->getQuery()
            ->getResult();
    }

    public function calculerSoldeTotalActif(): int
    {
        return (int) $this->createQueryBuilder('compte')
            ->select('COALESCE(SUM(compte.soldeActuel), 0)')
            ->andWhere('compte.actif = :actif')
            ->setParameter('actif', true)
            ->getQuery()
            ->getSingleScalarResult();
    }
}