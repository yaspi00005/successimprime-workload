<?php

namespace App\Controller;

use App\Entity\AtelierMachine;
use App\Entity\JournalMachine;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\RetourPrepresse;
use App\Entity\User;
use App\Repository\MachinesRepository;
use App\Repository\OrdreProductionRepository;
use App\Repository\PointageRepository;
use App\Repository\RetourPrepresseRepository;
use App\Service\Atelier\PosteMachiniste;
use App\Service\Atelier\TempsEstime;
use App\Service\Parametres\Parametres;
use App\Service\Atelier\ActionsOrdre;
use App\Service\Http\Entree;
use App\Twig\ProduitsClientsExtension;
use App\Service\SuiviMetiers\FileMachine;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Poste machiniste (maquettes OP01 à OP06, docs/poste-machiniste.md) :
 * la file en colonnes, vérifier le fichier, retour au prépresse, machine
 * et temps estimé, travail en cours, étiquettes des colis et « Prêt pour
 * livraison ». Bureau et téléphone. Aucun montant n'est montré, sauf le
 * reste à encaisser pour qui a le droit Paiements.
 *
 * Démarrer, terminer et signaler : depuis le poste de la machine (adresse
 * IP reconnue) ou depuis le téléphone d'une personne pointée à l'atelier.
 */
#[Route('/atelier/ma-file', name: 'app_ma_file_')]
#[IsGranted('ROLE_PRODUCTION_VOIR')]
final class MaFileController extends AbstractController
{
    private const CSRF = 'ma-file';

    public function __construct(
        private readonly PosteMachiniste $poste,
        private readonly FileMachine $file,
        private readonly ActionsOrdre $actions,
        private readonly MachinesRepository $machines,
        private readonly OrdreProductionRepository $ordres,
        private readonly RetourPrepresseRepository $retours,
        private readonly PointageRepository $pointages,
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
    ) {
    }

    /** OP01 : la file du machiniste en colonnes. */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $moi = $this->moi();
        $mesMachines = $this->poste->machinesDe($moi, $maintenant);
        $posteMachine = $this->machineDuPoste($request);
        $toutes = $this->machines->findBy([], ['nom' => 'ASC']);

        // ?m=toutes, ?m=12 (une machine) ; par défaut la machine du poste, sinon les siennes, sinon toutes.
        $choix = (string) $request->query->get('m', '');
        $suivies = match (true) {
            'toutes' === $choix => [],
            ctype_digit($choix) && null !== ($m = $this->machines->find((int) $choix)) => [$m],
            null !== $posteMachine => [$posteMachine],
            default => $mesMachines,
        };
        $file = $this->poste->file($suivies, $maintenant);

