<?php

namespace App\Controller;

use App\Repository\ClientsRepository;
use App\Repository\CommandesRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran d'accueil public (télé de la salle d'attente / accueil).
 *
 * Aucune authentification requise : voir security.yaml, section PUBLIC.
 */
final class TvDashboardController extends AbstractController
{
    #[Route('/ecran-accueil', name: 'app_tv_dashboard')]
    public function index(
        CommandesRepository $commandesRepository,
        ClientsRepository $clientsRepository
    ): Response {
        $maintenant = new \DateTimeImmutable('now');

        $debutSemaine = $maintenant->modify('monday this week')->setTime(0, 0);
        $debutMois = $maintenant->modify('first day of this month')->setTime(0, 0);
        $debutAnnee = $maintenant->modify('first day of january this year')->setTime(0, 0);

        return $this->render('tv_dashboard/index.html.twig', [
            'totalClients' => $clientsRepository->compterClients(),
            'commandesSemaine' => $commandesRepository->compterCommandes($debutSemaine, $maintenant),
            'commandesMois' => $commandesRepository->compterCommandes($debutMois, $maintenant),
            'commandesAnnee' => $commandesRepository->compterCommandes($debutAnnee, $maintenant),
            'medias' => $this->listerMedias(),
        ]);
    }

    /**
     * Liste les images/vidéos déposées dans public/uploads/tv/
     * pour le diaporama de fond. Aucune base de données : il suffit
     * d'ajouter/retirer des fichiers dans ce dossier.
     *
     * @return array<int, array{fichier: string, type: 'image'|'video'}>
     */
    private function listerMedias(): array
    {
        $dossier = $this->getParameter('kernel.project_dir') . '/public/uploads/tv';

        if (!is_dir($dossier)) {
            return [];
        }

        $extensionsImage = ['jpg', 'jpeg', 'png', 'webp'];
        $extensionsVideo = ['mp4', 'webm'];
        $medias = [];

        foreach (scandir($dossier) ?: [] as $fichier) {
            if ($fichier === '.' || $fichier === '..' || $fichier === '.gitkeep') {
                continue;
            }

            $extension = strtolower(pathinfo($fichier, PATHINFO_EXTENSION));

            if (\in_array($extension, $extensionsImage, true)) {
                $medias[] = ['fichier' => $fichier, 'type' => 'image'];
            } elseif (\in_array($extension, $extensionsVideo, true)) {
                $medias[] = ['fichier' => $fichier, 'type' => 'video'];
            }
        }

        usort($medias, static fn (array $a, array $b) => $a['fichier'] <=> $b['fichier']);

        return $medias;
    }
}
