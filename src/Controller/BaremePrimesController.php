<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Primes\CalculPrime;
use App\Service\Primes\Primes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Barème des primes par rôle (maquette N03) : critères, source des
 * données, poids (total 100 %), objectifs et prime cible du rôle.
 * Direction seulement ; ERP local seulement (PatrimoinePrimesHorsLigneSubscriber).
 * Les évaluations déjà validées gardent le barème de leur photo.
 */
#[IsGranted('ROLE_ADMIN')]
final class BaremePrimesController extends AbstractController
{
    private const CSRF = 'bareme-primes';

    #[Route('/equipe/bareme/primes', name: 'app_bareme_primes', methods: ['GET', 'POST'])]
    public function index(Request $request, Primes $primes): Response
    {
        $role = (string) $request->query->get('role', array_key_first(CalculPrime::ROLES));
        if (!isset(CalculPrime::ROLES[$role])) {
            $role = (string) array_key_first(CalculPrime::ROLES);
        }
        $bareme = $primes->bareme($role);
        $saisie = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }
            $saisie = self::lireSaisie($request->request->all());
            $par = $this->getUser();
            $erreur = $par instanceof User ? $primes->enregistrerBareme($role, $saisie['prime'], $saisie['criteres'], $par, new \DateTimeImmutable()) : 'Session expirée.';
            if (null === $erreur) {
                $this->addFlash('success', sprintf('Barème « %s » enregistré. Il vaut pour les évaluations en brouillon ; les évaluations validées gardent leur photo.', CalculPrime::ROLES[$role]));

                return $this->redirectToRoute('app_bareme_primes', ['role' => $role]);
            }
            $this->addFlash('error', $erreur);
        }

        $poids = [];
        $objectifs = [];
        foreach ($saisie['criteres'] ?? $bareme['criteres'] as $c) {
            $poids[$c['cle']] = $c['poids'];
            $objectifs[$c['cle']] = $c['objectif'];
        }
        // Ordre d'affichage : celui du barème, puis les critères disponibles.
        $ordre = array_values(array_unique([...array_keys($poids), ...array_keys(CalculPrime::CRITERES)]));

        return $this->render('primes/bareme.html.twig', [
            'role' => $role,
            'roles' => CalculPrime::ROLES,
            'criteres' => CalculPrime::CRITERES,
            'ordre' => $ordre,
            'prime' => $saisie['prime'] ?? $bareme['prime'],
            'poids' => $poids,
            'objectifs' => $objectifs,
            'modifie' => $bareme['modifie'],
            'paliers' => CalculPrime::paliers(),
            'jeton' => self::CSRF,
            'agent_voit_note' => $primes->agentVoitNote(),
            'agents' => array_values(array_filter($primes->agentsVisibles($this->moi()), static fn (User $u): bool => $primes->role($u) === $role)),
        ]);
    }

    /**
     * Lit le formulaire : prime cible et, par critère, poids et objectif.
     *
     * @param array<string, mixed> $donnees
     *
     * @return array{prime: int, criteres: list<array{cle: string, poids: int, objectif: int|float|null}>}
     */
    public static function lireSaisie(array $donnees): array
    {
        $criteres = [];
        $saisis = \is_array($donnees['criteres'] ?? null) ? $donnees['criteres'] : [];
        // Dans l'ordre du formulaire (celui du barème), critères connus seulement.
        foreach (array_keys($saisis) as $cle) {
            $critere = CalculPrime::CRITERES[$cle] ?? null;
            if (null === $critere) {
                continue;
            }
            $ligne = \is_array($saisis[$cle]) ? $saisis[$cle] : [];
            $poids = (int) preg_replace('/\D/', '', (string) ($ligne['poids'] ?? ''));
            if ($poids <= 0) {
                continue;
            }
            $objectif = null;
            if (null !== $critere['objectif']) {
                $texte = str_replace([' ', "\u{202F}", "\u{A0}", ','], ['', '', '', '.'], (string) ($ligne['objectif'] ?? ''));
                $objectif = is_numeric($texte) ? 0 + $texte : $critere['objectif'];
            }
            $criteres[] = ['cle' => $cle, 'poids' => min(100, $poids), 'objectif' => $objectif];
        }

        return ['prime' => (int) preg_replace('/\D/', '', (string) ($donnees['prime'] ?? '0')), 'criteres' => $criteres];
    }

    private function moi(): User
    {
        $u = $this->getUser();
        if (!$u instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $u;
    }
}
