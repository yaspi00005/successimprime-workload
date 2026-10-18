<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\ProduitClient;
use App\Entity\User;
use App\Repository\ProduitClientRepository;
use App\Security\FichierEnvoye;
use App\Service\ProduitsClient\DossierClient;
use App\Service\ProduitsClient\FilePrepresseProduits;
use App\Service\ProduitsClient\LiensFichiers;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Prépresse, produits des clients (maquette MP02, docs/mes-produits.md) : le
 * graphiste lie chaque produit ajouté par un client à son fichier traité du
 * dossier de l'atelier. Le serveur local liste ce dossier (réglage « Dossier
 * racine des clients »), contrôle le fichier (dimensions, couleurs, découpe)
 * et garde son empreinte SHA-256. Le site en ligne ne reçoit que la
 * référence du fichier, jamais le fichier.
 */
#[Route('/pre-presse/produits-clients', name: 'app_produits_clients_prepresse_')]
#[IsGranted('ROLE_PREPRESSE')]
final class ProduitsClientsPrepresseController extends AbstractController
{
    public function __construct(
        private readonly FilePrepresseProduits $file,
        private readonly DossierClient $dossiers,
        private readonly LiensFichiers $liens,
        private readonly ProduitClientRepository $produits,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('produits_clients_prepresse/index.html.twig', [
            'clients' => $this->file->clients(),
            'racine' => $this->dossiers->racine(),
            'racineLisible' => '' !== $this->dossiers->racine() && is_dir($this->dossiers->racine()),
        ]);
    }

    #[Route('/{publicId}', name: 'client', requirements: ['publicId' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function client(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, Request $request): Response
    {
        $aPreparer = $this->file->produitsAPreparer($client);
        $tailles = $this->file->taillesCommandees($client);
        // Les produits à traiter d'abord, puis ceux déjà liés.
        $ids = array_map(static fn (ProduitClient $p): int => (int) $p->getId(), $aPreparer);
        $autres = array_values(array_filter($this->produits->actifsDuClient($client), static fn (ProduitClient $p): bool => !\in_array($p->getId(), $ids, true) && (ProduitClient::ORIGINE_CLIENT === $p->getOrigine() || [] !== $p->getFichiersLies())));
        $liste = array_merge($aPreparer, $autres);

        $choisi = null;
        foreach ($liste as $p) {
            if ((string) $p->getId() === $request->query->getString('produit')) {
                $choisi = $p;
            }
        }
        $choisi ??= $aPreparer[0] ?? ($tailles[0]['produit'] ?? null) ?? ($liste[0] ?? null);

        $taillesParProduit = [];
        foreach ($tailles as $t) {
            $taillesParProduit[$t['produit']->getId()][] = $t;
        }
        $nouveaux = \count(array_filter($aPreparer, static fn (ProduitClient $p): bool => ProduitClient::LIEN_A_PREPARER === $p->getEtatLien()));

        return $this->render('produits_clients_prepresse/client.html.twig', [
            'client' => $client,
            'produits' => $liste,
            'aPreparer' => $ids,
            'nouveaux' => $nouveaux,
            'tailles' => $taillesParProduit,
            'choisi' => $choisi,
            'dossier' => $this->dossiers->dossier($client),
            'cheminAttendu' => $this->dossiers->cheminAttendu($client),
            'racine' => $this->dossiers->racine(),
            'fichiers' => $this->dossiers->liste($client),
            'suivant' => $this->file->suivant($client),
            'etatsLiens' => $this->etatsLiens($liste),
        ]);
    }

    /** Contrôle du fichier déposé pour un produit, avant de valider le lien (JSON). */
    #[Route('/{publicId}/controle', name: 'controle', requirements: ['publicId' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function controle(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, Request $request): JsonResponse
    {
        $this->jeton($request, $client);
        $produit = $this->produit($client, $request->request->getInt('produit'));
        [$l, $h] = self::taille($request);
        try {
            $prep = $this->liens->preparer($produit, $request->request->getString('nom'), $l, $h);
        } catch (\DomainException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        }

        return $this->json([
            'controles' => $prep['controles'],
            'etat' => $prep['etat'],
            'octets' => $prep['octets'],
            'taille' => null !== $l && null !== $h ? ProduitClient::libelleTaille($l, $h, $produit->isRond()) : null,
        ]);
    }

