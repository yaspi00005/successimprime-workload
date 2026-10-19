<?php

namespace App\Controller;

use App\Entity\CommandeDetailFichier;
use App\Entity\User;
use App\Repository\CommandeDetailFichierRepository;
use App\Security\FichierEnvoye;
use App\Service\Prepresse\DossierPartage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/commande-fichiers')]
final class FichierUploadController extends AbstractController
{
    private const TAILLE_MAX = 750 * 1024 * 1024;
    private const NOMBRE_MORCEAUX_MAX = 10000;

    private string $dossierTemporaire;
    private string $dossierFinal;
    private string $dossierPartage;

    public function __construct(
        KernelInterface $kernel
    ) {
        $this->dossierTemporaire =
            $kernel->getProjectDir().'/var/uploads/commande_tmp';

        $this->dossierFinal =
            $kernel->getProjectDir().'/var/uploads/commandes';

        $this->dossierPartage =
            $kernel->getProjectDir().'/var/uploads/dossier_partage';

        foreach ([
            $this->dossierTemporaire,
            $this->dossierFinal,
            $this->dossierPartage,
        ] as $dossier) {
            if (
                !is_dir($dossier)
                && !mkdir($dossier, 0775, true)
                && !is_dir($dossier)
            ) {
                throw new \RuntimeException(
                    'Impossible de créer le dossier : '.$dossier
                );
            }
        }
    }

    #[Route('/initialiser', name: 'app_fichier_upload_initialiser', methods: ['POST'])]
    public function initialiser(
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        if (!$this->isCsrfTokenValid(
            'upload-commande',
            $request->headers->get('X-CSRF-TOKEN')
        )) {
            return $this->json([
                'message' => 'Jeton CSRF invalide.',
            ], 403);
        }

        try {
            $donnees = $request->toArray();
        } catch (\Throwable) {
            return $this->json([
                'message' => 'Le corps JSON de la requête est invalide.',
            ], 400);
        }

        $nomOriginal = trim((string) ($donnees['nom'] ?? ''));
        $typeMime = trim((string) ($donnees['typeMime'] ?? 'application/octet-stream'));
        $taille = (int) ($donnees['taille'] ?? 0);
        $nombreMorceaux = (int) ($donnees['nombreMorceaux'] ?? 0);

        if (
            $nomOriginal === ''
            || $taille <= 0
            || $nombreMorceaux <= 0
            || $taille > self::TAILLE_MAX
            || $nombreMorceaux > self::NOMBRE_MORCEAUX_MAX
        ) {
            return $this->json([
                'message' => 'Informations du fichier invalides.',
            ], 422);
        }

        $jeton = bin2hex(random_bytes(32));

        $extension = strtolower(
            pathinfo($nomOriginal, PATHINFO_EXTENSION)
        );

        $nomStockage = $jeton;

        if ($extension !== '') {
            $nomStockage .= '.'.preg_replace(
                '/[^a-z0-9]/i',
                '',
                $extension
            );
        }

        $dossierJeton = $this->dossierTemporaire.'/'.$jeton;

        if (!mkdir($dossierJeton, 0775, true) && !is_dir($dossierJeton)) {
            return $this->json([
                'message' => 'Impossible de créer le dossier temporaire.',
            ], 500);
        }

        $fichier = (new CommandeDetailFichier())
            ->setJetonUpload($jeton)
            ->setNomOriginal(basename($nomOriginal))
            ->setNomStockage($nomStockage)
            ->setTypeMime($typeMime ?: 'application/octet-stream')
            ->setTaille($taille)
            ->setNombreMorceaux($nombreMorceaux)
            ->setMorceauxRecus(0)
            ->setStatut('EN_COURS');

        $entityManager->persist($fichier);
        $entityManager->flush();

        return $this->json([
            'jeton' => $jeton,
            'morceauxRecus' => 0,
            'nombreMorceaux' => $nombreMorceaux,
            'statut' => 'EN_COURS',
        ], 201);
    }

