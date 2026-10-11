<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Repository\CommandesRepository;
use App\Repository\CompteClientRepository;
use App\Service\Parametres\Parametres;
use App\Security\CodeParSms;
use App\Security\EspaceClientComptes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Espace client (maquettes CX01 et CX04) : connexion par code ou mot de
 * passe, création de compte, suivi d'une commande sans compte et liste
 * des commandes du client connecté (ses propres commandes seulement).
 */
#[Route('/espace', name: 'app_espace_')]
final class EspaceClientController extends AbstractController
{
    public function __construct(
        private readonly CodeParSms $codes,
        private readonly EspaceClientComptes $comptes,
        private readonly CompteClientRepository $repository,
        private readonly Parametres $parametres,
    ) {
    }

    #[Route('/connexion', name: 'connexion', methods: ['GET', 'POST'])]
    public function connexion(Request $request, AuthenticationUtils $authentification): Response
    {
        if ($this->getUser() instanceof CompteClient) {
            return $this->redirectToRoute('app_espace_accueil');
        }
        $erreur = $authentification->getLastAuthenticationError();

        // Paramètres › Espace client : la connexion par code peut être coupée.
        $codeActif = $this->codeActif();

        return $this->render('espace/connexion.html.twig', [
            'mode' => 'mdp' === $request->query->get('mode') || !$codeActif ? 'mdp' : 'code',
            'code_actif' => $codeActif,
            'erreur' => $erreur?->getMessageKey(),
            'erreur_donnees' => $erreur?->getMessageData() ?? [],
            'dernier_numero' => $authentification->getLastUsername(),
            'code' => $this->etatCode($request),
        ]);
    }

