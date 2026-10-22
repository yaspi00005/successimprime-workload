<?php

namespace App\Service\Conception\Generateur;

/**
 * Fournisseur d'images IA (docs/demande-conception.md). Choisi par les
 * variables IA_IMAGES_FOURNISSEUR, IA_IMAGES_CLE et IA_IMAGES_MODELE,
 * posées seulement dans .env.local du serveur en ligne.
 */
interface GenerateurImages
{
    /** « openai », « demo » : noté sur chaque image. */
    public function nom(): string;

    public function modele(): string;

    /** Clé posée (ou générateur de démonstration hors production). */
    public function estDisponible(): bool;

    /**
     * Génère une image. Aucune adresse renvoyée par le fournisseur n'est
     * suivie : seule l'image elle-même est acceptée.
     *
     * @param string                $orientation PromptConception::PORTRAIT, PAYSAGE ou CARRE
     * @param array<string, string> $textes      textes du client (le générateur de démonstration les dessine)
     *
     * @throws \RuntimeException raison technique (jamais montrée au client)
     */
    public function generer(string $prompt, string $orientation, array $textes = []): ImageGeneree;
}
