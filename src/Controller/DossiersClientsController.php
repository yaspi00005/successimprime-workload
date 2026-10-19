<?php

namespace App\Controller;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\FichierAClasser;
use App\Entity\FichierDossier;
use App\Entity\FichierPublie;
use App\Entity\LienDossierClient;
use App\Entity\User;
use App\Security\DossiersClientsVoter;
use App\Service\DossiersClients\AnalyseDossiers;
use App\Service\DossiersClients\ClassementFichiers;
use App\Service\DossiersClients\DroitsDossiers;
use App\Service\DossiersClients\ExplorateurDossier;
use App\Service\DossiersClients\JournalDossiers;
use App\Service\DossiersClients\Miniatures;
use App\Service\DossiersClients\ModeleSousDossiers;
use App\Service\DossiersClients\NommageFichier;
use App\Service\DossiersClients\PublicationFichiers;
use App\Service\DossiersClients\Rapprochement;
use App\Service\DossiersClients\ReglagesDossiers;
use App\Service\Http\Entree;
use App\Service\Prepresse\DossierPartage;
use App\Service\ProduitsClient\DossierClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Dossiers clients (maquettes DS01 à DS04 du 19/10/2026,
 * docs/dossiers-clients.md) : fichiers du dossier d'un client (aussi
 * dans l'onglet Fichiers de sa fiche), lier les dossiers existants,
 * fichiers à classer, réglages et droits.
 *
 * Chaque geste suit la matrice « Qui voit quoi » (sous-dossier par
 * sous-dossier) et part au journal. L'ERP ne déplace ni ne supprime aucun
 * fichier de l'atelier. Sur le site en ligne, aucun de ces écrans ne lit le
 * disque : la page d'un client montre seulement ses fichiers publiés.
 */
#[Route('/dossiers-clients', name: 'app_dossiers_clients_')]
#[IsGranted('ROLE_USER')]
final class DossiersClientsController extends AbstractController
{
    private const CSRF = 'dossiers-clients';

    public function __construct(
        private readonly ReglagesDossiers $reglages,
        private readonly EntityManagerInterface $em,
        private readonly JournalDossiers $journal,
    ) {
    }

    /* ===================== DS01 : fichiers d'un client ===================== */

    #[Route('/client/{publicId}', name: 'client', methods: ['GET'])]
    public function client(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, Request $request, ExplorateurDossier $explorateur): Response
    {
        $this->exigerVoir();

        return $this->render('dossiers_clients/client.html.twig', [
            'client' => $client,
            'dossier' => $explorateur->pourClient($client, $this->moi(), self::choix($request)),
            'base' => $this->generateUrl('app_dossiers_clients_client', ['publicId' => $client->getPublicId()]),
        ]);
    }

    /** Volet de détail d'un fichier (remplacé sans recharger la page). */
    #[Route('/fichier/{id}', name: 'detail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(FichierDossier $fichier, Request $request, ExplorateurDossier $explorateur): Response
    {
        $this->fichierVisible($fichier);

        return $this->render('dossiers_clients/_detail.html.twig', [
            'd' => $explorateur->detail($fichier, $this->moi()),
            'client' => $fichier->getClient(),
            'modele' => $this->reglages->modele(),
            'base' => $this->base($request, $fichier->getClient(), (string) $request->query->get('base', '')),
        ]);
    }

    #[Route('/fichier/{id}/miniature', name: 'miniature', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function miniature(FichierDossier $fichier, Miniatures $miniatures): Response
    {
        $this->fichierVisible($fichier);

        return $this->image($miniatures->chemin($fichier->getMiniature()), $fichier->getExtension());
    }

    #[Route('/fichier/{id}/telecharger', name: 'telecharger', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function telecharger(FichierDossier $fichier, DossierClient $dossiers): Response
    {
        $this->fichierVisible($fichier);
        $dossier = $dossiers->dossierClient($fichier->getClient());
        $chemin = null === $dossier ? null : ClassementFichiers::dans($dossier, $fichier->getChemin());
        if (null === $chemin) {
            throw $this->createNotFoundException('Le fichier n\'est plus dans le dossier du client.');
        }
        $this->journal->noter('telechargement', $fichier->getClient(), $this->moi(), ['fichier' => $fichier->getChemin()]);
        $this->em->flush();
        $reponse = new BinaryFileResponse($chemin, 200, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fichier->getNom(), 'fichier.'.($fichier->getExtension() ?: 'bin'));

        return $reponse;
    }

    /**
     * « Ouvrir avec Illustrator » / « Afficher dans le dossier » : lien
     * signé succes-fichier:// que reçoit le petit programme du poste.
     */
    #[Route('/fichier/{id}/ouvrir/{action}', name: 'ouvrir', requirements: ['id' => '\d+', 'action' => 'ouvrir|afficher'], methods: ['GET'])]
    public function ouvrir(FichierDossier $fichier, string $action, ExplorateurDossier $explorateur): Response
    {
        $this->fichierVisible($fichier);
        $lien = $this->em->getRepository(LienDossierClient::class)->findOneBy(['client' => $fichier->getClient(), 'etat' => LienDossierClient::LIE]);
        $chemin = null === $lien ? null : $explorateur->cheminPoste($lien, $fichier->getChemin());
        if (null === $chemin || ('ouvrir' === $action && NommageFichier::estProgramme($fichier->getNom()))) {
            throw $this->createNotFoundException();
        }
        $this->journal->noter('ouverture', $fichier->getClient(), $this->moi(), ['fichier' => $fichier->getChemin(), 'avec' => 'ouvrir' === $action ? 'logiciel du poste' : 'Explorateur']);
        $this->em->flush();

        return new RedirectResponse($this->reglages->lienProtocole()->lien($action, $chemin, time()));
    }

