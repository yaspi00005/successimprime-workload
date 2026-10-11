<?php

namespace App\Controller;

use App\Entity\CommandeDetailFinition;
use App\Entity\CommandesDetails;
use App\Entity\Finition;
use App\Entity\Machines;
use App\Entity\Produits;
use App\Service\CalculateurCoutRevient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Coût de revient et optimiseur de laize d'une ligne en cours de saisie
 * (maquette 22, panneau de droite du devis). Rien n'est enregistré : la
 * ligne est reconstruite en mémoire à partir de ce que l'écran envoie,
 * avec la machine choisie dans le panneau (elle n'est fixée qu'en prépresse).
 * Réservé à qui voit les marges.
 */
final class CoutLigneController extends AbstractController
{
    #[Route('/saisie/cout-ligne', name: 'app_saisie_cout_ligne', methods: ['POST'])]
    #[IsGranted('ROLE_COUT_MARGE')]
    public function cout(Request $request, EntityManagerInterface $em, CalculateurCoutRevient $calculateur): JsonResponse
    {
        if (!$this->isCsrfTokenValid('cout-ligne', (string) $request->headers->get('X-CSRF-Token'))) {
            return new JsonResponse(['erreur' => 'Jeton de sécurité invalide.'], 403);
        }
        $donnees = $request->toArray();

        $produit = isset($donnees['produit']) ? $em->find(Produits::class, (int) $donnees['produit']) : null;
        // L'écran affiche des centimètres ; les lignes de commande stockent des mètres.
        $largeur = max(0.0, (float) ($donnees['largeur'] ?? 0)) / 100;
        $hauteur = max(0.0, (float) ($donnees['longueur'] ?? 0)) / 100;
        $quantite = max(1, (int) ($donnees['quantite'] ?? 1));

        $ligne = (new CommandesDetails())
            ->setProduit($produit)
            ->setMachine(isset($donnees['machine']) ? $em->find(Machines::class, (int) $donnees['machine']) : null)
            ->setLargeur($largeur)
            ->setLongueur($hauteur)
            ->setQuantite($quantite)
            ->setNombreFaces(max(1, (int) ($donnees['nombreFaces'] ?? 1)));

        foreach (array_unique(array_map('intval', (array) ($donnees['finitions'] ?? []))) as $id) {
            $finition = $id > 0 ? $em->find(Finition::class, $id) : null;
            if ($finition instanceof Finition) {
                $ligne->addFinition((new CommandeDetailFinition())->setFinition($finition)->setQuantite(1));
            }
        }

        $cout = $calculateur->pourLigne($ligne);
        $machine = $ligne->getMachine();
        $prixHt = max(0, (int) ($donnees['prixHt'] ?? 0));

        return new JsonResponse($cout + [
            'machineNom' => $machine?->getNom(),
            'pose' => CalculateurCoutRevient::pose($largeur, $hauteur, $quantite, $machine?->getLaizeMetres()),
            'coutTotal' => $cout['total'],
            'marge' => CalculateurCoutRevient::tauxMarge($prixHt, $cout['total']),
        ]);
    }
}
