<?php

namespace App\Repository;

use App\Entity\Entreprise;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Entreprise>
 */
class EntrepriseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Entreprise::class);
    }

    /**
     * @return list<Entreprise>
     */
    public function dansLOrdre(): array
    {
        return $this->findBy([], ['ordre' => 'ASC', 'raisonSociale' => 'ASC']);
    }

    public function parCode(?string $code): ?Entreprise
    {
        return $code === null || $code === '' ? null : $this->findOneBy(['code' => $code]);
    }

    /**
     * Choix « Émetteur » des formulaires de facture et de devis : les
     * entreprises actives, plus celle déjà choisie si elle est désactivée.
     *
     * @return array<string, string> libellé => code
     */
    public function choixEmetteurs(?string $codeActuel = null): array
    {
        $choix = [];

        foreach ($this->dansLOrdre() as $entreprise) {
            if ($entreprise->isActif() || $entreprise->getCode() === $codeActuel) {
                $choix[$entreprise->getLibelle()] = $entreprise->getCode();
            }
        }

        return $choix;
    }
}
