<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\ReclamationRepository;
use App\Security\LivreurSeul;
use App\Service\NotificationsEquipe\NotificationsEquipe;
use App\Service\NotificationsEquipe\TypesNotification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;

/**
 * Cloche, centre de notifications (maquette NT03) et interrogation des
 * écrans toutes les 30 s (bannière critique, petites fenêtres, son).
 * Chacun ne voit que ses propres notifications ; les montants suivent ses
 * droits (Notification::titrePour).
 */
#[Route('/notifications', name: 'app_notification_')]
final class NotificationController extends AbstractController
{
    public const CSRF = 'notifications';

    public const FILTRES = [
        'toutes' => 'Toutes',
        'non_lues' => 'Non lues',
        'urgentes' => 'Urgentes',
        'a_faire' => 'À faire',
        'mentions' => 'Mentions',
        'reportees' => 'Reportées',
        'historique' => 'Historique',
    ];

    public function __construct(private readonly NotificationsEquipe $equipe)
    {
    }

    /**
     * État pour l'écran (interrogé toutes les 30 s par notifications-equipe.js) :
     * nombre non lu, compteurs, bannière critique, petites fenêtres à
     * montrer, son. « total » compte aussi les réclamations à encaisser.
     */
    #[Route('/etat', name: 'etat', methods: ['GET'])]
    public function etat(NotificationRepository $notificationRepository, ReclamationRepository $reclamationRepository): JsonResponse
    {
        $utilisateur = $this->getUser();
        if (!$utilisateur instanceof User) {
            return $this->json(['total' => 0]);
        }
        $maintenant = new \DateTimeImmutable();
        $this->equipe->actualiser($maintenant);
        $etat = $this->equipe->etat($utilisateur, $maintenant);
        $roles = $utilisateur->getRoles();
        $reclamations = LivreurSeul::est($roles) ? 0 : \count($reclamationRepository->findNotificationsNonLuesPourAgent($utilisateur));

        return $this->json([
            'total' => $etat['total'] + $reclamations,
            'compteurs' => $etat['compteurs'],
            'critiques' => array_map(fn (Notification $n): array => $this->resume($n, $roles), $etat['critiques']),
            'fenetres' => array_map(fn (Notification $n): array => $this->resume($n, $roles), $etat['fenetres']),
            'son' => $etat['son'],
            'sonner' => $etat['sonner'],
        ]);
    }

    /** Contenu de la cloche, rechargé à l'ouverture (fragment HTML). */
    #[Route('/cloche', name: 'cloche', methods: ['GET'])]
    public function cloche(): Response
    {
        if (!$this->getUser() instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('notification/_cloche_liste.html.twig');
    }

    /** Centre de notifications (NT03) : filtres, domaines, actions, report, historique. */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, NotificationRepository $depot): Response
    {
        $utilisateur = $this->getUser();
        if (!$utilisateur instanceof User) {
            throw $this->createAccessDeniedException();
        }
        $maintenant = new \DateTimeImmutable();
        $this->equipe->actualiser($maintenant);
        $listes = $this->equipe->pour($utilisateur, $maintenant);
        $filtre = (string) $request->query->get('filtre', 'toutes');
        $filtre = isset(self::FILTRES[$filtre]) ? $filtre : 'toutes';
        $domaine = (string) $request->query->get('domaine', '');
        $domaine = isset(TypesNotification::DOMAINES[$domaine]) ? $domaine : '';

        $liste = match ($filtre) {
            'non_lues' => array_values(array_filter($listes['toutes'], static fn (Notification $n): bool => !$n->isLue())),
            'urgentes' => [...$listes['critiques'], ...$listes['urgentes']],
            'a_faire' => $listes['a_faire'],
            'mentions' => $listes['mentions'],
            'reportees' => $listes['reportees'],
            'historique' => $depot->historique($utilisateur),
            default => $listes['toutes'],
        };
        if (LivreurSeul::est($utilisateur->getRoles())) {
            $liste = LivreurSeul::notificationsVisibles($liste);
        }
        $parDomaine = [];
        foreach ($listes['toutes'] as $n) {
            $d = null !== $n->getType() ? TypesNotification::type($n->getType())['domaine'] : 'systeme';
            $parDomaine[$d] = ($parDomaine[$d] ?? 0) + 1;
        }
        if ('' !== $domaine) {
            $liste = array_values(array_filter($liste, static fn (Notification $n): bool => (null !== $n->getType() ? TypesNotification::type($n->getType())['domaine'] : 'systeme') === $domaine));
        }
        $debutJour = $maintenant->setTime(0, 0);
        $durees = $depot->durees($utilisateur, $debutJour);

        return $this->render('notification/index.html.twig', [
            'liste' => $liste,
            'listes' => $listes,
            'filtre' => $filtre,
            'domaine' => $domaine,
            'parDomaine' => $parDomaine,
            'filtres' => self::FILTRES,
            'domaines' => TypesNotification::DOMAINES,
            'resume' => [
                'urgentes' => \count($listes['critiques']) + \count($listes['urgentes']),
                'traitees' => $depot->traiteesDepuis($utilisateur, $debutJour),
                'moyenne' => [] === $durees ? null : (int) round(array_sum($durees) / \count($durees)),
            ],
            'son' => !$this->equipe->reglages()->sonCoupe($utilisateur),
            'maintenant' => $maintenant,
        ]);
    }

