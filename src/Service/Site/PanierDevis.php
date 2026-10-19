<?php

namespace App\Service\Site;

use App\Entity\ProduitConfiguration;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Panier de devis du site (docs/site-vitrine.md) : limites d'un envoi
 * (lignes, fichiers, poids total), prix indicatif d'une ligne et résumé
 * montré sur la confirmation. Tout est statique et pur, pour être testé
 * sans base.
 *
 * Le prix indicatif est celui que le devis brouillon aura au local
 * (PreparationDemande::ligneDevis, tarif public, pièces au m² comprises) :
 * jamais le prix B2B, le coût ni la marge, et toujours présenté « à
 * confirmer par l'imprimerie ».
 */
final class PanierDevis
{
    /** Produits au plus dans une demande. */
    public const LIGNES_MAX = 10;

    /** Lignes lues au plus dans un envoi (le reste est ignoré sans être lu). */
    public const LIGNES_LUES = 30;

    /** Poids de tous les fichiers d'une demande, au plus (moins si le serveur n'accepte pas plus). */
    public const TOTAL_FICHIERS_MAX = 50 * 1024 * 1024;

    /** Place laissée aux champs du formulaire dans un envoi. */
    private const MARGE_ENVOI = 512 * 1024;

    /** Fichiers au plus dans une demande : un par ligne, et pas plus que le serveur n'en reçoit. */
    public static function fichiersMax(?int $parServeur = null): int
    {
        $parServeur ??= (int) \ini_get('max_file_uploads');

        return $parServeur > 0 ? min(self::LIGNES_MAX, $parServeur) : self::LIGNES_MAX;
    }

    /** Poids total permis : le nôtre, ou ce que le serveur accepte dans un envoi (post_max_size), moins la marge. */
    public static function tailleMaxTotale(?int $envoiMax = null): int
    {
        $envoiMax ??= self::octets((string) \ini_get('post_max_size'));
        $max = $envoiMax > 0 ? min(self::TOTAL_FICHIERS_MAX, $envoiMax - self::MARGE_ENVOI) : self::TOTAL_FICHIERS_MAX;

        return max(DemandesSite::tailleMaxFichier(), $max);
    }

    /** « 8M », « 512K », « 1G » (php.ini) en octets ; 0 pour « sans limite » ou illisible. */
    public static function octets(string $ini): int
    {
        $ini = trim($ini);
        if (!preg_match('/^(\d+)\s*([kmg]?)$/i', $ini, $m)) {
            return 0;
        }

        return (int) $m[1] * match (strtolower($m[2])) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
    }

    public static function mo(int $octets): string
    {
        return (string) intdiv($octets, 1024 * 1024);
    }

    /**
     * Message à montrer si les fichiers, ensemble, sont trop nombreux ou
     * trop lourds ; null sinon. Chaque fichier est contrôlé à part
     * (DemandesSite::verifierFichier).
     *
     * @param list<UploadedFile> $fichiers
     */
    public static function verifierEnsemble(array $fichiers, ?int $totalMax = null, ?int $nombreMax = null): ?string
    {
        $totalMax ??= self::tailleMaxTotale();
        $nombreMax ??= self::fichiersMax();
        if (\count($fichiers) > $nombreMax) {
            return \sprintf('%d fichiers au plus par demande : envoyez les autres par WhatsApp ou collez un lien dans le message.', $nombreMax);
        }
        $total = 0;
        foreach ($fichiers as $f) {
            $total += $f->isValid() ? (int) $f->getSize() : 0;
        }
        if ($total > $totalMax) {
            return \sprintf('Vos fichiers font %s Mo en tout, %s Mo au plus par demande : envoyez les plus lourds par WhatsApp ou collez un lien dans le message.', number_format($total / 1048576, 1, ',', ' '), self::mo($totalMax));
        }

        return null;
    }

    /**
     * Prix indicatif d'un produit demandé, au tarif public, tel que le
     * devis brouillon le comptera ; null quand le calcul n'est pas possible
     * (taille hors limites, quantité au-delà du maximum...) : l'imprimerie
     * chiffre alors elle-même.
     *
     * @param list<int> $finitions identifiants de ProduitConfigurationFinition
     */
    public static function prixIndicatif(ProduitConfiguration $config, ?int $quantite, ?float $largeurCm, ?float $hauteurCm, array $finitions, ?float $espacementPiecesM2): ?int
    {
        if (null === $quantite || $quantite < 1) {
            return null;
        }
        try {
            $ligne = PreparationDemande::ligneDevis($config, $quantite, $largeurCm, $hauteurCm, $finitions);
            if (null !== $espacementPiecesM2 && FormulaireDemande::piecesAuM2Possible($config)) {
                $ligne->choisirPiecesAuM2(true, $espacementPiecesM2)->calculerTotaux(false);
            }
        } catch (\DomainException) {
            return null;
        }
        $total = $ligne->getTotalTtc();

        return $total > 0 ? $total : null;
    }

    /**
     * Total indicatif des lignes chiffrées, et s'il couvre tout le panier.
     *
     * @param list<?int> $prix
     *
     * @return array{total: ?int, complet: bool}
     */
    public static function total(array $prix): array
    {
        $chiffres = array_filter($prix, static fn (?int $p): bool => null !== $p);

        return ['total' => [] === $chiffres ? null : array_sum($chiffres), 'complet' => [] !== $prix && \count($chiffres) === \count($prix)];
    }

    /**
     * Une ligne pour la confirmation : nom, détail, prix indicatif, fichier.
     *
     * @param array{produit: ?ProduitConfiguration, largeur: ?float, hauteur: ?float, quantite: ?int, quantiteM2: ?float, description: ?string} $ligne
     * @param list<string> $finitions noms des finitions choisies
     *
     * @return array{nom: string, detail: string, prix: ?string, fichier: ?string}
     */
    public static function resume(array $ligne, array $finitions, ?int $prix, ?string $fichier): array
    {
        $produit = $ligne['produit'] ?? null;
        $nombre = static fn (float $n): string => number_format($n, floor($n) == $n ? 0 : 1, ',', "\u{A0}");
        $detail = [];
        if (null !== ($ligne['largeur'] ?? null) && null !== ($ligne['hauteur'] ?? null)) {
            $detail[] = $nombre($ligne['largeur']).' × '.$nombre($ligne['hauteur']).' cm';
        }
        if (null !== ($ligne['quantiteM2'] ?? null)) {
            $detail[] = number_format((float) $ligne['quantiteM2'], 2, ',', "\u{A0}").' m² ('.$nombre((float) $ligne['quantite']).' pièces)';
        } elseif (null !== ($ligne['quantite'] ?? null)) {
            $detail[] = $nombre((float) $ligne['quantite']).' '.($ligne['quantite'] > 1 ? 'pièces' : 'pièce');
        }
        if ([] !== $finitions) {
            $detail[] = implode(', ', $finitions);
        }
        if (null === $produit && null !== ($ligne['description'] ?? null)) {
            $detail[] = mb_strimwidth((string) $ligne['description'], 0, 140, '…');
        }

        return [
            'nom' => null !== $produit ? CatalogueSite::libelle($produit) : 'Autre projet',
            'detail' => implode(' · ', $detail),
            'prix' => null !== $prix ? Calculateur::fcfa($prix) : null,
            'fichier' => $fichier,
        ];
    }
}
