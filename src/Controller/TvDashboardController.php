<?php

namespace App\Controller;

use App\Service\EcranTv;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran TV de l'imprimerie (démo de Yaya et maquette 42).
 *
 * La Smart TV ouvre un lien secret, sans compte utilisateur : voir
 * security.yaml, section PUBLIC. Le lien se change dans Réglages >
 * Écran TV. Seuls des volumes, des produits et des numéros de commande
 * sont affichés, jamais de montant ni de nom de client.
 */
final class TvDashboardController extends AbstractController
{
    public function __construct(private readonly EcranTv $ecranTv)
    {
    }

    /**
     * L'ancienne adresse sans jeton : l'admin est conduit à l'aperçu,
     * les autres ne voient rien.
     */
    #[Route('/ecran-accueil', name: 'app_tv_dashboard', methods: ['GET'])]
    public function sansJeton(): Response
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_ecran_tv_apercu');
        }

        throw $this->createNotFoundException();
    }

    #[Route('/ecran-accueil/{jeton}', name: 'app_tv_ecran', requirements: ['jeton' => '[A-Za-z0-9_-]{24,64}'], methods: ['GET'])]
    public function ecran(string $jeton): Response
    {
        $this->verifierJeton($jeton);
        $this->ecranTv->noterPassage();

        $reponse = $this->render('tv_dashboard/index.html.twig', $this->ecranTv->vue(new \DateTimeImmutable(), false) + [
            'urlDonnees' => $this->generateUrl('app_tv_ecran_donnees', ['jeton' => $jeton]),
        ]);
        $reponse->headers->set('Cache-Control', 'no-store');
        $reponse->headers->set('X-Robots-Tag', 'noindex');

        return $reponse;
    }

    #[Route('/ecran-accueil/{jeton}/donnees', name: 'app_tv_ecran_donnees', requirements: ['jeton' => '[A-Za-z0-9_-]{24,64}'], methods: ['GET'])]
    public function donnees(string $jeton): JsonResponse
    {
        $this->verifierJeton($jeton);
        $this->ecranTv->noterPassage();

        $reponse = $this->json($this->ecranTv->rafraichissement(new \DateTimeImmutable(), false));
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }

    private function verifierJeton(string $jeton): void
    {
        if (!$this->ecranTv->jetonValide($jeton)) {
            throw $this->createNotFoundException();
        }
    }
}
