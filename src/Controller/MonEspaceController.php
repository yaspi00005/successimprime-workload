<?php

namespace App\Controller;

use App\Entity\BulletinPaie;
use App\Entity\DemandeEmploye;
use App\Repository\BulletinPaieRepository;
use App\Service\EspaceEmploye\Paie;
use App\Entity\User;
use App\Repository\DemandeEmployeRepository;
use App\Repository\ImmobilisationRepository;
use App\Security\AccesEnLigne;
use App\Service\ActionsCaisse\ActionsCaisse;
use App\Service\EspaceEmploye\EspaceEmploye;
use App\Service\Pointage\Pointages;
use App\Service\Primes\Primes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace employé : mes chiffres, mes demandes (absence, congé,
 * réclamation, frais médicaux) et leurs réponses. Chacun ne voit que les
 * siennes.
 */
#[Route('/mon-espace', name: 'app_mon_espace_')]
final class MonEspaceController extends AbstractController
{
    private const CSRF = 'mon-espace';

    public function __construct(
        private readonly EspaceEmploye $espace,
        private readonly DemandeEmployeRepository $demandes,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Pointages $pointages, ActionsCaisse $actions, BulletinPaieRepository $bulletins, Primes $primes, ImmobilisationRepository $immobilisations, AccesEnLigne $acces): Response
    {
        $moi = $this->employe();
        $maintenant = new \DateTimeImmutable();
        $debutMois = $maintenant->modify('first day of this month')->setTime(0, 0);
        // Ma performance (N04) et biens confiés : ERP local seulement ; sa propre note, si la tuile Primes le permet.
        $local = !$acces->estEnLigne();
        $performance = $local && null !== $primes->role($moi) ? ($primes->agentVoitNote() ? $primes->fiche($moi, $debutMois, $maintenant) : []) : null;

        return $this->render('mon_espace/index.html.twig', [
            'nom' => Pointages::nom($moi),
            'pointage' => $pointages->mois($moi, $maintenant, $maintenant),
            'actions' => $this->isGranted('ROLE_PAIEMENT_ENCAISSER') ? $actions->bilan($moi, $debutMois, $maintenant) : null,
            'conge' => $this->espace->conge($moi),
            'demandes' => $this->demandes->findBy(['utilisateur' => $moi], ['creeLe' => 'DESC'], 30),
            'bulletins' => $bulletins->visiblesPour($moi),
            'maintenant' => $maintenant,
            'performance' => $performance,
            'biens' => $local ? \count($immobilisations->registre(null, false, $moi)) : 0,
        ]);
    }

    /**
     * Mon pointage : chaque journée du mois (arrivée, départ, heures
     * faites et dues, points et leur raison) et le total. Seulement les
     * siennes : aucun identifiant dans l'adresse, tout part de la
     * personne connectée.
     */
    #[Route('/pointage', name: 'pointage', methods: ['GET'])]
    public function pointage(Request $request, Pointages $pointages): Response
    {
        $moi = $this->employe();
        $maintenant = new \DateTimeImmutable();
        $courant = $maintenant->modify('first day of this month')->setTime(0, 0);
        $mois = \DateTimeImmutable::createFromFormat('!Y-m', (string) $request->query->get('mois')) ?: $courant;
        if ($mois > $courant) {
            $mois = $courant;
        }
        $regles = $pointages->regles();

        return $this->render('mon_espace/pointage.html.twig', [
            'nom' => Pointages::nom($moi),
            'mois' => $mois,
            'suivant' => $mois < $courant ? $mois->modify('+1 month') : null,
            'fiche' => $pointages->mois($moi, $mois, $maintenant),
            'regles' => $regles,
            'horaires' => Pointages::horairesTypes($regles),
        ]);
    }

    #[Route('/demande/{type}', name: 'demande', requirements: ['type' => 'absence|conge|reclamation|frais_medicaux'], methods: ['GET', 'POST'])]
    public function demande(string $type, Request $request): Response
    {
        $moi = $this->employe();
        $maintenant = new \DateTimeImmutable();
        $erreur = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
                $erreur = 'La page a expiré. Réessayez.';
            } else {
                $resultat = $this->espace->deposer($moi, $type, $request->request->all(), $request->files->get('justificatif'), $maintenant);
                if ($resultat instanceof DemandeEmploye) {
                    $this->addFlash('success', \sprintf('Demande « %s » envoyée à la RH : %s.', $resultat->libelleType(), $this->espace->resume($resultat)));

                    return $this->redirectToRoute('app_mon_espace_index');
                }
                $erreur = $resultat;
            }
        }

        return $this->render('mon_espace/demande.html.twig', [
            'type' => $type,
            'libelle' => DemandeEmploye::TYPES[$type],
            'conge' => $this->espace->conge($moi),
            'avantages' => $this->espace->remboursement($moi, 100, $maintenant),
            'erreur' => $erreur,
            'saisie' => $request->request->all(),
            'jeton' => self::CSRF,
        ], new Response(status: $erreur !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** Justificatif : la personne qui l'a déposé, ou la RH. */
    #[Route('/justificatif/{id}', name: 'justificatif', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function justificatif(DemandeEmploye $demande): Response
    {
        if ($demande->getUtilisateur() !== $this->getUser() && !$this->isGranted('ROLE_RH')) {
            throw $this->createAccessDeniedException();
        }
        $chemin = $this->espace->cheminJustificatif($demande);
        if ($chemin === null) {
            throw $this->createNotFoundException();
        }
        $reponse = new BinaryFileResponse($chemin);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'justificatif-'.$demande->getId().'.'.pathinfo($chemin, \PATHINFO_EXTENSION));
        $reponse->setPrivate();

        return $reponse;
    }

    /** Bulletin de paie de l'employé, une fois validé. */
    #[Route('/bulletin/{id}', name: 'bulletin', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function bulletin(BulletinPaie $bulletin): Response
    {
        if ($bulletin->getUtilisateur() !== $this->employe() || $bulletin->getEtat() === BulletinPaie::BROUILLON) {
            throw $this->createNotFoundException();
        }

        return $this->render('mon_espace/bulletin.html.twig', ['b' => $bulletin, 'libelleMois' => Paie::mois($bulletin->getMois())]);
    }

    private function employe(): User
    {
        $moi = $this->getUser();
        if (!$moi instanceof User || $moi->getEmploye() === null) {
            throw $this->createAccessDeniedException('L\'espace employé est réservé au personnel.');
        }

        return $moi;
    }
}
