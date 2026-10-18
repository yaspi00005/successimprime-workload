<?php

namespace App\Controller;

use App\Service\EcranTv;
use App\Service\ProgrammeEcranTv;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Programmation de l'écran TV : scènes, zones fixes, avis validés,
 * horaires, bandeau, alertes sonores, médias et lien sécurisé de la
 * Smart TV.
 *
 * Le programme s'enregistre en brouillon ; « Publier sur l'écran »
 * l'envoie à l'imprimerie.
 */
#[Route('/reglages/ecran-tv', name: 'app_ecran_tv_')]
#[IsGranted('ROLE_ADMIN')]
final class EcranTvReglagesController extends AbstractController
{
    private const TAILLE_MAX = 200 * 1024 * 1024;

    private const TYPES_MIME = [
        'image/jpeg', 'image/png', 'image/webp',
        'video/mp4', 'video/webm',
    ];

    public function __construct(private readonly EcranTv $ecranTv)
    {
    }

    #[Route('', name: 'reglages', methods: ['GET', 'POST'])]
    public function reglages(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('ecran-tv', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }

            $this->ecranTv->enregistrerBrouillon(self::programmeDepuis($request));
            $this->enregistrerLegendes($request);

            return match ($request->request->get('action')) {
                'publier' => $this->publier(),
                'apercu' => $this->redirectToRoute('app_ecran_tv_apercu'),
                default => $this->enregistre(),
            };
        }

        $programme = $this->ecranTv->programme(false);
        $programme['scenes'] = array_map(static fn (array $s) => $s + ['resume' => ProgrammeEcranTv::resumeHoraire($s)], $programme['scenes']);
        $passage = $this->ecranTv->dernierPassage();

