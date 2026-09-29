<?php

namespace App\Controller;

use App\Entity\CompteTresorerie;
use App\Form\CompteTresorerieType;
use App\Repository\CompteTresorerieRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/gestion/tresorerie/comptes', name: 'app_compte_tresorerie_')]
#[IsGranted('ROLE_ADMIN')]
class CompteTresorerieController extends AbstractController
{
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        CompteTresorerieRepository $repository
    ): Response {
        $recherche = trim(
            (string) $request->query->get('recherche', '')
        );

        $type = trim(
            (string) $request->query->get('type', '')
        );

        if (!in_array(
            $type,
            CompteTresorerie::getTypesDisponibles(),
            true
        )) {
            $type = null;
        }

        $actifParametre = $request->query->get('actif');
        $actif = null;

        if ($actifParametre === '1') {
            $actif = true;
        } elseif ($actifParametre === '0') {
            $actif = false;
        }

        $comptes = $repository
            ->creerQueryBuilderListe(
                $recherche !== '' ? $recherche : null,
                $type,
                $actif
            )
            ->getQuery()
            ->getResult();

        return $this->render(
            'compte_tresorerie/index.html.twig',
            [
                'comptes' => $comptes,
                'recherche' => $recherche,
                'type_selectionne' => $type,
                'actif_selectionne' => $actifParametre,
                'types' => CompteTresorerie::getTypesPourFormulaire(),
                'solde_total' => $repository->calculerSoldeTotalActif(),
            ]
        );
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager,
        CompteTresorerieRepository $repository
    ): Response {
        $compte = new CompteTresorerie();

        $form = $this->createForm(
            CompteTresorerieType::class,
            $compte
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($repository->codeExiste($compte->getCode())) {
                $this->addFlash(
                    'error',
                    sprintf(
                        'Le code « %s » est déjà utilisé.',
                        $compte->getCode()
                    )
                );
            } else {
                try {
                    $entityManager->persist($compte);
                    $entityManager->flush();

                    $this->addFlash(
                        'success',
                        'Le compte de trésorerie a été créé avec succès.'
                    );

                    return $this->redirectToRoute(
                        'app_compte_tresorerie_show',
                        ['id' => $compte->getId()]
                    );
                } catch (UniqueConstraintViolationException) {
                    $this->addFlash(
                        'error',
                        'Ce code de compte existe déjà.'
                    );
                }
            }
        }

        return $this->render(
            'compte_tresorerie/new.html.twig',
            [
                'compte' => $compte,
                'form' => $form,
            ]
        );
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(
        CompteTresorerie $compte
    ): Response {
        return $this->render(
            'compte_tresorerie/show.html.twig',
            [
                'compte' => $compte,
            ]
        );
    }

    #[Route('/{id}/modifier', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        CompteTresorerie $compte,
        EntityManagerInterface $entityManager,
        CompteTresorerieRepository $repository
    ): Response {
        /*
         * Le formulaire désactive déjà ce champ en modification.
         * Cette sauvegarde ajoute une protection côté serveur.
         */
        $soldeInitialAvantModification = $compte->getSoldeInitial();
        $soldeActuelAvantModification = $compte->getSoldeActuel();

        $form = $this->createForm(
            CompteTresorerieType::class,
            $compte
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $compte->setSoldeInitial(
                $soldeInitialAvantModification
            );

            $compte->setSoldeActuel(
                $soldeActuelAvantModification
            );

            if ($repository->codeExiste(
                $compte->getCode(),
                $compte->getId()
            )) {
                $this->addFlash(
                    'error',
                    sprintf(
                        'Le code « %s » est déjà utilisé.',
                        $compte->getCode()
                    )
                );
            } else {
                try {
                    $entityManager->flush();

                    $this->addFlash(
                        'success',
                        'Le compte de trésorerie a été modifié avec succès.'
                    );

                    return $this->redirectToRoute(
                        'app_compte_tresorerie_show',
                        ['id' => $compte->getId()]
                    );
                } catch (UniqueConstraintViolationException) {
                    $this->addFlash(
                        'error',
                        'Ce code de compte existe déjà.'
                    );
                }
            }
        }

        return $this->render(
            'compte_tresorerie/edit.html.twig',
            [
                'compte' => $compte,
                'form' => $form,
            ]
        );
    }

    #[Route('/{id}/basculer-statut', name: 'toggle_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function toggleStatus(
        Request $request,
        CompteTresorerie $compte,
        EntityManagerInterface $entityManager
    ): Response {
        if (!$this->isCsrfTokenValid(
            'toggle-compte-' . $compte->getId(),
            (string) $request->request->get('_token')
        )) {
            $this->addFlash(
                'error',
                'Le jeton de sécurité est invalide.'
            );

            return $this->redirectToRoute(
                'app_compte_tresorerie_index'
            );
        }

        $compte->setActif(!$compte->isActif());
        $entityManager->flush();

        $this->addFlash(
            'success',
            $compte->isActif()
                ? 'Le compte a été activé.'
                : 'Le compte a été désactivé.'
        );

        return $this->redirectToRoute(
            'app_compte_tresorerie_index'
        );
    }
}