    /** « Plus tard » : la notification revient dans 1 h, 3 h, ou demain à 9:00. */
    #[Route('/{id}/reporter', name: 'reporter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reporter(Notification $notification, Request $request, EntityManagerInterface $em): Response
    {
        $this->controler($notification, $request);
        $duree = (string) $request->request->get('duree', 'demain');
        $fin = $this->equipe->reporter($notification, \in_array($duree, ['1h', '3h', 'demain'], true) ? $duree : 'demain', new \DateTimeImmutable());
        $em->flush();
        if ($request->isXmlHttpRequest()) {
            return $this->json(['ok' => true, 'jusqua' => $fin->format(\DATE_ATOM)]);
        }
        $this->addFlash('success', 'Reportée : elle revient '.self::quand($fin).'.');

        return $this->retour($request);
    }

    /** Marquer lue (une info disparaît ; une action reste jusqu'à ce qu'elle soit faite). */
    #[Route('/{id}/lue', name: 'lue', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function lue(Notification $notification, Request $request, EntityManagerInterface $em): Response
    {
        $this->controler($notification, $request);
        $notification->marquerLue();
        $em->flush();

        return $request->isXmlHttpRequest() ? $this->json(['ok' => true]) : $this->retour($request);
    }

    #[Route('/tout-lu', name: 'tout_lu', methods: ['POST'])]
    public function toutLu(Request $request, EntityManagerInterface $em): Response
    {
        $utilisateur = $this->getUser();
        if (!$utilisateur instanceof User || !$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Rechargez-la.');
        }
        $n = $this->equipe->toutMarquerLu($utilisateur);
        $em->flush();
        if ($request->isXmlHttpRequest()) {
            return $this->json(['ok' => true, 'marquees' => $n]);
        }
        $this->addFlash('success', 0 === $n ? 'Tout était déjà lu.' : 'Tout est marqué lu. Les notifications avec une action restent jusqu\'à ce qu\'elle soit faite.');

        return $this->retour($request);
    }

    /** Son coupé ou remis pour la personne (tous ses appareils). */
    #[Route('/son', name: 'son', methods: ['POST'])]
    public function son(Request $request, EntityManagerInterface $em): Response
    {
        $utilisateur = $this->getUser();
        if (!$utilisateur instanceof User || !$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Rechargez-la.');
        }
        $actif = $request->request->getBoolean('actif');
        $this->equipe->reglages()->couperSon($utilisateur, !$actif, new \DateTimeImmutable());
        $em->flush();
        if ($request->isXmlHttpRequest()) {
            return $this->json(['ok' => true, 'son' => $actif]);
        }
        $this->addFlash('success', $actif ? 'Son des notifications remis.' : 'Son des notifications coupé.');

        return $this->retour($request);
    }

