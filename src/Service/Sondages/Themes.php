<?php

namespace App\Service\Sondages;

use Symfony\Component\String\UnicodeString;

/**
 * Classement des propositions des clients par thème, d'abord par
 * mots-clés (docs/sondages.md). L'atelier peut ensuite reclasser chaque
 * proposition à la main et la marquer retenue, à étudier ou écartée.
 */
final class Themes
{
    public const DELAI = 'delai';
    public const PRIX = 'prix';
    public const QUALITE = 'qualite';
    public const ACCUEIL = 'accueil';
    public const LIVRAISON = 'livraison';
    public const AUTRE = 'autre';

    /** Dans l'ordre du cahier : il départage deux thèmes à égalité. */
    public const THEMES = [
        self::DELAI => 'Délai',
        self::PRIX => 'Prix',
        self::QUALITE => 'Qualité d\'impression',
        self::ACCUEIL => 'Accueil',
        self::LIVRAISON => 'Livraison',
        self::AUTRE => 'Autre',
    ];

    public const RETENUE = 'retenue';
    public const A_ETUDIER = 'a_etudier';
    public const ECARTEE = 'ecartee';

    public const STATUTS = [
        self::A_ETUDIER => 'À étudier',
        self::RETENUE => 'Retenue',
        self::ECARTEE => 'Écartée',
    ];

    /**
     * Débuts de mots cherchés dans le texte sans accents ni majuscules ;
     * « \b » ferme un mot court (« cher » sans « chercher »).
     */
    private const MOTS = [
        self::DELAI => ['delai', 'retard', 'rapid', 'lent', 'lenteur', 'attend', 'attente', 'vite\b', 'tard\b', 'temps\b', 'urgen', 'heure\b', 'a l.heure', 'dans les temps', 'long\b', 'longue'],
        self::PRIX => ['prix', 'chere?s?\b', 'tarif', 'cout', 'remise', 'reduction', 'promo', 'abordable', 'moins cher', 'francs?\b', 'fcfa', 'facture', 'paiement', 'payer'],
        self::QUALITE => ['qualite', 'couleur', 'impression', 'imprim', 'flou', 'pixel', 'nett', 'papier', 'bache', 'decoup', 'finition', 'rendu', 'encre', 'defaut', 'pale', 'fonce', 'resolution', 'logo', 'texte', 'faute', 'bat\b', 'oeillet', 'lamin'],
        self::ACCUEIL => ['accueil', 'accueill', 'aimable', 'poli', 'polie', 'sourire', 'souriant', 'gentil', 'personnel', 'agent', 'vendeu', 'caissier', 'caissiere', 'reception', 'ecoute', 'conseil', 'respect', 'courtois', 'repond', 'reponse', 'whatsapp', 'appel'],
        self::LIVRAISON => ['livr', 'livreur', 'transport', 'deplac', 'adresse', 'colis', 'retrait', 'recuper', 'domicile', 'expedi', 'moto'],
    ];

    /** Thème d'une proposition, ou null quand il n'y a pas de texte. */
    public static function classer(?string $texte): ?string
    {
        $texte = trim((string) $texte);
        if ('' === $texte) {
            return null;
        }
        $propre = ' '.preg_replace('/[^a-z0-9]+/', ' ', (new UnicodeString($texte))->ascii()->lower()->toString()).' ';
        $meilleur = self::AUTRE;
        $score = 0;
        foreach (self::MOTS as $theme => $mots) {
            $n = 0;
            foreach ($mots as $mot) {
                $n += preg_match_all('/\b'.$mot.'/', $propre);
            }
            // À égalité, le premier thème du cahier l'emporte.
            if ($n > $score) {
                [$meilleur, $score] = [$theme, $n];
            }
        }

        return $meilleur;
    }

    public static function libelle(?string $theme): string
    {
        return self::THEMES[$theme] ?? self::THEMES[self::AUTRE];
    }
}
