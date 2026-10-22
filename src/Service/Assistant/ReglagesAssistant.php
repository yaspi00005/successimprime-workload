<?php

namespace App\Service\Assistant;

use App\Controller\UtilisateursController;
use App\Service\Parametres\Parametres;
use App\Twig\SiteExtension;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Réglages de l'assistant : la tuile Réglages › Assistant du site (venue
 * du local avec les réglages publics), la clé de l'API et le modèle imposé
 * par le serveur (.env.local). La clé ne sort jamais d'ici.
 */
class ReglagesAssistant
{
    public const TUILE = 'assistant';
    public const MODELE_DEFAUT = 'claude-opus-5-5';
    public const EFFORTS = ['low', 'medium', 'high'];

    public function __construct(
        private readonly Parametres $parametres,
        private readonly SiteExtension $site,
        #[Autowire('%env(ANTHROPIC_API_KEY)%')] private readonly string $cle,
        #[Autowire('%env(ASSISTANT_MODELE)%')] private readonly string $modeleServeur,
        #[Autowire('%env(bool:ASSISTANT_DEMO)%')] private readonly bool $demo,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
    ) {
    }

    /** Réponses de démonstration : en développement seulement, jamais en production. */
    public function demo(): bool
    {
        return self::demoPermise($this->demo, $this->environnement);
    }

    public static function demoPermise(bool $demande, string $environnement): bool
    {
        return $demande && 'dev' === $environnement;
    }

    /** La clé de l'API (ou la démonstration) est là : sinon l'assistant est caché. */
    public function cleConfiguree(): bool
    {
        return '' !== trim($this->cle) || $this->demo();
    }

    public function cle(): string
    {
        return trim($this->cle);
    }

    /** Montré aux visiteurs : clé posée et tuile allumée. */
    public function disponible(): bool
    {
        return $this->cleConfiguree() && (bool) $this->valeur('actif');
    }

    /** Modèle des nouvelles conversations : celui du serveur, sinon celui de la tuile. */
    public function modele(): string
    {
        $serveur = trim($this->modeleServeur);
        if ('' !== $serveur && preg_match('/^[a-z0-9][a-z0-9.\-]{2,58}$/', $serveur)) {
            return $serveur;
        }
        $tuile = (string) $this->valeur('modele');

        return '' !== $tuile ? $tuile : self::MODELE_DEFAUT;
    }

    public function effort(): string
    {
        $effort = (string) $this->valeur('effort');

        return \in_array($effort, self::EFFORTS, true) ? $effort : 'low';
    }

    public function messageAccueil(): string
    {
        return trim((string) $this->valeur('message_accueil')) ?: 'Bonjour ! Posez votre question.';
    }

    /** Dépense maximale du jour, en FCFA ; 0 coupe l'assistant. */
    public function limiteJour(): int
    {
        return max(0, (int) $this->valeur('limite_jour'));
    }

    public function tauxUsd(): float
    {
        return max(1.0, (float) $this->valeur('taux_usd'));
    }

    /**
     * Tarif du modèle choisi (dollars par million de jetons).
     *
     * @return array{entree: float, sortie: float, cacheLecture: float, cacheEcriture: float}
     */
    public function tarif(): array
    {
        return [
            'entree' => (float) $this->valeur('prix_entree'),
            'sortie' => (float) $this->valeur('prix_sortie'),
            'cacheLecture' => (float) $this->valeur('prix_cache_lecture'),
            'cacheEcriture' => (float) $this->valeur('prix_cache_ecriture'),
        ];
    }

    /** Délai à annoncer : jamais une promesse, seulement ce que l'atelier a écrit. */
    public function delais(): string
    {
        return trim((string) $this->valeur('delais')) ?: 'Annoncé à la validation du BAT (bon à tirer), selon le produit et la quantité.';
    }

    /** Lien wa.me vers l'atelier avec ce premier message, ou null sans numéro. */
    public function lienWhatsApp(string $texte): ?string
    {
        $numero = trim((string) $this->valeur('whatsapp'));
        if ('' === $numero) {
            return $this->site->whatsapp($texte);
        }
        $chiffres = UtilisateursController::numeroWhatsApp($numero);

        return '' === $chiffres ? $this->site->whatsapp($texte) : 'https://wa.me/'.$chiffres.'?text='.rawurlencode($texte);
    }

    private function valeur(string $cle): mixed
    {
        return $this->parametres->valeur(self::TUILE, $cle);
    }
}
