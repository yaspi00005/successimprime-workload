<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\MesStatistiques;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Accueil · Mes statistiques (maquette U05). L'administrateur peut
 * ouvrir celles d'une autre personne depuis sa fiche (?utilisateur=).
 * Les chiffres de l'entreprise en bas ne s'affichent qu'avec le droit
 * correspondant ; sinon le bloc dit « Masqué ».
 */
#[IsGranted('ROLE_STATS_PERSO')]
final class MesStatistiquesController extends AbstractController
{
    #[Route('/mes-statistiques', name: 'app_mes_statistiques', methods: ['GET'])]
    public function index(Request $request, MesStatistiques $statistiques, UserRepository $users): Response
    {
        $agent = $this->getUser();

        if ($request->query->getInt('utilisateur') > 0 && $this->isGranted('ROLE_ADMIN')) {
            $agent = $users->find($request->query->getInt('utilisateur')) ?? throw $this->createNotFoundException();
        }

        if (!$agent instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $maintenant = new \DateTimeImmutable();
        $moisPossibles = [];

        for ($i = 0; $i < 12; ++$i) {
            $mois = $maintenant->modify('first day of this month')->setTime(0, 0)->modify(sprintf('-%d months', $i));
            $moisPossibles[$mois->format('Y-m')] = $mois;
        }

        $cle = $request->query->getString('mois');
        $mois = $moisPossibles[$cle] ?? reset($moisPossibles);

        return $this->render('mes_statistiques/index.html.twig', [
            'agent' => $agent,
            'nom' => UtilisateursController::nomComplet($agent),
            'autre' => $agent !== $this->getUser(),
            'mois' => $mois,
            'moisPossibles' => $moisPossibles,
            's' => $statistiques->pour($agent, $mois, $maintenant),
            'chiffreAffaires' => $this->isGranted('ROLE_STATS_GLOBAL') ? $statistiques->chiffreAffairesGlobal($maintenant) : null,
            'marge' => $this->isGranted('ROLE_COUT_MARGE') ? $statistiques->margeDuMois($mois) : null,
            'tresorerie' => $this->isGranted('ROLE_TRESORERIE_VOIR') ? $statistiques->tresorerie() : null,
        ]);
    }
}
