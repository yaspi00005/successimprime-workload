<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Entity\ConversationClient;
use App\Entity\Devis;
use App\Entity\MessageClient;
use App\Repository\CompteClientRepository;
use App\Repository\EntrepriseRepository;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Http\Entree;
use App\Service\Messagerie\Conversations;
use App\Service\Messagerie\FilConversation;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client › Messages (maquettes MS01, MS02, MS05) : une conversation
 * par commande ou par devis, ou une question générale ; onglets Ouvertes et
 * Clôturées, recherche, fil avec le nom, le métier et la photo de chaque
 * agent, clôture proposée par l'agent et confirmée ici, réouverture pendant
 * 7 jours, avis en étoiles.
 *
 * Toujours limité à la fiche du compte connecté ; pas pour un compte
 * « Lecture · suivi » (DroitsEspace) ; sans montant pour qui ne les voit
 * pas (CompteClient::montantsVisibles, montants masqués dans les messages).
 */
#[Route('/espace/messages', name: 'app_espace_')]
final class EspaceMessagesController extends AbstractController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly EspaceClient $espace,
        private readonly Conversations $conversations,
        private readonly FilConversation $fil,
    ) {
    }

    #[Route('', name: 'messages', methods: ['GET'])]
    public function liste(Request $request, EntrepriseRepository $entreprises): Response
    {
        return $this->page($request, null, $entreprises);
    }

    #[Route('/{id}', name: 'message', requirements: ['id' => self::UUID], methods: ['GET'])]
    public function voir(string $id, Request $request, EntrepriseRepository $entreprises): Response
    {
        $c = $this->conversations->duClientParId($this->compte()->getClient(), $id) ?? throw $this->createNotFoundException();

        return $this->page($request, $c, $entreprises);
    }

    /** « + Nouvelle demande » : la commande ou le devis concerné, ou une question générale. */
    #[Route('/nouvelle', name: 'message_nouvelle', methods: ['POST'])]
    public function nouvelle(Request $request, #[Autowire(service: 'limiter.espace_message_compte')] RateLimiterFactoryInterface $limite): Response
    {
        $compte = $this->ecrivain($request);
        $client = $compte->getClient();
        [$commande, $devis] = $this->document($request->request->getString('document'));
        try {
            if ('' !== $request->request->getString('document') && null === $commande && null === $devis) {
                throw new \DomainException('Choisissez une de vos commandes ou un de vos devis.');
            }
            $this->limiter($limite, $compte);
            $c = $this->conversations->ouvrir($compte, $commande, $devis, $request->request->getString('objet'), $request->request->getString('sujet') ?: null, mb_substr($request->request->getString('texte'), 0, MessageClient::TEXTE_MAX + 200), new \DateTimeImmutable());
            $this->addFlash('succes', 'Message envoyé. L\'atelier vous répond ici ; vous êtes prévenu de sa réponse.');

            return $this->redirectToRoute('app_espace_message', ['id' => (string) $c->getPublicId(), '_fragment' => 'dernier']);
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_espace_messages', ['nouvelle' => 1, 'document' => $request->request->getString('document')]);
        }
    }

    #[Route('/{id}/ecrire', name: 'message_ecrire', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function ecrire(string $id, Request $request, #[Autowire(service: 'limiter.espace_message_compte')] RateLimiterFactoryInterface $limite): Response
    {
        $compte = $this->ecrivain($request);
        $c = $this->conversations->duClientParId($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        try {
            $this->limiter($limite, $compte);
            $this->conversations->ecrireClient($compte, $c, mb_substr($request->request->getString('texte'), 0, MessageClient::TEXTE_MAX + 200), new \DateTimeImmutable());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_message', ['id' => $id, '_fragment' => 'dernier']);
    }

    /** Clôture proposée par l'agent : « Oui, c'est résolu » ou « J'ai encore une question ». */
    #[Route('/{id}/cloture', name: 'message_cloture', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function cloture(string $id, Request $request): Response
    {
        $compte = $this->ecrivain($request);
        $c = $this->conversations->duClientParId($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        $resolu = 'oui' === $request->request->getString('reponse');
        try {
            $this->conversations->repondreCloture($compte, $c, $resolu, new \DateTimeImmutable());
            $this->addFlash('succes', $resolu ? 'Demande clôturée. Merci ! Vous pouvez la rouvrir pendant '.$this->conversations->regles()->reouvertureJours().' jours.' : 'D\'accord : écrivez votre question, l\'atelier vous répond ici.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_message', ['id' => $id, '_fragment' => $resolu ? 'fin' : 'ecrire']);
    }

    #[Route('/{id}/rouvrir', name: 'message_rouvrir', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function rouvrir(string $id, Request $request): Response
    {
        $compte = $this->ecrivain($request);
        $c = $this->conversations->duClientParId($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        try {
            $this->conversations->rouvrir($compte, $c, new \DateTimeImmutable());
            $this->addFlash('succes', 'Demande rouverte : écrivez votre message.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_message', ['id' => $id, '_fragment' => 'ecrire']);
    }

    #[Route('/{id}/avis', name: 'message_avis', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function avis(string $id, Request $request): Response
    {
        $compte = $this->ecrivain($request);
        $c = $this->conversations->duClientParId($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        try {
            $this->conversations->noter($compte, $c, Entree::entier($request->request, 'etoiles'), new \DateTimeImmutable());
            $this->addFlash('succes', 'Merci pour votre avis.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_message', ['id' => $id, '_fragment' => 'fin']);
    }

    private function page(Request $request, ?ConversationClient $active, EntrepriseRepository $entreprises): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $montants = $compte->montantsVisibles();
        $recherche = trim(mb_substr($request->query->getString('q'), 0, 60));
        $cloturees = 'cloturees' === $request->query->getString('onglet') || (null !== $active && $active->estCloturee() && '' === $request->query->getString('onglet'));
        $liste = $this->conversations->duClient($client, $cloturees, $recherche);
        $derniers = $this->conversations->derniers($active !== null && !\in_array($active, $liste, true) ? [...$liste, $active] : $liste);

        $nouvelle = $request->query->getBoolean('nouvelle') || (null === $active && [] === $liste && '' === $recherche && !$cloturees);
        // Sur ordinateur, la première conversation s'ouvre à côté de la liste (maquette MS01) ; au téléphone, la liste
        // d'abord (MS05) : « auto » ne la marque pas lue, le client ne l'a pas encore ouverte.
        $auto = null === $active && !$nouvelle && [] !== $liste;
        if ($auto) {
            $active = $liste[0];
        }
        $maintenant = new \DateTimeImmutable();
        $fil = [];
        $agents = [];
        if (null !== $active) {
            $messages = $this->conversations->messages($active);
            $fil = $this->fil->construire($active, $messages, [], $montants);
            foreach ($messages as $m) {
                if (($m->estDeLAtelier() || $m->estUnEvenement()) && null !== $m->getAgent()) {
                    $agents[$m->getAgent()->getId()] = $m->getAgent();
                }
            }
            if (!$auto) {
                $this->conversations->marquerLueParClient($active, $derniers[$active->getId()] ?? null, $maintenant);
            }
        }
        $telephone = null;
        foreach ($entreprises->dansLOrdre() as $e) {
            if ('' !== trim((string) $e->getTelephone())) {
                $telephone = UtilisateursController::numeroWhatsApp((string) $e->getTelephone());
                break;
            }
        }

        // État de chaque commande encore ouverte dans la liste (« En impression », « BAT à valider »).
        $cartes = [];
        foreach (\array_slice($liste, 0, 30) as $c) {
            if ($c->estOuverte() && null !== $c->getCommande()) {
                $cartes[$c->getId()] = $this->espace->carte($c->getCommande());
            }
        }

        return $this->render('espace/app/messages.html.twig', [
            'liste' => $liste,
            'cartes' => $cartes,
            'derniers' => $derniers,
            'compteurs' => $this->conversations->compter($client),
            'cloturees' => $cloturees,
            'recherche' => $recherche,
            'active' => $active,
            'auto' => $auto,
            'carte' => null !== $active?->getCommande() ? $this->espace->carte($active->getCommande()) : null,
            'fil' => $fil,
            'agents' => array_values($agents),
            'nouvelle' => $nouvelle,
            'document' => $request->query->getString('document'),
            // Pré-rempli depuis un autre écran (« Question sur la facture F-… »).
            'texte' => mb_substr($request->query->getString('texte'), 0, 200),
            'sujet' => \array_key_exists($request->query->getString('sujet'), ConversationClient::SUJETS) ? $request->query->getString('sujet') : '',
            'commandes' => \array_slice($this->espace->commandes($client, 30), 0, 30),
            'devis' => \array_slice($this->espace->devis($client), 0, 15),
            'montants' => $montants,
            'rouvrable' => null !== $active && $active->rouvrable($maintenant, $this->conversations->regles()->reouvertureJours()),
            'reouvertureJours' => $this->conversations->regles()->reouvertureJours(),
            'texteDelai' => $this->conversations->regles()->texteClient(),
            'whatsapp' => null !== $telephone ? 'https://wa.me/'.$telephone : null,
            'max' => MessageClient::TEXTE_MAX,
            'sujets' => ConversationClient::SUJETS,
        ]);
    }

    /**
     * « c:<uuid> » une commande, « d:<uuid> » un devis, du client seulement.
     *
     * @return array{0: ?Commandes, 1: ?Devis}
     */
    private function document(string $valeur): array
    {
        $client = $this->compte()->getClient();
        if (1 !== preg_match('/^([cd]):('.self::UUID.')$/', $valeur, $m)) {
            return [null, null];
        }
        if ('c' === $m[1]) {
            return [$this->espace->commande($client, $m[2]), null];
        }
        foreach ($this->espace->devis($client) as $d) {
            if ((string) $d->getPublicId() === $m[2]) {
                return [null, $d];
            }
        }

        return [null, null];
    }

    private function limiter(RateLimiterFactoryInterface $limite, CompteClient $compte): void
    {
        if (!$limite->create('compte-'.$compte->getId())->consume()->isAccepted()) {
            throw new \DomainException('Vous avez envoyé beaucoup de messages. Réessayez dans un moment, ou appelez-nous.');
        }
    }

    /** Envoi : jeton valable et compte qui peut écrire (jamais « Lecture · suivi »). */
    private function ecrivain(Request $request): CompteClient
    {
        if (!$this->isCsrfTokenValid('espace', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
        $compte = $this->compte();
        if (!$compte->peutEcrire()) {
            throw $this->createAccessDeniedException();
        }

        return $compte;
    }

    /** Le compte frais de la base ; « Lecture · suivi » n'a pas Messages. */
    private function compte(): CompteClient
    {
        $session = $this->getUser();
        $compte = $session instanceof CompteClient ? $this->comptes->find($session->getId()) : null;
        if (!$compte instanceof CompteClient || null === $compte->getClient() || $compte->estLectureSeule()) {
            throw $this->createAccessDeniedException();
        }

        return $compte;
    }
}
