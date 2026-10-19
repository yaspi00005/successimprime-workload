<?php

namespace App\Service\Alertes;

use App\Entity\AlerteSysteme;
use App\Service\Messagerie\JournalEnvois;

/**
 * Ce qu'une alerte système a le droit de garder (docs/alertes-systeme.md) :
 * jamais de mot de passe, de clé, de jeton, de numéro ni d'adresse e-mail,
 * jamais de montant, jamais les paramètres d'une adresse (?code=…).
 *
 * Le titre (le seul texte de l'alerte qui part dans l'e-mail et la
 * notification) ne contient que la sorte, la zone, la route et la classe
 * de l'erreur. Le message de l'erreur, nettoyé, ne va que dans le détail,
 * lu sur l'écran par la direction et le technicien.
 */
final class NettoyageAlerte
{
    /** Lignes de la pile d'appels gardées dans le détail. */
    public const PILE_MAX = 12;

    /**
     * Texte sans secret : adresses, numéros et montants masqués
     * (JournalEnvois::nettoyer), identifiants dans une adresse de connexion
     * (mysql://user:motdepasse@…), « password=… », « token: … », en-têtes
     * d'autorisation, longues suites hexadécimales ou base64 (jetons, clés).
     */
    public static function texte(string $texte, int $longueur = 2000): string
    {
        // Identifiants d'une adresse de connexion (DSN) : schéma://utilisateur:motdepasse@hôte.
        $texte = (string) preg_replace('#\b([a-z][a-z0-9+.-]*://)[^\s:/@]+:[^\s@/]*@#i', '$1***:***@', $texte);
        // Paramètres d'une adresse : ?code=…&jeton=… (liens à code des factures, sondages, paiements).
        $texte = (string) preg_replace('#(\?[^\s"\'<>]*)#', '?…', $texte);
        // « mot de passe = … », « token: … », « Authorization: Bearer … ».
        $texte = (string) preg_replace('/\b(bearer|basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 ***', $texte);
        $texte = (string) preg_replace('/\b(pass(?:word|wd)?|mot[_ ]de[_ ]passe|mdp|pwd|secret|token|jeton|api[_-]?key|cle|key|authorization|cookie|session|code|pin|otp|signature)(["\']?\s*[:=]\s*)(["\']?)[^\s"\',;)}]+/iu', '$1$2$3***', $texte);
        // Longues suites qui ressemblent à une clé ou un jeton.
        $texte = (string) preg_replace('/\b[A-Fa-f0-9]{32,}\b/', '***', $texte);
        $texte = (string) preg_replace('#(?<![\w/.-])[A-Za-z0-9+/_-]{40,}={0,2}(?![\w/.-])#', '***', $texte);
        $texte = JournalEnvois::nettoyer($texte);

        return mb_substr(trim($texte), 0, $longueur);
    }

    /** Titre court, une ligne, sans secret. */
    public static function titre(string $titre): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', self::texte($titre, AlerteSysteme::LONGUEUR_TITRE))), 0, AlerteSysteme::LONGUEUR_TITRE);
    }

    /** Chemin d'un fichier à partir du dossier du projet (jamais le chemin complet du serveur). */
    public static function chemin(string $fichier, string $dossierProjet): string
    {
        $dossierProjet = rtrim($dossierProjet, '/\\');

        return '' !== $dossierProjet && str_starts_with($fichier, $dossierProjet) ? ltrim(substr($fichier, \strlen($dossierProjet)), '/\\') : basename($fichier);
    }

    /**
     * Détail technique d'une erreur : classe, message nettoyé, fichier et
     * ligne, pile d'appels (fichier, ligne, fonction : jamais les valeurs
     * passées), et l'erreur d'origine.
     *
     * @param array<string, string|null> $contexte lignes en plus (Page, Méthode…)
     */
    public static function detailErreur(\Throwable $e, string $dossierProjet, array $contexte = []): string
    {
        $lignes = [];
        foreach ($contexte as $libelle => $valeur) {
            if (null !== $valeur && '' !== $valeur) {
                $lignes[] = $libelle.' : '.self::texte($valeur, 300);
            }
        }
        $niveau = 0;
        for ($erreur = $e; null !== $erreur && $niveau < 3; $erreur = $erreur->getPrevious(), ++$niveau) {
            if ($niveau > 0) {
                $lignes[] = '';
                $lignes[] = 'Erreur d\'origine :';
            }
            $lignes[] = 'Erreur : '.$erreur::class;
            $lignes[] = 'Message : '.self::texte($erreur->getMessage(), 1500);
            $lignes[] = 'Fichier : '.self::chemin($erreur->getFile(), $dossierProjet).':'.$erreur->getLine();
            $pile = [];
            foreach (\array_slice($erreur->getTrace(), 0, self::PILE_MAX) as $i => $appel) {
                $ou = isset($appel['file']) ? self::chemin($appel['file'], $dossierProjet).':'.($appel['line'] ?? '?') : '[interne]';
                $quoi = ($appel['class'] ?? '').($appel['type'] ?? '').($appel['function'] ?? '');
                $pile[] = '#'.$i.' '.$ou.' '.$quoi.'()';
            }
            if ([] !== $pile) {
                $lignes[] = 'Pile d\'appels :';
                array_push($lignes, ...$pile);
            }
        }

        return mb_substr(implode("\n", $lignes), 0, AlerteSysteme::LONGUEUR_DETAIL);
    }

    /**
     * Empreinte d'une panne : même sorte et mêmes éléments (classe, fichier,
     * ligne, route…) = même alerte.
     */
    public static function empreinte(string $type, string ...$elements): string
    {
        return sha1($type."\0".implode("\0", array_map(static fn (string $e): string => mb_strtolower(trim($e)), $elements)));
    }

    /**
     * Message ramené à sa forme : chiffres (numéros de commande, durées,
     * identifiants) et contenu entre guillemets retirés, pour qu'une même
     * panne ne fasse pas une alerte par commande.
     */
    public static function forme(string $message): string
    {
        $message = (string) preg_replace('/(["«\'])[^"»\']*(["»\'])/u', '$1…$2', $message);

        return mb_substr((string) preg_replace('/(?<![\p{L}\d])(?=[\p{L}\d]*\d)(?=[\p{L}\d]*\p{L})[\p{L}\d]{6,}(?![\p{L}\d])|\d+/iu', '#', $message), 0, 300);
    }

    /**
     * Zone de la page où l'erreur est arrivée, d'après son adresse :
     * espace client, site public, synchronisation, API ou administration.
     */
    public static function zone(string $chemin): string
    {
        return match (true) {
            str_starts_with($chemin, '/espace') => 'Espace client',
            str_starts_with($chemin, '/api/synchro') => 'Synchronisation',
            str_starts_with($chemin, '/api') => 'API',
            str_starts_with($chemin, '/om/') => 'Retour Orange Money',
            1 === preg_match('#^/($|site|nos-produits|calculateur|studio-textile|atelier-3d|realisations|equipements|guide-fichiers|a-propos|nos-engagements|faq|suivre-ma-commande|contact|conseils|demander-un-devis|nos-actions-sociales|nous-soutenir|avis/|f/)#', $chemin) => 'Site public',
            default => 'Administration',
        };
    }
}
