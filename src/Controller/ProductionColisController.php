<?php

namespace App\Controller;

use App\Entity\BonLivraison;
use App\Entity\ColisProduction;
use App\Entity\Commandes;
use App\Entity\User;
use App\Repository\ColisProductionRepository;
use App\Security\JetonJson;
use App\Service\Http\Entree;
use App\Service\Production\ColisCommandes;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Colis étiquetés (PD05, PD07 écran 2 ; docs/production-colis.md) :
 * étiquettes 100 × 60 mm par colis avec QR, page d'impression, scan des
 * colis (champ, douchette, caméra du téléphone) et QR d'un colis. ERP
 * local seulement (AtelierHorsLigneSubscriber). Aucun montant, aucun
 * téléphone sur les étiquettes.
 */
final class ProductionColisController extends AbstractController
{
    public const CSRF = 'production-colis';

    public function __construct(
        private readonly ColisCommandes $colis,
        private readonly ColisProductionRepository $depot,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** PD05 : travaux terminés sans étiquette, combien de colis, où coller, aperçu. */
    #[Route('/atelier/production/etiquettes', name: 'app_atelier_production_etiquettes', methods: ['GET'])]
    #[IsGranted('ROLE_PRODUCTION_VOIR')]
    public function etiquettes(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $cartes = $this->colis->aEtiqueter($maintenant);
        $id = Entree::entier($request->query, 'commande');
        $commande = $id > 0 ? $this->em->find(Commandes::class, $id) : null;
        if (null === $commande && [] !== $cartes) {
            $commande = $cartes[0]['commande'];
        }
        $choisi = null;
        if ($commande instanceof Commandes && !$commande->isDeleted()) {
            $n = $request->query->has('colis') ? Entree::entier($request->query, 'colis', 1) : null;
            $choisi = $this->colis->preparation($commande, $n, $maintenant);
            $choisi['apercus'] = array_map(fn (array $p, int $i): array => [
                'numero' => $commande->getNumero(),
                'rang' => $i + 1,
                'total' => \count($choisi['colis']),
                'client' => $choisi['client'],
                'contenu' => array_map(static fn (array $c): string => $c['libelle'], $p['contenu']),
                'livraison' => $choisi['livraison'],
                'nePasPlier' => ColisProduction::ROULEAU === $p['emballage'],
                'code' => null,
                'qr' => null,
            ], $choisi['colis'], array_keys($choisi['colis']));
        }

        return $this->render('production_colis/etiquettes.html.twig', [
            'cartes' => $cartes,
            'choisi' => $choisi,
            'ouColler' => ColisProduction::OU_COLLER,
            'peutAgir' => $this->isGranted('ROLE_PRODUCTION_GERER'),
            'maintenant' => $maintenant,
        ]);
    }

    /**
     * Imprime une série : un colis par étiquette, l'ancienne série de la
     * commande est remplacée. Rend la page d'impression (100 × 60 mm).
     */
    #[Route('/atelier/production/etiquettes/{id}/imprimer', name: 'app_atelier_production_etiquettes_imprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function imprimer(Commandes $commande, Request $request): Response
    {
        $this->jeton($request);
        try {
            $nouveaux = $this->colis->imprimer($commande, Entree::entier($request->request, 'colis', 1), $this->moi(), new \DateTimeImmutable());
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_atelier_production_etiquettes', ['commande' => $commande->getId()]);
        }

        return $this->impression($nouveaux);
    }

    /** Les mêmes étiquettes, de nouveau (étiquette abîmée, imprimante bloquée). */
    #[Route('/atelier/production/etiquettes/{id}/reimprimer', name: 'app_atelier_production_etiquettes_reimprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function reimprimer(Commandes $commande, Request $request): Response
    {
        $this->jeton($request);
        $actifs = $this->depot->actifs($commande);
        if ([] === $actifs) {
            $this->addFlash('warning', 'Aucune étiquette en cours pour cette commande : imprimez-les.');

            return $this->redirectToRoute('app_atelier_production_etiquettes', ['commande' => $commande->getId()]);
        }

        return $this->impression($actifs);
    }

    /**
     * Scan d'un colis : URL du QR, jeton ou code court (CMD-000183-C2).
     * JSON pour le scanner de la page, page de résultat pour un formulaire.
     * Avec ?commande=, seul un colis de cette commande est accepté.
     */
    #[Route('/atelier/production/colis/scanner', name: 'app_atelier_production_colis_scanner', methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function scanner(Request $request): Response
    {
        $json = str_contains((string) $request->headers->get('Content-Type'), 'json');
        $donnees = $json ? json_decode($request->getContent(), true) : $request->request->all();
        $donnees = \is_array($donnees) ? $donnees : [];
        $jeton = $json ? JetonJson::lire($request, $donnees) : (string) ($donnees['_token'] ?? '');
        if (!$this->isCsrfTokenValid(self::CSRF, $jeton)) {
            return $json ? new JsonResponse(['ok' => false, 'message' => JetonJson::MESSAGE], 403) : throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $saisie = mb_substr(trim((string) ($donnees['saisie'] ?? '')), 0, 300);
        $commandeId = Entree::entier($request->query, 'commande');
        $resultat = $this->scannerSaisie($saisie, $commandeId > 0 ? $commandeId : null);

        if ($json) {
            return new JsonResponse($resultat, $resultat['ok'] ? 200 : 422);
        }
        $retour = (string) ($donnees['retour'] ?? '');
        if ('controle' === $retour && $commandeId > 0) {
            $this->addFlash($resultat['ok'] ? 'success' : 'danger', $resultat['message'].($resultat['prete'] ?? false ? ' '.$resultat['suite'] : ''));

            return $this->redirectToRoute('app_atelier_production_controle_commande', ['id' => $commandeId]);
        }

        return $this->render('production_colis/scan_resultat.html.twig', ['r' => $resultat], new Response(null, $resultat['ok'] ? 200 : 422));
    }

    /** PD07 écran 2 : scanner une étiquette avec le téléphone. */
    #[Route('/atelier/operateur/scanner', name: 'app_atelier_operateur_scanner', methods: ['GET'])]
    #[IsGranted('ROLE_PRODUCTION_VOIR')]
    public function scannerTelephone(): Response
    {
        return $this->render('production_colis/scanner.html.twig', [
            'peutAgir' => $this->isGranted('ROLE_PRODUCTION_GERER'),
            // Début des liens de nos QR (« http://192.168.1.10/c/ ») : la caméra n'accepte que ceux-là.
            'prefixe' => substr($this->generateUrl('app_colis_qr', ['jeton' => str_repeat('0', 64)], UrlGeneratorInterface::ABSOLUTE_URL), 0, -64),
            'jeton' => self::CSRF,
        ]);
    }

    /**
     * QR d'une étiquette. L'atelier : page de confirmation (le scan se fait
     * par un bouton, jamais à l'ouverture). Le livreur : la remise de sa
     * livraison, comme les anciennes étiquettes (ColisController).
     */
    #[Route('/c/{jeton}', name: 'app_colis_qr', requirements: ['jeton' => '[a-f0-9]{64}'], methods: ['GET'])]
    #[IsGranted(new Expression("is_granted('ROLE_PRODUCTION_GERER') or is_granted('ROLE_LIVRAISON')"))]
    public function qr(#[MapEntity(mapping: ['jeton' => 'jeton'])] ColisProduction $colis): Response
    {
        $commande = $colis->getCommande();
        if (!$this->isGranted('ROLE_PRODUCTION_GERER')) {
            $bon = $this->em->getRepository(BonLivraison::class)->findOneBy(['commande' => $commande, 'statut' => BonLivraison::STATUT_VALIDE], ['id' => 'DESC']);
            if (null !== $bon && $bon->getLivreur() === $this->getUser()) {
                return $this->redirectToRoute('app_livraisons_remise', ['id' => $bon->getId()]);
            }
            if ($this->isGranted('ROLE_COMMANDE')) {
                return $this->redirectToRoute('app_livraisons_commande', ['id' => $commande->getId()]);
            }
            $this->addFlash('warning', \sprintf('Colis de la commande %s : il n\'est pas dans votre tournée.', $commande->getNumero()));

            return $this->redirectToRoute('app_livraisons_tournee');
        }

        return $this->render('production_colis/qr.html.twig', [
            'colis' => $colis,
            'etiquette' => $this->colis->etiquette($colis),
            'etat' => $this->colis->etat($commande),
        ]);
    }

    /**
     * @return array{ok: bool, message: string, nouveau?: bool, prete?: bool, suite?: string, colis?: array<string, mixed>}
     */
    private function scannerSaisie(string $saisie, ?int $commandeId): array
    {
        $lu = ColisProduction::lireSaisie($saisie);
        $colis = null !== $lu ? $this->depot->trouver($lu) : null;
        if (null === $colis) {
            return ['ok' => false, 'message' => '' === $saisie ? 'Scannez ou tapez le code sous le QR.' : 'Ce code n\'est pas une étiquette de colis Succès Imprim.'];
        }
        $commande = $colis->getCommande();
        $carte = [
            'numero' => $commande->getNumero(),
            'rang' => $colis->getRang(),
            'total' => $colis->getTotal(),
            'code' => $colis->getCode(),
            'client' => ColisCommandes::client($commande->getClients())['nom'],
            'contenu' => $colis->getContenuTexte(),
            'controle' => $this->generateUrl('app_atelier_production_controle_commande', ['id' => $commande->getId()]),
        ];
        if (null !== $commandeId && $commande->getId() !== $commandeId) {
            return ['ok' => false, 'message' => \sprintf('Ce colis est celui de %s, pas de cette commande.', $commande->getNumero()), 'colis' => $carte];
        }
        try {
            $r = $this->colis->scanner($colis, $this->moi(), new \DateTimeImmutable());
        } catch (\LogicException $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'colis' => $carte];
        }
        $demo = ColisCommandes::client($commande->getClients())['demo'];
        $message = $r['nouveau']
            ? \sprintf('Colis %d/%d scanné.', $colis->getRang(), $colis->getTotal())
            : \sprintf('Déjà scanné à %s.', $colis->getScanneLe()?->format('H:i'));
        $suite = match (true) {
            $r['prete'] => \sprintf('%d colis sur %d scannés : commande prête, %s.', $r['scannes'], $r['total'], $demo ? 'fiche de démonstration : aucun message' : 'client prévenu'),
            $r['scannes'] >= $r['total'] => \sprintf('%d colis sur %d scannés : le contrôle final reste à valider.', $r['scannes'], $r['total']),
            default => \sprintf('%d colis sur %d scannés.', $r['scannes'], $r['total']),
        };

        return ['ok' => true, 'message' => $message, 'nouveau' => $r['nouveau'], 'prete' => $r['prete'], 'suite' => $suite, 'colis' => $carte];
    }

    /** @param list<ColisProduction> $colis */
    private function impression(array $colis): Response
    {
        $etiquettes = [];
        foreach ($colis as $c) {
            $e = $this->colis->etiquette($c);
            $etiquettes[] = [
                'numero' => $e['numero'],
                'rang' => $c->getRang(),
                'total' => $c->getTotal(),
                'client' => $e['client'],
                'contenu' => $e['contenu'],
                'livraison' => $e['livraison'],
                'nePasPlier' => $e['nePasPlier'],
                'code' => $c->getCode(),
                'qr' => $this->qrImage($c),
            ];
        }

        return $this->render('production_colis/impression.html.twig', [
            'etiquettes' => $etiquettes,
            'commande' => $colis[0]->getCommande(),
        ]);
    }

    private function qrImage(ColisProduction $colis): string
    {
        $url = $this->generateUrl('app_colis_qr', ['jeton' => $colis->getJeton()], UrlGeneratorInterface::ABSOLUTE_URL);

        return (new PngWriter())->write(new QrCode(data: $url, encoding: new Encoding('UTF-8'), errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 240, margin: 4, roundBlockSizeMode: RoundBlockSizeMode::Margin))->getDataUri();
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    private function moi(): User
    {
        $moi = $this->getUser();
        \assert($moi instanceof User);

        return $moi;
    }
}
