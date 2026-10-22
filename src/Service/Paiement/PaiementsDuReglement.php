<?php

namespace App\Service\Paiement;

use App\Entity\Paiements;
use App\Entity\ReglementEnLigne;
use App\Entity\ReglementEnLignePart;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Argent reçu pour un règlement de plusieurs factures (« Factures et
 * paiements ») : un paiement « en attente » par commande, jamais validé
 * ici. La part Orange Money porte ses frais (comme à la caisse) et une
 * référence « OM <transaction Orange> n/N » : une par paiement, pour que
 * « Référence déjà utilisée » ne bloque pas la validation au local. L'avoir
 * utilisé devient un paiement « solde client » en attente, « AV <règlement> ».
 *
 * Appelé par OrangeMoney à la confirmation d'Orange (une seule fois : une
 * notification rejouée ne trouve plus la transaction ouverte), ou tout de
 * suite pour un règlement payé par l'avoir seul.
 */
final class PaiementsDuReglement
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AvoirClient $avoirs,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Crée les paiements en attente et marque le règlement reçu. Ne flushe
     * pas. Un règlement déjà reçu ne crée rien de plus.
     *
     * @return ?Paiements le premier paiement du règlement (Orange Money d'abord)
     */
    public function creer(ReglementEnLigne $reglement, ?string $txnid, \DateTimeImmutable $maintenant): ?Paiements
    {
        if ($reglement->estRecu()) {
            return self::premier($reglement);
        }
        $parts = $reglement->getParts();
        $avecOm = array_values(array_filter($parts, static fn (ReglementEnLignePart $p): bool => $p->getMontantOm() > 0));
        $avecAvoir = array_values(array_filter($parts, static fn (ReglementEnLignePart $p): bool => $p->getAvoir() > 0));

        // L'avoir a pu servir ailleurs entre-temps (l'imprimerie l'a utilisé en caisse) : on ne le promet pas deux fois.
        $avoirOk = [] === $avecAvoir || $this->avoirs->disponible($reglement->getClient(), $maintenant, $reglement) >= $reglement->getAvoir();
        if (!$avoirOk) {
            $this->logger?->warning('Règlement en ligne {reference} : avoir plus disponible, seule la part Orange Money est enregistrée.', ['reference' => $reglement->getReference()]);
        }

        $auteur = null !== $reglement->getCompte() ? ' par '.$reglement->getCompte()->getNomAffiche() : '';
        foreach ($parts as $part) {
            $om = null;
            $avoir = null;
            $commande = $part->getCommande();
            if ($part->getMontantOm() > 0) {
                $n = array_search($part, $avecOm, true) + 1;
                $om = (new Paiements())
                    ->setCommande($commande)
                    ->setMontant($part->getMontantOm())
                    ->setFondsSoutienInclus($part->getFondsSoutien() > 0)
                    ->setMontantFondsSoutien($part->getFondsSoutien())
                    ->setFraisRetraitInclus($part->getFraisRetrait() > 0)
                    ->setMontantFraisRetrait($part->getFraisRetrait())
                    ->setMode(Paiements::MODE_ORANGE_MONEY)
                    ->setDate($maintenant)
                    ->setReference(mb_substr(self::reference('OM '.($txnid ?? $reglement->getReference()), $n, \count($avecOm)), 0, 100))
                    ->setObservation(\sprintf(
                        'Payé en ligne depuis l\'espace client%s (Orange Money, règlement %s de %d élément%s).',
                        $auteur,
                        $reglement->getReference(),
                        \count($parts),
                        \count($parts) > 1 ? 's' : '',
                    ));
                $om->setFacture($part->getFacture());
                $commande->addPaiement($om);
                $this->em->persist($om);
            }
            if ($part->getAvoir() > 0 && $avoirOk) {
                $n = array_search($part, $avecAvoir, true) + 1;
                $avoir = (new Paiements())
                    ->setCommande($commande)
                    ->setMontant($part->getAvoir())
                    ->setMode(Paiements::MODE_SOLDE_CLIENT)
                    ->setDate($maintenant)
                    ->setReference(mb_substr(self::reference('AV '.$reglement->getReference(), $n, \count($avecAvoir)), 0, 100))
                    ->setObservation(\sprintf('Avoir de l\'imprimerie utilisé en ligne depuis l\'espace client%s (règlement %s) : à retirer du solde du client à la validation.', $auteur, $reglement->getReference()));
                $avoir->setFacture($part->getFacture());
                $commande->addPaiement($avoir);
                $this->em->persist($avoir);
            }
            $part->rattacherPaiements($om, $avoir);
        }
        $reglement->marquerRecu($maintenant);

        return self::premier($reglement);
    }

    /** « OM MP2610… 2/3 » : chaque paiement d'un même règlement a sa référence. */
    public static function reference(string $base, int $rang, int $nombre): string
    {
        return $nombre > 1 ? \sprintf('%s %d/%d', $base, $rang, $nombre) : $base;
    }

    private static function premier(ReglementEnLigne $reglement): ?Paiements
    {
        foreach ($reglement->getParts() as $p) {
            if (null !== $p->getPaiementOm()) {
                return $p->getPaiementOm();
            }
        }
        foreach ($reglement->getParts() as $p) {
            if (null !== $p->getPaiementAvoir()) {
                return $p->getPaiementAvoir();
            }
        }

        return null;
    }
}
