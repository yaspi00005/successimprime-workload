<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\ControlePrePresse;
use App\Entity\ConversationClient;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\MessageClient;
use App\Entity\NoteConversation;
use App\Entity\PropositionClient;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\MessagerieVoter;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\PropositionsClient;
use App\Service\Fidelite\Fidelite;
use App\Service\Http\Entree;
use App\Service\Messagerie\Conversations;
use App\Service\Messagerie\DelaisReponse;
use App\Service\Messagerie\FilConversation;
use App\Service\Messagerie\NotesVocales;
use App\Service\Messagerie\ReglesReponse;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Clients › Messages (maquettes MS03, MS04) : la boîte de l'agent (Mes
 * conversations, En retard, Non assignées, Clôturées), la conversation avec
 * son chronomètre, ses notes internes et la fiche du client ; pour la
 * Direction, la supervision des délais, toutes les conversations et les
 * règles de réponse.
 *
 * Les réponses partent de l'ERP de l'imprimerie : sur le site en ligne, les
 * pages se lisent seulement.
 *
 * Aussi, depuis la fiche client : appliquer ou refuser un changement de
 * raison sociale ou de NIF demandé en ligne (Mon compte, maquette EC04).
 */
#[Route('/clients', name: 'app_messages_clients_')]
final class MessagesClientsController extends AbstractController
{
    private const CSRF = 'messages-clients';
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
    public const ONGLETS = ['mes' => 'Mes conversations', 'retard' => 'En retard', 'non_assignees' => 'Non assignées', 'cloturees' => 'Clôturées'];

    /** Réponses rapides proposées sous la saisie (le texte se modifie avant l'envoi). */
    public const REPONSES_RAPIDES = [
        'Bien reçu' => 'Bonjour, bien reçu : je regarde et je reviens vers vous très vite.',
        'BAT dans l\'heure' => 'Bonjour, votre fichier est en contrôle : vous recevez le BAT dans l\'heure.',
        'Ajout accepté' => 'Bonjour, c\'est possible : je mets la commande à jour et je vous confirme le nouveau montant.',
        'Commande prête' => 'Bonjour, votre commande est prête : vous pouvez la retirer à l\'accueil, du lundi au samedi.',
        'Livraison' => 'Bonjour, la livraison est prévue ; le livreur vous appelle 30 min avant.',
    ];

