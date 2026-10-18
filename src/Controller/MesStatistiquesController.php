<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AccesEnLigne;
use App\Service\Finances\Periode;
use App\Service\Http\Entree;
use App\Service\MesStatistiques;
use App\Service\Statistiques\Export\ExportExcel;
use App\Service\Statistiques\Export\ExportPdf;
use App\Service\Statistiques\Export\ExportsStatistiques;
use App\Service\Statistiques\Objectifs;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Accueil · Mes statistiques (maquette U05). L'administrateur peut
 * ouvrir celles d'une autre personne depuis sa fiche (?utilisateur=).
 * Les chiffres de l'entreprise en bas ne s'affichent qu'avec le droit
 * correspondant ; sinon le bloc dit « Masqué ». Sur l'ERP local, les
 * objectifs du mois de la personne (CA facturé, commandes) avec leur
 * projection, et l'export Excel / PDF de ce que la page montre.
 */
#[IsGranted('ROLE_STATS_PERSO')]
final class MesStatistiquesController extends AbstractController
{
    use ExporterStatistiques;

    public function __construct(
        private readonly MesStatistiques $statistiques,
        private readonly UserRepository $users,
        private readonly Objectifs $objectifs,
        private readonly AccesEnLigne $acces,
    ) {
    }

    #[Route('/mes-statistiques', name: 'app_mes_statistiques', methods: ['GET'])]
    public function index(Request $request): Response
    {
        return $this->render('mes_statistiques/index.html.twig', $this->donnees($request));
    }

    #[Route('/mes-statistiques/export.{format}', name: 'app_mes_statistiques_export', methods: ['GET'], requirements: ['format' => 'xlsx|pdf'])]
    public function exporter(string $format, Request $request, ExportExcel $excel, ExportPdf $pdf, EntityManagerInterface $em): Response
    {
        $d = $this->donnees($request);

        return $this->reponseExport(ExportsStatistiques::mesStatistiques($d, $d['nom'], Periode::libelle($d['mois'])), $format, $excel, $pdf, $em);
    }

    /**
     * @return array<string, mixed>
     */
    private function donnees(Request $request): array
    {
        $agent = $this->getUser();

        if (Entree::entier($request->query, 'utilisateur') > 0 && $this->isGranted('ROLE_ADMIN')) {
            $agent = $this->users->find(Entree::entier($request->query, 'utilisateur')) ?? throw $this->createNotFoundException();
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

        // Objectifs : tables locales ; ses propres objectifs seulement (axe agent).
        $local = !$this->acces->estEnLigne();
        $objectifs = $local ? $this->objectifs->tableau($mois, $this->droitsStatistiques(), $maintenant, $agent) : [];
        $objectifCa = array_values(array_filter($objectifs, static fn (array $l): bool => 'ca' === $l['indicateur']))[0] ?? null;

        return [
            'agent' => $agent,
            'nom' => UtilisateursController::nomComplet($agent),
            'autre' => $agent !== $this->getUser(),
            'mois' => $mois,
            'moisPossibles' => $moisPossibles,
            's' => $this->statistiques->pour($agent, $mois, $maintenant),
            'objectifs' => $objectifs,
            'objectif_ca' => $objectifCa,
            'local' => $local,
            'chiffreAffaires' => $this->isGranted('ROLE_STATS_GLOBAL') && $this->isGranted('ROLE_STATS_VENTES') ? $this->statistiques->chiffreAffairesGlobal($maintenant) : null,
            'marge' => $this->isGranted('ROLE_COUT_MARGE') ? $this->statistiques->margeDuMois($mois) : null,
            'tresorerie' => $this->isGranted('ROLE_TRESORERIE_VOIR') ? $this->statistiques->tresorerie() : null,
        ];
    }
}
