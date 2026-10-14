<?php

namespace App\Service\Assistant;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Lecture des appels du panneau de l'assistant (public/js/assistant.js) :
 * du JSON avec l'en-tête X-Assistant, qu'un formulaire d'un autre site ne
 * peut pas envoyer sans y être autorisé.
 */
final class RequeteAssistant
{
    public const CORPS_MAX = 8192;

    /** @return array<string, mixed>|null */
    public static function lire(Request $request): ?array
    {
        if ('json' !== $request->getContentTypeFormat() || '1' !== $request->headers->get('X-Assistant')) {
            return null;
        }
        $contenu = $request->getContent();
        if (\strlen($contenu) > self::CORPS_MAX) {
            return null;
        }
        $donnees = json_decode($contenu, true);

        return \is_array($donnees) ? $donnees : null;
    }

    public static function chaine(array $donnees, string $cle, int $max = 200): string
    {
        $valeur = $donnees[$cle] ?? '';

        return \is_string($valeur) ? mb_substr($valeur, 0, $max) : '';
    }

    /** Chemin de la page ouverte, sans paramètres ni domaine. */
    public static function page(mixed $valeur): ?string
    {
        if (!\is_string($valeur)) {
            return null;
        }
        $chemin = (string) parse_url($valeur, \PHP_URL_PATH);

        return 1 === preg_match('~^/[A-Za-z0-9/_\-.]{0,200}$~', $chemin) ? $chemin : null;
    }

    /** Note envoyée par le panneau : 1, -1, ou null pour retirer l'avis. */
    public static function note(mixed $valeur): ?int
    {
        return \in_array($valeur, [1, -1], true) ? $valeur : null;
    }

    /** Réponse JSON jamais gardée en cache. */
    public static function json(array $donnees, int $statut = 200): JsonResponse
    {
        $reponse = new JsonResponse($donnees, $statut);
        $reponse->headers->set('Cache-Control', 'no-store, private');
        $reponse->headers->set('X-Robots-Tag', 'noindex');

        return $reponse;
    }

    public static function erreur(string $message, int $statut): JsonResponse
    {
        return self::json(['erreur' => $message], $statut);
    }
}
