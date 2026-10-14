<?php

namespace App\Service\Conception\Generateur;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Le générateur choisi par IA_IMAGES_FOURNISSEUR : « openai » (par
 * défaut, actif dès que IA_IMAGES_CLE est posée) ou « demo » (affiche GD
 * pour le développement, refusée en production).
 */
#[AsAlias(GenerateurImages::class)]
final class GenerateurChoisi implements GenerateurImages
{
    private readonly GenerateurImages $generateur;

    public function __construct(
        HttpClientInterface $http,
        #[Autowire('%env(IA_IMAGES_FOURNISSEUR)%')] string $fournisseur,
        #[Autowire('%env(IA_IMAGES_CLE)%')] string $cle,
        #[Autowire('%env(IA_IMAGES_MODELE)%')] string $modele,
        #[Autowire('%env(IA_IMAGES_URL)%')] string $adresse,
        #[Autowire('%kernel.project_dir%/vendor/dompdf/dompdf/lib/fonts')] string $polices,
        #[Autowire('%kernel.environment%')] string $environnement,
    ) {
        $this->generateur = 'demo' === strtolower(trim($fournisseur))
            ? new GenerateurDemo($polices, 'prod' === $environnement)
            : new GenerateurOpenAi($http, $cle, $modele, '' !== trim($adresse) ? trim($adresse) : 'https://api.openai.com/v1');
    }

    public function nom(): string
    {
        return $this->generateur->nom();
    }

    public function modele(): string
    {
        return $this->generateur->modele();
    }

    public function estDisponible(): bool
    {
        return $this->generateur->estDisponible();
    }

    public function generer(string $prompt, string $orientation, array $textes = []): ImageGeneree
    {
        return $this->generateur->generer($prompt, $orientation, $textes);
    }
}
