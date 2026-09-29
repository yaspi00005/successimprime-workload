<?php

namespace App\Service\Statistiques;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Service\EvolutionTemporelleService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Chiffre d'affaires par client et evolution des nouveaux clients.
 * Utilise par la page "Statistiques des clients" et par l'onglet
 * Accueil.
 */
final class StatistiquesClientsService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EvolutionTemporelleService $evolutionTemporelleService
    ) {
    }

    /**
     * @return list<array{nom: string, nombre: int, montant: int}>
     */
    public function caParClient(\DateTimeImmutable $debut, \DateTimeImmutable $fin, int $limite = 30): array
    {
        $commandes = $this->entityManager
            ->getRepository(Commandes::class)
            ->createQueryBuilder('c')
            ->leftJoin('c.clients', 'client')
            ->addSelect('client')
            ->andWhere('c.dateCommande BETWEEN :debut AND :fin')
            ->andWhere('c.deleted = false')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();

        $parClient = [];

        foreach ($commandes as $commande) {
            if (!$commande instanceof Commandes || $commande->getStatutTravaux() === 'annulee') {
                continue;
            }

            $client = $commande->getClients();
            $cle = $client?->getId() ?? 0;

            $parClient[$cle] ??= [
                'nom' => $client?->getNomComplet() ?? 'Client supprimé',
                'nombre' => 0,
                'montant' => 0,
            ];

            ++$parClient[$cle]['nombre'];
            $parClient[$cle]['montant'] += (int) $commande->getTotalTtc();
        }

        usort($parClient, static fn (array $a, array $b): int => $b['montant'] <=> $a['montant']);

        return array_slice(array_values($parClient), 0, $limite);
    }

    /**
     * @return array{labels: list<string>, valeurs: list<int>}
     */
    public function nouveauxClientsParPeriode(string $granularite): array
    {
        [$cles, $labels, $debutFenetre] = $this->evolutionTemporelleService->genererPaniers($granularite);

        $compteurs = array_fill_keys($cles, 0);

        $clients = $this->entityManager
            ->getRepository(Clients::class)
            ->createQueryBuilder('c')
            ->andWhere('c.createdAt >= :debut')
            ->setParameter('debut', $debutFenetre)
            ->getQuery()
            ->getResult();

        foreach ($clients as $client) {
            if (!$client instanceof Clients || $client->getCreatedAt() === null) {
                continue;
            }

            $cle = $this->evolutionTemporelleService->clePourDate($client->getCreatedAt(), $granularite);

            if (!isset($compteurs[$cle])) {
                continue;
            }

            ++$compteurs[$cle];
        }

        return [
            'labels' => $labels,
            'valeurs' => array_values($compteurs),
        ];
    }
}
