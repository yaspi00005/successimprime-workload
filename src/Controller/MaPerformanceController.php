<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Primes\CalculPrime;
use App\Service\Primes\Primes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Mon espace › Ma performance et Ma prime (maquette N04, pensée pour le
 * téléphone). Chacun ne voit que SA note et SA prime : aucun identifiant
 * dans l'adresse, tout part de l'utilisateur connecté. Le score du mois en
 * cours n'apparaît que si la tuile Primes le permet (agent_voit_note) ;
 * les mois validés, toujours. ERP local seulement.
 */
#[Route('/mon-espace', name: 'app_mon_espace_')]
final class MaPerformanceController extends AbstractController
{
    public function __construct(private readonly Primes $primes)
    {
    }

    #[Route('/performance', name: 'performance', methods: ['GET'])]
    public function performance(): Response
    {
        $moi = $this->employe();
        $maintenant = new \DateTimeImmutable();
        $fiche = $this->ficheVisible($moi, $maintenant);

        return $this->render('primes/ma_performance.html.twig', [
            'fiche' => $fiche,
            'palier' => CalculPrime::libellePalier($fiche['resultat']['score'] ?? null),
            'conseil' => null !== $fiche ? self::conseil($fiche) : null,
            'voit_note' => $this->primes->agentVoitNote(),
            'a_un_role' => null !== $this->primes->role($moi),
        ]);
    }

    #[Route('/prime', name: 'prime', methods: ['GET'])]
    public function prime(): Response
    {
        $moi = $this->employe();
        $maintenant = new \DateTimeImmutable();
        $fiche = $this->ficheVisible($moi, $maintenant);

        return $this->render('primes/ma_prime.html.twig', [
            'fiche' => $fiche,
            'paliers' => CalculPrime::paliers(),
            'historique' => array_slice($this->primes->historique($moi, 12), 0, 12, true),
            'voit_note' => $this->primes->agentVoitNote(),
            'a_un_role' => null !== $this->primes->role($moi),
        ]);
    }

    /**
     * Évaluation montrée à l'agent : le mois en cours (estimation) si la
     * tuile le permet ; sinon, le mois précédent une fois validé.
     *
     * @return array<string, mixed>|null
     */
    private function ficheVisible(User $moi, \DateTimeImmutable $maintenant): ?array
    {
        $courant = $maintenant->modify('first day of this month')->setTime(0, 0);
        if ($this->primes->agentVoitNote()) {
            $fiche = $this->primes->fiche($moi, $courant, $maintenant);
            if (null !== $fiche) {
                return $fiche + ['enCours' => true];
            }
        }
        $precedent = $this->primes->fiche($moi, $courant->modify('-1 month'), $maintenant);
        if (null !== $precedent && $precedent['fige']) {
            return $precedent + ['enCours' => false];
        }

        return null;
    }

    /**
     * Petit conseil : ce qu'il reste à vendre pour l'objectif, sinon le
     * critère le plus faible du mois.
     *
     * @param array<string, mixed> $fiche
     */
    public static function conseil(array $fiche): ?string
    {
        if (!$fiche['enCours']) {
            return null;
        }
        $reste = $fiche['mesures']['ventes']['reste'] ?? 0;
        if ($reste > 0) {
            return sprintf('Conseil : encore %s F de ventes pour atteindre 100 %% de l\'objectif.', number_format($reste, 0, ',', ' '));
        }
        $faible = null;
        foreach ($fiche['resultat']['criteres'] as $c) {
            if (null !== $c['note'] && !$c['manuel'] && $c['note'] < 90 && (null === $faible || $c['note'] < $faible['note'])) {
                $faible = $c;
            }
        }

        return null !== $faible ? sprintf('Conseil : « %s » est votre point à travailler ce mois-ci (%d / 100).', $faible['court'], $faible['note']) : null;
    }

    private function employe(): User
    {
        $moi = $this->getUser();
        if (!$moi instanceof User || null === $moi->getEmploye()) {
            throw $this->createAccessDeniedException('L\'espace employé est réservé au personnel.');
        }

        return $moi;
    }
}
