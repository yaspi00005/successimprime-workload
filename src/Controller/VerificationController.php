<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\AccesEnLigne;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Administration en ligne (maquette CX03) : le code de sécurité reçu
 * par SMS après le mot de passe, et l'enrôlement d'un appareil depuis
 * le QR affiché dans Réglages › Sécurité.
 */
final class VerificationController extends AbstractController
{
    public function __construct(private readonly AccesEnLigne $acces)
    {
    }

    #[Route('/verification', name: 'app_verification', methods: ['GET', 'POST'])]
    public function verification(Request $request): Response
    {
        $utilisateur = $this->getUser();
        $session = $request->getSession();
        if (!$this->acces->estEnLigne() || !$utilisateur instanceof User || !$this->acces->codeEnAttente($session)) {
            return $this->redirect('/');
        }

        $erreur = null;
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('verification', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }
            $saisi = \is_array($request->request->all()['code'] ?? null)
                ? implode('', $request->request->all()['code'])
                : (string) $request->request->get('code');

            $resultat = $this->acces->verifierCode($session, $saisi);
            if ('ok' === $resultat) {
                $session->remove('si_code_envoi');
                $session->migrate(true);

                return $this->redirect('/');
            }
            $erreur = match ($resultat) {
                'faux' => 'Code incorrect. Vérifiez le SMS et réessayez.',
                'expire' => 'Ce code a expiré. Demandez-en un nouveau.',
                default => 'Trop d\'essais. Demandez un nouveau code.',
            };
        }

        return $this->render('security/verification.html.twig', [
            'envoi' => $session->get('si_code_envoi', []),
            'renvoi' => $this->acces->secondesAvantRenvoi($session),
            'erreur' => $erreur,
            'en_ligne' => true,
        ], new Response(null, null === $erreur ? 200 : 422));
    }

    #[Route('/verification/renvoyer', name: 'app_verification_renvoyer', methods: ['POST'])]
    public function renvoyer(Request $request): Response
    {
        $utilisateur = $this->getUser();
        $session = $request->getSession();
        if (!$this->acces->estEnLigne() || !$utilisateur instanceof User || !$this->acces->codeEnAttente($session)) {
            return $this->redirect('/');
        }
        if ($this->isCsrfTokenValid('verification', (string) $request->request->get('_token'))
            && 0 === $this->acces->secondesAvantRenvoi($session)) {
            $session->set('si_code_envoi', $this->acces->envoyerCode($utilisateur, $session));
        }

        return $this->redirectToRoute('app_verification');
    }

    /** Page ouverte en scannant le QR : l'appareil devient autorisé après confirmation. */
    #[Route('/appareil/autoriser/{jeton}', name: 'app_appareil_autoriser', requirements: ['jeton' => '[0-9a-f]{48}'], methods: ['GET', 'POST'])]
    public function autoriser(Request $request, string $jeton): Response
    {
        $invitation = $this->acces->invitation($jeton);
        if (null === $invitation) {
            return $this->render('security/appareil_autoriser.html.twig', ['invitation' => null, 'en_ligne' => true], new Response(null, 410));
        }

        // Confirmation par un bouton : un aperçu de lien (WhatsApp…) ne doit pas consommer l'invitation.
        if ($request->isMethod('POST') && $this->isCsrfTokenValid('appareil-'.$jeton, (string) $request->request->get('_token'))) {
            $cookie = $this->acces->accepterInvitation($invitation, $request);
            $this->addFlash('succes', 'Cet appareil est autorisé. Connectez-vous avec votre identifiant.');
            $reponse = $this->redirectToRoute('app_login');
            $reponse->headers->setCookie($cookie);

            return $reponse;
        }

        return $this->render('security/appareil_autoriser.html.twig', [
            'invitation' => $invitation,
            'jeton' => $jeton,
            'en_ligne' => true,
        ]);
    }
}
