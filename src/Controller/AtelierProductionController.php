<?php

namespace App\Controller;

use App\Entity\AtelierMachine;
use App\Entity\EntretienMachine;
use App\Entity\IncidentMachine;
use App\Entity\Machines;
use App\Entity\Maintenance;
use App\Entity\OrdreProduction;
use App\Entity\User;
use App\Repository\MachinesRepository;
use App\Security\Droits;
use App\Service\Atelier\ActionsOrdre;
use App\Service\Atelier\AtelierProduction;
use App\Service\Atelier\AvancementOrdre;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Atelier de production (docs/atelier-production.md, maquettes AT01 à AT05) :
 * vue en direct, files d'attente, planning de la semaine, maintenance et
 * incidents, écran de l'opérateur, mode TV.
 *
 * Le chef d'atelier (droit « Chef d'atelier ») et la direction voient toutes
 * les machines ; un opérateur, celles qui lui sont affectées. Aucun montant,
 * sauf le devis d'une réparation avec le droit « Marges et coûts ». ERP
 * local seulement (AtelierHorsLigneSubscriber).
 */
#[Route('/atelier', name: 'app_atelier_')]
#[IsGranted('ROLE_PRODUCTION_VOIR')]
final class AtelierProductionController extends AbstractController
{
    public const CSRF = 'atelier-production';

    public function __construct(
        private readonly AtelierProduction $atelier,
        private readonly EntityManagerInterface $em,
        private readonly MachinesRepository $machinesRepository,
    ) {
    }

    // ------------------------------------------------------------------
    // AT01 · Vue en direct
    // ------------------------------------------------------------------

    #[Route('/direct', name: 'direct', methods: ['GET'])]
    public function direct(): Response
    {
        $maintenant = new \DateTimeImmutable();
        $machines = $this->machines();

        return $this->render('atelier_production/direct.html.twig', $this->atelier->vueDirecte($machines, $maintenant) + [
            'maintenant' => $maintenant,
            'chef' => $this->chef(),
        ]);
    }

    /** Mode TV (« Afficher sur l'écran TV ») : plein écran, sans nom de client ni montant, rechargé chaque minute. */
    #[Route('/tv', name: 'tv', methods: ['GET'])]
    public function tv(): Response
    {
        $maintenant = new \DateTimeImmutable();

        return $this->render('atelier_production/tv.html.twig', $this->atelier->vueDirecte($this->machines(), $maintenant) + [
            'maintenant' => $maintenant,
            'retour' => $this->generateUrl('app_atelier_direct'),
        ]);
    }

    // ------------------------------------------------------------------
    // AT02 · Files d'attente
    // ------------------------------------------------------------------

