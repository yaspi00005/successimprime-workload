<?php

namespace App\Controller;

use App\Entity\Machines;
use App\Repository\ReleveCompteurRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Atelier › Relevés de compteurs (M02) : les machines amorties à l'usage
 * dont le compteur se relève chaque mois, avec le relevé du mois dernier
 * fait ou à faire. Ouvert à la production, sans aucun montant ; la saisie
 * passe par la page du relevé (ImmobilisationsController::releve).
 */
final class AtelierRelevesController extends AbstractController
{
    #[Route('/atelier/releves', name: 'app_atelier_releves', methods: ['GET'])]
    public function index(EntityManagerInterface $em, ReleveCompteurRepository $releves): Response
    {
        if (!$this->isGranted('ROLE_PRODUCTION_GERER') && !$this->isGranted('ROLE_COMPTABILITE')) {
            throw $this->createAccessDeniedException();
        }

        $machines = array_values(array_filter(
            $em->getRepository(Machines::class)->findBy([], ['nom' => 'ASC']),
            static fn (Machines $m): bool => null !== $m->getReleveCompteurs(),
        ));
        $parMachine = $releves->deMachines($machines);
        $moisDernier = (new \DateTimeImmutable('first day of last month'))->setTime(0, 0);

        $lignes = [];
        foreach ($machines as $m) {
            $faits = $parMachine[(int) $m->getId()] ?? [];
            $lignes[] = [
                'machine' => $m,
                'fait' => isset($faits[$moisDernier->format('Y-m')]),
                'dernier' => [] !== $faits ? end($faits) : null,
            ];
        }

        return $this->render('atelier/releves.html.twig', [
            'lignes' => $lignes,
            'mois' => $moisDernier,
        ]);
    }
}