    /** « Valider le lien » : l'empreinte est gardée, la taille devient validée. */
    #[Route('/{publicId}/lier', name: 'lier', requirements: ['publicId' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function lier(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, Request $request): Response
    {
        $this->jeton($request, $client);
        $produit = $this->produit($client, $request->request->getInt('produit'));
        [$l, $h] = self::taille($request);
        try {
            $utilisateur = $this->getUser();
            $lien = $this->liens->lier($produit, $request->request->getString('nom'), (float) $l, (float) $h, $utilisateur instanceof User ? $utilisateur : null, new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', \sprintf('%s lié à « %s » pour %s : le produit est prêt à imprimer dans l\'espace client.', $lien['nom'], $produit->getNom(), ProduitClient::libelleTaille((float) $l, (float) $h, $produit->isRond())));
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_produits_clients_prepresse_client', ['publicId' => $client->getPublicId(), 'produit' => $produit->getId()]);
    }

    /** Retire une taille validée (le fichier n'est plus le bon). */
    #[Route('/{publicId}/delier', name: 'delier', requirements: ['publicId' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function delier(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, Request $request): Response
    {
        $this->jeton($request, $client);
        $produit = $this->produit($client, $request->request->getInt('produit'));
        [$l, $h] = self::taille($request);
        if (null !== $l && null !== $h) {
            $produit->delierTaille($l, $h);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Taille %s retirée de « %s ».', ProduitClient::libelleTaille($l, $h, $produit->isRond()), $produit->getNom()));
        }

        return $this->redirectToRoute('app_produits_clients_prepresse_client', ['publicId' => $client->getPublicId(), 'produit' => $produit->getId()]);
    }

    /** Ouvre un fichier du dossier du client (aperçu dans le navigateur). */
    #[Route('/{publicId}/fichier', name: 'fichier', requirements: ['publicId' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function fichier(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, Request $request): Response
    {
        $chemin = $this->dossiers->chemin($client, $request->query->getString('nom')) ?? throw $this->createNotFoundException('Fichier introuvable dans le dossier du client.');

        return self::envoyer($chemin);
    }

    /** Réponse qui ouvre le fichier dans le navigateur (PDF, images) ou le télécharge (type lu dans le contenu). */
    public static function envoyer(string $chemin): BinaryFileResponse
    {
        $reponse = FichierEnvoye::reponse($chemin, basename($chemin));
        $reponse->headers->set('Cache-Control', 'private, no-store');

        return $reponse;
    }

    /**
     * État des fichiers liés de chaque produit (empreinte recalculée :
     * « Fichier modifié » si quelqu'un l'a changé dans le dossier).
     *
     * @param list<ProduitClient> $produits
     *
     * @return array<int, array<string, string>>
     */
    private function etatsLiens(array $produits): array
    {
        $etats = [];
        foreach ($produits as $p) {
            foreach ($p->getFichiersLies() as $f) {
                $etats[(int) $p->getId()][(string) $f['nom'].'|'.$f['l'].'x'.$f['h']] = $this->liens->verifier($p, $f)['etat'];
            }
        }

        return $etats;
    }

    private function produit(Clients $client, int $id): ProduitClient
    {
        $produit = $this->produits->find($id);
        if (!$produit instanceof ProduitClient || $produit->getClient()?->getId() !== $client->getId()) {
            throw $this->createNotFoundException('Produit introuvable pour ce client.');
        }

        return $produit;
    }

    /** @return array{0: ?float, 1: ?float} taille en mm */
    private static function taille(Request $request): array
    {
        $lire = static function (string $v): ?float {
            $v = (float) str_replace([' ', ','], ['', '.'], $v);

            return $v > 0 ? round($v, 1) : null;
        };

        return [$lire($request->request->getString('l')), $lire($request->request->getString('h'))];
    }

    private function jeton(Request $request, Clients $client): void
    {
        $jeton = $request->request->getString('_token') ?: (string) $request->headers->get('X-CSRF-TOKEN');
        if (!$this->isCsrfTokenValid('produits-clients-prepresse-'.$client->getId(), $jeton)) {
            throw $this->createAccessDeniedException('La page a expiré. Rechargez-la.');
        }
    }
}
