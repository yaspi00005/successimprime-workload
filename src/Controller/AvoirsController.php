<?php

namespace App\Controller;

use App\Entity\Avoir;
use App\Entity\CompteTresorerie;
use App\Entity\Factures;
use App\Entity\ReclamationClient;
use App\Entity\User;
use App\Service\DocumentsEntreprise;
use App\Service\Facturation\AvoirsFactures;
use App\Service\MontantEnLettres;
use App\Service\Paiement\SoldeClient;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Avoir sur une facture validée (maquette FD04). Préparé par qui fait
 * les factures ; validé par la trésorière ou la direction (droit
 * « Saisir en trésorerie »). Un remboursement demande en plus le droit
 * « Rendre l'argent d'un compte client ».
 */
#[Route('/factures')]
final class AvoirsController extends AbstractController
{
    public function __construct(
        private readonly AvoirsFactures $avoirs,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('/{id}/avoir', name: 'app_factures_avoir', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function nouveau(Factures $facture, Request $request, SoldeClient $solde): Response
    {
        if (!$facture->estFacture() || !$facture->estEmise()) {
            $this->addFlash('warning', 'Un avoir se fait sur une facture validée. Un brouillon se corrige directement.');

            return $this->redirectToRoute('app_factures_show', ['id' => $facture->getId()]);
        }
        $client = $facture->getCommande()?->getClients();
        $r = $request->request;
        $quantites = (array) $r->all('quantites');
        $motif = $r->getString('motif', $request->query->getString('motif', 'defaut_fabrication'));
        if ('annulation_totale' === $motif && !$request->isMethod('POST')) {
            foreach ($this->avoirs->lignes($facture) as $l) {
                $quantites[$l['cle']] = $l['reste'];
            }
        }
        $suite = $r->getString('suite', Avoir::DEDUIRE);
        $reclamations = null === $client ? [] : $this->em->getRepository(ReclamationClient::class)->findBy(['client' => $client], ['id' => 'DESC'], 20);
        $reclamation = null;
        foreach ($reclamations as $rc) {
            if ((string) $rc->getId() === $r->getString('reclamation', $request->query->getString('reclamation'))) {
                $reclamation = $rc;
            }
        }
        $comptes = $this->em->getRepository(CompteTresorerie::class)->findBy(['actif' => true], ['nom' => 'ASC']);
        $comptes = array_values(array_filter($comptes, static fn (CompteTresorerie $c): bool => !$c->isOrangeMoneyEnLigne() && \in_array($c->getType(), [CompteTresorerie::TYPE_CAISSE, CompteTresorerie::TYPE_ORANGE_MONEY, CompteTresorerie::TYPE_WAVE], true)));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('avoir-facture-'.$facture->getId(), $r->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }
            if (!$this->isGranted('ROLE_TRESORERIE_SAISIR')) {
                $this->addFlash('error', 'Un avoir est validé par la trésorière ou la direction.');
            } elseif (Avoir::REMBOURSER === $suite && !$this->isGranted('ROLE_SOLDE_CLIENT_REMBOURSER')) {
                $this->addFlash('error', 'Rembourser demande le droit « Rendre l\'argent d\'un compte client ».');
            } else {
                $compte = null;
                foreach ($comptes as $c) {
                    if ((string) $c->getId() === $r->getString('compte')) {
                        $compte = $c;
                    }
                }
                $par = $this->getUser();
                try {
                    $avoir = $this->avoirs->valider($facture, $quantites, $motif, $reclamation, $suite, $compte, $r->getString('observation'), $par instanceof User ? $par : throw $this->createAccessDeniedException(), new \DateTimeImmutable());
                    $this->addFlash('success', \sprintf('Avoir %s validé : %s F, %s.', $avoir->getNumero(), number_format($avoir->getTotal(), 0, ',', ' '), mb_strtolower($avoir->getSuiteLibelle())));

                    return $this->redirectToRoute('app_factures_avoir_show', ['id' => $avoir->getId()]);
                } catch (\DomainException $e) {
                    $this->addFlash('error', $e->getMessage());
                }
            }
        }

        try {
            $calcul = $this->avoirs->calculer($facture, $quantites);
        } catch (\DomainException $e) {
            $calcul = ['lignes' => [], 'brut' => 0, 'remise' => 0, 'total' => 0];
            $this->addFlash('error', $e->getMessage());
        }
        $soldeAvant = null === $client ? 0 : $this->soldeDu($facture);

        return $this->render('facturation/avoir_nouveau.html.twig', [
            'facture' => $facture,
            'client' => $client,
            'lignes' => $this->avoirs->lignes($facture),
            'quantites' => $quantites,
            'calcul' => $calcul,
            'motif' => $motif,
            'suite' => $suite,
            'reclamations' => $reclamations,
            'reclamation' => $reclamation,
            'comptes' => $comptes,
            'compteChoisi' => $r->getString('compte'),
            'observation' => $r->getString('observation'),
            'soldeAvant' => $soldeAvant,
            'creditClient' => null === $client ? 0 : $solde->disponiblePour($client),
            'dejaAvoirs' => $this->avoirs->avoirs($facture),
            'peutValider' => $this->isGranted('ROLE_TRESORERIE_SAISIR'),
            'peutRembourser' => $this->isGranted('ROLE_SOLDE_CLIENT_REMBOURSER'),
        ]);
    }

    #[Route('/avoirs/{id}', name: 'app_factures_avoir_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(Avoir $avoir): Response
    {
        return $this->render('facturation/avoir_voir.html.twig', ['avoir' => $avoir, 'facture' => $avoir->getFacture()]);
    }

    #[Route('/avoirs/{id}/pdf', name: 'app_factures_avoir_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdf(Avoir $avoir, DocumentsEntreprise $documents): Response
    {
        $facture = $avoir->getFacture() ?? throw $this->createNotFoundException();
        $vue = $documents->vueFacture($facture);
        $html = $this->renderView('facturation/avoir_pdf.html.twig', [
            'avoir' => $avoir,
            'facture' => $facture,
            'client' => $avoir->getClient(),
            'entreprise' => $vue['entreprise'],
            'images' => $vue['images'],
            'montantLettres' => sprintf('Arrêté le présent avoir à la somme de %s (%s) francs CFA.', MontantEnLettres::enLettres($avoir->getTotal()), number_format($avoir->getTotal(), 0, ',', ' ')),
        ]);
        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response($dompdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => \sprintf('inline; filename="%s.pdf"', $avoir->getNumero()),
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Ce que le client doit encore sur ses commandes (solde « avant » de la maquette). */
    private function soldeDu(Factures $facture): int
    {
        $client = $facture->getCommande()?->getClients();

        return (int) $this->em->createQueryBuilder()->select('COALESCE(SUM(c.resteAPayer), 0)')->from(\App\Entity\Commandes::class, 'c')
            ->where('c.clients = :cl')->andWhere('c.deleted = false')->setParameter('cl', $client)
            ->getQuery()->getSingleScalarResult();
    }
}
