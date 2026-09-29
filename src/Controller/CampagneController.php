<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\CampagneDestinataireRepository;
use App\Repository\CampagneRepository;
use App\Repository\ClientsRepository;
use App\Repository\ModeleMessageRepository;
use App\Service\CampagneService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Campagnes de communication (promo, vœux de fêtes...) envoyées en
 * masse à une sélection de clients. L'envoi réel est étalé dans le
 * temps par CampagneService, via la commande app:envoyer-campagnes
 * (tâche planifiée) -- créer une campagne ici ne fait que la mettre
 * en file d'attente.
 */
#[Route('/communications/campagnes', name: 'app_campagne_')]
#[IsGranted('ROLE_ADMIN')]
final class CampagneController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        CampagneRepository $campagneRepository,
        CampagneDestinataireRepository $destinataireRepository
    ): Response {
        $campagnes = $campagneRepository->findToutes();

        $compteurs = [];

        foreach ($campagnes as $campagne) {
            $compteurs[$campagne->getId()] = $destinataireRepository->compterParStatut($campagne);
        }

        return $this->render('campagne/index.html.twig', [
            'campagnes' => $campagnes,
            'compteurs' => $compteurs,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        ModeleMessageRepository $modeleMessageRepository,
        ClientsRepository $clientsRepository,
        CampagneService $campagneService
    ): Response {
        $modeles = $modeleMessageRepository->findTous();
        $clients = $clientsRepository->findActifsPourCampagne();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('campagne_new', $request->getPayload()->getString('_token'))) {
                $this->addFlash('danger', 'Jeton de sécurité invalide, merci de réessayer.');

                return $this->redirectToRoute('app_campagne_new');
            }

            $nom = trim($request->getPayload()->getString('nom'));
            $modeleId = $request->getPayload()->getInt('modele');
            $clientIds = array_map('intval', $request->getPayload()->all('clients'));
            $senderName = trim($request->getPayload()->getString('senderName'));

            $modele = $modeleMessageRepository->find($modeleId);

            $erreurs = [];

            if ($nom === '') {
                $erreurs[] = 'Le nom de la campagne est obligatoire.';
            }

            if ($modele === null || !$modele->isActif()) {
                $erreurs[] = 'Veuillez sélectionner un modèle de message valide.';
            }

            if ($clientIds === []) {
                $erreurs[] = 'Sélectionnez au moins un client.';
            }

            if ($erreurs === []) {
                $clientsSelectionnes = array_values(array_filter(
                    array_map(
                        static fn (int $id) => $clientsRepository->find($id),
                        $clientIds
                    )
                ));

                $utilisateur = $this->getUser();

                $campagne = $campagneService->creerEtLancer(
                    $nom,
                    $modele,
                    $clientsSelectionnes,
                    $utilisateur instanceof User ? $utilisateur : null,
                    $senderName !== '' ? $senderName : null
                );

                $this->addFlash(
                    'success',
                    sprintf(
                        'La campagne « %s » a été créée pour %d client(s). L’envoi se fait progressivement en arrière-plan.',
                        $campagne->getNom(),
                        count($clientsSelectionnes)
                    )
                );

                return $this->redirectToRoute('app_campagne_show', ['id' => $campagne->getId()]);
            }

            foreach ($erreurs as $erreur) {
                $this->addFlash('danger', $erreur);
            }
        }

        return $this->render('campagne/new.html.twig', [
            'modeles' => $modeles,
            'clients' => $clients,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        int $id,
        CampagneRepository $campagneRepository,
        CampagneDestinataireRepository $destinataireRepository
    ): Response {
        $campagne = $campagneRepository->find($id);

        if ($campagne === null) {
            throw $this->createNotFoundException('Campagne introuvable.');
        }

        return $this->render('campagne/show.html.twig', [
            'campagne' => $campagne,
            'destinataires' => $destinataireRepository->findParCampagne($campagne),
            'compteurs' => $destinataireRepository->compterParStatut($campagne),
        ]);
    }
}
