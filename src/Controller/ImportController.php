<?php

namespace App\Controller;

use App\Entity\ImportDonnees;
use App\Entity\User;
use App\Service\Import\ContexteImport;
use App\Service\Import\Imports;
use App\Service\Import\LecteurTableur;
use App\Service\Import\LigneImport;
use App\Service\Import\ModeleImport;
use App\Service\Import\TypeImport;
use App\Service\Pointage\Pointages;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages › Sécurité et système › Import des données
 * (docs/import-donnees.md) : clients, articles du catalogue et de stock,
 * soldes d'ouverture, depuis un fichier Excel ou CSV.
 *
 * Direction seulement ; les soldes demandent aussi les droits Finances
 * (Imports::accessible). ERP local seulement : ImportHorsLigneSubscriber
 * ferme ces adresses sur le serveur en ligne.
 */
#[Route('/reglages/systeme/import', name: 'app_import_')]
#[IsGranted('ROLE_ADMIN')]
final class ImportController extends AbstractController
{
    /** Lignes de l'aperçu par page. */
    private const PAR_PAGE = 200;

    public function __construct(
        private readonly Imports $imports,
        private readonly ModeleImport $modeles,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $types = [];
        foreach ($this->imports->types() as $cle => $type) {
            $types[$cle] = ['type' => $type, 'accessible' => Imports::accessible($type, $this->isGranted(...))];
        }

        return $this->render('import/index.html.twig', [
            'types' => $types,
            'journal' => $this->imports->journal(50),
        ]);
    }

    #[Route('/{type}/modele', name: 'modele', requirements: ['type' => '[a-z_]+'], methods: ['GET'])]
    public function modele(string $type): Response
    {
        $t = $this->typeAccessible($type);
        $chemin = $this->modeles->ecrire($t, $this->isGranted(...));
        $reponse = new BinaryFileResponse($chemin);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, sprintf('modele-import-%s.xlsx', str_replace('_', '-', $t->cle())));
        $reponse->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->deleteFileAfterSend();

