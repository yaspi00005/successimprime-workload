<?php

namespace App\Controller;

use App\Service\EspaceClient\NouvelleCommande;
use App\Service\Site\Calculateur;
use App\Service\Site\CatalogueSite;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Site vitrine : nos produits (maquette 02) et calculateur de prix
 * (maquette 03). Le prix est toujours calculé sur le serveur, au prix
 * normal ; le formulaire marche sans JavaScript (envoi en GET), le
 * script de la page ne fait que le mettre à jour en direct. Aucune de
 * ces pages n'ouvre de session : elles restent en cache 5 minutes.
 */
final class SiteProduitsController extends AbstractController
{
    public function __construct(
        private readonly CatalogueSite $catalogue,
        private readonly Calculateur $calculateur,
        private readonly SiteController $site,
    ) {
    }

    #[Route('/nos-produits', name: 'app_site_produits', methods: ['GET'])]
    public function produits(Request $request): Response
    {
        $univers = $this->catalogue->univers();
        // Recherche par nom commercial ou désignation (docs/noms-commerciaux.md).
        $recherche = mb_substr(trim((string) $request->query->get('q', '')), 0, 80);
        $trouves = CatalogueSite::rechercher($univers, $recherche);
        $choisi = '' === $recherche ? CatalogueSite::universChoisi($univers, Calculateur::lire($request->query->all())['categorie']) : null;

        return $this->site->cache($this->render('site/produits.html.twig', [
            'univers' => $univers,
            'choisi' => $choisi,
            'recherche' => $recherche,
            'trouves' => $trouves,
        ]), $request);
    }

    #[Route('/calculateur', name: 'app_site_calculateur', methods: ['GET'])]
    public function calculateur(Request $request): Response
    {
        $configurations = $this->catalogue->configurations();
        $groupes = CatalogueSite::groupes($configurations);
        $saisie = Calculateur::lire($request->query->all());
        $config = Calculateur::configuration($configurations, $groupes, $saisie);
        $valeurs = null === $config ? null : Calculateur::valeurs($config, $saisie);

        return $this->site->cache($this->render('site/calculateur.html.twig', [
            'groupes' => $groupes,
            'fiches' => NouvelleCommande::fiches($configurations, false),
            'fiche' => null === $config ? null : NouvelleCommande::fiche($config, false),
            'valeurs' => $valeurs,
            'resultat' => null === $config ? null : $this->calculateur->resultat($config, $valeurs),
        ]), $request);
    }

    /**
     * Estimation pour le script de la page : mêmes paramètres que le
     * formulaire. Une saisie refusée (taille, quantité) revient avec son
     * message dans « erreur » ; seul un produit inconnu est une erreur 422.
     */
    #[Route('/calculateur/estimation', name: 'app_site_calculateur_estimation', methods: ['GET'])]
    public function estimation(Request $request): JsonResponse
    {
        $saisie = Calculateur::lire($request->query->all());
        $config = null;
        foreach (null === $saisie['produit'] ? [] : $this->catalogue->configurations() as $c) {
            if ($c->getId() === $saisie['produit']) {
                $config = $c;
                break;
            }
        }
        if (null === $config) {
            return $this->site->cache(new JsonResponse(['erreur' => 'Choisissez un produit.'], 422), $request);
        }
        return $this->site->cache(new JsonResponse($this->calculateur->resultat($config, Calculateur::valeurs($config, $saisie))), $request);
    }
}
