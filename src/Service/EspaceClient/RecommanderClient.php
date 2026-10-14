<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\CommandesDetails;

/**
 * « Recommander rapidement » (tableau de bord, maquette EC01) : les
 * derniers articles du client, au prix d'aujourd'hui, et le lien qui
 * ouvre la commande pré-remplie (Nouvelle commande, ou Mes produits pour
 * un produit à sa fiche). Rien n'est commandé ici.
 */
final class RecommanderClient
{
    public function __construct(
        private readonly EspaceClient $espace,
        private readonly NouvelleCommande $nouvelleCommande,
        private readonly MesProduits $mesProduits,
    ) {
    }

    /**
     * « Recommander rapidement » (maquette EC01) : les derniers articles
     * commandés, prix recalculé maintenant (catalogue du jour ou fiche
     * « Mes produits »), avec le lien qui ouvre la commande pré-remplie.
     * Un article qui ne se commande plus en ligne est passé. Sans $montants
     * (demandeur, CompteClient::montantsVisibles), le prix reste null.
     *
     * @return list<array{titre: string, derniere: \DateTimeInterface, prix: ?int, lien: array{0: string, 1: array<string, mixed>}}>
     */
    public function articles(Clients $client, int $nombre = 3, bool $montants = true): array
    {
        $vus = [];
        $liste = [];
        foreach ($this->espace->commandes($client, 30) as $c) {
            if (!$c->isStatut() || $c->isDeleted() || 'annulee' === $c->getStatutTravaux()) {
                continue;
            }
            foreach ($c->getCommandesDetails() as $l) {
                if (CommandesDetails::PRODUCTION_ANNULEE === $l->getStatutProduction()) {
                    continue;
                }
                try {
                    $article = $this->articleARecommander($client, $l);
                } catch (\DomainException) {
                    continue;
                }
                if (null === $article || isset($vus[$article['cle']])) {
                    continue;
                }
                $vus[$article['cle']] = true;
                $liste[] = ['titre' => EspaceClient::titreLigne($l), 'derniere' => $c->getDateCommande() ?? new \DateTimeImmutable(), 'prix' => $montants ? $article['prix'] : null, 'lien' => $article['lien']];
                if (\count($liste) >= $nombre) {
                    return $liste;
                }
            }
        }

        return $liste;
    }

    /**
     * @return array{cle: string, prix: int, lien: array{0: string, 1: array<string, mixed>}}|null
     *
     * @throws \DomainException article qui ne se calcule plus (quantité hors limites…)
     */
    private function articleARecommander(Clients $client, CommandesDetails $l): ?array
    {
        $produitClient = $l->getProduitClient();
        if (null !== $produitClient) {
            if (!$produitClient->isActif() || $produitClient->getClient()?->getId() !== $client->getId()) {
                return null;
            }
            $saisies = MesProduits::saisies($produitClient);
            $saisie = \in_array($l->getUniteDemandee(), $saisies, true) ? (string) $l->getUniteDemandee() : $saisies[0];
            $quantite = $l->getQuantiteDemandee() ?? (float) $l->getQuantite();
            $calcul = $this->mesProduits->calculer($produitClient, $quantite, $saisie);

            return [
                'cle' => 'pc'.$produitClient->getId().'|'.$quantite.'|'.$saisie,
                'prix' => (int) $calcul['montant'],
                'lien' => ['app_espace_produits', ['produit' => (string) $produitClient->getPublicId(), 'quantite' => self::nombreSaisi($quantite), 'saisie' => $saisie]],
            ];
        }
        $config = null !== $l->getProduitConfiguration()?->getId() ? $this->nouvelleCommande->produit((int) $l->getProduitConfiguration()->getId()) : null;
        if (null === $config) {
            return null;
        }
        $taille = NouvelleCommande::demandeTaille($config);
        $largeur = $taille && (float) $l->getLargeur() > 0 ? round((float) $l->getLargeur() * 100, 1) : null;
        $hauteur = $taille && (float) $l->getLongueur() > 0 ? round((float) $l->getLongueur() * 100, 1) : null;
        $finitions = [];
        foreach ($l->getFinitions() as $f) {
            if (null !== $f->getConfigurationFinition()?->getId() && $f->getConfigurationFinition()->getProduitConfiguration()?->getId() === $config->getId()) {
                $finitions[] = (int) $f->getConfigurationFinition()->getId();
            }
        }
        sort($finitions);
        $quantite = max(1, $l->getQuantite());
        $prix = NouvelleCommande::estimer($config, $quantite, $largeur, $hauteur, $finitions, $client->isB2B())['total'];

        return [
            'cle' => 'cf'.$config->getId().'|'.$quantite.'|'.$largeur.'|'.$hauteur.'|'.implode(',', $finitions),
            'prix' => $prix,
            'lien' => ['app_espace_nouvelle_commande', array_filter(['produit' => $config->getId(), 'quantite' => $quantite, 'largeur' => null !== $largeur ? self::nombreSaisi($largeur) : null, 'hauteur' => null !== $hauteur ? self::nombreSaisi($hauteur) : null, 'finitions' => $finitions], static fn ($v): bool => null !== $v && [] !== $v)],
        ];
    }

    /** « 85 », « 85,5 » : comme le client le saisit. */
    private static function nombreSaisi(float $n): string
    {
        return str_replace('.', ',', (string) (floor($n) == $n ? (int) $n : round($n, 2)));
    }
}
