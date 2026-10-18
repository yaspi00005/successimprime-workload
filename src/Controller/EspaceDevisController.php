<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Entity\Devis;
use App\Entity\MessageClient;
use App\Repository\CompteClientRepository;
use App\Entity\DemandeDevis;
use App\Service\Devis\AcceptationDevis;
use App\Service\Devis\SuiviDevis;
use App\Service\EspaceClient\EspaceClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Mes devis » de l'espace client (maquettes DT01 et DT04, docs/devis.md) :
 * la liste, le devis avec ses lignes, ses versions et son suivi, et la
 * réponse du client.
 *
 * Droits (CompteClient) : les montants pour qui les voit (montantsVisibles :
 * particulier, valideur, payeur) ; accepter, refuser ou demander une
 * modification pour qui valide (DROIT_VALIDEUR) ; payer l'acompte pour qui
 * paie (DROIT_PAYEUR), sinon l'acompte attend le payeur de l'entreprise.
 * Un compte « Lecture · suivi » n'ouvre pas les devis (DroitsEspace).
 * Jamais de coût ni de marge.
 */
#[Route('/espace/devis', name: 'app_espace_devis')]
final class EspaceDevisController extends AbstractController
{
    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly EspaceClient $espace,
        private readonly AcceptationDevis $acceptation,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function liste(Request $request): Response
    {
        $filtre = (string) $request->query->get('filtre', 'tous');
        $liste = $this->espace->devis($this->compte()->getClient());
        $liste = match ($filtre) {
            'a_accepter' => array_values(array_filter($liste, static fn (Devis $d): bool => $d->estEnAttente())),
            'acceptes' => array_values(array_filter($liste, static fn (Devis $d): bool => $d->estAccepte())),
            default => $liste,
        };

        return $this->vue('espace/app/devis.html.twig', ['liste' => $liste, 'filtre' => $filtre]);
    }

    #[Route('/{id}', name: '_detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        $compte = $this->compte();
        $devis = $this->devis($compte, $id);
        $maintenant = new \DateTimeImmutable();
        if (SuiviDevis::noterOuverture($devis, $compte->getNomAffiche(), $maintenant)) {
            $this->em->flush();
        }
        $versions = $devis->getVersions();
        $demande = $this->em->getRepository(DemandeDevis::class)->findOneBy(['devis' => $devis]);

