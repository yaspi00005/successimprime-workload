<?php

namespace App\Controller;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\ReclamationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/notifications', name: 'app_notification_')]
final class NotificationController extends AbstractController
{
    /**
     * Nombre total de notifications non lues (notifications générales
     * + réclamations à encaisser), pour l'alerte navigateur en
     * arrière-plan (voir base.html.twig).
     */
    #[Route('/etat', name: 'etat', methods: ['GET'])]
    public function etat(NotificationRepository $notificationRepository, ReclamationRepository $reclamationRepository): JsonResponse
    {
        $utilisateur = $this->getUser();

        if (!$utilisateur instanceof User) {
            return $this->json(['total' => 0]);
        }

        $total = $notificationRepository->countNonLuesPourUtilisateur($utilisateur)
            + count($reclamationRepository->findNotificationsNonLuesPourAgent($utilisateur));

        return $this->json(['total' => $total]);
    }

    /**
     * Historique complet des notifications de l'utilisateur (lues et
     * non lues), pour le lien "Afficher toutes les notifications".
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(NotificationRepository $notificationRepository): Response
    {
        $utilisateur = $this->getUser();

        if (!$utilisateur instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('notification/index.html.twig', [
            'notifications' => $notificationRepository->findToutesPourUtilisateur($utilisateur),
        ]);
    }

    /**
     * Marque la notification comme lue puis redirige vers sa cible
     * (ou vers l'accueil si elle n'a pas de route associée).
     */
    #[Route('/{id}/ouvrir', name: 'ouvrir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ouvrir(Notification $notification, EntityManagerInterface $entityManager): Response
    {
        $utilisateur = $this->getUser();

        if (!$utilisateur instanceof User || $notification->getDestinataire() !== $utilisateur) {
            throw $this->createAccessDeniedException('Cette notification ne vous appartient pas.');
        }

        if (!$notification->isLue()) {
            $notification->marquerLue();
            $entityManager->flush();
        }

        if ($notification->getRoute() !== null) {
            return $this->redirectToRoute($notification->getRoute(), $notification->getRouteParametres());
        }

        return $this->redirectToRoute('app_home');
    }
}
