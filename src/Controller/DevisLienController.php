<?php

namespace App\Controller;

use App\Entity\Devis;
use App\Entity\User;
use App\Repository\DevisRepository;
use App\Service\Devis\PdfDevis;
use App\Service\Devis\SuiviDevis;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lien du devis envoyé au client par WhatsApp ou e-mail (docs/devis.md),
 * sans compte : /d/{jeton}. L'ouverture est comptée (« vu 2 fois »), puis
 * le client arrive sur son devis dans l'espace client (connexion si besoin),
 * où il l'accepte. /d/{jeton}/pdf : le PDF seul, sans compter (en-tête du
 * modèle WhatsApp, lu par Meta). Une personne du personnel connectée ne
 * compte pas comme une ouverture du client.
 */
final class DevisLienController extends AbstractController
{
    #[Route('/d/{token}', name: 'app_devis_lien', requirements: ['token' => '[a-f0-9]{32,64}'], methods: ['GET'])]
    public function lien(string $token, DevisRepository $repository, EntityManagerInterface $em): Response
    {
        $devis = $this->devis($token, $repository);
        if (!$this->getUser() instanceof User && SuiviDevis::noterOuverture($devis, 'lien WhatsApp', new \DateTimeImmutable())) {
            $em->flush();
        }
        if ($this->getUser() instanceof User) {
            return $this->redirectToRoute('app_devis_lien_pdf', ['token' => $token]);
        }

        return $this->redirectToRoute('app_espace_devis_detail', ['id' => $devis->getId()]);
    }

    #[Route('/d/{token}/pdf', name: 'app_devis_lien_pdf', requirements: ['token' => '[a-f0-9]{32,64}'], methods: ['GET'])]
    public function pdf(string $token, DevisRepository $repository, PdfDevis $pdf): Response
    {
        $devis = $this->devis($token, $repository);

        return new Response($pdf->contenu($devis), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \sprintf('inline; filename="%s.pdf"', PdfDevis::nomFichier($devis)),
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function devis(string $token, DevisRepository $repository): Devis
    {
        $devis = $repository->findOneBy(['tokenAuthenticite' => $token, 'deleted' => false]);
        // Un brouillon n'a jamais été envoyé : le lien ne mène nulle part.
        if (!$devis instanceof Devis || \in_array($devis->getStatut(), [Devis::STATUT_BROUILLON, Devis::STATUT_ANNULE], true)) {
            throw $this->createNotFoundException('Devis introuvable.');
        }

        return $devis;
    }
}
