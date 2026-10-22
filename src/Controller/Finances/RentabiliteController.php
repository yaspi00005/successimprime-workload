<?php

namespace App\Controller\Finances;

use App\Entity\Commandes;
use App\Service\Finances\Periode;
use App\Service\Finances\Rentabilite;
use App\Service\Http\Entree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Finances › Rentabilité (maquette F06) : coût et marge d'une commande
 * poste par poste, marge par produit et par machine sur le mois.
 * Coûts et marges : réservé au droit ROLE_COUT_MARGE.
 */
#[Route('/finances/rentabilite')]
#[IsGranted('ROLE_COUT_MARGE')]
final class RentabiliteController extends AbstractController
{
    #[Route('', name: 'app_finances_rentabilite', methods: ['GET'])]
    public function index(Request $request, Rentabilite $rentabilite, EntityManagerInterface $em): Response
    {
        $maintenant = new \DateTimeImmutable();
        $mois = Periode::moisDemande((string) $request->query->get('periode', ''), $maintenant);
        [$debut, $fin] = Periode::bornes($mois);

        $commandes = $rentabilite->commandesDuMois($debut, $fin);
        // La commande détaillée : celle demandée, sinon la plus grosse du mois.
        $commande = null;
        $id = Entree::entier($request->query, 'commande');
        if ($id > 0) {
            $commande = $em->find(Commandes::class, $id);
            if (null === $commande || $commande->isDeleted()) {
                throw $this->createNotFoundException('Commande introuvable.');
            }
        }
        $commande ??= $commandes[0] ?? null;
        if (null !== $commande && !\in_array($commande, $commandes, true)) {
            array_unshift($commandes, $commande);
        }

        return $this->render('finances/rentabilite.html.twig', [
            'synthese' => $rentabilite->synthese($debut, $fin),
            'detail' => null !== $commande ? $rentabilite->commande($commande) : null,
            'commandes' => $commandes,
            'libelle_mois' => Periode::libelle($mois),
            'nom_mois' => Periode::MOIS[(int) $mois->format('n')],
            'periodes' => Periode::choix($maintenant),
            'periode' => $mois->format('Y-m'),
        ]);
    }
}