    /** Bouton « Explorateur » : ouvre le sous-dossier affiché sur le poste. */
    #[Route('/client/{publicId}/explorateur', name: 'ouvrir_dossier', methods: ['GET'])]
    public function ouvrirDossier(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, Request $request, ExplorateurDossier $explorateur): Response
    {
        $this->exigerLocal();
        $this->denyAccessUnlessGranted(DossiersClientsVoter::VOIR);
        $sd = (string) $request->query->get('sd', '');
        $entree = $this->reglages->modele()->entree($sd);
        if (null === $entree || !$this->reglages->peut($this->moi(), 'voir', $sd)) {
            throw $this->createAccessDeniedException();
        }
        $lien = $this->em->getRepository(LienDossierClient::class)->findOneBy(['client' => $client, 'etat' => LienDossierClient::LIE]);
        $chemin = null === $lien ? null : $explorateur->cheminPoste($lien, $explorateur->dossierReel($client, $sd));
        if (null === $chemin) {
            throw $this->createNotFoundException();
        }
        $this->journal->noter('ouverture', $client, $this->moi(), ['dossier' => $entree['dossier'], 'avec' => 'Explorateur']);
        $this->em->flush();

        return new RedirectResponse($this->reglages->lienProtocole()->lien('ouvrir', $chemin, time()));
    }

    #[Route('/fichier/{id}/commande', name: 'lier_commande', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function lierCommande(FichierDossier $fichier, Request $request): Response
    {
        $this->jeton($request);
        $this->fichierVisible($fichier);
        $moi = $this->moi();
        $id = Entree::entier($request->request, 'commande');
        if (0 === $id) {
            if (!$this->reglages->peut($moi, 'supprimer', $fichier->getSousDossier())) {
                throw $this->createAccessDeniedException();
            }
            $avant = $fichier->getCommande()?->getNumero();
            $fichier->lierCommande(null, $moi, new \DateTimeImmutable());
            $this->journal->noter('retrait_lien', $fichier->getClient(), $moi, ['fichier' => $fichier->getChemin(), 'commande' => $avant]);
        } else {
            if (!ExplorateurDossier::peutLier($this->reglages, $moi, $fichier->getSousDossier())) {
                throw $this->createAccessDeniedException();
            }
            $commande = $this->em->find(Commandes::class, $id);
            if (null === $commande || $commande->getClients()?->getId() !== $fichier->getClient()->getId()) {
                throw $this->createNotFoundException();
            }
            $fichier->lierCommande($commande, $moi, new \DateTimeImmutable());
            $this->journal->noter('lien_commande', $fichier->getClient(), $moi, ['fichier' => $fichier->getChemin(), 'commande' => $commande->getNumero()]);
            $this->addFlash('success', \sprintf('« %s » est lié à la commande %s.', $fichier->getNom(), $commande->getNumero()));
        }
        $this->em->flush();

        return $this->retour($request, $fichier);
    }

