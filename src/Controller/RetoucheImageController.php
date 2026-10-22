<?php

namespace App\Controller;

use App\Entity\CommandeDetailFichier;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\Format;
use App\Entity\MessageClient;
use App\Entity\User;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Http\Entree;
use App\Service\Prepresse\ConversionPdf;
use App\Service\Prepresse\GenerateurRetouche;
use App\Service\Prepresse\GeometrieRetouche as G;
use App\Service\Prepresse\PdfImage;
use App\Service\SuiviMetiers\TableauConception;
use App\Service\TableauProduction;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Retouche d'une image client en prépresse (maquettes PP01 à PP05,
 * docs/prepresse-retouche.md) : redimensionner au format fini, contour
 * intérieur, contrôles, puis fichier final rangé dans la commande en
 * nouvelle version, éventuellement envoyé comme BAT.
 *
 * L'aperçu se calcule dans le navigateur (public/js/retouche-image.js) ;
 * le fichier final est refait ici, avec GD, à partir de l'original, qui
 * n'est jamais modifié. Sous /pre-presse : réservé à la prépresse (et à
 * la direction), comme le reste de l'écran prépresse (security.yaml).
 */
#[Route('/pre-presse/travail/{id}/retouche', name: 'app_controle_pre_presse_retouche_', requirements: ['id' => '\d+'])]
final class RetoucheImageController extends AbstractController
{
    private const CSRF = 'retouche_image';
    public const DESIGNATION = 'Retouche prépresse';
    private const RAISON_MIN = 5;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('', name: 'outil', methods: ['GET'])]
    public function outil(CommandesDetails $detail, Request $request): Response
    {
        $this->denyAccessUnlessGranted('ROLE_PREPRESSE');
        $commande = $detail->getCommande() ?? throw $this->createNotFoundException();

        $images = $this->images($detail);
        $choisi = null;
        $idDemande = Entree::entier($request->query, 'fichier');
        foreach ($images as $f) {
            if ($f->getId() === $idDemande) {
                $choisi = $f;
            }
        }
        // Par défaut : le dernier original du client, sinon la dernière image de la ligne.
        $choisi ??= array_values(array_filter($images, static fn (CommandeDetailFichier $f): bool => CommandeDetailFichier::ORIGINE_CLIENT === $f->getOrigine()))[0] ?? $images[0] ?? null;
        $chemin = null !== $choisi ? $this->chemin($choisi) : null;
        $source = null !== $chemin ? GenerateurRetouche::lireSource($chemin) : null;

        [$largeurMm, $hauteurMm] = self::tailleCommandee($detail, $this->em);
        $config = null;
        if (null !== $choisi && null !== $source) {
            $l = $largeurMm ?? round($source['largeur'] / 300 * 25.4, 1);
            $h = $hauteurMm ?? round($source['hauteur'] / 300 * 25.4, 1);
            // Fichier livré à l'échelle (1/10 pour un grand format) : sa taille physique fois l'échelle donne la commande.
            $recu = G::tailleRecue($source['largeur'], $source['hauteur'], $source['dpi']);
            $echelle = G::echelleDetectee($recu['largeurMm'], $recu['hauteurMm'], $largeurMm, $hauteurMm);
            $client = $commande->getClients();
            $config = [
                'source' => [
                    'id' => $choisi->getId(),
                    'nom' => $choisi->getNomOriginal(),
                    'url' => $this->generateUrl('app_controle_pre_presse_telecharger_fichier', ['id' => $choisi->getId()]),
                    'largeur' => $source['largeur'],
                    'hauteur' => $source['hauteur'],
                    'type' => $source['type'],
                    'cmjn' => $source['cmjn'],
                    'transparence' => $source['transparence'],
                    'dpi' => $source['dpi'],
                    'taille' => $choisi->getTaille(),
                ],
                'commande' => [
                    'numero' => $commande->getNumero(),
                    'largeurMm' => $largeurMm,
                    'hauteurMm' => $hauteurMm,
                    'client' => null !== $client,
                    'telephone' => null !== $client && '' !== trim((string) $client->getTelephone()),
                ],
                'defaut' => [
                    'largeurMm' => $l,
                    'hauteurMm' => $h,
                    'mode' => G::RECADRER,
                    'dpiSortie' => G::dpiSortieConseille($l, $h),
                ],
                'formats' => $this->formatsRapides($largeurMm, $hauteurMm),
                'recu' => $recu + ['echelle' => $echelle, 'echelles' => G::ECHELLES],
                'seuils' => [
                    'petitFormatMaxMm' => G::PETIT_FORMAT_MAX_MM,
                    'dpiMinGrand' => G::DPI_MIN_GRAND_FORMAT,
                    'dpiPetit' => G::DPI_PETIT_FORMAT,
                    'dpiMinPetit' => G::DPI_MIN_PETIT_FORMAT,
                    'deformation' => G::DEFORMATION_TOLEREE,
                    'sortieMaxPx' => G::SORTIE_MAX_PX,
                    'fondPerduMm' => G::FOND_PERDU_MM,
                    'toleranceFond' => GenerateurRetouche::TOLERANCE_FOND,
                    'raisonMin' => self::RAISON_MIN,
                ],
                'version' => $this->prochaineVersion($detail),
                'bat' => $this->prochainBat($detail),
                'urls' => [
                    'generer' => $this->generateUrl('app_controle_pre_presse_retouche_generer', ['id' => $detail->getId()]),
                    'demander' => $this->generateUrl('app_controle_pre_presse_retouche_demander_fichier', ['id' => $detail->getId()]),
                    'prepresse' => $this->generateUrl('app_controle_pre_presse_controler', ['id' => $detail->getId()]),
                ],
                'messageClient' => $this->messageFichierPlusGrand($detail, $source),
                'jeton' => $this->container->get('security.csrf.token_manager')->getToken(self::CSRF)->getValue(),
            ];
        }

        $pdfs = $this->pdfs($detail);
        $pdfDemande = Entree::entier($request->query, 'pdf');

        return $this->render('controle_pre_presse/retouche.html.twig', [
            'pdfs' => $pdfs,
            'conversion' => [] === $pdfs ? null : [
                'pdfs' => array_map(fn (CommandeDetailFichier $f): array => [
                    'id' => $f->getId(),
                    'nom' => $f->getNomOriginal(),
                    'version' => $f->getVersion(),
                    'client' => CommandeDetailFichier::ORIGINE_CLIENT === $f->getOrigine(),
                    'url' => $this->generateUrl('app_controle_pre_presse_telecharger_fichier', ['id' => $f->getId()]),
                ], $pdfs),
                'ouvrir' => \in_array($pdfDemande, array_map(static fn (CommandeDetailFichier $f): ?int => $f->getId(), $pdfs), true) ? $pdfDemande : null,
                'commande' => ['numero' => $commande->getNumero(), 'largeurMm' => $largeurMm, 'hauteurMm' => $hauteurMm],
                'seuils' => [
                    'petitFormatMaxMm' => G::PETIT_FORMAT_MAX_MM,
                    'echelles' => G::ECHELLES,
                    'echelleToleree' => G::ECHELLE_TOLEREE,
                    'coteMaxPx' => ConversionPdf::COTE_MAX_PX,
                    'pixelsMax' => ConversionPdf::PIXELS_MAX,
                    'dpiMin' => ConversionPdf::DPI_MIN,
                    'dpiMax' => ConversionPdf::DPI_MAX,
                    'tailleMax' => (int) min(ConversionPdf::TAILLE_MAX, UploadedFile::getMaxFilesize()),
                ],
                'urls' => [
                    'convertir' => $this->generateUrl('app_controle_pre_presse_retouche_convertir_pdf', ['id' => $detail->getId()]),
                ],
                'jeton' => $this->container->get('security.csrf.token_manager')->getToken(self::CSRF)->getValue(),
            ],
            'detail' => $detail,
            'commande' => $commande,
            'fichier' => $choisi,
            'source' => $source,
            'config' => $config,
            'images' => $images,
            'autres' => $this->imagesDeLaCommande($detail),
            'dossier' => $this->dossier($detail),
        ]);
    }

    /**
     * Génère le fichier final à partir de l'original et le range dans la
     * ligne en nouvelle version (jamais à la place de l'original). Avec
     * action=bat, il part aussi comme BAT au client.
     */
    #[Route('/generer', name: 'generer', methods: ['POST'])]
    public function generer(CommandesDetails $detail, Request $request, GenerateurRetouche $generateur, TableauConception $conceptions): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_PREPRESSE');
        $d = $this->donnees($request);
        if (!$this->isCsrfTokenValid(self::CSRF, (string) ($d['_token'] ?? ''))) {
            return $this->json(['message' => 'La page a expiré : rechargez-la.'], Response::HTTP_FORBIDDEN);
        }
        $moi = $this->getUser();
        \assert($moi instanceof User);
        $commande = $detail->getCommande() ?? throw $this->createNotFoundException();

        $source = null;
        foreach ($this->images($detail) as $f) {
            if ($f->getId() === (int) ($d['fichier'] ?? 0)) {
                $source = $f;
            }
        }
        $cheminSource = null !== $source ? $this->chemin($source) : null;
        $info = null !== $cheminSource ? GenerateurRetouche::lireSource($cheminSource) : null;
        if (null === $source || null === $info) {
            return $this->json(['message' => 'Fichier d\'origine introuvable ou illisible.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $reglages = G::reglages(\is_array($d['reglages'] ?? null) ? $d['reglages'] : []);
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $format = \in_array($d['format'] ?? null, GenerateurRetouche::FORMATS, true) ? $d['format'] : 'pdf';
        if ('jpeg' === $format && 'transparent' === $reglages['fond']) {
            $reglages['fond'] = '#FFFFFF';
        }

        // Les contrôles sont refaits ici : le navigateur ne décide pas seul.
        $controles = G::controles($info['largeur'], $info['hauteur'], $reglages);
        $bloquants = G::bloquants($controles);
        $raison = trim(mb_substr((string) ($d['raison'] ?? ''), 0, 300));
        if ([] !== array_filter($bloquants, static fn (array $c): bool => 'sortie' === $c['cle'])) {
            return $this->json(['message' => 'Fichier de sortie trop grand : baissez la résolution de sortie.', 'controles' => $controles], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if ([] !== $bloquants && mb_strlen($raison) < self::RAISON_MIN) {
            return $this->json(['message' => 'Contrôles non résolus : corrigez les réglages, ou validez quand même en donnant la raison.', 'controles' => $controles], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $version = $this->prochaineVersion($detail);
        $extension = 'jpeg' === $format ? 'jpg' : $format;
        $nom = self::nomFichier((string) ($d['nom'] ?? ''), $extension) ?? self::nomParDefaut((string) $commande->getNumero(), $reglages, $version, $extension);
        $dossier = $this->getParameter('kernel.project_dir').'/var/uploads/commandes';
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            return $this->json(['message' => 'Dossier des fichiers des commandes introuvable sur le serveur.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $nomStockage = 'retouche-'.$detail->getId().'-'.bin2hex(random_bytes(12)).'.'.$extension;
        $cible = $dossier.'/'.$nomStockage;
        $empreinteOriginal = hash_file('sha256', $cheminSource);

        @set_time_limit(300);
        try {
            $resultat = $generateur->generer($cheminSource, $reglages, $format, $cible.'.tmp', \sprintf('%s · %s', $commande->getNumero(), $nom));
            rename($cible.'.tmp', $cible);
        } catch (\DomainException $e) {
            @unlink($cible.'.tmp');

            return $this->json(['message' => $e->getMessage(), 'contourImpossible' => null !== $reglages['contour'] && 'forme' === $reglages['contour']['type']], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Vérifications du fichier écrit (maquette PP05).
        $p = G::placement($info['largeur'], $info['hauteur'], $reglages['largeurMm'], $reglages['hauteurMm'], $reglages['mode'], $reglages['fondPerdu'], $reglages['dpiSortie']);
        $lisible = 'pdf' === $format
            ? null !== ($t = PdfImage::lireTaille($cible)) && abs($t['largeurMm'] - $p['sortieL']) < 0.5 && abs($t['hauteurMm'] - $p['sortieH']) < 0.5
            : false !== ($i = @getimagesize($cible)) && $i[0] === $resultat['largeurPx'] && $i[1] === $resultat['hauteurPx'];
        $originalIntact = is_file($cheminSource) && hash_file('sha256', $cheminSource) === $empreinteOriginal;
        if (!$lisible || !$originalIntact) {
            @unlink($cible);

            return $this->json(['message' => !$originalIntact ? 'L\'original a changé pendant la génération : rien n\'a été enregistré.' : 'Le fichier généré ne se relit pas correctement : rien n\'a été enregistré. Réessayez.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        $derogation = [] !== $bloquants ? ['raison' => $raison, 'alertes' => array_map(static fn (array $c): string => $c['valeur'], $bloquants)] : null;
        $retouche = [
            'reglages' => $reglages,
            'format' => $format,
            'source' => ['id' => $source->getId(), 'nom' => $source->getNomOriginal(), 'largeur' => $info['largeur'], 'hauteur' => $info['hauteur']],
            'sortie' => ['largeurPx' => $resultat['largeurPx'], 'hauteurPx' => $resultat['hauteurPx'], 'largeurMm' => $p['sortieL'], 'hauteurMm' => $p['sortieH']],
            'controles' => $controles,
            'derogation' => $derogation,
            'couleurs' => 'RVB',
            'par' => $moi->getUserIdentifier(),
            'le' => (new \DateTimeImmutable())->format(\DATE_ATOM),
        ];
        $resume = self::resume($retouche);
        $fichier = (new CommandeDetailFichier())
            ->setCommandeDetail($detail)
            ->setFichierSource($source)
            ->setJetonUpload(bin2hex(random_bytes(32)))
            ->setNomOriginal($nom)
            ->setNomStockage($nomStockage)
            ->setTypeMime(match ($format) { 'png' => 'image/png', 'jpeg' => 'image/jpeg', default => 'application/pdf' })
            ->setTaille((int) filesize($cible))
            ->setEmpreinte((string) hash_file('sha256', $cible))
            ->setOrigine(CommandeDetailFichier::ORIGINE_INTERNE)
            ->setEtat(CommandeDetailFichier::ETAT_TRAITE)
            ->setVersion($version)
            ->setFace($source->getFace())
            ->setDesignation(self::DESIGNATION)
            ->setObservation($resume)
            ->setRetouche($retouche)
            ->setRemarqueBat(null !== $derogation ? self::remarqueClient($bloquants, $raison) : null)
            ->setAjoutePar($moi)
            ->marquerUploadTermine();
        $detail->addFichier($fichier);
        $this->em->persist($fichier);
        $this->em->flush();

        $reponse = [
            'message' => \sprintf('%s généré et rangé dans %s (version %d). L\'original reste intact.', $nom, $commande->getNumero(), $version),
            'fichier' => ['id' => $fichier->getId(), 'nom' => $nom, 'version' => $version, 'url' => $this->generateUrl('app_controle_pre_presse_telecharger_fichier', ['id' => $fichier->getId()])],
            'retour' => $this->generateUrl('app_controle_pre_presse_controler', ['id' => $detail->getId(), 'fichier' => $fichier->getId()]),
        ];

        if ('bat' === ($d['action'] ?? null)) {
            $numero = $this->prochainBat($detail);
            $controle = (new ControlePrePresse())->setCommandeDetail($detail);
            $controle->addFichier($fichier);
            $controle->commencerControle($moi);
            $controle
                ->setBatNecessaire(true)
                ->setBatValide(false)
                ->setCorrectionNecessaire(false)
                ->setCorrectionsEffectuees($resume)
                ->setObservation(null !== $derogation ? 'Validé malgré une alerte. Raison : '.$raison : null)
                ->setStatut(ControlePrePresse::STATUT_EN_ATTENTE_BAT);
            $detail->addControlePrePresse($controle);
            $this->em->persist($controle);
            $this->em->flush();
            $conceptions->batEnvoyeDepuisPrepresse($controle, $moi, new \DateTimeImmutable());
            $reponse['message'] = \sprintf('%s généré et envoyé comme BAT n°%d. L\'original reste intact.', $nom, $numero);
            $telephone = trim((string) $commande->getClients()?->getTelephone());
            if ('' !== $telephone) {
                $reponse['whatsapp'] = 'https://wa.me/'.UtilisateursController::numeroWhatsApp($telephone).'?text='.rawurlencode(\sprintf(
                    "Bonjour, le BAT n°%d de votre %s, commande %s, est prêt chez Succès Imprim. Merci de nous dire s'il est bon à imprimer ou ce qu'il faut corriger. Suivi : %s",
                    $numero,
                    TableauProduction::titreClient($detail),
                    $commande->getNumero(),
                    $this->generateUrl('app_espace_suivre', ['numero' => $commande->getNumero()], UrlGeneratorInterface::ABSOLUTE_URL),
                ));
            }
        }

        return $this->json($reponse);
    }

    /**
     * Conversion d'un PDF de la ligne en image (docs/prepresse-retouche.md) :
     * la page est rendue dans le navigateur (pdf.js) ; ici, l'image reçue est
     * vérifiée puis rangée en nouvelle version, reliée au PDF, qui n'est
     * jamais modifié. L'outil de retouche s'ouvre ensuite sur l'image.
     */
    #[Route('/convertir-pdf', name: 'convertir_pdf', methods: ['POST'])]
    public function convertirPdf(CommandesDetails $detail, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_PREPRESSE');
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return $this->json(['message' => 'La page a expiré : rechargez-la.'], Response::HTTP_FORBIDDEN);
        }
        $moi = $this->getUser();
        \assert($moi instanceof User);
        $commande = $detail->getCommande() ?? throw $this->createNotFoundException();

        $pdf = null;
        foreach ($this->pdfs($detail) as $f) {
            if ($f->getId() === (int) $request->request->get('pdf')) {
                $pdf = $f;
            }
        }
        if (null === $pdf) {
            return $this->json(['message' => 'PDF d\'origine introuvable sur ce travail.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $image = $request->files->get('image');
        if (!$image instanceof UploadedFile) {
            return $this->json(['message' => 'Aucune image reçue : l\'envoi est peut-être trop lourd pour ce serveur. Baissez la résolution ou choisissez JPEG.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        if (!$image->isValid()) {
            return $this->json(['message' => \in_array($image->getError(), [\UPLOAD_ERR_INI_SIZE, \UPLOAD_ERR_FORM_SIZE], true)
                ? \sprintf('Image trop lourde pour ce serveur (%d Mo au plus) : baissez la résolution ou choisissez JPEG.', intdiv((int) UploadedFile::getMaxFilesize(), 1048576))
                : 'L\'image n\'est pas arrivée entière : réessayez.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $info = ConversionPdf::verifierImage($image->getPathname(), (int) $image->getSize());
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $reglages = ConversionPdf::reglages($request->request->all());
        $version = $this->prochaineVersion($detail);
        $nom = self::nomFichier((string) $request->request->get('nom'), $info['extension'])
            ?? ConversionPdf::nomParDefaut((string) $pdf->getNomOriginal(), $reglages['page'], $reglages['pages'], $reglages['dpi'], $info['extension']);
        $dossier = $this->getParameter('kernel.project_dir').'/var/uploads/commandes';
        if (!is_dir($dossier) && !mkdir($dossier, 0775, true) && !is_dir($dossier)) {
            return $this->json(['message' => 'Dossier des fichiers des commandes introuvable sur le serveur.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        $nomStockage = 'conversion-'.$detail->getId().'-'.bin2hex(random_bytes(12)).'.'.$info['extension'];
        $taille = (int) $image->getSize();
        $image->move($dossier, $nomStockage);
        $cible = $dossier.'/'.$nomStockage;

        $fichier = (new CommandeDetailFichier())
            ->setCommandeDetail($detail)
            ->setFichierSource($pdf)
            ->setJetonUpload(bin2hex(random_bytes(32)))
            ->setNomOriginal($nom)
            ->setNomStockage($nomStockage)
            ->setTypeMime($info['type'])
            ->setTaille($taille)
            ->setEmpreinte((string) hash_file('sha256', $cible))
            ->setOrigine(CommandeDetailFichier::ORIGINE_INTERNE)
            ->setEtat(CommandeDetailFichier::ETAT_TRAITE)
            ->setVersion($version)
            ->setFace($pdf->getFace())
            ->setDesignation(ConversionPdf::DESIGNATION)
            ->setObservation(ConversionPdf::observation((string) $pdf->getNomOriginal(), $reglages, $info['largeur'], $info['hauteur'], $info['extension']))
            ->setAjoutePar($moi)
            ->marquerUploadTermine();
        $detail->addFichier($fichier);
        $this->em->persist($fichier);
        $this->em->flush();

        return $this->json([
            'message' => \sprintf('%s rangé dans %s (version %d). Le PDF reste intact.', $nom, $commande->getNumero(), $version),
            'fichier' => ['id' => $fichier->getId(), 'nom' => $nom, 'version' => $version],
            'retouche' => $this->generateUrl('app_controle_pre_presse_retouche_outil', ['id' => $detail->getId(), 'fichier' => $fichier->getId()]),
        ]);
    }

    /**
     * « Demander un fichier plus grand au client » (maquette PP04) : un
     * message dans la conversation de la commande, côté espace client.
     */
    #[Route('/demander-fichier', name: 'demander_fichier', methods: ['POST'])]
    public function demanderFichier(CommandesDetails $detail, Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_PREPRESSE');
        $d = $this->donnees($request);
        if (!$this->isCsrfTokenValid(self::CSRF, (string) ($d['_token'] ?? ''))) {
            return $this->json(['message' => 'La page a expiré : rechargez-la.'], Response::HTTP_FORBIDDEN);
        }
        $moi = $this->getUser();
        \assert($moi instanceof User);
        $commande = $detail->getCommande() ?? throw $this->createNotFoundException();
        $client = $commande->getClients();
        if (null === $client) {
            return $this->json(['message' => 'Cette commande n\'a pas de client : prévenez-le par téléphone.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $this->envoyerAuClient($commande, $moi, (string) ($d['texte'] ?? ''));
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['message' => \sprintf('Message envoyé à %s dans la conversation de la commande %s.', $client->getNomComplet(), $commande->getNumero())]);
    }

    /**
     * Seul point d'écriture dans la messagerie client (refaite par un autre
     * chantier) : un message de l'atelier rattaché à la commande, signé du
     * prénom et du métier, sans classer les messages du client.
     */
    private function envoyerAuClient(\App\Entity\Commandes $commande, User $moi, string $texte): void
    {
        $texte = \App\Service\EspaceClient\MessagesClient::verifier($texte);
        $message = MessageClient::nouveau($commande->getClients(), MessageClient::ATELIER, $texte, $moi->getEmploye()?->getNomPublic() ?? MessageClient::NOM_ATELIER, $commande, new \DateTimeImmutable());
        $message->setEcritPar($moi);
        $this->em->persist($message);
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    private function donnees(Request $request): array
    {
        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $request->request->all();
        }
    }

    /**
     * Images de la ligne qu'on peut retoucher, de la plus récente à la plus ancienne.
     *
     * @return list<CommandeDetailFichier>
     */
    private function images(CommandesDetails $detail): array
    {
        $images = array_values(array_filter(
            $detail->getFichiers()->toArray(),
            static fn (CommandeDetailFichier $f): bool => $f->isActif() && $f->estUploadTermine() && \in_array($f->getTypeMime(), GenerateurRetouche::TYPES_SOURCE, true),
        ));
        usort($images, static fn (CommandeDetailFichier $a, CommandeDetailFichier $b): int => [$b->getVersion(), $b->getId()] <=> [$a->getVersion(), $a->getId()]);

        return $images;
    }

    /**
     * PDF de la ligne (fichiers du client et versions), qu'on peut convertir
     * en image, du plus récent au plus ancien.
     *
     * @return list<CommandeDetailFichier>
     */
    private function pdfs(CommandesDetails $detail): array
    {
        $pdfs = array_values(array_filter(
            $detail->getFichiers()->toArray(),
            static fn (CommandeDetailFichier $f): bool => $f->isActif() && $f->estUploadTermine() && $f->estPdf(),
        ));
        usort($pdfs, static fn (CommandeDetailFichier $a, CommandeDetailFichier $b): int => [$b->getVersion(), $b->getId()] <=> [$a->getVersion(), $a->getId()]);

        return $pdfs;
    }

    /** @return list<array{fichier: CommandeDetailFichier, ligne: CommandesDetails}> */
    private function imagesDeLaCommande(CommandesDetails $detail): array
    {
        $liste = [];
        foreach ($detail->getCommande()?->getCommandesDetails() ?? [] as $ligne) {
            foreach ($this->images($ligne) as $f) {
                $liste[] = ['fichier' => $f, 'ligne' => $ligne];
            }
        }

        return $liste;
    }

    /**
     * Dossier prépresse de la ligne (maquette PP05) : l'original et les
     * versions, avec l'état du BAT quand la version en a été un.
     *
     * @return list<array{fichier: CommandeDetailFichier, badge: string, detail: string}>
     */
    private function dossier(CommandesDetails $detail): array
    {
        $bats = [];
        $n = 0;
        $controles = $detail->getControlesPrePresse()->toArray();
        usort($controles, static fn (ControlePrePresse $a, ControlePrePresse $b): int => $a->getId() <=> $b->getId());
        foreach ($controles as $c) {
            if (!$c->isBatNecessaire()) {
                continue;
            }
            ++$n;
            foreach ($c->getFichiers() as $f) {
                $bats[$f->getId()] = 'BAT n°'.$n.' · '.match ($c->getStatut()) {
                    ControlePrePresse::STATUT_EN_ATTENTE_BAT => 'envoyé',
                    ControlePrePresse::STATUT_CORRECTION_REQUISE, ControlePrePresse::STATUT_REJETE => 'refusé',
                    ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION => 'validé',
                    default => 'en cours',
                };
            }
        }
        $liste = [];
        $fichiers = array_values(array_filter($detail->getFichiers()->toArray(), static fn (CommandeDetailFichier $f): bool => $f->isActif()));
        usort($fichiers, static fn (CommandeDetailFichier $a, CommandeDetailFichier $b): int => [$a->getVersion(), $a->getId()] <=> [$b->getVersion(), $b->getId()]);
        foreach ($fichiers as $f) {
            $original = CommandeDetailFichier::ORIGINE_CLIENT === $f->getOrigine();
            $liste[] = [
                'fichier' => $f,
                'badge' => $original ? 'Original' : 'v'.$f->getVersion(),
                'detail' => $bats[$f->getId()] ?? ($original ? 'original client · '.$f->getCreeLe()->format('d/m') : ($f->getDesignation() ?? 'version interne').' · '.$f->getCreeLe()->format('d/m')),
            ];
        }

        return $liste;
    }

    private function prochaineVersion(CommandesDetails $detail): int
    {
        $version = 1;
        foreach ($detail->getFichiers() as $f) {
            $version = max($version, $f->getVersion() + 1);
        }

        return $version;
    }

    private function prochainBat(CommandesDetails $detail): int
    {
        return 1 + \count(array_filter($detail->getControlesPrePresse()->toArray(), static fn (ControlePrePresse $c): bool => $c->isBatNecessaire() && null !== $c->getId()));
    }

    /**
     * Format fini de la ligne en mm : ses dimensions (en m), sinon son format.
     *
     * @return array{0: ?float, 1: ?float}
     */
    public static function tailleCommandee(CommandesDetails $detail, ?EntityManagerInterface $em = null): array
    {
        $l = (float) $detail->getLargeur();
        $h = (float) $detail->getLongueur();
        if ($l > 0 && $h > 0) {
            return [round($l * 1000, 1), round($h * 1000, 1)];
        }
        $format = $detail->getFormat();
        if (null !== $format && null !== $em) {
            // Valeurs lues en base, sans les accesseurs entiers du format (21,0 × 29,7 cm).
            $f = $em->createQueryBuilder()->select('f.largeur', 'f.hauteur', 'f.unite')->from(Format::class, 'f')
                ->where('f.id = :id')->setParameter('id', $format->getId())->getQuery()->getOneOrNullResult();
            if (null !== $f && (float) $f['largeur'] > 0 && (float) $f['hauteur'] > 0) {
                $facteur = match ($f['unite']) { 'cm' => 10, 'm' => 1000, default => 1 };

                return [round((float) $f['largeur'] * $facteur, 1), round((float) $f['hauteur'] * $facteur, 1)];
            }
        }

        return [null, null];
    }

    /** @return list<array{libelle: string, largeurMm: float, hauteurMm: float}> */
    private function formatsRapides(?float $largeurMm, ?float $hauteurMm): array
    {
        $liste = [];
        if (null !== $largeurMm && null !== $hauteurMm) {
            $liste[] = ['libelle' => 'Commande '.G::mm($largeurMm / 10).' × '.G::mm($hauteurMm / 10), 'largeurMm' => $largeurMm, 'hauteurMm' => $hauteurMm];
        }
        $lignes = $this->em->createQueryBuilder()->select('f.nom', 'f.largeur', 'f.hauteur', 'f.unite')->from(Format::class, 'f')
            ->where('f.publie = true')->orderBy('f.ordre', 'ASC')->getQuery()->getArrayResult();
        foreach ($lignes as $f) {
            $facteur = match ($f['unite']) { 'cm' => 10, 'm' => 1000, default => 1 };
            $l = (float) $f['largeur'] * $facteur;
            $h = (float) $f['hauteur'] * $facteur;
            if ($l > 0 && $h > 0) {
                $liste[] = ['libelle' => (string) $f['nom'], 'largeurMm' => $l, 'hauteurMm' => $h];
            }
        }
        $liste[] = ['libelle' => 'Bâche 3 × 1 m', 'largeurMm' => 3000.0, 'hauteurMm' => 1000.0];
        // La taille de la commande n'est montrée à part que si aucun format ne la donne déjà.
        if (null !== $largeurMm && \count(array_filter($liste, static fn (array $f): bool => abs($f['largeurMm'] - $largeurMm) < 0.5 && abs($f['hauteurMm'] - $hauteurMm) < 0.5)) > 1) {
            array_shift($liste);
        }

        return $liste;
    }

    /** @param array{largeur: int, hauteur: int} $source */
    private function messageFichierPlusGrand(CommandesDetails $detail, array $source): string
    {
        return \sprintf(
            "Bonjour, pour imprimer votre %s en bonne qualité, nous avons besoin d'une image plus grande : celle reçue fait %s × %s pixels et serait floue à la taille commandée. Pouvez-vous nous envoyer le fichier d'origine (celui du graphiste, ou la photo en taille réelle, pas une capture ni une image reçue par WhatsApp) ? Merci.",
            TableauProduction::titreClient($detail),
            G::nombre($source['largeur']),
            G::nombre($source['hauteur']),
        );
    }

    private function chemin(CommandeDetailFichier $f): ?string
    {
        return EspaceClient::cheminDansProjet((string) $this->getParameter('kernel.project_dir'), $f);
    }

    /** Nom saisi, nettoyé, avec la bonne extension ; null s'il est vide. */
    public static function nomFichier(string $nom, string $extension): ?string
    {
        $nom = trim((string) preg_replace('/[^\w.\-× ]+/u', '-', $nom));
        $nom = (string) preg_replace('/\.(pdf|png|jpe?g)$/i', '', $nom);
        $nom = trim(mb_substr($nom, 0, 180), ' .-');

        return '' === $nom ? null : $nom.'.'.$extension;
    }

    /** « CMD-D00764_850x2000_v2.pdf » @param array{largeurMm: float, hauteurMm: float} $reglages */
    public static function nomParDefaut(string $numero, array $reglages, int $version, string $extension): string
    {
        return \sprintf('%s_%sx%s_v%d.%s', preg_replace('/[^\w\-]+/', '-', $numero) ?: 'retouche', rtrim(rtrim(number_format($reglages['largeurMm'], 1, '.', ''), '0'), '.'), rtrim(rtrim(number_format($reglages['hauteurMm'], 1, '.', ''), '0'), '.'), $version, $extension);
    }

    /** Résumé lisible gardé dans l'observation du fichier. @param array<string, mixed> $r */
    public static function resume(array $r): string
    {
        $g = $r['reglages'];
        $modes = [G::ADAPTER => 'adapter', G::RECADRER => 'recadrer', G::ETIRER => 'étirer'];
        $parties = [
            G::mm($g['largeurMm']).' × '.G::mm($g['hauteurMm']).' mm',
            $modes[$g['mode']] ?? $g['mode'],
            $g['dpiSortie'].' dpi · '.G::nombre($r['sortie']['largeurPx']).' × '.G::nombre($r['sortie']['hauteurPx']).' px',
        ];
        if ($g['fondPerdu']) {
            $parties[] = 'fond perdu '.G::mm(G::FOND_PERDU_MM).' mm';
        }
        if (null !== $g['contour']) {
            $parties[] = 'contour '.('forme' === $g['contour']['type'] ? 'de forme' : 'rectangle').' '.$g['contour']['couleur'].' '.str_replace('.', ',', (string) $g['contour']['epaisseurMm']).' mm';
        }
        $parties[] = 'RVB, à convertir au RIP';
        $texte = 'Retouche : '.implode(' · ', $parties).'. Original conservé : '.$r['source']['nom'].'.';
        if (null !== ($r['derogation'] ?? null)) {
            $texte .= ' Validé malgré : '.implode(' ', $r['derogation']['alertes']).' Raison : '.$r['derogation']['raison'];
        }

        return $texte;
    }

    /** Ce que le client lit avec le BAT : l'alerte en mots simples et la raison. @param list<array{cle: string}> $bloquants */
    private static function remarqueClient(array $bloquants, string $raison): string
    {
        $alertes = array_map(static fn (array $c): string => match ($c['cle']) {
            'resolution' => 'image en basse résolution, le rendu peut être flou',
            'deformation' => 'image étirée pour remplir le format',
            default => 'alerte de contrôle',
        }, $bloquants);

        return ucfirst(implode(' et ', array_unique($alertes))).'. '.$raison;
    }
}
