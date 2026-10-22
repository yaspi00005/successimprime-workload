<?php

namespace App\Controller;

use App\Entity\DemandeDevis;
use App\Service\Atelier\CatalogueAtelier;
use App\Service\Atelier\CreationsAtelier;
use App\Service\Atelier\ImagesAtelier;
use App\Service\Atelier\TarifAtelier;
use App\Service\Http\Entree;
use App\Service\Site\FormulaireDemande;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Atelier 3D du site public (docs/atelier-3d.md), sans compte : le
 * visiteur compose son t-shirt, son polo ou sa casquette, voit le prix
 * public calculé ici, et « Valider mon modèle » envoie une demande de
 * devis (mêmes protections que la page Demander un devis). Un client qui
 * se connecte retrouve l'atelier dans son espace, à ses prix.
 */
final class SiteAtelierController extends AbstractController
{
    public const CSRF = 'site-atelier';

    public function __construct(
        private readonly CatalogueAtelier $catalogue,
        private readonly CreationsAtelier $creations,
        private readonly ImagesAtelier $images,
        private readonly TarifAtelier $tarif,
        #[Autowire('%kernel.secret%')] private readonly string $secret,
        #[Autowire(service: 'limiter.site_demande_ip')] private readonly RateLimiterFactoryInterface $limiteDemande,
        #[Autowire(service: 'limiter.atelier_image_ip')] private readonly RateLimiterFactoryInterface $limiteImage,
    ) {
    }

    /**
     * Ancienne adresse du studio textile (« Atelier 3D », jusqu'au 19/10/2026) :
     * renvoyée en 301 vers /studio-textile, paramètres gardés, pour les liens
     * déjà partagés et les moteurs de recherche.
     */
    #[Route('/atelier-3d', name: 'app_site_atelier_ancienne', methods: ['GET'])]
    public function ancienneAdresse(Request $request): Response
    {
        return $this->redirectToRoute('app_site_atelier', $request->query->all(), Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('/studio-textile', name: 'app_site_atelier', methods: ['GET'])]
    public function atelier(): Response
    {
        $modeles = $this->catalogue->modeles();
        $fiches = array_map(function ($m): array {
            $f = $this->catalogue->fiche($m);
            $f['nom'] = SiteDemandeController::sansDemo($f['nom']);

            return $f;
        }, $modeles);

        $reponse = $this->render('site/atelier.html.twig', [
            'fiches' => $fiches,
            'paliers' => $this->tarif->reglages()['paliers'],
            'jeton' => FormulaireDemande::jetonDelai(time(), $this->secret),
            'envoiMax' => ImagesAtelier::envoiMax(),
        ]);
        // Jeton du formulaire : jamais en cache.
        $reponse->setPrivate();
        $reponse->headers->addCacheControlDirective('no-store');

        return $reponse;
    }

    /** Prix public (le visiteur n'a ni prix B2B ni promotion). */
    #[Route('/studio-textile/prix', name: 'app_site_atelier_prix', methods: ['GET'])]
    public function prix(Request $request): JsonResponse
    {
        $modele = $this->catalogue->modele($request->query->getString('modele'));
        if (null === $modele) {
            return $this->json(['erreur' => 'Article indisponible.'], 404);
        }
        $estimation = $this->catalogue->estimer($modele, Entree::entier($request->query, 'quantite'), Entree::entier($request->query, 'faces', 1), null, true, new \DateTimeImmutable('today'));
        $reponse = $this->json($estimation);
        $reponse->setPrivate();
        $reponse->headers->addCacheControlDirective('no-store');

        return $reponse;
    }

    #[Route('/studio-textile/image', name: 'app_site_atelier_image', methods: ['POST'])]
    public function image(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['erreur' => 'La page a expiré : rechargez-la.'], 403);
        }

