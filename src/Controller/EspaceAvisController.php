<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Entity\ReponseSondage;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Sondages\Sondages;
use App\Service\Sondages\SondageCommande;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client, « Mes avis » (docs/sondages.md) : noter une commande
 * livrée, répondre aux sondages proposés. Toute personne d'un compte
 * d'entreprise peut donner l'avis, une fois par commande et par sondage.
 */
#[Route('/espace/avis', name: 'app_espace_avis')]
final class EspaceAvisController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly Sondages $sondages,
        private readonly EspaceClient $espace,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function liste(): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $maintenant = new \DateTimeImmutable();

        return $this->vue('espace/app/avis.html.twig', $compte, [
            'commandes' => array_map($this->espace->carte(...), $this->sondages->commandesANoter($this->espace->commandes($client, 30), $maintenant)),
            'sondages' => $this->sondages->sondagesPour($client, $maintenant),
            'donnes' => $this->sondages->reponsesDuClient($client, 10),
        ]);
    }

    #[Route('/commande/{id}', name: '_commande', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'POST'])]
    public function commande(string $id, Request $request): Response
    {
        $compte = $this->compte();
        $commande = $this->espace->commande($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        $deja = $this->sondages->reponseCommande($commande);
        if (null !== $deja) {
            $this->addFlash('succes', 'Vous avez déjà donné votre avis sur cette commande. Merci !');

            return $this->redirectToRoute('app_espace_commande', ['id' => $id]);
        }
        if (!$this->sondages->peutNoter($commande, new \DateTimeImmutable())) {
            $this->addFlash('error', 'Le sondage de cette commande n\'est plus ouvert.');

            return $this->redirectToRoute('app_espace_commande', ['id' => $id]);
        }
        $saisie = [];
        if ($request->isMethod('POST')) {
            $this->jeton($request);
            $saisie = $request->request->all();
            try {
                $this->sondages->repondreCommande($commande, $compte, $saisie, ReponseSondage::CANAL_ESPACE, new \DateTimeImmutable());
                $this->addFlash('succes', 'Merci pour votre avis ! Il nous aide à mieux vous servir.');

                return $this->redirectToRoute('app_espace_commande', ['id' => $id]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->vue('espace/app/avis_commande.html.twig', $compte, [
            'onglet' => 'commandes',
            'commande' => $commande,
            'articles' => Sondages::articles($commande),
            'questions' => SondageCommande::questions(),
            'saisie' => $saisie,
        ]);
    }

    #[Route('/sondage/{id}', name: '_sondage', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'POST'])]
    public function sondage(string $id, Request $request): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $sondage = $this->sondages->sondagePour($client, $id, new \DateTimeImmutable());
        if (null === $sondage) {
            $this->addFlash('error', 'Ce sondage n\'est plus ouvert, ou vous y avez déjà répondu.');

            return $this->redirectToRoute('app_espace_avis');
        }
        $saisie = [];
        if ($request->isMethod('POST')) {
            $this->jeton($request);
            $saisie = $request->request->all();
            try {
                $this->sondages->repondreSondage($sondage, $client, $compte, $saisie, ReponseSondage::CANAL_ESPACE, null, null, new \DateTimeImmutable());
                $this->addFlash('succes', 'Merci d\'avoir répondu ! Vos réponses nous aident à progresser.');

                return $this->redirectToRoute('app_espace_avis');
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->vue('espace/app/avis_sondage.html.twig', $compte, [
            'sondage' => $sondage,
            'saisie' => $saisie,
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

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('espace', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
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
