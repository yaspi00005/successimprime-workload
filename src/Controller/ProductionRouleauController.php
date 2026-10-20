<?php

namespace App\Controller;

use App\Entity\AtelierMachine;
use App\Entity\CommandeDetailFichier;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\TirageRouleau;
use App\Entity\User;
use App\Repository\MachinesRepository;
use App\Repository\PointageRepository;
use App\Security\Droits;
use App\Security\FichierEnvoye;
use App\Security\FichiersPrivesVoter;
use App\Service\Atelier\AtelierProduction;
use App\Service\Atelier\PosteMachiniste;
use App\Service\Http\Entree;
use App\Service\Production\ControlesTirage;
use App\Service\Production\EnvoiRip;
use App\Service\Production\FichierProduction;
use App\Service\Production\ImpositionRouleau;
use App\Service\Production\NomFichierProduction;
use App\Service\Production\ParcoursFichier;
use App\Service\Production\TiragesRouleau;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Production au rouleau (maquettes PD01 à PD03, docs/production-rouleau.md) :
 * le parcours d'un fichier, les rouleaux et leur imposition, l'envoi au
 * RIP, la fiche de production et « Démarrer ».
 *
 * Tout voir : « Production ». Agir (créer, placer, envoyer, cocher,
 * démarrer) : « Statuts de production » et la règle de l'atelier (chef
 * d'atelier, poste de la machine, opérateur affecté ; machine sans
 * opérateur : la personne pointée aujourd'hui). Aucun montant, sauf le
 * coût matière + encre de l'imposition pour « Marges et coûts de revient ».
 * ERP local seulement (AtelierHorsLigneSubscriber : 404 en ligne).
 */
