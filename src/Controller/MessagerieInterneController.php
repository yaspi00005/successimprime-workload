<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\Conversation;
use App\Entity\Devis;
use App\Entity\Message;
use App\Entity\PieceJointeMessage;
use App\Entity\RoleUtilisateur;
use App\Entity\User;
use App\Repository\ConversationRepository;
use App\Security\FichierEnvoye;
use App\Security\MessagerieInterneVoter;
use App\Security\MessagerieVoter;
use App\Service\Http\Entree;
use App\Service\MessagerieInterne\CartesCommande;
use App\Service\MessagerieInterne\FichiersEquipe;
use App\Service\MessagerieInterne\FichiersPartages;
use App\Service\MessagerieInterne\Groupes;
use App\Service\MessagerieInterne\MessagesEquipe;
use App\Service\MessagerieInterne\RangementDossierClient;
use App\Service\MessagerieInterne\ReglesMessagerieInterne;
use App\Service\Messagerie\NotesVocales;
use App\Service\PastillesMenu;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Messagerie de l'équipe (docs/messagerie-interne.md, maquettes MI01 à
 * MI05) : l'onglet « Équipe » à côté des messages des clients. ERP local
 * seulement (MessagerieInterneHorsLigneSubscriber). Les clients n'y ont
 * jamais accès (pare-feu à part, MessagerieInterneVoter).
 */
