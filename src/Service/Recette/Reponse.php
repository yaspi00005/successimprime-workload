<?php

namespace App\Service\Recette;

/**
 * Réponse d'une page demandée par le NavigateurInterne, rangée dans l'une
 * des cases de la matrice : ouverte, refusée, connexion, redirigée,
 * introuvable ou erreur.
 */
final class Reponse
{
    public const OUVERT = 'ouvert';
    public const REFUSE = 'refuse';
    public const CONNEXION = 'connexion';
    public const REDIRIGE = 'redirige';
    public const INTROUVABLE = 'introuvable';
    public const ERREUR = 'erreur';
    public const AUTRE = 'autre';

    /** Lettre de la matrice pour chaque case. */
    public const LETTRES = [
        self::OUVERT => 'O',
        self::REFUSE => 'R',
        self::CONNEXION => 'C',
        self::REDIRIGE => '>',
        self::INTROUVABLE => 'I',
        self::ERREUR => 'E',
        self::AUTRE => '?',
    ];

    public function __construct(
        public readonly int $statut,
        public readonly string $redirection,
        public readonly string $type,
        public readonly string $contenu,
        public readonly ?string $erreur = null,
        public readonly int $ecritures = 0,
    ) {
    }

    public function etat(): string
    {
        return self::classer($this->statut, $this->redirection);
    }

    /** Case de la matrice pour un code HTTP et l'adresse de redirection. */
    public static function classer(int $statut, string $redirection = ''): string
    {
        return match (true) {
            $statut >= 500 => self::ERREUR,
            401 === $statut, 403 === $statut => self::REFUSE,
            404 === $statut, 405 === $statut, 410 === $statut => self::INTROUVABLE,
            $statut >= 300 && $statut < 400 => self::estConnexion($redirection) ? self::CONNEXION : self::REDIRIGE,
            $statut >= 200 && $statut < 300 => self::OUVERT,
            default => self::AUTRE,
        };
    }

    /** Redirection vers une page de connexion (personnel, espace client, code de sécurité). */
    public static function estConnexion(string $redirection): bool
    {
        $chemin = (string) parse_url($redirection, \PHP_URL_PATH);

        return 1 === preg_match('#^/(login|lockscreen|verification|espace/connexion)(/|$)#', $chemin);
    }

    public function estHtml(): bool
    {
        return str_contains($this->type, 'text/html') || ('' === $this->type && str_contains($this->contenu, '<html'));
    }

    /** Texte visible de la page (balises, scripts et styles retirés), espaces simplifiés. */
    public function texte(): string
    {
        return ContenuSensible::texteVisible($this->contenu);
    }
}
