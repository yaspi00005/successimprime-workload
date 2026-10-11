<?php

namespace App\Service;

use App\Entity\Clients;
use App\Entity\ClotureCaisse;
use App\Entity\Commandes;
use App\Entity\CompteTresorerie;
use App\Entity\Paiements;
use App\Entity\User;
use App\Repository\MouvementTresorerieRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Caisse (maquette 28) : ce que les clients doivent, la caisse du jour
 * (fond, entrées, sorties, théorique) et les derniers encaissements.
 * L'encaissement lui-même passe par la route de paiement d'une commande,
 * qui crée le mouvement de trésorerie et met la facture à jour.
 */
final class Caisse
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MouvementTresorerieRepository $mouvements,
    ) {
    }

    /**
     * Clients qui doivent encore de l'argent, le plus gros solde d'abord.
     *
     * @return list<array{client: Clients, solde: int, commandes: int}>
     */
    public function clientsAvecSolde(): array
    {
        $parClient = [];
        foreach ($this->commandesImpayees() as $ligne) {
            $client = $ligne['commande']->getClients();
            if (null === $client) {
                continue;
            }
            $parClient[$client->getId()] ??= ['client' => $client, 'solde' => 0, 'commandes' => 0];
            $parClient[$client->getId()]['solde'] += $ligne['reste'];
            ++$parClient[$client->getId()]['commandes'];
        }
        usort($parClient, static fn (array $a, array $b): int => $b['solde'] <=> $a['solde']);

        return $parClient;
    }

    /**
     * Commandes du client avec un reste à payer, la plus ancienne d'abord.
     *
     * @return list<array{commande: Commandes, paye: int, reste: int, facture: ?string}>
     */
    public function commandesImpayees(?Clients $client = null): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('commande', 'client')
            ->from(Commandes::class, 'commande')
            ->leftJoin('commande.clients', 'client')
            ->andWhere('commande.statut = true')
            ->andWhere('commande.totalTtc > 0')
            ->orderBy('commande.id', 'ASC');
        if (null !== $client) {
            $qb->andWhere('commande.clients = :client')->setParameter('client', $client);
        }
        /** @var list<Commandes> $commandes */
        $commandes = $qb->getQuery()->getResult();
        if ([] === $commandes) {
            return [];
        }

        $payes = [];
        foreach ($this->em->createQueryBuilder()
            ->select('IDENTITY(paiement.commande) AS commande', 'SUM(paiement.montant) AS total')
            ->from(Paiements::class, 'paiement')
            ->andWhere('paiement.commande IN (:commandes)')
            ->andWhere('paiement.statut = :valide')
            ->setParameter('commandes', $commandes)
            ->setParameter('valide', Paiements::STATUT_VALIDE)
            ->groupBy('paiement.commande')
            ->getQuery()
            ->getArrayResult() as $ligne) {
            $payes[(int) $ligne['commande']] = (int) $ligne['total'];
        }

        $lignes = [];
        foreach ($commandes as $commande) {
            $paye = $payes[$commande->getId()] ?? 0;
            $reste = $commande->getTotalTtc() - $paye;
            if ($reste <= 0 || 'annulee' === $commande->getStatutTravaux()) {
                continue;
            }
            $facture = null;
            foreach ($commande->getFactures() as $f) {
                $facture = $f->getNumero() ?? $facture;
            }
            $lignes[] = ['commande' => $commande, 'paye' => $paye, 'reste' => $reste, 'facture' => $facture];
        }

        return $lignes;
    }

    /**
     * Caisse du jour de chaque compte espèces : fond (solde à
     * l'ouverture), encaissé, sorties et théorique, plus la clôture du jour.
     *
     * @param list<CompteTresorerie> $comptes
     *
     * @return list<array{compte: CompteTresorerie, fond: int, entrees: int, sorties: int, theorique: int, cloture: ?ClotureCaisse}>
     */
    public function caisseDuJour(array $comptes, \DateTimeImmutable $maintenant): array
    {
        $debut = $maintenant->setTime(0, 0);
        $fin = $maintenant->setTime(23, 59, 59);
        $resultat = [];
        foreach ($comptes as $compte) {
            if (CompteTresorerie::TYPE_CAISSE !== $compte->getType()) {
                continue;
            }
            $journal = $this->mouvements->construireJournalCompte($compte, $debut, $fin);
            $resultat[] = [
                'compte' => $compte,
                'fond' => (int) $journal['soldeOuverture'],
                'entrees' => (int) $journal['totalEntrees'],
                'sorties' => (int) $journal['totalSorties'],
                'theorique' => (int) $journal['soldeCloture'],
                'cloture' => $this->em->createQueryBuilder()
                    ->select('cloture')
                    ->from(ClotureCaisse::class, 'cloture')
                    ->andWhere('cloture.compte = :compte')
                    ->andWhere('cloture.clotureLe BETWEEN :debut AND :fin')
                    ->setParameter('compte', $compte)
                    ->setParameter('debut', $debut)
                    ->setParameter('fin', $fin)
                    ->orderBy('cloture.clotureLe', 'DESC')
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult(),
            ];
        }

        return $resultat;
    }

    /**
     * Encaissements du jour ; ceux de la personne seulement si elle ne
     * voit pas toute la caisse.
     *
     * @return list<Paiements>
     */
    public function encaissementsDuJour(\DateTimeImmutable $maintenant, ?User $seulement, int $nombre = 8): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('paiement', 'commande', 'client')
            ->from(Paiements::class, 'paiement')
            ->innerJoin('paiement.commande', 'commande')
            ->leftJoin('commande.clients', 'client')
            ->andWhere('paiement.date >= :debut')
            ->andWhere('paiement.statut IN (:statuts)')
            ->setParameter('debut', $maintenant->setTime(0, 0))
            ->setParameter('statuts', [Paiements::STATUT_VALIDE, Paiements::STATUT_EN_ATTENTE])
            ->orderBy('paiement.date', 'DESC')
            ->addOrderBy('paiement.id', 'DESC')
            ->setMaxResults($nombre);
        if (null !== $seulement) {
            $qb->andWhere('paiement.encaissePar = :moi')->setParameter('moi', $seulement);
        }

        return $qb->getQuery()->getResult();
    }
}
