<?php

namespace App\Service\Paiement;

use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\ReglementEnLigne;
use App\Entity\ReglementEnLignePart;
use App\Entity\TransactionOrangeMoney;
use App\Service\EspaceClient\ElementAPayer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Paiement de plusieurs factures et acomptes en une fois (« Factures et
 * paiements », maquette FP03). Le serveur recalcule tout : ce qui est dû
 * (FacturesClient), l'avoir disponible (AvoirClient), la répartition et les
 * frais (RepartitionPaiement). Un seul passage chez Orange Money pour le
 * total ; à la réception, un paiement « en attente » par commande
 * (PaiementsDuReglement). Payé par l'avoir seul, le règlement est reçu
 * tout de suite, sans Orange.
 */
final class ReglementsEnLigne
{
    /** Un second clic dans ces minutes reprend le même lien Orange Money. */
    private const REPRISE_MINUTES = TransactionOrangeMoney::VALIDITE_MINUTES - 1;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly OrangeMoney $orangeMoney,
        private readonly PaiementsDuReglement $paiements,
        private readonly AvoirClient $avoirs,
    ) {
    }

    /**
     * Récapitulatif avant de payer : montants, avoir, frais.
     *
     * @param list<ElementAPayer> $elements choisis, la plus ancienne échéance d'abord
     *
     * @return array{total: int, avoir: int, omMax: int, om: int, frais: FraisOrangeMoney, parts: array<string, array{om: int, avoir: int, fondsSoutien: int, fraisRetrait: int}>, avoirDisponible: int}
     *
     * @throws \DomainException
     */
    public function plan(CompteClient $compte, array $elements, bool $avecAvoir, ?int $montantOm, \DateTimeImmutable $maintenant): array
    {
        $dus = [];
        foreach ($elements as $e) {
            $dus[$e->cle()] = $e->montant;
        }
        // Un second clic : l'avoir que réserve le premier, encore ouvert chez Orange, compte comme disponible.
        $disponible = $this->avoirs->disponible($compte->getClient(), $maintenant, $this->ouvert($compte->getClient(), $maintenant)['reglement'] ?? null);

        return RepartitionPaiement::planifier($dus, $disponible, $avecAvoir && $disponible > 0, $montantOm, $this->orangeMoney->parametres()) + ['avoirDisponible' => $disponible];
    }

    /**
     * Lance le règlement : le lien de la page Orange Money, ou null s'il est
     * payé par l'avoir seul (reçu tout de suite, en attente de la boutique).
     *
     * @param list<ElementAPayer>                                                                                            $elements
     * @param array{om: int, frais: FraisOrangeMoney, parts: array<string, array{om: int, avoir: int, fondsSoutien: int, fraisRetrait: int}>} $plan
     *
     * @return array{reglement: ReglementEnLigne, lien: ?string}
     *
     * @throws \DomainException message à montrer au client
     */
    public function demarrer(CompteClient $compte, array $elements, array $plan, \DateTimeImmutable $maintenant): array
    {
        if ($plan['om'] > 0 && !$this->orangeMoney->estConfigure()) {
            throw new \DomainException('Le paiement Orange Money en ligne n\'est pas encore ouvert : payez en boutique, ou utilisez votre avoir seul.');
        }
        $client = $compte->getClient();
        $reference = $plan['om'] > 0 ? OrangeMoney::nouvelOrderId($maintenant) : \sprintf('AV%s%s', $maintenant->format('ymdHis'), strtoupper(bin2hex(random_bytes(3))));
        $reglement = new ReglementEnLigne($reference, $client, $compte, $maintenant);
        $rang = 0;
        foreach ($elements as $e) {
            $p = $plan['parts'][$e->cle()] ?? null;
            if (null === $p) {
                continue;
            }
            $reglement->ajouterPart(new ReglementEnLignePart($reglement, $e->commande, $e->facture, $e->nature, $p['om'], $p['avoir'], $p['fondsSoutien'], $p['fraisRetrait'], ++$rang));
        }
        if ([] === $reglement->getParts()) {
            throw new \DomainException('Rien à payer.');
        }

        if ($plan['om'] > 0) {
            // Double clic : le même règlement, encore ouvert chez Orange, est repris.
            if (null !== ($lien = $this->lienOuvert($reglement, $maintenant))) {
                return $lien;
            }
            $this->em->persist($reglement);
            try {
                $transaction = $this->orangeMoney->demarrerReglement($reglement, $compte, $maintenant);
            } catch (\DomainException $e) {
                // Orange n'a rien ouvert : la tentative reste tracée, l'avoir n'est plus réservé.
                $reglement->abandonner();
                $this->em->flush();
                throw $e;
            }

            return ['reglement' => $reglement, 'lien' => $transaction->getLienPaiement()];
        }

        $this->em->persist($reglement);
        $this->paiements->creer($reglement, null, $maintenant);
        $this->em->flush();

        return ['reglement' => $reglement, 'lien' => null];
    }

    /** @return ?array{reglement: ReglementEnLigne, lien: string} */
    private function lienOuvert(ReglementEnLigne $nouveau, \DateTimeImmutable $maintenant): ?array
    {
        $ouvert = $this->ouvert($nouveau->getClient(), $maintenant);

        return null !== $ouvert && $ouvert['reglement']->empreinte() === $nouveau->empreinte() && $ouvert['reglement']->getTotalOm() === $nouveau->getTotalOm() ? $ouvert : null;
    }

    /**
     * Le dernier règlement du client parti chez Orange il y a moins de
     * REPRISE_MINUTES, dont le lien de paiement est encore valable.
     *
     * @return ?array{reglement: ReglementEnLigne, lien: string}
     */
    private function ouvert(Clients $client, \DateTimeImmutable $maintenant): ?array
    {
        $r = $this->em->getRepository(ReglementEnLigne::class)->findOneBy(['client' => $client, 'statut' => ReglementEnLigne::EN_COURS], ['id' => 'DESC']);
        if (null === $r || $r->getCreeLe() < $maintenant->modify(\sprintf('-%d minutes', self::REPRISE_MINUTES))) {
            return null;
        }
        $transaction = $this->em->getRepository(TransactionOrangeMoney::class)->findOneBy(['reglement' => $r]);

        return null !== $transaction && $transaction->estOuverte($maintenant) ? ['reglement' => $r, 'lien' => $transaction->getLienPaiement()] : null;
    }
}
