<?php

namespace App\Controller;

use App\Entity\CompteClient;
use App\Entity\CreationAtelier;
use App\Repository\CompteClientRepository;
use App\Service\Atelier\CatalogueAtelier;
use App\Service\Atelier\CreationsAtelier;
use App\Service\Atelier\ImagesAtelier;
use App\Service\Atelier\TarifAtelier;
use App\Service\EspaceClient\CompteEntreprise;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Atelier 3D dans l'espace client (docs/atelier-3d.md) : mêmes outils
 * que sur le site, aux prix du client (grille B2B pour un revendeur,
 * promotion indiquée), et « Valider mon modèle » passe une commande « à
 * confirmer » comme Nouvelle commande. « Mes créations » garde chaque
 * modèle validé pour le rouvrir ou le commander à nouveau.
 *
 * Tout se limite aux créations du client connecté. Un compte en lecture
 * seule n'y entre pas (DroitsEspace) ; un demandeur commande sans voir de
 * montant, sa demande part chez un valideur de son entreprise.
 */
#[Route('/espace', name: 'app_espace_')]
final class EspaceAtelierController extends AbstractController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(
        private readonly CompteClientRepository $comptes,
        private readonly CatalogueAtelier $catalogue,
        private readonly CreationsAtelier $creations,
        private readonly ImagesAtelier $images,
        private readonly TarifAtelier $tarif,
        private readonly CompteEntreprise $compteEntreprise,
        private readonly EntityManagerInterface $em,
        #[Autowire(service: 'limiter.atelier_image_ip')] private readonly RateLimiterFactoryInterface $limiteImage,
    ) {
    }

    #[Route('/atelier-3d', name: 'atelier', methods: ['GET'])]
    public function atelier(Request $request): Response
    {
        $compte = $this->ecrivain();
        $client = $compte->getClient();
        $reprise = null;
        if ('' !== $request->query->getString('creation')) {
            $creation = $this->creations->duClientParId($client, $request->query->getString('creation')) ?? throw $this->createNotFoundException();
            $reprise = CreationsAtelier::pourLaPage($creation) + ['modele' => $this->modeleDe($creation)];
            foreach ($reprise['faces'] as $face => $r) {
                // L'image d'origine s'affiche par le contrôleur des fichiers du client.
                $reprise['faces'][$face]['url'] = null !== $r['source'] ? $this->generateUrl('app_espace_creation_fichier', ['id' => $reprise['id'], 'quoi' => 'image-'.$face]) : null;
            }
        }

        return $this->render('espace/app/atelier.html.twig', [
            'onglet' => 'commandes',
            'fiches' => array_map($this->catalogue->fiche(...), $this->catalogue->modeles()),
            'paliers' => $client->isB2B() && !$this->tarif->reglages()['paliers_b2b'] ? [] : $this->tarif->reglages()['paliers'],
            'reprise' => $reprise,
            'montants' => CatalogueAtelier::montantsVisibles($compte),
            'adresses' => $this->compteEntreprise->adresses($client),
            'entreprise' => CompteClient::clientAPlusieursComptes($client),
            'demandeValidation' => $this->compteEntreprise->demandeValidation($compte),
            'envoiMax' => ImagesAtelier::envoiMax(),
        ]);
    }

    /** Prix du client, recalculé ici à chaque changement ; aucun montant pour un demandeur. */
    #[Route('/atelier-3d/prix', name: 'atelier_prix', methods: ['GET'])]
    public function prix(Request $request): JsonResponse
    {
        $compte = $this->ecrivain();
        $modele = $this->catalogue->modele($request->query->getString('modele'));
        if (null === $modele) {
            return $this->json(['erreur' => 'Article indisponible.'], 404);
        }

        return $this->json($this->catalogue->estimer($modele, $request->query->getInt('quantite'), $request->query->getInt('faces', 1), $compte->getClient(), CatalogueAtelier::montantsVisibles($compte), new \DateTimeImmutable('today')));
    }

    #[Route('/atelier-3d/image', name: 'atelier_image', methods: ['POST'])]
    public function image(Request $request): JsonResponse
    {
        $this->ecrivain();
        if (!$this->isCsrfTokenValid('espace', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['erreur' => 'La page a expiré : rechargez-la.'], 403);
        }

        return SiteAtelierController::deposer($request, $this->images, $this->limiteImage);
    }

    /** « Valider mon modèle » : commande « à confirmer » (ou demande à valider dans l'entreprise). */
    #[Route('/atelier-3d/valider', name: 'atelier_valider', methods: ['POST'])]
    public function valider(Request $request): JsonResponse
    {
        $compte = $this->ecrivain();
        if (!$this->isCsrfTokenValid('espace', (string) $request->request->get('_token'))) {
            return $this->json(['erreur' => 'La page a expiré : rechargez-la.'], 403);
        }
        $modele = $this->catalogue->modele($request->request->getString('modele'));
        if (null === $modele) {
            return $this->json(['erreur' => 'Cet article n\'est plus proposé : rechargez la page.'], 422);
        }
        try {
            $lu = CreationsAtelier::lire($modele, $request->request->all());
            $creation = $this->creations->commander($compte, $lu, SiteAtelierController::rendus($request), $request->request->getString('note'), $request->getSession(), new \DateTimeImmutable());
            $commande = $creation->getCommande() ?? throw new \DomainException('Commande non enregistrée.');
            $client = $compte->getClient();
            $adresse = $request->request->getInt('adresse') > 0 ? $this->compteEntreprise->adresse($client, $request->request->getInt('adresse')) : null;
            $this->compteEntreprise->preparerCommande($compte, $commande, $request->request->getString('bon_commande'), $adresse);
        } catch (\DomainException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        }
        $this->creationsEnregistrees();
        if ($commande->attendValidationEntreprise()) {
            $this->compteEntreprise->prevenirValideurs($commande, $compte);
            $this->addFlash('succes', \sprintf('Demande %s envoyée avec votre modèle %s. Elle part à l\'atelier dès qu\'une personne qui valide pour votre entreprise l\'accepte.', $commande->getNumero(), $creation->getNumero()));
        } else {
            $this->addFlash('succes', \sprintf('Commande %s envoyée avec votre modèle %s. L\'atelier confirme le prix, prépare le BAT à partir de votre maquette, puis vous prévient.', $commande->getNumero(), $creation->getNumero()));
        }

        return $this->json(['redirection' => $this->generateUrl('app_espace_commande', ['id' => (string) $commande->getPublicId()])]);
    }

    #[Route('/creations', name: 'creations', methods: ['GET'])]
    public function creations(): Response
    {
        $compte = $this->ecrivain();

        return $this->render('espace/app/creations.html.twig', [
            'onglet' => 'commandes',
            'creations' => $this->creations->duClient($compte->getClient()),
            'modeles' => array_map(static fn ($m): string => (string) $m->getConfiguration()?->getId(), $this->catalogue->modeles()),
            'demandeValidation' => $this->compteEntreprise->demandeValidation($compte),
            'atelierOuvert' => [] !== $this->catalogue->modeles(),
        ]);
    }

    /** Vue ou image d'une création du client : jamais celle d'un autre client. */
    #[Route('/creations/{id}/fichier/{quoi}', name: 'creation_fichier', requirements: ['id' => self::UUID, 'quoi' => 'rendu-devant|rendu-dos|image-devant|image-dos'], methods: ['GET'])]
    public function fichier(string $id, string $quoi): Response
    {
        $compte = $this->ecrivain();
        $creation = $this->creations->duClientParId($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        $f = $creation->fichier($quoi);
        $chemin = null !== $f ? $this->images->chemin($f) : null;
        if (null === $chemin) {
            throw $this->createNotFoundException();
        }
        $reponse = new BinaryFileResponse($chemin);
        $reponse->headers->set('Content-Type', (string) $f->getTypeMime());
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, 'atelier-'.$quoi.'.'.pathinfo($chemin, \PATHINFO_EXTENSION));
        $reponse->setPrivate();
        $reponse->setMaxAge(3600);

        return $reponse;
    }

    /** « Commander à nouveau » : mêmes réglages et quantités, prix du jour. */
    #[Route('/creations/{id}/commander', name: 'creation_commander', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function commander(string $id, Request $request): Response
    {
        $compte = $this->ecrivain();
        if (!$this->isCsrfTokenValid('espace', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('La page a expiré. Réessayez.');
        }
        $creation = $this->creations->duClientParId($compte->getClient(), $id) ?? throw $this->createNotFoundException();
        try {
            $commande = $this->creations->recommander($compte, $creation, null, new \DateTimeImmutable());
            $this->compteEntreprise->preparerCommande($compte, $commande, null, null);
            $this->creationsEnregistrees();
        } catch (\DomainException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_espace_creations');
        }
        if ($commande->attendValidationEntreprise()) {
            $this->compteEntreprise->prevenirValideurs($commande, $compte);
            $this->addFlash('succes', \sprintf('Demande %s envoyée. Elle part à l\'atelier dès qu\'une personne qui valide pour votre entreprise l\'accepte.', $commande->getNumero()));
        } else {
            $this->addFlash('succes', \sprintf('Commande %s envoyée. L\'atelier confirme le prix et le BAT, puis vous prévient.', $commande->getNumero()));
        }

        return $this->redirectToRoute('app_espace_commande', ['id' => (string) $commande->getPublicId()]);
    }

    /** Fiche de l'article d'une création, si l'atelier le propose encore. */
    private function modeleDe(CreationAtelier $creation): ?string
    {
        foreach ($this->catalogue->modeles() as $m) {
            if ($m->getConfiguration() === $creation->getConfiguration()) {
                return $m->getPublicId()->toRfc4122();
            }
        }

        return null;
    }

    private function creationsEnregistrees(): void
    {
        $this->em->flush();
    }

    /** Le compte frais de la base, qui peut commander (pas « Lecture · suivi »). */
    private function ecrivain(): CompteClient
    {
        $session = $this->getUser();
        $compte = $session instanceof CompteClient ? $this->comptes->find($session->getId()) : null;
        if (!$compte instanceof CompteClient || null === $compte->getClient()) {
            throw $this->createAccessDeniedException();
        }
        if (!$compte->peutEcrire()) {
            throw $this->createAccessDeniedException('L\'atelier sert à commander : votre compte est en lecture seule.');
        }

        return $compte;
    }
}
