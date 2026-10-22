<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Parametres\CatalogueParametres;
use App\Service\Parametres\Parametres;
use App\Service\SuiviMetiers\Bareme;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Barème des points par métier (maquette W07). Les valeurs sont des
 * réglages de la tuile « Barème des points » : même journal des
 * changements, et depuis l'admin en ligne, même validation sur l'ERP local.
 */
#[Route('/equipe/bareme', name: 'app_bareme_')]
#[IsGranted('ROLE_RH')]
final class BaremeController extends AbstractController
{
    private const CSRF = 'bareme-points';

    public function __construct(private readonly Parametres $parametres)
    {
    }

    #[Route('', name: 'index', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $champs = array_values(array_filter(CatalogueParametres::tuiles()['bareme']['champs'], static fn (array $c): bool => !empty($c['ecran'])));
        $erreurs = [];
        $saisie = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }
            $lecture = CatalogueParametres::lireSaisie($champs, $request->request->all());
            $erreurs = $lecture['erreurs'];
            if ($erreurs === []) {
                $par = $this->getUser();
                match ($this->parametres->enregistrer('bareme', $lecture['valeurs'], $par instanceof User ? $par : null)) {
                    'applique' => $this->addFlash('success', 'Barème enregistré. Le changement est gardé dans le journal.'),
                    'propose' => $this->addFlash('warning', 'Barème envoyé : il sera appliqué après validation sur l\'ERP local.'),
                    default => $this->addFlash('info', 'Rien n\'a changé.'),
                };

                return $this->redirectToRoute('app_bareme_index');
            }
            $saisie = $request->request->all();
            $this->addFlash('danger', 'Certaines valeurs sont hors limites : corrigez les cases en rouge.');
        }

        $bareme = $this->parametres->bareme();
        $valeurs = [];
        foreach ($champs as $champ) {
            $valeurs[$champ['cle']] = $saisie[$champ['cle']] ?? $bareme->valeur($champ['cle']);
        }
        $exemple = ['points' => 612, 'possibles' => 703, 'autres' => [85, 80]];
        $exemple['note'] = Bareme::note($exemple['points'], $exemple['possibles']);
        $exemple['score'] = $bareme->score($exemple['note'], $exemple['autres']);
        $exemple['palier'] = Bareme::palier($exemple['score']);

        return $this->render('bareme/index.html.twig', [
            'metiers' => Bareme::METIERS,
            'difficultes' => Bareme::DIFFICULTES,
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'exemple' => $exemple,
            'paliers' => Bareme::PALIERS,
            'panneau' => $this->parametres->panneau('bareme'),
            'jeton' => self::CSRF,
        ], new Response(status: $erreurs !== [] ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }
}
