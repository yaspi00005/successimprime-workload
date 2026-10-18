<?php

namespace App\Controller;

use App\Entity\AppareilPointage;
use App\Entity\Pointage;
use App\Entity\PointPointage;
use App\Entity\User;
use App\Repository\PointageRepository;
use App\Repository\PointPointageRepository;
use App\Service\Pointage\Pointages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pointage depuis le téléphone de l'employé (maquettes Q02 et Q03) :
 * le QR affiché à l'entrée ouvre /pointer/{code}/{secret}, la photo est
 * prise en direct, l'heure est celle du serveur.
 */
#[Route('/pointer', name: 'app_pointer_')]
final class PointerController extends AbstractController
{
    private const CSRF = 'pointer';

    public function __construct(
        private readonly Pointages $pointages,
        private readonly PointPointageRepository $points,
        private readonly PointageRepository $depot,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $moi = $this->employe();
        $maintenant = new \DateTimeImmutable();
        $aujourdhui = $this->depot->entre($maintenant->setTime(0, 0), $maintenant->setTime(0, 0)->modify('+1 day'), $moi);

        return $this->render('pointer/index.html.twig', [
            'nom' => Pointages::nom($moi),
            'prochain' => $this->pointages->prochain($moi, $maintenant),
            'aujourdhui' => $aujourdhui,
            'domaine' => $this->generateUrl('app_pointer_index', [], 0),
        ]);
    }

    #[Route('/{code}/{secret}', name: 'photo', requirements: ['code' => 'PT-\d{2}', 'secret' => '[0-9a-f]{32}'], methods: ['GET'])]
    public function photo(string $code, string $secret, Request $request): Response
    {
        $moi = $this->employe();
        $point = $this->point($code, $secret);

        // Un jeton par téléphone : la RH valide l'appareil une fois.
        $jeton = (string) $request->cookies->get(AppareilPointage::COOKIE, '');
        $nouveau = \strlen($jeton) < 32;
        if ($nouveau) {
            $jeton = bin2hex(random_bytes(24));
        }
        $appareil = $this->pointages->appareil($moi, $jeton, $request->headers->get('User-Agent'));
        $maintenant = new \DateTimeImmutable();

        $reponse = $this->render('pointer/photo.html.twig', [
            'point' => $point,
            'nom' => Pointages::nom($moi),
            'prochain' => $this->pointages->prochain($moi, $maintenant),
            'maintenant' => $maintenant,
            'blocage' => $point === null ? 'Ce code n\'est pas valable : il a peut-être été renouvelé. Scannez l\'affiche à l\'entrée.' : $this->pointages->controleAppareil($appareil),
            'regles' => $this->pointages->regles(),
            'erreur' => $request->query->get('erreur'),
        ]);
        if ($nouveau) {
            $reponse->headers->setCookie(Cookie::create(AppareilPointage::COOKIE, $jeton, new \DateTimeImmutable('+5 years'), '/', null, $request->isSecure(), true, false, Cookie::SAMESITE_LAX));
        }

        return $reponse;
    }

    #[Route('/{code}/{secret}', name: 'enregistrer', requirements: ['code' => 'PT-\d{2}', 'secret' => '[0-9a-f]{32}'], methods: ['POST'])]
    public function enregistrer(string $code, string $secret, Request $request): Response
    {
        $moi = $this->employe();
        $retour = fn (string $erreur): Response => $this->redirectToRoute('app_pointer_photo', ['code' => $code, 'secret' => $secret, 'erreur' => $erreur]);

        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $retour('La page a expiré. Réessayez.');
        }
        $point = $this->point($code, $secret);
        if ($point === null) {
            return $retour('Ce code n\'est pas valable.');
        }

        $photo = null;
        if (preg_match('#^data:image/jpeg;base64,([A-Za-z0-9+/=]+)$#', (string) $request->request->get('photo'), $m)) {
            $photo = base64_decode($m[1], true) ?: null;
        }
        $position = null;
        $lat = $request->request->get('latitude');
        $lng = $request->request->get('longitude');
        if (is_numeric($lat) && is_numeric($lng) && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
            $position = ['latitude' => (float) $lat, 'longitude' => (float) $lng];
        }

        $appareil = $this->pointages->appareil($moi, (string) $request->cookies->get(AppareilPointage::COOKIE, ''), $request->headers->get('User-Agent'));
        $resultat = $this->pointages->pointer($moi, $point, $photo, $appareil, $position, new \DateTimeImmutable());

        if (!$resultat['ok']) {
            return $retour($resultat['message']);
        }
        if (isset($resultat['message'])) {
            $this->addFlash('info', $resultat['message']);
        }

        return $this->redirectToRoute('app_pointer_fait', ['id' => $resultat['pointage']->getId(), 'sens' => $resultat['action']]);
    }

    #[Route('/fait/{id}/{sens}', name: 'fait', requirements: ['id' => '\d+', 'sens' => 'arrivee|depart'], methods: ['GET'])]
    public function fait(Pointage $pointage, string $sens): Response
    {
        $moi = $this->employe();
        if ($pointage->getUtilisateur() !== $moi) {
            throw $this->createAccessDeniedException();
        }
        $maintenant = new \DateTimeImmutable();
        $regles = $this->pointages->regles();

        // Temps de la semaine (lundi → aujourd'hui), présences fermées seulement.
        $lundi = $maintenant->modify('monday this week')->setTime(0, 0);
        $semaine = 0;
        foreach ($this->pointages->calculs($this->depot->entre($lundi, $maintenant->modify('+1 day'), $moi), $regles, $maintenant) as $c) {
            $semaine += (int) $c['presence'];
        }

        return $this->render('pointer/fait.html.twig', [
            'pointage' => $pointage,
            'sens' => $sens,
            'calcul' => $this->pointages->calcul($pointage, $regles, $maintenant),
            'regles' => $regles,
            'semaine' => $semaine,
        ]);
    }

    /** Photo d'un pointage : la RH, ou la personne pour ses propres pointages. */
    #[Route('/photo/{id}/{sens}', name: 'voir_photo', requirements: ['id' => '\d+', 'sens' => 'arrivee|depart'], methods: ['GET'])]
    public function voirPhoto(Pointage $pointage, string $sens): Response
    {
        if (!$this->isGranted('ROLE_RH') && $pointage->getUtilisateur() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
        $chemin = $this->pointages->cheminPhoto($sens === 'arrivee' ? $pointage->getPhotoArrivee() : $pointage->getPhotoDepart());
        if ($chemin === null) {
            throw $this->createNotFoundException();
        }

        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('Content-Type', 'image/jpeg');
        $reponse->setPrivate();
        $reponse->setMaxAge(3600);

        return $reponse;
    }

    private function point(string $code, string $secret): ?PointPointage
    {
        $point = $this->points->findOneBy(['code' => $code]);

        return $point !== null && $point->secretValide($secret) ? $point : null;
    }

    private function employe(): User
    {
        $moi = $this->getUser();
        if (!$moi instanceof User || $moi->getEmploye() === null) {
            throw $this->createAccessDeniedException('Le pointage est réservé au personnel.');
        }

        return $moi;
    }
}
