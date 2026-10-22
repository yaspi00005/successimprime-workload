<?php

namespace App\Service\EspaceClient;

use App\Entity\CompteClient;
use App\Entity\ProduitClient;
use App\Service\CommandeProduitClient;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Panier de « Mes produits » (maquettes MP04, MP05, MP07). Gardé dans la
 * session du compte : produit, taille, quantité et façon de saisir. Les
 * prix, la conversion et l'aiguillage (impression directe ou BAT) sont
 * toujours recalculés par le serveur (MesProduits::calculer) ; le panier
 * validé devient la commande.
 */
final class Panier
{
    public const MAX = MesProduits::SELECTION_MAX;

    public function __construct(
        private readonly RequestStack $requetes,
        private readonly MesProduits $mesProduits,
    ) {
    }

    /** @return list<array{produit: string, quantite: float, saisie: string, inferieur: bool, taille: ?array{l: float, h: float}}> */
    public function articles(CompteClient $compte): array
    {
        $brut = $this->session()?->get(self::cle($compte), []);

        return \is_array($brut) ? array_values(array_filter($brut, 'is_array')) : [];
    }

    public function nombre(CompteClient $compte): int
    {
        return \count($this->articles($compte));
    }

    /**
     * Ajoute un produit (ou remplace la quantité du même produit à la même
     * taille). Rend le calcul.
     *
     * @throws \DomainException
     */
    public function ajouter(CompteClient $compte, ProduitClient $produit, float $quantite, string $saisie, bool $inferieur, ?array $taille): array
    {
        if (!MesProduits::estCommandable($produit)) {
            throw new \DomainException('Ce produit n\'est pas encore prêt : l\'atelier prépare son fichier.');
        }
        $taille = null !== $taille && MesProduits::choisitLaTaille($produit) && CommandeProduitClient::SAISIE_PIECES === $saisie ? self::tailleLue($taille) : null;
        $calcul = $this->mesProduits->calculer($produit, $quantite, $saisie, $inferieur, $taille);
        $articles = $this->articles($compte);
        $nouveau = ['produit' => (string) $produit->getPublicId(), 'quantite' => $calcul['quantite'], 'saisie' => $calcul['saisie'], 'inferieur' => $calcul['inferieur'], 'taille' => $taille];
        foreach ($articles as $i => $a) {
            if ($a['produit'] === $nouveau['produit'] && self::memeTaille($a['taille'] ?? null, $taille)) {
                $articles[$i] = $nouveau;
                $this->garder($compte, $articles);

                return $calcul;
            }
        }
        if (\count($articles) >= self::MAX) {
            throw new \DomainException(\sprintf('%d produits au plus dans le panier.', self::MAX));
        }
        $articles[] = $nouveau;
        $this->garder($compte, $articles);

        return $calcul;
    }

    /** @throws \DomainException */
    public function changerQuantite(CompteClient $compte, int $rang, float $quantite): void
    {
        $articles = $this->articles($compte);
        if (!isset($articles[$rang])) {
            throw new \DomainException('Cet article n\'est plus dans le panier.');
        }
        $produit = $this->mesProduits->trouver($compte->getClient(), $articles[$rang]['produit']) ?? throw new \DomainException('Ce produit n\'est plus disponible.');
        $calcul = $this->mesProduits->calculer($produit, $quantite, $articles[$rang]['saisie'], (bool) $articles[$rang]['inferieur'], $articles[$rang]['taille'] ?? null);
        $articles[$rang]['quantite'] = $calcul['quantite'];
        $this->garder($compte, $articles);
    }

    public function retirer(CompteClient $compte, int $rang): void
    {
        $articles = $this->articles($compte);
        unset($articles[$rang]);
        $this->garder($compte, array_values($articles));
    }

    public function vider(CompteClient $compte): void
    {
        $this->session()?->remove(self::cle($compte));
    }

    /**
     * Le panier recalculé : chaque article avec sa fiche et son calcul (sans
     * montant pour qui ne voit pas les prix), le total, et ce qui partira en
     * impression directe. Un article devenu impossible (produit retiré,
     * lien suspendu) est retiré du panier.
     *
     * @return array{articles: list<array<string, mixed>>, total: int, directs: int, avecBat: int}
     */
    public function detail(CompteClient $compte, bool $montants): array
    {
        $client = $compte->getClient();
        $garde = [];
        $articles = [];
        $total = 0;
        $directs = 0;
        foreach ($this->articles($compte) as $a) {
            $produit = $this->mesProduits->trouver($client, (string) $a['produit']);
            if (null === $produit || !MesProduits::estCommandable($produit)) {
                continue;
            }
            try {
                $calcul = $this->mesProduits->calculer($produit, (float) $a['quantite'], (string) $a['saisie'], (bool) $a['inferieur'], $a['taille'] ?? null);
            } catch (\DomainException) {
                continue;
            }
            $garde[] = $a;
            $total += $calcul['montant'];
            $directs += $calcul['direct'] ? 1 : 0;
            $fiche = $this->mesProduits->fiche($produit, $montants);
            $articles[] = [
                'rang' => \count($garde) - 1,
                'fiche' => $fiche,
                'calcul' => MesProduits::calculPourLaPage($calcul, $montants),
                'article' => $a,
                'nouvelleTaille' => !$calcul['direct'] && null !== $calcul['taille'] && $produit->estPret(),
            ];
        }
        if (\count($garde) !== \count($this->articles($compte))) {
            $this->garder($compte, $garde);
        }

        return ['articles' => $articles, 'total' => $montants ? $total : 0, 'directs' => $directs, 'avecBat' => \count($articles) - $directs];
    }

    /**
     * Ce que MesProduits::commander reçoit.
     *
     * @return list<array{produit: ProduitClient, quantite: float, saisie: string, inferieur: bool, taille: ?array}>
     *
     * @throws \DomainException
     */
    public function selection(CompteClient $compte): array
    {
        $selection = [];
        foreach ($this->articles($compte) as $a) {
            $produit = $this->mesProduits->trouver($compte->getClient(), (string) $a['produit']) ?? throw new \DomainException('Un produit du panier n\'est plus disponible. Rechargez la page.');
            $selection[] = ['produit' => $produit, 'quantite' => (float) $a['quantite'], 'saisie' => (string) $a['saisie'], 'inferieur' => (bool) $a['inferieur'], 'taille' => $a['taille'] ?? null];
        }

        return $selection;
    }

    /** @return array{l: float, h: float} */
    public static function tailleLue(array $taille): array
    {
        [$l, $h] = CommandeProduitClient::taille($taille);

        return ['l' => $l, 'h' => $h];
    }

    private static function memeTaille(?array $a, ?array $b): bool
    {
        if (null === $a || null === $b) {
            return $a === $b;
        }

        return ProduitClient::memeTaille((float) $a['l'], (float) $a['h'], (float) $b['l'], (float) $b['h']);
    }

    private function garder(CompteClient $compte, array $articles): void
    {
        $this->session()?->set(self::cle($compte), array_values($articles));
    }

    private function session(): ?\Symfony\Component\HttpFoundation\Session\SessionInterface
    {
        try {
            return $this->requetes->getSession();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function cle(CompteClient $compte): string
    {
        return 'espace_panier_'.$compte->getId();
    }
}