    public function __construct(
        private readonly Conversations $conversations,
        private readonly DelaisReponse $delais,
        private readonly FilConversation $fil,
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    // ------------------------------------------------------------------
    // Boîte de l'agent (MS03)
    // ------------------------------------------------------------------

    #[Route('/messages', name: 'index', methods: ['GET'])]
    #[IsGranted(MessagerieVoter::BOITE)]
    public function index(Request $request): Response
    {
        $onglet = $request->query->getString('onglet', 'mes');
        if (!\array_key_exists($onglet, self::ONGLETS)) {
            $onglet = 'mes';
        }
        $boite = $this->boite($this->moi(), new \DateTimeImmutable());
        $liste = $boite[$onglet];
        // Sur ordinateur, la première conversation s'ouvre à côté de la liste.
        $premiere = $liste[0]['conversation'] ?? null;
        if (null !== $premiere && !$request->query->getBoolean('liste')) {
            return $this->redirectToRoute('app_messages_clients_voir', ['publicId' => (string) $premiere->getPublicId(), 'onglet' => $onglet]);
        }

        return $this->render('messages_clients/index.html.twig', $this->vueBoite($boite, $onglet) + ['active' => null]);
    }

    #[Route('/messages/{publicId}', name: 'voir', requirements: ['publicId' => self::UUID], methods: ['GET'])]
    public function voir(string $publicId, Request $request, EspaceClient $espace, UserRepository $utilisateurs, Fidelite $fideliteService): Response
    {
        $c = $this->conversation($publicId);
        $maintenant = new \DateTimeImmutable();
        $onglet = $request->query->getString('onglet', $c->estCloturee() ? 'cloturees' : 'mes');
        if (!\array_key_exists($onglet, self::ONGLETS)) {
            $onglet = 'mes';
        }
        $this->conversations->marquerLueParAtelier($c, $maintenant);
        $commande = $c->getCommande();
        $messages = $this->conversations->messages($c);
        $client = $c->getClient();
        $fidelite = null !== $client ? $fideliteService->etat($client, $maintenant)['solde'] : null;
        $collegues = array_values(array_filter($utilisateurs->findBy(['actif' => true], ['username' => 'ASC']), static fn (User $u): bool => [] !== array_intersect(MessagerieVoter::ROLES_BOITE, $u->getRoles()) || \in_array('ROLE_ADMIN', $u->getRoles(), true)));

        return $this->render('messages_clients/index.html.twig', $this->vueBoite($this->boite($this->moi(), $maintenant), $onglet) + [
            'active' => $c,
            // L'acompte reçu (séparateur du jour) suit le droit de voir les paiements.
            'fil' => $this->fil->construire($c, $messages, $this->conversations->notes($c), $this->isGranted('ROLE_PAIEMENT_VOIR') || $this->isGranted('ROLE_ADMIN')),
            'chrono' => $this->delais->chrono($c, $maintenant),
            'dernierDelai' => $this->delais->dernierDelai($c),
            'carte' => null !== $commande ? $espace->carte($commande) : null,
            'facture' => null !== $commande ? $this->em->getRepository(Factures::class)->findOneBy(['commande' => $commande], ['id' => 'DESC']) : null,
            'bats' => null !== $commande ? $this->em->createQuery('SELECT b FROM '.ControlePrePresse::class.' b JOIN b.commandeDetail d WHERE d.commande = :c ORDER BY b.id DESC')->setParameter('c', $commande)->setMaxResults(10)->getResult() : [],
            'factures' => null !== $commande ? $this->em->getRepository(Factures::class)->findBy(['commande' => $commande], ['id' => 'DESC'], 5) : [],
            'fidelite' => $fidelite,
            'nbCommandes' => null !== $client ? (int) $this->em->createQuery('SELECT COUNT(co.id) FROM App\Entity\Commandes co WHERE co.clients = :c AND co.deleted = false AND co.soldeOuverture = false')->setParameter('c', $client)->getSingleScalarResult() : 0,
            'participants' => $this->conversations->participants($c),
            'collegues' => $collegues,
            'reponsesRapides' => self::REPONSES_RAPIDES,
            'regles' => $this->conversations->regles(),
            'max' => MessageClient::TEXTE_MAX,
        ]);
    }

    #[Route('/messages/{publicId}/repondre', name: 'repondre', requirements: ['publicId' => self::UUID], methods: ['POST'])]
    public function repondre(string $publicId, Request $request): Response
    {
        $c = $this->conversationAEcrire($publicId, $request);
        $bat = Entree::entier($request->request, 'bat') > 0 ? $this->em->find(ControlePrePresse::class, Entree::entier($request->request, 'bat')) : null;
        $facture = Entree::entier($request->request, 'facture') > 0 ? $this->em->find(Factures::class, Entree::entier($request->request, 'facture')) : null;
        try {
            if ('note' === $request->request->getString('mode')) {
                $this->conversations->noteInterne($c, $this->moi(), $request->request->getString('texte'), new \DateTimeImmutable());
                $this->addFlash('success', 'Note interne ajoutée : le client ne la voit pas.');
            } else {
                $m = $this->conversations->repondre($c, $this->moi(), $request->request->getString('texte'), $bat, $facture, new \DateTimeImmutable());
                $this->addFlash('success', \sprintf('Réponse envoyée%s : %s la lit dans son espace et en est prévenu.', null !== $m->getDelaiMinutes() ? ' en '.ReglesReponse::duree($m->getDelaiMinutes()).($m->estEnRetard() ? ' (retard de '.ReglesReponse::duree((int) $m->getRetardMinutes()).')' : '') : '', $c->getClient()?->getNomComplet()));
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->retour($c, $request);
    }

    /**
     * « Note vocale » dans la conversation (docs/messagerie-clients.md) :
     * envoyée au client comme une réponse (chronomètre arrêté, il est
     * prévenu), ou note interne pour l'équipe (mode « note »). Réponse en
     * JSON pour l'enregistreur de la page.
     */
    #[Route('/messages/{publicId}/vocale', name: 'vocale', requirements: ['publicId' => self::UUID], methods: ['POST'])]
    public function vocale(string $publicId, Request $request, NotesVocales $notesVocales): JsonResponse
    {
        $c = $this->conversationAEcrire($publicId, $request);

        return $this->joindreVocale($c, $request, $notesVocales);
    }

    /**
     * « Note vocale » depuis la fiche d'une commande (DT02) : dans la
     * conversation de la commande, ouverte au besoin comme « Écrire au
     * client ».
     */
    #[Route('/messages/vocale/commande/{id}', name: 'vocale_commande', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted(MessagerieVoter::BOITE)]
    public function vocaleCommande(int $id, Request $request, NotesVocales $notesVocales): JsonResponse
    {
        $this->jeton($request);
        if ($this->enLigne) {
            throw $this->createAccessDeniedException('Les réponses partent de l\'ERP de l\'imprimerie.');
        }
        $commande = $this->em->find(Commandes::class, $id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted('ROLE_COMMANDE');
        try {
            $c = $this->conversations->demarrer($commande, $this->moi(), new \DateTimeImmutable());
        } catch (\DomainException $e) {
            return new JsonResponse(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$this->isGranted(MessagerieVoter::VOIR, $c)) {
            return new JsonResponse(['erreur' => \sprintf('La conversation de %s est suivie par %s.', $commande->getNumero(), Conversations::nom($c->getAssigneA()))], Response::HTTP_FORBIDDEN);
        }

        return $this->joindreVocale($c, $request, $notesVocales, $this->generateUrl('app_commandes_show', ['id' => $commande->getId(), '_fragment' => 'conversation']));
    }

    /**
     * Écoute d'une note vocale de la conversation : mêmes droits que la
     * conversation (MESSAGERIE_VOIR), et la note doit en faire partie.
     */
    #[Route('/messages/{publicId}/vocale/{genre}/{id}', name: 'vocale_ecouter', requirements: ['publicId' => self::UUID, 'genre' => 'message|note', 'id' => '\d+'], methods: ['GET'])]
    public function ecouterVocale(string $publicId, string $genre, int $id, NotesVocales $notesVocales): Response
    {
        $c = $this->conversation($publicId);
        $element = $this->em->find('note' === $genre ? NoteConversation::class : MessageClient::class, $id);
        if (null === $element || !NotesVocales::appartient($element, $c) || null === $notesVocales->chemin($element)) {
            throw $this->createNotFoundException();
        }

        return $notesVocales->reponse($element);
    }

    /** « Clôturer la demande » : proposée au client, qui confirme (« Oui, c'est résolu »). */
    #[Route('/messages/{publicId}/cloturer', name: 'cloturer', requirements: ['publicId' => self::UUID], methods: ['POST'])]
    public function cloturer(string $publicId, Request $request): Response
    {
        $c = $this->conversationAEcrire($publicId, $request);
        $this->conversations->proposerCloture($c, $this->moi(), new \DateTimeImmutable());
        $this->addFlash('success', 'Clôture proposée au client : il confirme d\'un geste. Sans réponse de sa part, la demande se clôture seule après '.$this->conversations->regles()->clotureAutoHeures().' h.');

        return $this->retour($c, $request);
    }

    #[Route('/messages/{publicId}/transferer', name: 'transferer', requirements: ['publicId' => self::UUID], methods: ['POST'])]
    public function transferer(string $publicId, Request $request, UserRepository $utilisateurs): Response
    {
        $c = $this->conversationAEcrire($publicId, $request);
        $vers = $utilisateurs->find(Entree::entier($request->request, 'vers'));
        if (!$vers instanceof User || !$vers->isActif() || ([] === array_intersect(MessagerieVoter::ROLES_BOITE, $vers->getRoles()) && !\in_array('ROLE_ADMIN', $vers->getRoles(), true))) {
            $this->addFlash('error', 'Choisissez un collègue qui répond aux clients.');
        } else {
            $this->conversations->transferer($c, $vers, $this->moi(), new \DateTimeImmutable());
            $this->addFlash('success', \sprintf('Demande transférée à %s : le retard éventuel lui est désormais imputé.', Conversations::nom($vers)));
        }

        return $this->retour($c, $request);
    }

    /** « Je la prends » : une conversation non assignée devient la sienne. */
    #[Route('/messages/{publicId}/prendre', name: 'prendre', requirements: ['publicId' => self::UUID], methods: ['POST'])]
    public function prendre(string $publicId, Request $request): Response
    {
        $c = $this->conversationAEcrire($publicId, $request);
        if (null === $c->getAssigneA()) {
            $this->conversations->transferer($c, $this->moi(), $this->moi(), new \DateTimeImmutable());
            $this->addFlash('success', 'Conversation assignée à vous.');
        }

        return $this->retour($c, $request);
    }

    #[Route('/messages/{publicId}/ajouter', name: 'ajouter', requirements: ['publicId' => self::UUID], methods: ['POST'])]
    public function ajouter(string $publicId, Request $request, UserRepository $utilisateurs): Response
    {
        $c = $this->conversationAEcrire($publicId, $request);
        $qui = $utilisateurs->find(Entree::entier($request->request, 'qui'));
        if (!$qui instanceof User || !$qui->isActif() || ([] === array_intersect(MessagerieVoter::ROLES_BOITE, $qui->getRoles()) && !\in_array('ROLE_ADMIN', $qui->getRoles(), true))) {
            $this->addFlash('error', 'Choisissez un collègue qui peut écrire aux clients.');
        } else {
            $this->conversations->ajouterParticipant($c, $qui, $this->moi(), new \DateTimeImmutable());
            $this->addFlash('success', \sprintf('%s est ajouté(e) à la conversation : le client le voit dans le fil.', Conversations::nom($qui)));
        }

        return $this->retour($c, $request);
    }

    /** « Écrire au client » depuis la fiche d'une commande ou d'un devis (DT02, DT03) : sa conversation, ouverte au besoin. */
    #[Route('/messages/demarrer/{type}/{id}', name: 'demarrer', requirements: ['type' => 'commande|devis', 'id' => '\d+'], methods: ['POST'])]
    #[IsGranted(MessagerieVoter::BOITE)]
    public function demarrer(string $type, int $id, Request $request): Response
    {
        $this->jeton($request);
        if ($this->enLigne) {
            throw $this->createAccessDeniedException('Les réponses partent de l\'ERP de l\'imprimerie.');
        }
        $document = $this->em->find('commande' === $type ? Commandes::class : Devis::class, $id) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted('commande' === $type ? 'ROLE_COMMANDE' : 'ROLE_DEVIS');
        try {
            $c = $this->conversations->demarrer($document, $this->moi(), new \DateTimeImmutable());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_messages_clients_index'));
        }
        if (!$this->isGranted(MessagerieVoter::VOIR, $c)) {
            $this->addFlash('error', \sprintf('La conversation de %s est suivie par %s.', $document->getNumero(), Conversations::nom($c->getAssigneA())));

            return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_messages_clients_index'));
        }

        return $this->redirectToRoute('app_messages_clients_voir', ['publicId' => (string) $c->getPublicId(), '_fragment' => 'mc-ecrire']);
    }

    // ------------------------------------------------------------------
    // Direction (MS04)
    // ------------------------------------------------------------------

    #[Route('/messages/supervision', name: 'supervision', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function supervision(Request $request, ReglesReponse $regles): Response
    {
        [$debut, $fin, $semaines] = $this->semaine($request->query->getString('semaine'));
        $maintenant = new \DateTimeImmutable();

        return $this->render('messages_clients/supervision.html.twig', [
            'stats' => $this->delais->supervision($debut, $fin),
            'retards' => $this->delais->retardsEnCours($this->conversations->enAttente(), $maintenant),
            'debut' => $debut, 'fin' => $fin, 'semaines' => $semaines, 'semaine' => $debut->format('Y-m-d'),
            'regles' => $regles,
            'jeton' => self::CSRF,
            'enLigne' => $this->enLigne,
        ]);
    }

    #[Route('/messages/supervision/export', name: 'supervision_export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function export(Request $request): Response
    {
        [$debut, $fin] = $this->semaine($request->query->getString('semaine'));
        $stats = $this->delais->supervision($debut, $fin);
        $reponse = new StreamedResponse(static function () use ($stats): void {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Personne', 'Conversations', '1re réponse médiane (min)', 'Réponses en retard', 'Retard cumulé (min)', 'Points'], ';', '"', '');
            foreach ($stats['personnes'] as $p) {
                fputcsv($f, [Conversations::nom($p['personne']), $p['conversations'], $p['mediane'] ?? '', $p['enRetard'], $p['retardCumule'], $p['points']], ';', '"', '');
            }
            fclose($f);
        });
        $reponse->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $reponse->headers->set('Content-Disposition', 'attachment; filename="delais-reponse-'.$debut->format('Y-m-d').'.csv"');

        return $reponse;
    }

    #[Route('/messages/{publicId}/relancer', name: 'relancer', requirements: ['publicId' => self::UUID], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function relancer(string $publicId, Request $request): Response
    {
        $this->jeton($request);
        $c = $this->conversation($publicId);
        $prevenu = $this->conversations->relancer($c, $this->moi(), new \DateTimeImmutable());
        $this->addFlash('success', $prevenu ? \sprintf('%s est relancé(e).', Conversations::nom($c->getAssigneA())) : 'Conversation non assignée : la caisse est relancée.');

        return $this->redirectToRoute('app_messages_clients_supervision');
    }

    #[Route('/messages/toutes', name: 'toutes', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toutes(Request $request): Response
    {
        $statut = $request->query->getString('statut');
        $q = trim(mb_substr($request->query->getString('q'), 0, 60));
        $qb = $this->em->createQueryBuilder()->select('c', 'cl')->from(ConversationClient::class, 'c')->join('c.client', 'cl')
            ->leftJoin('c.commande', 'co')->leftJoin('c.devis', 'd')
            ->orderBy('c.dernierMessageLe', 'DESC')->addOrderBy('c.id', 'DESC')->setMaxResults(200);
        if (\in_array($statut, [ConversationClient::OUVERTE, ConversationClient::CLOTUREE], true)) {
            $qb->andWhere('c.statut = :s')->setParameter('s', $statut);
        } elseif ('attente' === $statut) {
            $qb->andWhere('c.statut = :s AND c.attenteDepuis IS NOT NULL')->setParameter('s', ConversationClient::OUVERTE);
        }
        if ('' !== $q) {
            $qb->andWhere('cl.nom LIKE :q OR cl.prenom LIKE :q OR cl.raisonSociale LIKE :q OR cl.code LIKE :q OR co.numero LIKE :q OR d.numero LIKE :q OR c.objet LIKE :q')->setParameter('q', '%'.addcslashes($q, '%_').'%');
        }
        $liste = $qb->getQuery()->getResult();
        $maintenant = new \DateTimeImmutable();

        return $this->render('messages_clients/toutes.html.twig', [
            'liste' => array_map(fn (ConversationClient $c): array => ['conversation' => $c, 'chrono' => $this->delais->chrono($c, $maintenant)], $liste),
            'derniers' => $this->conversations->derniers($liste),
            'statut' => $statut, 'q' => $q,
        ]);
    }

    #[Route('/messages/regles', name: 'regles', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function regles(Request $request, Parametres $parametres, ReglesReponse $regles, UserRepository $utilisateurs): Response
    {
        if ($request->isMethod('POST')) {
            $this->jeton($request);
            $valeurs = [];
            foreach (['premiere_minutes', 'suivantes_minutes', 'tresoriere', 'escalade_minutes', 'penalite_points', 'cloture_auto_heures', 'reouverture_jours'] as $cle) {
                $valeurs[$cle] = (string) Entree::entier($request->request, $cle);
            }
            $resultat = $parametres->enregistrer(ReglesReponse::TUILE, $valeurs, $this->moi());
            $this->addFlash('success', match ($resultat) {
                'propose' => 'Changement proposé : il s\'applique quand il est validé sur l\'ERP de l\'imprimerie.',
                'inchange' => 'Rien n\'a changé.',
                default => 'Règles de réponse enregistrées.',
            });

            return $this->redirectToRoute('app_messages_clients_regles');
        }
        $tresorieres = array_values(array_filter($utilisateurs->findBy(['actif' => true], ['username' => 'ASC']), static fn (User $u): bool => !\in_array('ROLE_ADMIN', $u->getRoles(), true) && [] !== array_intersect(['ROLE_PAIEMENT_ENCAISSER', 'ROLE_FACTURE', 'ROLE_TRESORERIE_VOIR', 'ROLE_TRESORERIE_SAISIR'], $u->getRoles())));

        return $this->render('messages_clients/regles.html.twig', [
            'regles' => $regles,
            'tresorieres' => $tresorieres,
            'jeton' => self::CSRF,
        ]);
    }

    // ------------------------------------------------------------------
    // Fiche client : changement demandé en ligne (EC04)
    // ------------------------------------------------------------------

    #[Route('/propositions/{id}/{decision}', name: 'proposition', requirements: ['id' => '\d+', 'decision' => 'appliquer|refuser'], methods: ['POST'])]
    #[IsGranted('ROLE_CLIENT')]
    public function proposition(PropositionClient $proposition, string $decision, Request $request, PropositionsClient $propositions): Response
    {
        $this->jeton($request);
        $moi = $this->getUser();
        if (!$moi instanceof User) {
            throw $this->createAccessDeniedException();
        }
        try {
            if ('appliquer' === $decision) {
                $propositions->appliquer($proposition, $moi, new \DateTimeImmutable());
                $this->addFlash('success', 'Changement appliqué à la fiche : le client le voit dans son espace.');
            } else {
                $propositions->refuser($proposition, $moi, $request->request->getString('motif'), new \DateTimeImmutable());
                $this->addFlash('success', 'Demande refusée : le client voit le motif dans son espace.');
            }
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_clients_show', ['publicId' => $proposition->getClient()?->getPublicId()]);
    }

    // ------------------------------------------------------------------

    /**
     * Les quatre onglets de la boîte, chacun avec ses conversations visibles.
     *
     * @return array<string, list<array{conversation: ConversationClient, chrono: ?array<string, mixed>}>>
     */
    private function boite(User $moi, \DateTimeImmutable $maintenant): array
    {
        $tresoriere = $this->conversations->regles()->tresoriere();
        $direction = \in_array('ROLE_ADMIN', $moi->getRoles(), true);
        /** @var list<ConversationClient> $ouvertes */
        $ouvertes = $this->em->createQuery('SELECT c, cl FROM '.ConversationClient::class.' c JOIN c.client cl WHERE c.statut = :o ORDER BY c.dernierMessageLe DESC')
            ->setParameter('o', ConversationClient::OUVERTE)->setMaxResults(500)->getResult();
        /** @var list<ConversationClient> $cloturees */
        $cloturees = $this->em->createQuery('SELECT c, cl FROM '.ConversationClient::class.' c JOIN c.client cl WHERE c.statut = :f ORDER BY c.clotureeLe DESC')
            ->setParameter('f', ConversationClient::CLOTUREE)->setMaxResults(300)->getResult();
        $boite = ['mes' => [], 'retard' => [], 'non_assignees' => [], 'cloturees' => []];
        foreach ($ouvertes as $c) {
            if (!MessagerieVoter::peutVoir($moi, $c, $tresoriere)) {
                continue;
            }
            $ligne = ['conversation' => $c, 'chrono' => $this->delais->chrono($c, $maintenant)];
            $mienne = $c->getAssigneA() === $moi || $c->aPourParticipant($moi) || ($c->getCommande()?->getAgents() ?? $c->getDevis()?->getAgents()) === $moi;
            if (null === $c->getAssigneA()) {
                $boite['non_assignees'][] = $ligne;
            }
            if ($mienne || ($direction && null !== $c->getAssigneA())) {
                $boite['mes'][] = $ligne;
            }
            if (null !== $ligne['chrono'] && $ligne['chrono']['retard'] > 0) {
                $boite['retard'][] = $ligne;
            }
        }
        foreach ($cloturees as $c) {
            if (MessagerieVoter::peutVoir($moi, $c, $tresoriere)) {
                $boite['cloturees'][] = ['conversation' => $c, 'chrono' => null];
            }
        }
        // Qui attend passe devant ; la plus en retard d'abord.
        $tri = static fn (array $a, array $b): int => [null !== $b['chrono'], $b['chrono']['retard'] ?? 0, $b['conversation']->getDernierMessageLe()] <=> [null !== $a['chrono'], $a['chrono']['retard'] ?? 0, $a['conversation']->getDernierMessageLe()];
        usort($boite['mes'], $tri);
        usort($boite['retard'], $tri);
        usort($boite['non_assignees'], $tri);

        return $boite;
    }

    /** @return array<string, mixed> */
    private function vueBoite(array $boite, string $onglet): array
    {
        $toutes = array_merge(...array_values(array_map(static fn (array $l): array => array_column($l, 'conversation'), $boite)));

        return [
            'onglets' => self::ONGLETS,
            'onglet' => $onglet,
            'boite' => $boite,
            'derniers' => $this->conversations->derniers(array_values(array_unique($toutes, \SORT_REGULAR))),
            'jeton' => self::CSRF,
            'enLigne' => $this->enLigne,
            'maintenant' => new \DateTimeImmutable(),
        ];
    }

    /** @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: list<\DateTimeImmutable>} */
    private function semaine(string $choisie): array
    {
        $lundi = (new \DateTimeImmutable('monday this week'))->setTime(0, 0);
        $semaines = [];
        for ($i = 0; $i < 8; ++$i) {
            $semaines[] = $lundi->modify('-'.$i.' week');
        }
        $debut = $lundi;
        foreach ($semaines as $s) {
            if ($s->format('Y-m-d') === $choisie) {
                $debut = $s;
            }
        }

        return [$debut, $debut->modify('+7 days'), $semaines];
    }

    private function joindreVocale(ConversationClient $c, Request $request, NotesVocales $notesVocales, ?string $retour = null): JsonResponse
    {
        $interne = 'note' === $request->request->getString('mode');
        $vocale = null;
        try {
            $vocale = $notesVocales->enregistrer($request->files->get('audio'));
            $libelle = MessageClient::libelleVocale($vocale['duree']);
            if ($interne) {
                $this->conversations->noteInterne($c, $this->moi(), $libelle, new \DateTimeImmutable(), $vocale);
                $this->addFlash('success', 'Note vocale interne ajoutée : le client ne l\'entend pas.');
            } else {
                $this->conversations->repondre($c, $this->moi(), $libelle, null, null, new \DateTimeImmutable(), $vocale);
                $this->addFlash('success', \sprintf('Note vocale envoyée : %s l\'écoute dans son espace et en est prévenu.', $c->getClient()?->getNomComplet()));
            }
        } catch (\DomainException $e) {
            $notesVocales->retirer($vocale);

            return new JsonResponse(['erreur' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['ok' => true, 'url' => $retour ?? $this->generateUrl('app_messages_clients_voir', ['publicId' => (string) $c->getPublicId(), 'onglet' => $request->request->getString('onglet') ?: null, '_fragment' => 'dernier'])]);
    }

    private function retour(ConversationClient $c, Request $request): Response
    {
        return $this->redirectToRoute('app_messages_clients_voir', ['publicId' => (string) $c->getPublicId(), 'onglet' => $request->request->getString('onglet') ?: null, '_fragment' => 'dernier']);
    }

    private function conversation(string $publicId): ConversationClient
    {
        $c = $this->conversations->parId($publicId) ?? throw $this->createNotFoundException();
        $this->denyAccessUnlessGranted(MessagerieVoter::VOIR, $c);

        return $c;
    }

    private function conversationAEcrire(string $publicId, Request $request): ConversationClient
    {
        $this->jeton($request);
        if ($this->enLigne) {
            throw $this->createAccessDeniedException('Les réponses partent de l\'ERP de l\'imprimerie.');
        }

        return $this->conversation($publicId);
    }

    private function moi(): User
    {
        $moi = $this->getUser();
        if (!$moi instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $moi;
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