    #[Route(
        '/{jeton}/morceaux/{index}',
        name: 'app_fichier_upload_morceau',
        requirements: [
            'jeton' => '[a-f0-9]{64}',
            'index' => '\d+',
        ],
        methods: ['POST']
    )]
    public function envoyerMorceau(
        string $jeton,
        int $index,
        Request $request,
        CommandeDetailFichierRepository $repository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        if (!$this->isCsrfTokenValid(
            'upload-commande',
            $request->headers->get('X-CSRF-TOKEN')
        )) {
            return $this->json([
                'message' => 'Jeton CSRF invalide.',
            ], 403);
        }

        $fichier = $repository->findOneBy([
            'jetonUpload' => $jeton,
        ]);

        if (!$fichier) {
            return $this->json([
                'message' => 'Session d’upload introuvable.',
            ], 404);
        }

        if ($fichier->getStatut() === 'TERMINE') {
            return $this->json([
                'jeton' => $jeton,
                'statut' => 'TERMINE',
                'progression' => 100,
            ]);
        }

        $nombreMorceaux = $fichier->getNombreMorceaux();

        if (
            $nombreMorceaux === null
            || $index < 0
            || $index >= $nombreMorceaux
        ) {
            return $this->json([
                'message' => 'Index du morceau invalide.',
            ], 422);
        }

        /** @var UploadedFile|null $morceau */
        $morceau = $request->files->get('morceau');

        if (!$morceau || !$morceau->isValid()) {
            return $this->json([
                'message' => 'Morceau absent ou invalide.',
            ], 422);
        }

        $dossierJeton = $this->dossierTemporaire.'/'.$jeton;

        if (
            !is_dir($dossierJeton)
            && !mkdir($dossierJeton, 0775, true)
            && !is_dir($dossierJeton)
        ) {
            return $this->json([
                'message' => 'Impossible de créer le dossier temporaire.',
            ], 500);
        }

        $cheminMorceau = $dossierJeton.'/'.sprintf(
            '%08d.part',
            $index
        );

        /*
         * Si le morceau existe déjà, on ne le compte pas deux fois.
         * Cela permet de reprendre un transfert interrompu.
         */
        if (!is_file($cheminMorceau)) {
            $morceau->move(
                $dossierJeton,
                basename($cheminMorceau)
            );
        }

        $morceauxRecus = count(
            glob($dossierJeton.'/*.part') ?: []
        );

        $fichier->setMorceauxRecus($morceauxRecus);

        if ($morceauxRecus === $nombreMorceaux) {
            $this->assemblerFichier($fichier);
        }

        $entityManager->flush();

        $progression = (int) floor(
            ($fichier->getMorceauxRecus() / $nombreMorceaux) * 100
        );

        return $this->json([
            'jeton' => $jeton,
            'morceauxRecus' => $fichier->getMorceauxRecus(),
            'nombreMorceaux' => $nombreMorceaux,
            'progression' => min(100, $progression),
            'statut' => $fichier->getStatut(),
        ]);
    }

    #[Route(
        '/{jeton}/statut',
        name: 'app_fichier_upload_statut',
        requirements: [
            'jeton' => '[a-f0-9]{64}',
        ],
        methods: ['GET']
    )]
    public function statut(
        string $jeton,
        CommandeDetailFichierRepository $repository
    ): JsonResponse {
        $fichier = $repository->findOneBy([
            'jetonUpload' => $jeton,
        ]);

        if (!$fichier) {
            return $this->json([
                'message' => 'Fichier introuvable.',
            ], 404);
        }

        $nombreMorceaux = $fichier->getNombreMorceaux() ?? 0;

        $progression = $nombreMorceaux > 0
            ? (int) floor(
                ($fichier->getMorceauxRecus() / $nombreMorceaux) * 100
            )
            : 0;

        return $this->json([
            'jeton' => $fichier->getJetonUpload(),
            'nom' => $fichier->getNomOriginal(),
            'taille' => $fichier->getTaille(),
            'morceauxRecus' => $fichier->getMorceauxRecus(),
            'nombreMorceaux' => $nombreMorceaux,
            'progression' => min(100, $progression),
            'statut' => $fichier->getStatut(),
        ]);
    }

    #[Route(
        '/{jeton}/supprimer',
        name: 'app_fichier_upload_supprimer',
        requirements: [
            'jeton' => '[a-f0-9]{64}',
        ],
        methods: ['POST']
    )]
    public function supprimer(
        string $jeton,
        Request $request,
        CommandeDetailFichierRepository $repository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        if (!$this->isCsrfTokenValid(
            'upload-commande',
            $request->headers->get('X-CSRF-TOKEN')
        )) {
            return $this->json([
                'message' => 'Jeton CSRF invalide.',
            ], 403);
        }

        $fichier = $repository->findOneBy([
            'jetonUpload' => $jeton,
        ]);

        if (!$fichier) {
            return $this->json([
                'message' => 'Fichier introuvable.',
            ], 404);
        }

        /*
         * Un fichier déjà rattaché à un détail de commande
         * enregistré ne peut plus être supprimé par ce biais : il
         * ne s'agit alors plus d'un envoi en attente, mais d'une
         * pièce jointe d'une commande existante.
         */
        if ($fichier->getCommandeDetail() !== null) {
            return $this->json([
                'message' => 'Ce fichier est déjà rattaché à une commande enregistrée.',
            ], 409);
        }

        if ($fichier->getStatut() === 'TERMINE' && $fichier->getNomStockage()) {
            $chemin = $this->dossierFinal
                .'/'.basename($fichier->getNomStockage());

            if (is_file($chemin)) {
                @unlink($chemin);
            }
        }

        $dossierJeton = $this->dossierTemporaire.'/'.$jeton;

        if (is_dir($dossierJeton)) {
            foreach (glob($dossierJeton.'/*.part') ?: [] as $morceau) {
                @unlink($morceau);
            }

            @rmdir($dossierJeton);
        }

        $entityManager->remove($fichier);
        $entityManager->flush();

        return $this->json([
            'success' => true,
        ]);
    }

    #[Route(
        '/{id}/visualiser',
        name: 'app_fichier_visualiser',
        requirements: ['id' => '\\d+'],
        methods: ['GET']
    )]
    public function visualiser(
        CommandeDetailFichier $fichier
    ): BinaryFileResponse {
        if ($fichier->getStatut() !== 'TERMINE' || !$fichier->getNomStockage()) {
            throw $this->createNotFoundException('Ce fichier est indisponible.');
        }

        $dossierFinal = realpath($this->dossierFinal);
        $chemin = $dossierFinal !== false
            ? realpath($dossierFinal.DIRECTORY_SEPARATOR.basename($fichier->getNomStockage()))
            : false;

        if (
            $dossierFinal === false
            || $chemin === false
            || !str_starts_with($chemin, $dossierFinal.DIRECTORY_SEPARATOR)
            || !is_file($chemin)
        ) {
            throw $this->createNotFoundException(
                'Le fichier physique est introuvable.'
            );
        }

        // Type lu dans le contenu, pas celui annoncé à l'envoi : une page
        // HTML déguisée se télécharge sans s'ouvrir (FichierEnvoye).
        $response = FichierEnvoye::reponse($chemin, $fichier->getNomOriginal());
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * Le dossier partagé des fichiers lourds (docs/fichiers-lourds.md,
     * docs/prepresse.md) : les fichiers nommés « CMD-numéro_format » ou
     * « 123_nom » se rattachent tout seuls chaque minute
     * (app:dossier-partage:importer) ; les autres restent ici et dans
     * « Fichiers à classer ». Lien depuis Prépresse et BAT.
     */
    #[Route(
        '/dossier-partage',
        name: 'app_fichier_dossier_partage',
        methods: ['GET']
    )]
    public function dossierPartage(DossierPartage $partage): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PREPRESSE');

        $raisons = array_column($partage->nonReconnus(), 'raison', 'nom');
        $fichiers = array_map(static fn (array $f): array => [
            'nom' => $f['nom'],
            'taille' => $f['taille'],
            'modifieLe' => $f['modifieLe'],
            'pret' => $f['pret'],
            'raison' => $raisons[$f['nom']] ?? null,
        ], $partage->fichiers());

        return $this->render('commande_fichiers/dossier_partage.html.twig', [
            'fichiers' => $fichiers,
            'cheminDossier' => $partage->dossier(),
        ]);
    }

    /** « Importer maintenant » : le même import que la tâche de chaque minute, noté au nom de la personne. */
    #[Route(
        '/dossier-partage/importer',
        name: 'app_fichier_dossier_partage_importer',
        methods: ['POST']
    )]
    public function importerDossierPartage(Request $request, DossierPartage $partage): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PREPRESSE');

        if (!$this->isCsrfTokenValid(
            'dossier-partage-importer',
            (string) $request->request->get('_token')
        )) {
            $this->addFlash('error', 'La page a expiré. Rechargez-la et recommencez.');

            return $this->redirectToRoute('app_fichier_dossier_partage');
        }

        $utilisateur = $this->getUser();
        $bilan = $partage->importer(new \DateTimeImmutable(), $utilisateur instanceof User ? $utilisateur : null);

        if ($bilan['importes'] !== []) {
            $this->addFlash('success', count($bilan['importes']).' fichier(s) rattaché(s) : '.implode(', ', $bilan['importes']));
        }
        if ($bilan['enCours'] > 0) {
            $this->addFlash('warning', $bilan['enCours'].' fichier(s) encore en cours de copie : ils seront rattachés dans une minute.');
        }
        if ($bilan['ignores'] !== []) {
            $this->addFlash('error', count($bilan['ignores']).' fichier(s) pas reconnu(s), laissé(s) dans le dossier : '.implode(', ', $bilan['ignores']));
        }
        if ($bilan['importes'] === [] && $bilan['ignores'] === [] && $bilan['enCours'] === 0) {
            $this->addFlash('success', 'Le dossier partagé est vide, rien à rattacher.');
        }

        return $this->redirectToRoute('app_fichier_dossier_partage');
    }

    private function assemblerFichier(
        CommandeDetailFichier $fichier
    ): void {
        $jeton = $fichier->getJetonUpload();

        if ($jeton === null) {
            throw new \RuntimeException('Jeton d’upload absent.');
        }

        $dossierJeton = $this->dossierTemporaire.'/'.$jeton;

        if (
            !is_dir($this->dossierFinal)
            && !mkdir($this->dossierFinal, 0775, true)
            && !is_dir($this->dossierFinal)
        ) {
            throw new \RuntimeException(
                'Impossible de créer le dossier final.'
            );
        }

        $cheminFinal = $this->dossierFinal
            .'/'.basename((string) $fichier->getNomStockage());
        $cheminAssemblage = $cheminFinal.'.assemblage';

        $sortie = fopen($cheminAssemblage, 'wb');

        if ($sortie === false) {
            throw new \RuntimeException(
                'Impossible de créer le fichier final.'
            );
        }

        try {
            for (
                $index = 0;
                $index < (int) $fichier->getNombreMorceaux();
                $index++
            ) {
                $cheminMorceau = $dossierJeton.'/'.sprintf(
                    '%08d.part',
                    $index
                );

                if (!is_file($cheminMorceau)) {
                    throw new \RuntimeException(
                        sprintf('Le morceau %d est absent.', $index)
                    );
                }

                $entree = fopen($cheminMorceau, 'rb');

                if ($entree === false) {
                    throw new \RuntimeException(
                        sprintf(
                            'Impossible de lire le morceau %d.',
                            $index
                        )
                    );
                }

                stream_copy_to_stream($entree, $sortie);
                fclose($entree);
            }
        } catch (\Throwable $exception) {
            @unlink($cheminAssemblage);
            throw $exception;
        } finally {
            fclose($sortie);
        }

        $tailleReelle = filesize($cheminAssemblage);

        if ($tailleReelle === false || $tailleReelle !== $fichier->getTaille()) {
            @unlink($cheminAssemblage);

            throw new \RuntimeException(
                'La taille du fichier assemblé est incorrecte.'
            );
        }

        if (!rename($cheminAssemblage, $cheminFinal)) {
            @unlink($cheminAssemblage);
            throw new \RuntimeException(
                'Impossible de finaliser le fichier assemblé.'
            );
        }

        $typeMime = mime_content_type($cheminFinal);

        if (is_string($typeMime)) {
            $fichier->setTypeMime($typeMime);
        }

        $fichier
            ->setStatut('TERMINE')
            ->setTermineLe(new \DateTimeImmutable());

        foreach (glob($dossierJeton.'/*.part') ?: [] as $morceau) {
            @unlink($morceau);
        }

        @rmdir($dossierJeton);
    }
}