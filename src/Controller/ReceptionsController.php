<?php

namespace App\Controller;

use App\Entity\Achats;
use App\Entity\ReclamationFournisseur;
use App\Entity\User;
use App\Security\FichierEnvoye;
use App\Service\Achats\CalculsAchat;
use App\Service\Achats\CalculsReception;
use App\Service\Achats\Receptions;
use App\Service\Achats\SuiviAchats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Achats › Réceptions (maquette SU05, docs/achats.md) : les commandes à
 * recevoir et les réceptions récentes, puis l'écran de réception d'une
 * commande (commandé, reçu, bon état, facturé ; casse et réclamation ;
 * frais réels ; contrôle à trois points ; entrée en stock).
 *
 * Réservé à la direction comme les achats (access_control, /achats).
 * Le montant de la facture et le paiement bloqué demandent en plus le
 * droit « Trésorerie et soldes » ; forcer le paiement, le droit de payer.
 */
#[Route('/achats/receptions')]
final class ReceptionsController extends AbstractController
{
    /** Voir la facture à payer et le blocage du solde. */
    public const ROLE_FINANCES = 'ROLE_TRESORERIE_VOIR';

    /** Forcer le paiement d'un solde bloqué : qui paie les fournisseurs. */
    public const ROLE_FORCER = 'ROLE_TRESORERIE_SAISIR';

    public function __construct(
        private readonly Receptions $receptions,
        private readonly SuiviAchats $suivi,
    ) {
    }

    #[Route('', name: 'app_reception_index', methods: ['GET'])]
    public function index(): Response
    {
        $aujourdhui = new \DateTimeImmutable('today');
        $liste = $this->receptions->liste($aujourdhui);
        $prevues = [];

        foreach ($liste['aRecevoir'] as $achat) {
            $prevues[(int) $achat->getId()] = $this->suivi->livraisonPrevue($achat, $aujourdhui);
        }

        // Badge des réceptions récentes : à l'heure ou +N j, et la casse.
        $recentes = [];
        foreach ($liste['recentes'] as $achat) {
            $abimees = 0;
            $unite = null;
            foreach ($achat->getLignes() as $ligne) {
                $abimees += $ligne->getQuantiteAbimee();
                $unite ??= $ligne->getQuantiteAbimee() > 0 ? $ligne->getArticle()?->getUniteAchat() : null;
            }
            $recentes[] = [
                'achat' => $achat,
                'ecart' => CalculsAchat::ecart($achat->getStatut(), $achat->getDatePromise(), $achat->getDateReception(), $abimees, $unite),
                'ouvertes' => \count($achat->getReclamationsOuvertes()),
            ];
        }

        return $this->render('achats/receptions.html.twig', [
            'a_recevoir' => $liste['aRecevoir'],
            'recentes' => $recentes,
            'prevues' => $prevues,
            'aujourdhui' => $aujourdhui,
            'jours_recentes' => Receptions::JOURS_RECENTES,
        ]);
    }

