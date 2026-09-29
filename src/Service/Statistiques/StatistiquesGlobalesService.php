<?php

namespace App\Service\Statistiques;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\MouvementTresorerie;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ventes par agent, encaissements par caissiere, production et
 * rendement machine, top produits -- utilise a la fois par la page
 * "Statistiques globales" et par l'onglet Accueil.
 */
final class StatistiquesGlobalesService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @return list<array{nom: string, nombre: int, montant: int}>
     */
    public function ventesParAgent(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $commandes = $this->entityManager
            ->getRepository(Commandes::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.agents', 'agent')
            ->addSelect('agent')
            ->leftJoin('c.commandesDetails', 'detail')
            ->addSelect('detail')
            ->andWhere('c.dateCommande BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();

        $parAgent = [];

        foreach ($commandes as $commande) {
            if (!$commande instanceof Commandes || $commande->getStatutTravaux() === 'annulee') {
                continue;
            }

            $agent = $commande->getAgents();
            $cle = $agent?->getId() ?? 0;

            $parAgent[$cle] ??= [
                'nom' => $agent?->getUsername() ?? 'Non attribué',
                'nombre' => 0,
                'montant' => 0,
            ];

            ++$parAgent[$cle]['nombre'];
            $parAgent[$cle]['montant'] += (int) $commande->getTotalTtc();
        }

        usort($parAgent, static fn (array $a, array $b): int => $b['montant'] <=> $a['montant']);

        return array_values($parAgent);
    }

    /**
     * Basé sur MouvementTresorerie (et non Paiements) : un
     * encaissement peut être saisi directement dans le journal de
     * caisse sans passer par une commande.
     *
     * @return list<array{nom: string, nombre: int, montant: int}>
     */
    public function encaissementsParCaissiere(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $mouvements = $this->entityManager
            ->getRepository(MouvementTresorerie::class)
            ->createQueryBuilder('m')
            ->leftJoin('m.agent', 'utilisateur')
            ->addSelect('utilisateur')
            ->andWhere('m.dateOperation BETWEEN :debut AND :fin')
            ->andWhere('m.type = :type')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('type', MouvementTresorerie::TYPE_ENCAISSEMENT)
            ->getQuery()
            ->getResult();

        $parCaissiere = [];

        foreach ($mouvements as $mouvement) {
            if (!$mouvement instanceof MouvementTresorerie || !$mouvement->isValide()) {
                continue;
            }

            $utilisateur = $mouvement->getAgent();
            $cle = $utilisateur?->getId() ?? 0;

            $parCaissiere[$cle] ??= [
                'nom' => $utilisateur?->getUsername() ?? 'Non attribué',
                'nombre' => 0,
                'montant' => 0,
            ];

            ++$parCaissiere[$cle]['nombre'];
            $parCaissiere[$cle]['montant'] += (int) $mouvement->getMontant();
        }

        usort($parCaissiere, static fn (array $a, array $b): int => $b['montant'] <=> $a['montant']);

        return array_values($parCaissiere);
    }

    /**
     * @return list<array{nom: string, nombre: int}>
     */
    public function productionParAgent(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $details = $this->entityManager
            ->getRepository(CommandesDetails::class)
            ->createQueryBuilder('d')
            ->leftJoin('d.productionTermineePar', 'utilisateur')
            ->addSelect('utilisateur')
            ->andWhere('d.productionTermineeLe BETWEEN :debut AND :fin')
            ->andWhere('d.statutProduction != :annulee')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE)
            ->getQuery()
            ->getResult();

        $parAgent = [];

        foreach ($details as $detail) {
            if (!$detail instanceof CommandesDetails) {
                continue;
            }

            $utilisateur = $detail->getProductionTermineePar();
            $cle = $utilisateur?->getId() ?? 0;

            $parAgent[$cle] ??= [
                'nom' => $utilisateur?->getUsername() ?? 'Non attribué',
                'nombre' => 0,
            ];

            ++$parAgent[$cle]['nombre'];
        }

        usort($parAgent, static fn (array $a, array $b): int => $b['nombre'] <=> $a['nombre']);

        return array_values($parAgent);
    }

    /**
     * @return list<array{nom: string, nombre: int, quantite: float}>
     */
    public function rendementParMachine(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $details = $this->entityManager
            ->getRepository(CommandesDetails::class)
            ->createQueryBuilder('d')
            ->leftJoin('d.machine', 'machine')
            ->addSelect('machine')
            ->andWhere('d.productionTermineeLe BETWEEN :debut AND :fin')
            ->andWhere('d.statutProduction != :annulee')
            ->andWhere('d.machine IS NOT NULL')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE)
            ->getQuery()
            ->getResult();

        $parMachine = [];

        foreach ($details as $detail) {
            if (!$detail instanceof CommandesDetails) {
                continue;
            }

            $machine = $detail->getMachine();

            if ($machine === null) {
                continue;
            }

            $cle = $machine->getId();

            $parMachine[$cle] ??= [
                'nom' => $machine->getNom() ?? ('Machine #' . $cle),
                'nombre' => 0,
                'quantite' => 0.0,
            ];

            ++$parMachine[$cle]['nombre'];
            $parMachine[$cle]['quantite'] += $detail->getSurfaceTotale();
        }

        usort($parMachine, static fn (array $a, array $b): int => $b['nombre'] <=> $a['nombre']);

        return array_values($parMachine);
    }

    /**
     * @return list<array{nom: string, nombre: int, quantite: int}>
     */
    public function topProduits(\DateTimeImmutable $debut, \DateTimeImmutable $fin, int $limite = 15): array
    {
        $details = $this->entityManager
            ->getRepository(CommandesDetails::class)
            ->createQueryBuilder('d')
            ->leftJoin('d.produit', 'produit')
            ->addSelect('produit')
            ->leftJoin('d.commande', 'commande')
            ->addSelect('commande')
            ->andWhere('commande.dateCommande BETWEEN :debut AND :fin')
            ->andWhere('d.produit IS NOT NULL')
            ->andWhere('d.statutProduction != :annulee')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE)
            ->getQuery()
            ->getResult();

        $parProduit = [];

        foreach ($details as $detail) {
            if (!$detail instanceof CommandesDetails) {
                continue;
            }

            $commande = $detail->getCommande();

            if ($commande !== null && $commande->getStatutTravaux() === 'annulee') {
                continue;
            }

            $produit = $detail->getProduit();

            if ($produit === null) {
                continue;
            }

            $cle = $produit->getId();

            $parProduit[$cle] ??= [
                'nom' => $produit->getNom() ?? ('Produit #' . $cle),
                'nombre' => 0,
                'quantite' => 0,
            ];

            ++$parProduit[$cle]['nombre'];
            $parProduit[$cle]['quantite'] += $detail->getQuantite();
        }

        usort($parProduit, static fn (array $a, array $b): int => $b['nombre'] <=> $a['nombre']);

        return array_slice(array_values($parProduit), 0, $limite);
    }
}
