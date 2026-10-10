<?php

namespace App\Service;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ProduitClient;

/**
 * Transforme la quantite demandee sur un produit du client en ligne de
 * commande a produire (docs/mes-produits.md). Le prix vient de la fiche
 * du produit ; la ligne part en production sans PAO, le BAT etant fige.
 */
final class CommandeProduitClient
{
    public const SAISIE_PIECES = 'piece';
    public const SAISIE_M2 = 'm2';
    public const SAISIE_LOTS = 'lot';

    /**
     * Ce que l'atelier produira et ce que le client paiera, avant de
     * creer la ligne. En m2 sur un format fixe, $arrondiInferieur choisit
     * l'option au-dessous quand le produit le permet.
     *
     * @return array{
     *     pieces: int,
     *     longueur: ?float,
     *     surface: float,
     *     montant: int,
     *     production: string,
     *     alternative: ?array{pieces: int, surface: float, montant: int}
     * }
     */
    public function proposer(ProduitClient $produit, float $quantite, string $saisie, bool $arrondiInferieur = false): array
    {
        if ($quantite <= 0) {
            throw new \InvalidArgumentException('La quantité doit être positive.');
        }

        $largeur = $produit->getLargeur() ?? 0.0;
        $hauteur = $produit->getHauteur() ?? 0.0;
        $prix = $produit->getPrixUnitaire();

        if (ProduitClient::UNITE_LOT === $produit->getUniteVente()) {
            $lots = ConversionQuantite::parLots((int) ceil($quantite), $produit->getTailleLot() ?? 1, $produit->getPosesParFeuille() ?? 1);

            return $this->verifierMinimum($produit, $lots['lots'], [
                'pieces' => $lots['quantite'],
                'longueur' => null,
                'surface' => 0.0,
                'montant' => $lots['lots'] * $prix,
                'production' => sprintf('%d ex. (%d lot%s, %d feuille%s)', $lots['quantite'], $lots['lots'], $lots['lots'] > 1 ? 's' : '', $lots['feuilles'], $lots['feuilles'] > 1 ? 's' : ''),
                'alternative' => null,
            ]);
        }

        if ($produit->estLongueurLibre()) {
            if (self::SAISIE_M2 !== $saisie) {
                throw new \InvalidArgumentException('Ce produit se commande en m².');
            }

            $rouleau = ConversionQuantite::longueurDepuisSurface($quantite, $largeur);

            // Le client paie les m2 demandes ; l'arrondi aux 10 cm est pour nous.
            return $this->verifierMinimum($produit, $quantite, [
                'pieces' => 1,
                'longueur' => $rouleau['longueur'],
                'surface' => $rouleau['surface'],
                'montant' => (int) round($quantite * $prix),
                'production' => sprintf('%s m sur laize %s m', self::nombre($rouleau['longueur']), self::nombre($largeur)),
                'alternative' => null,
            ]);
        }

        if (self::SAISIE_M2 === $saisie) {
            if (!$produit->accepteLesM2() || !$produit->estFormatFixe()) {
                throw new \InvalidArgumentException('Ce produit se commande à la pièce.');
            }

            $choix = ConversionQuantite::piecesDepuisSurface($quantite, $largeur, $hauteur);
            $dessous = $produit->isArrondiSuperieurImpose() ? null : $choix['inferieur'];
            $retenu = $arrondiInferieur && $dessous ? $dessous : $choix['superieur'];
            $autre = $retenu === $choix['superieur'] ? $dessous : $choix['superieur'];

            return $this->verifierMinimum($produit, $retenu['pieces'], [
                'pieces' => $retenu['pieces'],
                'longueur' => null,
                'surface' => $retenu['surface'],
                'montant' => $retenu['pieces'] * $prix,
                'production' => self::libellePieces($retenu['pieces'], $largeur, $hauteur, $retenu['surface']),
                'alternative' => $autre ? $autre + ['montant' => $autre['pieces'] * $prix] : null,
            ]);
        }

        $pieces = (int) ceil($quantite);
        $surface = $produit->estFormatFixe() ? ConversionQuantite::surfacePieces($pieces, $largeur, $hauteur) : 0.0;

        return $this->verifierMinimum($produit, $pieces, [
            'pieces' => $pieces,
            'longueur' => null,
            'surface' => $surface,
            'montant' => $pieces * $prix,
            'production' => $produit->estFormatFixe() ? self::libellePieces($pieces, $largeur, $hauteur, $surface) : sprintf('%d pièce%s', $pieces, $pieces > 1 ? 's' : ''),
            'alternative' => null,
        ]);
    }

    /**
     * Cree la ligne de commande correspondante, prete pour la production.
     */
    public function creerLigne(Commandes $commande, ProduitClient $produit, float $quantite, string $saisie, bool $arrondiInferieur = false): CommandesDetails
    {
        $proposition = $this->proposer($produit, $quantite, $saisie, $arrondiInferieur);
        $tva = max(0, $commande->getTva());

        $ligne = (new CommandesDetails())
            ->setCommande($commande)
            ->setModeConfiguration('manuel')
            ->setProduit($produit->getProduit())
            ->setProduitClient($produit)
            ->setSupport($produit->getSupport())
            ->setMachine($produit->getMachine())
            ->setDesignation(sprintf('%s (%s)', $produit->getNom(), $produit->getReference()))
            ->setDemande($quantite, $saisie)
            ->setTva($tva)
            ->setBatValide(null !== $produit->getBatValide())
            ->setPrePresseNecessaire(false);

        if (null !== $proposition['longueur']) {
            // Rouleau : une piece de la longueur calculee, facturee au forfait
            // sur les m2 demandes.
            $ligne
                ->setModeCalcul('forfait')
                ->setQuantite(1)
                ->setLargeur($produit->getLargeur())
                ->setLongueur($proposition['longueur'])
                ->setSurface($proposition['surface'])
                ->setPrixUnitaire($proposition['montant'] / (1 + $tva / 100));
        } else {
            $ligne
                ->setModeCalcul('unite')
                ->setQuantite($proposition['pieces'])
                ->setLargeur($produit->getLargeur())
                ->setLongueur($produit->getHauteur())
                ->setSurface($produit->estFormatFixe() ? $produit->getLargeur() * $produit->getHauteur() : null)
                ->setPrixUnitaire($proposition['montant'] / $proposition['pieces'] / (1 + $tva / 100));
        }

        $ligne->calculerTotaux();
        $commande->addCommandesDetail($ligne);

        return $ligne;
    }

    /**
     * @param array<string, mixed> $proposition
     *
     * @return array<string, mixed>
     */
    private function verifierMinimum(ProduitClient $produit, float $quantite, array $proposition): array
    {
        if ($quantite < $produit->getQuantiteMinimum()) {
            throw new \InvalidArgumentException(sprintf('Minimum de commande : %d.', $produit->getQuantiteMinimum()));
        }

        return $proposition;
    }

    private static function libellePieces(int $pieces, float $largeur, float $hauteur, float $surface): string
    {
        return sprintf('%d × %s × %s cm = %s m²', $pieces, self::nombre($largeur * 100), self::nombre($hauteur * 100), self::nombre($surface));
    }

    private static function nombre(float $valeur): string
    {
        return rtrim(rtrim(number_format($valeur, 2, ',', ' '), '0'), ',');
    }
}
