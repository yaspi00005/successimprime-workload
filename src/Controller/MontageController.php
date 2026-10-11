<?php

namespace App\Controller;

use App\Entity\CommandeDetailFichier;
use App\Entity\CommandesDetails;
use App\Entity\Machines;
use App\Service\Montage;
use App\Entity\User;
use App\Service\Parametres\Parametres;
use App\Service\SuiviMetiers\TableauConception;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Outil de montage d'impression (docs/montage-impression.md), fourni par
 * Yaya et intégré tel quel avec ses bibliothèques servies par
 * l'application. Ouvert depuis une ligne de commande, il reçoit le dernier
 * fichier, le format fini et la quantité, et range le PDF du montage dans
 * les fichiers de la commande.
 */
#[Route('/atelier/montage', name: 'app_montage_')]
final class MontageController extends AbstractController
{
    private const CSRF = 'montage';
    private const ROLES = ['ROLE_ADMIN', 'ROLE_PREPRESSE', 'ROLE_PRODUCTION_GERER'];
    private const DESIGNATION = 'Montage d\'impression';
    private const MAX_OCTETS = 200 * 1024 * 1024;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
    ) {
    }

    #[Route('', name: 'outil', methods: ['GET'])]
    public function outil(Request $request): Response
    {
        $this->autoriser();
        $ligne = $request->query->get('ligne') ? $this->em->getRepository(CommandesDetails::class)->find((int) $request->query->get('ligne')) : null;
        $fichier = $ligne !== null ? $this->dernierFichier($ligne) : null;

        $config = [
            'marge' => (float) $this->parametres->valeur('montage', 'marge_mm'),
            'fondPerdu' => (float) $this->parametres->valeur('montage', 'fond_perdu_mm'),
            'traitsCoupe' => (bool) $this->parametres->valeur('montage', 'traits_coupe'),
            'formats' => Montage::formats((string) $this->parametres->valeur('montage', 'formats')) ?: Montage::FORMATS,
            'machines' => array_map(static fn (Machines $m): array => [
                'id' => $m->getId(),
                'nom' => $m->getNom(),
                'marge' => $m->getMargeMontageMm(),
                'formats' => $m->getFormatsMontage(),
            ], $this->machinesFeuille()),
        ];
        if ($ligne !== null) {
            $config += [
                'ligne' => $ligne->getId(),
                'titre' => trim(($ligne->getCommande()?->getNumero() ?? '').' · '.($ligne->getDesignation() ?? 'Travail')),
                'quantite' => $ligne->getQuantite(),
                'largeurMm' => $ligne->getLargeur() !== null && (float) $ligne->getLargeur() > 0 ? round((float) $ligne->getLargeur() * 1000, 1) : null,
                'hauteurMm' => $ligne->getLongueur() !== null && (float) $ligne->getLongueur() > 0 ? round((float) $ligne->getLongueur() * 1000, 1) : null,
                'fichierUrl' => $fichier !== null ? $this->generateUrl('app_montage_fichier', ['id' => $fichier->getId()]) : null,
                'fichierNom' => $fichier?->getNomOriginal(),
                'enregistrerUrl' => $this->generateUrl('app_montage_enregistrer', ['id' => $ligne->getId()]),
                'jeton' => $this->container->get('security.csrf.token_manager')->getToken(self::CSRF)->getValue(),
            ];
        }

        return $this->render('montage/outil.html.twig', ['config' => $config, 'ligne' => $ligne]);
    }

    #[Route('/fichier/{id}', name: 'fichier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function fichier(CommandeDetailFichier $fichier): Response
    {
        $this->autoriser();
        $chemin = $fichier->isActif() ? $this->chemin($fichier) : null;
        if ($chemin === null) {
            throw $this->createNotFoundException('Fichier introuvable sur le serveur.');
        }
        $reponse = new BinaryFileResponse($chemin);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $fichier->getNomOriginal() ?: basename($chemin));
        $reponse->headers->set('Content-Type', $fichier->getTypeMime() ?: (mime_content_type($chemin) ?: 'application/octet-stream'));
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');
        $reponse->setPrivate();

        return $reponse;
    }

    #[Route('/{id}/enregistrer', name: 'enregistrer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function enregistrer(CommandesDetails $ligne, Request $request, TableauConception $conceptions): JsonResponse
    {
        $this->autoriser();
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            return new JsonResponse(['message' => 'La page a expiré : rechargez-la.'], Response::HTTP_FORBIDDEN);
        }
        $pdf = $request->files->get('pdf');
        if ($pdf === null || !$pdf->isValid() || $pdf->getSize() > self::MAX_OCTETS || $pdf->getMimeType() !== 'application/pdf') {
            return new JsonResponse(['message' => 'Le PDF du montage est manquant ou trop lourd.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $moi = $this->getUser();
        \assert($moi instanceof User);

        $version = 1;
        $source = $this->dernierFichier($ligne);
        foreach ($ligne->getFichiers() as $f) {
            $version = max($version, $f->getVersion() + 1);
        }
        $machine = $request->request->getInt('machine') > 0 ? $this->em->getRepository(Machines::class)->find($request->request->getInt('machine')) : null;
        $montage = Montage::donnees($request->request->all(), $machine?->getId(), $machine?->getNom());
        $resume = $montage !== null ? Montage::resume($montage) : '';
        $nomOriginal = mb_substr(preg_replace('/[^\w.\- ]+/u', '-', (string) $request->request->get('nom')) ?: 'montage.pdf', 0, 200);
        $nomStockage = 'montage-'.$ligne->getId().'-'.bin2hex(random_bytes(12)).'.pdf';
        $taille = (int) $pdf->getSize();
        $pdf->move($this->getParameter('kernel.project_dir').'/var/uploads/commandes', $nomStockage);

        $fichier = (new CommandeDetailFichier())
            ->setCommandeDetail($ligne)
            ->setFichierSource($source)
            ->setJetonUpload(bin2hex(random_bytes(32)))
            ->setNomOriginal($nomOriginal)
            ->setNomStockage($nomStockage)
            ->setTypeMime('application/pdf')
            ->setTaille($taille)
            ->setOrigine(CommandeDetailFichier::ORIGINE_INTERNE)
            ->setEtat(CommandeDetailFichier::ETAT_TRAITE)
            ->setVersion($version)
            ->setDesignation(self::DESIGNATION)
            ->setObservation($resume !== '' ? $resume : null)
            ->setMontage($montage)
            ->setAjoutePar($moi)
            ->marquerUploadTermine();
        $ligne->addFichier($fichier);
        $this->em->persist($fichier);
        $this->em->flush();
        $conceptions->noterMontage($ligne, $resume, new \DateTimeImmutable());

        return new JsonResponse(['message' => \sprintf('Montage rangé dans la commande (version %d).', $version)]);
    }

    /** @return list<Machines> machines à la feuille, celles qui reçoivent un montage */
    private function machinesFeuille(): array
    {
        return $this->em->getRepository(Machines::class)->findBy(['modeFacturation' => Machines::MODE_FACTURATION_FEUILLE], ['nom' => 'ASC']);
    }

    /** Dernière maquette de la ligne, sans les montages déjà rangés. */
    private function dernierFichier(CommandesDetails $ligne): ?CommandeDetailFichier
    {
        $dernier = null;
        foreach ($ligne->getFichiers() as $f) {
            if ($f->isActif() && $f->getDesignation() !== self::DESIGNATION && \in_array($f->getTypeMime(), ['application/pdf', 'image/jpeg', 'image/png'], true) && ($dernier === null || $f->getVersion() >= $dernier->getVersion())) {
                $dernier = $f;
            }
        }

        return $dernier;
    }

    /** Même règle que l'écran prépresse : public/<chemin> ou var/uploads/commandes. */
    private function chemin(CommandeDetailFichier $fichier): ?string
    {
        $projet = (string) $this->getParameter('kernel.project_dir');
        $candidats = [];
        if (trim((string) $fichier->getChemin()) !== '') {
            $candidats[] = [$projet.'/public', $projet.'/public/'.ltrim((string) $fichier->getChemin(), '/')];
        }
        $nom = basename(trim((string) $fichier->getNomStockage()));
        if ($nom !== '' && $nom !== '.') {
            $candidats[] = [$projet.'/var/uploads/commandes', $projet.'/var/uploads/commandes/'.$nom];
        }
        foreach ($candidats as [$racine, $chemin]) {
            $racine = realpath($racine);
            $reel = realpath($chemin);
            if ($racine !== false && $reel !== false && str_starts_with($reel, $racine.\DIRECTORY_SEPARATOR) && is_file($reel)) {
                return $reel;
            }
        }

        return null;
    }

    private function autoriser(): void
    {
        foreach (self::ROLES as $role) {
            if ($this->isGranted($role)) {
                return;
            }
        }
        throw $this->createAccessDeniedException();
    }
}
