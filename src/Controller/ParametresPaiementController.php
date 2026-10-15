<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ParametresPaiementType;
use App\Repository\ParametresPaiementRepository;
use App\Service\Parametres\Parametres;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages › Paiements › frais mobile money (fonds de soutien, frais de
 * retrait). L'enregistrement passe par la tuile Paiements : journal des
 * réglages, envoi au site en ligne (paiement Orange Money de l'espace
 * client) et, depuis l'admin en ligne, simple proposition au local.
 */
#[Route('/parametres/paiement')]
#[IsGranted('ROLE_ADMIN')]
final class ParametresPaiementController extends AbstractController
{
    #[Route(name: 'app_parametres_paiement_edit', methods: ['GET', 'POST'])]
    public function edit(
        Request $request,
        ParametresPaiementRepository $repository,
        Parametres $reglages,
    ): Response {
        // Le formulaire travaille sur une copie : la tuile compare avant / après.
        $saisie = clone $repository->recuperer();

        $form = $this->createForm(ParametresPaiementType::class, $saisie);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $utilisateur = $this->getUser();
            $resultat = $reglages->enregistrer('paiements', [
                'tauxFondsSoutien' => $saisie->getTauxFondsSoutien(),
                'tauxFraisRetrait' => $saisie->getTauxFraisRetrait(),
            ], $utilisateur instanceof User ? $utilisateur : null);

            $this->addFlash('success', match ($resultat) {
                'propose' => 'Changement proposé : il s\'applique quand la direction le valide sur le serveur de l\'atelier.',
                'inchange' => 'Rien n\'a changé.',
                default => 'Les paramètres de paiement ont été enregistrés.',
            });

            return $this->redirectToRoute('app_parametres_paiement_edit');
        }

        return $this->render('parametres_paiement/edit.html.twig', [
            'form' => $form,
        ]);
    }
}