        return $this->vue('espace/app/devis_detail.html.twig', [
            'devis' => $devis,
            'compte' => $compte,
            'etapes' => SuiviDevis::etapesClient($devis, $demande?->getCreeLe(), $maintenant),
            // « Ce qui a changé » : la version d'avant et celle-ci.
            'actuelle' => $versions[0] ?? null,
            'precedente' => $versions[1] ?? null,
            'changements' => isset($versions[0], $versions[1]) ? SuiviDevis::changements($versions[1], $versions[0]) : [],
            'expire' => SuiviDevis::estAExpirer($devis, $maintenant),
            'motifs' => Devis::MOTIFS_REFUS,
            'agent' => null !== $devis->getEnvoyePar() ? MessageClient::signatureDe($devis->getEnvoyePar()) : null,
        ]);
    }

    /**
     * « Accepter et payer l'acompte » : la commande est créée avec ce prix,
     * puis qui paie part au paiement de l'acompte (Orange Money), les
     * autres retrouvent leur commande.
     */
    #[Route('/{id}/accepter', name: '_accepter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function accepter(int $id, Request $request): Response
    {
        $this->jeton($request);
        $compte = $this->compte();
        $devis = $this->devis($compte, $id);
        // Accepter un devis engage l'entreprise comme valider une commande.
        if (!$compte->peut(CompteClient::DROIT_VALIDEUR)) {
            throw $this->createAccessDeniedException();
        }
        try {
            $commande = $this->acceptation->accepter($devis, $compte, new \DateTimeImmutable());
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_espace_devis_detail', ['id' => $id]);
        }
        $id = (string) $commande->getPublicId();
        if ($compte->peut(CompteClient::DROIT_PAYEUR) && $devis->getMontantAcompte() > 0) {
            $this->addFlash('succes', \sprintf('Devis %s accepté : votre commande %s est créée. Il reste à payer l\'acompte.', $devis->getNumero(), $commande->getNumero()));

            return $this->redirectToRoute('app_espace_nouvelle_commande_paiement', ['id' => $id, 'part' => 'acompte']);
        }
        $this->addFlash('succes', \sprintf('Devis %s accepté : votre commande %s est créée.%s', $devis->getNumero(), $commande->getNumero(), $devis->getMontantAcompte() > 0 ? ' L\'acompte sera payé par la personne qui paie pour votre entreprise.' : ''));

        return $this->redirectToRoute('app_espace_commande', ['id' => $id]);
    }

    #[Route('/{id}/refuser', name: '_refuser', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function refuser(int $id, Request $request): Response
    {
        $this->jeton($request);
        $compte = $this->compte();
        $devis = $this->devis($compte, $id);
        if (!$compte->peut(CompteClient::DROIT_VALIDEUR)) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->acceptation->refuser($devis, $compte, $request->request->getString('motif'), mb_substr($request->request->getString('precision'), 0, 200), new \DateTimeImmutable());
            $this->addFlash('succes', \sprintf('Devis %s refusé. Merci de nous avoir dit pourquoi.', $devis->getNumero()));
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_devis_detail', ['id' => $id]);
    }

    #[Route('/{id}/modification', name: '_modification', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function modification(int $id, Request $request): Response
    {
        $this->jeton($request);
        $compte = $this->compte();
        $devis = $this->devis($compte, $id);
        if (!$compte->peut(CompteClient::DROIT_VALIDEUR)) {
            throw $this->createAccessDeniedException();
        }
        try {
            $this->acceptation->demanderModification($devis, $compte, mb_substr($request->request->getString('texte'), 0, 1200), new \DateTimeImmutable());
            $this->addFlash('succes', 'Demande envoyée : nous préparons une nouvelle version et vous prévenons.');
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_espace_devis_detail', ['id' => $id]);
    }

    #[Route('/{id}/pdf', name: '_pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function pdf(int $id): Response
    {
        $compte = $this->compte();
        $devis = $this->devis($compte, $id);
        // Le PDF porte les prix : pas pour un demandeur (son valideur le voit).
        if (!$compte->montantsVisibles()) {
            throw $this->createAccessDeniedException('Le prix des devis est vu par les personnes qui valident ou paient pour l\'entreprise.');
        }
        if (null === $devis->getTokenAuthenticite()) {
            $devis->genererTokenAuthenticite();
            $this->em->flush();
        }

        return $this->redirectToRoute('app_devis_pdf_public', ['id' => $devis->getId(), 'token' => $devis->getTokenAuthenticite()]);
    }

    /** Un devis de son client, envoyé (jamais un brouillon) ; sinon introuvable. */
    private function devis(CompteClient $compte, int $id): Devis
    {
        $devis = $this->em->find(Devis::class, $id);
        if (!$devis instanceof Devis || $devis->isDeleted() || $devis->getClients()?->getId() !== $compte->getClient()?->getId()
            || !\in_array($devis->getStatut(), EspaceClient::DEVIS_VISIBLES, true)) {
            throw $this->createNotFoundException();
        }

        return $devis;
    }

    /** @param array<string, mixed> $parametres */
    private function vue(string $gabarit, array $parametres): Response
    {
        $compte = $this->compte();

        return $this->render($gabarit, $parametres + [
            'onglet' => 'commandes',
            'moi' => $compte->getClient(),
            'peutPayer' => $compte->peut(CompteClient::DROIT_PAYEUR),
            'peutValider' => $compte->peut(CompteClient::DROIT_VALIDEUR),
        ]);
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid('espace', $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
    }

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
