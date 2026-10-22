<?php

namespace App\Controller;

use App\Entity\AppareilAutorise;
use App\Entity\User;
use App\Repository\AppareilAutoriseRepository;
use App\Repository\UserRepository;
use App\Security\AccesEnLigne;
use App\Service\DocumentsEntreprise;
use App\Service\Http\Entree;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages › Sécurité › Appareils autorisés (maquette 38) : qui peut
 * ouvrir l'administration en ligne, depuis quel appareil et pour faire
 * quoi. Un appareil s'ajoute en scannant un QR valable 5 minutes.
 */
#[Route('/reglages/securite/appareils', name: 'app_appareils_')]
#[IsGranted('ROLE_ADMIN')]
final class AppareilsController extends AbstractController
{
    public function __construct(
        private readonly AppareilAutoriseRepository $appareils,
        private readonly AccesEnLigne $acces,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, UserRepository $utilisateurs): Response
    {
        $recherche = trim((string) $request->query->get('q', ''));
        $liste = $this->appareils->pourLaListe();
        if ('' !== $recherche) {
            $liste = array_values(array_filter($liste, static fn (AppareilAutorise $a): bool => false !== mb_stripos(
                $a->getNom().' '.$a->getNomUtilisateur().' '.$a->getUtilisateur()?->getUserIdentifier().' '.$a->getIdentifiantTente().' '.$a->getIp(),
                $recherche,
            )));
        }

        // Le QR vient d'être créé : il n'est montré qu'une fois.
        $invitation = $request->getSession()->getFlashBag()->get('invitation_appareil')[0] ?? null;
        if (\is_array($invitation)) {
            $lien = $this->generateUrl('app_appareil_autoriser', ['jeton' => $invitation['jeton']], UrlGeneratorInterface::ABSOLUTE_URL);
            $invitation += ['lien' => $lien, 'qr' => DocumentsEntreprise::qr($lien)];
        }

        return $this->render('appareils/index.html.twig', [
            'appareils' => $liste,
            'recherche' => $recherche,
            'utilisateurs' => $utilisateurs->findBy(['actif' => true], ['username' => 'ASC']),
            'roles' => AppareilAutorise::ROLES,
            'invitation' => $invitation,
            'en_ligne' => $this->acces->estEnLigne(),
        ]);
    }

    #[Route('/inviter', name: 'inviter', methods: ['POST'])]
    public function inviter(Request $request, UserRepository $utilisateurs): Response
    {
        if (!$this->isCsrfTokenValid('appareil-inviter', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $utilisateur = $utilisateurs->find(Entree::entier($request->request, 'utilisateur'));
        $role = (string) $request->request->get('role');
        $nom = mb_substr(trim((string) $request->request->get('nom')), 0, 100);
        $auteur = $this->getUser();

        if (!$utilisateur instanceof User || !$utilisateur->isActif() || !isset(AppareilAutorise::ROLES[$role]) || '' === $nom) {
            $this->addFlash('error', 'Choisissez la personne, le rôle en ligne et donnez un nom à l\'appareil.');

            return $this->redirectToRoute('app_appareils_index');
        }

        $jeton = $this->acces->creerInvitation($utilisateur, $role, $nom, $auteur instanceof User ? $auteur : null);
        $this->addFlash('invitation_appareil', [
            'jeton' => $jeton,
            'nom' => $nom,
            'utilisateur' => (new AppareilAutorise())->setUtilisateur($utilisateur)->getNomUtilisateur(),
            'expire' => time() + AccesEnLigne::VALIDITE_INVITATION_SECONDES,
        ]);

        return $this->redirectToRoute('app_appareils_index', ['_fragment' => 'autoriser']);
    }

    #[Route('/{id}/revoquer', name: 'revoquer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoquer(Request $request, AppareilAutorise $appareil): Response
    {
        return $this->changerEtat($request, $appareil, AppareilAutorise::ETAT_AUTORISE, AppareilAutorise::ETAT_REVOQUE, 'Appareil révoqué : il ne peut plus ouvrir de session en ligne.');
    }

    #[Route('/{id}/bloquer', name: 'bloquer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function bloquer(Request $request, AppareilAutorise $appareil): Response
    {
        return $this->changerEtat($request, $appareil, AppareilAutorise::ETAT_REFUSE, AppareilAutorise::ETAT_BLOQUE, 'Adresse bloquée : ses prochaines tentatives seront notées sans alerte.');
    }

    private function changerEtat(Request $request, AppareilAutorise $appareil, string $depuis, string $vers, string $message): Response
    {
        if (!$this->isCsrfTokenValid('appareil-'.$appareil->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        if ($appareil->getEtat() === $depuis) {
            $appareil->setEtat($vers);
            if (AppareilAutorise::ETAT_REVOQUE === $vers) {
                $appareil->setEmpreinte(null);
            }
            $this->em->flush();
            $this->addFlash('success', $message);
        }

        return $this->redirectToRoute('app_appareils_index');
    }
}
