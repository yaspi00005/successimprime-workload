<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sauvegarde en session la saisie en cours d'une commande ou d'un
 * devis "nouveau", pour pouvoir la restaurer si la page se ferme
 * ou plante avant l'enregistrement définitif.
 *
 * Les brouillons ne sont jamais persistés en base : ils vivent
 * uniquement dans la session de l'agent connecté et disparaissent
 * avec elle (déconnexion, expiration).
 */
#[Route('/brouillon')]
final class BrouillonSaisieController extends AbstractController
{
    private const TYPES_AUTORISES = [
        'commande' => 'commandes',
        'devis' => 'devis',
    ];

    #[Route(
        '/{type}',
        name: 'app_brouillon_enregistrer',
        requirements: ['type' => 'commande|devis'],
        methods: ['POST']
    )]
    public function enregistrer(string $type, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(
            'brouillon-' . $type,
            $request->headers->get('X-CSRF-TOKEN')
        )) {
            return $this->json([
                'message' => 'Jeton CSRF invalide.',
            ], 403);
        }

        $racine = self::TYPES_AUTORISES[$type];
        $champs = $request->request->all($racine);

        if ($champs === []) {
            return $this->json([
                'message' => 'Aucune donnée à enregistrer.',
            ], 422);
        }

        $request->getSession()->set('brouillon_' . $type, [
            'champs' => $champs,
            'date' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        return $this->json(['success' => true]);
    }

    #[Route(
        '/{type}',
        name: 'app_brouillon_verifier',
        requirements: ['type' => 'commande|devis'],
        methods: ['GET']
    )]
    public function verifier(string $type, Request $request): JsonResponse
    {
        $brouillon = $request->getSession()->get('brouillon_' . $type);

        if (!is_array($brouillon) || empty($brouillon['champs'])) {
            return $this->json(['existe' => false]);
        }

        return $this->json([
            'existe' => true,
            'date' => $brouillon['date'] ?? null,
        ]);
    }

    #[Route(
        '/{type}/supprimer',
        name: 'app_brouillon_supprimer',
        requirements: ['type' => 'commande|devis'],
        methods: ['POST']
    )]
    public function supprimer(string $type, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(
            'brouillon-' . $type,
            $request->headers->get('X-CSRF-TOKEN')
        )) {
            return $this->json([
                'message' => 'Jeton CSRF invalide.',
            ], 403);
        }

        $request->getSession()->remove('brouillon_' . $type);

        return $this->json(['success' => true]);
    }
}
