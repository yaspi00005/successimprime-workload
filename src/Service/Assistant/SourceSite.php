<?php

namespace App\Service\Assistant;

use App\Entity\Clients;
use App\Entity\ProduitConfiguration;
use App\Entity\Promotion;

/**
 * Ce que les outils de l'assistant lisent du site public : catalogue publié
 * au tarif public, coordonnées et horaires, pages publiées, promotions.
 * Rien d'autre (ni coût, ni prix B2B, ni note interne).
 */
interface SourceSite
{
    /** @return list<ProduitConfiguration> configurations publiées avec un prix public */
    public function configurations(): array;

    /**
     * Estimation du calculateur du site, au tarif public.
     *
     * @param array{produit: int, largeur: ?float, hauteur: ?float, quantite: int, finitions: list<int>} $valeurs
     *
     * @return array<string, mixed> Calculateur::resultat()
     */
    public function estimation(ProduitConfiguration $configuration, array $valeurs): array;

    /**
     * Coordonnées publiques de la boutique et horaires du jour.
     *
     * @return array{nom: string, adresse: ?string, telephone: ?string, email: ?string, horaires: string, ouvert: string, plan: ?string}
     */
    public function boutique(\DateTimeImmutable $maintenant): array;

    /** @return array{titre: string, texte: string, url: ?string}|null page publiée du site */
    public function page(string $slug): ?array;

    /**
     * Promotions en cours : celles de tout le monde sur le site, celles du
     * client dans son espace (aucune pour un revendeur au prix B2B).
     *
     * @return list<Promotion>
     */
    public function promotions(\DateTimeImmutable $jour, ?Clients $client): array;

    /** Adresse d'une page, ou null si elle n'existe pas. */
    public function lien(string $route, array $parametres = []): ?string;
}
