<?php

namespace App\Service\Paiement;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CompteTresorerie;
use App\Entity\Factures;
use App\Entity\Paiements;
use App\Entity\User;
use App\Service\PaiementService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Un montant reçu à la caisse pour plusieurs commandes du même client
 * (docs/finances.md, « Encaisser plusieurs commandes ») : le client
 * apporte 200 000 F pour 250 000 F dus, la caisse coche ses commandes et
 * le montant se répartit, la plus ancienne d'abord. Un paiement par
 * commande (la comptabilité reste par commande et par facture), reliés
 * par une même référence de groupe sur un seul reçu. Le surplus va sur le
 * compte du client ou se rend.
 */
final class EncaissementGroupe
{
    /** Préfixe de la référence qui relie les paiements d'un même encaissement. */
    public const PREFIXE = 'G-';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaiementService $paiements,
        private readonly SoldeClient $soldeClient,
    ) {
    }

    /**
     * Répartit le montant reçu sur les restes dus, dans l'ordre donné (la
     * plus ancienne d'abord) : chaque commande est soldée tant que
     * l'argent suffit, la suivante reçoit ce qui reste.
     *
     * @param array<int, int> $restes reste dû par commande (id => reste), dans l'ordre
     *
     * @return array{parts: array<int, int>, surplus: int}
     */
    public static function repartir(int $montant, array $restes): array
    {
        $parts = [];
        $reste = max(0, $montant);
        foreach ($restes as $id => $du) {
            if ($reste <= 0) {
                break;
            }
            $part = min($reste, max(0, $du));
            if ($part > 0) {
                $parts[$id] = $part;
                $reste -= $part;
            }
        }

        return ['parts' => $parts, 'surplus' => $reste];
    }

    /**
     * Contrôle les parts saisies à la caisse : commandes de ce client,
     * jamais plus que leur reste, total égal au montant reçu moins le
     * surplus. Le serveur ne fait confiance ni au calcul de la page ni aux
     * montants retouchés à la main.
     *
     * @param array<int, int>                $parts   commande id => montant
     * @param array<int, array{reste: int}> $restes  commande id => reste dû (Caisse::commandesImpayees)
     *
     * @throws \DomainException
     */
    public static function verifier(array $parts, array $restes, int $recu, int $surplus): void
    {
        if ($recu <= 0) {
            throw new \DomainException('Indiquez le montant reçu du client.');
        }
        $parts = array_filter($parts, static fn (int $m): bool => $m > 0);
        if ([] === $parts) {
            throw new \DomainException('Cochez au moins une commande.');
        }
        foreach ($parts as $id => $montant) {
            if (!isset($restes[$id])) {
                throw new \DomainException('Une des commandes cochées n\'est plus à régler pour ce client : rechargez la caisse.');
            }
            if ($montant > $restes[$id]['reste']) {
                throw new \DomainException(\sprintf('Le montant de la commande dépasse son reste dû de %s F.', number_format($restes[$id]['reste'], 0, ',', ' ')));
            }
        }
        if (array_sum($parts) + max(0, $surplus) !== $recu) {
            throw new \DomainException(\sprintf('Les montants répartis (%s F) ne font pas le montant reçu (%s F).', number_format(array_sum($parts) + max(0, $surplus), 0, ',', ' '), number_format($recu, 0, ',', ' ')));
        }
    }

    /**
     * Enregistre l'encaissement : un paiement par commande, validé tout de
     * suite comme à la caisse (espèces, Orange Money, Wave), en attente
     * pour un virement ou un chèque, toujours en attente quand la caisse
     * est saisie en ligne. Le surplus va sur le compte du client si
     * demandé. Tout ou rien.
     *
     * @param array<int, int> $parts commande id => montant
     *
     * @return array{groupe: string, paiements: list<Paiements>, surplus: int}
     *
     * @throws \DomainException
     */
    public function encaisser(Clients $client, array $parts, string $mode, CompteTresorerie $compte, ?string $reference, int $surplusSurCompte, bool $enLigne, User $par): array
    {
        if (!\in_array($compte->getType(), Paiements::typesComptesPour($mode), true) || $compte->isOrangeMoneyEnLigne()) {
            throw new \DomainException(\sprintf('Le compte « %s » ne reçoit pas ce mode de paiement.', $compte->getNom()));
        }
        $reference = trim((string) $reference) ?: null;
        if (Paiements::MODE_CHEQUE === $mode && null === $reference) {
            throw new \DomainException('Le numéro du chèque est obligatoire.');
        }
        if ($surplusSurCompte > 0 && $enLigne) {
            throw new \DomainException('Le surplus ne se garde sur le compte du client qu\'à la caisse de l\'atelier : rendez la monnaie ou encaissez moins.');
        }
        $commandes = [];
        foreach (array_filter($parts, static fn (int $m): bool => $m > 0) as $id => $montant) {
            $commande = $this->em->getRepository(Commandes::class)->find($id);
            if (!$commande instanceof Commandes || $commande->getClients() !== $client) {
                throw new \DomainException('Une des commandes cochées n\'est pas à ce client.');
            }
            $commandes[] = [$commande, $montant];
        }
        $groupe = self::PREFIXE.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            $paiements = [];
            foreach ($commandes as [$commande, $montant]) {
                $paiement = (new Paiements())
                    ->setCommande($commande)
                    ->setMontant($montant)
                    ->setMode($mode)
                    ->setCompteTresorerie($compte)
                    ->setReference($reference ?? $groupe)
                    ->setEncaissePar($par)
                    ->setObservation(\sprintf('Encaissement groupé %s : %s F reçus pour %d commandes.', $groupe, number_format(array_sum(array_column($commandes, 1)) + $surplusSurCompte, 0, ',', ' '), \count($commandes)));
                if (Paiements::MODE_CHEQUE === $mode) {
                    $paiement->setNumeroCheque($reference);
                }
                $commande->addPaiement($paiement);
                $this->em->persist($paiement);
                if (!$enLigne && !$paiement->necessiteValidationManuelle()) {
                    // Même chemin que « Valider » d'un paiement : mouvement de trésorerie et compte crédité.
                    $this->paiements->valider($paiement, $par);
                }
                $this->actualiserCommande($commande, $paiement);
                $paiements[] = $paiement;
            }
            $this->em->flush();
            if ($surplusSurCompte > 0) {
                $this->soldeClient->deposer($client, $surplusSurCompte, $mode, $compte, $reference ?? $groupe, 0, $par, \sprintf('Surplus de l\'encaissement groupé %s.', $groupe));
            }
            $connexion->commit();
        } catch (\Throwable $e) {
            if ($connexion->isTransactionActive()) {
                $connexion->rollBack();
            }
            $this->em->clear();

            throw $e instanceof \LogicException || $e instanceof \InvalidArgumentException ? new \DomainException($e->getMessage(), 0, $e) : $e;
        }

        return ['groupe' => $groupe, 'paiements' => $paiements, 'surplus' => $surplusSurCompte];
    }

    /** Total payé, reste, état du paiement et facture comptabilisée de la commande. */
    private function actualiserCommande(Commandes $commande, Paiements $paiement): void
    {
        $paye = $commande->getTotalPaye();
        $commande->setTotalPaye($paye);
        $commande->setResteAPayer(max(0, (int) $commande->getTotalTtc() - $paye));
        $commande->actualiserStatutPaiement();
        $facture = $this->em->getRepository(Factures::class)->findOneBy(['commande' => $commande, 'comptabilisee' => true]);
        if ($facture instanceof Factures) {
            $facture->synchroniserPaiementsDepuisCommande();
            if ($paiement->estValide() && null === $paiement->getFacture()) {
                $paiement->setFacture($facture);
            }
        }
    }
}
