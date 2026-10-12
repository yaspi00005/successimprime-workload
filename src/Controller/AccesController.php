<?php

namespace App\Controller;

use App\Entity\AppareilPointage;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\UserAuthenticator;
use App\Service\Equipe\AccesApplication;
use App\Service\Pointage\Pointages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Accès des employés à l'application.
 *
 * - Gestion des accès (maquette E04), pour un administrateur.
 * - Activation par le lien WhatsApp et connexion par PIN sur le
 *   téléphone enregistré (maquette E05, à gauche), sans connexion.
 */
#[Route(name: 'app_acces_')]
final class AccesController extends AbstractController
{
    public const SESSION_INVITATION = 'si_invitation_';

    public function __construct(
        private readonly AccesApplication $acces,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/equipe/acces', name: 'index', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function index(): Response
    {
        $maintenant = new \DateTimeImmutable();
        $lignes = [];
        $sans = [];
        $personnel = $this->users->createQueryBuilder('u')
            ->innerJoin('u.employe', 'e')->addSelect('e')
            ->orderBy('u.actif', 'DESC')->addOrderBy('e.prenom', 'ASC')
            ->getQuery()->getResult();

        foreach ($personnel as $u) {
            if (\in_array('ROLE_ESPACE_CLIENT', $u->getRoles(), true)) {
                continue;
            }
            $statut = $this->acces->statut($u, $maintenant);
            $ligne = ['utilisateur' => $u, 'nom' => Pointages::nom($u), 'statut' => $statut, 'appareil' => $this->acces->appareil($u)];
            if ($statut === 'sans') {
                $sans[] = $ligne;
            } else {
                $lignes[] = $ligne;
            }
        }
        usort($lignes, static fn (array $a, array $b): int => ($a['statut'] === 'retire') <=> ($b['statut'] === 'retire'));

        return $this->render('acces/index.html.twig', [
            'lignes' => $lignes,
            'sans' => $sans,
            'statuts' => AccesApplication::STATUTS,
            'maintenant' => $maintenant,
        ]);
    }

    #[Route('/equipe/acces/{id}/{action}', name: 'agir', requirements: ['id' => '\d+', 'action' => 'inviter|renvoyer|reinitialiser|suspendre|reactiver|annuler|retirer'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function agir(User $utilisateur, string $action, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('acces-'.$utilisateur->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        $moi = $this->getUser() instanceof User ? $this->getUser() : null;
        if ($utilisateur === $moi && \in_array($action, ['suspendre', 'retirer'], true)) {
            $this->addFlash('danger', 'Vous ne pouvez pas couper votre propre accès.');

            return $this->redirectToRoute('app_acces_index');
        }
        if (!$utilisateur->isActif()) {
            $this->addFlash('danger', 'Ce compte est désactivé (départ) : réactivez-le d\'abord dans Utilisateurs.');

            return $this->redirectToRoute('app_acces_index');
        }
        $nom = Pointages::nom($utilisateur);
        $maintenant = new \DateTimeImmutable();

        switch ($action) {
            case 'inviter':
            case 'renvoyer':
            case 'reinitialiser':
                if ($utilisateur->getProfil() === null && !$utilisateur->isAdmin()) {
                    $this->addFlash('warning', 'Choisissez d\'abord le rôle de '.$nom.'.');

                    return $this->redirectToRoute('app_equipe_employes_modifier', ['id' => $utilisateur->getEmploye()?->getId(), '_fragment' => 'acces']);
                }
                $jeton = 'reinitialiser' === $action
                    ? $this->acces->reinitialiserPin($utilisateur, $moi, $maintenant)
                    : $this->acces->inviter($utilisateur, $moi, $maintenant);
                $request->getSession()->set(self::SESSION_INVITATION.$utilisateur->getId(), $jeton);

                return $this->redirectToRoute('app_acces_invitation', ['id' => $utilisateur->getId()]);
            case 'suspendre':
                $this->acces->suspendre($utilisateur, $moi);
                $this->addFlash('warning', 'Accès de '.$nom.' suspendu : plus aucune connexion possible. Il pointe sur la tablette en attendant.');
                break;
            case 'reactiver':
                if ($utilisateur->getPin() === null) {
                    $this->addFlash('warning', $nom.' n\'a pas encore choisi de PIN : envoyez-lui une invitation.');
                    break;
                }
                $this->acces->reactiver($utilisateur, $moi);
                $this->addFlash('success', 'Accès de '.$nom.' réactivé.');
                break;
            case 'annuler':
                $this->acces->annulerInvitation($utilisateur, $moi);
                $this->addFlash('success', 'Invitation de '.$nom.' annulée : le lien ne marche plus.');
                break;
            case 'retirer':
                $this->acces->retirer($utilisateur, $moi);
                $this->addFlash('success', $nom.' n\'a plus accès à l\'application. Son dossier et son historique restent.');
                break;
        }

        return $this->redirectToRoute('app_acces_index');
    }

