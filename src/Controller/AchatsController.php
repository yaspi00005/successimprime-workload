<?php

namespace App\Controller;

use App\Entity\Achats;
use App\Entity\Fournisseurs;
use App\Entity\StockEntrees;
use App\Form\AchatType;
use App\Repository\AchatsRepository;
use App\Repository\FournisseursRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/achats')]
final class AchatsController extends AbstractController
{
    #[Route('', name: 'app_achat_index', methods: ['GET'])]
    public function index(
        Request $request,
        AchatsRepository $achatsRepository
    ): Response {
        $statut = $request->query->get('statut', Achats::STATUT_DEMANDE);

        if (!in_array($statut, Achats::STATUTS, true)) {
            $statut = Achats::STATUT_DEMANDE;
        }

        return $this->render('achats/index.html.twig', [
            'achats' => $achatsRepository->findParStatut($statut),
            'statut' => $statut,
        ]);
    }

    #[Route('/new', name: 'app_achat_new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        FournisseursRepository $fournisseursRepository
    ): Response {
        $achat = new Achats();

        $fournisseurId = $request->query->getInt('fournisseur');

        if ($fournisseurId > 0) {
            $fournisseur = $fournisseursRepository->find($fournisseurId);

            if ($fournisseur instanceof Fournisseurs) {
                $achat->setFournisseur($fournisseur);
            }
        }

        $form = $this->createForm(AchatType::class, $achat);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $achat->recalculerTotaux();

            $entityManager->persist($achat);
            $entityManager->flush();

            $achat->setNumero(
                sprintf(
                    'ACH-%06d-%s',
                    $achat->getId(),
                    (new \DateTimeImmutable())->format('m-Y')
                )
            );

            $entityManager->flush();

            $this->addFlash(
                'success',
                sprintf(
                    'La demande d’achat %s a été enregistrée.',
                    $achat->getNumero()
                )
            );

            return $this->redirectToRoute(
                'app_achat_show',
                ['id' => $achat->getId()],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('achats/new.html.twig', [
            'form' => $form,
        ]);
    }

    #[Route(
        '/{id}',
        name: 'app_achat_show',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function show(Achats $achat): Response
    {
        return $this->render('achats/show.html.twig', [
            'achat' => $achat,
        ]);
    }

    #[Route(
        '/{id}/edit',
        name: 'app_achat_edit',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST']
    )]
    public function edit(
        Request $request,
        Achats $achat,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$achat->estEnDemande()) {
            $this->addFlash(
                'error',
                'Seule une demande d’achat en attente peut être modifiée.'
            );

            return $this->redirectToRoute(
                'app_achat_show',
                ['id' => $achat->getId()],
                Response::HTTP_SEE_OTHER
            );
        }

        $form = $this->createForm(AchatType::class, $achat);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $achat->recalculerTotaux();

            $entityManager->flush();

            $this->addFlash(
                'success',
                'La demande d’achat a été modifiée avec succès.'
            );

            return $this->redirectToRoute(
                'app_achat_show',
                ['id' => $achat->getId()],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('achats/edit.html.twig', [
            'achat' => $achat,
            'form' => $form,
        ]);
    }

    #[Route(
        '/{id}/recevoir',
        name: 'app_achat_recevoir',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function recevoir(
        Request $request,
        Achats $achat,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isCsrfTokenValid(
            'recevoir_achat' . $achat->getId(),
            $request->getPayload()->getString('_token')
        )) {
            throw $this->createAccessDeniedException(
                'Jeton de sécurité invalide.'
            );
        }

        if (!$achat->estEnDemande()) {
            $this->addFlash(
                'error',
                'Cet achat a déjà été traité.'
            );

            return $this->redirectToRoute(
                'app_achat_show',
                ['id' => $achat->getId()],
                Response::HTTP_SEE_OTHER
            );
        }

        if ($achat->getLignes()->isEmpty()) {
            $this->addFlash(
                'error',
                'Cet achat ne contient aucune ligne à réceptionner.'
            );

            return $this->redirectToRoute(
                'app_achat_show',
                ['id' => $achat->getId()],
                Response::HTTP_SEE_OTHER
            );
        }

        $maintenant = new \DateTimeImmutable();

        /*
         * Chaque ligne d'achat reçue génère une entrée de stock,
         * exactement comme l'entrée de stock manuelle sur une fiche
         * article (StockService additionne StockEntrees - StockSorties).
         */
        foreach ($achat->getLignes() as $ligne) {
            $article = $ligne->getArticle();

            if ($article === null) {
                continue;
            }

            $entree = new StockEntrees();
            $entree->setArticle($article);
            $entree->setQuantites($ligne->getQuantite());
            $entree->setPrix($ligne->getPrixUnitaire());
            $entree->setDate($maintenant);

            $entityManager->persist($entree);

            if ($ligne->getPrixUnitaire() > 0) {
                $article->setPrixAchat($ligne->getPrixUnitaire());
            }
        }

        $achat->setStatut(Achats::STATUT_RECU);
        $achat->setDateReception($maintenant);

        $entityManager->flush();

        $this->addFlash(
            'success',
            sprintf(
                'L’achat %s a été réceptionné, le stock a été mis à jour.',
                $achat->getNumero()
            )
        );

        return $this->redirectToRoute(
            'app_achat_show',
            ['id' => $achat->getId()],
            Response::HTTP_SEE_OTHER
        );
    }

    #[Route(
        '/{id}/annuler',
        name: 'app_achat_annuler',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function annuler(
        Request $request,
        Achats $achat,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isCsrfTokenValid(
            'annuler_achat' . $achat->getId(),
            $request->getPayload()->getString('_token')
        )) {
            throw $this->createAccessDeniedException(
                'Jeton de sécurité invalide.'
            );
        }

        if (!$achat->estEnDemande()) {
            $this->addFlash(
                'error',
                'Seule une demande d’achat en attente peut être annulée.'
            );

            return $this->redirectToRoute(
                'app_achat_show',
                ['id' => $achat->getId()],
                Response::HTTP_SEE_OTHER
            );
        }

        $achat->setStatut(Achats::STATUT_ANNULE);
        $entityManager->flush();

        $this->addFlash(
            'success',
            'La demande d’achat a été annulée.'
        );

        return $this->redirectToRoute(
            'app_achat_show',
            ['id' => $achat->getId()],
            Response::HTTP_SEE_OTHER
        );
    }

    #[Route(
        '/{id}/delete',
        name: 'app_achat_delete',
        requirements: ['id' => '\d+'],
        methods: ['POST']
    )]
    public function delete(
        Request $request,
        Achats $achat,
        EntityManagerInterface $entityManager
    ): Response {
        if ($this->isCsrfTokenValid(
            'delete' . $achat->getId(),
            $request->getPayload()->getString('_token')
        )) {
            if (!$achat->estEnDemande()) {
                $this->addFlash(
                    'error',
                    'Seule une demande d’achat en attente peut être supprimée.'
                );

                return $this->redirectToRoute(
                    'app_achat_show',
                    ['id' => $achat->getId()],
                    Response::HTTP_SEE_OTHER
                );
            }

            $entityManager->remove($achat);
            $entityManager->flush();

            $this->addFlash(
                'success',
                'La demande d’achat a été supprimée.'
            );
        }

        return $this->redirectToRoute(
            'app_achat_index',
            [],
            Response::HTTP_SEE_OTHER
        );
    }
}
