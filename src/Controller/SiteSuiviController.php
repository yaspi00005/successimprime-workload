<?php

namespace App\Controller;

use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\SuiviSansCompte;
use App\Service\Site\SuiviSite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Suivre ma commande » du site (maquette 10) : numéro de commande et
 * téléphone, sans compte. Même recherche que /espace/suivre (même limite,
 * même réponse si la commande n'existe pas ou n'est pas à ce numéro) ;
 * seulement les étapes, jamais les montants, le nom ni les fichiers.
 */
final class SiteSuiviController extends AbstractController
{
    #[Route('/suivre-ma-commande', name: 'app_site_suivi', methods: ['GET', 'POST'])]
    public function suivre(Request $request, SuiviSansCompte $suivi, EspaceClient $espace): Response
    {
        $carte = null;
        $erreur = null;
        $numero = mb_substr(trim((string) $request->request->get('numero', $request->query->get('numero', ''))), 0, 50);
        $telephone = mb_substr(trim((string) $request->request->get('telephone', '')), 0, 20);

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('site-suivi', (string) $request->request->get('_token'))) {
                $erreur = 'La page a expiré. Réessayez.';
            } elseif ('' === $numero || '' === $telephone) {
                $erreur = 'Indiquez le numéro de la commande et votre téléphone.';
            } else {
                ['commande' => $commande, 'erreur' => $erreur] = $suivi->chercher($numero, $telephone, (string) $request->getClientIp());
                $carte = null !== $commande ? $espace->carte($commande) : null;
            }
        }

        $maintenant = new \DateTimeImmutable();
        $reponse = $this->render('site/suivi.html.twig', [
            'carte' => $carte,
            'pastille' => null !== $carte ? SuiviSite::pastille($carte, $maintenant) : null,
            'etapes' => null !== $carte ? SuiviSite::etapes($carte['etapes'], $maintenant) : [],
            'numero' => $numero,
            'telephone' => $telephone,
            'erreur' => $erreur,
        ], new Response(null, null !== $erreur ? 422 : 200));
        // Page de recherche : jamais dans les moteurs, jamais gardée en cache.
        $reponse->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $reponse->setPrivate();
        $reponse->headers->addCacheControlDirective('no-store');

        return $reponse;
    }
}
