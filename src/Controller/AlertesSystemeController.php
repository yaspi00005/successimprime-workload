<?php

namespace App\Controller;

use App\Entity\AlerteSysteme;
use App\Entity\User;
use App\Repository\AlerteSystemeRepository;
use App\Service\Alertes\AlertesSysteme;
use App\Service\Alertes\ReglagesAlertes;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages › Alertes système (docs/alertes-systeme.md) : ce qui a cassé sur
 * le site, l'espace client ou l'ERP, qui s'en occupe (« Je prends »), le
 * détail technique et la résolution. La direction et le droit « Alertes
 * système » (le technicien) ; les réglages (qui reçoit quoi, délai entre
 * deux e-mails) sont à la direction.
 */
#[Route('/reglages/alertes-systeme', name: 'app_alertes_systeme_')]
#[IsGranted('ROLE_ALERTES_SYSTEME')]
final class AlertesSystemeController extends AbstractController
{
    public const VUES = ['en_cours' => 'En cours', 'resolues' => 'Résolues', 'toutes' => 'Toutes'];

    public function __construct(
        private readonly AlerteSystemeRepository $depot,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $vue = isset(self::VUES[(string) $request->query->get('vue')]) ? (string) $request->query->get('vue') : 'en_cours';
        $type = isset(AlerteSysteme::TYPES[(string) $request->query->get('type')]) ? (string) $request->query->get('type') : null;

        return $this->render('alertes_systeme/index.html.twig', [
            'alertes' => $this->depot->liste($vue, $type),
            'vue' => $vue,
            'type' => $type,
            'vues' => self::VUES,
            'compte' => $this->depot->compterParEtat(),
            'limite' => AlerteSystemeRepository::LIMITE,
        ]);
    }

    #[Route('/reglages', name: 'reglages', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reglages(Request $request, ReglagesAlertes $reglages, AlertesSysteme $alertes, #[Autowire('%env(MAILER_FROM_ADDRESS)%')] string $expediteur, #[Autowire('%env(MAILER_DSN)%')] string $dsn): Response
    {
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('alertes-systeme-reglages', (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException();
            }
            $utilisateur = $this->getUser();
            $reglages->enregistrer([...$request->request->all(), 'formulaire' => 1], $utilisateur instanceof User ? $utilisateur : null, new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', 'Réglages des alertes système enregistrés.');

            return $this->redirectToRoute('app_alertes_systeme_reglages');
        }

        $possibles = $alertes->possibles();

        return $this->render('alertes_systeme/reglages.html.twig', [
            'r' => $reglages->valeurs(),
            'possibles' => $possibles,
            'sans_email' => array_values(array_filter($possibles, static fn (User $u): bool => '' === trim((string) $u->getEmploye()?->getEmail()))),
            'messagerie' => '' !== trim($expediteur) && '' !== trim($dsn) && !str_starts_with($dsn, 'null://'),
            'en_ligne' => $alertes->estEnLigne(),
        ]);
    }

    #[Route('/essai', name: 'essai', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function essai(Request $request, AlertesSysteme $alertes): Response
    {
        if (!$this->isCsrfTokenValid('alertes-systeme-essai', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $maintenant = new \DateTimeImmutable();
        $utilisateur = $this->getUser();
        $id = $alertes->signaler(AlerteSysteme::ESSAI, 'Alerte d\'essai demandée depuis l\'écran des réglages', 'Essai demandé par '.($utilisateur?->getUserIdentifier() ?? '?').'. Rien n\'est cassé : résolvez-la.', ['essai', $maintenant->format('U')], AlerteSysteme::AVERTISSEMENT, $maintenant);
        $this->addFlash(null === $id ? 'error' : 'success', null === $id ? 'L\'alerte d\'essai n\'a pas pu être créée.' : 'Alerte d\'essai créée : elle part par e-mail et dans l\'application à ses destinataires (sauf si une autre alerte d\'essai est partie il y a moins que le délai réglé).');

        return null === $id ? $this->redirectToRoute('app_alertes_systeme_reglages') : $this->redirectToRoute('app_alertes_systeme_voir', ['id' => $id]);
    }

    #[Route('/{id}', name: 'voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(AlerteSysteme $alerte): Response
    {
        return $this->render('alertes_systeme/voir.html.twig', ['alerte' => $alerte]);
    }

    #[Route('/{id}/prendre', name: 'prendre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function prendre(AlerteSysteme $alerte, Request $request): Response
    {
        $this->verifierJeton($request, 'prendre', $alerte);
        $utilisateur = $this->getUser();
        if ($utilisateur instanceof User && !$alerte->estResolue()) {
            $alerte->prendre($utilisateur, new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', \sprintf('Vous prenez en charge %s.', $alerte->getReference()));
        }

        return $this->retour($request, $alerte);
    }

    #[Route('/{id}/resoudre', name: 'resoudre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function resoudre(AlerteSysteme $alerte, Request $request): Response
    {
        $this->verifierJeton($request, 'resoudre', $alerte);
        $utilisateur = $this->getUser();
        $alerte->resoudre($utilisateur instanceof User ? $utilisateur : null, (string) $request->request->get('resolution', ''), new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', \sprintf('%s est résolue. Si la même panne revient, une nouvelle alerte s\'ouvrira.', $alerte->getReference()));

        return $this->retour($request, $alerte);
    }

    #[Route('/{id}/rouvrir', name: 'rouvrir', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function rouvrir(AlerteSysteme $alerte, Request $request): Response
    {
        $this->verifierJeton($request, 'rouvrir', $alerte);
        if ($alerte->estResolue()) {
            $alerte->rouvrir();
            // Rouverte à la main : la personne le sait déjà, pas de nouvel envoi.
            $alerte->marquerPrevenue(new \DateTimeImmutable(), false);
            $this->em->flush();
            $this->addFlash('success', \sprintf('%s est rouverte.', $alerte->getReference()));
        }

        return $this->retour($request, $alerte);
    }

    private function verifierJeton(Request $request, string $geste, AlerteSysteme $alerte): void
    {
        if (!$this->isCsrfTokenValid('alerte-systeme-'.$geste.'-'.$alerte->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    /** Retour à la liste quand le geste vient d'elle, sinon à l'alerte. */
    private function retour(Request $request, AlerteSysteme $alerte): Response
    {
        $vue = (string) $request->request->get('vue', '');

        return isset(self::VUES[$vue])
            ? $this->redirectToRoute('app_alertes_systeme_index', ['vue' => $vue])
            : $this->redirectToRoute('app_alertes_systeme_voir', ['id' => $alerte->getId()]);
    }
}