    #[Route('/files/{id}', name: 'files', requirements: ['id' => '\d+'], defaults: ['id' => null], methods: ['GET'])]
    public function files(?int $id, Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $machines = $this->machines();
        $machine = null;
        foreach ($machines as $m) {
            if ($m->getId() === $id) {
                $machine = $m;
            }
        }
        if (null !== $id && null === $machine) {
            throw $this->createNotFoundException();
        }
        $machine ??= $this->machinePreferee($machines, $request);

        $onglets = [];
        $incidents = $this->atelier->incidentsOuverts($machines);
        foreach ($machines as $m) {
            $onglets[] = ['machine' => $m, 'nombre' => \count($this->atelier->file($m, $maintenant)), 'panne' => [] !== array_filter($incidents[(int) $m->getId()] ?? [], static fn (IncidentMachine $i): bool => $i->estArret())];
        }
        $file = null !== $machine ? $this->atelier->file($machine, $maintenant) : [];
        if (null !== $machine) {
            $request->getSession()->set('atelier_machine', $machine->getId());
        }

        return $this->render('atelier_production/files.html.twig', [
            'machine' => $machine,
            'onglets' => $onglets,
            'file' => $file,
            'totaux' => AtelierProduction::totaux($file),
            'consommables' => null !== $machine ? $this->atelier->consommables($machine, $file) : [],
            'operateur' => null !== $machine ? $this->atelier->operateur($machine, $maintenant) : null,
            'atelierMachine' => null !== $machine ? $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine]) : null,
            'personnel' => $this->chef() ? $this->personnelProduction() : [],
            'panne' => null !== $machine ? array_values(array_filter($incidents[(int) $machine->getId()] ?? [], static fn (IncidentMachine $i): bool => $i->estArret()))[0] ?? null : null,
            'chef' => $this->chef(),
            'peutAgir' => null !== $machine && $this->peutAgir($machine, $request),
            'encres' => AtelierMachine::ENCRES,
            'maintenant' => $maintenant,
        ]);
    }

    /** Ordre de passage choisi par le chef (glisser-déposer ou flèches), ou « Réordonner automatiquement ». */
    #[Route('/files/{id}/ordre', name: 'files_ordre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ordreFile(Machines $machine, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerChef();
        $atelier = $this->atelier->atelier($machine);
        $ids = array_map(static fn (OrdreProduction $o): int => (int) $o->getId(), array_column($this->atelier->file($machine, new \DateTimeImmutable()), 'ordre'));

        if ($request->request->has('automatique')) {
            $atelier->setOrdreFile([]);
            $this->addFlash('success', 'File réordonnée : retards d\'abord, puis date promise, même matière groupée, travaux bloqués en bas.');
        } else {
            $choisi = array_values(array_filter(array_map('intval', (array) $request->request->all('ordres')), static fn (int $i): bool => \in_array($i, $ids, true)));
            $deplacer = $request->request->getInt('deplacer');
            $sens = (string) $request->request->get('sens');
            if ($deplacer > 0 && \in_array($sens, ['haut', 'bas'], true)) {
                $choisi = $ids;
                $rang = array_search($deplacer, $choisi, true);
                $cible = false !== $rang ? $rang + ('haut' === $sens ? -1 : 1) : -1;
                if (false !== $rang && $cible >= 0 && $cible < \count($choisi)) {
                    [$choisi[$rang], $choisi[$cible]] = [$choisi[$cible], $choisi[$rang]];
                }
            }
            $atelier->setOrdreFile($choisi);
            $this->addFlash('success', 'Ordre de passage enregistré.');
        }
        $this->em->flush();

        return $this->redirectToRoute('app_atelier_files', ['id' => $machine->getId()]);
    }

    /** Opérateurs affectés à une machine (chef d'atelier). */
    #[Route('/machine/{id}/operateurs', name: 'operateurs', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function operateurs(Machines $machine, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerChef();
        $atelier = $this->atelier->atelier($machine);
        $choisis = array_map('intval', (array) $request->request->all('operateurs'));
        foreach ($atelier->getOperateurs()->toArray() as $u) {
            if (!\in_array((int) $u->getId(), $choisis, true)) {
                $atelier->retirerOperateur($u);
            }
        }
        foreach ($this->personnelProduction() as $u) {
            if (\in_array((int) $u->getId(), $choisis, true)) {
                $atelier->ajouterOperateur($u);
            }
        }
        $this->em->flush();
        $this->addFlash('success', 'Opérateurs de '.$machine->getNom().' enregistrés.');

        return $this->redirectToRoute('app_atelier_files', ['id' => $machine->getId()]);
    }

    /** Niveaux d'encre saisis à la main par l'opérateur (aucune donnée ne vient des machines). */
    #[Route('/machine/{id}/encre', name: 'encre', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function encre(Machines $machine, Request $request): Response
    {
        $this->verifierJeton($request);
        if (!$this->peutAgir($machine, $request)) {
            throw $this->createAccessDeniedException('Seuls les opérateurs de la machine et le chef d\'atelier saisissent l\'encre.');
        }
        $this->atelier->atelier($machine)->saisirNiveauxEncre((array) $request->request->all('encre'), $this->moi(), new \DateTimeImmutable());
        $this->em->flush();
        $this->addFlash('success', 'Niveaux d\'encre de '.$machine->getNom().' enregistrés.');

        return $this->retour($request, $this->generateUrl('app_atelier_files', ['id' => $machine->getId()]));
    }

    // ------------------------------------------------------------------
    // AT03 · Planning de la semaine
    // ------------------------------------------------------------------

    #[Route('/planning', name: 'planning', methods: ['GET'])]
    public function planning(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        // Le dimanche (atelier fermé), on montre la semaine qui commence demain.
        $semaine = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('semaine'))
            ?: ('7' === $maintenant->format('N') ? $maintenant->modify('+1 day') : $maintenant);
        $machines = $this->machines();

        return $this->render('atelier_production/planning.html.twig', $this->atelier->planning($machines, $semaine, $maintenant) + [
            'machines' => $machines,
            'chef' => $this->chef(),
            'maintenant_date' => $maintenant,
        ]);
    }

    /**
     * Déplacer un travail (glisser un bloc, ou le petit formulaire sur
     * téléphone) : nouveau jour et, s'il n'a pas commencé, autre machine.
     * Si la fin dépasse la date promise, la caisse est prévenue pour
     * avertir le client.
     */
    #[Route('/planning/deplacer', name: 'planning_deplacer', methods: ['POST'])]
    public function deplacer(Request $request, NotificationService $notifications): Response
    {
        $this->verifierJeton($request);
        $this->exigerChef();
        $ordre = $this->em->getRepository(OrdreProduction::class)->find($request->request->getInt('ordre'));
        $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('jour'));
        $machine = $request->request->getInt('machine') > 0 ? $this->machinesRepository->find($request->request->getInt('machine')) : null;
        $semaine = $jour ? $jour->modify('monday this week')->format('Y-m-d') : null;
        if (!$ordre instanceof OrdreProduction || !$ordre->estADemarrer() || false === $jour) {
            $this->addFlash('error', 'Seul un travail pas encore commencé se déplace.');

            return $this->redirectToRoute('app_atelier_planning', ['semaine' => $semaine]);
        }
        if ($machine instanceof Machines && $machine !== $ordre->getMachine()) {
            $ordre->setMachine($machine);
        }
        $maintenant = new \DateTimeImmutable();
        $debut = AvancementOrdre::ouvert(max($jour->setTime(AvancementOrdre::HEURE_OUVERTURE, 0), $maintenant));
        $duree = AvancementOrdre::dureeEstimee($ordre);
        $ordre->planifier($debut, $duree);
        $this->em->flush();

        $promis = AvancementOrdre::datePromise($ordre);
        $fin = AvancementOrdre::enchainer([$duree], $debut)[0]['fin'];
        $numero = $ordre->getCommandeDetail()?->getCommande()?->getNumero() ?? $ordre->getNumero();
        if (AvancementOrdre::retardMinutes($promis, $fin, $maintenant) > 0) {
            $commande = $ordre->getCommandeDetail()?->getCommande();
            $notifications->notifierRoles(['ROLE_COMMANDE'], sprintf('Atelier : %s passe au %s, après la date promise (%s). Prévenez le client.', $numero, $debut->format('d/m'), $promis?->format('d/m')), null !== $commande ? 'app_commandes_show' : null, null !== $commande ? ['id' => $commande->getId()] : []);
            $this->em->flush();
            $this->addFlash('warning', sprintf('%s déplacé au %s : après la date promise du %s. La caisse est prévenue pour avertir le client.', $numero, $debut->format('d/m'), $promis?->format('d/m')));
        } else {
            $this->addFlash('success', sprintf('%s déplacé au %s %s sur %s.', $numero, ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][(int) $debut->format('N')], $debut->format('d/m'), $ordre->getMachine()?->getNom()));
        }

        return $this->redirectToRoute('app_atelier_planning', ['semaine' => $semaine]);
    }

    // ------------------------------------------------------------------
    // AT04 · Maintenance et incidents
    // ------------------------------------------------------------------

    #[Route('/maintenance', name: 'maintenance', methods: ['GET'])]
    public function maintenance(): Response
    {
        $machines = $this->machines();

        return $this->render('atelier_production/maintenance.html.twig', $this->atelier->maintenance($machines, new \DateTimeImmutable()) + [
            'machines' => $machines,
            'chef' => $this->chef(),
            'frequences' => EntretienMachine::FREQUENCES,
            'etats' => IncidentMachine::ETATS,
            'masquage' => AtelierProduction::masquageDisponible($this->em),
        ]);
    }

    /** « Signaler un incident » (AT04) ou « Signaler un problème » (AT05). */
    #[Route('/incident', name: 'incident_signaler', methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function signaler(Request $request, NotificationService $notifications): Response
    {
        $this->verifierJeton($request);
        $machine = $this->machinesRepository->find($request->request->getInt('machine'));
        $titre = trim((string) $request->request->get('titre'));
        if (!$machine instanceof Machines || !$this->peutAgir($machine, $request)) {
            throw $this->createAccessDeniedException('Signalez un incident sur une de vos machines.');
        }
        if ('' === $titre) {
            $this->addFlash('error', 'Décrivez le problème en quelques mots.');

            return $this->retour($request, $this->generateUrl('app_atelier_maintenance'));
        }
        $arret = $request->request->getBoolean('arret');
        $incident = (new IncidentMachine($machine, $titre, $arret, new \DateTimeImmutable(), $this->moi()))
            ->setDetail((string) $request->request->get('detail'))
            ->setSuite((string) $request->request->get('suite'));
        $ordre = $request->request->getInt('ordre') > 0 ? $this->em->getRepository(OrdreProduction::class)->find($request->request->getInt('ordre')) : null;
        if ($ordre instanceof OrdreProduction && $ordre->getMachine() === $machine) {
            $incident->setOrdre($ordre);
        }
        $this->em->persist($incident);
        $this->em->flush();
        $masques = $arret && $request->request->getBoolean('masquer') && $this->chef() ? $this->atelier->masquerProduits($machine, true, 'Machine en réparation') : 0;
        $notifications->notifierRoles([Droits::ATELIER_CHEF], sprintf('%s %s : %s', $machine->getNom(), $arret ? 'en panne' : 'incident', mb_substr($titre, 0, 120)), 'app_atelier_maintenance', [], $this->moi());
        $this->em->flush();
        $this->addFlash('success', ($arret ? $machine->getNom().' est passée « En panne » : le chef d\'atelier est prévenu.' : 'Incident noté : le chef d\'atelier est prévenu.').($masques > 0 ? sprintf(' %d produit%s masqué%s en ligne.', $masques, $masques > 1 ? 's' : '', $masques > 1 ? 's' : '') : ''));

        return $this->retour($request, $this->generateUrl('app_atelier_maintenance'));
    }

    /** Suite d'un incident : état, prochaine étape, devis (droit Marges et coûts), résolution. */
    #[Route('/incident/{id}', name: 'incident_maj', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function majIncident(IncidentMachine $incident, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerChef();
        $maintenant = new \DateTimeImmutable();
        if ($request->request->getBoolean('resoudre')) {
            $etaitArret = $incident->estArret();
            $incident->resoudre($this->moi(), $maintenant, (string) $request->request->get('note'));
            // L'arrêt entre dans l'historique de la machine (temps d'arrêt du mois, amortissement).
            if ($etaitArret) {
                $this->em->persist((new Maintenance())->setMachine($incident->getMachine())->setDate(\DateTime::createFromImmutable($incident->getSignaleLe()))
                    ->setType('Panne')->setDescription(mb_substr($incident->getTitre(), 0, 255))->setCout((string) ($incident->getDevis() ?? 0))
                    ->setTechnicien(mb_substr((string) (AtelierProduction::prenom($this->moi())), 0, 255))
                    ->setDureeArretMinutes(intdiv($maintenant->getTimestamp() - $incident->getSignaleLe()->getTimestamp(), 60)));
            }
            $this->em->flush();
            $remis = $this->atelier->masquerProduits($incident->getMachine(), false, 'Machine en réparation');
            $this->em->flush();
            $this->addFlash('success', 'Incident résolu : '.$incident->getMachine()->getNom().' reprend le travail.'.($remis > 0 ? sprintf(' %d produit%s de nouveau en ligne.', $remis, $remis > 1 ? 's' : '') : ''));
        } else {
            $etat = (string) $request->request->get('etat');
            if (isset(IncidentMachine::ETATS[$etat]) && IncidentMachine::RESOLU !== $etat) {
                $incident->changerEtat($etat);
            }
            $incident->setSuite((string) $request->request->get('suite'));
            if ($this->isGranted('ROLE_COUT_MARGE') && $request->request->has('devis')) {
                $devis = (int) preg_replace('/\D+/', '', (string) $request->request->get('devis'));
                $incident->setDevis($devis > 0 ? $devis : null);
            }
            $this->em->flush();
            $this->addFlash('success', 'Incident mis à jour.');
        }

        return $this->redirectToRoute('app_atelier_maintenance');
    }

    #[Route('/entretien', name: 'entretien_ajouter', methods: ['POST'])]
    public function ajouterEntretien(Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerChef();
        $machine = $this->machinesRepository->find($request->request->getInt('machine'));
        $tache = trim((string) $request->request->get('tache'));
        $frequence = (string) $request->request->get('frequence');
        if (!$machine instanceof Machines || '' === $tache || !isset(EntretienMachine::FREQUENCES[$frequence])) {
            $this->addFlash('error', 'Choisissez la machine, la tâche et la fréquence.');
        } else {
            $this->em->persist(new EntretienMachine($machine, $tache, $frequence, max(1, $request->request->getInt('intervalle', 1))));
            $this->em->flush();
            $this->addFlash('success', 'Entretien « '.$tache.' » ajouté pour '.$machine->getNom().'.');
        }

        return $this->redirectToRoute('app_atelier_maintenance');
    }

    /** Entretien fait : noté sur la tâche et dans l'historique de la machine (intervention préventive). */
    #[Route('/entretien/{id}/fait', name: 'entretien_fait', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function entretienFait(EntretienMachine $entretien, Request $request): Response
    {
        $this->verifierJeton($request);
        if (!$this->peutAgir($entretien->getMachine(), $request)) {
            throw $this->createAccessDeniedException('Seuls les opérateurs de la machine et le chef d\'atelier notent l\'entretien.');
        }
        $maintenant = new \DateTimeImmutable();
        $entretien->noterFait($this->moi(), $maintenant);
        $this->em->persist((new Maintenance())->setMachine($entretien->getMachine())->setDate(\DateTime::createFromImmutable($maintenant))
            ->setType('Préventive')->setDescription(mb_substr($entretien->getTache(), 0, 255))->setCout('0')
            ->setTechnicien(mb_substr((string) AtelierProduction::prenom($this->moi()), 0, 255)));
        $this->em->flush();
        $this->addFlash('success', '« '.$entretien->getTache().' » noté à '.$maintenant->format('H:i').'.');

        return $this->retour($request, $this->generateUrl('app_atelier_maintenance'));
    }

    #[Route('/entretien/{id}/retirer', name: 'entretien_retirer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retirerEntretien(EntretienMachine $entretien, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerChef();
        $entretien->setActif(false);
        $this->em->flush();
        $this->addFlash('success', 'Entretien « '.$entretien->getTache().' » retiré.');

        return $this->redirectToRoute('app_atelier_maintenance');
    }

    #[Route('/machine/{id}/tetes', name: 'tetes', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function tetes(Machines $machine, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->exigerChef();
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('date')) ?: null;
        $this->atelier->atelier($machine)->setTetesPoseesLe($date);
        $this->em->flush();
        $this->addFlash('success', 'Date de pose des têtes de '.$machine->getNom().' enregistrée.');

        return $this->redirectToRoute('app_atelier_maintenance');
    }

    // ------------------------------------------------------------------
    // AT05 · Écran de l'opérateur
    // ------------------------------------------------------------------

    #[Route('/operateur', name: 'operateur', methods: ['GET'])]
    public function operateur(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $machines = $this->machines();
        $id = $request->query->getInt('machine');
        $machine = null;
        foreach ($machines as $m) {
            if ($m->getId() === $id) {
                $machine = $m;
            }
        }
        $machine ??= $this->machinePreferee($machines, $request);
        $donnees = null !== $machine ? $this->atelier->operateurMachine($machine, $maintenant) : ['travail' => null, 'suivant' => null, 'restants' => 0, 'file' => []];
        if (null !== $machine) {
            $request->getSession()->set('atelier_machine', $machine->getId());
        }
        $travail = $donnees['travail'] ?? $donnees['suivant'];

        return $this->render('atelier_production/operateur.html.twig', $donnees + [
            'machine' => $machine,
            'machines' => $machines,
            'fichiers' => null !== $travail ? AtelierProduction::fichiers($travail['ordre']) : [],
            'peutAgir' => null !== $machine && $this->peutAgir($machine, $request),
            'atelierMachine' => null !== $machine ? $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine]) : null,
            'encres' => AtelierMachine::ENCRES,
            'maintenant' => $maintenant,
        ]);
    }

    #[Route('/operateur/{id}/{action}', name: 'operateur_agir', requirements: ['id' => '\d+', 'action' => 'demarrer|pause|reprendre'], methods: ['POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function agir(OrdreProduction $ordre, string $action, Request $request, ActionsOrdre $actions): Response
    {
        $this->verifierJeton($request);
        $this->exigerAction($ordre, $request);
        try {
            match ($action) {
                'demarrer' => $actions->demarrer($ordre, $this->moi()),
                'pause' => $actions->pause($ordre),
                default => $actions->reprendre($ordre, $this->moi()),
            };
            $this->addFlash('success', match ($action) {
                'demarrer' => 'Travail démarré.',
                'pause' => 'Travail en pause : le temps s\'arrête.',
                default => 'Travail repris.',
            });
        } catch (\LogicException|\InvalidArgumentException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_atelier_operateur', ['machine' => $ordre->getMachine()?->getId()]);
    }

    #[Route('/operateur/{id}/terminer', name: 'operateur_terminer', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_PRODUCTION_GERER')]
    public function terminer(OrdreProduction $ordre, Request $request, ActionsOrdre $actions): Response
    {
        $this->exigerAction($ordre, $request);
        $maintenant = new \DateTimeImmutable();
        $suivante = $this->atelier->etapeSuivante($ordre, $maintenant);
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request);
            $imprime = $request->request->getInt('imprime');
            $gache = $request->request->getInt('gache');
            try {
                $actions->terminer($ordre, $this->moi(), $imprime, $gache, (string) $request->request->get('motif') ?: null, (string) $request->request->get('observation'));
                if (null !== $suivante) {
                    if (null === $suivante['ordre']->getTransmisLe()) {
                        $suivante['ordre']->transmettre($maintenant);
                    }
                    $this->em->flush();
                }
                $taux = AvancementOrdre::tauxGache((float) ($imprime - $gache), (float) $gache);
                $this->addFlash('success', sprintf('Travail terminé : %d bon%s, gâche %s.%s', $imprime - $gache, $imprime - $gache > 1 ? 's' : '', null !== $taux ? str_replace('.', ',', (string) $taux).' %' : '0 %', null !== $suivante ? ' Envoyé à '.$suivante['machine']?->getNom().'.' : ''));

                return $this->redirectToRoute('app_atelier_operateur', ['machine' => $ordre->getMachine()?->getId()]);
            } catch (\LogicException|\InvalidArgumentException|\DomainException $e) {
                $this->addFlash('error', $e->getMessage());
            }
        }

        return $this->render('atelier_production/terminer.html.twig', [
            'o' => $ordre,
            'ligne' => $ordre->getCommandeDetail(),
            'suivante' => $suivante,
            'motifs' => ActionsOrdre::MOTIFS_GACHE,
            'avancement' => AvancementOrdre::avancement($ordre, $maintenant),
            'maintenant' => $maintenant,
        ]);
    }

    // ------------------------------------------------------------------
    // Droits
    // ------------------------------------------------------------------

    /** @return list<Machines> */
    private function machines(): array
    {
        return $this->atelier->machinesVisibles($this->moi(), $this->chef());
    }

    private function chef(): bool
    {
        return $this->isGranted(Droits::ATELIER_CHEF);
    }

    private function exigerChef(): void
    {
        if (!$this->chef()) {
            throw $this->createAccessDeniedException('Réservé au chef d\'atelier.');
        }
    }

    /**
     * Agir sur une machine : le chef d'atelier, un opérateur affecté à la
     * machine, ou le poste de la machine (adresse IP reconnue), toujours
     * avec le droit « Statuts de production ».
     */
    private function peutAgir(Machines $machine, Request $request): bool
    {
        if (!$this->isGranted('ROLE_PRODUCTION_GERER')) {
            return false;
        }
        if ($this->chef()) {
            return true;
        }
        $ip = $request->getClientIp();
        if (null !== $ip && $this->machinesRepository->findOneByAdresseIp($ip) === $machine) {
            return true;
        }
        $atelier = $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine]);

        return null !== $atelier && $atelier->getOperateurs()->contains($this->moi());
    }

    private function exigerAction(OrdreProduction $ordre, Request $request): void
    {
        $machine = $ordre->getMachine();
        if (null === $machine || !$this->peutAgir($machine, $request)) {
            throw $this->createAccessDeniedException('Ce travail est sur une machine qui ne vous est pas affectée.');
        }
    }

    /** @param list<Machines> $machines */
    private function machinePreferee(array $machines, Request $request): ?Machines
    {
        $id = (int) $request->getSession()->get('atelier_machine', 0);
        foreach ($machines as $m) {
            if ($m->getId() === $id) {
                return $m;
            }
        }

        return $machines[0] ?? null;
    }

    /** @return list<User> */
    private function personnelProduction(): array
    {
        $tous = $this->em->getRepository(User::class)->findBy(['actif' => true], ['username' => 'ASC']);

        return array_values(array_filter($tous, static fn (User $u): bool => \in_array('ROLE_PRODUCTION_GERER', $u->getRoles(), true) && !\in_array('ROLE_ADMIN', $u->getRoles(), true)));
    }

    private function moi(): User
    {
        $moi = $this->getUser();
        \assert($moi instanceof User);

        return $moi;
    }

    private function verifierJeton(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Jeton de sécurité invalide.');
        }
    }

    /** Retour à la page d'où vient le formulaire, si elle est de l'atelier. */
    private function retour(Request $request, string $defaut): RedirectResponse
    {
        $retour = (string) $request->request->get('retour');

        return $this->redirect(str_starts_with($retour, '/atelier/') && !str_contains($retour, '//') ? $retour : $defaut);
    }
}
