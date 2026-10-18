<?php

namespace App\Controller;

use App\Entity\Finition;
use App\Entity\User;
use App\Security\AccesEnLigne;
use App\Service\Catalogue\EditionProduit;
use App\Service\Catalogue\OptionsPartagees;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ventes › Produits › Options partagées (maquette PR04, docs/produits.md) :
 * matières, finitions, délais et services réutilisés par tous les
 * produits, où chacun est utilisé, et les matières liées au stock.
 * Prix d'achat et coût au m² : droit « Marges et coûts de revient ».
 */
#[Route('/produits/options', name: 'app_produits_options_')]
#[IsGranted(new Expression("is_granted('ROLE_ADMIN') or is_granted('ROLE_COMMANDE') or is_granted('ROLE_DEVIS')"))]
final class OptionsPartageesController extends AbstractController
{
    public function __construct(
        private readonly OptionsPartagees $options,
        private readonly AccesEnLigne $acces,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $matieres = $this->options->matieres();
        $finitions = $this->options->finitions();
        $delais = $this->options->delais();
        $services = $this->options->services();
        $voir = $request->query->getString('voir', 'delai-express');
        $utilisation = $this->options->utilisation($voir, [...$matieres, ...$finitions, ...$delais, ...$services])
            ?? $this->options->utilisation('delai-standard', $delais);

        return $this->render('produits/options.html.twig', [
            'matieres' => array_values(array_filter($matieres, static fn (array $m): bool => [] !== $m['produits'])),
            'matieres_sans_produit' => \count(array_filter($matieres, static fn (array $m): bool => [] === $m['produits'])),
            'finitions' => $finitions,
            'delais' => $delais,
            'services' => $services,
            'voir' => $voir,
            'utilisation' => $utilisation,
            'stock' => $this->options->matieresStock(),
            'avec_couts' => $this->isGranted('ROLE_COUT_MARGE'),
            'modifiable' => $this->isGranted('ROLE_ADMIN') && !$this->acces->estEnLigne(),
        ]);
    }

    /**
     * Prix d'une finition, appliqué à toutes les configurations qui la proposent.
     */
    #[Route('/finition/{id}', name: 'finition', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function finition(Finition $finition, Request $request): Response
    {
        if ($this->acces->estEnLigne()) {
            throw $this->createAccessDeniedException('Les prix se modifient sur l\'ERP de l\'atelier, puis se synchronisent.');
        }
        if (!$this->isCsrfTokenValid('option-finition-'.$finition->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $prix = EditionProduit::entier($request->request->getString('prix'));
        if (!\is_int($prix)) {
            $this->addFlash('danger', 'Le prix est un nombre entier de francs (0 : incluse).');
        } else {
            $u = $this->getUser();
            $n = $this->options->appliquerPrixFinition($finition, $prix, $u instanceof User ? $u : null);
            $this->addFlash('success', 0 === $n ? 'Aucun prix changé.' : \sprintf('« %s » : nouveau prix sur %d configuration%s, ajouté à l\'historique des prix.', $finition->getNom(), $n, $n > 1 ? 's' : ''));
        }

        return $this->redirectToRoute('app_produits_options_index', ['voir' => 'finition-'.$finition->getId()]);
    }
}