        return $this->render('ma_file/index.html.twig', [
            'file' => $file,
            'colonnes' => PosteMachiniste::COLONNES,
            'suivies' => $suivies,
            'mesMachines' => $mesMachines,
            'toutes' => $toutes,
            'choix' => $choix,
            'posteMachine' => $posteMachine,
            'maintenant' => $maintenant,
            'peutAgir' => $this->isGranted('ROLE_PRODUCTION_GERER'),
        ]);
    }

    /** « Scanner un bon » : numéro de commande, d'ordre ou d'étiquette (douchette ou saisie). */
    #[Route('/scanner', name: 'scanner', methods: ['GET'])]
    public function scanner(Request $request): Response
    {
        $code = strtoupper(trim((string) $request->query->get('code')));
        if (preg_match('#/colis/([A-Z0-9\-]+)#', $code, $m)) {
            $code = $m[1];
        }
        $ordre = null;
        if ($code !== '') {
            $ordre = $this->ordres->findOneBy(['numeroEtiquette' => $code]) ?? $this->ordres->findOneBy(['numero' => $code]);
            if ($ordre === null) {
                $ordre = $this->em->createQueryBuilder()->select('o')->from(OrdreProduction::class, 'o')
                    ->join('o.commandeDetail', 'd')->join('d.commande', 'c')
                    ->where('c.numero = :n')->andWhere('o.statut <> :annule')->setParameter('n', $code)->setParameter('annule', OrdreProduction::STATUT_ANNULE)
                    ->orderBy('o.id', 'DESC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
            }
        }
        if (!$ordre instanceof OrdreProduction) {
            $this->addFlash('danger', $code === '' ? 'Scannez ou tapez le numéro du bon.' : \sprintf('Aucun travail trouvé pour « %s ».', mb_substr($code, 0, 40)));

            return $this->redirectToRoute('app_ma_file_index');
        }

        return $this->redirectToRoute('app_ma_file_travail', ['id' => $ordre->getId()]);
    }

    /**
     * Un travail : selon son état, vérifier le fichier, le travail (prêt
     * ou en cours, OP06) ou les étiquettes.
     */
    #[Route('/{id}', name: 'travail', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function travail(OrdreProduction $ordre, Request $request): Response
    {
        $etat = PosteMachiniste::etat($ordre, $this->retours->ouvertPour($ordre));
        if (\in_array($etat, [PosteMachiniste::A_VERIFIER, PosteMachiniste::RETOURNE], true)) {
            return $this->redirectToRoute('app_ma_file_verifier', ['id' => $ordre->getId()]);
        }
        if (\in_array($etat, [PosteMachiniste::A_ETIQUETER, PosteMachiniste::PRET_LIVRAISON], true)) {
            return $this->redirectToRoute('app_ma_file_etiquettes', ['id' => $ordre->getId()]);
        }
        $maintenant = new \DateTimeImmutable();
        $evenements = [];
        if ($ordre->getMachine() !== null) {
            $evenements = array_values(array_filter($this->file->journee($ordre->getMachine(), $maintenant)['evenements'], static fn (JournalMachine $j): bool => $j->getOrdre() === $ordre));
        }

        return $this->render('ma_file/travail.html.twig', [
            'o' => $ordre,
            'ligne' => $ordre->getCommandeDetail(),
            'carte' => $this->poste->carte($ordre, $maintenant),
            'progression' => FileMachine::progression($ordre, $maintenant),
            'libreA' => $ordre->getMachine() !== null ? $this->poste->machineLibreA($ordre->getMachine(), $maintenant, $ordre) : null,
            'evenements' => $evenements,
            'bareme' => $this->parametres->bareme(),
            'faits' => FileMachine::faitsOrdre($ordre),
            'motifsGache' => ActionsOrdre::MOTIFS_GACHE,
            'maintenant' => $maintenant,
            'peutAgir' => $this->peutProduire($ordre, $request),
            'raisonRefus' => $this->raisonRefus($ordre, $request),
            'jeton' => self::CSRF,
        ]);
    }

    /** OP02 : vérifier la conformité du fichier ; OP04 : retour au prépresse (fenêtre). */
    #[Route('/{id}/verifier', name: 'verifier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function verifier(OrdreProduction $ordre): Response
    {
        $ligne = $ordre->getCommandeDetail();
        $fichier = PosteMachiniste::fichier($ordre);
        $controle = $this->poste->dernierControle($ordre);
        $conception = $controle !== null ? $this->em->getRepository(\App\Entity\Conception::class)->findOneBy(['controle' => $controle], ['id' => 'DESC']) : null;

        return $this->render('ma_file/verifier.html.twig', [
            'o' => $ordre,
            'ligne' => $ligne,
            'carte' => $this->poste->carte($ordre, new \DateTimeImmutable()),
            'fichier' => $fichier,
            'typeApercu' => PosteMachiniste::typeApercu($fichier),
            'analyse' => $this->poste->analyseFichier($ordre),
            'controle' => $controle,
            'valideur' => $this->poste->graphiste($ordre),
            'batValide' => $controle?->isBatValide() ?? false,
            'controles' => PosteMachiniste::CONTROLES,
            'coches' => $this->poste->controlesProposes($ordre),
            'retour' => $this->retours->ouvertPour($ordre),
            'dernierRetour' => $this->retours->dernierPour($ordre),
            'graphiste' => $this->poste->graphiste($ordre),
            'motifs' => RetourPrepresse::MOTIFS,
            'zone' => PosteMachiniste::ZONE_SECURITE_CM,
            'peutAgir' => $this->isGranted('ROLE_PRODUCTION_GERER') && $ordre->estADemarrer(),
            'maintenant' => new \DateTimeImmutable(),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/{id}/conforme', name: 'conforme', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function conforme(OrdreProduction $ordre, Request $request, ProduitsClientsExtension $produitsClients): Response
    {
        $this->jeton($request);
        $observation = trim((string) $ordre->getCommandeDetail()?->getObservation()) !== '';
        [$controles, $manquent] = PosteMachiniste::lireControles($request->request->all('controle'), $observation);
        if ($this->retours->ouvertPour($ordre) !== null) {
            $this->addFlash('danger', 'Ce travail est au prépresse : attendez le fichier corrigé.');
        } elseif (\in_array($produitsClients->etatFichierLie($ordre->getCommandeDetail()), ['modifie', 'introuvable'], true)) {
            $this->addFlash('danger', 'Le fichier lié a changé ou est introuvable depuis le lien : retournez-le au prépresse.');
        } elseif ($manquent !== []) {
            $this->addFlash('danger', 'Cochez avant de déclarer le fichier conforme : '.mb_strtolower(implode(', ', $manquent)).'.');
        } else {
            try {
                $this->poste->verifierFichier($ordre, $this->moi(), $controles, new \DateTimeImmutable());
                $this->addFlash('success', 'Fichier conforme. Choisissez la machine et estimez le temps.');

                return $this->redirectToRoute('app_ma_file_estimer', ['id' => $ordre->getId()]);
            } catch (\LogicException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_ma_file_verifier', ['id' => $ordre->getId()]);
    }

    #[Route('/{id}/retour-prepresse', name: 'retour', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function retour(OrdreProduction $ordre, Request $request): Response
    {
        $this->jeton($request);
        $annotation = json_decode((string) $request->request->get('annotation', '[]'), true);
        try {
            $retour = $this->poste->retournerAuPrepresse(
                $ordre,
                $this->moi(),
                array_map('strval', $request->request->all('motifs')),
                (string) $request->request->get('commentaire'),
                \is_array($annotation) ? array_values($annotation) : [],
                new \DateTimeImmutable(),
            );
            $this->addFlash('success', \sprintf('Renvoyé au prépresse%s. Vous serez prévenu dès que le fichier corrigé revient.', $retour->getDestinataire() !== null ? ' ('.\App\Service\Pointage\Pointages::nom($retour->getDestinataire()).')' : ''));

            return $this->redirectToRoute('app_ma_file_index');
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_ma_file_verifier', ['id' => $ordre->getId(), 'retour' => 1]);
        }
    }

    /** OP03 : choisir la machine et estimer le temps de chaque étape. */
    #[Route('/{id}/estimer', name: 'estimer', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function estimer(OrdreProduction $ordre, Request $request): Response
    {
        if ($ordre->estTermine() || $ordre->estAnnule()) {
            return $this->redirectToRoute('app_ma_file_travail', ['id' => $ordre->getId()]);
        }
        $maintenant = new \DateTimeImmutable();
        $choix = $this->poste->choixMachines($ordre, $maintenant);
        $demandee = Entree::entier($request->query, 'machine');
        $selection = null;
        foreach ($choix as $c) {
            if ($c['possible'] && ($c['id'] === $demandee || ($demandee === 0 && $ordre->getMachine()?->getId() === $c['id']))) {
                $selection = $c;
            }
        }
        $selection ??= array_values(array_filter($choix, static fn (array $c): bool => $c['recommandee']))[0] ?? null;
        $machine = $selection['machine'] ?? $ordre->getMachine();
        $etapes = PosteMachiniste::etapesProposees($ordre, $machine);
        $deja = [];
        foreach ($ordre->getEstimation()['etapes'] ?? [] as $e) {
            $deja[$e['cle']] = (int) $e['estime'];
        }
        $libre = $selection['libreA'] ?? null;
        $debut = $ordre->getDemarreLe() ?? TempsEstime::debut($maintenant, $libre);
        $ligne = $ordre->getCommandeDetail();

        return $this->render('ma_file/estimer.html.twig', [
            'o' => $ordre,
            'ligne' => $ligne,
            'carte' => $this->poste->carte($ordre, $maintenant),
            'choix' => $choix,
            'selection' => $selection,
            'etapes' => $etapes,
            'deja' => $deja,
            'raison' => $ordre->getEstimation()['raison'] ?? null,
            'debut' => $debut,
            'libreA' => $libre,
            'rouleau' => TempsEstime::rouleau($ligne?->getLargeur() !== null ? (float) $ligne->getLargeur() : null, $ligne?->getLongueur() !== null ? (float) $ligne->getLongueur() : null, (int) ($ordre->getQuantite() ?? $ligne?->getQuantite() ?? 1), $machine?->getLargeurImpression() !== null ? (float) $machine->getLargeurImpression() : null),
            'recentes' => $this->poste->estimationsRecentes($this->moi(), $maintenant),
            'promis' => $ordre->getDateLimite() ?? $ligne?->getCommande()?->getDateLivraison(),
            'peutAgir' => $this->isGranted('ROLE_PRODUCTION_GERER'),
            'peutDemarrer' => $this->peutProduire($ordre, $request, $machine),
            'maintenant' => $maintenant,
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/{id}/estimer', name: 'estimer_enregistrer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function estimerEnregistrer(OrdreProduction $ordre, Request $request): Response
    {
        $this->jeton($request);
        $maintenant = new \DateTimeImmutable();
        $machine = null;
        foreach ($this->poste->choixMachines($ordre, $maintenant) as $c) {
            if ($c['id'] === Entree::entier($request->request, 'machine')) {
                if (!$c['possible']) {
                    $this->addFlash('danger', $c['nom'].' : '.$c['raison'].'.');

                    return $this->redirectToRoute('app_ma_file_estimer', ['id' => $ordre->getId()]);
                }
                $machine = $c['machine'];
            }
        }
        if (!$machine instanceof Machines) {
            $this->addFlash('danger', 'Choisissez la machine.');

            return $this->redirectToRoute('app_ma_file_estimer', ['id' => $ordre->getId()]);
        }
        $saisies = $request->request->all('estime');
        $etapes = [];
        foreach (PosteMachiniste::etapesProposees($ordre, $machine) as $e) {
            $saisie = TempsEstime::lire(\is_string($saisies[$e['cle']] ?? null) ? $saisies[$e['cle']] : null);
            $etapes[] = $e + ['estime' => $saisie ?? (int) ($e['propose'] ?? 0)];
        }
        $libre = $this->poste->machineLibreA($machine, $maintenant, $ordre);
        try {
            $ordre->estimer($etapes, (string) $request->request->get('raison'), TempsEstime::debut($maintenant, $libre), $machine);
            $this->em->flush();
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_ma_file_estimer', ['id' => $ordre->getId(), 'machine' => $machine->getId()]);
        }

        $demarrer = 'demarrer' === $request->request->get('action') && $ordre->estADemarrer() && ($libre === null || $libre <= $maintenant->modify('+5 minutes'));
        if ($demarrer && $this->peutProduire($ordre, $request, $machine)) {
            try {
                $this->actions->demarrer($ordre, $this->moi(), $machine);
                $this->addFlash('success', \sprintf('Démarré sur %s. Fin estimée vers %s.', $machine->getNom(), $ordre->getFinPrevueLe()?->format('H:i')));
            } catch (\LogicException|\InvalidArgumentException $e) {
                $this->addFlash('danger', $e->getMessage());
            }
        } else {
            $this->addFlash('success', \sprintf('Estimation enregistrée : %s sur %s, début prévu à %s.', TempsEstime::duree($ordre->getDureeEstimeeMinutes()), $machine->getNom(), $ordre->getDebutPrevuLe()?->format('H:i')));
        }

        return $this->redirectToRoute('app_ma_file_travail', ['id' => $ordre->getId()]);
    }

    #[Route('/{id}/{action}', name: 'agir', requirements: ['id' => '\d+', 'action' => 'demarrer|pause|reprendre'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function agir(OrdreProduction $ordre, string $action, Request $request): Response
    {
        $this->jeton($request);
        if (!$this->peutProduire($ordre, $request)) {
            $this->addFlash('danger', (string) $this->raisonRefus($ordre, $request));

            return $this->redirectToRoute('app_ma_file_travail', ['id' => $ordre->getId()]);
        }
        try {
            match ($action) {
                'demarrer' => $this->demarrer($ordre),
                'pause' => $this->actions->pause($ordre),
                default => $this->actions->reprendre($ordre, $this->moi()),
            };
            $this->addFlash('success', match ($action) {
                'demarrer' => 'Démarré à '.$ordre->getDemarreLe()?->format('H:i').'.',
                'pause' => 'En pause : le temps de pause ne compte pas.',
                default => 'Repris.',
            });
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_ma_file_travail', ['id' => $ordre->getId()]);
    }

    #[Route('/{id}/terminer', name: 'terminer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function terminer(OrdreProduction $ordre, Request $request): Response
    {
        $this->jeton($request);
        if (!$this->peutProduire($ordre, $request)) {
            $this->addFlash('danger', (string) $this->raisonRefus($ordre, $request));

            return $this->redirectToRoute('app_ma_file_travail', ['id' => $ordre->getId()]);
        }
        try {
            $motif = (string) $request->request->get('motif_gache');
            $this->actions->terminer($ordre, $this->moi(), Entree::entier($request->request, 'quantite_produite'), Entree::entier($request->request, 'quantite_rebut'), isset(ActionsOrdre::MOTIFS_GACHE[$motif]) ? $motif : null, (string) $request->request->get('observation'));
            $this->addFlash('success', \sprintf('Terminé : %d bon%s, %d en gâche. Imprimez les étiquettes des colis.', $ordre->getQuantiteConforme(), $ordre->getQuantiteConforme() > 1 ? 's' : '', $ordre->getQuantiteRebut()));

            return $this->redirectToRoute('app_ma_file_etiquettes', ['id' => $ordre->getId()]);
        } catch (\LogicException|\InvalidArgumentException|\DomainException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_ma_file_travail', ['id' => $ordre->getId()]);
        }
    }

    /** Gâche, incident, entretien des têtes, réimpression (journal de la machine, points du barème). */
    #[Route('/signaler', name: 'signaler', methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function signaler(Request $request): Response
    {
        $this->jeton($request);
        $type = (string) $request->request->get('type');
        $ordre = $request->request->get('ordre') ? $this->ordres->find((int) $request->request->get('ordre')) : null;
        $machine = $ordre?->getMachine() ?? $this->machineDuPoste($request) ?? (Entree::entier($request->request, 'machine') > 0 ? $this->machines->find(Entree::entier($request->request, 'machine')) : null);
        $quantite = is_numeric(str_replace(',', '.', (string) $request->request->get('quantite'))) ? (float) str_replace(',', '.', (string) $request->request->get('quantite')) : null;
        $note = (string) $request->request->get('note');

        if (!$machine instanceof Machines || !isset(JournalMachine::TYPES[$type])) {
            $this->addFlash('danger', 'Choisissez la machine et ce qui est signalé.');
        } elseif ($ordre !== null && !$this->peutProduire($ordre, $request)) {
            $this->addFlash('danger', (string) $this->raisonRefus($ordre, $request));
        } elseif ($ordre === null && $this->machineDuPoste($request) !== $machine && !$this->estPointe()) {
            $this->addFlash('danger', 'Signalez depuis le poste de la machine, ou pointez votre arrivée à l\'atelier.');
        } elseif (\in_array($type, [JournalMachine::GACHE, JournalMachine::REIMPRESSION], true) && ($quantite === null || $quantite <= 0 || $quantite > 1000)) {
            $this->addFlash('danger', 'Indiquez les m² concernés.');
        } elseif ($type === JournalMachine::INCIDENT && trim($note) === '') {
            $this->addFlash('danger', 'Décrivez le problème en quelques mots.');
        } else {
            $j = $this->file->signaler($machine, $this->moi(), $type, new \DateTimeImmutable(), $ordre, $quantite, $note);
            $this->addFlash('success', match ($type) {
                JournalMachine::TETES => 'Entretien des têtes noté à '.$j->getLe()->format('H:i').'.',
                JournalMachine::GACHE => \sprintf('Gâche de %s m² notée.', str_replace('.', ',', (string) $j->getQuantite())),
                JournalMachine::REIMPRESSION => 'Réimpression notée.',
                default => 'Problème signalé : il ne retire aucun point.',
            });
        }

        return $ordre !== null
            ? $this->redirectToRoute('app_ma_file_travail', ['id' => $ordre->getId()])
            : $this->redirectToRoute('app_ma_file_index');
    }

    /** OP05 : suivi de production, étiquettes des colis avec QR, puis « Prêt pour livraison ». */
    #[Route('/{id}/etiquettes', name: 'etiquettes', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function etiquettes(OrdreProduction $ordre, Request $request): Response
    {
        if (!$ordre->estTermine()) {
            return $this->redirectToRoute('app_ma_file_travail', ['id' => $ordre->getId()]);
        }
        $colis = max(1, min(99, Entree::entier($request->query, 'colis', $ordre->getNombreColis() ?? 1)));

        return $this->render('ma_file/etiquettes.html.twig', [
            'o' => $ordre,
            'ligne' => $ordre->getCommandeDetail(),
            'suivi' => $this->poste->suivi($ordre),
            'livraison' => $this->poste->livraison($ordre, $this->isGranted('ROLE_PAIEMENT_VOIR')),
            'colis' => $colis,
            'qr' => $this->qr($ordre),
            'peutAgir' => $this->isGranted('ROLE_PRODUCTION_GERER'),
            'maintenant' => new \DateTimeImmutable(),
            'jeton' => self::CSRF,
        ]);
    }

    /** Enregistre le nombre de colis et rend la page d'impression (100 × 70 mm, une étiquette par page). */
    #[Route('/{id}/etiquettes', name: 'etiquettes_imprimer', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function imprimerEtiquettes(OrdreProduction $ordre, Request $request): Response
    {
        $this->jeton($request);
        try {
            $this->poste->etiqueter($ordre, Entree::entier($request->request, 'colis', 1), $this->moi(), new \DateTimeImmutable());
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_ma_file_etiquettes', ['id' => $ordre->getId()]);
        }

        return $this->render('ma_file/impression_etiquettes.html.twig', [
            'o' => $ordre,
            'livraison' => $this->poste->livraison($ordre, false),
            'colis' => (int) $ordre->getNombreColis(),
            'qr' => $this->qr($ordre),
            'maintenant' => new \DateTimeImmutable(),
        ]);
    }

    #[Route('/{id}/pret-livraison', name: 'pret', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function pret(OrdreProduction $ordre, Request $request): Response
    {
        $this->jeton($request);
        try {
            $this->poste->pretPourLivraison($ordre, $this->moi(), new \DateTimeImmutable());
            $aLivrer = $ordre->getCommandeDetail()?->getCommande()?->estALivrer() ?? false;
            $livreur = $this->poste->livraison($ordre, false)['livreur'];
            $this->addFlash('success', $aLivrer ? \sprintf('Prêt pour livraison : %s et le client sont prévenus.', $livreur ?? 'le bureau') : 'Prêt : le client voit « Prête à retirer » dans son espace.');

            return $this->redirectToRoute('app_ma_file_index');
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());

            return $this->redirectToRoute('app_ma_file_etiquettes', ['id' => $ordre->getId()]);
        }
    }

    private function demarrer(OrdreProduction $ordre): void
    {
        if (!$ordre->estFichierVerifie()) {
            throw new \LogicException('Vérifiez d\'abord le fichier.');
        }
        if ($ordre->getMachine() === null) {
            throw new \LogicException('Choisissez la machine et estimez le temps avant de démarrer.');
        }
        $this->actions->demarrer($ordre, $this->moi(), $ordre->getMachine());
    }

    /**
     * Démarrer, terminer et signaler : droit « Statuts de production », et
     * le poste de la machine du travail ou une personne pointée à l'atelier.
     */
    private function peutProduire(OrdreProduction $ordre, Request $request, ?Machines $machine = null): bool
    {
        return $this->raisonRefus($ordre, $request, $machine) === null;
    }

    private function raisonRefus(OrdreProduction $ordre, Request $request, ?Machines $machine = null): ?string
    {
        if (!$this->isGranted('ROLE_PRODUCTION_GERER')) {
            return 'Il faut le droit « Statuts de production ».';
        }
        $machine ??= $ordre->getMachine();
        // Même règle que l'atelier (docs/atelier-production.md) : chef d'atelier, poste de la machine, opérateur affecté.
        if ($this->isGranted('ROLE_ATELIER_CHEF')) {
            return null;
        }
        $poste = $this->machineDuPoste($request);
        if ($poste !== null && ($machine === null || $poste === $machine)) {
            return null;
        }
        $atelier = $machine !== null ? $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine]) : null;
        if ($atelier !== null && $atelier->getOperateurs()->contains($this->moi())) {
            return null;
        }
        if ($poste !== null) {
            return \sprintf('Ce poste est celui de %s : démarrez ce travail depuis %s.', $poste->getNom(), $machine?->getNom() ?? 'sa machine');
        }
        if ($atelier !== null && !$atelier->getOperateurs()->isEmpty()) {
            return \sprintf('Seuls les opérateurs affectés à %s, le chef d\'atelier ou le poste de la machine peuvent agir.', $machine?->getNom());
        }

        // Machine sans opérateur affecté : la personne pointée à l'atelier aujourd'hui.
        return $this->estPointe() ? null : 'Pointez votre arrivée à l\'atelier pour démarrer ou terminer depuis le téléphone (ou utilisez le poste de la machine).';
    }

    /** La personne a pointé son arrivée aujourd'hui et pas encore son départ. */
    private function estPointe(): bool
    {
        $dernier = $this->pointages->dernier($this->moi());

        return $dernier !== null && $dernier->getDepartLe() === null && $dernier->getArriveeLe()->format('Ymd') === (new \DateTimeImmutable())->format('Ymd');
    }

    private function machineDuPoste(Request $request): ?Machines
    {
        $ip = $request->getClientIp();

        return $ip !== null ? $this->machines->findOneByAdresseIp($ip) : null;
    }

    /** QR de l'étiquette : ouvre le colis pour le livreur (sa remise) ou le bureau. */
    private function qr(OrdreProduction $ordre): string
    {
        // Avant l'impression, le numéro que l'étiquette recevra (PosteMachiniste::etiqueter).
        $numero = $ordre->getNumeroEtiquette() ?? \sprintf('ETQ-%s-%06d', (new \DateTimeImmutable())->format('Y'), $ordre->getId());
        $url = $this->generateUrl('app_livraisons_tournee_colis', ['numero' => $numero], UrlGeneratorInterface::ABSOLUTE_URL);

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
