<?php

namespace App\Controller;

use App\Service\EcranTv;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Réglages de l'écran TV : images et vidéos qui défilent, légendes,
 * messages du bandeau et note Google.
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
        $reglages = $this->ecranTv->reglages();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('ecran-tv', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }

            $messages = preg_split('/\R/u', (string) $request->request->get('messages', '')) ?: [];
            $note = str_replace(',', '.', trim((string) $request->request->get('note_google', '')));
            $avis = trim((string) $request->request->get('avis_google', ''));
            $legendes = [];

            foreach ($this->ecranTv->medias() as $media) {
                $legende = trim((string) ($request->request->all('legendes')[$media['fichier']] ?? ''));

                if ($legende !== '' && $legende !== EcranTv::legendeDepuisNom($media['fichier'])) {
                    $legendes[$media['fichier']] = mb_substr($legende, 0, 120);
                }
            }

            $this->ecranTv->enregistrerReglages([
                'messages' => array_values(array_filter(array_map(static fn (string $m) => mb_substr(trim($m), 0, 120), $messages))),
                'noteGoogle' => is_numeric($note) ? max(0, min(5, round((float) $note, 1))) : null,
                'avisGoogle' => ctype_digit($avis) ? (int) $avis : null,
                'dureeImage' => (int) $request->request->get('duree_image', 8),
                'legendes' => $legendes,
            ]);

            $this->addFlash('success', 'Réglages de l\'écran TV enregistrés.');

            return $this->redirectToRoute('app_ecran_tv_reglages');
        }

        return $this->render('ecran_tv/reglages.html.twig', [
            'reglages' => $reglages,
            'medias' => $this->ecranTv->medias(),
            'tailleMaxMo' => (int) (min(self::TAILLE_MAX, UploadedFile::getMaxFilesize()) / 1024 / 1024),
        ]);
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
     * Renomme les médias 01_, 02_… dans l'ordre donné, en gardant les légendes.
     *
     * @param list<string> $fichiers
     */
    private function renumeroter(array $fichiers): void
    {
        $dossier = $this->ecranTv->dossierMedias();
        $reglages = $this->ecranTv->reglages();
        $legendes = [];
        $temporaires = [];

        // En deux temps pour ne jamais écraser un fichier qui porte déjà le nouveau nom.
        foreach ($fichiers as $i => $fichier) {
            $temporaire = '.renommage-'.$i.'-'.$fichier;
            rename($dossier.'/'.$fichier, $dossier.'/'.$temporaire);
            $temporaires[$fichier] = $temporaire;
        }

        foreach ($fichiers as $i => $fichier) {
            $nouveau = sprintf('%02d_%s', $i + 1, preg_replace('/^\d+_/', '', $fichier));
            rename($dossier.'/'.$temporaires[$fichier], $dossier.'/'.$nouveau);
            $legendes[$nouveau] = $reglages['legendes'][$fichier] ?? null;
        }

        $reglages['legendes'] = array_filter($legendes);
        $this->ecranTv->enregistrerReglages($reglages);
    }
}
