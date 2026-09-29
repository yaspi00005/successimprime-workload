<?php

namespace App\Controller;

use App\Repository\JournalActiviteRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/journal-activite')]
final class JournalActiviteController extends AbstractController
{
    private const PAR_PAGE = 40;

    #[Route('', name: 'app_journal_activite_index', methods: ['GET'])]
    public function index(Request $request, JournalActiviteRepository $repository): Response
    {
        $filtres = [
            'entite' => $request->query->get('entite', ''),
            'action' => $request->query->get('action', ''),
            'entite_id' => $request->query->get('entite_id', ''),
            'date_debut' => $request->query->get('date_debut', ''),
            'date_fin' => $request->query->get('date_fin', ''),
        ];

        $page = max(1, $request->query->getInt('page', 1));

        $resultat = $repository->rechercher($filtres, $page, self::PAR_PAGE);
        $pageCount = (int) max(1, ceil($resultat['total'] / self::PAR_PAGE));

        return $this->render('journal_activite/index.html.twig', [
            'journaux' => $resultat['resultats'],
            'total' => $resultat['total'],
            'page' => $page,
            'pageCount' => $pageCount,
            'filtres' => $filtres,
            'entitesDisponibles' => $repository->listerEntites(),
        ]);
    }

    #[Route('/{id}', name: 'app_journal_activite_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, JournalActiviteRepository $repository): Response
    {
        $journal = $repository->find($id);

        if (!$journal) {
            throw $this->createNotFoundException('Entrée de journal introuvable.');
        }

        $champs = array_values(array_unique(array_merge(
            array_keys($journal->getDonneesAvant() ?? []),
            array_keys($journal->getDonneesApres() ?? [])
        )));

        sort($champs);

        return $this->render('journal_activite/show.html.twig', [
            'journal' => $journal,
            'champs' => $champs,
        ]);
    }
}
