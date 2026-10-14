<?php

namespace App\Controller;

use App\Entity\CommandesDetails;
use App\Entity\CompteClient;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\TransactionOrangeMoney;
use App\Repository\CompteClientRepository;
use App\Repository\EntrepriseRepository;
use App\Service\Atelier\CatalogueAtelier;
use App\Service\EspaceClient\CompteEntreprise;
use App\Service\EspaceClient\AppareilsClient;
use App\Service\EspaceClient\CoqueEspace;
use App\Service\EspaceClient\MonCompte;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\FichiersClient;
use App\Service\EspaceClient\FilNotifications;
use App\Service\EspaceClient\MesProduits;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\EspaceClient\RecommanderClient;
use App\Service\EspaceClient\SuiviSansCompte;
use App\Service\Paiement\OrangeMoney;
use App\Service\Parametres\Parametres;
use App\Security\CodeDeConnexion;
use App\Security\DestinationCode;
use App\Security\EspaceClientComptes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Espace client (maquettes CX01 et CX04) : connexion par code ou mot de
 * passe, création de compte, suivi d'une commande sans compte et liste
 * des commandes du client connecté (ses propres commandes seulement).
 */
#[Route('/espace', name: 'app_espace_')]
final class EspaceClientController extends AbstractController
{
    use TargetPathTrait;

    public function __construct(
        private readonly CodeDeConnexion $codes,
        private readonly EspaceClientComptes $comptes,
        private readonly CompteClientRepository $repository,
        private readonly Parametres $parametres,
        private readonly EntrepriseRepository $entreprises,
        private readonly CompteEntreprise $compteEntreprise,
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

        // Le mot de passe d'abord ; le code sert à la première connexion et au mot de passe oublié.
        return $this->render('espace/connexion.html.twig', [
            'mode' => 'code' === $request->query->get('mode') && $codeActif ? 'code' : 'mdp',
            'code_actif' => $codeActif,
            'erreur' => $erreur?->getMessageKey(),
            'erreur_donnees' => $erreur?->getMessageData() ?? [],
            'dernier_numero' => $authentification->getLastUsername(),
            'code' => $this->etatCode($request),
        ]);
    }

    /**
     * Première connexion avec le code d'accès donné par la boutique sur
     * WhatsApp : le lien du message remplit le numéro (jamais le code).
     * Le formulaire part vers app_espace_connexion (EspaceClientAuthenticator, mode « acces »).
     */
    #[Route('/premiere-connexion', name: 'premiere_connexion', methods: ['GET'])]
    public function premiereConnexion(Request $request, AuthenticationUtils $authentification): Response
    {
        if ($this->getUser() instanceof CompteClient) {
            return $this->redirectToRoute('app_espace_accueil');
        }
        $erreur = $authentification->getLastAuthenticationError();
        $numero = CompteClient::normaliserTelephone((string) $request->query->get('tel'));

        $reponse = $this->render('espace/premiere_connexion.html.twig', [
            'erreur' => $erreur?->getMessageKey(),
            'erreur_donnees' => $erreur?->getMessageData() ?? [],
            'numero' => null !== $numero ? substr($numero, 3) : $authentification->getLastUsername(),
            'code_actif' => $this->codeActif(),
        ]);
        $reponse->headers->set('Cache-Control', 'no-store, private');

        return $reponse;
    }

