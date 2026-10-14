<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\EvaluationPrimeRepository;
use App\Security\PrimesVoter;
use App\Service\EspaceEmploye\Paie;
use App\Service\Pointage\Pointages;
use App\Service\Primes\CalculPrime;
use App\Service\Primes\Primes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Équipe › Performance et primes (maquettes N01 et N02,
 * docs/immobilisations-primes.md) : vue d'ensemble du mois, évaluation
 * d'un agent, appréciation, validation, recalcul en nouvelle version,
 * validation des primes par la direction et export pour la paie.
 *
 * Le responsable (fiche employé) voit et évalue son équipe, l'admin tout
 * le monde (PrimesVoter) ; l'agent voit sa note dans Mon espace (N04).
 * ERP local seulement (PatrimoinePrimesHorsLigneSubscriber).
 */
#[Route('/equipe/primes', name: 'app_primes_')]
#[IsGranted(PrimesVoter::EQUIPE)]
final class PrimesController extends AbstractController
{
    public function __construct(private readonly Primes $primes)
    {
    }

    /** Vue d'ensemble du mois (N01). */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $mois = self::mois((string) $request->query->get('mois'), $this->primes->moisParDefaut($maintenant));

        return $this->render('primes/index.html.twig', $this->primes->equipe($this->moi(), $mois, $maintenant) + [
            'paliers' => CalculPrime::paliers(),
            'roles' => CalculPrime::ROLES,
            'courant' => $mois->format('Y-m') === $maintenant->format('Y-m'),
        ]);
    }

    /** Évaluation d'un agent pour un mois (N02). */
    #[Route('/agent/{id}', name: 'agent', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function agent(Request $request, User $agent): Response
    {
        $this->denyAccessUnlessGranted(PrimesVoter::AGENT, $agent);
        $maintenant = new \DateTimeImmutable();
        $mois = self::mois((string) $request->query->get('mois'), $this->primes->moisParDefaut($maintenant));
        $fiche = $this->primes->fiche($agent, $mois, $maintenant);

        $historique = $this->primes->historique($agent, 12);
        $six = [];
        for ($k = 5; $k >= 0; --$k) {
            $m = $mois->modify(sprintf('-%d months', $k));
            $six[] = ['mois' => $m, 'score' => 0 === $k ? $fiche['resultat']['score'] ?? null : ($historique[$m->format('Y-m')] ?? null)?->getScore()];
        }

        return $this->render('primes/agent.html.twig', [
            'agent' => $agent,
            'nom' => Pointages::nom($agent),
            'mois' => $mois,
            'fiche' => $fiche,
            'six' => $six,
            'roles' => CalculPrime::ROLES,
            'reglage' => $this->primes->reglage($agent),
            'role_profil' => Primes::roleDuProfil($agent),
            'termine' => $mois < $maintenant->modify('first day of this month')->setTime(0, 0),
            'palier' => CalculPrime::libellePalier($fiche['resultat']['score'] ?? null),
            'historique' => $historique,
            'prime_bareme' => null !== $fiche ? $this->primes->bareme($fiche['role'])['prime'] : null,
            'appreciation_obligatoire' => $this->primes->appreciationObligatoire(),
        ]);
    }

    /** Appréciation du responsable (et suspension de la prime) sur le brouillon du mois. */
    #[Route('/agent/{id}/appreciation', name: 'appreciation', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function appreciation(Request $request, User $agent): Response
    {
        $this->denyAccessUnlessGranted(PrimesVoter::AGENT, $agent);
        $this->verifierJeton($request, 'prime-'.$agent->getId());
        $mois = self::mois((string) $request->request->get('mois'), new \DateTimeImmutable());
        $note = trim((string) $request->request->get('note'));
        try {
            $this->primes->apprecier(
                $agent,
                $mois,
                '' !== $note && ctype_digit($note) ? (int) $note : null,
                (string) $request->request->get('texte'),
                $request->request->getBoolean('suspendue'),
                (string) $request->request->get('motif_suspension'),
                $this->moi(),
                new \DateTimeImmutable(),
            );
            $this->addFlash('success', 'Appréciation enregistrée.');
        } catch (\DomainException|\LogicException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_primes_agent', ['id' => $agent->getId(), 'mois' => $mois->format('Y-m')]);
    }

    /** « Valider l'évaluation » : la photo des critères est figée. */
    #[Route('/agent/{id}/valider', name: 'valider', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function valider(Request $request, User $agent): Response
    {
        $this->denyAccessUnlessGranted(PrimesVoter::AGENT, $agent);
        $this->verifierJeton($request, 'prime-'.$agent->getId());
        $mois = self::mois((string) $request->request->get('mois'), new \DateTimeImmutable());
        $erreur = $this->primes->valider($agent, $mois, $this->moi(), new \DateTimeImmutable());
        $this->addFlash(null === $erreur ? 'success' : 'error', $erreur ?? sprintf('Évaluation de %s validée pour %s.', Pointages::nom($agent), Paie::mois($mois)));

        return $this->redirectToRoute('app_primes_agent', ['id' => $agent->getId(), 'mois' => $mois->format('Y-m')]);
    }

    /** Recalcul d'une évaluation validée : nouvelle version, avec son motif. */
    #[Route('/agent/{id}/recalculer', name: 'recalculer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function recalculer(Request $request, User $agent): Response
    {
        $this->denyAccessUnlessGranted(PrimesVoter::AGENT, $agent);
        $this->verifierJeton($request, 'prime-'.$agent->getId());
        $mois = self::mois((string) $request->request->get('mois'), new \DateTimeImmutable());
        $erreur = $this->primes->recalculer($agent, $mois, (string) $request->request->get('motif'), $this->moi(), new \DateTimeImmutable());
        $this->addFlash(null === $erreur ? 'success' : 'error', $erreur ?? 'Nouvelle version créée : vérifiez-la puis validez-la.');

        return $this->redirectToRoute('app_primes_agent', ['id' => $agent->getId(), 'mois' => $mois->format('Y-m')]);
    }

    /** Rôle de prime et prime cible propres à la personne (direction). */
    #[Route('/agent/{id}/reglage', name: 'reglage', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reglage(Request $request, User $agent): Response
    {
        $this->verifierJeton($request, 'prime-'.$agent->getId());
        $cible = preg_replace('/\D/', '', (string) $request->request->get('prime_cible'));
        $this->primes->regler($agent, (string) $request->request->get('role') ?: null, '' !== $cible ? (int) $cible : null, $this->moi(), new \DateTimeImmutable());
        $this->addFlash('success', 'Réglage de prime de '.Pointages::nom($agent).' enregistré.');

        return $this->redirectToRoute('app_primes_agent', ['id' => $agent->getId(), 'mois' => (string) $request->request->get('mois')]);
    }

    /** « Valider les primes » : la direction valide les primes du mois avant la paie. */
    #[Route('/valider-primes', name: 'valider_primes', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function validerPrimes(Request $request): Response
    {
        $this->verifierJeton($request, 'valider-primes');
        $mois = self::mois((string) $request->request->get('mois'), new \DateTimeImmutable());
        $r = $this->primes->validerPrimes($mois, $this->moi(), new \DateTimeImmutable());
        $this->addFlash('success', sprintf('%d prime%s validée%s pour %s ; %d ligne%s ajoutée%s aux bulletins en brouillon. Rien n\'est versé automatiquement.', $r['validees'], $r['validees'] > 1 ? 's' : '', $r['validees'] > 1 ? 's' : '', Paie::mois($mois), $r['reportees'], $r['reportees'] > 1 ? 's' : '', $r['reportees'] > 1 ? 's' : ''));

        return $this->redirectToRoute('app_primes_index', ['mois' => $mois->format('Y-m')]);
    }

    /** Primes du mois pour la paie, en CSV (séparateur « ; »). */
    #[Route('/export-paie', name: 'export', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function export(Request $request, EvaluationPrimeRepository $evaluations): Response
    {
        $maintenant = new \DateTimeImmutable();
        $mois = self::mois((string) $request->query->get('mois'), $this->primes->moisParDefaut($maintenant));
        $equipe = $this->primes->equipe($this->moi(), $mois, $maintenant);
        $roles = CalculPrime::ROLES;

        $reponse = new StreamedResponse(static function () use ($equipe, $roles): void {
            $sortie = fopen('php://output', 'w');
            fwrite($sortie, "\xEF\xBB\xBF");
            fputcsv($sortie, ['Agent', 'Rôle', 'Score', 'Taux', 'Prime cible', 'Prime', 'Évaluation', 'Prime validée le', 'Reportée sur le bulletin'], ';', '"', '');
            foreach ($equipe['lignes'] as $l) {
                $vigueur = $l['enVigueur'];
                fputcsv($sortie, [
                    Pointages::nom($l['agent']), $roles[$l['role']] ?? $l['role'], $l['resultat']['score'] ?? '', $l['resultat']['taux'].' %',
                    $l['resultat']['primeCible'], $l['resultat']['prime'], $l['statut'][0],
                    $vigueur?->getPrimeValideeLe()?->format('d/m/Y') ?? '', null !== $vigueur?->getBulletin() ? Paie::mois($vigueur->getBulletin()->getMois()) : '',
                ], ';', '"', '');
            }
            fclose($sortie);
        });
        $reponse->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $reponse->headers->set('Content-Disposition', sprintf('attachment; filename="primes-%s.csv"', $mois->format('Y-m')));

        return $reponse;
    }

    private function moi(): User
    {
        $u = $this->getUser();
        if (!$u instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $u;
    }

    private function verifierJeton(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    public static function mois(string $saisie, \DateTimeImmutable $defaut): \DateTimeImmutable
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $saisie) ? new \DateTimeImmutable($saisie.'-01') : $defaut->modify('first day of this month')->setTime(0, 0);
    }
}
