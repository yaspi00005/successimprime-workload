<?php

namespace App\Service\Achats;

use App\Entity\AchatDetail;
use App\Entity\Achats;
use App\Entity\Articles;

/**
 * Préparation d'une commande d'achat (docs/achats.md) : une ligne au prix
 * du fournisseur, et le transport et le transit repris de sa dernière
 * commande. Sert à la commande créée depuis une alerte (SU03) et au
 * brouillon proposé par le réapprovisionnement (SU06, SU07).
 */
final class PreparationAchat
{
    public function __construct(
        private readonly SuiviAchats $suivi,
    ) {
    }

    /**
     * Ajoute l'article au prix noté pour ce fournisseur (sinon le prix
     * d'achat de la fiche article).
     */
    public static function ajouterLigne(Achats $achat, Articles $article, int $quantite): AchatDetail
    {
        $ligne = (new AchatDetail())->setArticle($article)->setQuantite(max(1, $quantite))->setPrixUnitaire(self::prix($achat, $article));
        $achat->addLigne($ligne);

        return $ligne;
    }

    /**
     * Prix d'une unité d'achat chez le fournisseur de la commande.
     */
    public static function prix(Achats $achat, Articles $article): int
    {
        $prix = $article->getPrixAchat() ?? 0;

        foreach ($article->getFournisseurs() as $lien) {
            if ($lien->getFournisseur() === $achat->getFournisseur() && $lien->getPrixAchat() !== null) {
                $prix = $lien->getPrixAchat();
            }
        }

        return $prix;
    }

    /**
     * Transport et transit repris de la dernière commande du fournisseur :
     * même libellé, même base, et le même montant par unité de stock
     * appliqué aux quantités de cette commande (arrondi à la centaine).
     */
    public function preremplirFrais(Achats $achat): void
    {
        $precedente = null;
        foreach ($this->suivi->achats() as $a) {
            if ($a !== $achat && $a->getFournisseur() === $achat->getFournisseur() && SuiviAchats::estPassee($a) && ($a->getFraisTransport() > 0 || $a->getFraisTransit() > 0)) {
                $precedente = $a;
                break;
            }
        }

        if ($precedente === null) {
            return;
        }

        $avant = SuiviAchats::detailCommande($precedente);
        $maintenant = SuiviAchats::detailCommande($achat);
        $mettreAEchelle = static function (int $frais) use ($avant, $maintenant): int {
            if ($avant['quantiteTotale'] <= 0 || $maintenant['quantiteTotale'] <= 0 || !$avant['memeUnite'] || $avant['unite'] !== $maintenant['unite']) {
                return $frais;
            }

            return (int) (round($frais / $avant['quantiteTotale'] * $maintenant['quantiteTotale'] / 100) * 100);
        };

        $achat->setFraisTransportLibelle($precedente->getFraisTransportLibelle())
            ->setFraisTransportBase($precedente->getFraisTransportBase())
            ->setFraisTransport($mettreAEchelle($precedente->getFraisTransport()))
            ->setFraisTransitLibelle($precedente->getFraisTransitLibelle())
            ->setFraisTransitBase($precedente->getFraisTransitBase())
            ->setFraisTransit($mettreAEchelle($precedente->getFraisTransit()));
    }
}