    #[Route('/code', name: 'code', methods: ['POST'])]
    public function code(Request $request): Response
    {
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
        $email = trim((string) $request->request->get('email'));
        if ($creation && '' !== $email && null === DestinationCode::emailValable($email)) {
            $this->addFlash('error', 'Cette adresse e-mail n\'est pas valable. Corrigez-la, ou laissez-la vide pour recevoir le code par SMS.');

            return $this->redirectToRoute($retour);
        }

        // Création : l'e-mail saisi ne sert que pour un numéro inconnu (EspaceClientComptes::destination).
        $identite = $creation ? ['nom' => $nom, 'prenom' => trim((string) $request->request->get('prenom')), 'email' => $email] : [];
        // « Renvoyer le code » pendant une création : on garde le nom et l'e-mail saisis.
        $enCours = $this->codes->donnees($request->getSession(), EspaceClientComptes::SESSION_CODE);
        if (!$creation && ($enCours['telephone'] ?? null) === $telephone && isset($enCours['nom'])) {
            $identite = ['nom' => $enCours['nom'], 'prenom' => $enCours['prenom'] ?? '', 'email' => $enCours['email'] ?? null];
        }
        $envoi = $this->comptes->envoyerCode($request->getSession(), $telephone, (string) $request->getClientIp(), $identite);
        if ($envoi['limite']) {
            // Limites par numéro, par adresse e-mail et par adresse IP : le code déjà envoyé reste bon.
            $this->addFlash('error', $envoi['erreur']);

            return $this->redirectToRoute($retour);
        }
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
    public function suivre(Request $request, SuiviSansCompte $suivi): Response
    {
        $commande = null;
        $erreur = null;
        $numero = trim((string) $request->request->get('numero', $request->query->get('numero', '')));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('espace-suivre', (string) $request->request->get('_token'))) {
                $erreur = 'La page a expiré. Réessayez.';
            } else {
                // Même réponse si la commande n'existe pas ou n'est pas à ce numéro.
                ['commande' => $commande, 'erreur' => $erreur] = $suivi->chercher($numero, (string) $request->request->get('telephone'), (string) $request->getClientIp());
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

    /**
     * Tableau de bord (maquettes EC01 et EC05) : ce qui attend la personne
     * (BAT, paiement, devis, demandes à valider), commandes en cours,
     * activité récente, compte en un coup d'œil et « Recommander », chacun
     * selon ses droits.
     */
    #[Route('', name: 'accueil', methods: ['GET'])]
    public function accueil(EspaceClient $espace, RecommanderClient $recommander, FilNotifications $fil): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $maintenant = new \DateTimeImmutable();
        $droits = CoqueEspace::droits($compte);
        // Les demandes que cette personne doit valider sont deja dans leur propre encadre.
        $aValider = array_map($espace->carte(...), $this->compteEntreprise->aValider($compte));
        $dejaListees = array_map(static fn (array $c): ?int => $c['commande']->getId(), $aValider);
        $enCours = [];
        foreach ($espace->commandes($client, 30) as $c) {
            $carte = $espace->carte($c);
            if (!$carte['terminee'] && !$carte['annulee'] && !\in_array($c->getId(), $dejaListees, true)) {
                $enCours[] = $carte + ['tableau' => EspaceClient::etapesTableau($carte['etapes'], !$c->estALivrer()), 'livraison' => $espace->livraison($c)];
            }
            if (\count($enCours) >= 4) {
                break;
            }
        }
        // Paiement à régler : la facture échue ou due aujourd'hui la plus ancienne.
        $factureDue = null;
        if ($droits['payeur']) {
            foreach ($espace->facturesARegler($client) as $f) {
                if (null !== $f->getDateEcheance() && $f->getDateEcheance()->format('Y-m-d') <= $maintenant->format('Y-m-d') && null !== $f->getCommande() && OrangeMoney::aPayer($f->getCommande()) > 0) {
                    $factureDue = $f;
                    break;
                }
            }
        }

        return $this->vue('espace/app/accueil.html.twig', [
            'onglet' => 'accueil',
            'compte' => $compte,
            'client' => $client,
            'droits' => $droits,
            'bats' => $droits['lecture'] ? [] : $espace->bats($client, true),
            'factureDue' => $factureDue,
            'solde' => $droits['payeur'] ? $espace->solde($client) : null,
            'devisAAccepter' => $droits['valideur'] ? array_values(array_filter($espace->devis($client), static fn (Devis $d): bool => Devis::STATUT_ENVOYE === $d->getStatut())) : [],
            'aValider' => $aValider,
            'enCours' => $enCours,
            'activite' => \array_slice($fil->pour($compte, $maintenant), 0, 5),
            'apercu' => $droits['payeur'] ? $espace->apercuCompte($client, $maintenant) : null,
            'recommander' => $droits['ecrit'] ? $recommander->articles($client, 3) : [],
        ]);
    }

    #[Route('/commandes', name: 'commandes', methods: ['GET'])]
    public function commandes(EspaceClient $espace, MesProduits $mesProduits): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();

        return $this->vue('espace/app/commandes.html.twig', [
            'onglet' => 'commandes',
            'cartes' => array_map($espace->carte(...), $espace->commandes($client)),
            'aValider' => array_map($espace->carte(...), $this->compteEntreprise->aValider($compte)),
            'devisAAccepter' => \count(array_filter($espace->devis($client), static fn (Devis $d): bool => Devis::STATUT_ENVOYE === $d->getStatut())),
            'mesProduits' => \count($mesProduits->liste($client)),
        ]);
    }

