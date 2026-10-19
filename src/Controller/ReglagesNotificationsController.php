<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\NotificationsEquipe\CanauxExternes;
use App\Service\NotificationsEquipe\ReglagesNotifications;
use App\Service\NotificationsEquipe\TypesNotification;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages › Notifications (maquette NT04) : qui reçoit quoi et par quel
 * moyen, heures calmes, remontée. Direction seulement ; les changements se
 * font sur l'ERP local (en ligne : lecture seule, comme les tuiles de
 * Paramètres qui ne descendent pas).
 */
#[Route('/reglages/notifications', name: 'app_reglages_notifications')]
#[IsGranted('ROLE_ADMIN')]
final class ReglagesNotificationsController extends AbstractController
{
    private const CSRF = 'reglages-notifications';

    public function __construct(
        private readonly ReglagesNotifications $reglages,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request, CanauxExternes $canaux, UserRepository $utilisateurs): Response
    {
        $noms = $this->reglages->nomsDesRoles();
        $role = (string) $request->query->get('role', TypesNotification::DIRECTION);

        return $this->render('notification/reglages.html.twig', [
            'valeurs' => $this->reglages->valeurs(),
            'roles' => $noms,
            'role' => isset($noms[$role]) ? $role : TypesNotification::DIRECTION,
            'types' => TypesNotification::TYPES,
            'canaux' => TypesNotification::CANAUX,
            'etatCanaux' => $canaux->etat(),
            'personnes' => array_values(array_filter($utilisateurs->findBy(['actif' => true], ['username' => 'ASC']), static fn (User $u): bool => !\in_array('ROLE_ESPACE_CLIENT', $u->getRoles(), true))),
            'enLigne' => $this->enLigne,
            'jeton' => self::CSRF,
            'jours' => ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'],
        ]);
    }

    #[Route('', name: '_enregistrer', methods: ['POST'])]
    public function enregistrer(Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Rechargez-la.');
        }
        if ($this->enLigne) {
            $this->addFlash('warning', 'Les réglages des notifications se font sur l\'ERP de l\'imprimerie.');

            return $this->redirectToRoute('app_reglages_notifications');
        }
        $utilisateur = $this->getUser();
        $this->reglages->enregistrer($request->request->all(), $utilisateur instanceof User ? $utilisateur : null, new \DateTimeImmutable());
        $em->flush();
        $this->addFlash('success', 'Réglages des notifications enregistrés.');

        return $this->redirectToRoute('app_reglages_notifications', ['role' => (string) $request->request->get('role_affiche')]);
    }
}
