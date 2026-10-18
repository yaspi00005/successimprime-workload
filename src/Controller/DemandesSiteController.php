<?php

namespace App\Controller;

use App\Entity\DemandeDevis;
use App\Entity\JournalActivite;
use App\Entity\User;
use App\Security\AccesEnLigne;
use App\Service\EspaceClient\FichiersClient;
use App\Service\Site\DemandesSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Ventes › Demandes du site : demandes de devis et messages écrits sans
 * compte sur le site (maquette 11), arrivés par la synchronisation, avec
 * le prospect ou le client et le devis brouillon préparés.
 *
 * Seulement sur l'ERP de l'imprimerie, pour les personnes qui font les
 * devis : en ligne, rien n'est listé (les demandes y restent le temps de
 * monter au local).
 */
#[Route('/ventes/demandes-site', name: 'app_demandes_site_')]
#[IsGranted('ROLE_DEVIS')]
final class DemandesSiteController extends AbstractController
{
    private const CSRF = 'demandes-site';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccesEnLigne $acces,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($this->acces->estEnLigne()) {
            return $this->render('demandes_site/index.html.twig', ['enLigne' => true, 'liste' => [], 'traitees' => false, 'maintenant' => new \DateTimeImmutable()]);
        }
        $traitees = $request->query->getBoolean('traitees');
        $liste = $this->em->getRepository(DemandeDevis::class)->createQueryBuilder('d')
            ->where($traitees ? 'd.traiteeLe IS NOT NULL' : 'd.traiteeLe IS NULL')
            ->orderBy('d.id', 'DESC')
            ->setMaxResults(200)
            ->getQuery()->getResult();

        return $this->render('demandes_site/index.html.twig', [
            'enLigne' => false,
            'liste' => $liste,
            'traitees' => $traitees,
            'maintenant' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/{id}', name: 'voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(int $id, FichiersClient $fichiers): Response
    {
        $demande = $this->demande($id);

        return $this->render('demandes_site/voir.html.twig', [
            'd' => $demande,
            'fichierPresent' => null !== $demande->getFichier() && null !== $fichiers->chemin($demande->getFichier()),
            'jeton' => self::CSRF,
        ]);
    }

    /** Fichier joint : toujours téléchargé (jamais affiché dans la page), et noté au journal. */
    #[Route('/{id}/fichier', name: 'fichier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function fichier(int $id, FichiersClient $fichiers): Response
    {
        $demande = $this->demande($id);
        $fichier = $demande->getFichier();
        $chemin = null !== $fichier && $fichier->isActif() ? $fichiers->chemin($fichier) : null;
        if (null === $chemin) {
            throw $this->createNotFoundException('Le fichier n\'est pas encore arrivé du site.');
        }
        $this->tracer($demande, 'telechargement', ['fichier' => $fichier->getNomOriginal()]);
        $this->em->flush();

        $reponse = new BinaryFileResponse($chemin);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, (string) $fichier->getNomOriginal(), 'fichier');
        $reponse->headers->set('Content-Type', 'application/octet-stream');
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->headers->set('Cache-Control', 'private, no-store');

        return $reponse;
    }

    #[Route('/{id}/traiter', name: 'traiter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function traiter(int $id, Request $request): Response
    {
        $demande = $this->demande($id);
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $moi = $this->getUser();
        if ($request->request->getBoolean('rouvrir')) {
            $demande->rouvrir();
            $this->tracer($demande, JournalActivite::ACTION_MODIFICATION, ['traitee' => false]);
            $this->addFlash('success', \sprintf('Demande %s remise à traiter.', $demande->getNumero()));
        } else {
            $par = $moi instanceof User ? ($moi->getEmploye()?->getPrenom() ?? $moi->getUsername()) : 'Atelier';
            $demande->marquerTraitee((string) $par, new \DateTimeImmutable());
            $this->tracer($demande, JournalActivite::ACTION_MODIFICATION, ['traitee' => true]);
            $this->addFlash('success', \sprintf('Demande %s marquée traitée.', $demande->getNumero()));
        }
        $this->em->flush();

        return $this->redirectToRoute('app_demandes_site_voir', ['id' => $demande->getId()]);
    }

    /** Si la préparation n'a pas pu se faire à l'arrivée : prospect ou client, devis brouillon. */
    #[Route('/{id}/preparer', name: 'preparer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function preparer(int $id, Request $request, DemandesSite $demandes): Response
    {
        $demande = $this->demande($id);
        if (!$this->isCsrfTokenValid(self::CSRF, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $this->addFlash('success', $demandes->preparer($demande).'.');

        return $this->redirectToRoute('app_demandes_site_voir', ['id' => $demande->getId()]);
    }

    private function demande(int $id): DemandeDevis
    {
        $demande = $this->acces->estEnLigne() ? null : $this->em->find(DemandeDevis::class, $id);
        if (!$demande instanceof DemandeDevis) {
            throw $this->createNotFoundException('Demande introuvable.');
        }

        return $demande;
    }

    /** @param array<string, mixed> $apres */
    private function tracer(DemandeDevis $demande, string $action, array $apres): void
    {
        $moi = $this->getUser();
        $this->em->persist((new JournalActivite())
            ->setEntite('DemandeDevis')
            ->setEntiteId((int) $demande->getId())
            ->setAction($action)
            ->setDonneesApres($apres)
            ->setUtilisateur($moi instanceof User ? $moi : null));
    }
}
