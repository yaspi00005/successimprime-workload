<?php

namespace App\Controller;

use App\Entity\BonLivraison;
use App\Entity\CompteClient;
use App\Repository\CompteClientRepository;
use App\Service\EspaceClient\EspaceClient;
use App\Service\EspaceClient\LivraisonsClient;
use App\Service\Livraisons\ProgrammationLivraison;
use App\Service\Parametres\ReglagesSaisie;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Espace client (maquette EC01) : recherche de la barre du haut et
 * Livraisons (Messages : EspaceMessagesController). Toujours limité à la fiche de la personne
 * connectée et à ses droits.
 */
#[Route('/espace', name: 'app_espace_')]
final class EspaceSuiviController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly EspaceClient $espace,
        private readonly EntityManagerInterface $em,
        private readonly ReglagesSaisie $reglages,
    ) {
    }

    /** Recherche dans ses commandes, devis, fichiers et (payeur) factures. */
    #[Route('/recherche', name: 'recherche', methods: ['GET'])]
    public function recherche(Request $request): Response
    {
        $compte = $this->compte();
        $texte = trim(mb_substr($request->query->getString('q'), 0, 60));
        $resultats = $this->espace->rechercher($compte, $texte);

        return $this->render('espace/app/recherche.html.twig', [
            'texte' => $texte,
            'resultats' => $resultats,
            'total' => array_sum(array_map('count', $resultats)),
        ]);
    }

    /** Livraisons (maquette DB08) : en route maintenant, à programmer, programmées, livrées avec preuve, retirées. */
    #[Route('/livraisons', name: 'livraisons', methods: ['GET'])]
    public function livraisons(Request $request, LivraisonsClient $livraisons): Response
    {
        $compte = $this->compte();

        return $this->render('espace/app/livraisons.html.twig', [
            'page' => $livraisons->page($compte->getClient(), new \DateTimeImmutable()),
            'vue' => 'retirees' === $request->query->getString('vue') ? 'retirees' : 'livrees',
            // Tarif de livraison : un montant, seulement pour qui voit les montants.
            'frais' => $compte->montantsVisibles() ? $this->reglages->fraisEnLigne() : 0,
        ]);
    }

    /**
     * Programmer la livraison (maquette DB09) : où, quand, qui reçoit, le
     * paiement du reste. Le choix part au local avec la commande.
     */
    #[Route('/livraisons/programmer/{id}', name: 'livraison_programmer', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET', 'POST'])]
    public function programmer(string $id, Request $request, ProgrammationLivraison $programmation): Response
    {
        $compte = $this->compte();
        $client = $compte->getClient();
        $commande = $this->espace->commande($client, $id);
        if (null === $commande || !$commande->isStatut() || $commande->isRecupereParClient()) {
            throw $this->createNotFoundException();
        }
        $bon = $this->em->getRepository(BonLivraison::class)->findOneBy(['commande' => $commande, 'statut' => [BonLivraison::STATUT_BROUILLON, BonLivraison::STATUT_VALIDE]], ['id' => 'DESC']);
        if (null !== $bon?->getEnRouteLe() && !$bon->isEchecEnCours()) {
            $this->addFlash('error', 'Le livreur est déjà parti : appelez-le pour changer quelque chose.');

            return $this->redirectToRoute('app_espace_livraison', ['id' => $bon->getPublicId()]);
        }
        $maintenant = new \DateTimeImmutable();
        $jours = $programmation->jours($commande->getDateLivraison(), $maintenant, $commande);
        $adresses = $programmation->adresses($client);
        $saisie = $commande->getProgrammationLivraison() ?? [];
        $erreurs = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('espace', $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
            }
            $saisie = $request->request->all();
            [$choix, $erreurs] = ProgrammationLivraison::lire($saisie, $adresses, $jours, $maintenant);
            if (null !== $choix) {
                $commande->setProgrammationLivraison($choix);
                ProgrammationLivraison::appliquer($commande, $bon);
                $this->em->flush();
                if (ProgrammationLivraison::MODE_RETRAIT === $choix['mode']) {
                    $this->addFlash('succes', 'C\'est noté : vous viendrez la retirer à l\'imprimerie. Vous êtes prévenu quand elle est prête.');

                    return $this->redirectToRoute('app_espace_livraisons');
                }
                $this->addFlash('succes', \sprintf('Livraison programmée : %s. La veille, vous recevez un rappel ; le jour J, le lien pour suivre le livreur et votre code de réception.', ProgrammationLivraison::quand($choix)));
                if (ProgrammationLivraison::PAIEMENT_OM === $choix['paiement'] && $compte->peut(CompteClient::DROIT_PAYEUR) && $commande->getResteAPayer() > 0) {
                    return $this->redirectToRoute('app_espace_payer', ['id' => $commande->getPublicId()]);
                }

                return $this->redirectToRoute('app_espace_livraisons');
            }
        }
        $principale = $adresses[0] ?? null;

        return $this->render('espace/app/livraison_programmer.html.twig', [
            'commande' => $commande,
            'titre' => EspaceClient::titre($commande),
            'jours' => $jours,
            'adresses' => $adresses,
            'saisie' => $saisie + [
                'adresse' => null !== $principale ? (string) $principale->getId() : 'nouvelle',
                'nom' => $client->getNomComplet(),
                'telephone' => $principale?->getTelephone() ?: $client->getTelephone(),
                'repere' => $principale?->getRepere(),
                'paiement' => ProgrammationLivraison::PAIEMENT_LIVREUR,
            ],
            'erreurs' => $erreurs,
            'frais' => (int) $commande->getFraisLivraison() > 0 ? null : $this->reglages->fraisEnLigne(),
            'montants' => $compte->montantsVisibles(),
            'peutPayer' => $compte->peut(CompteClient::DROIT_PAYEUR),
            'position' => $client->estLocalise() ? ['lat' => $client->getLatitude(), 'lng' => $client->getLongitude()] : null,
        ]);
    }

    /** Une livraison : suivi en direct (maquette DB10) ou preuve de remise (maquette DB11). */
    #[Route('/livraisons/{id}', name: 'livraison', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function livraison(string $id, LivraisonsClient $livraisons): Response
    {
        $compte = $this->compte();
        $bon = $livraisons->bon($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        $maintenant = new \DateTimeImmutable();
        $livree = BonLivraison::STATUT_LIVRE === $bon->getStatut();

        return $this->render($livree ? 'espace/app/livraison_preuve.html.twig' : 'espace/app/livraison_suivi.html.twig', [
            'b' => $bon,
            'commande' => $bon->getCommande(),
            'titre' => EspaceClient::titre($bon->getCommande()),
            'suivi' => $livree ? null : $livraisons->suivi($bon, $maintenant),
            'quand' => LivraisonsClient::quandBon($bon),
            'probleme' => $bon->problemePossible($maintenant),
            'montants' => $compte->montantsVisibles(),
            'peutPayer' => $compte->peut(CompteClient::DROIT_PAYEUR),
            'lecture' => $compte->estLectureSeule(),
        ]);
    }

    /** Position du livreur et heure d'arrivée, relues toutes les 30 secondes par la page de suivi. */
    #[Route('/livraisons/{id}/position', name: 'livraison_position', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function position(string $id, LivraisonsClient $livraisons): JsonResponse
    {
        $bon = $livraisons->bon($this->compte()->getClient(), $id) ?? throw $this->createNotFoundException();
        $a = $livraisons->arrivee($bon, new \DateTimeImmutable());

        return new JsonResponse([
            'livree' => BonLivraison::STATUT_LIVRE === $bon->getStatut(),
            'livreur' => $a['livreur'],
            'heure' => $a['heure']?->format('H:i'),
            'km' => $a['km'],
            'minutes' => $a['minutes'],
        ]);
    }

    /** Photo de remise réduite (seule la copie pour le client est en ligne). */
    #[Route('/livraisons/{id}/photo', name: 'livraison_photo', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function photo(string $id, LivraisonsClient $livraisons): Response
    {
        $bon = $livraisons->bon($this->compte()->getClient(), $id) ?? throw $this->createNotFoundException();
        $octets = null === $bon->getPhotoClient() ? false : base64_decode($bon->getPhotoClient(), true);
        if (false === $octets || '' === $octets) {
            throw $this->createNotFoundException();
        }

        return new Response($octets, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400']);
    }

    /** « Tout est conforme ? » et la note de la livraison (maquette DB11). */
    #[Route('/livraisons/{id}/avis', name: 'livraison_avis', requirements: ['id' => '[0-9a-f-]{36}'], methods: ['POST'])]
    public function avis(string $id, Request $request, LivraisonsClient $livraisons): Response
    {
        if (!$this->isCsrfTokenValid('espace', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
        $bon = $livraisons->bon($this->compte()->getClient(), $id) ?? throw $this->createNotFoundException();
        if (BonLivraison::STATUT_LIVRE !== $bon->getStatut()) {
            throw $this->createNotFoundException();
        }
        $maintenant = new \DateTimeImmutable();
        if ($request->request->has('conforme')) {
            $bon->confirmerConforme($maintenant);
        }
        $note = $request->request->getInt('note');
        if ($note >= 1 && $note <= 5) {
            $bon->noterLivraison($note, array_values(array_filter($request->request->all('tags'), 'is_string')), $maintenant);
        }
        $this->em->flush();
        $this->addFlash('succes', $note >= 1 ? 'Merci pour votre note !' : 'Merci, c\'est noté.');

        return $this->redirectToRoute('app_espace_livraison', ['id' => $id]);
    }

    /** Le compte frais de la base (celui de la session ne garde pas la fiche client). */
    private function compte(): CompteClient
    {
        $session = $this->getUser();
        $compte = $session instanceof CompteClient ? $this->comptes->find($session->getId()) : null;
        if (!$compte instanceof CompteClient || null === $compte->getClient()) {
            throw $this->createAccessDeniedException();
        }

        return $compte;
    }
}