    #[Route('/commandes/{id}', name: 'commande', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function commande(string $id, EspaceClient $espace): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $commande = $espace->commande($client, $id) ?? throw $this->createNotFoundException();

        return $this->vue('espace/app/commande.html.twig', [
            'onglet' => 'commandes',
            'carte' => $espace->carte($commande),
            'peutValider' => $commande->attendValidationEntreprise() && $compte->peut(CompteClient::DROIT_VALIDEUR) && CompteClient::clientAPlusieursComptes($client),
            'lignes' => array_values(array_filter($commande->getCommandesDetails()->toArray(), static fn (CommandesDetails $l): bool => CommandesDetails::PRODUCTION_ANNULEE !== $l->getStatutProduction())),
            'livraison' => $espace->livraison($commande),
            'bats' => array_values(array_filter($espace->bats($client, true), static fn ($ctl): bool => $ctl->getCommandeDetail()->getCommande() === $commande)),
            'whatsapp' => $this->whatsapp(\sprintf('Bonjour, je vous écris au sujet de ma commande %s.', $commande->getNumero())),
        ]);
    }

    /** Nouvelle commande (maquette 14) : produit, taille, finitions, quantité, fichier. */
    #[Route('/commandes/nouvelle', name: 'nouvelle_commande', methods: ['GET', 'POST'])]
    public function nouvelleCommande(Request $request, NouvelleCommande $service, FichiersClient $fichiers, CatalogueAtelier $atelier): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $produits = NouvelleCommande::fiches($service->produits(), $client->isB2B());
        $adresses = $this->compteEntreprise->adresses($client);

        if ($request->isMethod('POST')) {
            $this->jeton($request);
            $config = $service->produit($request->request->getInt('produit'));
            $choisis = [];
            foreach ($request->request->all('fichiers') as $id) {
                $f = $fichiers->trouver($client, (int) $id);
                if (null !== $f && null === $f->getCommandeDetail() && !$f->isLogo()) {
                    $choisis[] = $f;
                }
            }
            try {
                if (null === $config) {
                    throw new \DomainException('Choisissez un produit.');
                }
                [$largeur, $hauteur] = [self::nombre($request->request->get('largeur')), self::nombre($request->request->get('hauteur'))];
                $commande = $service->commander($client, $config, $request->request->getInt('quantite', 1), $largeur, $hauteur, array_map('intval', $request->request->all('finitions')), $choisis, $request->request->getString('note'), new \DateTimeImmutable());
                // Le fichier se contrôle à la taille commandée.
                foreach ($choisis as $f) {
                    $fichiers->recontroler($f, $largeur, $hauteur);
                }
                $adresse = $request->request->getInt('adresse') > 0 ? $this->compteEntreprise->adresse($client, $request->request->getInt('adresse')) : null;
                $this->compteEntreprise->preparerCommande($compte, $commande, $request->request->getString('bon_commande'), $adresse);
                $service->enregistrer();
                if ($commande->attendValidationEntreprise()) {
                    $this->compteEntreprise->prevenirValideurs($commande, $compte);
                    $this->addFlash('succes', \sprintf('Demande %s envoyée. Elle part à l\'atelier dès qu\'une personne qui valide pour votre entreprise l\'accepte.', $commande->getNumero()));
                } else {
                    $this->addFlash('succes', \sprintf('Commande %s envoyée. L\'atelier confirme le prix et votre fichier, puis vous prévient.', $commande->getNumero()));
                }

                return $this->redirectToRoute('app_espace_commande', ['id' => (string) $commande->getPublicId()]);
            } catch (\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->vue('espace/app/nouvelle_commande.html.twig', [
            'onglet' => 'commandes',
            'produits' => $produits,
            // Textile à personnaliser : l'atelier 3D (docs/atelier-3d.md).
            'atelier' => [] !== $atelier->modeles(),
            'choix' => $request->request->all() + $request->query->all(),
            'mesFichiers' => array_values(array_filter($fichiers->liste($client), static fn ($f): bool => null === $f->getCommandeDetail())),
            'extensions' => FichiersClient::extensions(),
            'morceau' => FichiersClient::MORCEAU,
            'adresses' => $adresses,
            'entreprise' => CompteClient::clientAPlusieursComptes($client),
            'demandeValidation' => $this->compteEntreprise->demandeValidation($compte),
        ]);
    }

    /** Prix indicatif, recalculé à chaque changement (même calcul qu'à l'enregistrement). */
    #[Route('/commandes/estimer', name: 'estimer', methods: ['POST'])]
    public function estimer(Request $request, NouvelleCommande $service): JsonResponse
    {
        $this->jetonEntete($request);
        $donnees = $request->toArray();
        $config = $service->produit((int) ($donnees['produit'] ?? 0));
        if (null === $config) {
            return $this->json(['erreur' => 'Choisissez un produit.'], 422);
        }
        try {
            return $this->json(NouvelleCommande::estimer(
                $config,
                max(1, (int) ($donnees['quantite'] ?? 1)),
                self::nombre($donnees['largeur'] ?? null),
                self::nombre($donnees['hauteur'] ?? null),
                array_map('intval', (array) ($donnees['finitions'] ?? [])),
                $this->compte()->getClient()->isB2B(),
            ));
        } catch (\DomainException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        }
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
        $compte = $this->compte();
        if ($devis->getClients()?->getId() !== $compte->getClient()->getId()) {
            throw $this->createNotFoundException();
        }
        // Accepter un devis engage l'entreprise comme valider une commande.
        if (!$compte->peut(CompteClient::DROIT_VALIDEUR)) {
            throw $this->createAccessDeniedException();
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
    public function fichiers(EspaceClient $espace, FichiersClient $fichiers): Response
    {
        $client = $this->compte()->getClient();
        $bats = $espace->bats($client);

        return $this->vue('espace/app/fichiers.html.twig', [
            'onglet' => 'fichiers',
            'aValider' => array_values(array_filter($bats, static fn ($c): bool => \App\Entity\ControlePrePresse::STATUT_EN_ATTENTE_BAT === $c->getStatut())),
            'autres' => array_values(array_filter($bats, static fn ($c): bool => \App\Entity\ControlePrePresse::STATUT_EN_ATTENTE_BAT !== $c->getStatut())),
            'mesFichiers' => array_map(static fn ($f): array => ['fichier' => $f, 'controle' => FichiersClient::resume($f->getAnalyse())], $fichiers->liste($client)),
            'logos' => $fichiers->liste($client, true),
            'extensions' => FichiersClient::extensions(),
            'morceau' => FichiersClient::MORCEAU,
        ]);
    }

    /** Commence l'envoi d'un fichier (les morceaux suivent). */
    #[Route('/fichiers/envoi', name: 'fichier_envoi', methods: ['POST'])]
    public function commencerEnvoi(Request $request, FichiersClient $fichiers): JsonResponse
    {
        $this->jetonEntete($request);
        $donnees = $request->toArray();
        try {
            $f = $fichiers->commencer($this->compte()->getClient(), (string) ($donnees['nom'] ?? ''), (int) ($donnees['taille'] ?? 0), (bool) ($donnees['logo'] ?? false), new \DateTimeImmutable());
        } catch (\DomainException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        }

        return $this->json(['jeton' => $f->getJetonUpload(), 'recu' => 0, 'morceau' => FichiersClient::MORCEAU]);
    }

    /** Un morceau du fichier (corps brut), à la position annoncée. */
    #[Route('/fichiers/envoi/{jeton}', name: 'fichier_morceau', requirements: ['jeton' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function morceau(string $jeton, Request $request, FichiersClient $fichiers): JsonResponse
    {
        $this->jetonEntete($request);
        $f = $fichiers->trouverEnvoi($this->compte()->getClient(), $jeton) ?? throw $this->createNotFoundException();
        try {
            $etat = $fichiers->ajouter($f, $request->query->getInt('position', -1), $request->getContent(), self::nombre($request->query->get('largeur')), self::nombre($request->query->get('hauteur')));
        } catch (\DomainException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        }
        if ($etat['termine']) {
            $etat['fichier'] = ['id' => $f->getId(), 'nom' => $f->getNomOriginal(), 'controle' => FichiersClient::resume($f->getAnalyse())];
        }

        return $this->json($etat);
    }

    /** Aperçu ou téléchargement d'un de ses fichiers. */
    #[Route('/fichiers/{id}', name: 'fichier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function fichier(int $id, Request $request, FichiersClient $fichiers): Response
    {
        $f = $fichiers->trouver($this->compte()->getClient(), $id);
        $chemin = null === $f ? null : $fichiers->chemin($f);
        if (null === $chemin) {
            throw $this->createNotFoundException();
        }
        $image = \in_array($f->getTypeMime(), ['image/jpeg', 'image/png'], true);
        $reponse = new BinaryFileResponse($chemin, 200, ['Content-Type' => $image ? (string) $f->getTypeMime() : 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=3600']);
        $reponse->setContentDisposition($image && !$request->query->getBoolean('telecharger') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, (string) $f->getNomOriginal(), 'fichier');

        return $reponse;
    }

    #[Route('/fichiers/{id}/retirer', name: 'fichier_retirer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retirerFichier(int $id, Request $request, FichiersClient $fichiers): Response
    {
        $this->jeton($request);
        $f = $fichiers->trouver($this->compte()->getClient(), $id) ?? throw $this->createNotFoundException();
        try {
            $fichiers->retirer($f);
            $this->addFlash('succes', \sprintf('%s retiré.', $f->getNomOriginal()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_fichiers');
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
            $reperes = EspaceClient::reperes(json_decode(mb_substr($request->request->getString('reperes', '[]'), 0, 8000), true, 4), \count($espace->imagesBat($controle)));
            if (!$attente) {
                $this->addFlash('error', 'Ce BAT n\'attend plus votre réponse.');
            } elseif ('valider' === $request->request->get('action')) {
                $espace->validerBat($controle, $this->nom($compte), new \DateTimeImmutable());
                $this->addFlash('succes', 'BAT validé, merci. Votre accord est enregistré avec la date et l\'heure ; l\'atelier lance l\'impression.');

                return $this->redirectToRoute('app_espace_commande', ['id' => (string) $controle->getCommandeDetail()->getCommande()->getPublicId()]);
            } elseif ('' === $texte && [] === $reperes) {
                $this->addFlash('error', 'Dites-nous ce qu\'il faut modifier, ou touchez l\'image à l\'endroit à changer.');
            } else {
                $espace->modifierBat($controle, $texte, new \DateTimeImmutable(), $reperes);
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
            // Rang de chaque image du BAT : les repères s'y rapportent.
            'rangs' => array_flip(array_map(static fn ($f): int => (int) $f->getId(), $espace->imagesBat($controle))),
            'reperes' => $conception?->derniersReperes(),
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
        $client = $this->payeur()->getClient();

        return $this->vue('espace/app/factures.html.twig', [
            'onglet' => 'factures',
            'solde' => $espace->solde($client),
            'factures' => $espace->factures($client),
        ]);
    }

    #[Route('/factures/{id}/pdf', name: 'facture_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function facturePdf(Factures $facture, EspaceClient $espace): Response
    {
        if ($facture->getCommande()?->getClients()?->getId() !== $this->payeur()->getClient()->getId() || Factures::ETAT_EMISE !== $facture->getEtat()) {
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
    public function payer(string $id, Request $request, EspaceClient $espace, OrangeMoney $orangeMoney): Response
    {
        $compte = $this->payeur();
        $commande = $espace->commande($compte->getClient(), $id, true) ?? throw $this->createNotFoundException();
        if (!$commande->isStatut()) {
            // Prix encore indicatif : on paie une fois la commande confirmée par l'atelier.
            return $this->redirectToRoute('app_espace_commande', ['id' => $id]);
        }
        if ($request->query->getBoolean('annule')) {
            $this->addFlash('error', 'Paiement annulé sur la page Orange Money : rien n\'a été débité.');

            return $this->redirectToRoute('app_espace_payer', ['id' => $id]);
        }

        return $this->vue('espace/app/payer.html.twig', [
            'onglet' => 'factures',
            'commande' => $commande,
            'titre' => EspaceClient::titre($commande),
            'compte' => $compte,
            'omActif' => $orangeMoney->estConfigure(),
            'aPayer' => OrangeMoney::aPayer($commande),
            'enValidation' => OrangeMoney::enValidation($commande),
            'marchand' => $espace->marchandOrangeMoney(),
            'whatsapp' => $this->whatsapp(\sprintf('Bonjour, je souhaite payer ma commande %s (%s F).', $commande->getNumero(), number_format((float) OrangeMoney::aPayer($commande), 0, ',', ' '))),
        ]);
    }

    /** Ouvre le paiement chez Orange et envoie le client sur sa page de paiement. */
    #[Route('/payer/{id}', name: 'payer_orange_money', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function payerOrangeMoney(string $id, Request $request, EspaceClient $espace, OrangeMoney $orangeMoney): Response
    {
        $this->jeton($request);
        $compte = $this->payeur();
        $commande = $espace->commande($compte->getClient(), $id, true) ?? throw $this->createNotFoundException();
        try {
            return $this->redirect($orangeMoney->demarrer($commande, $compte, new \DateTimeImmutable())->getLienPaiement());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_espace_payer', ['id' => $id]);
        }
    }

    /** Retour de la page Orange Money : l'état est relu chez Orange, sans attendre sa notification. */
    #[Route('/payer/{id}/retour', name: 'payer_retour', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function payerRetour(string $id, Request $request, EspaceClient $espace, OrangeMoney $orangeMoney, EntityManagerInterface $em): Response
    {
        $compte = $this->payeur();
        $commande = $espace->commande($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        $transaction = $em->getRepository(TransactionOrangeMoney::class)->findOneBy(['commande' => $commande, 'orderId' => mb_substr($request->query->getString('o'), 0, 40)])
            ?? throw $this->createNotFoundException();

        $this->addFlash(...match ($orangeMoney->verifier($transaction, new \DateTimeImmutable())) {
            TransactionOrangeMoney::REUSSIE => ['succes', \sprintf('Paiement Orange Money de %s FCFA reçu, merci ! La caisse le valide et votre reçu arrive dans l\'espace.', number_format($transaction->getMontant(), 0, ',', ' '))],
            TransactionOrangeMoney::ECHOUEE => ['error', 'Le paiement Orange Money n\'a pas abouti : rien n\'a été débité. Vous pouvez réessayer.'],
            TransactionOrangeMoney::EXPIREE => ['error', 'Le lien de paiement a expiré. Relancez le paiement.'],
            default => ['succes', 'Paiement en cours chez Orange Money : il apparaît ici dès qu\'Orange le confirme.'],
        });

        return $this->redirectToRoute('app_espace_commande', ['id' => $id]);
    }

    /**
     * Mon compte (maquette EC04) : entreprise (raison sociale et NIF proposés
     * à l'atelier), adresses, utilisateurs, sécurité (appareils, dernière
     * connexion, mes données). Un particulier a la même page sans les
     * parties entreprise.
     */
    #[Route('/compte', name: 'compte', methods: ['GET'])]
    public function monCompte(Request $request, MonCompte $monCompte, AppareilsClient $appareils): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $entreprise = CompteClient::clientAPlusieursComptes($client);

        return $this->vue('espace/app/compte.html.twig', [
            'onglet' => 'compte',
            'compte' => $compte,
            'client' => $client,
            'entreprise' => $entreprise,
            'gere' => $compte->peut(CompteClient::DROIT_ADMINISTRATEUR),
            'facturePar' => $monCompte->facturePar($client),
            'conditions' => MonCompte::voitConditions($compte) ? MonCompte::conditions($client) : null,
            'voitConditions' => MonCompte::voitConditions($compte),
            'proposition' => $entreprise ? $monCompte->proposition($client, new \DateTimeImmutable()) : null,
            'utilisateurs' => $entreprise ? array_values(array_filter($this->compteEntreprise->utilisateurs($client), static fn (CompteClient $c): bool => $c->isActif())) : [],
            'adresses' => $this->compteEntreprise->adresses($client),
            'telephoneMasque' => MonCompte::telephoneMasque($compte->getTelephone()),
            'emailCodesMasque' => null !== $compte->emailDesCodes() ? DestinationCode::masquerEmail((string) $compte->emailDesCodes()) : null,
            'appareils' => AppareilsClient::regrouper($appareils->liste($compte, $request)),
        ]);
    }

    #[Route('/compte/preferences', name: 'preferences', methods: ['POST'])]
    public function preferences(Request $request, EntityManagerInterface $em): Response
    {
        $this->jeton($request);
        $compte = $this->compte();
        // Réglage de la fiche (campagnes, sondages, notifications) : la personne qui gère le compte.
        if (!$compte->peut(CompteClient::DROIT_ADMINISTRATEUR)) {
            throw $this->createAccessDeniedException();
        }
        $compte->getClient()->setRecevoirSms($request->request->getBoolean('sms'));
        $em->flush();
        $this->addFlash('succes', 'Préférences enregistrées.');

        return 'notifications' === $request->request->get('retour') ? $this->redirect($this->generateUrl('app_espace_notifications').'#preferences') : $this->redirectToRoute('app_espace_compte');
    }

    /**
     * Avant d'entrer après un code (première connexion, mot de passe
     * oublié, code d'accès de la boutique) : le mot de passe, et l'e-mail
     * où partiront les prochains codes. Seule page ouverte tant que c'est
     * obligatoire (EspaceClientSubscriber).
     */
    #[Route('/securiser', name: 'securiser', methods: ['GET', 'POST'])]
    public function securiser(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em, TokenStorageInterface $jetons): Response
    {
        $compte = $this->compte();
        $session = $request->getSession();
        $par = $session->get(EspaceClientComptes::SESSION_A_FINIR);
        $etapes = EspaceClientComptes::etapes($compte, \is_string($par) ? $par : null);
        if ((null === $etapes['motDePasse'] && null === $etapes['email']) || (!$etapes['bloquant'] && $request->query->getBoolean('plus_tard'))) {
            $session->remove(EspaceClientComptes::SESSION_A_FINIR);

            return $this->redirectToRoute('app_espace_accueil');
        }

        $erreur = null;
        $email = (string) $compte->emailDesCodes();
        if ($request->isMethod('POST')) {
            $email = trim((string) $request->request->get('email', $email));
            $valable = DestinationCode::emailValable($email);
            $nouveau = (string) $request->request->get('mot_de_passe');
            $erreur = match (true) {
                !$this->isCsrfTokenValid('espace-securiser', (string) $request->request->get('_token')) => 'La page a expiré. Réessayez.',
                EspaceClientComptes::REQUIS === $etapes['email'] && null === $valable => 'Indiquez votre adresse e-mail : vos prochains codes y arriveront.',
                null !== $etapes['email'] && '' !== $email && null === $valable => 'Cette adresse e-mail n\'est pas valable.',
                (EspaceClientComptes::REQUIS === $etapes['motDePasse'] || '' !== $nouveau) && mb_strlen($nouveau) < 8 => 'Le mot de passe doit faire au moins 8 caractères.',
                '' !== $nouveau && $nouveau !== (string) $request->request->get('confirmation') => 'Les deux mots de passe ne sont pas identiques.',
                default => null,
            };
            if (null === $erreur) {
                if (null !== $etapes['email'] && null !== $valable) {
                    $this->comptes->noterEmail($compte, $valable);
                }
                if (null !== $etapes['motDePasse'] && '' !== $nouveau) {
                    $compte->setMotDePasse($hasher->hashPassword($compte, $nouveau));
                }
                $em->flush();
                // La session garde le compte à jour : changer de mot de passe ne la ferme pas.
                $jetons->getToken()?->setUser($compte);
                $session->remove(EspaceClientComptes::SESSION_A_FINIR);
                $this->addFlash('succes', '' !== $nouveau ? 'C\'est fait. La prochaine fois, connectez-vous avec votre numéro et votre mot de passe.' : 'C\'est enregistré.');

                return $this->redirect($this->getTargetPath($session, 'espace') ?? $this->generateUrl('app_espace_accueil'));
            }
        }

        $reponse = $this->render('espace/securiser.html.twig', [
            'compte' => $compte,
            'etapes' => $etapes,
            'email' => $email,
            'email_masque' => null !== $compte->emailDesCodes() ? DestinationCode::masquerEmail((string) $compte->emailDesCodes()) : null,
            'erreur' => $erreur,
        ], new Response(null, null === $erreur ? 200 : 422));
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }

    /** Mon compte › Sécurité : l'e-mail où partent les codes, changé avec le mot de passe actuel. */
    #[Route('/compte/email', name: 'email', methods: ['POST'])]
    public function changerEmail(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em): Response
    {
        $compte = $this->compte();
        $email = DestinationCode::emailValable((string) $request->request->get('email'));
        if (!$this->isCsrfTokenValid('espace-email', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'La page a expiré. Réessayez.');
        } elseif (null === $email) {
            $this->addFlash('error', 'Cette adresse e-mail n\'est pas valable.');
        } elseif (!$compte->aUnMotDePasse() || !$hasher->isPasswordValid($compte, (string) $request->request->get('mot_de_passe'))) {
            $this->addFlash('error', 'Mot de passe incorrect : l\'adresse n\'a pas changé.');
        } else {
            $this->comptes->noterEmail($compte, $email);
            $em->flush();
            $this->addFlash('succes', 'Adresse enregistrée : vos prochains codes y arriveront.');
        }

        return $this->redirect($this->generateUrl('app_espace_compte').'#securite');
    }

    #[Route('/mot-de-passe', name: 'mot_de_passe', methods: ['POST'])]
    public function motDePasse(Request $request, UserPasswordHasherInterface $hasher, EntityManagerInterface $em, TokenStorageInterface $jetons): Response
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
            // La session garde le compte à jour : changer de mot de passe ne la ferme pas.
            $jetons->getToken()?->setUser($compte);
            $this->addFlash('succes', 'Mot de passe enregistré.');
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
     * @return list<array{le: \DateTimeImmutable, titre: string, detail: ?string, reperes?: list<string>}>
     */
    private static function frise(?\App\Entity\Conception $c): array
    {
        $frise = [];
        foreach ($c?->getEvenements() ?? [] as $e) {
            $frise[] = match ($e['type']) {
                'bat' => ['le' => $e['le'], 'titre' => $e['titre'], 'detail' => null],
                'retour' => ['le' => $e['le'], 'titre' => 'Votre demande de modification', 'detail' => preg_match('/« (.+?) »/u', (string) $e['detail'], $m) ? $m[1] : null, 'reperes' => array_column($e['reperes'] ?? [], 't')],
                'valide' => ['le' => $e['le'], 'titre' => 'BAT validé', 'detail' => null],
                default => null,
            };
        }

        return array_reverse(array_values(array_filter($frise)));
    }

    /** @param array<string, mixed> $parametres */
    private function vue(string $gabarit, array $parametres): Response
    {
        $compte = $this->compte();

        return $this->render($gabarit, $parametres + [
            'moi' => $compte->getClient(),
            'peutPayer' => $compte->peut(CompteClient::DROIT_PAYEUR),
            'peutValider' => $compte->peut(CompteClient::DROIT_VALIDEUR),
        ]);
    }

    /** Factures, solde et paiement : réservés à qui paie pour l'entreprise. */
    private function payeur(): CompteClient
    {
        $compte = $this->compte();
        if (!$compte->peut(CompteClient::DROIT_PAYEUR)) {
            throw $this->createAccessDeniedException('Les factures sont réservées aux personnes qui paient pour l\'entreprise.');
        }

        return $compte;
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
    }

    /** Jeton des appels du navigateur (en-tête X-CSRF-TOKEN). */
    private function jetonEntete(Request $request): void
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
    }

    /** Nombre saisi (« 85 », « 85,5 ») ou null. */
    private static function nombre(mixed $valeur): ?float
    {
        $texte = str_replace([' ', ','], ['', '.'], trim((string) $valeur));

        return is_numeric($texte) && (float) $texte > 0 ? (float) $texte : null;
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
