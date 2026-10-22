<?php

namespace App\Controller;

use App\Service\SuiviMetiers\SuiviEquipes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Atelier › Équipes en direct (maquette W06). Les points servent aux
 * primes : réservé au responsable (droit Administrateur RH).
 */
#[Route('/atelier/equipes', name: 'app_atelier_equipes')]
#[IsGranted('ROLE_RH')]
final class SuiviEquipesController extends AbstractController
{
    #[Route('', name: '', methods: ['GET'])]
    public function index(SuiviEquipes $suivi): Response
    {
        $maintenant = new \DateTimeImmutable();

        return $this->render('suivi_equipes/index.html.twig', $suivi->direct($maintenant) + ['maintenant' => $maintenant]);
    }
}
