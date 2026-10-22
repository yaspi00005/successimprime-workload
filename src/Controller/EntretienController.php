<?php

namespace App\Controller;

use App\Entity\Tache;
use App\Entity\User;
use App\Service\SuiviMetiers\Entretien;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Agent d'entretien (W05, mobile) : son planning du jour et la validation
 * par photo ; le responsable (RH ou direction) contrôle des tâches tirées
 * au hasard. L'agent ne voit que ses tâches et ses points.
 */
#[Route('/entretien', name: 'app_entretien_')]
final class EntretienController extends AbstractController
{
    private const CSRF = 'entretien';

    public function __construct(
        private readonly Entretien $entretien,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $agent = $this->moi();
        if ($this->responsable() && (int) $request->query->get('agent') > 0) {
            $agent = $this->em->find(User::class, (int) $request->query->get('agent')) ?? $agent;
        } elseif (!$this->isGranted('ROLE_ENTRETIEN')) {
            return $this->redirectToRoute('app_entretien_controle');
        }

        return $this->render('entretien/index.html.twig', [
            'agent' => $agent,
            'j' => $this->entretien->journee($agent, new \DateTimeImmutable()),
            'maintenant' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/tache/{id}', name: 'tache', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function tache(Tache $tache, Request $request): Response
    {
        $agent = $tache->getResponsable() === $this->getUser();
        if (!$agent && !$this->responsable()) {
            throw $this->createAccessDeniedException('Cette tâche est confiée à un autre agent.');
        }
        $maintenant = new \DateTimeImmutable();
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request);
            if (!$agent) {
                throw $this->createAccessDeniedException('Seul l\'agent de la tâche la valide.');
            }
            $erreur = $this->entretien->valider($tache, $this->moi(), (string) $request->request->get('photo') ?: null, array_map('intval', (array) $request->request->all('etapes')), $maintenant);
            if ($erreur === null) {
                $this->addFlash('success', \sprintf('« %s » validée à %s.', $tache->getTitre(), $maintenant->format('H:i')));

                return $this->redirectToRoute('app_entretien_index');
            }
            $this->addFlash('danger', $erreur);
        } elseif ($agent && !$tache->estFaite() && $tache->getPrevueLe()->format('Y-m-d') === $maintenant->format('Y-m-d')) {
            $tache->commencer($maintenant);
            $this->em->flush();
        }

        return $this->render('entretien/tache.html.twig', [
            't' => $tache,
            'agent' => $agent,
            'retard' => Entretien::retard($tache, $maintenant),
            'valeurs' => $this->entretien->valeurs(),
            'points' => $tache->estFaite() ? $this->entretien->points($tache, $maintenant) : null,
            'jeton' => self::CSRF,
        ]);
    }

    /** Contrôle du responsable : tâches du jour, dont celles tirées au hasard. */
    #[Route('/controle', name: 'controle', methods: ['GET'])]
    public function controle(Request $request): Response
    {
        $this->exigerResponsable();
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('jour')) ?: new \DateTimeImmutable('today');
        $taches = $this->entretien->duJour($jour);
        $tirees = Entretien::tirage($taches, $jour, $this->entretien->nombreControles());
        $parAgent = [];
        foreach ($taches as $t) {
            $parAgent[$t->getResponsable()?->getId() ?? 0]['agent'] = $t->getResponsable();
            $parAgent[$t->getResponsable()?->getId() ?? 0]['taches'][] = $t;
        }

        $points = [];
        foreach ($taches as $t) {
            $points[$t->getId()] = $this->entretien->points($t, new \DateTimeImmutable());
        }

        return $this->render('entretien/controle.html.twig', [
            'points' => $points,
            'jour' => $jour,
            'parAgent' => array_values($parAgent),
            'tirees' => $tirees,
            'nombre' => $this->entretien->nombreControles(),
            'maintenant' => new \DateTimeImmutable(),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/tache/{id}/controle', name: 'controler', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function controler(Tache $tache, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerResponsable();
        if (!$tache->estFaite()) {
            $this->addFlash('danger', 'La tâche n\'est pas encore faite.');
        } else {
            $conforme = $request->request->get('resultat') === 'conforme';
            $tache->controler($conforme, $this->moi(), new \DateTimeImmutable());
            if (!$conforme) {
                // À refaire : l'agent est prévenu dans l'application.
                if ($tache->getResponsable() !== null) {
                    $this->em->persist((new \App\Entity\Notification())->setDestinataire($tache->getResponsable())->setMessage(\sprintf('Contrôle non conforme : « %s » est à refaire.', $tache->getTitre()))->setRoute('app_entretien_tache')->setRouteParametres(['id' => $tache->getId()]));
                }
            }
            $this->em->flush();
            $this->addFlash('success', $conforme ? 'Contrôle conforme.' : 'Contrôle non conforme : l\'agent est prévenu.');
        }

        return $this->redirectToRoute('app_entretien_controle', ['jour' => $tache->getPrevueLe()->format('Y-m-d')]);
    }

    #[Route('/tache/{id}/photo', name: 'photo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function photo(Tache $tache): Response
    {
        if ($tache->getResponsable() !== $this->getUser() && !$this->responsable()) {
            throw $this->createAccessDeniedException();
        }
        $chemin = $this->entretien->cheminPhoto($tache) ?? throw $this->createNotFoundException();
        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('Content-Type', 'image/jpeg');
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->setPrivate();

        return $reponse;
    }

    private function responsable(): bool
    {
        return $this->isGranted('ROLE_RH');
    }

    private function exigerResponsable(): void
    {
        if (!$this->responsable()) {
            throw $this->createAccessDeniedException('Le contrôle de l\'entretien est réservé au responsable (droit Administrateur RH).');
        }
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
