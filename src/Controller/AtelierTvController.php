<?php

namespace App\Controller;

use App\Repository\MachinesRepository;
use App\Service\Atelier\AtelierProduction;
use App\Service\EcranTv;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran TV de l'atelier sans compte (docs/atelier-production.md) : la TV de
 * l'atelier ouvre le lien secret de l'écran TV (Réglages › Écran TV) suivi
 * de « /atelier ». Toutes les machines, sans nom de client ni montant, la
 * page se recharge chaque minute. Le lien changé dans les réglages coupe
 * aussi celui-ci. ERP local seulement (AtelierHorsLigneSubscriber).
 */
final class AtelierTvController extends AbstractController
{
    #[Route('/ecran-accueil/{jeton}/atelier', name: 'app_tv_atelier', requirements: ['jeton' => '[A-Za-z0-9_-]{24,64}'], methods: ['GET'])]
    public function ecran(string $jeton, EcranTv $ecranTv, AtelierProduction $atelier, MachinesRepository $machines): Response
    {
        if (!$ecranTv->jetonValide($jeton)) {
            throw $this->createNotFoundException();
        }
        $maintenant = new \DateTimeImmutable();
        $reponse = $this->render('atelier_production/tv.html.twig', $atelier->vueDirecte($machines->findBy([], ['nom' => 'ASC']), $maintenant) + [
            'maintenant' => $maintenant,
            'retour' => null,
        ]);
        $reponse->headers->set('Cache-Control', 'no-store');
        $reponse->headers->set('X-Robots-Tag', 'noindex');

        return $reponse;
    }
}