#[Route('/atelier/production', name: 'app_atelier_production_')]
#[IsGranted('ROLE_PRODUCTION_VOIR')]
final class ProductionRouleauController extends AbstractController
{
    private const CSRF = 'production-rouleau';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TiragesRouleau $tirages,
        private readonly FichierProduction $fichiers,
        private readonly MachinesRepository $machinesRepository,
        private readonly PointageRepository $pointages,
        private readonly AtelierProduction $atelierProduction,
    ) {
    }

    // ------------------------------------------------------------------
    // PD01 · Parcours d'un fichier
    // ------------------------------------------------------------------

    #[Route('/parcours', name: 'parcours', methods: ['GET'])]
    public function parcours(Request $request, ParcoursFichier $parcours): Response
    {
        $recherche = mb_substr(trim((string) $request->query->get('q', '')), 0, 80);
        $id = Entree::entier($request->query, 'ordre');
        $resultats = '' !== $recherche ? $parcours->rechercher($recherche) : [];
        $ordre = $id > 0 ? $this->em->find(OrdreProduction::class, $id) : null;
        $ordre ??= 1 === \count($resultats) ? $resultats[0] : null;
        $ordre ??= '' === $recherche ? $parcours->dernierEnvoye() : null;

        return $this->render('production_rouleau/parcours.html.twig', [
            'recherche' => $recherche,
            'resultats' => $resultats,
            'ordre' => $ordre,
            'etapes' => null !== $ordre ? $parcours->etapes($ordre) : [],
            'nom' => null !== $ordre ? FichierProduction::nomProduction($ordre) : null,
            'parties' => null !== $ordre ? NomFichierProduction::parties(FichierProduction::nomProduction($ordre)) : [],
            'dossier' => null !== $ordre ? $this->fichiers->dossierAffiche($this->fichiers->codeDossier($ordre->getTirage()?->getMachine() ?? $ordre->getMachine())) : null,
            'racine' => null !== $this->fichiers->racine(),
            'machines' => $this->tirages->machines(),
            'peutCopier' => null !== $ordre && $this->peutAgirSur($ordre->getMachine(), $request),
        ]);
    }

    /** Le fichier d'un ordre sous son nom de production (impression ou découpe). */
    #[Route('/ordre/{id}/fichier/{quoi}', name: 'fichier', requirements: ['id' => '\d+', 'quoi' => 'impression|decoupe'], methods: ['GET'])]
    public function fichier(OrdreProduction $ordre, string $quoi, Request $request): Response
    {
        $decoupe = 'decoupe' === $quoi;
        $f = $decoupe ? PosteMachiniste::fichierDecoupe($ordre) : PosteMachiniste::fichier($ordre);
        if ($f instanceof CommandeDetailFichier) {
            $this->denyAccessUnlessGranted(FichiersPrivesVoter::PREPRESSE, $f);
        }
        $source = $this->fichiers->source($ordre, $decoupe);
        if (null === $source['chemin']) {
            throw $this->createNotFoundException((string) $source['raison']);
        }
        $nom = $decoupe ? FichierProduction::nomDecoupe($ordre) : FichierProduction::nomProduction($ordre);

        return FichierEnvoye::reponse($source['chemin'], $nom, !$request->query->getBoolean('apercu'));
    }

    /** Copie le fichier de l'ordre dans le dossier de sa machine (et la découpe chez le traceur). */
    #[Route('/ordre/{id}/copier', name: 'copier', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function copier(OrdreProduction $ordre, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerAction($ordre->getMachine(), $request);
        try {
            $r = $this->fichiers->copierOrdre($ordre, $this->moi(), new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash(FichierProduction::COPIE === $r['etat'] ? 'success' : 'warning', $r['message'].(null !== $r['decoupe'] ? ' Fichier de découpe copié pour le traceur.' : ''));
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_atelier_production_parcours', ['ordre' => $ordre->getId()]);
    }

    // ------------------------------------------------------------------
    // PD02 · Rouleaux et imposition
    // ------------------------------------------------------------------

    #[Route('/rouleaux', name: 'rouleaux', methods: ['GET'])]
    public function rouleaux(): Response
    {
        $maintenant = new \DateTimeImmutable();

        return $this->render('production_rouleau/rouleaux.html.twig', [
            'groupes' => $this->tirages->parEtat($maintenant),
            'machines' => $this->tirages->machinesRouleau(),
            'libres' => \count($this->tirages->ordresLibres()),
            'peutGerer' => $this->isGranted('ROLE_PRODUCTION_GERER'),
        ]);
    }

    #[Route('/rouleaux/nouveau', name: 'nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $machines = $this->tirages->machinesRouleau();
        $id = Entree::entier($request->isMethod('POST') ? $request->request : $request->query, 'machine');
        $machine = null;
        foreach ($machines as $m) {
            if ($m->getId() === $id) {
                $machine = $m;
            }
        }
        $machine ??= $machines[0] ?? null;
        $supports = null !== $machine ? $this->tirages->supportsProposes($machine) : [];
        $code = NomFichierProduction::nettoyerCode((string) ($request->isMethod('POST') ? $request->request->get('support') : $request->query->get('support')));
        if ('' === $code || !\in_array($code, array_column($supports, 'code'), true)) {
            $code = $supports[0]['code'] ?? '';
        }
        $candidats = null !== $machine && '' !== $code ? $this->tirages->candidats($machine, $code, $maintenant) : ['ordres' => [], 'preselection' => [], 'laize' => 0.0, 'article' => null];

        if ($request->isMethod('POST')) {
            $this->verifierJeton($request);
            if (null === $machine) {
                throw $this->createNotFoundException();
            }
            $this->exigerAction($machine, $request);
            $choisis = array_map('intval', (array) $request->request->all('ordres'));
            $ordres = array_values(array_filter($candidats['ordres'], static fn (OrdreProduction $o): bool => \in_array((int) $o->getId(), $choisis, true)));
            try {
                if (\count($ordres) !== \count(array_unique($choisis))) {
                    throw new \DomainException('Un travail coché n\'est plus disponible : la liste a été rafraîchie.');
                }
                $tirage = $this->tirages->creer($machine, $code, $ordres, $this->moi(), $maintenant);
                $this->em->flush();
                $this->addFlash('success', \sprintf('Rouleau %s préparé : %d pièce%s placée%s.', $tirage->getNumero(), $tirage->getPieces()->count(), $tirage->getPieces()->count() > 1 ? 's' : '', $tirage->getPieces()->count() > 1 ? 's' : ''));

                return $this->redirectToRoute('app_atelier_production_imposition', ['id' => $tirage->getId()]);
            } catch (\DomainException $e) {
                $this->addFlash('danger', $e->getMessage());

                return $this->redirectToRoute('app_atelier_production_nouveau', ['machine' => $machine->getId(), 'support' => $code]);
            }
        }

        return $this->render('production_rouleau/nouveau.html.twig', [
            'machines' => $machines,
            'machine' => $machine,
            'supports' => $supports,
            'code' => $code,
            'candidats' => $candidats,
            'tailles' => array_map(static fn (OrdreProduction $o): ?array => TiragesRouleau::taillePiece($o), array_combine(array_map(static fn (OrdreProduction $o): int => (int) $o->getId(), $candidats['ordres']), $candidats['ordres']) ?: []),
            'longueurMax' => $this->tirages->reglages()['longueurMax'],
            'peutAgir' => null !== $machine && $this->peutAgirSur($machine, $request),
            'maintenant' => $maintenant,
        ]);
    }

    #[Route('/rouleau/{id}/imposition', name: 'imposition', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function imposition(TirageRouleau $tirage, Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $travaux = $this->tirages->travaux($tirage);
        $couleurs = [];
        foreach ($travaux as $t) {
            $couleurs[$t['ordre']->getId()] = $t['couleur'];
        }
        $pieces = [];
        foreach ($tirage->piecesTriees() as $p) {
            $taille = TiragesRouleau::taillePiece($p->getOrdre());
            $pieces[] = [
                'cle' => $p->cle(),
                'ordre' => $p->getOrdre()->getId(),
                'rang' => $p->getRang(),
                'x' => $p->getXCm(), 'y' => $p->getYCm(), 'l' => $p->getLargeurCm(), 'h' => $p->getHauteurCm(), 'tourne' => $p->isTourne(),
                'lo' => $taille[0] ?? $p->getLargeurCm(), 'ho' => $taille[1] ?? $p->getHauteurCm(),
                'libelle' => $this->tirages->libellePiece($p).' · '.$this->nomCourtClient($p->getOrdre(), 18),
                'exemplaire' => $p->getExemplaire(),
                'couleur' => $couleurs[$p->getOrdre()->getId()] ?? TiragesRouleau::COULEURS[0],
            ];
        }
        $profil = $this->tirages->profil($tirage);
        $peutAgir = $this->peutAgirSur($tirage->getMachine(), $request);

        return $this->render('production_rouleau/imposition.html.twig', [
            'tirage' => $tirage,
            'travaux' => $travaux,
            'pieces' => $pieces,
            'longueur' => max(1.0, $tirage->getConsommeM() * 100),
            'profil' => $profil,
            'cout' => $this->isGranted('ROLE_COUT_MARGE') ? $this->tirages->cout($tirage) : null,
            'voitCout' => $this->isGranted('ROLE_COUT_MARGE'),
            'pourquoi' => $this->tirages->pourquoi($tirage),
            'sortSec' => $this->tirages->sortSecA($tirage, $maintenant),
            'peutAgir' => $peutAgir,
            'raison' => $peutAgir ? null : $this->raisonRefus($tirage->getMachine(), $request),
            'nomRip' => $this->tirages->nomRip($tirage),
            'morceau' => EnvoiRip::morceau(),
            'tailleMax' => EnvoiRip::TAILLE_MAX,
            'dossier' => $this->fichiers->dossierAffiche($this->fichiers->codeDossier($tirage->getMachine())),
            'racine' => null !== $this->fichiers->racine(),
            'longueurMax' => $this->tirages->reglages()['longueurMax'],
            'margeTexte' => ImpositionRouleau::nombre($tirage->getMargeCm()),
            'espaceTexte' => ImpositionRouleau::nombre($tirage->getEspaceCm()),
        ]);
    }

    /** Placement automatique, par urgence ou dans l'ordre choisi (flèches de l'ordre d'impression). */
    #[Route('/rouleau/{id}/recalculer', name: 'recalculer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function recalculer(TirageRouleau $tirage, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerAction($tirage->getMachine(), $request);
        $ordre = null;
        $deplacer = Entree::entier($request->request, 'deplacer');
        if ($deplacer > 0) {
            $ids = array_map(static fn (OrdreProduction $o): int => (int) $o->getId(), $tirage->ordres());
            $i = array_search($deplacer, $ids, true);
            $j = false !== $i ? $i + ('haut' === $request->request->get('sens') ? -1 : 1) : -1;
            if (false !== $i && $j >= 0 && $j < \count($ids)) {
                [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            }
            $ordre = $ids;
        }
        try {
            foreach ($this->tirages->placerAuto($tirage, new \DateTimeImmutable(), $ordre) as $message) {
                $this->addFlash('warning', $message);
            }
            $this->em->flush();
            $this->addFlash('success', null !== $ordre ? 'Ordre d\'impression changé, placement recalculé.' : 'Placement recalculé par urgence.');
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_atelier_production_imposition', ['id' => $tirage->getId()]);
    }

    /** Placement manuel : positions déplacées dans le plan (JSON), contrôlées ici. */
    #[Route('/rouleau/{id}/manuel', name: 'manuel', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function manuel(TirageRouleau $tirage, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['message' => 'Jeton de sécurité invalide.'], Response::HTTP_FORBIDDEN);
        }
        $raison = $this->raisonRefus($tirage->getMachine(), $request);
        if (null !== $raison) {
            return $this->json(['message' => $raison], Response::HTTP_FORBIDDEN);
        }
        try {
            $donnees = $request->toArray();
        } catch (\Throwable) {
            return $this->json(['message' => 'Données illisibles.'], Response::HTTP_BAD_REQUEST);
        }
        $problemes = $this->tirages->placerManuel($tirage, \is_array($donnees['positions'] ?? null) ? $donnees['positions'] : [], new \DateTimeImmutable());
        if ([] !== $problemes) {
            return $this->json(['message' => 'Placement refusé : '.implode(' ', \array_slice($problemes, 0, 4)), 'problemes' => $problemes], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->em->flush();
        $this->addFlash('success', 'Placement manuel enregistré.');

        return $this->json(['ok' => true, 'url' => $this->generateUrl('app_atelier_production_imposition', ['id' => $tirage->getId()])]);
    }

    #[Route('/rouleau/{id}/annuler', name: 'annuler', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function annuler(TirageRouleau $tirage, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerAction($tirage->getMachine(), $request);
        try {
            $this->tirages->annuler($tirage, new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', \sprintf('Rouleau %s annulé : ses travaux attendent un autre rouleau.', $tirage->getNumero()));
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_atelier_production_rouleaux');
    }

    /**
     * Ce que le navigateur monte dans le PDF du rouleau : les pièces, leur
     * place, leur fichier (sous son nom de production) et son type.
     */
    #[Route('/rouleau/{id}/rip/donnees', name: 'rip_donnees', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function ripDonnees(TirageRouleau $tirage): JsonResponse
    {
        $pieces = [];
        $total = 0;
        $vus = [];
        foreach ($tirage->piecesTriees() as $p) {
            $o = $p->getOrdre();
            $f = PosteMachiniste::fichier($o);
            $source = $this->fichiers->source($o);
            $type = match (true) {
                null === $source['chemin'] => null,
                'pdf' === NomFichierProduction::extension($f?->getNomOriginal() ?? (string) ($o->getCommandeDetail()?->getFichierLie()['nom'] ?? ''), '') => 'pdf',
                \in_array(NomFichierProduction::extension($f?->getNomOriginal() ?? '', ''), ['png'], true) => 'png',
                \in_array(NomFichierProduction::extension($f?->getNomOriginal() ?? '', ''), ['jpg', 'jpeg'], true) => 'jpeg',
                default => null,
            };
            if (null !== $source['chemin'] && !isset($vus[$o->getId()])) {
                $vus[$o->getId()] = true;
                $total += (int) @filesize($source['chemin']);
            }
            $taille = TiragesRouleau::taillePiece($o);
            $pieces[] = [
                'cle' => $p->cle(),
                'x' => $p->getXCm(), 'y' => $p->getYCm(), 'l' => $p->getLargeurCm(), 'h' => $p->getHauteurCm(), 'tourne' => $p->isTourne(),
                'lo' => $taille[0] ?? $p->getLargeurCm(), 'ho' => $taille[1] ?? $p->getHauteurCm(),
                'marque' => $this->tirages->libellePiece($p).' · '.$this->nomCourtClient($o).' · '.$tirage->getNumero(),
                'nom' => FichierProduction::nomProduction($o),
                'type' => $type,
                'raison' => null === $source['chemin'] ? $source['raison'] : (null === $type ? 'format à monter au RIP (ni PDF, ni PNG, ni JPEG)' : null),
                'url' => null !== $source['chemin'] ? $this->generateUrl('app_atelier_production_fichier', ['id' => $o->getId(), 'quoi' => 'impression', 'apercu' => 1]) : null,
            ];
        }

        return $this->json([
            'numero' => $tirage->getNumero(),
            'nom' => $this->tirages->nomRip($tirage),
            'laize' => $tirage->getLaizeCm(),
            'marge' => $tirage->getMargeCm(),
            'longueur' => $tirage->getConsommeM() * 100,
            'pieces' => $pieces,
            'taille' => $total,
            'tailleMax' => EnvoiRip::TAILLE_MAX,
        ]);
    }

    #[Route('/rouleau/{id}/rip/initialiser', name: 'rip_initialiser', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ripInitialiser(TirageRouleau $tirage, Request $request, EnvoiRip $rip): JsonResponse
    {
        $refus = $this->refusJson($tirage, $request);
        if (null !== $refus) {
            return $refus;
        }
        try {
            $d = $request->toArray();
            $jeton = $rip->initialiser($tirage, (int) ($d['taille'] ?? 0), (int) ($d['morceaux'] ?? 0), $request->getSession());
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable) {
            return $this->json(['message' => 'Données illisibles.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->json(['jeton' => $jeton, 'morceau' => EnvoiRip::morceau()]);
    }

    #[Route('/rouleau/{id}/rip/morceau', name: 'rip_morceau', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ripMorceau(TirageRouleau $tirage, Request $request, EnvoiRip $rip): JsonResponse
    {
        $refus = $this->refusJson($tirage, $request);
        if (null !== $refus) {
            return $refus;
        }
        $morceau = $request->files->get('morceau');
        if (!$morceau instanceof UploadedFile) {
            return $this->json(['message' => 'Morceau absent (trop gros pour le serveur ?).'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            $recus = $rip->recevoir($tirage, (string) $request->request->get('jeton'), Entree::entier($request->request, 'index', -1), $morceau, $request->getSession());
        } catch (\DomainException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->json(['recus' => $recus]);
    }

    #[Route('/rouleau/{id}/rip/terminer', name: 'rip_terminer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ripTerminer(TirageRouleau $tirage, Request $request, EnvoiRip $rip): JsonResponse
    {
        $refus = $this->refusJson($tirage, $request);
        if (null !== $refus) {
            return $refus;
        }
        try {
            $d = $request->toArray();
            $r = $rip->terminer($tirage, (string) ($d['jeton'] ?? ''), (int) ($d['pages'] ?? 1), $this->moi(), new \DateTimeImmutable(), $request->getSession());
            $this->em->flush();
        } catch (\DomainException|\LogicException $e) {
            return $this->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $this->addFlash('success', $r['message']);

        return $this->json(['ok' => true, 'url' => $this->generateUrl('app_atelier_production_fiche', ['id' => $tirage->getId()])]);
    }

    /** Repli : chaque fichier sous son nom de production et une feuille de placement. */
    #[Route('/rouleau/{id}/separes', name: 'separes', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function separes(TirageRouleau $tirage, Request $request, EnvoiRip $rip): Response
    {
        $this->verifierJeton($request);
        $this->exigerAction($tirage->getMachine(), $request);
        try {
            $r = $rip->envoyerSepares($tirage, $this->moi(), new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', $r['message']);

            return $this->redirectToRoute('app_atelier_production_fiche', ['id' => $tirage->getId()]);
        } catch (\DomainException|\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_atelier_production_imposition', ['id' => $tirage->getId()]);
    }

    /** Le fichier envoyé au RIP (ou la feuille de placement), version au choix. */
    #[Route('/rouleau/{id}/rip/{version}', name: 'rip_fichier', requirements: ['id' => '\d+', 'version' => '\d{1,3}'], methods: ['GET'])]
    public function ripFichier(TirageRouleau $tirage, int $version, EnvoiRip $rip): Response
    {
        $envoi = $tirage->envoi($version) ?? throw $this->createNotFoundException('Envoi inconnu.');
        $chemin = $rip->chemin((string) ($envoi['chemin'] ?? '')) ?? throw $this->createNotFoundException('Fichier introuvable sur le serveur.');

        return FichierEnvoye::reponse($chemin, (string) ($envoi['nom'] ?? basename($chemin)), true);
    }

    // ------------------------------------------------------------------
    // PD03 · Fiche de production
    // ------------------------------------------------------------------

    #[Route('/rouleau/{id}', name: 'fiche', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function fiche(TirageRouleau $tirage, Request $request): Response
    {
        return $this->render('production_rouleau/fiche.html.twig', $this->donneesFiche($tirage, $request) + [
            'qr' => $this->qr($tirage),
        ]);
    }

    /** Fiche à imprimer (A4, sans montant). */
    #[Route('/rouleau/{id}/imprimer', name: 'imprimer', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function imprimer(TirageRouleau $tirage, Request $request): Response
    {
        return $this->render('production_rouleau/fiche_imprimer.html.twig', $this->donneesFiche($tirage, $request) + [
            'qr' => $this->qr($tirage),
        ]);
    }

    /** Cocher ou décocher un point de la fiche (profil, mode, séchage, miroir, support). */
    #[Route('/rouleau/{id}/cocher', name: 'cocher', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function cocher(TirageRouleau $tirage, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerAction($tirage->getMachine(), $request);
        $cle = ControlesTirage::cocheValide((string) $request->request->get('cle'));
        if (null === $cle || !$tirage->estModifiable()) {
            $this->addFlash('warning', null === $cle ? 'Point inconnu.' : 'Le rouleau est déjà en impression.');

            return $this->redirectToRoute('app_atelier_production_fiche', ['id' => $tirage->getId()]);
        }
        $fait = $request->request->getBoolean('fait');
        if ('support' === $cle && $fait) {
            $tirage->confirmerRouleau(null, $this->moi(), new \DateTimeImmutable());
        } else {
            $tirage->cocher($cle, $fait);
        }
        $this->em->flush();

        return $this->redirectToRoute('app_atelier_production_fiche', ['id' => $tirage->getId(), '_fragment' => 'point-'.$cle]);
    }

    /** Code du rouleau scanné (douchette) ou tapé : il doit être la référence de l'article. */
    #[Route('/rouleau/{id}/scanner', name: 'scanner', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function scanner(TirageRouleau $tirage, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerAction($tirage->getMachine(), $request);
        $code = mb_substr(trim((string) $request->request->get('code')), 0, 60);
        $reference = $tirage->getSupport()?->getReference();
        if (null === $reference) {
            $this->addFlash('warning', 'Ce support n\'a pas de référence d\'article : cochez « Support vérifié » après avoir lu l\'étiquette du rouleau.');
        } elseif (ControlesTirage::codeRouleauConforme($code, $reference)) {
            $tirage->confirmerRouleau($code, $this->moi(), new \DateTimeImmutable());
            $this->em->flush();
            $this->addFlash('success', 'Rouleau confirmé : c\'est bien le support commandé.');
        } else {
            $this->addFlash('danger', \sprintf('« %s » n\'est pas le support commandé (%s, référence %s) : changez de rouleau.', $code, $tirage->getLibelleSupport(), $reference));
        }

        return $this->redirectToRoute('app_atelier_production_fiche', ['id' => $tirage->getId(), '_fragment' => 'point-support']);
    }

    /** « Test fait » : le test de buses du jour, noté au journal de la machine. */
    #[Route('/rouleau/{id}/buses', name: 'buses', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function buses(TirageRouleau $tirage, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerAction($tirage->getMachine(), $request);
        $bouchees = max(0, min(999, Entree::entier($request->request, 'bouchees')));
        $this->tirages->noterTestBuses($tirage->getMachine(), $this->moi(), $bouchees, new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash($bouchees > 0 ? 'warning' : 'success', $bouchees > 0 ? 'Test de buses noté : nettoyez les têtes et refaites le test.' : 'Test de buses noté : aucune buse bouchée.');

        return $this->redirectToRoute('app_atelier_production_fiche', ['id' => $tirage->getId(), '_fragment' => 'point-buses']);
    }

    #[Route('/rouleau/{id}/demarrer', name: 'demarrer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function demarrer(TirageRouleau $tirage, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerAction($tirage->getMachine(), $request);
        try {
            $this->tirages->demarrer($tirage, $this->moi(), new \DateTimeImmutable());
            $this->addFlash('success', \sprintf('Impression démarrée à %s par %s.', $tirage->getDemarreLe()?->format('H:i'), AtelierProduction::prenom($this->moi())));
        } catch (\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_atelier_production_fiche', ['id' => $tirage->getId()]);
    }

    // ------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function donneesFiche(TirageRouleau $tirage, Request $request): array
    {
        $maintenant = new \DateTimeImmutable();
        $points = $this->tirages->controles($tirage, $maintenant);
        $atelier = $this->tirages->atelier($tirage->getMachine());
        $envoyePar = null;
        $envoyeLe = null;
        foreach ($tirage->ordres() as $o) {
            $c = $o->getControlePrePresse();
            if (null !== $c?->getEnvoyeProductionLe() && (null === $envoyeLe || $c->getEnvoyeProductionLe() > $envoyeLe)) {
                $envoyeLe = $c->getEnvoyeProductionLe();
                $envoyePar = $c->getEnvoyeProductionPar();
            }
        }
        $peutAgir = $this->peutAgirSur($tirage->getMachine(), $request);
        $machiniste = $tirage->getDemarrePar() ?? $this->moi();

        return [
            'tirage' => $tirage,
            'etatMachine' => $this->etatMachine($tirage),
            'points' => $points,
            'resume' => ControlesTirage::resume($points),
            'travaux' => $this->tirages->travaux($tirage),
            'apres' => $this->tirages->apresImpression($tirage),
            'atelier' => $atelier,
            'encres' => $atelier?->getNiveauxEncre() ?? [],
            'seuilEncre' => $this->tirages->reglages()['seuilEncre'],
            'nomsEncres' => ControlesTirage::NOMS_ENCRES,
            'machiniste' => $machiniste,
            'machinistePrenom' => AtelierProduction::prenom($machiniste),
            'envoyePar' => $envoyePar,
            'envoyeParPrenom' => AtelierProduction::prenom($envoyePar),
            'envoyeLe' => $envoyeLe,
            'peutAgir' => $peutAgir,
            'raison' => $peutAgir ? null : $this->raisonRefus($tirage->getMachine(), $request),
            'aCocher' => ControlesTirage::A_COCHER,
            'buses' => $this->tirages->busesDuJour($tirage->getMachine(), $maintenant),
            'maintenant' => $maintenant,
            'dossier' => $this->fichiers->dossierAffiche($this->fichiers->codeDossier($tirage->getMachine())),
            'apercus' => array_map(static fn (OrdreProduction $o): ?int => 'image' === PosteMachiniste::typeApercu(PosteMachiniste::fichier($o)) ? PosteMachiniste::fichier($o)?->getId() : null, $tirage->ordres()),
        ];
    }

    /**
     * État de la machine pour la carte de la fiche : à l'arrêt (incident
     * ouvert), en impression (ce rouleau ou un autre), sinon prête.
     *
     * @return array{libelle: string, couleur: string}
     */
    private function etatMachine(TirageRouleau $tirage): array
    {
        $machine = $tirage->getMachine();
        foreach ($this->atelierProduction->incidentsOuverts([$machine])[(int) $machine->getId()] ?? [] as $incident) {
            if ($incident->estArret()) {
                return ['libelle' => 'À l\'arrêt : '.$incident->getTitre(), 'couleur' => 'rouge'];
            }
        }
        if (\in_array($tirage->getStatut(), [TirageRouleau::STATUT_EN_IMPRESSION, TirageRouleau::STATUT_EN_PAUSE], true)) {
            return ['libelle' => TirageRouleau::STATUT_EN_PAUSE === $tirage->getStatut() ? 'En pause' : 'En impression', 'couleur' => 'orange'];
        }
        $autre = $this->em->getRepository(TirageRouleau::class)->findOneBy(['machine' => $machine, 'statut' => TirageRouleau::STATUT_EN_IMPRESSION]);
        if (null !== $autre) {
            return ['libelle' => 'Occupée : '.$autre->getNumero().' en impression', 'couleur' => 'orange'];
        }

        return ['libelle' => 'Prête', 'couleur' => 'vert'];
    }

    private function qr(TirageRouleau $tirage): string
    {
        $url = $this->generateUrl('app_atelier_production_fiche', ['id' => $tirage->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        return (new PngWriter())->write(new QrCode(data: $url, encoding: new Encoding('UTF-8'), errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 240, margin: 4, roundBlockSizeMode: RoundBlockSizeMode::Margin))->getDataUri();
    }

    private function nomCourtClient(OrdreProduction $o, int $max = 40): string
    {
        return mb_substr((string) $o->getCommandeDetail()?->getCommande()?->getClients()?->getNomComplet(), 0, $max);
    }

    private function refusJson(TirageRouleau $tirage, Request $request): ?JsonResponse
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['message' => 'Jeton de sécurité invalide.'], Response::HTTP_FORBIDDEN);
        }
        $raison = $this->raisonRefus($tirage->getMachine(), $request);
        if (null !== $raison) {
            return $this->json(['message' => $raison], Response::HTTP_FORBIDDEN);
        }
        if (!$tirage->estModifiable()) {
            return $this->json(['message' => 'Ce rouleau est déjà en impression : il ne peut plus être renvoyé au RIP.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return null;
    }

    private function peutAgirSur(?Machines $machine, Request $request): bool
    {
        return null === $this->raisonRefus($machine, $request);
    }

    /**
     * La règle de l'atelier (docs/atelier-production.md, comme Ma file) :
     * « Statuts de production », puis chef d'atelier, poste de la machine,
     * opérateur affecté ; machine sans opérateur : pointé aujourd'hui.
     */
    private function raisonRefus(?Machines $machine, Request $request): ?string
    {
        if (!$this->isGranted('ROLE_PRODUCTION_GERER')) {
            return 'Il faut le droit « Statuts de production ».';
        }
        if ($this->isGranted(Droits::ATELIER_CHEF)) {
            return null;
        }
        $ip = $request->getClientIp();
        $poste = null !== $ip ? $this->machinesRepository->findOneByAdresseIp($ip) : null;
        if ($poste instanceof Machines && (null === $machine || $poste === $machine)) {
            return null;
        }
        $atelier = null !== $machine ? $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine]) : null;
        if (null !== $atelier && $atelier->getOperateurs()->contains($this->moi())) {
            return null;
        }
        if ($poste instanceof Machines) {
            return \sprintf('Ce poste est celui de %s : agissez depuis %s.', $poste->getNom(), $machine?->getNom() ?? 'sa machine');
        }
        if (null !== $atelier && !$atelier->getOperateurs()->isEmpty()) {
            return \sprintf('Seuls les opérateurs affectés à %s, le chef d\'atelier ou le poste de la machine peuvent agir.', $machine?->getNom());
        }
        $dernier = $this->pointages->dernier($this->moi());
        $pointe = null !== $dernier && null === $dernier->getDepartLe() && $dernier->getArriveeLe()->format('Ymd') === (new \DateTimeImmutable())->format('Ymd');

        return $pointe ? null : 'Pointez votre arrivée à l\'atelier pour agir depuis le téléphone (ou utilisez le poste de la machine).';
    }

    private function exigerAction(?Machines $machine, Request $request): void
    {
        $raison = $this->raisonRefus($machine, $request);
        if (null !== $raison) {
            throw $this->createAccessDeniedException($raison);
        }
    }

    private function verifierJeton(Request $request): void
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