        return $this->render('ecran_tv/reglages.html.twig', [
            'programme' => $programme,
            'medias' => $this->ecranTv->medias(),
            'nbPartenaires' => $this->ecranTv->nombreLogosPartenaires(),
            'boucle' => ProgrammeEcranTv::dureeBoucle($programme['scenes']),
            'nonPublie' => $this->ecranTv->aDesModificationsNonPubliees(),
            'publieLe' => $this->ecranTv->publieLe(),
            'dernierPassage' => $passage,
            'ecranEnLigne' => $passage !== null && $passage > new \DateTimeImmutable('-3 minutes'),
            'lienEcran' => $this->generateUrl('app_tv_ecran', ['jeton' => $this->ecranTv->jeton()], UrlGeneratorInterface::ABSOLUTE_URL),
            'modeles' => array_map(
                static fn (string $type) => ProgrammeEcranTv::normaliser(['scenes' => [['id' => 'nouvelle', 'type' => $type, 'titre' => '']]])['scenes'][0],
                array_combine(array_keys(ProgrammeEcranTv::TYPES), array_keys(ProgrammeEcranTv::TYPES))
            ),
            'tailleMaxMo' => (int) (min(self::TAILLE_MAX, UploadedFile::getMaxFilesize()) / 1024 / 1024),
        ]);
    }

    /**
     * Le brouillon tel qu'il est, horaires ignorés : rien n'est publié.
     */
    #[Route('/apercu', name: 'apercu', methods: ['GET'])]
    public function apercu(): Response
    {
        return $this->render('tv_dashboard/index.html.twig', $this->ecranTv->vue(new \DateTimeImmutable(), true) + [
            'urlDonnees' => $this->generateUrl('app_ecran_tv_apercu_donnees'),
        ]);
    }

    #[Route('/apercu/donnees', name: 'apercu_donnees', methods: ['GET'])]
    public function apercuDonnees(): JsonResponse
    {
        $reponse = $this->json($this->ecranTv->rafraichissement(new \DateTimeImmutable(), true));
        $reponse->headers->set('Cache-Control', 'no-store');

        return $reponse;
    }

    #[Route('/nouveau-lien', name: 'nouveau_lien', methods: ['POST'])]
    public function nouveauLien(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ecran-tv-lien', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $this->ecranTv->nouveauJeton();
        $this->addFlash('success', 'Nouveau lien créé. L\'ancien ne marche plus : ouvrez le nouveau sur la Smart TV.');

        return $this->redirectToRoute('app_ecran_tv_reglages');
    }

    /**
     * @return array<string, mixed>
     */
    private static function programmeDepuis(Request $request): array
    {
        $champs = $request->request;
        $avis = [];

        foreach ($champs->all('avis') as $un) {
            if (\is_array($un)) {
                $avis[] = $un;
            }
        }

        return [
            'scenes' => array_values(array_filter($champs->all('scenes'), 'is_array')),
            'zones' => [
                'colonneLive' => $champs->getBoolean('zone_colonne'),
                'bandeau' => $champs->getBoolean('zone_bandeau'),
                'horlogeLogo' => $champs->getBoolean('zone_horloge'),
                'son' => $champs->getBoolean('zone_son'),
            ],
            'avis' => $avis,
            'messages' => preg_split('/\R/u', (string) $champs->get('messages', '')) ?: [],
            'noteGoogle' => str_replace(',', '.', trim((string) $champs->get('note_google', ''))),
            'avisGoogle' => trim((string) $champs->get('avis_google', '')),
            'horaires' => $champs->all('horaires'),
            'horsHoraires' => (string) $champs->get('hors_horaires'),
            'alertes' => [
                'son' => $champs->getBoolean('alertes_son'),
                'voix' => $champs->getBoolean('alertes_voix'),
                'volume' => $champs->get('alertes_volume'),
                'silenceDebut' => $champs->get('alertes_silence_debut'),
                'silenceFin' => $champs->get('alertes_silence_fin'),
                // Aucune case cochée : aucune annonce (liste vide, pas les valeurs par défaut).
                'evenements' => array_values(array_filter($champs->all('alertes_evenements'), 'is_string')),
            ],
        ];
    }

    private function enregistrerLegendes(Request $request): void
    {
        $saisies = $request->request->all('legendes');
        $legendes = [];

        foreach ($this->ecranTv->medias() as $media) {
            $legende = trim((string) ($saisies[$media['fichier']] ?? $media['legende']));

            if ($legende !== '' && $legende !== EcranTv::legendeDepuisNom($media['fichier'])) {
                $legendes[$media['fichier']] = mb_substr($legende, 0, 120);
            }
        }

        $this->ecranTv->enregistrerLegendes($legendes);
    }

    private function publier(): Response
    {
        $this->ecranTv->publier(new \DateTimeImmutable());
        $this->addFlash('success', 'Programme publié : l\'écran de l\'imprimerie le prend dans la minute.');

        return $this->redirectToRoute('app_ecran_tv_reglages');
    }

    private function enregistre(): Response
    {
        $this->addFlash('success', 'Brouillon enregistré. Publiez-le pour l\'envoyer sur l\'écran.');

        return $this->redirectToRoute('app_ecran_tv_reglages');
    }

    #[Route('/medias', name: 'ajouter_medias', methods: ['POST'])]
    public function ajouterMedias(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ecran-tv-medias', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $dossier = $this->ecranTv->dossierMedias();

        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            throw new \RuntimeException('Impossible de créer le dossier des médias.');
        }

        $rang = \count($this->ecranTv->medias());
        $ajoutes = 0;
        $refuses = [];
        $slugger = new AsciiSlugger('fr');

        foreach ($request->files->all('medias') as $fichier) {
            if (!$fichier instanceof UploadedFile) {
                continue;
            }

            $nom = $fichier->getClientOriginalName();

            if (!$fichier->isValid()) {
                $refuses[] = $nom.' (envoi incomplet ou trop lourd)';
                continue;
            }

            $extension = strtolower($fichier->getClientOriginalExtension());

            if (
                EcranTv::typeMedia('x.'.$extension) === null
                || !\in_array($fichier->getMimeType(), self::TYPES_MIME, true)
                || $fichier->getSize() > self::TAILLE_MAX
            ) {
                $refuses[] = $nom.' (format ou taille non accepté)';
                continue;
            }

            $base = $slugger->slug(pathinfo($nom, \PATHINFO_FILENAME))->lower()->truncate(60)->toString() ?: 'media';
            $fichier->move($dossier, sprintf('%02d_%s.%s', ++$rang, $base, $extension));
            ++$ajoutes;
        }

        if ($ajoutes > 0) {
            $this->addFlash('success', sprintf('%d média(s) ajouté(s) à l\'écran TV.', $ajoutes));
        }

        if ($refuses !== []) {
            $this->addFlash('error', 'Refusé : '.implode(', ', $refuses));
        }

        return $this->redirectToRoute('app_ecran_tv_reglages');
    }

    #[Route('/medias/deplacer', name: 'deplacer_media', methods: ['POST'])]
    public function deplacerMedia(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ecran-tv-medias', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $fichiers = array_column($this->ecranTv->medias(), 'fichier');
        $position = array_search((string) $request->request->get('fichier'), $fichiers, true);
        $cible = $position === false ? false : $position + ($request->request->get('sens') === 'haut' ? -1 : 1);

        if ($position !== false && isset($fichiers[$cible])) {
            [$fichiers[$position], $fichiers[$cible]] = [$fichiers[$cible], $fichiers[$position]];
            $this->renumeroter($fichiers);
        }

        return $this->redirectToRoute('app_ecran_tv_reglages');
    }

    #[Route('/medias/supprimer', name: 'supprimer_media', methods: ['POST'])]
    public function supprimerMedia(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('ecran-tv-medias', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $fichiers = array_column($this->ecranTv->medias(), 'fichier');
        $fichier = (string) $request->request->get('fichier');

        // Seulement un fichier listé : jamais un chemin venu du formulaire.
        if (\in_array($fichier, $fichiers, true)) {
            @unlink($this->ecranTv->dossierMedias().'/'.$fichier);
            $this->renumeroter(array_values(array_diff($fichiers, [$fichier])));
            $this->addFlash('success', 'Média retiré de l\'écran TV.');
        }

        return $this->redirectToRoute('app_ecran_tv_reglages');
    }

    /**
     * Renomme les médias 01_, 02_… dans l'ordre donné, en gardant les
     * légendes et les scènes qui les utilisent.
     *
     * @param list<string> $fichiers
     */
    private function renumeroter(array $fichiers): void
    {
        $dossier = $this->ecranTv->dossierMedias();
        $temporaires = [];
        $nouveaux = [];

        // En deux temps pour ne jamais écraser un fichier qui porte déjà le nouveau nom.
        foreach ($fichiers as $i => $fichier) {
            $temporaire = '.renommage-'.$i.'-'.$fichier;
            rename($dossier.'/'.$fichier, $dossier.'/'.$temporaire);
            $temporaires[$fichier] = $temporaire;
        }

        foreach ($fichiers as $i => $fichier) {
            $nouveau = sprintf('%02d_%s', $i + 1, preg_replace('/^\d+_/', '', $fichier));
            rename($dossier.'/'.$temporaires[$fichier], $dossier.'/'.$nouveau);
            $nouveaux[$fichier] = $nouveau;
        }

        $this->ecranTv->renommerMedias($nouveaux);
    }
}
