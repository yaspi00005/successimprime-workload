<?php

namespace App\Controller;

use App\Entity\Entreprise;
use App\Repository\EntrepriseRepository;
use App\Repository\SocieteRepository;
use App\Service\DocumentsEntreprise;
use App\Service\FichiersPrives;
use App\Service\MontantEnLettres;
use App\Service\NumerotationFactures;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Réglages › Entreprises et en-têtes des documents (maquette I03).
 *
 * Chaque facture ou devis est rattaché à une entreprise émettrice ;
 * l'en-tête, les mentions, les couleurs et les QR suivent ce choix.
 * Chaque entreprise appartient à une société (docs/societes.md), qui
 * passe aux documents qu'elle émet.
 */
#[Route('/reglages/entreprises', name: 'app_entreprises_')]
#[IsGranted('ROLE_ADMIN')]
final class EntreprisesController extends AbstractController
{
    private const IMAGES = ['logo', 'logoPied', 'signature'];
    private const TYPES_IMAGE = ['image/png', 'image/jpeg', 'image/webp'];
    private const TAILLE_IMAGE = 2 * 1024 * 1024;

    public function __construct(
        private readonly EntrepriseRepository $entreprises,
        private readonly EntityManagerInterface $em,
        private readonly DocumentsEntreprise $documents,
        private readonly SocieteRepository $societes,
        private readonly FichiersPrives $fichiersPrives,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $premiere = $this->entreprises->dansLOrdre()[0] ?? null;

        if ($premiere === null) {
            return $this->render('entreprises/edition.html.twig', ['entreprises' => [], 'entreprise' => null]);
        }

        return $this->redirectToRoute('app_entreprises_edition', ['id' => $premiere->getId()]);
    }

    #[Route('/nouvelle', name: 'nouvelle', methods: ['POST'])]
    public function nouvelle(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('entreprise-nouvelle', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }

        $raison = trim((string) $request->request->get('raison_sociale'));

        if ($raison === '') {
            $this->addFlash('error', 'Indiquez la raison sociale de la nouvelle entreprise.');

            return $this->redirectToRoute('app_entreprises_index');
        }

        $base = substr((string) preg_replace('/[^a-z0-9]+/', '_', (new AsciiSlugger('fr'))->slug($raison)->lower()->toString()), 0, 24) ?: 'entreprise';
        $code = trim($base, '_');

        for ($i = 2; $this->entreprises->parCode($code) !== null; ++$i) {
            $code = trim($base, '_').'_'.$i;
        }

        $entreprise = (new Entreprise())
            ->setCode($code)
            ->setRaisonSociale($raison)
            ->setOrdre(\count($this->entreprises->findAll()));
        $this->em->persist($entreprise);
        $this->em->flush();

        $this->addFlash('success', sprintf('Entreprise « %s » créée. Complétez son en-tête.', $raison));

        return $this->redirectToRoute('app_entreprises_edition', ['id' => $entreprise->getId()]);
    }

    #[Route('/{id}', name: 'edition', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edition(Entreprise $entreprise, Request $request): Response
    {
        $erreurs = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('entreprise-'.$entreprise->getId(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }

            $erreurs = $this->enregistrer($entreprise, $request);

            if ($erreurs === []) {
                $this->em->flush();
                $this->addFlash('success', 'En-tête enregistré. Il s\'applique aux prochains documents ; les factures déjà archivées ne changent pas.');

                return $this->redirectToRoute('app_entreprises_edition', ['id' => $entreprise->getId()]);
            }
        }

        $format = $entreprise->getFormatNumero();

        return $this->render('entreprises/edition.html.twig', [
            'entreprises' => $this->entreprises->dansLOrdre(),
            'entreprise' => $entreprise,
            'images' => $this->documents->images($entreprise),
            'societes' => $this->societes->choix($entreprise->getSociete()),
            'erreurs' => $erreurs,
            'prochainNumero' => $format !== null
                ? NumerotationFactures::formater($format, NumerotationFactures::suivant($format, $entreprise->getCompteur(), $entreprise->getCompteurAnnee(), new \DateTimeImmutable())[0], new \DateTimeImmutable())
                : null,
        ]);
    }

