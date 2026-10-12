<?php

namespace App\Controller;

use App\Entity\AppareilPointage;
use App\Entity\Pointage;
use App\Entity\PointPointage;
use App\Entity\User;
use App\Repository\AppareilPointageRepository;
use App\Repository\PointPointageRepository;
use App\Service\DocumentsEntreprise;
use App\Service\Pointage\Pointages;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Pointage côté RH (maquettes Q01, Q04, Q05, Q06). Les photos ne sont
 * visibles qu'avec le droit « Administrateur RH » (ROLE_RH).
 */
#[Route('/equipe/pointage', name: 'app_pointage_')]
#[IsGranted('ROLE_RH')]
final class PointageController extends AbstractController
{
    private const CSRF = 'pointage-rh';

    public function __construct(
        private readonly Pointages $pointages,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'jour', methods: ['GET'])]
    public function jour(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $jour = self::date((string) $request->query->get('date'), $maintenant);

        return $this->render('pointage/jour.html.twig', [
            'jour' => $jour,
            'aujourdhui' => $jour->format('Y-m-d') === $maintenant->format('Y-m-d'),
            'tableau' => $this->pointages->jour($jour, $maintenant),
        ]);
    }

    #[Route('/employe/{id}', name: 'employe', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function employe(User $utilisateur, Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $mois = self::mois((string) $request->query->get('mois'), $maintenant);
        $fiche = $this->pointages->mois($utilisateur, $mois, $maintenant);

        // Pointage détaillé : celui demandé, sinon le dernier du mois.
        $detail = null;
        foreach ($fiche['jours'] as $j) {
            foreach ($j['presences'] as $c) {
                if ((string) $c['pointage']->getId() === (string) $request->query->get('pointage') || $request->query->get('pointage') === null) {
                    $detail = $c;
                }
            }
        }

        return $this->render('pointage/employe.html.twig', [
            'utilisateur' => $utilisateur,
            'nom' => Pointages::nom($utilisateur),
            'mois' => $mois,
            'fiche' => $fiche,
            'detail' => $detail,
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/employe/{id}/feuille', name: 'feuille', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function feuille(User $utilisateur, Request $request, DocumentsEntreprise $documents): Response
    {
        $maintenant = new \DateTimeImmutable();
        $mois = self::mois((string) $request->query->get('mois'), $maintenant);

        $html = $this->renderView('pointage/feuille.html.twig', [
            'nom' => Pointages::nom($utilisateur),
            'fonction' => $utilisateur->getEmploye()?->getFonction(),
            'mois' => $mois,
            'fiche' => $this->pointages->mois($utilisateur, $mois, $maintenant),
            'edite' => $maintenant,
        ]);

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html);
        $pdf->setPaper('A4');
        $pdf->render();

        return new Response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \sprintf('inline; filename="presence-%s-%s.pdf"', $utilisateur->getId(), $mois->format('Y-m')),
        ]);
    }

    #[Route('/{id}/corriger', name: 'corriger', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function corriger(Pointage $pointage, Request $request): Response
    {
        $this->verifierJeton($request);
        $motif = trim((string) $request->request->get('motif'));
        $arrivee = self::dateHeure((string) $request->request->get('arrivee'));
        $departSaisi = trim((string) $request->request->get('depart'));
        $depart = $departSaisi === '' ? null : self::dateHeure($departSaisi);

        if ($motif === '' || $arrivee === null || ($departSaisi !== '' && ($depart === null || $depart <= $arrivee))) {
            $this->addFlash('danger', 'Correction refusée : indiquez un motif, une arrivée, et un départ après l\'arrivée.');
        } else {
            $this->pointages->corriger($pointage, $arrivee, $depart, $motif, $this->utilisateur());
            $this->addFlash('success', 'Pointage corrigé. La correction et son motif sont dans l\'historique.');
        }

        return $this->retourFiche($pointage);
    }

    #[Route('/{id}/ajuster', name: 'ajuster', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ajuster(Pointage $pointage, Request $request): Response
    {
        $this->verifierJeton($request);
        $motif = trim((string) $request->request->get('motif'));
        $points = filter_var($request->request->get('points'), \FILTER_VALIDATE_INT);

        if ($motif === '' || $points === false || abs($points) > 500) {
            $this->addFlash('danger', 'Ajustement refusé : indiquez des points (entre −500 et 500) et un motif.');
        } else {
            $this->pointages->ajuster($pointage, $points, $motif, $this->utilisateur());
            $this->addFlash('success', 'Points ajustés. L\'employé voit le motif sur sa fiche.');
        }

        return $this->retourFiche($pointage);
    }

    #[Route('/{id}/valider-heures', name: 'valider_heures', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function validerHeures(Pointage $pointage, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->pointages->validerHeuresSup($pointage, $this->utilisateur());
        $this->addFlash('success', 'Heures supplémentaires validées : le bonus de points est acquis.');

        return $this->retourFiche($pointage);
    }

    #[Route('/reglages', name: 'reglages', methods: ['GET'])]
    public function reglages(PointPointageRepository $points, AppareilPointageRepository $appareils): Response
    {
        $liste = $points->findBy([], ['code' => 'ASC']);

        return $this->render('pointage/reglages.html.twig', [
            'points' => array_map(fn (PointPointage $p): array => ['point' => $p, 'qr' => DocumentsEntreprise::qr($this->lienQr($p))], $liste),
            'en_attente' => $appareils->findBy(['etat' => AppareilPointage::EN_ATTENTE], ['creeLe' => 'DESC']),
            'autorises' => $appareils->findBy(['etat' => AppareilPointage::AUTORISE], ['creeLe' => 'DESC']),
            'regles' => $this->pointages->regles(),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/points/nouveau', name: 'point_nouveau', methods: ['POST'])]
    public function nouveauPoint(Request $request, PointPointageRepository $points): Response
    {
        $this->verifierJeton($request);
        $nom = trim((string) $request->request->get('nom'));
        if ($nom === '') {
            $this->addFlash('danger', 'Donnez un nom au point de pointage, par exemple « Atelier Bamako · Entrée ».');

            return $this->redirectToRoute('app_pointage_reglages');
        }
        $code = \sprintf('PT-%02d', \count($points->findAll()) + 1);
        $point = new PointPointage($code, mb_substr($nom, 0, 80));
        $this->positionner($point, $request);
        $this->em->persist($point);
        $this->em->flush();
        $this->addFlash('success', $code.' créé. Imprimez son affiche et posez-la à l\'entrée.');

        return $this->redirectToRoute('app_pointage_reglages');
    }

    #[Route('/points/{id}/modifier', name: 'point_modifier', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function modifierPoint(PointPointage $point, Request $request): Response
    {
        $this->verifierJeton($request);
        $nom = trim((string) $request->request->get('nom'));
        if ($nom !== '') {
            $point->setNom(mb_substr($nom, 0, 80));
        }
        $this->positionner($point, $request);
        $point->setActif($request->request->getBoolean('actif'));
        $this->em->flush();
        $this->addFlash('success', $point->getCode().' enregistré.');

        return $this->redirectToRoute('app_pointage_reglages');
    }

    #[Route('/points/{id}/renouveler', name: 'point_renouveler', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function renouveler(PointPointage $point, Request $request): Response
    {
        $this->verifierJeton($request);
        $point->renouveler(new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('warning', $point->getCode().' renouvelé : l\'ancienne affiche ne marche plus, imprimez la nouvelle.');

        return $this->redirectToRoute('app_pointage_reglages');
    }

    /**
     * Branche la tablette de l'entrée (maquette E05) : ouvrir cette page
     * sur la tablette, toucher « Brancher cette tablette ». Un seul
     * appareil par point ; le rebrancher ailleurs débranche l'ancien.
     */
    #[Route('/points/{id}/tablette', name: 'tablette', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function brancherTablette(PointPointage $point, Request $request): Response
    {
        $this->verifierJeton($request);
        if ('debrancher' === $request->request->get('action')) {
            $point->brancherTablette(null, null);
            $this->em->flush();
            $this->addFlash('warning', 'Tablette de '.$point->getCode().' débranchée.');

            return $this->redirectToRoute('app_pointage_reglages');
        }

        $jeton = bin2hex(random_bytes(24));
        $point->brancherTablette(hash('sha256', $jeton), new \DateTimeImmutable());
        $this->em->flush();

        $reponse = $this->redirectToRoute('app_tablette_index');
        $reponse->headers->setCookie(Cookie::create(TabletteController::COOKIE, $jeton, new \DateTimeImmutable('+5 years'), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));

        return $reponse;
    }

    #[Route('/points/{id}/affiche', name: 'affiche', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function affiche(PointPointage $point): Response
    {
        return $this->render('pointage/affiche.html.twig', [
            'point' => $point,
            'qr' => DocumentsEntreprise::qr($this->lienQr($point)),
        ]);
    }

    #[Route('/appareils/{id}/decider', name: 'appareil_decider', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function deciderAppareil(AppareilPointage $appareil, Request $request): Response
    {
        $this->verifierJeton($request);
        $autorise = 'autoriser' === $request->request->get('decision');
        $appareil->decider($autorise, $this->utilisateur());
        $this->em->flush();
        $this->addFlash('success', \sprintf('Téléphone de %s %s.', Pointages::nom($appareil->getUtilisateur()), $autorise ? 'autorisé' : 'refusé'));

        return $this->redirectToRoute('app_pointage_reglages');
    }

    private function lienQr(PointPointage $point): string
    {
        return $this->generateUrl('app_pointer_photo', ['code' => $point->getCode(), 'secret' => $point->getSecret()], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    private function positionner(PointPointage $point, Request $request): void
    {
        $lat = str_replace(',', '.', trim((string) $request->request->get('latitude')));
        $lng = str_replace(',', '.', trim((string) $request->request->get('longitude')));
        if ($lat === '' && $lng === '') {
            $point->setPosition(null, null);
        } elseif (is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
            $point->setPosition((float) $lat, (float) $lng);
        }
        if (is_numeric($request->request->get('rayon'))) {
            $point->setRayonMetres((int) $request->request->get('rayon'));
        }
    }

    private function retourFiche(Pointage $pointage): Response
    {
        return $this->redirectToRoute('app_pointage_employe', [
            'id' => $pointage->getUtilisateur()->getId(),
            'mois' => $pointage->getArriveeLe()->format('Y-m'),
            'pointage' => $pointage->getId(),
        ]);
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function utilisateur(): ?User
    {
        $u = $this->getUser();

        return $u instanceof User ? $u : null;
    }

    private static function date(string $saisie, \DateTimeImmutable $defaut): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $saisie);

        return $date ?: $defaut->setTime(0, 0);
    }

    private static function mois(string $saisie, \DateTimeImmutable $defaut): \DateTimeImmutable
    {
        $mois = \DateTimeImmutable::createFromFormat('!Y-m', $saisie);

        return $mois ?: $defaut->modify('first day of this month')->setTime(0, 0);
    }

    private static function dateHeure(string $saisie): ?\DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $saisie) ?: null;
    }
}
