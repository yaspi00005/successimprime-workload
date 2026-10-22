<?php

namespace App\Service\Catalogue;

use App\Entity\Clients;
use App\Entity\CommandesDetails;
use App\Entity\PrixSpecial;
use App\Entity\ProduitConfiguration;
use App\Repository\PrixSpecialRepository;

/**
 * Applique les prix négociés par client (maquette PR03, docs/produits.md).
 *
 * Le prix négocié remplace la grille du client : il est posé en remise
 * par unité sur la ligne (prix du catalogue − prix négocié), comme la
 * remise B2B au comptoir. La facture garde le prix normal, et une
 * promotion ne donne que ce qui dépasse cette remise (pas de cumul).
 *
 * Appelé par la commande en ligne (estimation et enregistrement) et par
 * la saisie du comptoir (prix de la configuration pour le client choisi).
 */
final class PrixSpeciaux
{
    public function __construct(private readonly PrixSpecialRepository $depot)
    {
    }

    public function pour(?Clients $client, ?ProduitConfiguration $configuration, \DateTimeInterface $jour): ?PrixSpecial
    {
        if (null === $client || null === $client->getId() || null === $configuration || null === $configuration->getId()) {
            return null;
        }

        return $this->depot->valablePour($client, $configuration, $jour);
    }

    /**
     * Pose le prix négocié valable ce jour-là sur la ligne, s'il y en a un.
     */
    public function appliquerALigne(CommandesDetails $ligne, ?Clients $client, \DateTimeInterface $jour): ?PrixSpecial
    {
        $prix = $this->pour($client, $ligne->getProduitConfiguration(), $jour);
        if (null !== $prix) {
            self::appliquer($ligne, $prix->getPrix(), $client?->isB2B() ?? false);
        }

        return $prix;
    }

    /** Remise par unité qui ramène le prix de la ligne au prix négocié. */
    public static function remiseParUnite(int $prixLigne, int $prixNegocie): int
    {
        return max(0, $prixLigne - max(0, $prixNegocie));
    }

    /**
     * Remise par unité de la ligne : celle qui ramène au prix négocié,
     * sauf si la ligne a déjà une remise plus forte (geste du comptoir).
     */
    public static function appliquer(CommandesDetails $ligne, int $prixNegocie, bool $clientB2B = false): CommandesDetails
    {
        $remise = self::remiseParUnite((int) round($ligne->getPrixUnitaire()), $prixNegocie);
        if ($remise > $ligne->getRemise()) {
            $ligne->setRemise($remise);
            $ligne->calculerTotaux($clientB2B);
        }

        return $ligne;
    }
}