    #[Route('/fichier/{id}/publier', name: 'publier', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function publier(FichierDossier $fichier, Request $request, PublicationFichiers $publications): Response
    {
        $this->jeton($request);
        $this->fichierVisible($fichier);
        try {
            $p = $publications->publier($fichier, (string) $request->request->get('categorie', ''), $request->request->getBoolean('source'), $this->moi() ?? throw $this->createAccessDeniedException());
            $this->addFlash('success', \sprintf('« %s » est publié pour le client (%s). Il sera dans son espace en ligne à la prochaine synchronisation.', $p->getNom(), $p->getCategorieLibelle()));
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->retour($request, $fichier);
    }

    #[Route('/publie/{id}/retirer', name: 'retirer_publication', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retirerPublication(FichierPublie $publie, Request $request, PublicationFichiers $publications): Response
    {
        $this->jeton($request);
        $sd = $publie->getFichierDossier()?->getSousDossier() ?? ModeleSousDossiers::AUTRES;
        if (!$this->reglages->peut($this->moi(), 'supprimer', $sd) && $publie->getPubliePar()?->getId() !== $this->moi()?->getId()) {
            throw $this->createAccessDeniedException();
        }
        $publications->retirer($publie, $this->moi() ?? throw $this->createAccessDeniedException());
        $this->addFlash('success', 'La publication est retirée : le client ne voit plus ce fichier. Le fichier de l\'atelier n\'est pas touché.');

        return null !== $publie->getFichierDossier() ? $this->retour($request, $publie->getFichierDossier()) : $this->redirectToRoute('app_dossiers_clients_client', ['publicId' => $publie->getClient()->getPublicId()]);
    }

    #[Route('/client/{publicId}/deposer', name: 'deposer', methods: ['POST'])]
    public function deposer(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, Request $request, ClassementFichiers $classement): Response
    {
        $this->jeton($request);
        $this->exigerVoir();
        $sd = (string) $request->request->get('sd', '');
        $fichier = $request->files->get('fichier');
        if (!$fichier instanceof UploadedFile || !$fichier->isValid()) {
            $this->addFlash('danger', 'Aucun fichier reçu (ou trop lourd pour l\'envoi par le navigateur : '.ini_get('upload_max_filesize').' au plus). Pour un gros fichier, enregistrez-le directement dans le dossier du client.');
        } else {
            try {
                $f = $classement->deposer($client, $sd, (string) $fichier->getPathname(), (string) $fichier->getClientOriginalName(), $this->moi() ?? throw $this->createAccessDeniedException());
                $this->addFlash('success', \sprintf('« %s » est rangé dans %s.', $f->getNom(), $this->reglages->modele()->libelle($sd)));

                return $this->redirect($this->base($request, $client).'?'.http_build_query(['sd' => $sd, 'f' => $f->getId()]).'#fichiers');
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirect($this->base($request, $client).'?'.http_build_query(['sd' => $sd]).'#fichiers');
    }

    /** « Analyser maintenant » : relit le dossier de ce client seulement. */
    #[Route('/client/{publicId}/analyser', name: 'analyser_client', methods: ['POST'])]
    public function analyserClient(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, Request $request, AnalyseDossiers $analyse): Response
    {
        $this->jeton($request);
        $this->exigerVoir();
        $n = $analyse->analyserClient($client, false, new \DateTimeImmutable());
        $this->addFlash('success', \sprintf('Dossier relu : %d fichier%s.', $n, $n > 1 ? 's' : ''));

        return $this->redirect($this->base($request, $client).'#fichiers');
    }

    /* ===================== DS03 : fichiers à classer ===================== */

    #[Route('/a-classer', name: 'a_classer', methods: ['GET'])]
    public function aClasser(ClassementFichiers $classement, DossierPartage $partage): Response
    {
        if ($this->reglages->enLigne()) {
            return $this->render('dossiers_clients/en_ligne.html.twig', ['titre' => 'Fichiers à classer']);
        }
        $this->denyAccessUnlessGranted(DossiersClientsVoter::CLASSER);
        $moi = $this->moi();
        $fichiers = $this->em->getRepository(FichierAClasser::class)->findBy(['etat' => FichierAClasser::A_CLASSER], ['apparuLe' => 'DESC'], 300);
        $commandes = [];
        foreach ($fichiers as $f) {
            if (null !== $f->getClient() && !isset($commandes[$f->getClient()->getId()])) {
                $commandes[$f->getClient()->getId()] = $this->em->getRepository(Commandes::class)->findBy(['clients' => $f->getClient(), 'deleted' => false], ['dateCommande' => 'DESC'], 15);
            }
        }

        return $this->render('dossiers_clients/a_classer.html.twig', [
            'fichiers' => $fichiers,
            'commandes' => $commandes,
            'modele' => $this->reglages->modele(),
            'droits' => $this->reglages->droitsDe($moi),
            'sures' => \count($classement->suggestionsSures($moi ?? throw $this->createAccessDeniedException())),
            'dossier' => $this->reglages->dossierAClasser(),
            'dossier_postes' => $this->reglages->dossierAClasser(),
            'peut_retirer' => $this->reglages->droits()->peutQuelquePart($moi->getRoles(), 'supprimer'),
            'confiance_masse' => ClassementFichiers::CONFIANCE_MASSE,
            'exemple' => NommageFichier::nomAutomatique('CM-0175', 'photo-facade', new \DateTimeImmutable('2026-10-19'), 'jpg'),
            // Fichiers du dossier partagé de la prépresse sans numéro reconnu : listés ici, laissés en place.
            'partage_non_reconnus' => $partage->nonReconnus(),
        ]);
    }

    /** Liste proposée dans le champ « Client » (code · nom), pour qui classe ou lie les dossiers. */
    #[Route('/clients', name: 'chercher_client', methods: ['GET'])]
    public function chercherClient(Request $request): JsonResponse
    {
        $this->exigerLocal();
        if (!$this->isGranted(DossiersClientsVoter::CLASSER) && !$this->isGranted(DossiersClientsVoter::REGLER)) {
            throw $this->createAccessDeniedException();
        }

        return new JsonResponse(array_map(static fn (Clients $c): array => ['id' => $c->getId(), 'texte' => ($c->getCode() ? $c->getCode().' · ' : '').$c->getNomComplet()], $this->rechercherClients((string) $request->query->get('q', ''), 12)));
    }

    #[Route('/a-classer/{id}/miniature', name: 'a_classer_miniature', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function aClasserMiniature(FichierAClasser $fichier, Miniatures $miniatures): Response
    {
        $this->exigerLocal();
        $this->denyAccessUnlessGranted(DossiersClientsVoter::CLASSER);

        return $this->image($miniatures->chemin($fichier->getMiniature()), $fichier->getExtension());
    }

    #[Route('/a-classer/{id}/classer', name: 'classer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function classer(FichierAClasser $fichier, Request $request, ClassementFichiers $classement, AnalyseDossiers $analyse): Response
    {
        $this->jeton($request);
        $this->exigerLocal();
        $this->denyAccessUnlessGranted(DossiersClientsVoter::CLASSER);
        $client = $this->clientChoisi($request) ?? $fichier->getClient();
        $idCommande = Entree::entier($request->request, 'commande', $fichier->getCommande()?->getId() ?? 0);
        $commande = $idCommande > 0 ? $this->em->find(Commandes::class, $idCommande) : null;
        $sd = (string) $request->request->get('sd', (string) $fichier->getSousDossier());
        $nom = trim((string) $request->request->get('nom', ''));
        if (null === $client) {
            $this->addFlash('danger', 'Choisissez le client avant de classer « '.$fichier->getNom().' ».');

            return $this->redirectToRoute('app_dossiers_clients_a_classer', ['_fragment' => 'fichier-'.$fichier->getId()]);
        }
        if ('' === $nom || $client->getId() !== $fichier->getClient()?->getId()) {
            $nom = $analyse->nomPropose($fichier, $client, $commande?->getClients()?->getId() === $client->getId() ? $commande : null, $sd);
        }
        try {
            $copie = $classement->classer($fichier, $client, $commande?->getClients()?->getId() === $client->getId() ? $commande : null, $sd, $nom, $this->moi() ?? throw $this->createAccessDeniedException());
            $this->addFlash('success', \sprintf('« %s » est rangé chez %s sous le nom « %s ». L\'original reste dans « À classer ».', $fichier->getNom(), $client->getNomComplet(), $copie->getNom()));
        } catch (\DomainException $e) {
            $this->addFlash('danger', $fichier->getNom().' : '.$e->getMessage());
        }

        return $this->redirectToRoute('app_dossiers_clients_a_classer');
    }

    #[Route('/a-classer/suggestions', name: 'classer_suggestions', methods: ['POST'])]
    public function classerSuggestions(Request $request, ClassementFichiers $classement): Response
    {
        $this->jeton($request);
        $this->exigerLocal();
        $this->denyAccessUnlessGranted(DossiersClientsVoter::CLASSER);
        $bilan = $classement->classerSuggestions($this->moi() ?? throw $this->createAccessDeniedException());
        $this->addFlash($bilan['classes'] > 0 ? 'success' : 'info', \sprintf('%d fichier%s classé%s. Les originaux restent dans « À classer ».', $bilan['classes'], $bilan['classes'] > 1 ? 's' : '', $bilan['classes'] > 1 ? 's' : ''));
        foreach (\array_slice($bilan['erreurs'], 0, 5) as $e) {
            $this->addFlash('danger', $e);
        }

        return $this->redirectToRoute('app_dossiers_clients_a_classer');
    }

    #[Route('/a-classer/{id}/retirer', name: 'retirer_a_classer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retirerAClasser(FichierAClasser $fichier, Request $request): Response
    {
        $this->jeton($request);
        $this->exigerLocal();
        $moi = $this->moi() ?? throw $this->createAccessDeniedException();
        if (!$this->reglages->droits()->peutQuelquePart($moi->getRoles(), 'supprimer')) {
            throw $this->createAccessDeniedException();
        }
        $fichier->retirer($moi, new \DateTimeImmutable());
        $this->journal->noter('retrait_liste', $fichier->getClient(), $moi, ['fichier' => $fichier->getChemin()]);
        $this->em->flush();
        $this->addFlash('success', '« '.$fichier->getNom().' » est retiré de la liste. Le fichier reste dans le dossier « À classer ».');

        return $this->redirectToRoute('app_dossiers_clients_a_classer');
    }

    #[Route('/a-classer/analyser', name: 'analyser_a_classer', methods: ['POST'])]
    public function analyserAClasser(Request $request, AnalyseDossiers $analyse): Response
    {
        $this->jeton($request);
        $this->exigerLocal();
        $this->denyAccessUnlessGranted(DossiersClientsVoter::CLASSER);
        $n = $analyse->analyserAClasser(new \DateTimeImmutable());
        $this->addFlash('success', \sprintf('Dossier « À classer » relu : %d fichier%s en attente.', $n, $n > 1 ? 's' : ''));

        return $this->redirectToRoute('app_dossiers_clients_a_classer');
    }

    /* ===================== DS02 : lier les dossiers existants ===================== */

    #[Route('/liaison', name: 'liaison', methods: ['GET'])]
    public function liaison(Request $request, AnalyseDossiers $analyse): Response
    {
        if ($this->reglages->enLigne()) {
            return $this->render('dossiers_clients/en_ligne.html.twig', ['titre' => 'Lier les dossiers existants']);
        }
        $this->denyAccessUnlessGranted(DossiersClientsVoter::REGLER);
        $liens = $this->em->getRepository(LienDossierClient::class)->findBy([], ['score' => 'DESC']);
        $aValider = array_filter($liens, static fn (LienDossierClient $l): bool => !$l->estLie());
        $vue = \in_array($request->query->get('vue'), ['suggere', 'lie', 'introuvable', 'sans_client'], true) ? (string) $request->query->get('vue') : ([] !== $aValider ? 'suggere' : 'lie');
        $etat = $analyse->etat()['rapprochement'] ?? null;
        $dossiers = $analyse->dossiersRacine();
        $utilises = [];
        foreach ($liens as $l) {
            $utilises[$l->getNomDossier()] = $l;
        }
        $sansClient = array_values(array_filter($dossiers, static fn (string $d): bool => !isset($utilises[$d])));
        $lignes = match ($vue) {
            'lie' => array_values(array_filter($liens, static fn (LienDossierClient $l): bool => $l->estLie())),
            'suggere' => array_values(array_filter($liens, static fn (LienDossierClient $l): bool => !$l->estLie())),
            default => [],
        };
        $page = max(1, Entree::entier($request->query, 'page', 1));
        $totalLignes = \count($lignes);
        $lignes = \array_slice($lignes, ($page - 1) * 50, 50);
        $introuvables = [];
        $totalIntrouvables = 0;
        if ('introuvable' === $vue) {
            $qb = $this->em->createQueryBuilder()->select('c')->from(Clients::class, 'c')
                ->leftJoin(LienDossierClient::class, 'l', 'WITH', 'l.client = c')->where('l.id IS NULL');
            $q = trim((string) $request->query->get('q', ''));
            if ('' !== $q) {
                $qb->andWhere('c.code LIKE :q OR c.nom LIKE :q OR c.prenom LIKE :q OR c.raisonSociale LIKE :q')->setParameter('q', '%'.addcslashes($q, '%_').'%');
            }
            $totalIntrouvables = (int) (clone $qb)->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
            $introuvables = $qb->orderBy('c.id', 'DESC')->setFirstResult(($page - 1) * 50)->setMaxResults(50)->getQuery()->getResult();
        }

        return $this->render('dossiers_clients/liaison.html.twig', [
            'vue' => $vue,
            'lignes' => $lignes,
            'total_lignes' => $totalLignes,
            'introuvables' => $introuvables,
            'total_introuvables' => $totalIntrouvables,
            'page' => $page,
            'q' => (string) $request->query->get('q', ''),
            'compteurs' => [
                'dossiers' => \count($dossiers),
                'lies' => \count(array_filter($liens, static fn (LienDossierClient $l): bool => $l->estLie())),
                'suggeres' => \count(array_filter($liens, static fn (LienDossierClient $l): bool => !$l->estLie())),
                'sans_client' => \count($sansClient),
            ],
            'sans_client' => $sansClient,
            'libres' => array_values(array_filter($dossiers, static fn (string $d): bool => !isset($utilises[$d]) || !$utilises[$d]->estLie())),
            'etat' => $etat,
            'racine' => $this->reglages->racine(),
            'racine_postes' => $this->reglages->racinePostes(),
            'modele' => $this->reglages->modele(),
            'seuil' => Rapprochement::SEUIL_SUGGESTION,
            'exemple_dossier' => AnalyseDossiers::nomDossierClient($introuvables[0] ?? $this->em->getRepository(Clients::class)->findOneBy([], ['id' => 'DESC']) ?? new Clients()),
        ]);
    }

    #[Route('/liaison/rechercher', name: 'rechercher', methods: ['POST'])]
    public function rechercher(Request $request, AnalyseDossiers $analyse): Response
    {
        $this->jeton($request);
        $this->exigerLocal();
        $this->denyAccessUnlessGranted(DossiersClientsVoter::REGLER);
        if ('' === $this->reglages->racine() || !is_dir($this->reglages->racine())) {
            $this->addFlash('danger', 'Le dossier racine des clients n\'est pas réglé ou pas accessible depuis le serveur de l\'ERP.');

            return $this->redirectToRoute('app_dossiers_clients_reglages');
        }
        $b = $analyse->rapprocher($this->moi());
        $this->addFlash('success', \sprintf('%d dossiers trouvés : %d liés, %d suggestions à valider, %d sans client.', $b['dossiers'], $b['lies'], $b['suggeres'], $b['sans_client']));

        return $this->redirectToRoute('app_dossiers_clients_liaison');
    }

    #[Route('/liaison/valider', name: 'valider_suggestions', methods: ['POST'])]
    public function validerSuggestions(Request $request, AnalyseDossiers $analyse): Response
    {
        $this->jeton($request);
        $this->exigerLocal();
        $this->denyAccessUnlessGranted(DossiersClientsVoter::REGLER);
        $n = 0;
        $erreurs = [];
        foreach ($this->em->getRepository(LienDossierClient::class)->findBy(['etat' => LienDossierClient::SUGGERE]) as $l) {
            try {
                $analyse->lier($l->getClient(), $l->getNomDossier(), $this->moi());
                ++$n;
            } catch (\DomainException $e) {
                $erreurs[] = $l->getClient()->getNomComplet().' : '.$e->getMessage();
            }
        }
        $this->addFlash('success', \sprintf('%d suggestion%s validée%s.', $n, $n > 1 ? 's' : '', $n > 1 ? 's' : ''));
        foreach (\array_slice($erreurs, 0, 5) as $e) {
            $this->addFlash('danger', $e);
        }

        return $this->redirectToRoute('app_dossiers_clients_liaison', ['vue' => 'lie']);
    }

    /** Valider la suggestion, ou « Choisir un autre » dossier. */
    #[Route('/liaison/{publicId}/lier', name: 'lier', methods: ['POST'])]
    public function lier(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, Request $request, AnalyseDossiers $analyse): Response
    {
        $this->jeton($request);
        $this->exigerLocal();
        $this->denyAccessUnlessGranted(DossiersClientsVoter::REGLER);
        try {
            $l = $analyse->lier($client, trim((string) $request->request->get('dossier', '')), $this->moi());
            $this->addFlash('success', \sprintf('%s est lié au dossier « %s »%s.', $client->getNomComplet(), $l->getNomDossier(), $l->isMarquePosee() ? '' : ' (marque « .succes » pas posée : dossier en lecture seule, la liaison suit son nom)'));
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($this->retourInterne($request, $this->generateUrl('app_dossiers_clients_liaison')));
    }

    /** « Dossiers sans client » : lier un dossier au client choisi (code · nom). */
    #[Route('/liaison/dossier', name: 'lier_dossier', methods: ['POST'])]
    public function lierDossier(Request $request, AnalyseDossiers $analyse): Response
    {
        $this->jeton($request);
        $this->exigerLocal();
        $this->denyAccessUnlessGranted(DossiersClientsVoter::REGLER);
        $client = $this->clientChoisi($request);
        if (null === $client) {
            $this->addFlash('danger', 'Client introuvable : choisissez-le dans la liste proposée.');
        } else {
            try {
                $l = $analyse->lier($client, trim((string) $request->request->get('dossier', '')), $this->moi());
                $this->addFlash('success', \sprintf('%s est lié au dossier « %s ».', $client->getNomComplet(), $l->getNomDossier()));
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_dossiers_clients_liaison', ['vue' => 'sans_client']);
    }

    #[Route('/liaison/{publicId}/creer', name: 'creer', methods: ['POST'])]
    public function creer(#[MapEntity(mapping: ['publicId' => 'publicId'])] Clients $client, Request $request, AnalyseDossiers $analyse): Response
    {
        $this->jeton($request);
        $this->exigerLocal();
        $this->denyAccessUnlessGranted(DossiersClientsVoter::REGLER);
        try {
            $l = $analyse->creerDossier($client, $this->moi());
            $this->addFlash('success', \sprintf('Dossier « %s » créé avec ses sous-dossiers, et lié à %s.', $l->getNomDossier(), $client->getNomComplet()));
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirect($this->retourInterne($request, $this->generateUrl('app_dossiers_clients_liaison', ['vue' => 'introuvable'])));
    }

    /* ===================== DS04 : réglages et droits ===================== */

    #[Route('/reglages', name: 'reglages', methods: ['GET'])]
    public function reglages(AnalyseDossiers $analyse, PublicationFichiers $publications): Response
    {
        if ($this->reglages->enLigne()) {
            return $this->render('dossiers_clients/en_ligne.html.twig', ['titre' => 'Dossiers clients · réglages']);
        }
        $this->denyAccessUnlessGranted(DossiersClientsVoter::REGLER);

        return $this->render('dossiers_clients/reglages.html.twig', $this->donneesReglages($analyse, $publications));
    }

    #[Route('/reglages', name: 'reglages_enregistrer', methods: ['POST'])]
    public function enregistrerReglages(Request $request): Response
    {
        $this->jeton($request);
        $this->exigerLocal();
        $this->denyAccessUnlessGranted(DossiersClientsVoter::REGLER);
        $moi = $this->moi();
        $r = $request->request;
        $valeurs = [];
        foreach (['dossier_racine', 'racine_postes', 'dossier_a_classer'] as $cle) {
            $v = trim((string) $r->get($cle, ''));
            if (mb_strlen($v) > 255 || str_contains($v, "\0")) {
                $this->addFlash('danger', 'Chemin trop long ou invalide.');

                return $this->redirectToRoute('app_dossiers_clients_reglages');
            }
            $valeurs[$cle] = $v;
        }
        foreach (['surveiller', 'analyse_nuit', 'apercus', 'recherche_pdf'] as $cle) {
            $valeurs[$cle] = $r->getBoolean($cle);
        }
        if (!$this->reglages->pdftotextDisponible()) {
            $valeurs['recherche_pdf'] = false;
        }
        $heure = trim((string) $r->get('analyse_heure', '02:00'));
        $valeurs['analyse_heure'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $heure) ? $heure : '02:00';
        // Modèle de sous-dossiers : noms, ordre, couleurs.
        $modele = [];
        foreach ((array) $r->all('modele') as $ligne) {
            if (\is_array($ligne) && '' !== trim((string) ($ligne['nom'] ?? '')) && empty($ligne['retirer'])) {
                $modele[] = ['cle' => (string) ($ligne['cle'] ?? ''), 'nom' => (string) $ligne['nom'], 'couleur' => (string) ($ligne['couleur'] ?? ''), 'ordre' => (int) ($ligne['ordre'] ?? 0)];
            }
        }
        usort($modele, static fn (array $a, array $b): int => $a['ordre'] <=> $b['ordre']);
        $modele = ModeleSousDossiers::lire($modele);
        if ([] !== $modele) {
            $valeurs['modele'] = $modele;
        }
        // Qui voit quoi : la Direction seulement.
        if (null !== $moi && \in_array('ROLE_ADMIN', $moi->getRoles(), true) && \is_array($r->all('droits')) && [] !== $r->all('droits')) {
            $cles = array_column($modele ?: $this->reglages->modele()->entrees(), 'cle');
            $matrice = [];
            foreach ($r->all('droits') as $role => $ligne) {
                if (!\is_array($ligne) || 'ROLE_ADMIN' === $role) {
                    continue;
                }
                foreach (DroitsDossiers::ACTIONS as $action) {
                    $choix = $ligne[$action] ?? [];
                    $matrice[(string) $role][$action] = \is_array($choix) && \in_array(DroitsDossiers::TOUS, $choix, true) ? DroitsDossiers::TOUS : array_values(array_intersect($cles, \is_array($choix) ? $choix : []));
                }
            }
            $valeurs['droits'] = DroitsDossiers::lire($matrice, $cles);
        }
        $resultat = $this->reglages->enregistrer($valeurs, $moi);
        $this->addFlash('applique' === $resultat ? 'success' : 'info', 'applique' === $resultat ? 'Réglages des dossiers clients enregistrés.' : 'Rien n\'a changé.');
        $racine = $valeurs['dossier_racine'];
        if ('' !== $racine && !is_dir($racine)) {
            $this->addFlash('warning', 'Le serveur de l\'ERP ne voit pas « '.$racine.' » : vérifiez le chemin et les droits du compte qui fait tourner l\'ERP.');
        }

        return $this->redirectToRoute('app_dossiers_clients_reglages');
    }

    /** @return array<string, mixed> */
    private function donneesReglages(AnalyseDossiers $analyse, PublicationFichiers $publications): array
    {
        $racine = $this->reglages->racine();
        $roles = $this->em->getRepository(\App\Entity\RoleUtilisateur::class)->findBy([], ['ordre' => 'ASC', 'id' => 'ASC']);
        $moisDebut = new \DateTimeImmutable('first day of this month midnight');
        $publies = $this->em->createQueryBuilder()->select('COUNT(p.id) AS n')->from(FichierPublie::class, 'p')->where('p.publieLe >= :d')->setParameter('d', $moisDebut)->getQuery()->getSingleScalarResult();
        $octets = $this->em->createQueryBuilder()->select('SUM(p.taille)')->from(FichierPublie::class, 'p')->where('p.retireLe IS NULL')->getQuery()->getSingleScalarResult();
        $etat = $analyse->etat();
        $moi = $this->moi();
        $modele = $this->reglages->modele();
        $matrice = $this->reglages->droits()->matrice();
        $lignes = [];
        foreach ($roles as $r) {
            $code = $r->getCode();
            $ligne = 'ROLE_ADMIN' === $code ? array_fill_keys(DroitsDossiers::ACTIONS, DroitsDossiers::TOUS) : ($matrice[$code] ?? array_fill_keys(DroitsDossiers::ACTIONS, []));
            $cases = [];
            foreach (DroitsDossiers::ACTIONS as $action) {
                $cases[$action] = ['valeur' => $ligne[$action], 'texte' => DroitsDossiers::resumer($ligne[$action], $modele, $action)];
            }
            $lignes[] = ['code' => $code, 'nom' => $r->getNom(), 'fixe' => 'ROLE_ADMIN' === $code, 'cases' => $cases];
        }

        return [
            'v' => [
                'dossier_racine' => (string) $this->reglages->valeur('dossier_racine'),
                'racine_postes' => (string) $this->reglages->valeur('racine_postes'),
                'dossier_a_classer' => (string) $this->reglages->valeur('dossier_a_classer'),
                'surveiller' => (bool) $this->reglages->valeur('surveiller'),
                'analyse_nuit' => (bool) $this->reglages->valeur('analyse_nuit'),
                'analyse_heure' => (string) ($this->reglages->valeur('analyse_heure') ?: '02:00'),
                'apercus' => (bool) $this->reglages->valeur('apercus'),
                'recherche_pdf' => (bool) $this->reglages->valeur('recherche_pdf'),
            ],
            'connexion' => [
                'ok' => '' !== $racine && is_dir($racine) && is_readable($racine),
                'ecriture' => '' !== $racine && is_dir($racine) && is_writable($racine),
                'a_classer' => '' !== $this->reglages->dossierAClasser() && is_dir($this->reglages->dossierAClasser()),
                'compte' => \function_exists('posix_getpwuid') && \function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '') : (string) get_current_user(),
                'dossiers' => $etat['rapprochement']['dossiers'] ?? null,
                'fichiers' => $etat['complete']['fichiers'] ?? ($etat['rapide']['fichiers'] ?? null),
            ],
            'etat' => $etat,
            'pdftotext' => $this->reglages->pdftotextDisponible(),
            'modele' => $this->reglages->modele(),
            'matrice' => $lignes,
            'peut_droits' => null !== $moi && \in_array('ROLE_ADMIN', $moi->getRoles(), true),
            'publies_mois' => (int) $publies,
            'octets_en_ligne' => (int) $octets,
            'journal' => $this->journal->dernieres(8),
            'cle_postes' => null !== $moi && \in_array('ROLE_ADMIN', $moi->getRoles(), true) ? $this->reglages->clePostes() : null,
        ];
    }

    /* ===================== Outils ===================== */

    /** @return array{sd?: string, q?: string, filtre?: string, tri?: string, f?: int} */
    public static function choix(Request $request): array
    {
        $q = $request->query;

        return ['sd' => (string) $q->get('sd', ''), 'q' => mb_substr((string) $q->get('q', ''), 0, 100), 'filtre' => (string) $q->get('filtre', ''), 'tri' => (string) $q->get('tri', ''), 'f' => Entree::entier($q, 'f')];
    }

    private function moi(): ?User
    {
        $u = $this->getUser();

        return $u instanceof User ? $u : null;
    }

    private function exigerLocal(): void
    {
        if ($this->reglages->enLigne()) {
            throw $this->createNotFoundException();
        }
    }

    /** Page d'un client : en ligne, ses fichiers publiés (droit Clients) ; au local, la matrice. */
    private function exigerVoir(): void
    {
        if ($this->reglages->enLigne()) {
            $this->denyAccessUnlessGranted('ROLE_CLIENT');

            return;
        }
        $this->denyAccessUnlessGranted(DossiersClientsVoter::VOIR);
    }

    private function fichierVisible(FichierDossier $f): void
    {
        $this->exigerLocal();
        if ($f->isAbsent() || !$this->reglages->peut($this->moi(), 'voir', $f->getSousDossier())) {
            throw $this->createAccessDeniedException();
        }
    }

    private function jeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide : rechargez la page.');
        }
    }

    /** Client choisi : son id, ou le texte « CODE · Nom » de la liste proposée (marche sans JavaScript). */
    private function clientChoisi(Request $request): ?Clients
    {
        $id = Entree::entier($request->request, 'client');
        if ($id > 0) {
            return $this->em->find(Clients::class, $id);
        }
        $texte = trim((string) $request->request->get('client_texte', ''));
        if ('' === $texte) {
            return null;
        }
        $code = trim(explode(' · ', $texte)[0]);
        $client = $this->em->getRepository(Clients::class)->findOneBy(['code' => $code]);
        if (null !== $client) {
            return $client;
        }
        $trouves = $this->rechercherClients($texte, 2);

        return 1 === \count($trouves) ? $trouves[0] : null;
    }

    /** @return list<Clients> */
    private function rechercherClients(string $q, int $max): array
    {
        $q = mb_substr(trim($q), 0, 60);
        if (mb_strlen($q) < 2) {
            return [];
        }

        return $this->em->createQueryBuilder()->select('c')->from(Clients::class, 'c')
            ->where('c.code LIKE :q OR c.nom LIKE :q OR c.prenom LIKE :q OR c.raisonSociale LIKE :q')
            ->setParameter('q', '%'.addcslashes($q, '%_').'%')
            ->orderBy('c.nom', 'ASC')->setMaxResults($max)->getQuery()->getResult();
    }

    /** Fiche du client (onglet Fichiers) ou page des fichiers, d'où vient la demande. */
    private function base(Request $request, Clients $client, ?string $base = null): string
    {
        $base ??= (string) $request->request->get('base', '');
        $permises = [
            $this->generateUrl('app_clients_show', ['publicId' => $client->getPublicId()]),
            $this->generateUrl('app_dossiers_clients_client', ['publicId' => $client->getPublicId()]),
        ];

        return \in_array($base, $permises, true) ? $base : $permises[1];
    }

    /** Adresse de retour : seulement une page de ce site. */
    private function retourInterne(Request $request, string $defaut): string
    {
        $r = (string) $request->request->get('retour', '');

        return 1 === preg_match('#^/(?![/\\\\])[^\s]*$#', $r) ? $r : $defaut;
    }

    private function retour(Request $request, FichierDossier $f): Response
    {
        return $this->redirect($this->base($request, $f->getClient()).'?'.http_build_query(['sd' => $f->getSousDossier(), 'f' => $f->getId()]).'#fichiers');
    }

    /** Miniature (déjà faite par l'analyse), sinon une icône du type. */
    private function image(?string $chemin, string $extension): Response
    {
        if (null !== $chemin) {
            return new BinaryFileResponse($chemin, 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
        }
        $p = NommageFichier::pastille($extension);
        $svg = \sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="120" height="120" viewBox="0 0 120 120"><rect width="120" height="120" fill="#F1F5F9"/><path d="M38 22h32l16 16v60a4 4 0 0 1-4 4H38a4 4 0 0 1-4-4V26a4 4 0 0 1 4-4z" fill="#fff" stroke="#CBD5E1" stroke-width="2"/><rect x="30" y="66" width="60" height="20" rx="4" fill="%s"/><text x="60" y="81" font-family="Arial,sans-serif" font-size="13" font-weight="700" fill="#fff" text-anchor="middle">%s</text></svg>', $p['couleur'], htmlspecialchars($p['texte'], \ENT_XML1));

        return new Response($svg, 200, ['Content-Type' => 'image/svg+xml', 'Cache-Control' => 'private, max-age=86400', 'X-Content-Type-Options' => 'nosniff']);
    }
}
