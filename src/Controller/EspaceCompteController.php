<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\AppareilsClient;
use App\Service\EspaceClient\MonCompte;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;

/**
 * Mon compte (maquette EC04) : demande de changement de la raison sociale
 * ou du NIF, « Déconnecter les autres appareils », « Télécharger mes
 * données ». La page elle-même est EspaceClientController::monCompte.
 */
#[Route('/espace/compte', name: 'app_espace_')]
final class EspaceCompteController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly MonCompte $monCompte,
    ) {
    }

    /** Raison sociale et NIF : une proposition part à l'atelier, la fiche ne change qu'après sa validation. */
    #[Route('/entreprise', name: 'compte_entreprise', methods: ['POST'])]
    public function entreprise(Request $request): Response
    {
        $this->jeton($request);
        $compte = $this->compte();
        try {
            $this->monCompte->proposer($compte, [
                'raisonSociale' => $request->request->getString('raison_sociale'),
                'nif' => $request->request->getString('nif'),
            ], new \DateTimeImmutable());
            $this->addFlash('succes', 'Demande envoyée à l\'atelier : la fiche sera mise à jour après sa validation.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_compte');
    }

    /**
     * Les autres sessions et cookies « rester connecté » tombent ; cet
     * appareil reste connecté (son cookie est refait avec la nouvelle version).
     */
    #[Route('/appareils/deconnecter', name: 'appareils_deconnecter', methods: ['POST'])]
    public function deconnecterLesAutres(
        Request $request,
        AppareilsClient $appareils,
        #[Autowire(service: 'security.authenticator.remember_me_handler.espace')] RememberMeHandlerInterface $souvenir,
    ): Response {
        $this->jeton($request);
        $compte = $this->compte();
        $appareils->deconnecterLesAutres($compte, $request);
        if ($request->cookies->has('SI_ESPACE_SOUVENIR')) {
            $souvenir->createRememberMeCookie($compte);
        }
        $this->addFlash('succes', 'Les autres appareils sont déconnectés. Celui-ci reste connecté.');

        return $this->redirect($this->generateUrl('app_espace_compte').'#securite');
    }

    /** Ses données, selon ses droits (fichier JSON). */
    #[Route('/mes-donnees', name: 'mes_donnees', methods: ['GET'])]
    public function mesDonnees(): Response
    {
        $compte = $this->compte();
        $maintenant = new \DateTimeImmutable();
        $json = json_encode($this->monCompte->exporter($compte, $maintenant), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);
        $reponse = new Response($json, Response::HTTP_OK, ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
        $reponse->headers->set('Content-Disposition', $reponse->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'mes-donnees-succes-imprim-'.$maintenant->format('Y-m-d').'.json'));

        return $reponse;
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

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('espace', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
    }
}
