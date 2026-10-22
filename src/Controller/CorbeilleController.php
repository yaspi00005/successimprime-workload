<?php

namespace App\Controller;

use App\Entity\Corbeille as EntreeCorbeille;
use App\Entity\User;
use App\Repository\CorbeilleRepository;
use App\Security\CorbeilleVoter;
use App\Service\Corbeille\Corbeille;
use App\Service\Corbeille\TypesCorbeille;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Écran « Corbeille » (Réglages › Corbeille, docs/corbeille.md) : ERP local
 * seulement. La Direction et qui a le droit « Corbeille » ; chacun n'y voit
 * que les fiches de ses autres droits (CorbeilleVoter). En mode « Voir »
 * (lecture seule), LectureSeuleSubscriber refuse restaurer et supprimer.
 */
#[Route('/corbeille')]
#[IsGranted(CorbeilleVoter::CORBEILLE)]
final class CorbeilleController extends AbstractController
{
    /** Entrées montrées au plus (les plus récentes). */
    private const LIMITE = 200;

    public function __construct(
        private readonly Corbeille $corbeille,
        private readonly CorbeilleVoter $voter,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    #[Route('', name: 'app_corbeille_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->fermerEnLigne();
        $tables = $this->voter->tablesVisibles();
        $filtres = [
            'type' => (string) $request->query->get('type', ''),
            'du' => (string) $request->query->get('du', ''),
            'au' => (string) $request->query->get('au', ''),
            'par' => (string) $request->query->get('par', ''),
        ];
        $choix = $this->corbeille->choixFiltres($tables);
        if (!isset($choix['types'][$filtres['type']])) {
            $filtres['type'] = '';
        }

        $entrees = $this->corbeille->entrees($filtres, $tables, self::LIMITE);

        return $this->render('corbeille/index.html.twig', [
            'entrees' => array_map(fn (array $e): array => $e + ['expire' => $this->corbeille->expireLe($e['entree'])], $entrees),
            'filtres' => $filtres,
            'choix' => $choix,
            'filtre' => '' !== implode('', $filtres),
            'duree' => $this->corbeille->duree(),
            'limite' => self::LIMITE,
            'aujourdhui' => new \DateTimeImmutable('today'),
        ]);
    }

    #[Route('/{id}', name: 'app_corbeille_voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(int $id, CorbeilleRepository $depot): Response
    {
        $entree = $this->entree($id, $depot);

        return $this->render('corbeille/voir.html.twig', [
            'entree' => $entree,
            'detail' => $this->corbeille->detail($entree, $this->isGranted(TypesCorbeille::DIRECTION), $this->voter->peutVoirTable(...)),
            'expire' => $this->corbeille->expireLe($entree),
            'duree' => $this->corbeille->duree(),
        ]);
    }

    #[Route('/{id}/restaurer', name: 'app_corbeille_restaurer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function restaurer(int $id, Request $request, CorbeilleRepository $depot): Response
    {
        $entree = $this->entree($id, $depot);
        if (!$this->isCsrfTokenValid('corbeille-restaurer-'.$entree->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : rechargez-la puis recommencez.');

            return $this->redirectToRoute('app_corbeille_voir', ['id' => $entree->getId()]);
        }
        try {
            $message = $this->corbeille->restaurer($entree, $this->utilisateur());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_corbeille_voir', ['id' => $entree->getId()]);
        }
        $this->addFlash('success', $message);

        return $this->redirectToRoute('app_corbeille_index');
    }

    #[Route('/{id}/supprimer', name: 'app_corbeille_supprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function supprimer(int $id, Request $request, CorbeilleRepository $depot): Response
    {
        $entree = $this->entree($id, $depot);
        if (!$this->isCsrfTokenValid('corbeille-supprimer-'.$entree->getId(), $request->request->getString('_token'))) {
            $this->addFlash('error', 'La page a expiré : rechargez-la puis recommencez.');

            return $this->redirectToRoute('app_corbeille_voir', ['id' => $entree->getId()]);
        }
        $libelle = $entree->getLibelle();
        $this->corbeille->supprimerDefinitivement($entree, $this->utilisateur());
        $this->addFlash('success', \sprintf('%s est supprimé pour de bon.', $libelle));

        return $this->redirectToRoute('app_corbeille_index');
    }

    /** Une entrée (sa fiche principale) que la personne a le droit de voir. */
    private function entree(int $id, CorbeilleRepository $depot): EntreeCorbeille
    {
        $this->fermerEnLigne();
        $entree = $depot->find($id);
        if (!$entree instanceof EntreeCorbeille || !$entree->isRacine()) {
            throw $this->createNotFoundException('Cette entrée n\'est plus dans la corbeille.');
        }
        $this->denyAccessUnlessGranted(CorbeilleVoter::ENTREE, $entree);

        return $entree;
    }

    private function fermerEnLigne(): void
    {
        if ($this->enLigne) {
            throw $this->createNotFoundException();
        }
    }

    private function utilisateur(): ?User
    {
        $u = $this->getUser();

        return $u instanceof User ? $u : null;
    }
}
