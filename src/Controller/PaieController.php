<?php

namespace App\Controller;

use App\Entity\BulletinPaie;
use App\Entity\User;
use App\Repository\BulletinPaieRepository;
use App\Repository\CompteTresorerieRepository;
use App\Service\EspaceEmploye\Paie;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Paie du mois (tuile Paie) : préparer les bulletins, les compléter, les
 * valider puis les payer. Salaires visibles par la RH seulement ; chaque
 * employé voit ses bulletins validés dans son espace.
 */
#[Route('/equipe/paie', name: 'app_paie_')]
#[IsGranted('ROLE_RH')]
final class PaieController extends AbstractController
{
    private const CSRF = 'paie';

    public function __construct(
        private readonly Paie $paie,
        private readonly BulletinPaieRepository $bulletins,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, CompteTresorerieRepository $comptes): Response
    {
        $mois = $this->mois($request);
        $bulletins = $this->bulletins->duMois($mois);
        $total = ['brut' => 0, 'retenues' => 0, 'net' => 0, 'paye' => 0];
        foreach ($bulletins as $b) {
            $total['brut'] += $b->brut();
            $total['retenues'] += $b->retenues();
            $total['net'] += $b->net();
            $total['paye'] += $b->getEtat() === BulletinPaie::PAYE ? $b->net() : 0;
        }

        return $this->render('paie/index.html.twig', [
            'mois' => $mois,
            'libelleMois' => Paie::mois($mois),
            'versement' => $this->paie->dateVersement($mois),
            'bulletins' => $bulletins,
            'total' => $total,
            'comptes' => $this->isGranted('ROLE_TRESORERIE_SAISIR') ? $comptes->findActifs() : [],
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/preparer', name: 'preparer', methods: ['POST'])]
    public function preparer(Request $request): Response
    {
        $this->verifierJeton($request);
        $mois = $this->mois($request);
        $r = $this->paie->preparer($mois, $this->moi(), new \DateTimeImmutable());
        $this->addFlash($r['crees'] > 0 ? 'success' : 'info', $r['crees'] > 0 ? \sprintf('%d bulletin%s préparé%2$s.', $r['crees'], $r['crees'] > 1 ? 's' : '') : 'Aucun bulletin à ajouter.');
        if ($r['sansSalaire'] !== []) {
            $this->addFlash('warning', 'Sans salaire sur la fiche employé : '.implode(', ', $r['sansSalaire']).'.');
        }

        return $this->redirectToRoute('app_paie_index', ['mois' => $mois->format('Y-m')]);
    }

    #[Route('/{id}', name: 'voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(BulletinPaie $bulletin, CompteTresorerieRepository $comptes): Response
    {
        return $this->render('paie/voir.html.twig', [
            'b' => $bulletin,
            'libelleMois' => Paie::mois($bulletin->getMois()),
            'types' => $this->paie->typesLignes(),
            'absences' => $this->paie->joursAbsence($bulletin->getUtilisateur(), $bulletin->getMois()),
            'versement' => $this->paie->dateVersement($bulletin->getMois()),
            'comptes' => $this->isGranted('ROLE_TRESORERIE_SAISIR') ? $comptes->findActifs() : [],
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/{id}/{action}', name: 'agir', requirements: ['id' => '\d+', 'action' => 'ligne|retirer|base|valider|rouvrir'], methods: ['POST'])]
    public function agir(BulletinPaie $bulletin, string $action, Request $request): Response
    {
        $this->verifierJeton($request);
        $montant = static fn (string $cle): int => (int) preg_replace('/[^\d-]/', '', (string) $request->request->get($cle));
        $erreur = match ($action) {
            'ligne' => $this->paie->ajouterLigne($bulletin, (string) $request->request->get('type'), (string) $request->request->get('libelle'), $montant('montant'), $this->moi()),
            'retirer' => $bulletin->modifiable() ? null : 'Ce bulletin est validé : il ne change plus.',
            'base' => $bulletin->modifiable() ? null : 'Ce bulletin est validé : il ne change plus.',
            'valider' => $this->paie->valider($bulletin, $this->moi(), new \DateTimeImmutable()),
            'rouvrir' => $bulletin->rouvrir(),
        };
        if ($erreur === null && $action === 'retirer') {
            $bulletin->retirerLigne($request->request->getInt('index', -1));
        }
        if ($erreur === null && $action === 'base') {
            $bulletin->setSalaireBase(max(0, $montant('salaire')));
        }
        if ($erreur === null) {
            $this->em->flush();
        }
        $erreur !== null ? $this->addFlash('danger', $erreur) : $this->addFlash('success', match ($action) {
            'valider' => 'Bulletin validé : l\'employé le voit dans son espace.',
            'rouvrir' => 'Bulletin remis en brouillon.',
            default => 'Bulletin mis à jour.',
        });

        return $this->redirectToRoute('app_paie_voir', ['id' => $bulletin->getId()]);
    }

    #[Route('/{id}/payer', name: 'payer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_TRESORERIE_SAISIR')]
    public function payer(BulletinPaie $bulletin, Request $request, CompteTresorerieRepository $comptes): Response
    {
        $this->verifierJeton($request);
        $compte = $comptes->find($request->request->getInt('compte'));
        $erreur = $compte === null ? 'Choisissez le compte qui paie.' : $this->paie->payer($bulletin, $compte, $this->moi());
        $erreur !== null ? $this->addFlash('danger', $erreur) : $this->addFlash('success', 'Salaire payé : décaissement enregistré en trésorerie (confidentiel).');

        return $request->request->get('retour') === 'liste'
            ? $this->redirectToRoute('app_paie_index', ['mois' => $bulletin->getMois()->format('Y-m')])
            : $this->redirectToRoute('app_paie_voir', ['id' => $bulletin->getId()]);
    }

    private function mois(Request $request): \DateTimeImmutable
    {
        $saisi = \DateTimeImmutable::createFromFormat('!Y-m', (string) ($request->query->get('mois') ?? $request->request->get('mois')));

        return $saisi ?: BulletinPaie::premierDuMois(new \DateTimeImmutable());
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function moi(): User
    {
        $moi = $this->getUser();
        \assert($moi instanceof User);

        return $moi;
    }
}