    /** Lien d'invitation à envoyer : affiché une seule fois (seule son empreinte est gardée). */
    #[Route('/equipe/acces/{id}/invitation', name: 'invitation', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function invitation(User $utilisateur, Request $request): Response
    {
        $session = $request->getSession();
        $jeton = $session->get(self::SESSION_INVITATION.$utilisateur->getId());
        $session->remove(self::SESSION_INVITATION.$utilisateur->getId());
        $employe = $utilisateur->getEmploye();
        $lien = \is_string($jeton) ? $this->generateUrl('app_acces_activer', ['jeton' => $jeton], UrlGeneratorInterface::ABSOLUTE_URL) : null;

        return $this->render('acces/invitation.html.twig', [
            'utilisateur' => $utilisateur,
            'nom' => Pointages::nom($utilisateur),
            'lien' => $lien,
            'message' => $lien === null ? null : self::message($employe?->getPrenom() ?: Pointages::nom($utilisateur), $lien),
            'whatsapp' => UtilisateursController::numeroWhatsApp((string) $employe?->getTelephone()),
        ]);
    }

    public static function message(string $prenom, string $lien): string
    {
        return \sprintf("Bonjour %s 👋\nBienvenue chez Succès Imprim ! Voici votre accès à l'application de l'équipe : pointage, tâches, congés et bulletins.\nActiver mon accès : %s\nCe lien marche 7 jours. Ouvrez-le sur votre téléphone.", $prenom, $lien);
    }

    /** Activation (maquette E05) : choisir son PIN ; ce téléphone est enregistré. */
    #[Route('/acces/activer/{jeton}', name: 'activer', requirements: ['jeton' => '[0-9a-f]{48}'], methods: ['GET', 'POST'])]
    public function activer(string $jeton, Request $request, Security $security): Response
    {
        $maintenant = new \DateTimeImmutable();
        $utilisateur = $this->acces->invitationValable($jeton, $maintenant);
        $erreur = null;

        if ($utilisateur !== null && $request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('activer-acces', $request->getPayload()->getString('_token'))) {
                $erreur = 'La page a expiré. Réessayez.';
            } else {
                $appareil = bin2hex(random_bytes(24));
                $resultat = $this->acces->activer($jeton, $request->getPayload()->getString('pin'), $request->getPayload()->getString('pin2'), $appareil, $request->headers->get('User-Agent'), $maintenant);
                if ($resultat['ok']) {
                    $reponse = $this->connecter($resultat['utilisateur'], $security, $request);
                    $reponse->headers->setCookie(Cookie::create(AppareilPointage::COOKIE, $appareil, new \DateTimeImmutable('+5 years'), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
                    $this->addFlash('success', 'Votre accès est activé. La prochaine fois, ouvrez l\'application et tapez votre code PIN.');

                    return $reponse;
                }
                $erreur = $resultat['message'];
            }
        }

        return $this->render('acces/activer.html.twig', [
            'utilisateur' => $utilisateur,
            'prenom' => $utilisateur?->getEmploye()?->getPrenom() ?: ($utilisateur ? Pointages::nom($utilisateur) : null),
            'erreur' => $erreur,
        ], new Response(status: $utilisateur === null ? 404 : ($erreur === null ? 200 : 422)));
    }

    /** Connexion par PIN, sur le téléphone enregistré. */
    #[Route('/acces/pin', name: 'pin', methods: ['GET', 'POST'])]
    public function pin(Request $request, Security $security): Response
    {
        if ($this->getUser() instanceof User) {
            return $this->redirect('/');
        }
        $jetonAppareil = (string) $request->cookies->get(AppareilPointage::COOKIE, '');
        $utilisateur = $this->acces->proprietaire($jetonAppareil);
        $erreur = null;

        if ($utilisateur !== null && $request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('connexion-pin', $request->getPayload()->getString('_token'))) {
                $erreur = 'La page a expiré. Réessayez.';
            } else {
                $resultat = $this->acces->connecterParPin($jetonAppareil, $request->getPayload()->getString('pin'), new \DateTimeImmutable());
                if ($resultat['ok']) {
                    return $this->connecter($resultat['utilisateur'], $security, $request);
                }
                $erreur = $resultat['message'];
            }
        }

        return $this->render('acces/pin.html.twig', [
            'utilisateur' => $utilisateur,
            'prenom' => $utilisateur?->getEmploye()?->getPrenom() ?: ($utilisateur ? Pointages::nom($utilisateur) : null),
            'erreur' => $erreur,
        ], new Response(status: $erreur === null ? 200 : 422));
    }

    private function connecter(User $utilisateur, Security $security, Request $request): Response
    {
        try {
            $reponse = $security->login($utilisateur, UserAuthenticator::class, 'main');
        } catch (AuthenticationException $e) {
            $this->addFlash('error', $e->getMessageKey());

            return $this->redirectToRoute('app_login');
        }

        return $reponse ?? $this->redirect('/');
    }
}
