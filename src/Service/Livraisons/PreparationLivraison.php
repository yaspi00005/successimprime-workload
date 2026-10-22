<?php

namespace App\Service\Livraisons;

use App\Entity\BonLivraison;
use App\Entity\BonLivraisonLigne;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\StockSorties;
use App\Entity\User;
use App\Service\StockService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les trois gestes du bureau pour livrer une commande prête : créer le bon
 * de livraison des lignes prêtes, le valider, le planifier dans la tournée
 * d'un livreur. Sortis de BonLivraisonController (creerDepuisCommande,
 * valider) et de TourneeController (planifier) sans rien changer à leur
 * effet, pour que le contrôle final (PD06, docs/production-colis.md) les
 * enchaîne en un bouton.
 */
final class PreparationLivraison
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockService $stock,
    ) {
    }

    /**
     * Bon de livraison des lignes « prêtes » de la commande, pour la quantité
     * pas encore livrée ni réservée dans un autre bon ouvert ; la livraison
     * programmée par le client y est reportée. Enregistré.
     *
     * @return array{bon: BonLivraison, lignes: int}
     */
    public function creerDepuisCommande(Commandes $commande, User $par): array
    {
        // Seulement PRETE_LIVRAISON : une ligne EN_LIVRAISON appartient déjà à un bon validé.
        $details = $this->em->getRepository(CommandesDetails::class)->createQueryBuilder('detail')
            ->andWhere('detail.commande = :commande')->andWhere('detail.statutProduction = :statut')
            ->setParameter('commande', $commande)->setParameter('statut', CommandesDetails::PRODUCTION_PRETE_LIVRAISON)
            ->orderBy('detail.id', 'ASC')
            ->getQuery()->getResult();
        if ($details === []) {
            throw new \LogicException('Aucune ligne de cette commande n’est actuellement prête à être livrée.');
        }

        $bon = new BonLivraison();
        $bon->setNumero($this->genererNumero())->setCommande($commande)->setCreePar($par);
        $nombreLignesAjoutees = 0;
        foreach ($details as $detail) {
            // Quantité commandée - déjà livrée - déjà placée dans un autre bon brouillon ou validé.
            $quantiteRestante = $detail->getQuantiteRestanteLivraison();
            if ($quantiteRestante <= 0) {
                continue;
            }
            $quantiteDisponible = max(0, $quantiteRestante - $this->quantiteReservee($detail));
            if ($quantiteDisponible <= 0) {
                continue;
            }
            $ligne = new BonLivraisonLigne();
            $ligne->setCommandeDetail($detail);
            $ligne->setQuantiteLivree($quantiteDisponible);
            $bon->addLigne($ligne);
            ++$nombreLignesAjoutees;
        }
        if ($nombreLignesAjoutees === 0) {
            throw new \LogicException('Aucune quantité n’est disponible pour un nouveau bon de livraison. Les quantités restantes sont peut-être déjà réservées dans un autre bon.');
        }

        // Livraison déjà programmée par le client dans son espace (maquette DB09) : jour, créneau, adresse, qui reçoit.
        ProgrammationLivraison::appliquer($commande, $bon);
        $this->em->persist($bon);
        $this->em->flush();

        return ['bon' => $bon, 'lignes' => $nombreLignesAjoutees];
    }

    /**
     * Valide le bon : stock des ventes directes (les lignes fabriquées l'ont
     * déjà sorti à la fin de la production), lignes prêtes passées « en
     * livraison ». Un seul enregistrement.
     */
    public function valider(BonLivraison $bon, User $par): void
    {
        if (!$bon->estBrouillon()) {
            throw new \LogicException('Seul un bon de livraison en brouillon peut être validé.');
        }
        if ($bon->getLignes()->isEmpty()) {
            throw new \LogicException('Le bon de livraison ne contient aucune ligne.');
        }
        foreach ($bon->getLignes() as $ligne) {
            $detail = $ligne->getCommandeDetail();
            if ($detail === null) {
                throw new \LogicException('Une ligne du bon de livraison n’est plus liée à son détail de commande.');
            }
            $quantiteLivraison = (float) $ligne->getQuantiteLivree();
            if ($quantiteLivraison <= 0) {
                throw new \LogicException(sprintf('La quantité de « %s » doit être supérieure à zéro.', $ligne->getDesignation()));
            }
            // Ligne fabriquée : aucune deuxième sortie de stock.
            if ($detail->isProductionNecessaire()) {
                continue;
            }
            $this->stock->consommerPourDetail($detail, StockSorties::ORIGINE_LIVRAISON, (string) $bon->getNumero(), $quantiteLivraison);
        }
        $bon->valider($par);
        foreach ($bon->getLignes() as $ligne) {
            $detail = $ligne->getCommandeDetail();
            if ($detail !== null && $detail->getStatutProduction() === CommandesDetails::PRODUCTION_PRETE_LIVRAISON) {
                $detail->marquerEnLivraison();
            }
        }
        $this->em->flush();
    }

    /**
     * Livreur, jour, créneau et rang dans la tournée. Après un échec, la
     * nouvelle planification remet la livraison dans la tournée : renvoie
     * true dans ce cas. Enregistré.
     */
    public function planifier(BonLivraison $bon, ?User $livreur, ?\DateTimeImmutable $jour, ?string $creneau, ?int $ordre): bool
    {
        $bon->setLivreur($livreur)
            ->setPrevuLe($jour)
            ->setCreneau((string) $creneau)
            ->setOrdreTournee($ordre !== null && $ordre > 0 ? $ordre : null);
        $replanifiee = $bon->etatSuivi() === BonLivraison::ETAT_ECHEC && $bon->getPrevuLe() !== null;
        if ($replanifiee) {
            $bon->reprendre();
        }
        $this->em->flush();

        return $replanifiee;
    }

    /** Quantité de la ligne déjà placée dans un bon brouillon ou validé (sauf $bonExclu, celui qu'on modifie). */
    public function quantiteReservee(CommandesDetails $detail, ?BonLivraison $bonExclu = null): int
    {
        $qb = $this->em->getRepository(BonLivraisonLigne::class)->createQueryBuilder('ligne')
            ->select('COALESCE(SUM(ligne.quantiteLivree), 0)')
            ->innerJoin('ligne.bonLivraison', 'bl')
            ->andWhere('ligne.commandeDetail = :detail')->andWhere('bl.statut IN (:statuts)')
            ->setParameter('detail', $detail)
            ->setParameter('statuts', [BonLivraison::STATUT_BROUILLON, BonLivraison::STATUT_VALIDE]);
        if ($bonExclu !== null && $bonExclu->getId() !== null) {
            $qb->andWhere('bl.id != :bonExclu')->setParameter('bonExclu', $bonExclu->getId());
        }

        return max(0, (int) $qb->getQuery()->getSingleScalarResult());
    }

    /** BL-AAAA-000123 : le suivant de l'année. */
    public function genererNumero(): string
    {
        $prefixe = 'BL-'.(new \DateTimeImmutable())->format('Y').'-';
        $dernier = $this->em->getRepository(BonLivraison::class)->createQueryBuilder('bl')
            ->andWhere('bl.numero LIKE :prefixe')->setParameter('prefixe', $prefixe.'%')
            ->orderBy('bl.numero', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        $prochain = 1;
        if ($dernier instanceof BonLivraison && $dernier->getNumero() !== null) {
            $prochain = ((int) substr($dernier->getNumero(), \strlen($prefixe))) + 1;
        }

        return sprintf('%s%06d', $prefixe, $prochain);
    }
}