    /**
     * Marque la notification comme lue puis redirige vers sa cible
     * (ou vers l'accueil si elle n'a pas de route associée).
     */
    #[Route('/{id}/ouvrir', name: 'ouvrir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ouvrir(Notification $notification, EntityManagerInterface $entityManager): Response
    {
        $utilisateur = $this->getUser();

        // Seule la personne à qui elle est adressée l'ouvre (lien de la cloche ou du téléphone).
        if (!$utilisateur instanceof User || $notification->getDestinataire()?->getId() !== $utilisateur->getId()) {
            throw $this->createAccessDeniedException('Cette notification ne vous appartient pas.');
        }

        if (!$notification->isLue() || (\in_array($notification->getType(), TypesNotification::RESOLUS_A_L_OUVERTURE, true) && !$notification->estResolue())) {
            $notification->marquerLue();
            if (\in_array($notification->getType(), TypesNotification::RESOLUS_A_L_OUVERTURE, true)) {
                $notification->resoudre(Notification::RESOLUE_LUE, new \DateTimeImmutable());
            }
            $entityManager->flush();
        }

        // Le livreur seul n'ouvre que sa tournée et ses livraisons.
        if (LivreurSeul::est($utilisateur->getRoles()) && !LivreurSeul::routePermise($notification->getRoute())) {
            return $this->redirectToRoute('app_livraisons_tournee');
        }

        if ($notification->getRoute() !== null) {
            try {
                return $this->redirectToRoute($notification->getRoute(), $notification->getRouteParametres());
            } catch (RoutingException) {
                // Page renommée ou retirée depuis la notification : la liste plutôt qu'une erreur.
                return $this->redirectToRoute('app_notification_index');
            }
        }

        return $this->redirectToRoute('app_notification_index');
    }

    /**
     * @param list<string> $roles
     *
     * @return array<string, mixed>
     */
    private function resume(Notification $n, array $roles): array
    {
        $type = null !== $n->getType() ? TypesNotification::type($n->getType()) : null;

        return [
            'id' => $n->getId(),
            'niveau' => $n->getNiveau(),
            'titre' => $n->titrePour($roles),
            'detail' => $n->getDetail(),
            'action' => null !== $n->getRoute() ? ($n->getActionLibelle() ?? 'Ouvrir') : null,
            'url' => $this->generateUrl('app_notification_ouvrir', ['id' => $n->getId()]),
            'icone' => $type['icone'] ?? 'fas fa-bell',
            'compteur' => $n->getCompteur(),
            'depuis' => $n->getDateCreation()?->format(\DATE_ATOM),
        ];
    }

    private function controler(Notification $n, Request $request): void
    {
        $utilisateur = $this->getUser();
        if (!$utilisateur instanceof User || $n->getDestinataire()?->getId() !== $utilisateur->getId()) {
            throw $this->createAccessDeniedException('Cette notification ne vous appartient pas.');
        }
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Rechargez-la.');
        }
    }

    private function retour(Request $request): Response
    {
        $filtre = (string) $request->request->get('filtre', '');

        return $this->redirectToRoute('app_notification_index', isset(self::FILTRES[$filtre]) ? ['filtre' => $filtre] : []);
    }

    public static function quand(\DateTimeImmutable $le, ?\DateTimeImmutable $maintenant = null): string
    {
        $maintenant ??= new \DateTimeImmutable();
        if ($le->format('Y-m-d') === $maintenant->format('Y-m-d')) {
            return 'aujourd\'hui à '.$le->format('H:i');
        }
        if ($le->format('Y-m-d') === $maintenant->modify('+1 day')->format('Y-m-d')) {
            return 'demain à '.$le->format('H:i');
        }

        return 'le '.$le->format('d/m à H:i');
    }
}
