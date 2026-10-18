<?php

namespace App\Service\Clients;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\ProduitClient;
use App\Entity\User;
use App\Service\CommandeProduitClient;
use App\Service\EspaceClient\MesProduits;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Commander pour lui » (fiche client, onglet Produits, maquette FC03) :
 * l'employé commande un produit enregistré du client, au prix du produit,
 * comme le panier de l'espace client (même ligne, même aiguillage : un
 * fichier lié à la taille choisie va droit à l'impression). La commande
 * part « à confirmer » au comptoir : l'employé la vérifie, encaisse
 * l'acompte s'il y a lieu et la confirme depuis sa fiche.
 */
final class CommandePourClient
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CommandeProduitClient $conversion,
    ) {
    }

    /**
     * @param array{l: float, h: float}|null $taille taille validée choisie (mm), null : format du produit
     *
     * @throws \DomainException
     */
    public function commander(Clients $client, ProduitClient $produit, float $quantite, ?array $taille, ?User $par, \DateTimeImmutable $maintenant): Commandes
    {
        if ($produit->getClient()?->getId() !== $client->getId() || !$produit->isActif()) {
            throw new \DomainException('Ce produit n\'est pas un produit actif de ce client.');
        }
        if (!MesProduits::estCommandable($produit)) {
            throw new \DomainException(\sprintf('« %s » n\'est pas encore commandable : fichier en préparation ou prix à fixer.', $produit->getNom()));
        }
        if ($quantite <= 0) {
            throw new \DomainException('Indiquez la quantité.');
        }
        if (null !== $taille && !$produit->estTailleValidee((float) $taille['l'], (float) $taille['h'])) {
            throw new \DomainException('Choisissez une des tailles préparées du produit.');
        }

        $commande = (new Commandes())
            ->setClients($client)
            ->setDateCommande($maintenant)
            ->setCanal(Commandes::CANAL_COMPTOIR)
            ->setAgents($par)
            ->setStatut(false) // à confirmer depuis la fiche de la commande
            ->setEtat(true);
        try {
            $this->conversion->creerLigne($commande, $produit, $quantite, MesProduits::saisies($produit)[0], false, $taille);
        } catch (\InvalidArgumentException $e) {
            throw new \DomainException($e->getMessage());
        }

        $this->em->wrapInTransaction(function () use ($commande, $maintenant): void {
            $this->em->persist($commande);
            $this->em->flush();
            // Même numéro qu'au comptoir.
            $commande->setNumero(\sprintf('CMD-%06d-%s', $commande->getId(), $maintenant->format('m-Y')));
            $this->em->flush();
        });

        return $commande;
    }
}