#[Route('/equipe/messages', name: 'app_messagerie_interne_')]
#[IsGranted(MessagerieInterneVoter::ACCES)]
final class MessagerieInterneController extends AbstractController
{
    private const JETON = '[0-9a-f]{32}';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ConversationRepository $conversations,
        private readonly MessagesEquipe $messages,
        private readonly Groupes $groupes,
        private readonly CartesCommande $cartes,
        private readonly FichiersEquipe $fichiers,
        private readonly RangementDossierClient $rangement,
        private readonly ReglesMessagerieInterne $regles,
        private readonly PastillesMenu $pastilles,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('messagerie_interne/index.html.twig', $this->page(null));
    }

    #[Route('/{jeton}', name: 'voir', requirements: ['jeton' => self::JETON], methods: ['GET'])]
    public function voir(#[MapEntity(mapping: ['jeton' => 'jeton'])] Conversation $c): Response
    {
        $this->denyAccessUnlessGranted(MessagerieInterneVoter::VOIR, $c);
        $this->messages->marquerLu($c, $this->moi());

        return $this->render('messagerie_interne/index.html.twig', $this->page($c));
    }

    /** Rafraîchissement léger (toutes les quelques secondes) : nouveaux messages, réactions, non-lus. */
    #[Route('/{jeton}/nouveaux', name: 'nouveaux', requirements: ['jeton' => self::JETON], methods: ['GET'])]
    public function nouveaux(#[MapEntity(mapping: ['jeton' => 'jeton'])] Conversation $c, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(MessagerieInterneVoter::VOIR, $c);
        $moi = $this->moi();
        $this->messages->noterPresence($moi);
        $nouveaux = $this->messages->messages($c, Entree::entier($request->query, 'apres'), 100);
        if ([] !== $nouveaux) {
            $this->messages->marquerLu($c, $moi);
        }
        $recents = $this->messages->messages($c, 0, 40);
        $reactions = [];
        foreach ($recents as $m) {
            $reactions[$m->getId()] = $this->renderView('messagerie_interne/_reactions.html.twig', ['m' => $m, 'c' => $c, 'peutReagir' => $this->peutReagir($c)]);
        }

        return $this->json([
            'html' => [] === $nouveaux ? '' : $this->renderView('messagerie_interne/_messages.html.twig', $this->donneesMessages($c, $nouveaux)),
            'dernier' => [] === $nouveaux ? null : end($nouveaux)->getId(),
            'reactions' => $reactions,
            'nonLus' => $this->nonLusParJeton($moi),
        ]);
    }

    #[Route('/{jeton}/envoyer', name: 'envoyer', requirements: ['jeton' => self::JETON], methods: ['POST'])]
    public function envoyer(#[MapEntity(mapping: ['jeton' => 'jeton'])] Conversation $c, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(MessagerieInterneVoter::ECRIRE, $c);
        $this->verifierJeton($request, $c);
        $moi = $this->moi();
        $jetons = array_values(array_filter((array) $request->request->all('fichiers'), 'is_string'));
        $liens = array_values(array_filter((array) $request->request->all('liens'), 'is_string'));
        if (([] !== $jetons || [] !== $liens) && !$this->isGranted(MessagerieInterneVoter::FICHIERS, $c)) {
            return $this->json(['erreur' => 'Dans ce groupe, seuls les responsables envoient des fichiers.'], 403);
        }
        if (\count($jetons) + \count($liens) > 20) {
            return $this->json(['erreur' => '20 fichiers au plus par message.'], 422);
        }
        $avertissements = [];
        $pieces = [];
        try {
            foreach ($jetons as $jeton) {
                $pieces[] = $this->fichiers->rangerEnvoi($moi, $jeton);
            }
            foreach ($liens as $lien) {
                [$clientId, $chemin] = array_pad(explode('|', $lien, 2), 2, '');
                $client = $this->em->find(Clients::class, (int) $clientId);
                if (!$client instanceof Clients || !$this->peutParcourir($c, $client)) {
                    throw new \DomainException('Dossier du client non permis.');
                }
                $pieces[] = $this->fichiers->lienServeur($client, $chemin);
            }
            $reponseA = $this->em->find(Message::class, Entree::entier($request->request, 'reponse'));
            $message = $this->messages->envoyer(
                $c, $moi, (string) $request->request->get('texte', ''),
                $reponseA instanceof Message ? $reponseA : null,
                array_map('intval', (array) $request->request->all('mentions')),
                $pieces,
            );
        } catch (\DomainException $e) {
            // Rien n'est enregistré : les fichiers déjà rangés partent, les envois restent pour réessayer.
            foreach ($pieces as $piece) {
                $this->fichiers->retirer($piece);
            }

            return $this->json(['erreur' => $e->getMessage()], 422);
        }
        $this->fichiers->terminer($moi, $jetons);
        // « Ranger aussi dans le dossier du client » : une copie, jamais d'écrasement, une fois le message enregistré.
        $client = $c->getClient();
        if ($request->request->getBoolean('ranger') && null !== $client) {
            foreach ($pieces as $piece) {
                if ($piece->estLien()) {
                    continue;
                }
                try {
                    $piece->setRangeDans($this->rangement->ranger($client, (string) $this->fichiers->chemin($piece), $piece->getNom()))->setClient($client);
                } catch (\DomainException $e) {
                    $avertissements[] = \sprintf('« %s » n\'a pas été rangé dans le dossier du client : %s', $piece->getNom(), $e->getMessage());
                }
            }
            $this->em->flush();
        }

        return $this->json([
            'html' => $this->renderView('messagerie_interne/_messages.html.twig', $this->donneesMessages($c, [$message])),
            'dernier' => $message->getId(),
            'avertissements' => $avertissements,
        ]);
    }

    /** Note vocale (enregistreur partagé avec les messages des clients, public/js/note-vocale.js). */
    #[Route('/{jeton}/vocale', name: 'vocale', requirements: ['jeton' => self::JETON], methods: ['POST'])]
    public function vocale(#[MapEntity(mapping: ['jeton' => 'jeton'])] Conversation $c, Request $request, NotesVocales $notesVocales): JsonResponse
    {
        if (!$this->isGranted(MessagerieInterneVoter::ECRIRE, $c)) {
            return $this->json(['erreur' => 'Vous ne pouvez pas écrire ici.'], 403);
        }
        if (!$this->isCsrfTokenValid('mi_'.$c->getJeton(), (string) $request->request->get('_token'))) {
            return $this->json(['erreur' => 'Page expirée : rechargez-la, puis réessayez.'], 403);
        }
        $vocale = null;
        try {
            $vocale = $notesVocales->enregistrer($request->files->get('audio'));
            $message = $this->messages->envoyer($c, $this->moi(), '', null, [], [], $vocale);
        } catch (\DomainException $e) {
            $notesVocales->retirer($vocale);

            return $this->json(['erreur' => $e->getMessage()], 422);
        }

        return $this->json([
            'url' => $this->generateUrl('app_messagerie_interne_voir', ['jeton' => $c->getJeton()]).'#m'.$message->getId(),
            'html' => $this->renderView('messagerie_interne/_messages.html.twig', $this->donneesMessages($c, [$message])),
            'dernier' => $message->getId(),
        ]);
    }

    #[Route('/{jeton}/vocale/{id}', name: 'vocale_ecouter', requirements: ['jeton' => self::JETON, 'id' => '\d+'], methods: ['GET'])]
    public function vocaleEcouter(#[MapEntity(mapping: ['jeton' => 'jeton'])] Conversation $c, #[MapEntity(id: 'id')] Message $m, NotesVocales $notesVocales): Response
    {
        $this->denyAccessUnlessGranted(MessagerieInterneVoter::VOIR, $c);
        if ($m->getConversation()?->getId() !== $c->getId() || !$m->aUneNoteVocale() || null === $notesVocales->chemin($m)) {
            throw $this->createNotFoundException();
        }

        return $notesVocales->reponse($m);
    }

    #[Route('/{jeton}/reaction/{id}', name: 'reaction', requirements: ['jeton' => self::JETON, 'id' => '\d+'], methods: ['POST'])]
    public function reaction(#[MapEntity(mapping: ['jeton' => 'jeton'])] Conversation $c, #[MapEntity(id: 'id')] Message $m, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(MessagerieInterneVoter::VOIR, $c);
        $this->verifierJeton($request, $c);
        if (!$this->peutReagir($c) || $m->getConversation()?->getId() !== $c->getId()) {
            return $this->json(['erreur' => 'Réaction impossible ici.'], 403);
        }
        try {
            $this->messages->reagir($m, $this->moi(), (string) $request->request->get('emoji'));
        } catch (\DomainException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        }

        return $this->json(['html' => $this->renderView('messagerie_interne/_reactions.html.twig', ['m' => $m, 'c' => $c, 'peutReagir' => true])]);
    }

    #[Route('/{jeton}/epingler/{id}', name: 'epingler', requirements: ['jeton' => self::JETON, 'id' => '\d+'], methods: ['POST'])]
    public function epingler(#[MapEntity(mapping: ['jeton' => 'jeton'])] Conversation $c, #[MapEntity(id: 'id')] Message $m, Request $request): Response
    {
        $this->denyAccessUnlessGranted(MessagerieInterneVoter::ECRIRE, $c);
        $this->verifierJeton($request, $c);
        if ($m->getConversation()?->getId() !== $c->getId() || $m->estSysteme()) {
            throw $this->createNotFoundException();
        }
        $epingle = $this->messages->epingler($m, $this->moi());
        $this->addFlash('success', $epingle ? 'Message épinglé.' : 'Message désépinglé.');

        return $this->redirectToRoute('app_messagerie_interne_voir', ['jeton' => $c->getJeton(), '_fragment' => 'm'.$m->getId()]);
    }

    /** Fichier ou photo d'un message (ou sa vignette), ou fichier du serveur partagé en lien : après contrôle des droits. */
    #[Route('/{jeton}/fichier/{id}', name: 'fichier', requirements: ['jeton' => self::JETON, 'id' => '\d+'], methods: ['GET'])]
    public function fichier(#[MapEntity(mapping: ['jeton' => 'jeton'])] Conversation $c, #[MapEntity(id: 'id')] PieceJointeMessage $piece, Request $request): Response
    {
        $this->denyAccessUnlessGranted(MessagerieInterneVoter::VOIR, $c);
        if ($piece->getConversation()?->getId() !== $c->getId()) {
            throw $this->createNotFoundException();
        }
        $miniature = $request->query->getBoolean('mini');
        $chemin = $this->fichiers->chemin($piece, $miniature);
        if (null === $chemin) {
            throw $this->createNotFoundException($piece->estLien() ? 'Le fichier n\'est plus à cet endroit dans le dossier du client.' : 'Fichier introuvable.');
        }

        return FichierEnvoye::reponse($chemin, $miniature ? null : $piece->getNom(), $request->query->getBoolean('telecharger'));
    }

    // ------------------------------------------------------------------
    // Envoi des fichiers par morceaux (plusieurs à la fois, progression par fichier)
    // ------------------------------------------------------------------

    #[Route('/envoi', name: 'envoi_debut', methods: ['POST'])]
    public function envoiDebut(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('mi_envoi', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['erreur' => 'Page expirée : rechargez-la.'], 403);
        }
        try {
            $donnees = $request->toArray();

            return $this->json($this->fichiers->debut($this->moi(), (string) ($donnees['nom'] ?? ''), (int) ($donnees['taille'] ?? 0)), 201);
        } catch (\DomainException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        } catch (\Throwable) {
            return $this->json(['erreur' => 'Demande illisible.'], 400);
        }
    }

    #[Route('/envoi/{jeton}/{index}', name: 'envoi_morceau', requirements: ['jeton' => '[0-9a-f]{40}', 'index' => '\d+'], methods: ['POST'])]
    public function envoiMorceau(string $jeton, int $index, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('mi_envoi', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['erreur' => 'Page expirée : rechargez-la.'], 403);
        }
        try {
            return $this->json($this->fichiers->morceau($this->moi(), $jeton, $index, $request->getContent()));
        } catch (\DomainException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        }
    }

    #[Route('/envoi/{jeton}', name: 'envoi_etat', requirements: ['jeton' => '[0-9a-f]{40}'], methods: ['GET'])]
    public function envoiEtat(string $jeton): JsonResponse
    {
        try {
            return $this->json($this->fichiers->etat($this->moi(), $jeton));
        } catch (\DomainException $e) {
            return $this->json(['erreur' => $e->getMessage()], 404);
        }
    }

    /** Fichiers du dossier du client sur le serveur, pour les partager en lien (MI03 « Depuis le dossier du client »). */
    #[Route('/{jeton}/dossier-client', name: 'dossier_client', requirements: ['jeton' => self::JETON], methods: ['GET'])]
    public function dossierClient(#[MapEntity(mapping: ['jeton' => 'jeton'])] Conversation $c, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted(MessagerieInterneVoter::FICHIERS, $c);
        $client = $this->em->find(Clients::class, Entree::entier($request->query, 'client')) ?? $c->getClient();
        if (!$this->fichiers->dossiersDisponibles()) {
            return $this->json(['erreur' => 'Le dossier racine des clients n\'est pas réglé (Paramètres › Atelier › Dossier des clients).', 'fichiers' => []]);
        }
        if (!$client instanceof Clients) {
            return $this->json(['erreur' => 'Choisissez d\'abord un client.', 'fichiers' => []]);
        }
        if (!$this->peutParcourir($c, $client)) {
            return $this->json(['erreur' => 'Vous ne pouvez pas parcourir le dossier de ce client.', 'fichiers' => []], 403);
        }
        $liste = array_map(static fn (array $f): array => ['chemin' => $f['chemin'], 'nom' => $f['nom'], 'dossier' => $f['dossier'], 'octets' => $f['octets'], 'modifie' => $f['modifie']->format('d/m/Y H:i')], $this->fichiers->listeDossier($client));

        return $this->json(['client' => ['id' => $client->getId(), 'libelle' => $client->getCode().' '.$client->getNomComplet()], 'fichiers' => $liste, 'erreur' => [] === $liste ? 'Aucun fichier dans le dossier de ce client (ou dossier introuvable).' : null]);
    }

    /** Recherche d'un client, d'un devis ou d'une commande (groupe de projet, dossier du client). */
    #[Route('/liaisons', name: 'liaisons', methods: ['GET'])]
    public function liaisons(Request $request): JsonResponse
    {
        $q = trim((string) $request->query->get('q', ''));
        $seulementClients = $request->query->getBoolean('clients');
        $resultats = [];
        if (mb_strlen($q) >= 2) {
            $motif = '%'.addcslashes($q, '%_\\').'%';
            if ($this->isGranted('ROLE_CLIENT') || $this->isGranted('ROLE_COMMANDE') || $this->isGranted('ROLE_DEVIS') || $this->isGranted('ROLE_PREPRESSE')) {
                foreach ($this->em->createQuery('SELECT c FROM '.Clients::class.' c WHERE c.code LIKE :q OR c.nom LIKE :q OR c.prenom LIKE :q OR c.raisonSociale LIKE :q ORDER BY c.id DESC')->setParameter('q', $motif)->setMaxResults(8)->getResult() as $cl) {
                    $resultats[] = ['valeur' => 'client:'.$cl->getId(), 'id' => $cl->getId(), 'genre' => 'client', 'libelle' => $cl->getCode().' · '.$cl->getNomComplet()];
                }
            }
            if (!$seulementClients && $this->isGranted('ROLE_DEVIS')) {
                foreach ($this->em->createQuery('SELECT d FROM '.Devis::class.' d WHERE d.numero LIKE :q ORDER BY d.id DESC')->setParameter('q', $motif)->setMaxResults(6)->getResult() as $d) {
                    $resultats[] = ['valeur' => 'devis:'.$d->getId(), 'id' => $d->getId(), 'genre' => 'devis', 'libelle' => trim(($d->getClients()?->getNomComplet() ?? '').' · '.$d->getNumero(), ' ·')];
                }
            }
            if (!$seulementClients && $this->isGranted('ROLE_COMMANDE')) {
                foreach ($this->em->createQuery('SELECT c FROM '.Commandes::class.' c WHERE c.numero LIKE :q AND c.deleted = false ORDER BY c.id DESC')->setParameter('q', $motif)->setMaxResults(6)->getResult() as $cmd) {
                    $resultats[] = ['valeur' => 'commande:'.$cmd->getId(), 'id' => $cmd->getId(), 'genre' => 'commande', 'numero' => $cmd->getNumero(), 'libelle' => trim(($cmd->getClients()?->getNomComplet() ?? '').' · '.$cmd->getNumero(), ' ·')];
                }
            }
        }

        return $this->json(['resultats' => $resultats]);
    }

    // ------------------------------------------------------------------
    // Groupes et messages privés
    // ------------------------------------------------------------------

    #[Route('/groupe', name: 'groupe_creer', methods: ['POST'])]
    public function groupeCreer(Request $request): Response
    {
        $this->denyAccessUnlessGranted(MessagerieInterneVoter::CREER_GROUPE);
        if (!$this->isCsrfTokenValid('mi_groupe', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Page expirée : recommencez.');

            return $this->redirectToRoute('app_messagerie_interne_index');
        }
        $lie = ['client' => null, 'devis' => null, 'commande' => null];
        [$genre, $id] = array_pad(explode(':', (string) $request->request->get('lie', ''), 2), 2, '0');
        $classe = ['client' => Clients::class, 'devis' => Devis::class, 'commande' => Commandes::class][$genre] ?? null;
        if (null !== $classe) {
            $lie[$genre] = $this->em->find($classe, (int) $id);
        }
        $membres = array_values(array_filter(array_map(fn ($i) => $this->em->find(User::class, (int) $i), (array) $request->request->all('membres')), static fn ($u): bool => $u instanceof User));
        try {
            $groupe = $this->groupes->creer($this->moi(), [
                'nom' => (string) $request->request->get('nom', ''),
                'type' => (string) $request->request->get('type', Conversation::PERMANENT),
                'description' => (string) $request->request->get('description', ''),
                'client' => $lie['client'], 'devis' => $lie['devis'], 'commande' => $lie['commande'],
                'fermetureAuto' => $request->request->getBoolean('fermeture_auto', true),
                'fichiersPourTous' => $request->request->getBoolean('fichiers_pour_tous'),
                'rangerDossierClient' => $request->request->getBoolean('ranger_dossier_client'),
                'roles' => array_values(array_filter((array) $request->request->all('roles'), 'is_string')),
                'membres' => $membres,
            ]);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_messagerie_interne_index');
        }
        $this->addFlash('success', \sprintf('Groupe « %s » créé.', $groupe->getNom()));

        return $this->redirectToRoute('app_messagerie_interne_voir', ['jeton' => $groupe->getJeton()]);
    }

    #[Route('/prive', name: 'prive', methods: ['POST'])]
    public function prive(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('mi_prive', (string) $request->request->get('_token'))) {
            return $this->redirectToRoute('app_messagerie_interne_index');
        }
        $autre = $this->em->find(User::class, Entree::entier($request->request, 'personne'));
        try {
            if (!$autre instanceof User) {
                throw new \DomainException('Personne introuvable.');
            }
            $c = $this->groupes->prive($this->moi(), $autre);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_messagerie_interne_index');
        }

        return $this->redirectToRoute('app_messagerie_interne_voir', ['jeton' => $c->getJeton()]);
    }

    #[Route('/{jeton}/membres', name: 'membres', requirements: ['jeton' => self::JETON], methods: ['POST'])]
    public function membres(#[MapEntity(mapping: ['jeton' => 'jeton'])] Conversation $c, Request $request): Response
    {
        $this->verifierJeton($request, $c);
        $moi = $this->moi();
        $action = (string) $request->request->get('action');
        try {
            if ('quitter' === $action) {
                if (!$c->estGroupe() || !$c->aPourParticipant($moi)) {
                    throw $this->createAccessDeniedException();
                }
                $this->groupes->retirerMembre($c, $moi, $moi);
                $this->addFlash('success', 'Vous avez quitté le groupe.');

                return $this->redirectToRoute('app_messagerie_interne_index');
            }
            $this->denyAccessUnlessGranted(MessagerieInterneVoter::GERER, $c);
            $membre = $this->em->find(User::class, Entree::entier($request->request, 'membre'));
            match ($action) {
                'ajouter' => $membre instanceof User ? $this->groupes->ajouterMembre($c, $moi, $membre) : null,
                'retirer' => $membre instanceof User ? $this->groupes->retirerMembre($c, $moi, $membre) : null,
                'role' => $this->groupes->ajouterRoles($c, $moi, array_values(array_filter((array) $request->request->all('roles'), 'is_string'))),
                'fermer' => $this->groupes->fermer($c, $moi),
                'rouvrir' => $this->groupes->rouvrir($c, $moi),
                default => throw $this->createNotFoundException(),
            };
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_messagerie_interne_voir', ['jeton' => $c->getJeton()]);
    }

    // ------------------------------------------------------------------
    // Fichiers partagés (MI04) et recherche
    // ------------------------------------------------------------------

    #[Route('/fichiers', name: 'fichiers', methods: ['GET'])]
    public function fichiersPartages(Request $request, FichiersPartages $partages): Response
    {
        $moi = $this->moi();
        $filtres = [
            'q' => (string) $request->query->get('q', ''),
            'groupe' => Entree::entier($request->query, 'groupe'),
            'personne' => Entree::entier($request->query, 'personne'),
            'commande' => Entree::entier($request->query, 'commande'),
            'onglet' => (string) $request->query->get('onglet', 'tous'),
        ];
        $resultat = $partages->liste($moi, $filtres);
        $lisibles = $partages->lisibles($moi);
        $commandes = $this->em->createQuery('SELECT DISTINCT cmd.id, cmd.numero FROM '.PieceJointeMessage::class.' p JOIN p.commande cmd WHERE p.conversation IN (:f) ORDER BY cmd.id DESC')
            ->setParameter('f', [] === $lisibles ? [0] : $lisibles)->setMaxResults(100)->getArrayResult();
        $personnes = $this->em->createQuery('SELECT DISTINCT a.id FROM '.PieceJointeMessage::class.' p JOIN p.message m JOIN m.auteur a WHERE p.conversation IN (:f)')
            ->setParameter('f', [] === $lisibles ? [0] : $lisibles)->getArrayResult();

        return $this->render('messagerie_interne/fichiers.html.twig', [
            ...$this->page(null),
            'resultat' => $resultat,
            'filtres' => $filtres,
            'onglets' => FichiersPartages::ONGLETS,
            'filtreGroupes' => $lisibles,
            'filtreCommandes' => $commandes,
            'filtrePersonnes' => array_values(array_filter(array_map(fn (array $l) => $this->em->find(User::class, $l['id']), $personnes))),
        ]);
    }

    #[Route('/recherche', name: 'recherche', methods: ['GET'])]
    public function recherche(Request $request, FichiersPartages $partages): Response
    {
        $moi = $this->moi();
        $q = trim((string) $request->query->get('q', ''));
        $personnes = [];
        if (mb_strlen($q) >= 2) {
            $cle = MessagesEquipe::cle($q);
            foreach ($this->collegues($moi) as $u) {
                if (str_contains(MessagesEquipe::cle(Conversation::nomDe($u)), $cle) || str_contains(MessagesEquipe::cle((string) $u->getUsername()), $cle)) {
                    $personnes[] = $u;
                }
            }
        }

        return $this->render('messagerie_interne/recherche.html.twig', [
            ...$this->page(null),
            'q' => $q,
            'trouves' => $partages->rechercherMessages($moi, $q),
            'fichiersTrouves' => mb_strlen($q) >= 2 ? $partages->liste($moi, ['q' => $q], 30)['fichiers'] : [],
            'personnesTrouvees' => \array_slice($personnes, 0, 12),
        ]);
    }

    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function page(?Conversation $c): array
    {
        $moi = $this->moi();
        $this->groupes->rejoindreGroupesDeRole($moi);
        $this->messages->noterPresence($moi);
        $direction = \in_array(User::ROLE_ADMIN, $moi->getRoles(), true);
        $tous = $direction && $this->regles->directionLitGroupes();
        $fils = $this->messages->fils($moi, $tous);
        $pastilles = $this->pastilles->parRoute();
        $donnees = [
            'fils' => $fils,
            'active' => $c,
            'moi' => $moi,
            'regles' => $this->regles,
            'peutCreerGroupe' => $this->isGranted(MessagerieInterneVoter::CREER_GROUPE),
            'collegues' => $this->collegues($moi),
            'roles' => $this->em->getRepository(RoleUtilisateur::class)->findBy([], ['ordre' => 'ASC', 'id' => 'ASC']),
            'ongletClients' => $this->isGranted(MessagerieVoter::BOITE),
            'nombreClients' => $pastilles['app_messages_clients_index'] ?? 0,
            'nombreEquipe' => \count(array_filter([...$fils['groupes'], ...$fils['prives']], static fn (array $l): bool => $l['nonLus'] > 0)),
            'dossiersDisponibles' => $this->fichiers->dossiersDisponibles(),
            'tailleMax' => $this->regles->tailleMax(),
            'morceau' => FichiersEquipe::MORCEAU,
            'emojis' => \App\Entity\ReactionMessage::EMOJIS,
        ];
        if (null === $c) {
            return $donnees;
        }
        $liste = $this->messages->messages($c);
        $pieces = $this->em->getRepository(PieceJointeMessage::class)->findBy(['conversation' => $c], ['id' => 'DESC'], 200);
        $membres = [];
        foreach ($c->getParticipants() as $p) {
            $u = $p->getUtilisateur();
            if (null !== $u && $u->isActif()) {
                $membres[] = ['utilisateur' => $u, 'admin' => $p->isAdmin(), 'presence' => $this->messages->presence($u)];
            }
        }
        usort($membres, static fn (array $a, array $b): int => [!$a['admin'], Conversation::nomDe($a['utilisateur'])] <=> [!$b['admin'], Conversation::nomDe($b['utilisateur'])]);

        return [
            ...$donnees,
            ...$this->donneesMessages($c, $liste),
            'membres' => $membres,
            'pieces' => $pieces,
            'liensTexte' => $this->liensDuTexte($liste),
            'epingles' => $this->messages->epingles($c),
            'lectureDirection' => MessagerieInterneVoter::lectureDirection($moi, $c),
            'peutEcrire' => $this->isGranted(MessagerieInterneVoter::ECRIRE, $c),
            'peutFichiers' => $this->isGranted(MessagerieInterneVoter::FICHIERS, $c),
            'peutGerer' => $this->isGranted(MessagerieInterneVoter::GERER, $c),
            'peutParcourir' => $this->peutParcourir($c, $c->getClient()),
            'rangementRaison' => $this->rangement->raisonIndisponible($c->getClient()),
            'rangementDestination' => null !== $c->getClient() ? $this->rangement->destination($c->getClient()) : null,
            'autre' => $c->estPrive() ? $c->getAutreUtilisateurPour($moi) : null,
            'presenceAutre' => $c->estPrive() && null !== $c->getAutreUtilisateurPour($moi) ? $this->messages->presence($c->getAutreUtilisateurPour($moi)) : null,
        ];
    }

    /**
     * Messages et leurs cartes de commande, vues par le lecteur.
     *
     * @param list<Message> $liste
     *
     * @return array<string, mixed>
     */
    private function donneesMessages(Conversation $c, array $liste): array
    {
        $ids = [];
        foreach ($liste as $m) {
            $ids = [...$ids, ...$m->getCommandes()];
        }
        $cartes = [];
        foreach ([] === $ids ? [] : $this->em->getRepository(Commandes::class)->findBy(['id' => array_values(array_unique($ids))]) as $commande) {
            $cartes[$commande->getId()] = $this->cartes->carte($commande);
        }
        $noms = [];
        foreach ($c->getParticipants() as $p) {
            if (null !== $p->getUtilisateur()) {
                $noms[$p->getUtilisateur()->getId()] = $p->getUtilisateur();
            }
        }

        return ['c' => $c, 'liste' => $liste, 'cartes' => $cartes, 'peutReagir' => $this->peutReagir($c), 'peutEpingler' => $this->isGranted(MessagerieInterneVoter::ECRIRE, $c), 'membresParId' => $noms];
    }

    /**
     * @param list<Message> $liste
     *
     * @return list<array{url: string, message: Message}>
     */
    private function liensDuTexte(array $liste): array
    {
        $liens = [];
        foreach (array_reverse($liste) as $m) {
            if (preg_match_all('#https?://[^\s<>"]+#u', $m->getContenu(), $t)) {
                foreach ($t[0] as $url) {
                    $liens[] = ['url' => $url, 'message' => $m];
                }
            }
        }

        return \array_slice($liens, 0, 30);
    }

    /** @return array<string, int> jeton => non-lus */
    private function nonLusParJeton(User $moi): array
    {
        $nonLus = $this->messages->nonLus($moi);
        if ([] === $nonLus) {
            return [];
        }
        $jetons = [];
        foreach ($this->conversations->findBy(['id' => array_keys($nonLus)]) as $c) {
            $jetons[$c->getJeton()] = $nonLus[$c->getId()];
        }

        return $jetons;
    }

    /** Parcourir le dossier d'un client : ses fichiers sont ceux du client, pas pour tout le monde. */
    private function peutParcourir(Conversation $c, ?Clients $client): bool
    {
        if (null === $client || !$this->isGranted(MessagerieInterneVoter::FICHIERS, $c)) {
            return false;
        }
        $metier = $this->isGranted('ROLE_CLIENT') || $this->isGranted('ROLE_COMMANDE') || $this->isGranted('ROLE_PREPRESSE') || $this->isGranted('ROLE_PRODUCTION_VOIR');
        // Le client du groupe : les membres qui travaillent les commandes ; un autre client : qui a les fiches clients.
        return $metier && ($client->getId() === $c->getClient()?->getId() || $this->isGranted('ROLE_CLIENT') || $this->isGranted('ROLE_PREPRESSE'));
    }

    private function peutReagir(Conversation $c): bool
    {
        $moi = $this->moi();

        return $c->aPourParticipant($moi) && !$c->estFerme();
    }

    /** @return list<User> collègues actifs qui ont la messagerie (nouveau message privé, membres, @mentions) */
    private function collegues(User $moi): array
    {
        $liste = array_values(array_filter(
            $this->em->getRepository(User::class)->findBy(['actif' => true]),
            static fn (User $u): bool => $u->getId() !== $moi->getId() && MessagerieInterneVoter::aAcces($u),
        ));
        usort($liste, static fn (User $a, User $b): int => strcoll(Conversation::nomDe($a), Conversation::nomDe($b)));

        return $liste;
    }

    private function verifierJeton(Request $request, Conversation $c): void
    {
        $jeton = (string) ($request->request->get('_token') ?? $request->headers->get('X-CSRF-TOKEN'));
        if (!$this->isCsrfTokenValid('mi_'.$c->getJeton(), $jeton)) {
            throw $this->createAccessDeniedException('Page expirée : rechargez-la.');
        }
    }

    private function moi(): User
    {
        $u = $this->getUser();
        if (!$u instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $u;
    }
}
