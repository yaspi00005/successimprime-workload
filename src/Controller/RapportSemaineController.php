<?php

namespace App\Controller;

use App\Entity\RapportHebdomadaire;
use App\Entity\User;
use App\Service\Statistiques\Export\ExportPdf;
use App\Service\Statistiques\Objectifs;
use App\Service\Statistiques\RapportSemaine;
use App\Service\Statistiques\ReglagesRapport;
use App\Service\Statistiques\SemaineIso;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Accueil › Rapport de la semaine : la version de la personne connectée,
 * calculée à l'ouverture selon ses droits (RapportSemaine). La Direction
 * voit les chiffres de l'entreprise, un agent les siens ; les montants et
 * l'encaissement suivent les mêmes droits que les écrans de statistiques.
 * La notification du lundi (app:rapport-hebdomadaire) mène ici. ERP local
 * seulement (StatistiquesHorsLigneSubscriber).
 */
#[Route('/rapport-semaine', name: 'app_rapport_semaine')]
final class RapportSemaineController extends AbstractController
{
    use ExporterStatistiques;

    /** Semaines consultables en arrière (deux ans). */
    public const SEMAINES_EN_ARRIERE = 104;

    public function __construct(
        private readonly RapportSemaine $rapport,
        private readonly ReglagesRapport $reglages,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        [$utilisateur, $droits] = $this->lecteur();
        $maintenant = new \DateTimeImmutable();
        $semaine = self::semaineDemandee($request->query->getString('semaine'), $maintenant);
        $derniere = SemaineIso::precedente($maintenant);

        return $this->render('statistiques/rapport_semaine.html.twig', [
            'r' => $this->rapport->donnees($utilisateur, $droits, $semaine, $maintenant),
            'semaine' => $semaine,
            'precedente' => $semaine->debut > $derniere->debut->modify(\sprintf('-%d weeks', self::SEMAINES_EN_ARRIERE)) ? $semaine->avant() : null,
            'suivante' => $semaine->debut < $derniere->debut ? $semaine->apres() : null,
            'envoi' => $this->em->getRepository(RapportHebdomadaire::class)->findOneBy(['semaine' => $semaine->code()]),
            'reglages' => $this->reglages->libelle(),
            'droits' => $droits,
        ]);
    }

    #[Route('/pdf', name: '_pdf', methods: ['GET'])]
    public function pdf(Request $request, ExportPdf $pdf): Response
    {
        [$utilisateur, $droits] = $this->lecteur();
        $maintenant = new \DateTimeImmutable();
        $semaine = self::semaineDemandee($request->query->getString('semaine'), $maintenant);
        $r = $this->rapport->donnees($utilisateur, $droits, $semaine, $maintenant);
        $this->journaliserExport($this->em, 'Rapport de la semaine', $semaine->code(), 'pdf', array_keys(array_filter($r['sections'])));

        return ExportPdf::reponse(
            $pdf->rendre('statistiques/export/rapport_semaine_pdf.html.twig', ['r' => $r, 'semaine' => $semaine], $maintenant, Objectifs::nomAgent($utilisateur)),
            \sprintf('rapport-semaine-%s.pdf', strtolower($semaine->code())),
        );
    }

    /**
     * La semaine demandée (« 2026-W41 ») si elle est terminée et date de
     * moins de deux ans ; la semaine dernière sinon.
     */
    public static function semaineDemandee(string $texte, \DateTimeImmutable $maintenant): SemaineIso
    {
        $derniere = SemaineIso::precedente($maintenant);
        $semaine = SemaineIso::lire($texte);
        if (null === $semaine || $semaine->debut > $derniere->debut || $semaine->debut < $derniere->debut->modify(\sprintf('-%d weeks', self::SEMAINES_EN_ARRIERE))) {
            return $derniere;
        }

        return $semaine;
    }

    /**
     * La personne connectée et ses droits ; il faut les statistiques
     * globales ou personnelles pour avoir un rapport.
     *
     * @return array{0: User, 1: array<string, bool>}
     */
    private function lecteur(): array
    {
        $utilisateur = $this->getUser();
        $droits = $this->droitsStatistiques();
        if (!$utilisateur instanceof User || (!$droits['global'] && !$droits['perso'])) {
            throw $this->createAccessDeniedException();
        }

        return [$utilisateur, $droits];
    }
}