    #[Route('/{id}', name: 'app_reception_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Achats $achat): Response
    {
        if (!$achat->estEnCours() && !$achat->estRecu()) {
            $this->addFlash('error', 'Seule une commande passée chez le fournisseur se réceptionne.');

            return $this->redirectToRoute('app_achat_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
        }

        $fournisseur = $achat->getFournisseur();
        $voitFinances = $this->isGranted(self::ROLE_FINANCES);
        $reclamations = [];

        foreach ($achat->getReclamations() as $r) {
            $reclamations[] = [
                'reclamation' => $r,
                'texte' => CalculsReception::texteReclamation($r->getQuantite(), $r->getArticle()?->getUniteAchat() ?: $r->getArticle()?->getUniteCourte(), $voitFinances ? $r->getMontant() : null),
                'whatsapp' => CalculsReception::lienWhatsApp($fournisseur?->getNumeroWhatsapp(), (string) $r->getMessage()),
            ];
        }

        return $this->render('achats/reception.html.twig', [
            'achat' => $achat,
            'fournisseur' => $fournisseur,
            'detail' => $this->receptions->detail($achat),
            'reclamations' => $reclamations,
            'voit_finances' => $voitFinances,
            'peut_forcer' => $this->isGranted(self::ROLE_FORCER),
            'dette' => $voitFinances ? $this->receptions->dette($achat) : null,
            'livraison' => $achat->getDateReception() !== null ? CalculsReception::texteLivraison($achat->getDatePromise(), $achat->getDateReception(), (string) $fournisseur?->getNomCourt()) : null,
            'prevue' => $achat->estEnCours() ? $this->suivi->livraisonPrevue($achat, new \DateTimeImmutable('today')) : null,
            'aujourdhui' => new \DateTimeImmutable('today'),
            'types_photo' => implode(',', array_keys(Receptions::TYPES_PHOTO)),
            'taille_photo' => Receptions::TAILLE_MAX_PHOTO,
        ]);
    }

    /**
     * « Valider la réception » : statut reçu, date, stock, coût de
     * revient, réclamations et paiement bloqué.
     */
    #[Route('/{id}/valider', name: 'app_reception_valider', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function valider(Request $request, Achats $achat): Response
    {
        $this->verifierJeton($request, 'reception'.$achat->getId());
        $photo = $request->files->get('photo');

        try {
            $this->receptions->valider($achat, $request->request->all(), $photo instanceof UploadedFile ? $photo : null, $this->utilisateur(), $this->isGranted(self::ROLE_FINANCES), new \DateTimeImmutable());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_reception_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
        }

        $ouvertes = \count($achat->getReclamationsOuvertes());
        $this->addFlash('success', sprintf(
            'La commande %s est réceptionnée : le stock est à jour%s.',
            $achat->getNumero(),
            $ouvertes > 0 ? sprintf(', %d réclamation%s au fournisseur à envoyer', $ouvertes, $ouvertes > 1 ? 's' : '') : ''
        ));

        return $this->redirectToRoute('app_reception_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Bon de livraison et facture arrivés après la réception.
     */
    #[Route('/{id}/controle', name: 'app_reception_controle', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function controle(Request $request, Achats $achat): Response
    {
        $this->verifierJeton($request, 'controle_reception'.$achat->getId());

        if ($achat->estRecu()) {
            $this->receptions->enregistrerControle($achat, $request->request->all(), $this->utilisateur(), $this->isGranted(self::ROLE_FINANCES), new \DateTimeImmutable());
            $this->addFlash('success', 'Bon de livraison et facture enregistrés.');
        }

        return $this->redirectToRoute('app_reception_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * La finance force le paiement du solde malgré une réclamation ouverte.
     */
    #[Route('/{id}/debloquer', name: 'app_reception_debloquer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function debloquer(Request $request, Achats $achat): Response
    {
        $this->verifierJeton($request, 'debloquer_reception'.$achat->getId());
        $this->denyAccessUnlessGranted(self::ROLE_FORCER);

        try {
            $this->receptions->forcerPaiement($achat, (string) $request->request->get('note', ''), $this->utilisateur(), new \DateTimeImmutable());
            $this->addFlash('success', 'Le solde peut être payé : la note est gardée sur la commande.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_reception_show', ['id' => $achat->getId()], Response::HTTP_SEE_OTHER);
    }

    /**
     * Le message part par WhatsApp (lien wa.me) : l'envoi est noté. Le
     * bouton ouvre wa.me lui-même et prévient ici en arrière-plan (réponse
     * vide) ; sans JavaScript, le formulaire est renvoyé vers wa.me.
     */
    #[Route('/reclamations/{id}/whatsapp', name: 'app_reception_reclamation_whatsapp', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function whatsapp(Request $request, ReclamationFournisseur $reclamation): Response
    {
        $this->verifierJeton($request, 'whatsapp_reclamation'.$reclamation->getId());
        $lien = $this->receptions->envoyerReclamation($reclamation, new \DateTimeImmutable());

        if ($lien === null) {
            $this->addFlash('error', 'Le fournisseur n\'a pas de numéro : ajoutez celui du contact sur sa fiche.');

            return $this->redirectToRoute('app_reception_show', ['id' => $reclamation->getAchat()?->getId()], Response::HTTP_SEE_OTHER);
        }

        if ($request->isXmlHttpRequest()) {
            return new Response(null, Response::HTTP_NO_CONTENT);
        }

        return $this->redirect($lien, Response::HTTP_SEE_OTHER);
    }

    #[Route('/reclamations/{id}/statut', name: 'app_reception_reclamation_statut', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function statut(Request $request, ReclamationFournisseur $reclamation): Response
    {
        $this->verifierJeton($request, 'statut_reclamation'.$reclamation->getId());
        $statut = (string) $request->request->get('statut', '');

        try {
            $this->receptions->cloturerReclamation($reclamation, $statut, $this->utilisateur(), new \DateTimeImmutable());
            $this->addFlash('success', match ($statut) {
                ReclamationFournisseur::REMPLACEE => 'Réclamation réglée : la marchandise de remplacement est entrée en stock.',
                ReclamationFournisseur::DEDUITE => 'Réclamation réglée : le montant est déduit de la facture à payer.',
                default => 'Réclamation close.',
            });
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_reception_show', ['id' => $reclamation->getAchat()?->getId()], Response::HTTP_SEE_OTHER);
    }

    #[Route('/reclamations/{id}/photo', name: 'app_reception_reclamation_photo_ajout', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ajouterPhoto(Request $request, ReclamationFournisseur $reclamation): Response
    {
        $this->verifierJeton($request, 'photo_reclamation'.$reclamation->getId());
        $photo = $request->files->get('photo');

        try {
            if (!$photo instanceof UploadedFile) {
                throw new \DomainException('Choisissez une photo.');
            }
            $this->receptions->ajouterPhoto($reclamation, $photo);
            $this->addFlash('success', 'Photo ajoutée à la réclamation.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_reception_show', ['id' => $reclamation->getAchat()?->getId()], Response::HTTP_SEE_OTHER);
    }

    /** Photo de la casse, servie hors du dossier public. */
    #[Route('/reclamations/{id}/photo', name: 'app_reception_reclamation_photo', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function photo(ReclamationFournisseur $reclamation): Response
    {
        $chemin = $this->receptions->cheminPhoto($reclamation) ?? throw $this->createNotFoundException('Photo introuvable.');

        return FichierEnvoye::reponse($chemin, sprintf('casse-%s.%s', $reclamation->getAchat()?->getNumero() ?? $reclamation->getId(), pathinfo($chemin, \PATHINFO_EXTENSION)));
    }

    private function verifierJeton(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function utilisateur(): ?User
    {
        $utilisateur = $this->getUser();

        return $utilisateur instanceof User ? $utilisateur : null;
    }
}