    #[Route('/code', name: 'code', methods: ['POST'])]
    public function code(
        Request $request,
        #[Autowire(service: 'limiter.espace_code_telephone')] RateLimiterFactoryInterface $parTelephone,
        #[Autowire(service: 'limiter.espace_code_ip')] RateLimiterFactoryInterface $parAdresse,
    ): Response {
        $creation = 'creation' === $request->request->get('intention');
        $retour = $creation ? 'app_espace_creer_compte' : 'app_espace_connexion';
        if (!$this->isCsrfTokenValid('espace-code', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La page a expiré. Réessayez.');

            return $this->redirectToRoute($retour);
        }

        if (!$creation && !$this->codeActif()) {
            $this->addFlash('error', 'La connexion par code est désactivée. Utilisez votre mot de passe.');

            return $this->redirectToRoute('app_espace_connexion', ['mode' => 'mdp']);
        }

        $telephone = CompteClient::normaliserTelephone((string) $request->request->get('telephone'));
        $nom = trim((string) $request->request->get('nom'));
        if (null === $telephone || ($creation && '' === $nom)) {
            $this->addFlash('error', $creation ? 'Indiquez votre nom et un numéro malien à 8 chiffres.' : 'Indiquez un numéro malien à 8 chiffres.');

            return $this->redirectToRoute($retour);
        }

        if (!$parTelephone->create($telephone)->consume()->isAccepted() || !$parAdresse->create((string) $request->getClientIp())->consume()->isAccepted()) {
            $this->addFlash('error', 'Trop de codes demandés. Réessayez dans quelques minutes.');

            return $this->redirectToRoute($retour);
        }

        $identite = $creation ? ['nom' => $nom, 'prenom' => trim((string) $request->request->get('prenom'))] : [];
        // « Renvoyer le code » pendant une création : on garde le nom saisi.
        $enCours = $this->codes->donnees($request->getSession(), EspaceClientComptes::SESSION_CODE);
        if (!$creation && ($enCours['telephone'] ?? null) === $telephone && isset($enCours['nom'])) {
            $identite = ['nom' => $enCours['nom'], 'prenom' => $enCours['prenom'] ?? ''];
        }
        $envoi = $this->comptes->envoyerCode($request->getSession(), $telephone, $identite);
        $request->getSession()->set('espace_code_envoi', $envoi);

        return $this->redirectToRoute('app_espace_connexion', ['mode' => 'code']);
    }

    #[Route('/creer-compte', name: 'creer_compte', methods: ['GET'])]
    public function creerCompte(): Response
    {
        if ($this->getUser() instanceof CompteClient) {
            return $this->redirectToRoute('app_espace_accueil');
        }

        return $this->render('espace/creer_compte.html.twig');
    }

    /** Suivi sans compte : numéro de commande + téléphone du client, sans montants. */
    #[Route('/suivre', name: 'suivre', methods: ['GET', 'POST'])]
    public function suivre(
        Request $request,
        CommandesRepository $commandes,
        #[Autowire(service: 'limiter.espace_suivi_ip')] RateLimiterFactoryInterface $limite,
    ): Response {
        $commande = null;
        $erreur = null;
        $numero = trim((string) $request->request->get('numero', $request->query->get('numero', '')));

        if ($request->isMethod('POST')) {
            $telephone = CompteClient::normaliserTelephone((string) $request->request->get('telephone'));
            if (!$this->isCsrfTokenValid('espace-suivre', (string) $request->request->get('_token'))) {
                $erreur = 'La page a expiré. Réessayez.';
            } elseif (!$limite->create((string) $request->getClientIp())->consume()->isAccepted()) {
                $erreur = 'Trop de recherches. Réessayez plus tard.';
            } else {
                $trouvee = '' !== $numero ? $commandes->findOneBy(['numero' => $numero]) : null;
                $client = null !== $telephone ? $this->repository->clientDuTelephone($telephone) : null;
                // Même réponse si la commande n'existe pas ou n'est pas à ce numéro.
                if ($trouvee instanceof Commandes && null !== $client && $trouvee->getClients()?->getId() === $client->getId() && $trouvee->isStatut()) {
                    $commande = $trouvee;
                } else {
                    $erreur = 'Aucune commande ne correspond à ce numéro et à ce téléphone.';
                }
            }
        }

        $reponse = $this->render('espace/suivre.html.twig', [
            'commande' => $commande,
            'numero' => $numero,
            'erreur' => $erreur,
        ], new Response(null, null !== $erreur ? 422 : 200));
        $reponse->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $reponse;
    }

    #[Route('', name: 'accueil', methods: ['GET'])]
    public function accueil(CommandesRepository $commandes): Response
    {
        $compte = $this->compte();

        return $this->render('espace/accueil.html.twig', [
            'compte' => $compte,
            'client' => $compte->getClient(),
            'commandes' => $commandes->findBy(['clients' => $compte->getClient(), 'statut' => true], ['id' => 'DESC'], 50),
        ]);
    }

    #[Route('/mot-de-passe', name: 'mot_de_passe', methods: ['POST'])]
    public function motDePasse(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em): Response
    {
        $compte = $this->compte();
        $nouveau = (string) $request->request->get('mot_de_passe');
        if (!$this->isCsrfTokenValid('espace-mdp', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La page a expiré. Réessayez.');
        } elseif (mb_strlen($nouveau) < 8) {
            $this->addFlash('error', 'Le mot de passe doit faire au moins 8 caractères.');
        } elseif ($nouveau !== (string) $request->request->get('confirmation')) {
            $this->addFlash('error', 'Les deux mots de passe ne sont pas identiques.');
        } else {
            $compte->setMotDePasse($hasher->hashPassword($compte, $nouveau));
            $em->flush();
            $this->addFlash('succes', 'Mot de passe enregistré. Vous pouvez aussi vous connecter avec.');
        }

        return $this->redirectToRoute('app_espace_accueil');
    }

    #[Route('/deconnexion', name: 'deconnexion', methods: ['GET'])]
    public function deconnexion(): never
    {
        throw new \LogicException('Interceptée par le pare-feu de l\'espace client.');
    }

    /** Le compte frais de la base (celui de la session ne garde pas la fiche client). */
    private function compte(): CompteClient
    {
        $session = $this->getUser();
        $compte = $session instanceof CompteClient ? $this->repository->find($session->getId()) : null;
        if (!$compte instanceof CompteClient) {
            throw $this->createAccessDeniedException();
        }

        return $compte;
    }

    /** @return array{attente: bool, telephone: ?string, envoi: array<string, mixed>, renvoi: int} */
    private function etatCode(Request $request): array
    {
        $session = $request->getSession();
        $attente = $this->codes->enAttente($session, EspaceClientComptes::SESSION_CODE);

        return [
            'attente' => $attente,
            'telephone' => $this->codes->donnees($session, EspaceClientComptes::SESSION_CODE)['telephone'] ?? null,
            'envoi' => $attente ? (array) $session->get('espace_code_envoi', []) : [],
            'renvoi' => $this->codes->secondesAvantRenvoi($session, EspaceClientComptes::SESSION_CODE),
        ];
    }

    private function codeActif(): bool
    {
        return (bool) $this->parametres->valeur('espace_client', 'code_sms');
    }
}
