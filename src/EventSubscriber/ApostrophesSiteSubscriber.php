<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Apostrophes lisibles dans le code des pages du site vitrine
 * (docs/referencement.md, « Apostrophes »).
 *
 * Cause : l'échappement HTML de Twig (htmlspecialchars avec ENT_QUOTES)
 * écrit chaque apostrophe d'un texte « &#039; ». C'est du HTML valide, que
 * les navigateurs affichent bien, mais les outils qui lisent le code de la
 * page sans le décoder (analyse de référencement, certains aperçus de
 * partage, lecteurs de texte brut) montrent « l&#039;imprimerie » dans les
 * textes des images, les titres de partage et les descriptions.
 *
 * Sur les pages du site, l'apostrophe n'a pas besoin d'être échappée : les
 * gabarits de templates/site n'écrivent une valeur que dans le texte ou dans
 * un attribut entre guillemets doubles, jamais entre apostrophes ni dans
 * une chaîne de script (vérifié par ApostrophesSiteSubscriberTest). La page
 * est donc rendue avec de vraies apostrophes ; « & », « < », « > » et « " »
 * restent échappés. Le reste de l'application (administration, espace
 * client) garde l'échappement de Twig tel quel.
 */
final class ApostrophesSiteSubscriber implements EventSubscriberInterface
{
    /** Marque du gabarit du site (site/_site.html.twig). */
    public const MARQUE = '<body class="si-site">';

    public static function getSubscribedEvents(): array
    {
        // Avant le calcul des en-têtes de cache par d'autres abonnés : rien d'autre ne lit le contenu ici.
        return [KernelEvents::RESPONSE => ['adoucir', 10]];
    }

    public function adoucir(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $route = (string) $event->getRequest()->attributes->get('_route', '');
        if (!self::pageDuSite($route)) {
            return;
        }
        $reponse = $event->getResponse();
        $type = (string) $reponse->headers->get('Content-Type', 'text/html');
        $contenu = $reponse->getContent();
        if (!str_starts_with($type, 'text/html') || false === $contenu || !str_contains($contenu, self::MARQUE)) {
            return;
        }
        // L'ETag de « / » est calculé sur ce même contenu adouci (SiteController::cache).
        $reponse->setContent(self::apostrophes($contenu));
    }

    /** Pages du site vitrine (l'accueil « / » sert aussi le tableau de bord : la marque du gabarit tranche). */
    public static function pageDuSite(string $route): bool
    {
        return 'app_home' === $route || str_starts_with($route, 'app_site_');
    }

    /** « l&#039;imprimerie » devient « l'imprimerie » ; un « &amp;#039; » tapé tel quel reste du texte. */
    public static function apostrophes(string $html): string
    {
        return str_replace('&#039;', "'", $html);
    }
}
