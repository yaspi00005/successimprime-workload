<?php

namespace App\Controller;

use App\Entity\Avoir;
use App\Entity\Commandes;
use App\Entity\Factures;
use App\Entity\Paiements;
use App\Entity\User;
use App\Form\PaiementsType;
use App\Repository\EntrepriseRepository;
use App\Repository\ParametresPaiementRepository;
use App\Service\Clients\CalculsFicheClient;
use App\Service\Facturation\ChaineDocuments;
use App\Service\Facturation\ValidationFacture;
use App\Service\DocumentsEntreprise;
use App\Service\MontantEnLettres;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Écrans de la facture (maquettes FD02, FD03, FD05) : l'éditeur du
 * brouillon avec l'aperçu en direct, la facture validée avec ses envois
 * et ses actions, les documents liés d'une commande.
 */
#[Route('/factures')]
final class FacturesEcransController extends AbstractController
{
    private const MODES = [
        Paiements::MODE_ORANGE_MONEY => 'Orange Money',
        Paiements::MODE_ESPECES => 'Espèces',
        Paiements::MODE_VIREMENT => 'Virement',
        Paiements::MODE_CHEQUE => 'Chèque',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChaineDocuments $chaine,
    ) {
    }

    /** Appelé par FacturesController::show pour un brouillon, une facture ou une facture d'acompte. */
    public function ecran(int $id, RequestStack $requetes, EntrepriseRepository $entreprises, Parametres $parametres, ParametresPaiementRepository $parametresPaiement): Response
    {
        $facture = $this->em->getRepository(Factures::class)->find($id) ?? throw $this->createNotFoundException();
        $commande = $facture->getCommande() ?? throw $this->createNotFoundException();
        $client = $commande->getClients();
        $chaine = $this->chaine->pour($commande, $facture);

        if ($facture->estBrouillon()) {
            $soldeDu = null === $client ? 0 : (int) $this->em->createQueryBuilder()->select('COALESCE(SUM(c.resteAPayer), 0)')->from(Commandes::class, 'c')
                ->where('c.clients = :cl AND c.deleted = false')->setParameter('cl', $client)->getQuery()->getSingleScalarResult();

            return $this->render('facturation/facture_editeur.html.twig', [
                'facture' => $facture,
                'commande' => $commande,
                'client' => $client,
                'chaine' => $chaine,
                'plafond' => null === $client ? null : CalculsFicheClient::plafondUtilise($soldeDu, $client->getPlafondCredit()),
                'emetteurs' => $entreprises->choixEmetteurs($facture->getEmetteur()),
                'lettres' => MontantEnLettres::enLettres($facture->getTotalTtc()),
                'delai' => null !== $facture->getDateFacture() && null !== $facture->getDateEcheance() ? (int) $facture->getDateFacture()->diff($facture->getDateEcheance())->days : 0,
            ]);
        }

        // « Enregistrer un paiement » (FD07) : le formulaire de la caisse, renvoyé ici avec le reçu.
        $paiement = null;
        $utilisateur = $this->getUser();
        if ($facture->getResteAPayer() > 0 && !$facture->estProforma() && $utilisateur instanceof User && $this->isGranted('ROLE_PAIEMENT_ENCAISSER')) {
            $modes = array_intersect_key(self::MODES, array_flip($parametres->modesPaiementActifs()));
            $formulaire = $this->createForm(PaiementsType::class, (new Paiements())->setMontant($facture->getResteAPayer())->setMode((string) array_key_first($modes)), [
                'est_admin' => $this->isGranted('ROLE_ADMIN'),
                'utilisateur' => $utilisateur,
                'voir_soldes' => $this->isGranted('ROLE_TRESORERIE_VOIR'),
                'action' => $this->generateUrl('app_commandes_paiement', ['id' => $commande->getId(), 'retour' => 'facture', 'facture' => $facture->getId()]),
            ]);
            $taux = $parametresPaiement->recuperer();
            $paiement = [
                'formulaire' => $formulaire->createView(),
                'comptes' => array_map(static fn ($choix) => $choix->data, array_values($formulaire->get('compteTresorerie')->createView()->vars['choices'])),
                'modes' => $modes,
                'frais' => ['fonds' => $taux->getTauxFondsSoutien(), 'retrait' => $taux->getTauxFraisRetrait()],
            ];
        }

        // Retour de l'enregistrement : le reçu et son envoi au client.
        $recu = null;
        // Appelé par forward() : les paramètres de l'adresse sont sur la requête principale.
        $request = $requetes->getMainRequest() ?? throw $this->createNotFoundException();
        if ($request->query->getInt('paiement') > 0) {
            $p = $this->em->getRepository(Paiements::class)->find($request->query->getInt('paiement'));
            if (null !== $p && $p->getCommande() === $commande && ($this->isGranted('ROLE_PAIEMENT_VOIR') || $p->getEncaissePar() === $utilisateur)) {
                $recu = ['paiement' => $p, 'envoi' => CaisseController::envoiRecu($p, (string) ($parametres->valeur('paiements', 'recu') ?: 'whatsapp')), 'ouvrir' => $request->query->getBoolean('envoyer')];
            }
        }

        return $this->render('facturation/facture_validee.html.twig', [
            'paiement' => $paiement,
            'recu' => $recu,
            'facture' => $facture,
            'commande' => $commande,
            'client' => $client,
            'chaine' => $chaine,
            'avoirs' => $this->em->getRepository(Avoir::class)->findBy(['facture' => $facture], ['id' => 'ASC']),
            'lienPublic' => $this->generateUrl('app_factures_pdf_public', ['id' => $facture->getId(), 'token' => $facture->getTokenAuthenticite()], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    /** Éditeur du brouillon (FD02) : date, échéance, entreprise, note ; ou recharger depuis la commande. */
    #[Route('/{id}/brouillon', name: 'app_factures_brouillon', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function enregistrer(Factures $facture, Request $request, EntrepriseRepository $entreprises, ValidationFacture $validation): Response
    {
        if (!$this->isCsrfTokenValid('brouillon-facture-'.$facture->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        if (!$facture->estBrouillon()) {
            $this->addFlash('warning', 'Ce document est validé : il ne se modifie plus.');

            return $this->redirectToRoute('app_factures_show', ['id' => $facture->getId()]);
        }
        $r = $request->request;
        $commande = $facture->getCommande();
        if ('recharger' === $r->getString('action') && null !== $commande) {
            $facture->estAcompte() ? $facture->chargerAcompte($commande, $r->getInt('acompte', (int) $facture->getPourcentageAcompte())) : $facture->chargerDepuisCommande($commande);
            $facture->synchroniserPaiementsDepuisCommande();
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $r->getString('date')) ?: $facture->getDateFacture() ?? new \DateTimeImmutable('today');
        $facture->setDateFacture($date);
        $delai = max(0, min(180, $r->getInt('delai')));
        $facture->setDateEcheance($date->modify('+'.$delai.' days'));
        if (\in_array($r->getString('emetteur'), $entreprises->choixEmetteurs($facture->getEmetteur()), true)) {
            $facture->setEmetteur($r->getString('emetteur'));
        }
        $note = trim($r->getString('observation'));
        $facture->setObservation('' === $note ? null : mb_substr($note, 0, 1000));
        $this->em->flush();
        if ('valider' === $r->getString('action')) {
            $par = $this->getUser();
            $validation->valider($facture, $par instanceof User ? $par : null);
            $this->addFlash('success', \sprintf('%s validée : numéro %s. Elle ne se modifie plus ; une correction passe par un avoir.', $facture->estAcompte() ? 'Facture d\'acompte' : ($facture->estProforma() ? 'Proforma' : 'Facture'), $facture->getNumero()));

            return $this->redirectToRoute('app_factures_show', ['id' => $facture->getId()]);
        }
        if ('apercu' !== $r->getString('action')) {
            $this->addFlash('success', 'recharger' === $r->getString('action') ? 'Montants repris de la commande.' : 'Brouillon enregistré.');
        }

        return $this->redirectToRoute('app_factures_show', ['id' => $facture->getId()]);
    }

    /**
     * « Envoyer sur WhatsApp » (FD03) : note l'envoi et ouvre WhatsApp avec
     * le message et le lien du PDF (l'API Meta n'est pas branchée).
     */
    #[Route('/{id}/whatsapp-lien', name: 'app_factures_whatsapp_lien', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function whatsapp(Factures $facture, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('whatsapp-lien-'.$facture->getId(), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $telephone = trim((string) $facture->getCommande()?->getClients()?->getTelephone());
        if (null === $facture->getNumero() || $facture->estBrouillon() || '' === $telephone) {
            $this->addFlash('warning', '' === $telephone ? 'Ce client n\'a pas de numéro de téléphone.' : 'Validez la facture avant de l\'envoyer.');

            return $this->redirectToRoute('app_factures_show', ['id' => $facture->getId()]);
        }
        $facture->setEnvoyeeWhatsappLe(new \DateTimeImmutable());
        $this->em->flush();
        $lien = $this->generateUrl('app_factures_pdf_public', ['id' => $facture->getId(), 'token' => $facture->getTokenAuthenticite()], UrlGeneratorInterface::ABSOLUTE_URL);
        $message = \sprintf("Bonjour, ici Succès Imprim. Voici votre %s %s de %s F%s :\n%s\nMerci pour votre confiance.",
            $facture->estAcompte() ? 'facture d\'acompte' : 'facture', $facture->getNumero(), number_format($facture->getTotalTtc(), 0, ',', ' '),
            $facture->getResteAPayer() > 0 && null !== $facture->getDateEcheance() ? ', à régler avant le '.$facture->getDateEcheance()->format('d/m/Y') : '', $lien);

        return $this->redirect('https://wa.me/'.UtilisateursController::numeroWhatsApp($telephone).'?text='.rawurlencode($message));
    }

    /**
     * Aperçu de la facture dans la page (FD02, FD03) : le même gabarit que
     * le PDF, en HTML. Un PDF dans un cadre ne s'affiche pas sur tous les
     * téléphones ; le PDF reste le document envoyé et archivé.
     */
    #[Route('/{id}/apercu', name: 'app_factures_apercu', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apercu(Factures $facture, DocumentsEntreprise $documents): Response
    {
        $commande = $facture->getCommande() ?? throw $this->createNotFoundException();
        $html = $this->renderView('factures/pdf.html.twig', ['facture' => $facture, 'commande' => $commande, 'client' => $commande->getClients(), 'masquerPaiements' => !$this->isGranted('ROLE_PAIEMENT_VOIR')] + $documents->vueFacture($facture));
        $style = '<style>html{background:#fff}body{width:760px;margin:0 auto;padding:28px 24px;box-sizing:content-box}</style>';
        $html = str_contains($html, '</head>') ? str_replace('</head>', $style.'</head>', $html) : $style.$html;

        return new Response($html, Response::HTTP_OK, ['Cache-Control' => 'private, no-store', 'X-Frame-Options' => 'SAMEORIGIN']);
    }

    /** Documents liés d'une commande (FD05). */
    #[Route('/commande/{id}/documents', name: 'app_factures_documents_lies', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function documentsLies(Commandes $commande): Response
    {
        return $this->render('facturation/documents_lies.html.twig', [
            'commande' => $commande,
            'chaine' => $this->chaine->pour($commande),
        ]);
    }
}
