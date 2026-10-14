<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\CompteEntreprise;
use App\Service\EspaceClient\MesProduits;
use App\Service\EspaceClient\NouvelleCommande;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client, « Mes produits » (maquettes P01 à P03) : le client
 * recommande ses produits déjà validés en pièces, en m² ou par lot, sans
 * nouveau BAT. Chacun ne voit que les produits de sa fiche.
 */
#[Route('/espace/produits', name: 'app_espace_produits')]
final class EspaceProduitsController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly MesProduits $mesProduits,
        private readonly CompteEntreprise $compteEntreprise,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function liste(): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        // Un demandeur ne reçoit aucun prix (CompteClient::montantsVisibles).
        $montants = $compte->montantsVisibles();
        $fiches = array_map(fn ($p): array => $this->mesProduits->fiche($p, $montants), $this->mesProduits->liste($client));
        $categories = array_count_values(array_filter(array_map(static fn (array $f): ?string => $f['categorie'], $fiches)));
        ksort($categories);

        return $this->render('espace/app/produits.html.twig', [
            'onglet' => 'commandes',
            'moi' => $client,
            'peutPayer' => $compte->peut(CompteClient::DROIT_PAYEUR),
            'peutValider' => $compte->peut(CompteClient::DROIT_VALIDEUR),
            'fiches' => $fiches,
            'categories' => $categories,
            'entreprise' => CompteClient::clientAPlusieursComptes($client),
            'demandeValidation' => $this->compteEntreprise->demandeValidation($compte),
            'max' => MesProduits::SELECTION_MAX,
        ]);
    }

    /** Conversion et prix pour la quantité saisie (même calcul qu'à la commande) ; sans prix pour un demandeur. */
    #[Route('/{id}/calcul', name: '_calcul', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function calcul(string $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['erreur' => 'La page a expiré. Rechargez-la.'], 403);
        }
        $compte = $this->compte();
        $produit = $this->mesProduits->trouver($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        $donnees = json_decode(mb_substr($request->getContent(), 0, 2000), true, 3);
        $donnees = \is_array($donnees) ? $donnees : [];
        try {
            $r = $this->mesProduits->calculer($produit, self::nombre($donnees['quantite'] ?? null), (string) ($donnees['saisie'] ?? ''), (bool) ($donnees['inferieur'] ?? false));

            return $this->json(MesProduits::calculPourLaPage($r, $compte->montantsVisibles()));
        } catch (\DomainException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        }
    }

    #[Route('/commander', name: '_commander', methods: ['POST'])]
    public function commander(Request $request, NouvelleCommande $nouvelleCommande): Response
    {
        if (!$this->isCsrfTokenValid('espace', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
        $compte = $this->compte();
        $client = $compte->getClient();
        try {
            $selection = [];
            foreach (\array_slice($request->request->all('lignes'), 0, MesProduits::SELECTION_MAX + 1) as $ligne) {
                $produit = \is_array($ligne) ? $this->mesProduits->trouver($client, (string) ($ligne['produit'] ?? '')) : null;
                if (null === $produit) {
                    throw new \DomainException('Un produit de la sélection n\'est plus disponible. Rechargez la page.');
                }
                $selection[] = ['produit' => $produit, 'quantite' => self::nombre($ligne['quantite'] ?? null), 'saisie' => (string) ($ligne['saisie'] ?? ''), 'inferieur' => '1' === ($ligne['inferieur'] ?? '')];
            }
            $commande = $this->mesProduits->commander($client, $selection, new \DateTimeImmutable());
            $this->compteEntreprise->preparerCommande($compte, $commande, $request->request->getString('bon_commande'), null);
            $nouvelleCommande->enregistrer();
            if ($commande->attendValidationEntreprise()) {
                $this->compteEntreprise->prevenirValideurs($commande, $compte);
                $this->addFlash('succes', \sprintf('Demande %s envoyée. Elle part à l\'atelier dès qu\'une personne qui valide pour votre entreprise l\'accepte.', $commande->getNumero()));
            } else {
                $sansPao = [] === array_filter($commande->getCommandesDetails()->toArray(), static fn ($l): bool => $l->isPrePresseNecessaire());
                $this->addFlash('succes', \sprintf('Commande %s envoyée. %s', $commande->getNumero(), $sansPao ? 'Vos BAT sont déjà validés : l\'atelier confirme et lance l\'impression.' : 'L\'atelier confirme et vous prévient.'));
            }

            return $this->redirectToRoute('app_espace_commande', ['id' => (string) $commande->getPublicId()]);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_espace_produits');
        }
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
