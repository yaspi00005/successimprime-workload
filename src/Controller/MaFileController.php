<?php

namespace App\Controller;

use App\Entity\JournalMachine;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\User;
use App\Repository\MachinesRepository;
use App\Service\Parametres\Parametres;
use App\Service\SuiviMetiers\FileMachine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * File machine du machiniste et travail en cours (maquette W03, mobile).
 * Démarrer, mettre en pause et terminer passent par les actions de la
 * production, qui gèrent le stock et les compteurs ; cet écran ajoute la
 * gâche, les incidents, l'entretien des têtes et les points.
 */
#[Route('/atelier/ma-file', name: 'app_ma_file_')]
#[IsGranted('ROLE_PRODUCTION_VOIR')]
final class MaFileController extends AbstractController
{
    private const CSRF = 'ma-file';
    private const SESSION_MACHINE = 'ma_file_machine';

    public function __construct(
        private readonly FileMachine $file,
        private readonly MachinesRepository $machines,
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $poste = $this->poste($request);
        $machine = $poste ?? $this->machine($request);
        $maintenant = new \DateTimeImmutable();

        return $this->render('ma_file/index.html.twig', [
            'machine' => $machine,
            'poste' => $poste,
            'machines' => $this->machines->findBy([], ['nom' => 'ASC']),
            'journee' => $machine !== null ? $this->file->journee($machine, $maintenant) : null,
            'maintenant' => $maintenant,
            'peutAgir' => $poste !== null && $this->isGranted('ROLE_PRODUCTION_GERER'),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/{id}', name: 'travail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function travail(OrdreProduction $ordre, Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $poste = $this->poste($request);

        return $this->render('ma_file/travail.html.twig', [
            'o' => $ordre,
            'ligne' => $ordre->getCommandeDetail(),
            'progression' => FileMachine::progression($ordre, $maintenant),
            'faits' => FileMachine::faitsOrdre($ordre),
            'bareme' => $this->parametres->bareme(),
            'evenements' => array_values(array_filter($ordre->getMachine() !== null ? $this->file->journee($ordre->getMachine(), $maintenant)['evenements'] : [], static fn (JournalMachine $j): bool => $j->getOrdre() === $ordre)),
            'maintenant' => $maintenant,
            // Agir sur un travail : seulement depuis le poste de sa machine (adresse IP reconnue).
            'peutAgir' => $poste !== null && $poste === $ordre->getMachine() && $this->isGranted('ROLE_PRODUCTION_GERER'),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/signaler', name: 'signaler', methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function signaler(Request $request): Response
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $type = (string) $request->request->get('type');
        $ordre = $request->request->get('ordre') ? $this->em->getRepository(OrdreProduction::class)->find((int) $request->request->get('ordre')) : null;
        $machine = $this->poste($request);
        if ($machine === null || ($ordre !== null && $ordre->getMachine() !== $machine)) {
            throw $this->createAccessDeniedException('La production se signale depuis le poste de la machine.');
        }
        $quantite = is_numeric(str_replace(',', '.', (string) $request->request->get('quantite'))) ? (float) str_replace(',', '.', (string) $request->request->get('quantite')) : null;
        $note = (string) $request->request->get('note');

        if ($machine === null || !isset(JournalMachine::TYPES[$type])) {
            $this->addFlash('danger', 'Choisissez la machine et ce qui est signalé.');
        } elseif (\in_array($type, [JournalMachine::GACHE, JournalMachine::REIMPRESSION], true) && ($quantite === null || $quantite <= 0 || $quantite > 1000)) {
            $this->addFlash('danger', 'Indiquez les m² concernés.');
        } elseif ($type === JournalMachine::INCIDENT && trim($note) === '') {
            $this->addFlash('danger', 'Décrivez l\'incident en quelques mots.');
        } else {
            $moi = $this->getUser();
            \assert($moi instanceof User);
            $j = $this->file->signaler($machine, $moi, $type, new \DateTimeImmutable(), $ordre, $quantite, $note);
            $this->addFlash('success', match ($type) {
                JournalMachine::TETES => 'Entretien des têtes noté à '.$j->getLe()->format('H:i').'.',
                JournalMachine::GACHE => \sprintf('Gâche de %s m² notée.', str_replace('.', ',', (string) $j->getQuantite())),
                JournalMachine::REIMPRESSION => 'Réimpression notée.',
                default => 'Incident signalé : il ne retire aucun point.',
            });
        }

        return $ordre !== null
            ? $this->redirectToRoute('app_ma_file_travail', ['id' => $ordre->getId()])
            : $this->redirectToRoute('app_ma_file_index');
    }

    /** Machine du poste, reconnue par l'adresse IP (comme la production). */
    private function poste(Request $request): ?Machines
    {
        $ip = $request->getClientIp();

        return $ip !== null ? $this->machines->findOneByAdresseIp($ip) : null;
    }

    /** Machine choisie pour consulter, gardée en session ; sinon celle du dernier ordre de la personne. */
    private function machine(Request $request): ?Machines
    {
        $session = $request->getSession();
        $id = (int) ($request->query->get('machine') ?? $request->request->get('machine') ?? $session->get(self::SESSION_MACHINE, 0));
        $machine = $id > 0 ? $this->machines->find($id) : null;
        if ($machine === null) {
            $dernier = $this->em->getRepository(OrdreProduction::class)->findOneBy(['operateur' => $this->getUser()], ['demarreLe' => 'DESC']);
            $machine = $dernier?->getMachine() ?? $this->machines->findOneBy([], ['nom' => 'ASC']);
        }
        if ($machine !== null) {
            $session->set(self::SESSION_MACHINE, $machine->getId());
        }

        return $machine;
    }
}
