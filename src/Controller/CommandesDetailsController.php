<?php

namespace App\Controller;

use App\Entity\CommandeDetailFinition;
use App\Entity\CommandesDetails;
use App\Entity\ProduitConfigurationFinition;
use App\Form\CommandesDetailsType;
use App\Repository\CommandesDetailsRepository;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/commandes/details')]
final class CommandesDetailsController extends AbstractController
{
    #[Route(
        name: 'app_commandes_details_index',
        methods: ['GET']
    )]
    public function index(
        CommandesDetailsRepository $commandesDetailsRepository
    ): Response {
        return $this->render('commandes_details/index.html.twig', [
            'commandes_details' => $commandesDetailsRepository->findAll(),
        ]);
    }

    #[Route(
        '/new',
        name: 'app_commandes_details_new',
        methods: ['GET', 'POST']
    )]
    public function new(
        Request $request,
        EntityManagerInterface $entityManager
    ): Response {
        $commandesDetail = new CommandesDetails();

        $form = $this->createForm(
            CommandesDetailsType::class,
            $commandesDetail
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->synchroniserFinitions(
                $commandesDetail,
                $form
            );

            $entityManager->persist($commandesDetail);
            $entityManager->flush();

            $this->addFlash(
                'success',
                'Le détail de la commande a été enregistré.'
            );

            return $this->redirectToRoute(
                'app_commandes_details_index',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('commandes_details/new.html.twig', [
            'commandes_detail' => $commandesDetail,
            'form' => $form,
        ]);
    }

    #[Route(
        '/{id}',
        name: 'app_commandes_details_show',
        methods: ['GET'],
        requirements: ['id' => '\d+']
    )]
    public function show(
        CommandesDetails $commandesDetail
    ): Response {
        return $this->render('commandes_details/show.html.twig', [
            'commandes_detail' => $commandesDetail,
        ]);
    }

    #[Route(
        '/{id}/edit',
        name: 'app_commandes_details_edit',
        methods: ['GET', 'POST'],
        requirements: ['id' => '\d+']
    )]
    public function edit(
        Request $request,
        CommandesDetails $commandesDetail,
        EntityManagerInterface $entityManager
    ): Response {
        $form = $this->createForm(
            CommandesDetailsType::class,
            $commandesDetail
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->synchroniserFinitions(
                $commandesDetail,
                $form
            );

            $entityManager->flush();

            $this->addFlash(
                'success',
                'Le détail de la commande a été modifié.'
            );

            return $this->redirectToRoute(
                'app_commandes_details_index',
                [],
                Response::HTTP_SEE_OTHER
            );
        }

        return $this->render('commandes_details/edit.html.twig', [
            'commandes_detail' => $commandesDetail,
            'form' => $form,
        ]);
    }

    #[Route(
        '/{id}',
        name: 'app_commandes_details_delete',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function delete(
        Request $request,
        CommandesDetails $commandesDetail,
        EntityManagerInterface $entityManager
    ): Response {
        $token = $request
            ->getPayload()
            ->getString('_token');

        if (
            $this->isCsrfTokenValid(
                'delete'.$commandesDetail->getId(),
                $token
            )
        ) {
            $entityManager->remove($commandesDetail);
            $entityManager->flush();

            $this->addFlash(
                'success',
                'Le détail de la commande a été supprimé.'
            );
        } else {
            $this->addFlash(
                'danger',
                'Le jeton de sécurité est invalide.'
            );
        }

        return $this->redirectToRoute(
            'app_commandes_details_index',
            [],
            Response::HTTP_SEE_OTHER
        );
    }

    /**
     * Enregistre les finitions sélectionnées et obligatoires.
     *
     * Les prix et montants sont toujours recalculés depuis
     * la configuration enregistrée en base de données.
     */
    private function synchroniserFinitions(
        CommandesDetails $commandeDetail,
        FormInterface $form
    ): void {
        $configuration = $commandeDetail
            ->getProduitConfiguration();

        /*
         * Supprime les anciennes lignes.
         *
         * Avec orphanRemoval: true, Doctrine les supprimera
         * automatiquement de la base pendant le flush.
         */
        foreach (
            $commandeDetail->getFinitions()->toArray()
            as $ancienneFinition
        ) {
            $commandeDetail->removeFinition(
                $ancienneFinition
            );
        }

        if ($configuration === null) {
            return;
        }

        /** @var Collection<int, ProduitConfigurationFinition>|array $choixFormulaire */
        $choixFormulaire = $form
            ->get('finitionsSelectionnees')
            ->getData();

        /*
         * Tableau indexé par identifiant pour empêcher les doublons.
         */
        $finitionsAEnregistrer = [];

        foreach ($choixFormulaire as $choix) {
            if (
                !$choix instanceof ProduitConfigurationFinition
                || !$choix->isActive()
                || $choix->getProduitConfiguration() !== $configuration
            ) {
                continue;
            }

            $identifiant = $choix->getId();

            if ($identifiant !== null) {
                $finitionsAEnregistrer[$identifiant] = $choix;
            }
        }

        /*
         * Ajout forcé de toutes les finitions obligatoires,
         * même si leur case a été décochée dans le navigateur.
         */
        foreach (
            $configuration->getConfigurationFinitions()
            as $configurationFinition
        ) {
            if (
                !$configurationFinition->isActive()
                || !$configurationFinition->isObligatoire()
            ) {
                continue;
            }

            $identifiant = $configurationFinition->getId();

            if ($identifiant !== null) {
                $finitionsAEnregistrer[$identifiant]
                    = $configurationFinition;
            }
        }

        $quantite = max(
            1,
            $commandeDetail->getQuantite()
        );

        $surface = $this->convertirEnFloat(
            $commandeDetail->getSurface()
        );

        $longueur = $this->convertirEnFloat(
            $commandeDetail->getLongueur()
        );

        $nombreFaces = max(
            1,
            $commandeDetail->getNombreFaces()
        );

        foreach (
            $finitionsAEnregistrer
            as $configurationFinition
        ) {
            $montant = $configurationFinition->calculerMontant(
                quantite: $quantite,
                surface: $surface,
                longueur: $longueur,
                nombreFaces: $nombreFaces,
                nombrePoints: 1
            );

            $ligneFinition = new CommandeDetailFinition();

            $ligneFinition
                ->setConfigurationFinition(
                    $configurationFinition
                )
                ->setPrixApplique(
                    $configurationFinition->getPrix()
                )
                ->setModeCalcul(
                    $configurationFinition->getModeCalcul()
                )
                ->setQuantite($quantite)
                ->setMontant($montant);

            $commandeDetail->addFinition(
                $ligneFinition
            );
        }
    }

    /**
     * Convertit les valeurs Doctrine decimal, par exemple
     * "12.50" ou "12,50", en nombre décimal PHP.
     */
    private function convertirEnFloat(
        string|int|float|null $valeur
    ): ?float {
        if ($valeur === null || $valeur === '') {
            return null;
        }

        $valeurNormalisee = str_replace(
            [' ', ','],
            ['', '.'],
            (string) $valeur
        );

        if (!is_numeric($valeurNormalisee)) {
            return null;
        }

        return max(
            0.0,
            (float) $valeurNormalisee
        );
    }
}