        return $reponse;
    }

    #[Route('/{type}', name: 'envoyer', requirements: ['type' => '[a-z_]+'], methods: ['GET', 'POST'])]
    public function envoyer(string $type, Request $request): Response
    {
        $t = $this->typeAccessible($type);
        $utilisateur = $this->utilisateur();
        $erreur = null;
        $maintenant = new \DateTimeImmutable();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('import-envoyer-'.$t->cle(), (string) $request->request->get('_token'))) {
                throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
            }
            $fichier = $request->files->get('fichier');
            if (null === $fichier) {
                $erreur = 'Choisissez le fichier à importer.';
            } else {
                try {
                    $jeton = $this->imports->deposer($t, $fichier, $utilisateur, [
                        'dateOuverture' => $request->request->getString('date_ouverture'),
                        'completer' => $request->request->getBoolean('completer'),
                    ], $maintenant);

                    return $this->redirectToRoute('app_import_apercu', ['type' => $t->cle(), 'jeton' => $jeton]);
                } catch (\InvalidArgumentException|\RuntimeException $e) {
                    $erreur = $e->getMessage();
                }
            }
        }

        return $this->render('import/envoyer.html.twig', [
            'type' => $t,
            'colonnes' => ModeleImport::colonnes($t, $this->isGranted(...)),
            'erreur' => $erreur,
            'aujourdhui' => $maintenant,
            'xlsx_lisible' => LecteurTableur::xlsxLisible(),
        ], new Response(null, null !== $erreur ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/{type}/apercu/{jeton}', name: 'apercu', requirements: ['type' => '[a-z_]+', 'jeton' => '[0-9a-f]{32}'], methods: ['GET'])]
    public function apercu(string $type, string $jeton, Request $request): Response
    {
        $t = $this->typeAccessible($type);
        $envoi = $this->envoi($t, $jeton);
        if (null === $envoi) {
            $this->addFlash('warning', 'Ce fichier n\'est plus en attente : envoyez-le de nouveau.');

            return $this->redirectToRoute('app_import_envoyer', ['type' => $t->cle()]);
        }
        try {
            $apercu = $this->imports->apercu($t, $jeton, $envoi, $this->contexte($envoi));
        } catch (\InvalidArgumentException $e) {
            $this->imports->abandonner($jeton, $this->utilisateur());
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_import_envoyer', ['type' => $t->cle()]);
        }

        $statut = $request->query->getString('statut');
        $lignes = \array_key_exists($statut, LigneImport::STATUTS)
            ? array_values(array_filter($apercu['lignes'], static fn (LigneImport $l): bool => $l->statut === $statut))
            : $apercu['lignes'];
        $avertis = $request->query->getBoolean('avertis');
        if ($avertis) {
            $lignes = array_values(array_filter($lignes, static fn (LigneImport $l): bool => [] !== $l->avertissements));
        }
        $pages = max(1, (int) ceil(\count($lignes) / self::PAR_PAGE));
        $page = min($pages, max(1, $request->query->getInt('page', 1)));

        return $this->render('import/apercu.html.twig', [
            'apercu' => $apercu,
            'type' => $t,
            'jeton' => $jeton,
            'lignes' => \array_slice($lignes, ($page - 1) * self::PAR_PAGE, self::PAR_PAGE),
            'filtre' => \array_key_exists($statut, LigneImport::STATUTS) ? $statut : null,
            'avertis' => $avertis,
            'nb_avertis' => \count(array_filter($apercu['lignes'], static fn (LigneImport $l): bool => [] !== $l->avertissements)),
            'filtrees' => \count($lignes),
            'page' => $page,
            'pages' => $pages,
            'par_page' => self::PAR_PAGE,
            'statuts' => LigneImport::STATUTS,
            'date_ouverture' => null !== $envoi['dateOuverture'] ? new \DateTimeImmutable($envoi['dateOuverture']) : null,
        ]);
    }

    #[Route('/{type}/apercu/{jeton}/confirmer', name: 'confirmer', requirements: ['type' => '[a-z_]+', 'jeton' => '[0-9a-f]{32}'], methods: ['POST'])]
    public function confirmer(string $type, string $jeton, Request $request): Response
    {
        $t = $this->typeAccessible($type);
        if (!$this->isCsrfTokenValid('import-confirmer-'.$jeton, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $envoi = $this->envoi($t, $jeton);
        if (null === $envoi) {
            $this->addFlash('warning', 'Ce fichier n\'est plus en attente : envoyez-le de nouveau.');

            return $this->redirectToRoute('app_import_envoyer', ['type' => $t->cle()]);
        }
        if ([] !== $this->imports->dejaImportes($envoi['empreinte']) && !$request->request->getBoolean('malgre')) {
            $this->addFlash('warning', 'Ce fichier a déjà été importé : cochez la case pour confirmer que vous voulez le relire.');

            return $this->redirectToRoute('app_import_apercu', ['type' => $t->cle(), 'jeton' => $jeton]);
        }
        set_time_limit(300);
        try {
            $import = $this->imports->confirmer($t, $jeton, $envoi, $this->contexte($envoi), $this->utilisateur());
        } catch (\DomainException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_import_apercu', ['type' => $t->cle(), 'jeton' => $jeton]);
        }
        $this->addFlash('success', sprintf('Import enregistré : %d créé%s, %d mis à jour, %d écarté%s, %d en erreur.',
            $import->getCrees(), $import->getCrees() > 1 ? 's' : '', $import->getModifies(), $import->getIgnores(), $import->getIgnores() > 1 ? 's' : '', $import->getErreurs()));

        return $this->redirectToRoute('app_import_voir', ['id' => $import->getId()]);
    }

    #[Route('/{type}/apercu/{jeton}/abandonner', name: 'abandonner', requirements: ['type' => '[a-z_]+', 'jeton' => '[0-9a-f]{32}'], methods: ['POST'])]
    public function abandonner(string $type, string $jeton, Request $request): Response
    {
        $t = $this->typeAccessible($type);
        if (!$this->isCsrfTokenValid('import-abandonner-'.$jeton, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $this->imports->abandonner($jeton, $this->utilisateur());
        $this->addFlash('success', 'Fichier abandonné : rien n\'a été importé.');

        return $this->redirectToRoute('app_import_envoyer', ['type' => $t->cle()]);
    }

    #[Route('/journal/{id}', name: 'voir', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function voir(ImportDonnees $import): Response
    {
        $t = $this->typeDuJournal($import);

        return $this->render('import/voir.html.twig', [
            'import' => $import,
            'type' => $t,
            'blocages' => $import->estFait() ? $this->imports->blocagesAnnulation($import) : [],
            'fichier' => null !== $this->imports->cheminFichier($import),
            'statuts' => LigneImport::STATUTS,
        ]);
    }

    /** Le fichier gardé pour l'audit : direction, avec les droits du type. */
    #[Route('/journal/{id}/fichier', name: 'fichier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function fichier(ImportDonnees $import): Response
    {
        $this->typeDuJournal($import);
        $chemin = $this->imports->cheminFichier($import) ?? throw $this->createNotFoundException('Fichier introuvable.');
        $reponse = new BinaryFileResponse($chemin);
        $reponse->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, Imports::nomPropre($import->getNomFichier()), sprintf('import-%d.%s', $import->getId(), pathinfo($chemin, \PATHINFO_EXTENSION)));
        $reponse->headers->set('Cache-Control', 'private, no-store');
        $reponse->headers->set('X-Content-Type-Options', 'nosniff');

        return $reponse;
    }

    #[Route('/journal/{id}/annuler', name: 'annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function annuler(ImportDonnees $import, Request $request): Response
    {
        $this->typeDuJournal($import);
        if (!$this->isCsrfTokenValid('import-annuler-'.$import->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
        $utilisateur = $this->utilisateur();
        try {
            $this->imports->annuler($import, new ContexteImport($utilisateur, Pointages::nom($utilisateur), new \DateTimeImmutable(), $this->isGranted(...)));
            $this->addFlash('success', 'Import annulé : ce qu\'il avait enregistré est défait.');
        } catch (\DomainException $e) {
            $this->addFlash('error', 'Annulation impossible : '.$e->getMessage());
        }

        return $this->redirectToRoute('app_import_voir', ['id' => $import->getId()]);
    }

    private function typeAccessible(string $cle): TypeImport
    {
        $type = $this->imports->type($cle) ?? throw $this->createNotFoundException('Type d\'import inconnu.');
        if (!Imports::accessible($type, $this->isGranted(...))) {
            throw $this->createAccessDeniedException('Cet import demande des droits que vous n\'avez pas.');
        }

        return $type;
    }

    private function typeDuJournal(ImportDonnees $import): ?TypeImport
    {
        $type = $this->imports->type($import->getType());
        if (null !== $type && !Imports::accessible($type, $this->isGranted(...))) {
            throw $this->createAccessDeniedException('Cet import demande des droits que vous n\'avez pas.');
        }

        return $type;
    }

    /** @return array<string, mixed>|null */
    private function envoi(TypeImport $type, string $jeton): ?array
    {
        $envoi = $this->imports->envoi($jeton, $this->utilisateur());

        return null !== $envoi && $envoi['type'] === $type->cle() ? $envoi : null;
    }

    /** @param array<string, mixed> $envoi */
    private function contexte(array $envoi): ContexteImport
    {
        $utilisateur = $this->utilisateur();

        return new ContexteImport(
            $utilisateur,
            Pointages::nom($utilisateur),
            new \DateTimeImmutable(),
            $this->isGranted(...),
            null !== ($envoi['dateOuverture'] ?? null) ? new \DateTimeImmutable($envoi['dateOuverture']) : null,
            (bool) ($envoi['completer'] ?? false),
        );
    }

    private function utilisateur(): User
    {
        $u = $this->getUser();
        if (!$u instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $u;
    }
}