    /**
     * Le modèle de facture avec des données fictives, dans l'iframe de
     * l'aperçu (HTML) ou en PDF.
     */
    #[Route('/{id}/apercu', name: 'apercu', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function apercu(Entreprise $entreprise, Request $request): Response
    {
        $html = $this->renderView('factures/pdf.html.twig', $this->exemple($entreprise));

        if ($request->query->get('format') !== 'pdf') {
            return new Response($html);
        }

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response((string) $dompdf->output(), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="apercu-%s.pdf"', $entreprise->getCode()),
        ]);
    }

    /**
     * @return list<string> messages d'erreur, vide si tout est enregistré
     */
    private function enregistrer(Entreprise $entreprise, Request $request): array
    {
        $champs = $request->request;
        $erreurs = [];
        $raison = trim((string) $champs->get('raison_sociale'));

        if ($raison === '') {
            $erreurs[] = 'La raison sociale est obligatoire.';
        } else {
            $entreprise->setRaisonSociale(mb_substr($raison, 0, 150));
        }

        foreach ([
            'nom_commercial' => ['setNomCommercial', 150], 'mention' => ['setMention', 150], 'activite' => ['setActivite', 150],
            'adresse' => ['setAdresse', 255], 'telephone' => ['setTelephone', 60], 'email' => ['setEmail', 150],
            'site_web' => ['setSiteWeb', 150], 'rccm' => ['setRccm', 60], 'nif' => ['setNif', 60], 'banque' => ['setBanque', 100],
            'compte_bancaire' => ['setCompteBancaire', 80], 'marchand_orange_money' => ['setMarchandOrangeMoney', 40],
        ] as $champ => [$setter, $longueur]) {
            $entreprise->{$setter}(mb_substr((string) $champs->get($champ, ''), 0, $longueur));
        }

        // Bloc « NB » des factures : texte libre, vide pour ne pas l'imprimer.
        $conditions = trim((string) $champs->get('conditions_paiement', ''));
        if (mb_strlen($conditions) > Entreprise::CONDITIONS_PAIEMENT_LONGUEUR) {
            $erreurs[] = \sprintf('Les conditions de paiement tiennent en %d caractères au plus.', Entreprise::CONDITIONS_PAIEMENT_LONGUEUR);
        } else {
            $entreprise->setConditionsPaiement($conditions);
        }

        // Les documents déjà émis gardent leur société : seuls les prochains suivent ce choix.
        $societe = $this->societes->find((int) $champs->get('societe', 0));

        if ($societe === null || (!$societe->isActif() && $societe !== $entreprise->getSociete())) {
            $erreurs[] = 'Choisissez la société de cette entreprise.';
        } else {
            $entreprise->setSociete($societe);
        }

        $format = trim((string) $champs->get('format_numero', ''));

        if ($format !== '' && !NumerotationFactures::formatValide($format)) {
            $erreurs[] = 'Le format de numérotation doit contenir le compteur, par exemple F-SI-{AAAA}-{0000}.';
        } else {
            $entreprise->setFormatNumero($format);
        }

        $couleurs = array_values(array_filter(
            array_map('strval', $champs->all('couleurs')),
            static fn (string $c) => preg_match('/^#[0-9a-fA-F]{6}$/', $c) === 1
        ));
        $entreprise->setCouleurs(\count($couleurs) === 5 ? $couleurs : Entreprise::COULEURS_PAR_DEFAUT);

        $entreprise
            ->setQrSuivi($champs->getBoolean('qr_suivi'))
            ->setQrPaiement($champs->getBoolean('qr_paiement'))
            ->setAfficherLogoPied($champs->getBoolean('logo_pied_actif'))
            ->setMontantEnLettres($champs->getBoolean('montant_en_lettres'))
            ->setActif($champs->getBoolean('actif') || $this->derniereActive($entreprise));

        foreach (self::IMAGES as $image) {
            $fichier = $request->files->get('image_'.$image);

            if ($champs->getBoolean('retirer_'.$image)) {
                $entreprise->{'set'.ucfirst($image)}(null);
            } elseif ($fichier instanceof UploadedFile) {
                $chemin = $this->deposerImage($entreprise, $image, $fichier);

                if ($chemin === null) {
                    $erreurs[] = sprintf('Image « %s » refusée : PNG, JPG ou WebP, 2 Mo au plus.', $fichier->getClientOriginalName());
                } else {
                    $entreprise->{'set'.ucfirst($image)}($chemin);
                }
            }
        }

        return $erreurs;
    }

    /**
     * On garde toujours au moins une entreprise active pour les formulaires.
     */
    private function derniereActive(Entreprise $entreprise): bool
    {
        return array_filter($this->entreprises->dansLOrdre(), static fn (Entreprise $e) => $e->isActif() && $e !== $entreprise) === [];
    }

    private function deposerImage(Entreprise $entreprise, string $type, UploadedFile $fichier): ?string
    {
        if (!$fichier->isValid() || $fichier->getSize() > self::TAILLE_IMAGE || !\in_array($fichier->getMimeType(), self::TYPES_IMAGE, true)) {
            return null;
        }

        // Hors de public/ (le cachet et la signature ne doivent pas se télécharger) :
        // var/uploads/entreprises, lu seulement pour les documents (DocumentsEntreprise::dataUri).
        try {
            $dossier = $this->fichiersPrives->dossier('entreprises');
        } catch (\RuntimeException) {
            return null;
        }

        $nom = sprintf('%s-%s-%s.%s', $entreprise->getCode(), strtolower($type), bin2hex(random_bytes(4)), $fichier->guessExtension() ?? 'png');
        $fichier->move($dossier, $nom);

        return DocumentsEntreprise::DOSSIER_IMAGES.'/'.$nom;
    }

    /**
     * Facture fictive de la maquette I01 pour l'aperçu.
     *
     * @return array<string, mixed>
     */
    private function exemple(Entreprise $entreprise): array
    {
        $maintenant = new \DateTimeImmutable();
        $format = $entreprise->getFormatNumero() ?? 'FAC-{000000}';
        $numero = NumerotationFactures::formater($format, 112, $maintenant);
        $lignes = [
            ['designation' => 'Bâche 510 g frontlit 400 × 150 cm', 'observation' => 'Œillets tous les 50 cm, ourlet', 'quantite' => 2, 'prixUnitaire' => 24576, 'totalTtc' => 49153],
            ['designation' => 'Roll-up 85 × 200 cm', 'observation' => 'Bâche 440 g, structure et housse', 'quantite' => 3, 'prixUnitaire' => 21186, 'totalTtc' => 63559],
            ['designation' => 'T-shirt coton imprimé DTF', 'observation' => 'Logo cœur + dos', 'quantite' => 25, 'prixUnitaire' => 3390, 'totalTtc' => 84746],
        ];
        $url = 'https://succesimprim.com/f/7K4P-2Q';

        return [
            'entreprise' => $entreprise,
            'images' => $this->documents->images($entreprise),
            'facture' => [
                'id' => 112, 'numero' => $numero, 'dateFacture' => $maintenant, 'dateEcheance' => null, 'estProforma' => false, 'estAnnulee' => false,
                'facturerAUnTiers' => false, 'observation' => null, 'totalHt' => 199458, 'remise' => 0, 'tva' => 18, 'montantTva' => 35542,
                'surfacturation' => false, 'montantSurfacturation' => 0, 'tauxSurfacturation' => null, 'comptabilisee' => true,
                'totalTtc' => 235000, 'montantPaye' => 117500, 'resteAPayer' => 117500, 'codeSuivi' => '7K4P-2Q',
                // Objet et frais de livraison (sans TVA, compris dans le total HT).
                'objet' => 'Impressions', 'fraisLivraison' => 2000,
            ],
            'commande' => ['id' => 144, 'numero' => 'CM-0144', 'bonCommande' => null, 'demandeePar' => null, 'commandesDetails' => $lignes],
            'client' => ['raisonSociale' => 'Hôtel Niger (exemple)', 'adresse' => 'Bamako, Mali', 'telephone' => '+223 XX XX XX XX'],
            'urlSuivi' => $url,
            'qrSuivi' => $entreprise->isQrSuivi() ? DocumentsEntreprise::qr($url) : null,
            'qrPaiement' => $entreprise->isQrPaiement() ? DocumentsEntreprise::qr($url.'#payer') : null,
            'montantLettres' => $entreprise->isMontantEnLettres() ? MontantEnLettres::phraseFacture(235000) : null,
        ];
    }
}
