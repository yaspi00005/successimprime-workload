<?php

namespace App\Controller;

use App\Entity\CongeEmploye;
use App\Entity\DemandeEmploye;
use App\Entity\User;
use App\Repository\CompteTresorerieRepository;
use App\Repository\DemandeEmployeRepository;
use App\Repository\UserRepository;
use App\Service\EspaceEmploye\EspaceEmploye;
use App\Service\Pointage\Pointages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Demandes du personnel côté RH : réponses aux absences, congés,
 * réclamations et frais médicaux, droits aux congés, calendrier des
 * absences, paiement des sommes accordées.
 */
#[Route('/equipe/demandes', name: 'app_demandes_')]
#[IsGranted('ROLE_RH')]
final class DemandesEmployesController extends AbstractController
{
    private const CSRF = 'demandes-rh';

    public function __construct(
        private readonly EspaceEmploye $espace,
        private readonly DemandeEmployeRepository $demandes,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request, CompteTresorerieRepository $comptes): Response
    {
        $maintenant = new \DateTimeImmutable();
        $mois = \DateTimeImmutable::createFromFormat('!Y-m', (string) $request->query->get('mois')) ?: $maintenant->modify('first day of this month')->setTime(0, 0);

        $enAttente = $this->demandes->findBy(['etat' => DemandeEmploye::EN_ATTENTE], ['creeLe' => 'ASC']);
        $aPayer = array_values(array_filter($this->demandes->findBy(['etat' => DemandeEmploye::ACCEPTEE], ['decideLe' => 'ASC']), static fn (DemandeEmploye $d): bool => $d->estAPayer()));
        $traitees = $this->demandes->traiteesEntre($mois, $mois->modify('+1 month'));

        return $this->render('demandes_employes/index.html.twig', [
            'enAttente' => array_map(fn (DemandeEmploye $d): array => ['demande' => $d, 'conge' => $d->getType() === DemandeEmploye::CONGE ? $this->espace->conge($d->getUtilisateur()) : null, 'remboursement' => $d->getType() === DemandeEmploye::FRAIS_MEDICAUX ? $this->espace->remboursement($d->getUtilisateur(), (int) $d->getMontant(), $maintenant) : null], $enAttente),
            'aPayer' => $aPayer,
            'traitees' => $traitees,
            'calendrier' => $this->calendrier($mois),
            'mois' => $mois,
            'comptes' => $this->isGranted('ROLE_TRESORERIE_SAISIR') ? $comptes->findActifs() : [],
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/{id}/decider', name: 'decider', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function decider(DemandeEmploye $demande, Request $request): Response
    {
        $this->verifierJeton($request);
        $montant = preg_replace('/\D+/', '', (string) $request->request->get('montant'));
        $erreur = $this->espace->decider(
            $demande,
            'accepter' === $request->request->get('decision'),
            $montant !== '' ? (int) $montant : null,
            (string) $request->request->get('commentaire'),
            $this->moi(),
            new \DateTimeImmutable(),
        );
        $erreur !== null
            ? $this->addFlash('danger', $erreur)
            : $this->addFlash('success', \sprintf('Demande « %s » (%s) : %s.', $demande->libelleType(), $demande->getUtilisateur()->getEmploye()?->getPrenom() ?? $demande->getUtilisateur()->getUsername(), mb_strtolower($demande->libelleEtat())));

        return $this->redirectToRoute('app_demandes_index');
    }

    #[Route('/{id}/payer', name: 'payer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_TRESORERIE_SAISIR')]
    public function payer(DemandeEmploye $demande, Request $request, CompteTresorerieRepository $comptes): Response
    {
        $this->verifierJeton($request);
        $compte = $comptes->find((int) $request->request->get('compte'));
        $erreur = $compte === null ? 'Choisissez le compte qui paie.' : $this->espace->payer($demande, $compte, $this->moi());
        $erreur !== null ? $this->addFlash('danger', $erreur) : $this->addFlash('success', 'Paiement enregistré en trésorerie (confidentiel).');

        return $this->redirectToRoute('app_demandes_index');
    }

    #[Route('/conges', name: 'conges', methods: ['GET'])]
    public function conges(UserRepository $utilisateurs): Response
    {
        $personnel = array_values(array_filter($utilisateurs->findBy(['actif' => true]), static fn (User $u): bool => $u->getEmploye() !== null));

        return $this->render('demandes_employes/conges.html.twig', [
            'lignes' => array_map(fn (User $u): array => ['utilisateur' => $u, 'conge' => $this->espace->conge($u)], $personnel),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/conges/{id}', name: 'conge_regler', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reglerConge(User $utilisateur, Request $request): Response
    {
        $this->verifierJeton($request);
        $date = static fn (string $v): ?\DateTimeImmutable => \DateTimeImmutable::createFromFormat('!Y-m-d', $v) ?: null;
        $nombre = static fn (string $v): ?float => is_numeric(str_replace(',', '.', $v)) ? (float) str_replace(',', '.', $v) : null;
        $conge = $this->espace->conge($utilisateur);
        $conge->regler(
            $request->request->getBoolean('actif'),
            $nombre((string) $request->request->get('solde')) ?? 0,
            $date((string) $request->request->get('plage_debut')),
            $date((string) $request->request->get('plage_fin')),
            $nombre((string) $request->request->get('plage_jours')),
        );
        $this->em->persist($conge);
        $this->em->flush();
        $this->addFlash('success', \sprintf('Congés de %s enregistrés : %s, solde %s j.', $utilisateur->getEmploye()?->getPrenom() ?? $utilisateur->getUsername(), $conge->isActif() ? 'droit activé' : 'droit désactivé', CongeEmploye::nombre($conge->getSolde())));

        return $this->redirectToRoute('app_demandes_conges');
    }

    /**
     * Qui est absent ou en congé, jour par jour (demandes acceptées).
     *
     * @return array{jours: list<\DateTimeImmutable>, lignes: list<array{nom: string, jours: array<string, string>}>}
     */
    private function calendrier(\DateTimeImmutable $mois): array
    {
        $fin = $mois->modify('+1 month');
        $jours = [];
        for ($j = $mois; $j < $fin; $j = $j->modify('+1 day')) {
            $jours[] = $j;
        }
        $lignes = [];
        foreach ($this->demandes->absencesEntre($mois, $fin) as $d) {
            $cle = (string) $d->getUtilisateur()->getId();
            $lignes[$cle] ??= ['nom' => Pointages::nom($d->getUtilisateur()), 'jours' => []];
            for ($j = $d->getDu(); $j <= ($d->getAu() ?? $d->getDu()); $j = $j->modify('+1 day')) {
                if ($this->espace->joursOuvres($j, $j) > 0) {
                    $lignes[$cle]['jours'][$j->format('Y-m-d')] = $d->getType();
                }
            }
        }

        return ['jours' => $jours, 'lignes' => array_values($lignes)];
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
