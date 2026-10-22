<?php

namespace App\Service\Facturation;

use App\Entity\Avoir;
use App\Entity\CommandesDetails;
use App\Entity\CompteTresorerie;
use App\Entity\Factures;
use App\Entity\ReclamationClient;
use App\Entity\User;
use App\Service\Paiement\SoldeClient;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Avoirs sur facture validée (maquette FD04, docs/facturation-documents.md).
 *
 * On choisit les lignes et les quantités à créditer (10 badges mal coupés
 * sur 200), la part de la remise de la facture suit. L'avoir validé va au
 * compte du client (SoldeClient::crediterAvoir), puis selon le choix :
 * - « Déduire de cette facture » : il paie le reste dû de la commande
 *   (paiement « solde client », aucun argent n'entre) ; le surplus reste
 *   en crédit ;
 * - « Garder pour une prochaine commande » : il reste en crédit ;
 * - « Rembourser » : l'argent sort de la caisse ou du compte Orange Money
 *   choisi (droit « Rendre l'argent d'un compte client »).
 *
 * Jamais plus que ce qui a été facturé, avoirs précédents déduits.
 */
final class AvoirsFactures
{
    /** Clé de la ligne « Livraison » (frais de livraison de la facture). */
    public const LIVRAISON = 'livraison';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SoldeClient $solde,
    ) {
    }

    /**
     * Les lignes que l'on peut créditer : quantité facturée, déjà créditée,
     * prix unitaire net (remises de ligne et finitions comprises).
     *
     * @return list<array{cle: string, ligne: ?int, designation: string, facture: float, credite: float, reste: float, total: int, prixUnitaire: int}>
     */
    public function lignes(Factures $facture): array
    {
        $deja = $this->dejaCredite($facture);
        $lignes = [];
        foreach ($facture->getCommande()?->getCommandesDetails() ?? [] as $l) {
            if (CommandesDetails::PRODUCTION_ANNULEE === $l->getStatutProduction() || $l->getTotalTtc() <= 0) {
                continue;
            }
            $qte = (float) max(1, $l->getQuantite());
            $cle = (string) $l->getId();
            $lignes[] = self::ligne($cle, $l->getId(), (string) $l->getDesignation(), $qte, $l->getTotalTtc(), $deja[$cle] ?? 0.0);
        }
        if ($facture->getFraisLivraison() > 0) {
            $lignes[] = self::ligne(self::LIVRAISON, null, 'Livraison', 1.0, $facture->getFraisLivraison(), $deja[self::LIVRAISON] ?? 0.0);
        }

        return $lignes;
    }

    /** Montant d'une quantité créditée sur une ligne : la ligne entière redonne exactement son total. */
    public static function montant(float $quantite, float $facture, int $total): int
    {
        if ($quantite <= 0 || $facture <= 0) {
            return 0;
        }

        return $quantite >= $facture ? $total : (int) round($total * $quantite / $facture);
    }

    /** Part de la remise de la facture sur un montant crédité. */
    public static function partRemise(int $montant, int $remiseFacture, int $totalHtFacture): int
    {
        if ($montant <= 0 || $remiseFacture <= 0 || $totalHtFacture <= 0) {
            return 0;
        }

        return (int) round($montant * min($remiseFacture, $totalHtFacture) / $totalHtFacture);
    }

    /** Total des avoirs déjà faits sur la facture. */
    public function totalAvoirs(Factures $facture): int
    {
        return (int) $this->em->createQueryBuilder()->select('COALESCE(SUM(a.total), 0)')->from(Avoir::class, 'a')
            ->where('a.facture = :f')->setParameter('f', $facture)->getQuery()->getSingleScalarResult();
    }

    /** @return list<Avoir> */
    public function avoirs(Factures $facture): array
    {
        return $this->em->getRepository(Avoir::class)->findBy(['facture' => $facture], ['id' => 'ASC']);
    }

    /**
     * Prépare l'avoir (sans rien enregistrer) : lignes, remise, total.
     *
     * @param array<string, float|int|string> $quantites clé de ligne => quantité à créditer
     *
     * @return array{lignes: list<array{ligne: ?int, designation: string, quantite: float, facture: float, prixUnitaire: int, montant: int}>, brut: int, remise: int, total: int}
     *
     * @throws \DomainException
     */
    public function calculer(Factures $facture, array $quantites): array
    {
        $lignes = [];
        $brut = 0;
        foreach ($this->lignes($facture) as $l) {
            $q = (float) str_replace([',', ' ', "\u{202F}", "\u{00A0}"], ['.', '', '', ''], (string) ($quantites[$l['cle']] ?? 0));
            if ($q <= 0) {
                continue;
            }
            if ($q > $l['reste'] + 0.0001) {
                throw new \DomainException(\sprintf('%s : au plus %s à créditer.', $l['designation'], self::nombre($l['reste'])));
            }
            $montant = self::montant($q + $l['credite'], $l['facture'], $l['total']) - self::montant($l['credite'], $l['facture'], $l['total']);
            $lignes[] = ['ligne' => $l['ligne'], 'designation' => $l['designation'], 'quantite' => $q, 'facture' => $l['facture'], 'prixUnitaire' => $l['prixUnitaire'], 'montant' => $montant];
            $brut += $montant;
        }
        $remise = self::partRemise($brut, $facture->getRemise(), $facture->getTotalHt());

        return ['lignes' => $lignes, 'brut' => $brut, 'remise' => $remise, 'total' => max(0, $brut - $remise)];
    }

    /**
     * Valide l'avoir : numéro, compte du client, puis déduction ou
     * remboursement. Tout ou rien.
     *
     * @param array<string, float|int|string> $quantites
     *
     * @throws \DomainException
     */
    public function valider(Factures $facture, array $quantites, string $motif, ?ReclamationClient $reclamation, string $suite, ?CompteTresorerie $compte, ?string $observation, User $par, \DateTimeImmutable $maintenant): Avoir
    {
        if (!$facture->estFacture() || !$facture->estEmise()) {
            throw new \DomainException('Un avoir se fait sur une facture validée.');
        }
        $commande = $facture->getCommande();
        $client = $commande?->getClients();
        if (null === $client || null === $commande) {
            throw new \DomainException('Cette facture n\'a pas de client : l\'avoir n\'a pas de compte où aller.');
        }
        $calcul = $this->calculer($facture, $quantites);
        if ($calcul['total'] <= 0) {
            throw new \DomainException('Choisissez au moins une ligne et une quantité à créditer.');
        }
        if ($calcul['total'] > $facture->getTotalTtc() - $this->totalAvoirs($facture)) {
            throw new \DomainException('L\'avoir dépasse ce qui reste facturé.');
        }
        if (null !== $reclamation && $reclamation->getClient()?->getId() !== $client->getId()) {
            $reclamation = null;
        }
        $avoir = (new Avoir())->setFacture($facture)->setClient($client)->setMotif($motif)->setSuite($suite)
            ->setReclamation($reclamation)->setObservation($observation)->setValidePar($par)->setCreeLe($maintenant)
            ->setLignes($calcul['lignes'], $calcul['remise']);
        if (Avoir::REMBOURSER === $suite) {
            if (null === $compte) {
                throw new \DomainException('Choisissez la caisse ou le compte d\'où l\'argent est rendu.');
            }
            $avoir->setCompteRemboursement($compte);
        }

        return $this->em->wrapInTransaction(function () use ($avoir, $facture, $commande, $client, $suite, $compte, $par, $maintenant): Avoir {
            $avoir->setNumero($this->prochainNumero($maintenant));
            $this->em->persist($avoir);
            $this->em->flush();
            $this->solde->crediterAvoir($client, $avoir->getTotal(), $avoir->getNumero(), $commande, $par);
            if (Avoir::DEDUIRE === $suite) {
                $deduit = min($avoir->getTotal(), max(0, $commande->getResteAPayer()));
                if ($deduit > 0) {
                    $paiement = $this->solde->utiliser($commande, $deduit, $par);
                    $paiement->setObservation(\sprintf('Avoir %s déduit de la facture %s.', $avoir->getNumero(), $facture->getNumero()));
                    $avoir->setDeduit($deduit);
                }
            } elseif (Avoir::REMBOURSER === $suite && null !== $compte) {
                $this->solde->rembourser($client, $avoir->getTotal(), $compte, \sprintf('Avoir %s sur la facture %s', $avoir->getNumero(), $facture->getNumero()), $par);
                $avoir->setRembourse($avoir->getTotal());
            }
            $facture->synchroniserPaiementsDepuisCommande();
            $this->em->flush();

            return $avoir;
        });
    }

    /** AV-2026-0032 : compteur par année, verrouillé le temps de la transaction. */
    private function prochainNumero(\DateTimeImmutable $le): string
    {
        $prefixe = 'AV-'.$le->format('Y').'-';
        $dernier = $this->em->createQueryBuilder()->select('a.numero')->from(Avoir::class, 'a')
            ->where('a.numero LIKE :p')->setParameter('p', $prefixe.'%')
            ->orderBy('a.numero', 'DESC')->setMaxResults(1)
            ->getQuery()->setLockMode(LockMode::PESSIMISTIC_WRITE)->getOneOrNullResult();
        $n = null === $dernier ? 0 : (int) substr((string) $dernier['numero'], \strlen($prefixe));

        return $prefixe.str_pad((string) ($n + 1), 4, '0', \STR_PAD_LEFT);
    }

    /** @return array<string, float> clé de ligne => quantité déjà créditée */
    private function dejaCredite(Factures $facture): array
    {
        $deja = [];
        foreach ($this->avoirs($facture) as $a) {
            foreach ($a->getLignes() as $l) {
                $cle = null === $l['ligne'] ? self::LIVRAISON : (string) $l['ligne'];
                $deja[$cle] = ($deja[$cle] ?? 0.0) + (float) $l['quantite'];
            }
        }

        return $deja;
    }

    /** @return array{cle: string, ligne: ?int, designation: string, facture: float, credite: float, reste: float, total: int, prixUnitaire: int} */
    private static function ligne(string $cle, ?int $id, string $designation, float $facture, int $total, float $credite): array
    {
        return [
            'cle' => $cle, 'ligne' => $id, 'designation' => $designation, 'facture' => $facture,
            'credite' => $credite, 'reste' => max(0.0, $facture - $credite), 'total' => $total,
            'prixUnitaire' => (int) round($total / max(0.0001, $facture)),
        ];
    }

    private static function nombre(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ' '), '0'), ',');
    }
}
