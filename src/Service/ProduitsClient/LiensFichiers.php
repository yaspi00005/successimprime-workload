<?php

namespace App\Service\ProduitsClient;

use App\Entity\ProduitClient;
use App\Entity\User;
use App\Service\Pointage\Pointages;

/**
 * Lien entre un produit du client et son fichier traité du dossier de
 * l'atelier (maquette MP02, règles « Tailles validées » et « Fichier
 * modifié ») :
 *  - préparer : contrôle du fichier choisi pour une taille, sans rien lier ;
 *  - lier : l'empreinte SHA-256, le poids et le contrôle sont gardés, le
 *    produit devient « Prêt à imprimer » pour cette taille ;
 *  - vérifier : avant l'impression, l'empreinte du fichier du dossier est
 *    recalculée et comparée à celle du lien.
 */
final class LiensFichiers
{
    public const IDENTIQUE = 'identique';
    public const MODIFIE = 'modifie';
    public const INTROUVABLE = 'introuvable';
    /** Sur le site en ligne : pas de dossier, seule la référence est connue. */
    public const HORS_ATELIER = 'hors_atelier';

    public function __construct(
        private readonly DossierClient $dossiers,
        private readonly ControleFichierLie $controle,
    ) {
    }

    /**
     * Contrôle du fichier pour la taille donnée (mm), avant de lier.
     *
     * @return array{chemin: string, octets: int, controles: list<array{libelle: string, valeur: string, etat: string}>, etat: string}
     *
     * @throws \DomainException
     */
    public function preparer(ProduitClient $produit, string $nom, ?float $lMm, ?float $hMm): array
    {
        $chemin = $this->dossiers->chemin($produit->getClient(), $nom) ?? throw new \DomainException('Ce fichier n\'est pas dans le dossier « Produits » du client sur le serveur de l\'atelier.');
        if (!\in_array(strtolower(pathinfo($chemin, \PATHINFO_EXTENSION)), DossierClient::EXTENSIONS, true)) {
            throw new \DomainException('Format non pris en charge : liez un PDF, AI, EPS, TIFF, JPG, PNG ou PSD.');
        }
        $controles = $this->controle->controler($chemin, $lMm, $hMm);

        return ['chemin' => $chemin, 'octets' => (int) filesize($chemin), 'controles' => $controles, 'etat' => ControleFichierLie::etatGlobal($controles)];
    }

    /**
     * Lie le fichier à la taille donnée (mm). La taille devient « validée »
     * pour une commande directe.
     *
     * @throws \DomainException
     */
    public function lier(ProduitClient $produit, string $nom, float $lMm, float $hMm, ?User $par, \DateTimeImmutable $maintenant, ?string $nomDecoupe = null, bool $decoupeSansFichier = false): array
    {
        if ($lMm <= 0 || $hMm <= 0 || $lMm > 10000 || $hMm > 100000) {
            throw new \DomainException('Indiquez la taille validée en mm.');
        }
        $nomDecoupe = null !== $nomDecoupe && '' !== trim($nomDecoupe) ? trim($nomDecoupe) : null;
        // Fichier de découpe (docs/prepresse.md) : demandé par une finition de découpe, sauf contour dans le fichier.
        if (null === $nomDecoupe && !$decoupeSansFichier && $produit->besoinFichierDecoupe()) {
            throw new \DomainException('Ce produit a une découpe : choisissez aussi le fichier de découpe dans le dossier du client, ou cochez « Découpe sans fichier séparé ».');
        }
        if (null !== $nomDecoupe && $nomDecoupe === $nom) {
            throw new \DomainException('Le fichier de découpe doit être un autre fichier que celui à imprimer.');
        }
        $prep = $this->preparer($produit, $nom, $lMm, $hMm);
        $empreinte = DossierClient::empreinte($prep['chemin']) ?? throw new \DomainException('Le fichier n\'a pas pu être lu sur le serveur.');
        $decoupe = null;
        if (null !== $nomDecoupe) {
            $chemin = $this->dossiers->chemin($produit->getClient(), $nomDecoupe) ?? throw new \DomainException('Le fichier de découpe n\'est pas dans le dossier « Produits » du client.');
            $decoupe = [
                'nom' => basename($chemin),
                'empreinte' => DossierClient::empreinte($chemin) ?? throw new \DomainException('Le fichier de découpe n\'a pas pu être lu sur le serveur.'),
                'octets' => (int) filesize($chemin),
            ];
        }
        $lien = [
            'l' => round($lMm, 1),
            'h' => round($hMm, 1),
            'nom' => basename($prep['chemin']),
            'empreinte' => $empreinte,
            'octets' => $prep['octets'],
            'le' => $maintenant->format('Y-m-d H:i'),
            'par' => null !== $par ? Pointages::nom($par) : null,
            'controles' => $prep['controles'],
        ];
        if (null !== $decoupe) {
            $lien['decoupe'] = $decoupe;
        } elseif ($decoupeSansFichier) {
            $lien['decoupeSansFichier'] = true;
        }
        $produit->lierFichier($lien);

        return $lien;
    }

    /**
     * Le fichier lié est-il toujours celui du dossier ? (règle « Fichier
     * modifié ») L'empreinte est recalculée à chaque appel.
     *
     * @param array{nom: string, empreinte: string} $lien
     *
     * @return array{etat: string, chemin: ?string}
     */
    public function verifier(ProduitClient $produit, array $lien, bool $enLigne = false): array
    {
        if ($enLigne) {
            return ['etat' => self::HORS_ATELIER, 'chemin' => null];
        }
        $chemin = null !== $produit->getClient() ? $this->dossiers->chemin($produit->getClient(), (string) ($lien['nom'] ?? '')) : null;
        if (null === $chemin) {
            return ['etat' => self::INTROUVABLE, 'chemin' => null];
        }

        return ['etat' => hash_equals((string) ($lien['empreinte'] ?? ''), (string) DossierClient::empreinte($chemin)) ? self::IDENTIQUE : self::MODIFIE, 'chemin' => $chemin];
    }

    public function dossiers(): DossierClient
    {
        return $this->dossiers;
    }
}
