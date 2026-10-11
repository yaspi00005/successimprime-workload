<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteClient;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Repository\CommandesRepository;
use App\Repository\CompteClientRepository;
use App\Repository\EntrepriseRepository;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Parametres\Parametres;
use App\Security\CodeParSms;
use App\Security\EspaceClientComptes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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
        private readonly EntrepriseRepository $entreprises,
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
    public function accueil(EspaceClient $espace): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $cartes = array_map($espace->carte(...), $espace->commandes($client, 12));
        $enCours = array_values(array_filter($cartes, static fn (array $c): bool => !$c['terminee'] && !$c['annulee']));
        $solde = $espace->solde($client);

        return $this->vue('espace/app/accueil.html.twig', [
            'onglet' => 'accueil',
            'compte' => $compte,
            'client' => $client,
            'bats' => $espace->bats($client, true),
            'enCours' => \array_slice($enCours, 0, 2),
            'recentes' => \array_slice(array_values(array_filter($cartes, static fn (array $c): bool => $c['terminee'])), 0, 3),
            'solde' => $solde,
            'devisAAccepter' => array_values(array_filter($espace->devis($client), static fn (Devis $d): bool => Devis::STATUT_ENVOYE === $d->getStatut())),
        ]);
    }

    #[Route('/commandes', name: 'commandes', methods: ['GET'])]
    public function commandes(EspaceClient $espace): Response
    {
        $client = $this->compte()->getClient();

        return $this->vue('espace/app/commandes.html.twig', [
            'onglet' => 'commandes',
            'cartes' => array_map($espace->carte(...), $espace->commandes($client)),
            'devisAAccepter' => \count(array_filter($espace->devis($client), static fn (Devis $d): bool => Devis::STATUT_ENVOYE === $d->getStatut())),
        ]);
    }

    #[Route('/commandes/{id}', name: 'commande', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function commande(string $id, EspaceClient $espace): Response
    {
        $client = $this->compte()->getClient();
        $commande = $espace->commande($client, $id) ?? throw $this->createNotFoundException();

        return $this->vue('espace/app/commande.html.twig', [
            'onglet' => 'commandes',
            'carte' => $espace->carte($commande),
            'lignes' => array_values(array_filter($commande->getCommandesDetails()->toArray(), static fn (CommandesDetails $l): bool => CommandesDetails::PRODUCTION_ANNULEE !== $l->getStatutProduction())),
            'livraison' => $espace->livraison($commande),
            'bats' => array_values(array_filter($espace->bats($client, true), static fn ($ctl): bool => $ctl->getCommandeDetail()->getCommande() === $commande)),
            'whatsapp' => $this->whatsapp(\sprintf('Bonjour, je vous écris au sujet de ma commande %s.', $commande->getNumero())),
        ]);
    }

    #[Route('/devis', name: 'devis', methods: ['GET'])]
    public function devis(Request $request, EspaceClient $espace): Response
    {
        $filtre = (string) $request->query->get('filtre', 'tous');
        $liste = $espace->devis($this->compte()->getClient());
        $liste = match ($filtre) {
            'a_accepter' => array_values(array_filter($liste, static fn (Devis $d): bool => Devis::STATUT_ENVOYE === $d->getStatut())),
            'acceptes' => array_values(array_filter($liste, static fn (Devis $d): bool => \in_array($d->getStatut(), [Devis::STATUT_ACCEPTE, Devis::STATUT_CONVERTI], true))),
            default => $liste,
        };

        return $this->vue('espace/app/devis.html.twig', ['onglet' => 'commandes', 'liste' => $liste, 'filtre' => $filtre]);
    }

    #[Route('/devis/{id}/accepter', name: 'devis_accepter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function accepterDevis(Devis $devis, Request $request, EspaceClient $espace): Response
    {
        $this->jeton($request);
        if ($devis->getClients()?->getId() !== $this->compte()->getClient()->getId()) {
            throw $this->createNotFoundException();
        }
        if (Devis::STATUT_ENVOYE !== $devis->getStatut()) {
            $this->addFlash('error', 'Ce devis ne peut plus être accepté.');
        } else {
            $espace->accepterDevis($devis);
            $this->addFlash('succes', \sprintf('Devis %s accepté. Nous préparons votre commande et revenons vers vous.', $devis->getNumero()));
        }

        return $this->redirectToRoute('app_espace_devis');
    }

    #[Route('/devis/{id}/pdf', name: 'devis_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function devisPdf(Devis $devis, EspaceClient $espace): Response
    {
        if ($devis->getClients()?->getId() !== $this->compte()->getClient()->getId() || !\in_array($devis->getStatut(), EspaceClient::DEVIS_VISIBLES, true)) {
            throw $this->createNotFoundException();
        }
        if (null === $devis->getTokenAuthenticite()) {
            $devis->genererTokenAuthenticite();
            $espace->flush();
        }

        return $this->redirectToRoute('app_devis_pdf_public', ['id' => $devis->getId(), 'token' => $devis->getTokenAuthenticite()]);
    }

    #[Route('/fichiers', name: 'fichiers', methods: ['GET'])]
    public function fichiers(EspaceClient $espace): Response
    {
        $client = $this->compte()->getClient();
        $bats = $espace->bats($client);

        return $this->vue('espace/app/fichiers.html.twig', [
            'onglet' => 'fichiers',
            'aValider' => array_values(array_filter($bats, static fn ($c): bool => \App\Entity\ControlePrePresse::STATUT_EN_ATTENTE_BAT === $c->getStatut())),
            'autres' => array_values(array_filter($bats, static fn ($c): bool => \App\Entity\ControlePrePresse::STATUT_EN_ATTENTE_BAT !== $c->getStatut())),
        ]);
    }

    #[Route('/bat/{id}', name: 'bat', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function bat(int $id, Request $request, EspaceClient $espace): Response
    {
        $compte = $this->compte();
        $controle = $espace->bat($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        $attente = \App\Entity\ControlePrePresse::STATUT_EN_ATTENTE_BAT === $controle->getStatut();
        if ($request->isMethod('POST')) {
            $this->jeton($request);
            $texte = trim((string) $request->request->get('texte'));
            if (!$attente) {
                $this->addFlash('error', 'Ce BAT n\'attend plus votre réponse.');
            } elseif ('valider' === $request->request->get('action')) {
                $espace->validerBat($controle, $this->nom($compte), new \DateTimeImmutable());
                $this->addFlash('succes', 'BAT validé, merci. Votre accord est enregistré avec la date et l\'heure ; l\'atelier lance l\'impression.');

                return $this->redirectToRoute('app_espace_commande', ['id' => (string) $controle->getCommandeDetail()->getCommande()->getPublicId()]);
            } elseif ('' === $texte) {
                $this->addFlash('error', 'Dites-nous ce qu\'il faut modifier.');
            } else {
                $espace->modifierBat($controle, $texte, new \DateTimeImmutable());
                $this->addFlash('succes', 'Demande envoyée à l\'atelier. Vous recevrez une nouvelle version du BAT.');

                return $this->redirectToRoute('app_espace_fichiers');
            }
        }
        $conception = $espace->conception($controle);

        return $this->vue('espace/app/bat.html.twig', [
            'onglet' => 'fichiers',
            'controle' => $controle,
            'ligne' => $controle->getCommandeDetail(),
            'titre' => EspaceClient::titreLigne($controle->getCommandeDetail()),
            'fichiers' => $espace->fichiersBat($controle),
            'conception' => $conception,
            'frise' => self::frise($conception),
            'attente' => $attente,
        ]);
    }

    #[Route('/bat/fichier/{id}', name: 'bat_fichier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function batFichier(int $id, Request $request, EspaceClient $espace): Response
    {
        $f = $espace->cheminFichier($this->compte()->getClient(), $id) ?? throw $this->createNotFoundException();
        $reponse = new BinaryFileResponse($f['chemin']);
        $reponse->headers->set('Content-Type', $f['type']);
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->setContentDisposition($request->query->getBoolean('telecharger') ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE, $f['nom'] !== '' ? $f['nom'] : 'bat', 'bat');
        $reponse->setPrivate();

        return $reponse;
    }

    #[Route('/factures', name: 'factures', methods: ['GET'])]
    public function factures(EspaceClient $espace): Response
    {
        $client = $this->compte()->getClient();

        return $this->vue('espace/app/factures.html.twig', [
            'onglet' => 'factures',
            'solde' => $espace->solde($client),
            'factures' => $espace->factures($client),
        ]);
    }

    #[Route('/factures/{id}/pdf', name: 'facture_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function facturePdf(Factures $facture, EspaceClient $espace): Response
    {
        if ($facture->getCommande()?->getClients()?->getId() !== $this->compte()->getClient()->getId() || Factures::ETAT_EMISE !== $facture->getEtat()) {
            throw $this->createNotFoundException();
        }
        if (null === $facture->getTokenAuthenticite()) {
            $facture->genererTokenAuthenticite();
            $espace->flush();
        }

        return $this->redirectToRoute('app_factures_pdf_public', ['id' => $facture->getId(), 'token' => $facture->getTokenAuthenticite()]);
    }

    /** Paiement d'une commande (maquette 17) : Orange Money en ligne dès que les clés sont posées. */
    #[Route('/payer/{id}', name: 'payer', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function payer(string $id, EspaceClient $espace): Response
    {
        $compte = $this->compte();
        $commande = $espace->commande($compte->getClient(), $id) ?? throw $this->createNotFoundException();

        return $this->vue('espace/app/payer.html.twig', [
            'onglet' => 'factures',
            'commande' => $commande,
            'titre' => EspaceClient::titre($commande),
            'compte' => $compte,
            'marchand' => $espace->marchandOrangeMoney(),
            'whatsapp' => $this->whatsapp(\sprintf('Bonjour, je souhaite payer ma commande %s (%s F).', $commande->getNumero(), number_format((float) $commande->getResteAPayer(), 0, ',', ' '))),
        ]);
    }

    #[Route('/compte', name: 'compte', methods: ['GET'])]
    public function monCompte(): Response
    {
        $compte = $this->compte();

        return $this->vue('espace/app/compte.html.twig', ['onglet' => 'compte', 'compte' => $compte, 'client' => $compte->getClient()]);
    }

    #[Route('/compte/preferences', name: 'preferences', methods: ['POST'])]
    public function preferences(Request $request, EntityManagerInterface $em): Response
    {
        $this->jeton($request);
        $client = $this->compte()->getClient();
        $client->setRecevoirSms($request->request->getBoolean('sms'));
        $em->flush();
        $this->addFlash('succes', 'Préférences enregistrées.');

        return $this->redirectToRoute('app_espace_compte');
    }

    #[Route('/notifications', name: 'notifications', methods: ['GET'])]
    public function notifications(EspaceClient $espace): Response
    {
        return $this->vue('espace/app/notifications.html.twig', [
            'onglet' => 'accueil',
            'fil' => $espace->notifications($this->compte()->getClient(), new \DateTimeImmutable()),
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

        return $this->redirectToRoute('app_espace_compte');
    }

    #[Route('/deconnexion', name: 'deconnexion', methods: ['GET'])]
    public function deconnexion(): never
    {
        throw new \LogicException('Interceptée par le pare-feu de l\'espace client.');
    }

    /**
     * Frise du BAT montrée au client : versions envoyées, ses demandes (ses
     * propres mots), validation. Rien de la vie interne de l'atelier.
     *
     * @return list<array{le: \DateTimeImmutable, titre: string, detail: ?string}>
     */
    private static function frise(?\App\Entity\Conception $c): array
    {
        $frise = [];
        foreach ($c?->getEvenements() ?? [] as $e) {
            $frise[] = match ($e['type']) {
                'bat' => ['le' => $e['le'], 'titre' => $e['titre'], 'detail' => null],
                'retour' => ['le' => $e['le'], 'titre' => 'Votre demande de modification', 'detail' => preg_match('/« (.+?) »/u', (string) $e['detail'], $m) ? $m[1] : null],
                'valide' => ['le' => $e['le'], 'titre' => 'BAT validé', 'detail' => null],
                default => null,
            };
        }

        return array_reverse(array_values(array_filter($frise)));
    }

    /** @param array<string, mixed> $parametres */
    private function vue(string $gabarit, array $parametres): Response
    {
        return $this->render($gabarit, $parametres + ['moi' => $this->compte()->getClient()]);
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
    }

    private function nom(CompteClient $compte): string
    {
        $c = $compte->getClient();

        return trim(($c->getPrenom() ?? '').' '.($c->getNom() ?? '')) ?: (string) $c->getRaisonSociale();
    }

    /** Lien WhatsApp vers l'atelier (numéro de la tuile Entreprise), ou null. */
    private function whatsapp(string $texte): ?string
    {
        foreach ($this->entreprises->dansLOrdre() as $e) {
            if (trim((string) $e->getTelephone()) !== '') {
                return 'https://wa.me/'.UtilisateursController::numeroWhatsApp((string) $e->getTelephone()).'?text='.rawurlencode($texte);
            }
        }

        return null;
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
