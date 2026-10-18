<?php

namespace App\Service\Assistant;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\DemandeDevis;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\MesProduits;
use App\Service\Paiement\OrangeMoney;
use App\Service\Site\DemandesSite;

/** SourceEspace branchée sur les services de l'espace client. */
final class SourceEspaceApplication implements SourceEspace
{
    public function __construct(
        private readonly EspaceClient $espace,
        private readonly MesProduits $mesProduits,
        private readonly OrangeMoney $orangeMoney,
        private readonly DemandesSite $demandes,
    ) {
    }

    public function commandes(Clients $client): array
    {
        return $this->espace->commandes($client, 20);
    }

    public function carte(Commandes $commande): array
    {
        return $this->espace->carte($commande);
    }

    public function solde(Clients $client): array
    {
        return $this->espace->solde($client);
    }

    public function facturesARegler(Clients $client): array
    {
        return $this->espace->facturesARegler($client);
    }

    public function produits(Clients $client): array
    {
        $produits = [];
        foreach (\array_slice($this->mesProduits->liste($client), 0, 15) as $p) {
            $fiche = $this->mesProduits->fiche($p);
            $produits[] = ['nom' => (string) $p->getNom(), 'format' => (string) $fiche['format'], 'prix' => (string) $fiche['prix'], 'batLe' => $fiche['batLe']];
        }

        return $produits;
    }

    /** Comme les pages de paiement : clés posées et interrupteur « Paiement Orange Money en ligne » allumé. */
    public function paiementEnLigne(): bool
    {
        return $this->orangeMoney->estOuvert();
    }

    public function enregistrerDemande(array $valeurs, \DateTimeImmutable $le): DemandeDevis
    {
        return $this->demandes->enregistrer(DemandeDevis::NATURE_DEVIS, $valeurs, null, $le);
    }
}
