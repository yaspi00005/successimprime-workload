<?php

namespace App\Controller;

use App\Entity\Etiquette;
use App\Entity\LotEtiquette;
use App\Entity\User;
use App\Repository\EtiquetteRepository;
use App\Repository\LotEtiquetteRepository;
use App\Service\GenerateurEtiquetteService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/lot/etiquette')]
final class LotEtiquetteController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/public')]
        private readonly string $publicDirectory,
    ) {
    }

    /**
     * Affiche la liste des lots et le formulaire de génération.
     */
    #[Route(
        '',
        name: 'app_lot_etiquette_index',
        methods: ['GET']
    )]
    public function index(
        LotEtiquetteRepository $lotRepository,
    ): Response {
        $lots = $lotRepository->findBy(
            [],
            ['id' => 'DESC']
        );

        return $this->render(
            'lot_etiquette/index.html.twig',
            [
                'lots' => $lots,
                'quantite_maximale' => 1000,
            ]
        );
    }

    /**
     * Génère un nouveau lot de QR codes.
     */
    #[Route(
        '/generer',
        name: 'app_lot_etiquette_generer',
        methods: ['POST']
    )]
    public function generer(
        Request $request,
        GenerateurEtiquetteService $generateur,
    ): RedirectResponse {
        if (!$this->isCsrfTokenValid(
            'generer_lot_etiquette',
            (string) $request->request->get('_token')
        )) {
            $this->addFlash(
                'error',
                'Le jeton de sécurité est invalide. Veuillez réessayer.'
            );

            return $this->redirectToRoute(
                'app_lot_etiquette_index'
            );
        }

        $utilisateur = $this->getUser();

        if (!$utilisateur instanceof User) {
            $this->addFlash(
                'error',
                'Vous devez être connecté pour générer des étiquettes.'
            );

            return $this->redirectToRoute('app_login');
        }

        $quantite = $request->request->getInt('quantite');

        $prefixe = (string) $request->request->get(
            'prefixe',
            'ETQ'
        );

        try {
            $lot = $generateur->genererLot(
                $quantite,
                $utilisateur,
                $prefixe
            );

            $this->addFlash(
                'success',
                sprintf(
                    'Le lot %s contenant %d étiquette(s) a été généré.',
                    $lot->getNumero(),
                    $lot->getQuantite()
                )
            );

            return $this->redirectToRoute(
                'app_lot_etiquette_voir',
                ['id' => $lot->getId()]
            );
        } catch (\InvalidArgumentException $exception) {
            $this->addFlash(
                'error',
                $exception->getMessage()
            );
        } catch (\Throwable $exception) {
            $this->addFlash(
                'error',
                sprintf(
                    'La génération du lot a échoué : %s',
                    $exception->getMessage()
                )
            );
        }

        return $this->redirectToRoute(
            'app_lot_etiquette_index'
        );
    }

    /**
     * Affiche les informations et les étiquettes d’un lot.
     */
    #[Route(
        '/{id}',
        name: 'app_lot_etiquette_voir',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function voir(
        LotEtiquette $lot,
    ): Response {
        return $this->render(
            'lot_etiquette/voir.html.twig',
            [
                'lot' => $lot,
                'etiquettes' => $lot->getEtiquettes(),
            ]
        );
    }

    /**
     * Télécharge le fichier ZIP d’un lot prêt.
     */
    #[Route(
        '/{id}/telecharger',
        name: 'app_lot_etiquette_telecharger',
        requirements: ['id' => '\d+'],
        methods: ['GET']
    )]
    public function telecharger(
        LotEtiquette $lot,
    ): BinaryFileResponse|RedirectResponse {
        if ($lot->getStatut() !== LotEtiquette::STATUT_PRET) {
            $this->addFlash(
                'error',
                'Ce lot n’est pas encore prêt au téléchargement.'
            );

            return $this->redirectToRoute(
                'app_lot_etiquette_voir',
                ['id' => $lot->getId()]
            );
        }

        $fichierZip = $lot->getFichierZip();

        if ($fichierZip === null || trim($fichierZip) === '') {
            $this->addFlash(
                'error',
                'Aucun fichier ZIP n’est associé à ce lot.'
            );

            return $this->redirectToRoute(
                'app_lot_etiquette_voir',
                ['id' => $lot->getId()]
            );
        }

        $cheminPublic = realpath($this->publicDirectory);

        $cheminFichier = realpath(
            rtrim($this->publicDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . ltrim(
                str_replace(
                    ['/', '\\'],
                    DIRECTORY_SEPARATOR,
                    $fichierZip
                ),
                DIRECTORY_SEPARATOR
            )
        );

        /*
         * On vérifie que le fichier existe et se trouve réellement
         * dans le dossier public du projet.
         */
        if (
            $cheminPublic === false
            || $cheminFichier === false
            || !is_file($cheminFichier)
            || !str_starts_with(
                $cheminFichier,
                $cheminPublic . DIRECTORY_SEPARATOR
            )
        ) {
            $this->addFlash(
                'error',
                'Le fichier ZIP du lot est introuvable.'
            );

            return $this->redirectToRoute(
                'app_lot_etiquette_voir',
                ['id' => $lot->getId()]
            );
        }

        $nomTelechargement = sprintf(
            '%s.zip',
            $lot->getNumero()
        );

        return $this->file(
            $cheminFichier,
            $nomTelechargement,
            ResponseHeaderBag::DISPOSITION_ATTACHMENT
        );
    }

    /**
     * Route publique contenue dans chaque QR code.
     *
     * Le token est aléatoire : aucune donnée interne n’est exposée
     * directement dans l’adresse du QR code.
     */
    #[Route(
        '/suivi/{token}',
        name: 'app_suivi_etiquette_public',
        requirements: ['token' => '[a-fA-F0-9]{64}'],
        methods: ['GET']
    )]
    public function suiviPublic(
        string $token,
        EtiquetteRepository $etiquetteRepository,
    ): Response {
        $etiquette = $etiquetteRepository->findOneBy([
            'token' => $token,
        ]);

        if (!$etiquette instanceof Etiquette) {
            throw $this->createNotFoundException(
                'Cette étiquette est introuvable ou invalide.'
            );
        }

        return $this->render(
            'lot_etiquette/suivi_public.html.twig',
            [
                'etiquette' => $etiquette,
            ]
        );
    }
}