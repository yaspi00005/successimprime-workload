<?php

namespace App\Service;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ProduitClient;

/**
 * Transforme la quantite demandee sur un produit du client en ligne de
 * commande a produire (docs/mes-produits.md). Le prix vient de la fiche
 * du produit (palier atteint pour les pieces). A une taille validee, la
 * ligne garde la reference du fichier lie et saute le prepresse ; a une
 * autre taille (« Autre taille », MP04), elle passe au prepresse avec un
 * BAT, au prix rapporte a la surface.
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
    public function proposer(ProduitClient $produit, float $quantite, string $saisie, bool $arrondiInferieur = false, ?array $taille = null): array
    {
        if ($quantite <= 0) {
            throw new \InvalidArgumentException('La quantité doit être positive.');
        }

        $largeur = $produit->getLargeur() ?? 0.0;
        $hauteur = $produit->getHauteur() ?? 0.0;
        $prix = $produit->getPrixUnitaire();
        $facteur = 1.0;
        if (null !== $taille && self::SAISIE_PIECES === $saisie && ProduitClient::UNITE_LOT !== $produit->getUniteVente()) {
            [$lMm, $hMm] = self::taille($taille);
            // Autre taille : prix rapporté à la surface du format du produit (jamais moins cher).
            if ($largeur > 0 && $hauteur > 0 && !$produit->estTailleValidee($lMm, $hMm)) {
                $facteur = max(1.0, ($lMm * $hMm) / ($largeur * 1000 * $hauteur * 1000));
            }
            $largeur = $lMm / 1000;
            $hauteur = $hMm / 1000;
        }

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
        $formatFixe = $largeur > 0 && $hauteur > 0;
        $surface = $formatFixe ? ConversionQuantite::surfacePieces($pieces, $largeur, $hauteur) : 0.0;
        // Prix par quantité (MP04) : le palier atteint ; arrondi au franc, multiple de 5 pour une autre taille.
        $unitaire = $produit->prixPour($pieces) * $facteur;
        $unitaire = $facteur > 1.0 ? (int) (ceil($unitaire / 5) * 5) : (int) round($unitaire);

        return $this->verifierMinimum($produit, $pieces, [
            'pieces' => $pieces,
            'longueur' => null,
            'surface' => $surface,
            'montant' => $pieces * $unitaire,
            'production' => $formatFixe ? self::libellePieces($pieces, $largeur, $hauteur, $surface) : sprintf('%d pièce%s', $pieces, $pieces > 1 ? 's' : ''),
            'alternative' => null,
        ]);
    }

    /**
     * Cree la ligne de commande correspondante, prete pour la production.
     */
    public function creerLigne(Commandes $commande, ProduitClient $produit, float $quantite, string $saisie, bool $arrondiInferieur = false, ?array $taille = null): CommandesDetails
    {
        $proposition = $this->proposer($produit, $quantite, $saisie, $arrondiInferieur, $taille);
        $tva = max(0, $commande->getTva());

        $ligne = (new CommandesDetails())
            ->setCommande($commande)
            ->setModeConfiguration('manuel')
            ->setProduit($produit->getProduit())
            ->setProduitClient($produit)
            ->setSupport($produit->getSupport())
            ->setMachine($produit->getMachine())
            ->setDesignation($produit->getNom())
            ->setDemande($quantite, $saisie)
            ->setTva($tva);

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
            [$lM, $hM] = null !== $taille && self::SAISIE_PIECES === $saisie ? array_map(static fn (float $mm): float => $mm / 1000, self::taille($taille)) : [$produit->getLargeur(), $produit->getHauteur()];
            $ligne
                ->setModeCalcul('unite')
                ->setQuantite($proposition['pieces'])
                ->setLargeur($lM)
                ->setLongueur($hM)
                ->setSurface(null !== $lM && null !== $hM ? $lM * $hM : null)
                ->setPrixUnitaire($proposition['montant'] / $proposition['pieces'] / (1 + $tva / 100));
        }

        self::aiguillerALaCommande($ligne, $produit);
        $ligne->calculerTotaux();
        $commande->addCommandesDetail($ligne);
        $commande->recalculerTotaux();

        return $ligne;
    }

    /**
     * À la commande (MP05) : une ligne à une taille validée garde la
     * référence du fichier lié et saute le prépresse ; un produit enregistré
     * par l'atelier avec un BAT validé, à son format, aussi ; sinon la ligne
     * passe au prépresse, avec un BAT au client. Le local revérifie tout à
     * la réception (Synchro::verifierLigneEnLigne) et avant l'impression.
     */
    public static function aiguillerALaCommande(CommandesDetails $ligne, ProduitClient $produit): void
    {
        $l = null !== $ligne->getLargeur() ? round((float) $ligne->getLargeur() * 1000, 1) : null;
        $h = null !== $ligne->getLongueur() ? round((float) $ligne->getLongueur() * 1000, 1) : null;
        $lien = null !== $l && null !== $h ? $produit->fichierPourTaille($l, $h) : null;
        if (null !== $lien) {
            $ligne->setFichierLie(['nom' => (string) $lien['nom'], 'empreinte' => (string) $lien['empreinte'], 'l' => (float) $lien['l'], 'h' => (float) $lien['h'], 'le' => $lien['le'] ?? null])
                ->setPrePresseNecessaire(false)->setBatValide(true);

            return;
        }
        $ligne->setFichierLie(null);
        // Sans format (textile, lots) : le BAT validé suffit, comme avant.
        $direct = null !== $produit->getBatValide() && ProduitClient::LIEN_SUSPENDU !== $produit->getEtatLien()
            && (null === $l || null === $h || !$produit->estFormatFixe() || $produit->estTailleValidee($l, $h));
        $ligne->setPrePresseNecessaire(!$direct)->setBatValide($direct);
    }

    /** @return array{0: float, 1: float} largeur et hauteur en mm */
    public static function taille(array $taille): array
    {
        $l = (float) ($taille['l'] ?? $taille[0] ?? 0);
        $h = (float) ($taille['h'] ?? $taille[1] ?? 0);
        if ($l <= 0 || $h <= 0 || $l > 10000 || $h > 10000) {
            throw new \InvalidArgumentException('Indiquez une taille valable (en mm).');
        }

        return [round($l, 1), round($h, 1)];
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
