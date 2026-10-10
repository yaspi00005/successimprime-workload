<?php

namespace App\Controller;

use App\Repository\FacturesRepository;
use App\Service\DocumentsEntreprise;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page ouverte par le QR « Suivre cette facture en ligne » (maquette I04).
 *
 * Publique (security.yaml, section PUBLIC) : le code de 6 caractères
 * suffit, comme le papier. Elle montre l'authenticité, le total, ce qui
 * est payé et le reste ; le détail des lignes reste dans le PDF.
 */
final class FactureSuiviController extends AbstractController
{
    #[Route('/f/{code}', name: 'app_facture_suivi', requirements: ['code' => '[2-9A-HJ-NP-Z]{4}-[2-9A-HJ-NP-Z]{2}'], methods: ['GET'])]
    public function suivi(string $code, FacturesRepository $factures, DocumentsEntreprise $documents): Response
    {
        $facture = $factures->findOneBy(['codeSuivi' => $code]);

        if ($facture === null || $facture->getCommande() === null) {
            throw $this->createNotFoundException('Aucune facture ne correspond à ce code.');
        }

        $entreprise = $documents->entreprise($facture->getEmetteur(), $facture->getEmetteurLabel());

        $reponse = $this->render('facture_suivi/suivi.html.twig', [
            'facture' => $facture,
            'commande' => $facture->getCommande(),
            'client' => $facture->getCommande()->getClients(),
            'entreprise' => $entreprise,
            'logo' => $documents->dataUri($entreprise->getLogo()),
        ]);
        $reponse->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $reponse->headers->set('Cache-Control', 'no-store');
        $reponse->headers->set('Referrer-Policy', 'no-referrer');

        return $reponse;
    }
}
