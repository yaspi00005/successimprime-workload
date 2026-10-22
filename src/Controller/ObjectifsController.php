<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\Finances\Periode;
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
 * Statistiques › Objectifs du mois : réalisé, part atteinte et projection
 * en fin de mois pour chaque objectif que la personne a le droit de voir
 * (le CA demande les indicateurs commerciaux, le taux d'encaissement le
 * droit Paiements). La Direction (ROLE_ADMIN) fixe les objectifs, sur
 * l'ERP local seulement (StatistiquesHorsLigneSubscriber).
 */
#[Route('/statistiques/objectifs', name: 'app_statistiques_objectifs')]
#[IsGranted('ROLE_STATS_GLOBAL')]
final class ObjectifsController extends AbstractController
{
    use ExporterStatistiques;

    public function __construct(private readonly Objectifs $objectifs)
    {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $mois = self::moisDemande($request->query->getString('mois'), $maintenant);
        $droits = $this->droitsStatistiques();
        $modifier = $droits['admin'] && $request->query->getBoolean('modifier');

        return $this->render('statistiques/objectifs.html.twig', [
            'mois' => $mois,
            'libelle_mois' => Periode::libelle($mois),
            'choix_mois' => self::choixMois($maintenant),
            'lignes' => $this->objectifs->tableau($mois, $droits, $maintenant),
            'saisie' => $modifier ? $this->objectifs->saisie($mois) : null,
            'droits' => $droits,
            'en_cours' => $mois->format('Y-m') === $maintenant->format('Y-m'),
            'a_venir' => $mois > $maintenant,
            'jour' => (int) $maintenant->format('j'),
            'jours_mois' => (int) $mois->format('t'),
        ]);
    }

    #[Route('/{mois}', name: '_enregistrer', methods: ['POST'], requirements: ['mois' => '\d{4}-\d{2}'])]
    #[IsGranted('ROLE_ADMIN')]
    public function enregistrer(string $mois, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('objectifs-'.$mois, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $debut = self::moisDemande($mois, new \DateTimeImmutable());
        $utilisateur = $this->getUser();
        $par = $utilisateur instanceof User ? $utilisateur : null;

        if ($request->getPayload()->has('copier')) {
            $n = $this->objectifs->copierMoisPrecedent($debut, $par);
            $this->addFlash($n > 0 ? 'success' : 'info', $n > 0 ? \sprintf('%d objectif%s repris du mois précédent.', $n, $n > 1 ? 's' : '') : 'Aucun objectif à reprendre du mois précédent.');

            return $this->redirectToRoute('app_statistiques_objectifs', ['mois' => $debut->format('Y-m'), 'modifier' => 1]);
        }

        $saisies = [];
        $autorisees = array_column($this->objectifs->saisie($debut), 'cle');
        foreach ($request->getPayload()->all('objectif') as $cle => $valeur) {
            if (\in_array($cle, $autorisees, true)) {
                $saisies[$cle] = Objectifs::lireValeur((string) $valeur);
            }
        }
        $n = $this->objectifs->enregistrer($debut, $saisies, $par);
        $this->addFlash('success', 0 === $n ? 'Aucun changement.' : \sprintf('Objectifs enregistrés pour %s (%d changement%s).', Periode::libelle($debut), $n, $n > 1 ? 's' : ''));

        return $this->redirectToRoute('app_statistiques_objectifs', ['mois' => $debut->format('Y-m')]);
    }

    #[Route('/export.{format}', name: '_export', methods: ['GET'], requirements: ['format' => 'xlsx|pdf'], priority: 10)]
    public function exporter(string $format, Request $request, ExportExcel $excel, ExportPdf $pdf, EntityManagerInterface $em): Response
    {
        $maintenant = new \DateTimeImmutable();
        $mois = self::moisDemande($request->query->getString('mois'), $maintenant);
        $lignes = $this->objectifs->tableau($mois, $this->droitsStatistiques(), $maintenant);

        return $this->reponseExport(ExportsStatistiques::objectifs($lignes, Periode::libelle($mois)), $format, $excel, $pdf, $em);
    }

    /** Le mois demandé (AAAA-MM), de 12 mois en arrière à 3 mois en avance ; ce mois-ci sinon. */
    public static function moisDemande(string $texte, \DateTimeImmutable $maintenant): \DateTimeImmutable
    {
        $choix = self::choixMois($maintenant);

        return isset($choix[$texte]) ? new \DateTimeImmutable($texte.'-01 00:00:00') : $maintenant->modify('first day of this month')->setTime(0, 0);
    }

    /**
     * @return array<string, string>
     */
    public static function choixMois(\DateTimeImmutable $maintenant): array
    {
        $courant = $maintenant->modify('first day of this month')->setTime(0, 0);
        $choix = [];
        for ($i = 3; $i >= -12; --$i) {
            $mois = $courant->modify(\sprintf('%+d months', $i));
            $choix[$mois->format('Y-m')] = Periode::libelle($mois).match (true) {
                0 === $i => ' (en cours)',
                $i > 0 => ' (à venir)',
                default => '',
            };
        }

        return $choix;
    }
}
