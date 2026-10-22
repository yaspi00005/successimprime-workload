<?php

namespace App\Controller;

use App\Entity\ActionCaisse;
use App\Entity\User;
use App\Repository\ActionCaisseRepository;
use App\Repository\UserRepository;
use App\Service\ActionsCaisse\ActionsCaisse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Actions commerciales côté responsable (maquettes AC03 et AC04) : règles,
 * journal du mois par personne, contrôle au hasard, lignes manuelles.
 */
#[Route('/equipe/actions', name: 'app_actions_equipe_')]
#[IsGranted('ROLE_ACTIONS_GERER')]
final class ActionsEquipeController extends AbstractController
{
    private const CSRF = 'actions-equipe';
    private const NOMBRE_CONTROLE = 5;

    public function __construct(
        private readonly ActionsCaisse $actions,
        private readonly ActionCaisseRepository $depot,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'journal', methods: ['GET'])]
    public function journal(Request $request, UserRepository $utilisateurs): Response
    {
        $maintenant = new \DateTimeImmutable();
        $this->actions->actualiser($maintenant);
        [$debut, $fin] = self::mois((string) $request->query->get('mois'), $maintenant);

        $lignes = $this->depot->duMois($debut, $fin);
        $personnes = $this->actions->caissiers();
        foreach ($lignes as $a) {
            if ($a->getAssigneA() !== null && !\in_array($a->getAssigneA(), $personnes, true)) {
                $personnes[] = $a->getAssigneA();
            }
        }
        $cartes = array_map(fn (User $u): array => ['personne' => $u, 'bilan' => $this->actions->bilan($u, $debut, min($fin, $maintenant))], $personnes);
        $choisie = $utilisateurs->find((int) $request->query->get('personne')) ?? ($personnes[0] ?? null);

        return $this->render('actions_caisse/journal.html.twig', [
            'debut' => $debut,
            'cartes' => $cartes,
            'choisie' => $choisie,
            'lignes' => array_map(fn (ActionCaisse $a): array => ['action' => $a, 'ecoule' => $this->actions->heures()->ecoule($a->getDepartLe(), $a->getFaiteLe() ?? $maintenant)], array_values(array_filter($lignes, static fn (ActionCaisse $a): bool => $a->getAssigneA() === $choisie && ($a->getEtat() !== ActionCaisse::SANS_OBJET || $a->getPenalite() > 0)))),
            'sansPersonne' => array_values(array_filter($lignes, static fn (ActionCaisse $a): bool => $a->getAssigneA() === null && $a->getEtat() === ActionCaisse::A_FAIRE)),
            'maintenant' => $maintenant,
            'regles' => $this->actions->regles(),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/regles', name: 'regles', methods: ['GET'])]
    public function regles(): Response
    {
        $heures = $this->actions->heures();
        $regles = $this->actions->regles();
        // Exemple de la maquette : une ligne créée samedi à 17:00.
        $samedi = new \DateTimeImmutable('saturday this week 17:00');
        $echeance = $heures->ajouter($samedi, (int) $regles['delai_heures'] * 60);
        $jours = [];
        for ($j = $samedi->setTime(0, 0); $j <= $echeance->setTime(0, 0); $j = $j->modify('+1 day')) {
            $jours[] = ['jour' => $j, 'minutes' => $heures->ecoule(max($samedi, $j), min($echeance, $j->modify('+1 day')))];
        }

        return $this->render('actions_caisse/regles.html.twig', [
            'regles' => $regles,
            'exemple' => ['debut' => $samedi, 'echeance' => $echeance, 'jours' => $jours],
            'personnes' => $this->actions->caissiers(),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/nouvelle', name: 'nouvelle', methods: ['POST'])]
    public function nouvelle(Request $request, UserRepository $utilisateurs): Response
    {
        $this->verifierJeton($request);
        $libelle = trim((string) $request->request->get('libelle'));
        if (!$this->actions->regles()['source_manuelle']) {
            $this->addFlash('warning', 'Les lignes manuelles sont désactivées dans Paramètres › Relances et délais.');
        } elseif ($libelle === '') {
            $this->addFlash('danger', 'Écrivez l\'action, par exemple « Prospecter 5 écoles cette semaine ».');
        } else {
            $maintenant = new \DateTimeImmutable();
            $pour = $utilisateurs->find((int) $request->request->get('pour'));
            $action = $this->actions->nouvelle('manuelle', 'manuelle:'.bin2hex(random_bytes(6)), $libelle, $maintenant, $maintenant, $pour instanceof User ? $pour : null)
                ->setDetail(trim((string) $request->request->get('detail')) ?: null);
            $action->setCreePar($this->getUser() instanceof User ? $this->getUser() : null);
            $this->em->flush();
            $this->addFlash('success', \sprintf('Ligne créée pour %s, à faire avant le %s.', ActionsCaisse::nom($action->getAssigneA()), $action->getEcheance()->format('d/m H:i')));
        }

        return $this->redirectToRoute('app_actions_equipe_regles');
    }

    /** Quelques lignes faites ce mois, prises au hasard, pas encore contrôlées. */
    #[Route('/controle', name: 'controle', methods: ['GET'])]
    public function controle(): Response
    {
        $maintenant = new \DateTimeImmutable();
        $faites = array_values(array_filter(
            $this->depot->faitesEntre($maintenant->modify('first day of this month')->setTime(0, 0), $maintenant),
            static fn (ActionCaisse $a): bool => $a->getControleLe() === null,
        ));
        shuffle($faites);

        return $this->render('actions_caisse/controle.html.twig', [
            'lignes' => \array_slice($faites, 0, self::NOMBRE_CONTROLE),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/{id}/controler', name: 'controler', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function controler(ActionCaisse $action, Request $request): Response
    {
        $this->verifierJeton($request);
        $annuler = 'annuler' === $request->request->get('decision');
        $motif = trim((string) $request->request->get('motif'));
        if ($annuler && mb_strlen($motif) < 5) {
            $this->addFlash('danger', 'Le motif est obligatoire pour annuler des points : la personne le verra.');

            return $this->redirectToRoute('app_actions_equipe_controle');
        }
        /** @var User $moi */
        $moi = $this->getUser();
        $this->actions->controler($action, $moi, $annuler, $motif !== '' ? mb_substr($motif, 0, 255) : null, new \DateTimeImmutable());
        $this->addFlash('success', $annuler ? 'Points annulés, motif enregistré.' : 'Ligne vérifiée.');

        return $this->redirectToRoute('app_actions_equipe_controle');
    }

    #[Route('/export', name: 'export', methods: ['GET'])]
    public function export(Request $request): StreamedResponse
    {
        $maintenant = new \DateTimeImmutable();
        [$debut, $fin] = self::mois((string) $request->query->get('mois'), $maintenant);
        $lignes = $this->depot->duMois($debut, $fin);
        $heures = $this->actions->heures();
        $montants = $this->isGranted('ROLE_PAIEMENT_VOIR');

        $reponse = new StreamedResponse(static function () use ($lignes, $heures, $maintenant, $montants): void {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Créée', 'Personne', 'Type', 'Client', 'Détail', 'Montant', 'Échéance', 'Faite le', 'Temps de travail (min)', 'Résultat', 'Note', 'Preuve', 'Points', 'Pénalité', 'Contrôle'], ';', '"', '');
            foreach ($lignes as $a) {
                fputcsv($f, [
                    $a->getCreeLe()->format('d/m/Y H:i'),
                    ActionsCaisse::nom($a->getAssigneA()),
                    $a->libelleType(),
                    $a->getLibelle(),
                    $a->getDetail(),
                    $montants ? $a->getMontant() : '',
                    $a->getEcheance()->format('d/m/Y H:i'),
                    $a->getFaiteLe()?->format('d/m/Y H:i') ?? ($a->getEtat() === ActionCaisse::A_FAIRE ? 'à faire' : ''),
                    $heures->ecoule($a->getDepartLe(), $a->getFaiteLe() ?? $maintenant),
                    $a->libelleResultat(),
                    $a->getNote(),
                    $a->preuve(),
                    $a->isPointsAnnules() ? 0 : $a->getPoints(),
                    -$a->getPenalite(),
                    $a->getControleLe() ? ($a->isPointsAnnules() ? 'points annulés : '.$a->getMotifControle() : 'vérifiée') : '',
                ], ';', '"', '');
            }
            fclose($f);
        });
        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', \sprintf('attachment; filename="actions-%s.csv"', $debut->format('Y-m')));

        return $reponse;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private static function mois(string $saisie, \DateTimeImmutable $maintenant): array
    {
        $debut = \DateTimeImmutable::createFromFormat('!Y-m', $saisie) ?: $maintenant->modify('first day of this month')->setTime(0, 0);

        return [$debut, $debut->modify('+1 month')];
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }
}
