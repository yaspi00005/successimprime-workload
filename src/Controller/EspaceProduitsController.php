<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Entity\ProduitClient;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\FichiersClient;
use App\Service\EspaceClient\MesProduits;
use App\Service\EspaceClient\Panier;
use App\Service\Http\Entree;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client, « Mes produits » (maquettes MP01, MP03, MP04, MP07) : le
 * catalogue du client, indépendant des commandes. Il ajoute ses produits
 * (image, nom, type, dimensions) sans rien commander ; l'atelier prépare
 * le fichier une fois ; il choisit ensuite la taille et la quantité et
 * met le produit au panier (EspacePanierController). Chacun ne voit que
 * les produits de sa fiche ; un demandeur ne reçoit aucun prix.
 */
#[Route('/espace/produits', name: 'app_espace_produits')]
final class EspaceProduitsController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly MesProduits $mesProduits,
        private readonly Panier $panier,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function liste(Request $request): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        // Un demandeur ne reçoit aucun prix (CompteClient::montantsVisibles).
        $montants = $compte->montantsVisibles();
        $fiches = array_map(fn ($p): array => $this->mesProduits->fiche($p, $montants), $this->mesProduits->liste($client));
        $familles = array_count_values(array_filter(array_map(static fn (array $f): ?string => $f['famille'], $fiches)));
        ksort($familles);

        return $this->render('espace/app/produits.html.twig', [
            'onglet' => 'commandes',
            'moi' => $client,
            'peutPayer' => $compte->peut(CompteClient::DROIT_PAYEUR),
            'peutValider' => $compte->peut(CompteClient::DROIT_VALIDEUR),
            'fiches' => $fiches,
            'familles' => $familles,
            'panier' => $this->panier->detail($compte, $montants),
            'types' => array_keys(ProduitClient::TYPES),
            'extensions' => FichiersClient::extensions(),
            'morceau' => FichiersClient::MORCEAU,
            'ajoutMax' => MesProduits::AJOUT_MAX,
            // « Commander à nouveau » (RecommanderClient) : ouvre la feuille de ce produit.
            'ouvrir' => ['produit' => $request->query->getString('produit'), 'quantite' => $request->query->getString('quantite'), 'saisie' => $request->query->getString('saisie')],
        ]);
    }

    /** MP01 : le client enregistre ses produits (une ligne chacun), sans commander. */
    #[Route('/ajouter', name: '_ajouter', methods: ['POST'])]
    public function ajouter(Request $request, FichiersClient $fichiers): Response
    {
        if (!$this->isCsrfTokenValid('espace', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
        $compte = $this->compte();
        $client = $compte->getClient();
        $lignes = [];
        foreach (\array_slice($request->request->all('produits'), 0, MesProduits::AJOUT_MAX + 1) as $l) {
            if (!\is_array($l)) {
                continue;
            }
            $image = ctype_digit((string) ($l['image'] ?? '')) ? $fichiers->trouver($client, (int) $l['image']) : null;
            $lignes[] = ['nom' => mb_substr((string) ($l['nom'] ?? ''), 0, 200), 'type' => (string) ($l['type'] ?? ''), 'dimensions' => mb_substr((string) ($l['dimensions'] ?? ''), 0, 60), 'image' => $image];
        }
        try {
            $crees = $this->mesProduits->ajouterProduits($client, $lignes, new \DateTimeImmutable());
            $this->addFlash('succes', \sprintf('%d produit%s enregistré%s. Notre atelier prépare le fichier d\'impression de chacun, une seule fois (en général sous 24 h ouvrées) : vous pourrez ensuite les commander quand vous voulez.', \count($crees), \count($crees) > 1 ? 's' : '', \count($crees) > 1 ? 's' : ''));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_produits');
    }

    /** Règle « Nouvelle image » : le lien est suspendu et le produit repasse au prépresse. */
    #[Route('/{id}/image', name: '_image', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function image(string $id, Request $request, FichiersClient $fichiers): Response
    {
        if (!$this->isCsrfTokenValid('espace', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
        $compte = $this->compte();
        $produit = $this->mesProduits->trouver($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        $image = $fichiers->trouver($compte->getClient(), Entree::entier($request->request, 'image'));
        try {
            if (null === $image) {
                throw new \DomainException('Choisissez la nouvelle image.');
            }
            $etaitPret = $produit->estPret();
            $this->mesProduits->remplacerImage($produit, $image, new \DateTimeImmutable());
            $this->addFlash('succes', $etaitPret
                ? \sprintf('Nouvelle image pour « %s ». L\'atelier refait le fichier d\'impression ; en attendant, le produit est « en préparation ».', $produit->getNom())
                : \sprintf('Nouvelle image pour « %s ». L\'atelier en tiendra compte.', $produit->getNom()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_produits');
    }

    /** Conversion et prix pour la taille et la quantité choisies (même calcul qu'à la commande) ; sans prix pour un demandeur. */
    #[Route('/{id}/calcul', name: '_calcul', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function calcul(string $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['erreur' => 'La page a expiré. Rechargez-la.'], 403);
        }
        $compte = $this->compte();
        $produit = $this->mesProduits->trouver($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        $donnees = self::donnees($request);
        try {
            $r = $this->mesProduits->calculer($produit, self::nombre($donnees['quantite'] ?? null), (string) ($donnees['saisie'] ?? ''), (bool) ($donnees['inferieur'] ?? false), self::taille($donnees['taille'] ?? null));

            return $this->json(MesProduits::calculPourLaPage($r, $compte->montantsVisibles()));
        } catch (\DomainException|\InvalidArgumentException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        }
    }

    /** MP04 : « Ajouter au panier ». Rend le panier recalculé pour le volet de droite. */
    #[Route('/{id}/panier', name: '_panier', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function ajouterAuPanier(string $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['erreur' => 'La page a expiré. Rechargez-la.'], 403);
        }
        $compte = $this->compte();
        $produit = $this->mesProduits->trouver($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        $donnees = self::donnees($request);
        try {
            $this->panier->ajouter($compte, $produit, self::nombre($donnees['quantite'] ?? null), (string) ($donnees['saisie'] ?? ''), (bool) ($donnees['inferieur'] ?? false), self::taille($donnees['taille'] ?? null));
        } catch (\DomainException|\InvalidArgumentException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        }

        return $this->json(self::panierPourLaPage($this->panier->detail($compte, $compte->montantsVisibles()), $compte->montantsVisibles()));
    }

    /**
     * Ce que le volet du panier montre (nom, taille, quantité, prix pour qui
     * les voit, impression directe ou BAT).
     *
     * @param array{articles: list<array<string, mixed>>, total: int, directs: int, avecBat: int} $panier
     *
     * @return array<string, mixed>
     */
    public static function panierPourLaPage(array $panier, bool $montants): array
    {
        // Un demandeur ne reçoit aucune clé de montant (docs/espace-client.md).
        $articles = array_map(static fn (array $a): array => [
            'nom' => $a['fiche']['p']->getNom(),
            'resume' => trim(($a['calcul']['taille']['libelle'] ?? '').' · '.$a['calcul']['resume'], ' ·'),
            'direct' => (bool) $a['calcul']['direct'],
            'image' => $a['fiche']['id'],
        ] + ($montants ? ['montant' => $a['calcul']['montant'] ?? null] : []), $panier['articles']);

        return ['nombre' => \count($panier['articles']), 'articles' => $articles] + ($montants ? ['total' => $panier['total']] : []);
    }

    /** @return array<string, mixed> */
    private static function donnees(Request $request): array
    {
        $donnees = json_decode(mb_substr($request->getContent(), 0, 2000), true, 4);

        return \is_array($donnees) ? $donnees : [];
    }

    /** {l, h} en mm, ou null (taille du produit). */
    private static function taille(mixed $valeur): ?array
    {
        if (!\is_array($valeur)) {
            return null;
        }
        $l = self::nombre($valeur['l'] ?? null);
        $h = self::nombre($valeur['h'] ?? null);

        return $l > 0 && $h > 0 ? Panier::tailleLue(['l' => $l, 'h' => $h]) : throw new \DomainException('Indiquez la largeur et la hauteur en mm.');
    }

    /** « 20 », « 4,5 » ; 0 si illisible. */
    private static function nombre(mixed $valeur): float
    {
        $texte = str_replace([' ', ','], ['', '.'], trim((string) (\is_scalar($valeur) ? $valeur : '')));

        return is_numeric($texte) ? (float) $texte : 0.0;
    }

    private function compte(): CompteClient
    {
        $session = $this->getUser();
        $compte = $session instanceof CompteClient ? $this->comptes->find($session->getId()) : null;
        if (!$compte instanceof CompteClient || null === $compte->getClient()) {
            throw $this->createAccessDeniedException();
        }

        return $compte;
    }
}