        return self::deposer($request, $this->images, $this->limiteImage);
    }

    /** « Valider mon modèle » : demande de devis sans compte, jamais de prix envoyé par la page. */
    #[Route('/studio-textile/valider', name: 'app_site_atelier_valider', methods: ['POST'])]
    public function valider(Request $request): JsonResponse
    {
        $donnees = $request->request->all();
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->json(['erreur' => 'La page a expiré : rechargez-la, votre modèle reste à refaire.'], 403);
        }
        $session = $request->getSession();
        if (FormulaireDemande::estPiege($donnees)) {
            // Un robot : rien n'est enregistré, et il ne le sait pas.
            $session->set(SiteDemandeController::SESSION, ['nature' => DemandeDevis::NATURE_DEVIS, 'numero' => null, 'accuse' => false]);

            return $this->json(['redirection' => $this->generateUrl('app_site_devis_envoyee')]);
        }
        if (null !== $erreur = FormulaireDemande::verifierDelai((string) $request->request->get('_t', ''), time(), $this->secret)) {
            return $this->json(['erreur' => $erreur], 422);
        }
        ['valeurs' => $valeurs, 'erreurs' => $erreurs] = FormulaireDemande::valider(DemandeDevis::NATURE_DEVIS, $donnees, []);
        // Le modèle décrit le projet : ni produit choisi ni message obligatoires (un message trop long reste refusé).
        unset($erreurs['produit'], $erreurs['quantite']);
        if (mb_strlen(trim((string) ($donnees['message'] ?? ''))) <= DemandeDevis::MESSAGE_MAX) {
            unset($erreurs['message']);
        }
        if ([] !== $erreurs) {
            return $this->json(['erreurs' => $erreurs, 'erreur' => 'Vérifiez vos coordonnées.'], 422);
        }
        $modele = $this->catalogue->modele($request->request->getString('modele'));
        if (null === $modele) {
            return $this->json(['erreur' => 'Cet article n\'est plus proposé : rechargez la page.'], 422);
        }
        try {
            $lu = CreationsAtelier::lire($modele, $donnees);
            $rendus = self::rendus($request);
        } catch (\DomainException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        }
        if (!$this->limiteDemande->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return $this->json(['erreur' => 'Trop d\'envois depuis cette connexion. Réessayez dans une heure, ou écrivez-nous sur WhatsApp.'], 429);
        }

        try {
            $demande = $this->creations->demanderDevis($lu, $rendus, $valeurs, $session, new \DateTimeImmutable());
        } catch (\DomainException $e) {
            return $this->json(['erreur' => $e->getMessage()], 422);
        }
        $session->set(SiteDemandeController::SESSION, [
            'nature' => DemandeDevis::NATURE_DEVIS,
            'numero' => $demande->getNumero(),
            'accuse' => null !== $demande->getAccuseEnvoyeLe(),
            'resume' => SiteDemandeController::sansDemo($demande->getResume()),
            'fichier' => true,
        ]);

        return $this->json(['redirection' => $this->generateUrl('app_site_devis_envoyee')]);
    }

    /**
     * Dépôt d'une image (site et espace client) : limite par connexion,
     * contrôle et réencodage, résultat sans chemin.
     */
    public static function deposer(Request $request, ImagesAtelier $images, RateLimiterFactoryInterface $limite): JsonResponse
    {
        $fichier = $request->files->get('image');
        if (!$fichier instanceof UploadedFile) {
            return new JsonResponse(['erreur' => (int) $request->server->get('CONTENT_LENGTH') > 0
                ? \sprintf('Image trop lourde : %d Mo au plus.', intdiv(ImagesAtelier::envoiMax(), 1024 * 1024))
                : 'Choisissez une image PNG ou JPG.'], 422);
        }
        if (!$limite->create((string) $request->getClientIp())->consume()->isAccepted()) {
            return new JsonResponse(['erreur' => 'Trop d\'images envoyées depuis cette connexion : réessayez dans une heure.'], 429);
        }
        try {
            return new JsonResponse($images->deposer($fichier, $request->getSession(), new \DateTimeImmutable()));
        } catch (\DomainException $e) {
            return new JsonResponse(['erreur' => $e->getMessage()], 422);
        }
    }

    /**
     * Vues de face et de dos envoyées par la page.
     *
     * @return array<string, string> face => octets
     *
     * @throws \DomainException
     */
    public static function rendus(Request $request): array
    {
        $rendus = [];
        foreach (['devant', 'dos'] as $face) {
            $f = $request->files->get('rendu_'.$face);
            if (!$f instanceof UploadedFile || !$f->isValid() || (int) $f->getSize() > ImagesAtelier::RENDU_OCTETS_MAX) {
                throw new \DomainException('Vues du modèle manquantes : réessayez.');
            }
            $rendus[$face] = (string) file_get_contents($f->getPathname());
        }

        return $rendus;
    }
}
