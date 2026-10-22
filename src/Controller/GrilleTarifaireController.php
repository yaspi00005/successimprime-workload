<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\AccesEnLigne;
use App\Service\GrilleTarifaire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages › Grille tarifaire (maquette 37). Les prix se modifient sur
 * le serveur de l'atelier seulement ; en ligne la grille est en lecture.
 * Chaque changement est gardé dans PrixHistorique.
 */
#[Route('/reglages/grille-tarifaire', name: 'app_grille_tarifaire_')]
#[IsGranted('ROLE_ADMIN')]
final class GrilleTarifaireController extends AbstractController
{
    public function __construct(
        private readonly GrilleTarifaire $grille,
        private readonly AccesEnLigne $acces,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $onglets = $this->grille->onglets();
        $onglet = (string) $request->query->get('onglet', $onglets[0]['cle']);
        if (!\in_array($onglet, array_column($onglets, 'cle'), true)) {
            $onglet = $onglets[0]['cle'];
        }

        $configurations = match ($onglet) {
            'finitions', 'negocies' => [],
            'autres' => $this->grille->configurations(null),
            default => $this->grille->configurations($this->grille->categorie($onglet)),
        };
        $voirCouts = $this->isGranted('ROLE_COUT_MARGE');
        $couts = [];
        if ($voirCouts) {
            foreach ($configurations as $c) {
                $couts[$c->getId()] = null === $c->getProduit() ? null : GrilleTarifaire::coutMatiere($c->getProduit(), $c->getModeCalcul());
            }
        }

        return $this->render('grille_tarifaire/index.html.twig', [
            'onglets' => $onglets,
            'onglet' => $onglet,
            'configurations' => $configurations,
            'couts' => $couts,
            'finitions' => 'finitions' === $onglet ? $this->grille->finitions() : [],
            'negocies' => 'negocies' === $onglet ? $this->grille->prixNegocies() : [],
            'modifiable' => !$this->acces->estEnLigne(),
            'voir_couts' => $voirCouts,
        ]);
    }

    #[Route('/enregistrer', name: 'enregistrer', methods: ['POST'])]
    public function enregistrer(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('grille-tarifaire', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $onglet = (string) $request->request->get('onglet');
        if ($this->acces->estEnLigne()) {
            $this->addFlash('warning', 'Les prix se modifient sur le serveur de l’atelier, puis se synchronisent.');

            return $this->redirectToRoute('app_grille_tarifaire_index', ['onglet' => $onglet]);
        }
        $utilisateur = $this->getUser();
        $par = $utilisateur instanceof User ? $utilisateur : null;
        $resultat = 'finitions' === $onglet
            ? $this->grille->enregistrerFinitions($request->request->all('finitions'), $par)
            : $this->grille->enregistrerConfigurations($request->request->all('configurations'), $par);

        foreach ($resultat['erreurs'] as $erreur) {
            $this->addFlash('warning', $erreur);
        }
        if ($resultat['changements'] > 0 || [] === $resultat['erreurs']) {
            $this->addFlash($resultat['changements'] > 0 ? 'success' : 'info', match ($resultat['changements']) {
                0 => 'Aucun prix modifié.',
                1 => '1 changement enregistré et ajouté à l’historique.',
                default => sprintf('%d changements enregistrés et ajoutés à l’historique.', $resultat['changements']),
            });
        }

        return $this->redirectToRoute('app_grille_tarifaire_index', ['onglet' => $onglet]);
    }

    #[Route('/historique', name: 'historique', methods: ['GET'])]
    public function historique(): Response
    {
        return $this->render('grille_tarifaire/historique.html.twig', [
            'changements' => $this->grille->historique(),
            'champs' => GrilleTarifaire::CHAMPS_CONFIGURATION + GrilleTarifaire::CHAMPS_FINITION,
        ]);
    }
}
