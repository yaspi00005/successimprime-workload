<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\ReclamationClient;
use App\Entity\ReclamationClientPhoto;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\ReclamationsClients;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client, réclamations (maquette 18) : la liste avec la réponse de
 * l'atelier, et le formulaire avec photos. Chacun ne voit que celles de sa
 * fiche.
 */
#[Route('/espace/reclamations', name: 'app_espace_reclamation')]
final class EspaceReclamationsController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly ReclamationsClients $reclamations,
    ) {
    }

    #[Route('', name: 's', methods: ['GET'])]
    public function liste(): Response
    {
        $compte = $this->compte();

        return $this->vue('espace/app/reclamations.html.twig', $compte, [
            'liste' => $this->reclamations->duClient($compte->getClient()),
        ]);
    }

    #[Route('/nouvelle', name: '_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request, EspaceClient $espace): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $commandes = array_values(array_filter($espace->commandes($client, 30), static fn (Commandes $c): bool => $c->isStatut()));
        $saisie = ['commande' => $request->query->getString('commande'), 'motif' => 'autre', 'objet' => '', 'description' => ''];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('espace', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
            }
            $saisie = [
                'commande' => $request->request->getString('commande'),
                'motif' => $request->request->getString('motif'),
                'objet' => $request->request->getString('objet'),
                'description' => $request->request->getString('description'),
            ];
            $commande = '' !== $saisie['commande'] ? $espace->commande($client, $saisie['commande']) : null;
            try {
                $r = $this->reclamations->creer($compte, $commande, $saisie['motif'], $saisie['objet'], $saisie['description'], array_values($request->files->all('photos')), new \DateTimeImmutable());
                $this->addFlash('succes', \sprintf('Réclamation %s envoyée. L\'atelier vous répond ici et par SMS.', $r->getNumero()));

                return $this->redirectToRoute('app_espace_reclamations');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->vue('espace/app/reclamation_nouvelle.html.twig', $compte, [
            'commandes' => $commandes,
            'saisie' => $saisie,
            'motifs' => ReclamationClient::MOTIFS,
            'max' => ReclamationClient::PHOTOS_MAX,
        ]);
    }

    #[Route('/photo/{id}', name: '_photo', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function photo(string $id): Response
    {
        $client = $this->compte()->getClient();
        foreach ($this->reclamations->duClient($client) as $r) {
            foreach ($r->getPhotos() as $p) {
                if ((string) $p->getPublicId() === $id) {
                    return self::image($p);
                }
            }
        }

        throw $this->createNotFoundException();
    }

    private static function image(ReclamationClientPhoto $p): Response
    {
        return new Response($p->getOctets(), 200, [
            'Content-Type' => $p->getType(),
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    /** @param array<string, mixed> $parametres */
    private function vue(string $gabarit, CompteClient $compte, array $parametres): Response
    {
        return $this->render($gabarit, $parametres + [
            'onglet' => 'compte',
            'moi' => $compte->getClient(),
            'peutPayer' => $compte->peut(CompteClient::DROIT_PAYEUR),
            'peutValider' => $compte->peut(CompteClient::DROIT_VALIDEUR),
        ]);
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
