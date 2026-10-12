<?php

namespace App\Controller;

use App\Entity\Echeance;
use App\Entity\Entreprise;
use App\Entity\JourFerie;
use App\Entity\RappelWhatsApp;
use App\Entity\Tache;
use App\Entity\User;
use App\Repository\EntrepriseRepository;
use App\Service\Planning\Calendrier;
use App\Service\Planning\Planning;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Planning (docs/planning-taches.md, T01, T02, T04) : calendrier jour,
 * semaine ou mois, tâches du jour, échéances récurrentes et « Mes
 * tâches » sur mobile. Chacun voit ses tâches, la direction voit tout ;
 * les montants demandent le droit trésorerie.
 */
#[Route('/planning', name: 'app_planning_')]
final class PlanningController extends AbstractController
{
    private const CSRF = 'planning';

    public function __construct(
        private readonly Planning $planning,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $moi = $this->moi();
        $maintenant = new \DateTimeImmutable();
        $vue = \in_array($request->query->get('vue'), ['jour', 'semaine', 'mois'], true) ? (string) $request->query->get('vue') : 'mois';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('date')) ?: $maintenant->setTime(0, 0);
        $categories = array_values(array_intersect((array) $request->query->all('cat'), array_keys(Echeance::CATEGORIES)));
        [$du, $au, $precedent, $suivant] = match ($vue) {
            'jour' => [$date, $date, $date->modify('-1 day'), $date->modify('+1 day')],
            'semaine' => [$d = $date->modify('monday this week'), $d->modify('+6 days'), $date->modify('-7 days'), $date->modify('+7 days')],
            default => [$d = $date->modify('first day of this month')->modify('monday this week'), $date->modify('last day of this month')->modify('sunday this week'), $date->modify('first day of previous month'), $date->modify('first day of next month')],
        };
        $taches = $this->planning->entre($du, $au, $moi, $categories);
        $parJour = [];
        foreach ($taches as $t) {
            $parJour[$t->getPrevueLe()->format('Y-m-d')][] = $t;
        }
        // Les tâches décalées sont aussi notées, barrées, à leur date d'origine.
        $decalees = [];
        foreach ($taches as $t) {
            if ($t->estDecalee()) {
                $decalees[$t->getDateOrigine()->format('Y-m-d')][] = $t;
            }
        }
        $jours = [];
        for ($d = $du; $d <= $au; $d = $d->modify('+1 day')) {
            $jours[] = $d;
        }
        $calendrier = $this->planning->calendrier();
        $feries = [];
        foreach ($jours as $d) {
            if (($f = $calendrier->ferie($d)) !== null) {
                $feries[$d->format('Y-m-d')] = $f;
            }
        }

        return $this->render('planning/index.html.twig', [
            'rappelsAEnvoyer' => $this->isGranted('ROLE_ADMIN') ? \count($this->planning->rappelsAEnvoyer($maintenant)) : 0,
            'vue' => $vue,
            'date' => $date,
            'jours' => $jours,
            'parJour' => $parJour,
            'decalees' => $decalees,
            'feries' => $feries,
            'precedent' => $precedent,
            'suivant' => $suivant,
            'categories' => $categories,
            'aujourdhui' => $this->planning->entre($maintenant, $maintenant, $moi),
            'aNePasManquer' => $this->planning->aNePasManquer($moi, $maintenant),
            'retard' => $this->planning->enRetard($moi, $maintenant),
            'voitMontants' => $this->planning->voitMontants(),
            'jeton' => self::CSRF,
        ]);
    }

    /** Mes tâches (T04, mobile) : aujourd'hui, la semaine, en retard. */
    #[Route('/mes-taches', name: 'mes_taches', methods: ['GET'])]
    public function mesTaches(Request $request): Response
    {
        $moi = $this->moi();
        $maintenant = new \DateTimeImmutable();
        $mien = static fn (array $liste): array => array_values(array_filter($liste, static fn (Tache $t): bool => $t->concerne($moi)));
        $onglets = [
            'jour' => $mien($this->planning->entre($maintenant, $maintenant, $moi)),
            'semaine' => $mien($this->planning->entre($maintenant, $maintenant->modify('+6 days'), $moi)),
            'retard' => $mien($this->planning->enRetard($moi, $maintenant)),
        ];
        $onglet = isset($onglets[$request->query->get('onglet')]) ? (string) $request->query->get('onglet') : 'jour';
        $prochain = $mien($this->planning->aNePasManquer($moi, $maintenant, 10))[0] ?? null;

        return $this->render('planning/mes_taches.html.twig', [
            'onglets' => $onglets,
            'onglet' => $onglet,
            'prochain' => $prochain,
            'voitMontants' => $this->planning->voitMontants(),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/tache/{id}', name: 'tache', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function tache(Tache $tache): Response
    {
        $this->voir($tache);
        $e = $tache->getEcheance();

        return $this->render('planning/tache.html.twig', [
            't' => $tache,
            'peutModifier' => $e !== null && $this->planning->peutModifier($e, $this->moi()),
            'peutCocher' => $this->peutCocher($tache),
            'resume' => $e !== null && $e->getFrequence() !== Calendrier::UNE_FOIS ? Calendrier::resume($e->regle()).($e->getHeure() ? ' à '.$e->getHeure() : '') : null,
            'voitMontants' => $this->planning->voitMontants(),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/tache/{id}/{action}', name: 'tache_agir', requirements: ['id' => '\d+', 'action' => 'cocher|etape|note|annuler'], methods: ['POST'])]
    public function agir(Tache $tache, string $action, Request $request): Response
    {
        $this->verifierJeton($request);
        $this->voir($tache);
        if (!$this->peutCocher($tache)) {
            throw $this->createAccessDeniedException('Seuls le responsable, la copie et la direction cochent cette tâche.');
        }
        // Une tâche d'entretien se valide avec la photo, sur son écran.
        if ($action === 'cocher' && $tache->getCategorie() === 'entretien' && !$tache->estFaite()) {
            return $this->redirectToRoute('app_entretien_tache', ['id' => $tache->getId()]);
        }
        match ($action) {
            'cocher' => $this->planning->cocher($tache, $this->moi(), new \DateTimeImmutable()),
            'etape' => $tache->basculerEtape((int) $request->request->get('index', -1)),
            'note' => $tache->setNote((string) $request->request->get('note')),
            'annuler' => $this->planning->direction() ? $tache->annuler() : throw $this->createAccessDeniedException(),
        };
        $this->em->flush();
        $retour = (string) $request->request->get('retour');

        return str_starts_with($retour, '/planning') ? $this->redirect($retour) : $this->redirectToRoute('app_planning_tache', ['id' => $tache->getId()]);
    }

    #[Route('/echeance/nouvelle', name: 'echeance_nouvelle', methods: ['GET', 'POST'])]
    public function nouvelle(Request $request, EntrepriseRepository $entreprises): Response
    {
        $e = new Echeance($this->moi());
        // Une tâche datée à la main garde sa date ; les modèles avancent au jour ouvré.
        $e->setResponsable($this->moi())->setFrequence(Calendrier::UNE_FOIS)->setDecalage(Calendrier::GARDER);
        if (($date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->query->get('date'))) !== false) {
            $e->setDebut($date);
        }
        if (isset(Planning::MODELES[$request->query->get('modele')])) {
            $m = Planning::MODELES[$request->query->get('modele')];
            $e->setDecalage($m['decalage'] ?? Calendrier::AVANT)->setTitre($m['titre'])->setCategorie($m['categorie'])->setFrequence($m['frequence'])->setJourMois($m['jourMois'] ?? null)->setHeure($m['heure'] ?? null)->setHeureFin($m['heureFin'] ?? null)->setFermeture($m['fermeture'] ?? false)->setEtapes($m['etapes']);
            if (isset($m['jourMois'])) {
                $e->setDebut($e->getDebut()->modify('first day of this month'));
            }
        }

        return $this->formulaire($e, $request, $entreprises, true);
    }

    #[Route('/echeance/{id}', name: 'echeance', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function echeance(Echeance $e, Request $request, EntrepriseRepository $entreprises): Response
    {
        if (!$this->planning->peutModifier($e, $this->moi())) {
            throw $this->createAccessDeniedException('Cette échéance est gérée par son responsable ou la direction.');
        }

        return $this->formulaire($e, $request, $entreprises, false);
    }

    #[Route('/echeance/{id}/arreter', name: 'echeance_arreter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function arreter(Echeance $e, Request $request): Response
    {
        $this->verifierJeton($request);
        if (!$this->planning->peutModifier($e, $this->moi())) {
            throw $this->createAccessDeniedException();
        }
        $e->setActif(false);
        $this->planning->generer($e, new \DateTimeImmutable(), true);
        $this->addFlash('success', 'Échéance arrêtée : ses tâches futures non commencées sont retirées.');

        return $this->redirectToRoute('app_planning_index');
    }

    /** Prochaines dates calculées pendant la saisie (colonne de droite de T02). */
    #[Route('/echeance/apercu', name: 'echeance_apercu', methods: ['POST'])]
    public function apercu(Request $request): JsonResponse
    {
        $e = $this->remplir(new Echeance(), $request, false);
        $maintenant = new \DateTimeImmutable('today');
        $du = $e->getFrequence() === Calendrier::UNE_FOIS ? $e->getDebut()->modify('-1 month') : $maintenant;

        return new JsonResponse([
            'resume' => Calendrier::resume($e->regle()),
            'dates' => array_map(static fn (array $o): array => [
                'date' => $o['date']->format('Y-m-d'),
                'origine' => $o['origine']->format('Y-m-d'),
                'raison' => $o['raison'],
            ], \array_slice($this->planning->calendrier()->occurrences($e->regle(), $du, $maintenant->modify(Planning::HORIZON)), 0, 3)),
        ]);
    }

    /**
     * Rappels WhatsApp à envoyer (étape 2) : sans clé Meta, la direction
     * touche le bouton de chaque rappel ; l'heure du toucher est notée.
     */
    #[Route('/rappels', name: 'rappels', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function rappels(): Response
    {
        $maintenant = new \DateTimeImmutable();

        return $this->render('planning/rappels.html.twig', [
            'aEnvoyer' => array_map(static fn (RappelWhatsApp $r): array => ['r' => $r, 'lien' => self::lienWhatsApp($r)], $this->planning->rappelsAEnvoyer($maintenant)),
            'envoyes' => $this->em->getRepository(RappelWhatsApp::class)->createQueryBuilder('r')->where('r.envoyeLe >= :jour')
                ->setParameter('jour', $maintenant->setTime(0, 0))->orderBy('r.envoyeLe', 'DESC')->getQuery()->getResult(),
            'libelles' => array_map(static fn (array $r): string => $r[0], Echeance::RAPPELS),
            'jeton' => self::CSRF,
        ]);
    }

    #[Route('/rappels/{id}/envoye', name: 'rappel_envoye', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function rappelEnvoye(RappelWhatsApp $rappel, Request $request): Response
    {
        $this->verifierJeton($request);
        $rappel->marquerEnvoye($this->moi(), new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    /** Lien WhatsApp avec le message prêt. */
    public static function lienWhatsApp(RappelWhatsApp $r): string
    {
        return 'https://wa.me/'.UtilisateursController::numeroWhatsApp($r->getTelephone()).'?text='.rawurlencode($r->getMessage());
    }

    /** Fêtes mobiles (Ramadan, Tabaski, Maouloud…), saisies par l'admin. */
    #[Route('/feries', name: 'feries', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function feries(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request);
            $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->request->get('jour'));
            $libelle = trim((string) $request->request->get('libelle'));
            if ((int) $request->request->get('supprimer') > 0) {
                $f = $this->em->find(JourFerie::class, (int) $request->request->get('supprimer'));
                if ($f !== null) {
                    $this->em->remove($f);
                }
            } elseif ($jour === false || $libelle === '') {
                $this->addFlash('danger', 'Indiquez la date et le nom de la fête.');
            } elseif ($this->em->getRepository(JourFerie::class)->findOneBy(['jour' => $jour]) === null) {
                $this->em->persist(new JourFerie($jour, $libelle));
            }
            $this->em->flush();
            // Les tâches futures non commencées suivent les nouvelles dates.
            foreach ($this->em->getRepository(Echeance::class)->findBy(['actif' => true]) as $e) {
                $this->planning->generer($e, new \DateTimeImmutable(), true);
            }
            $this->addFlash('success', 'Jours fériés mis à jour ; le planning est recalculé.');

            return $this->redirectToRoute('app_planning_feries');
        }
        $annee = (int) date('Y');
        $fixes = [];
        foreach ([$annee, $annee + 1] as $a) {
            foreach (Calendrier::FETES_FIXES as $md => $libelle) {
                $fixes[] = ['jour' => new \DateTimeImmutable($a.'-'.$md), 'libelle' => $libelle];
            }
        }

        return $this->render('planning/feries.html.twig', [
            'mobiles' => $this->em->getRepository(JourFerie::class)->findBy([], ['jour' => 'ASC']),
            'fixes' => $fixes,
            'jeton' => self::CSRF,
        ]);
    }

    private function formulaire(Echeance $e, Request $request, EntrepriseRepository $entreprises, bool $nouvelle): Response
    {
        if ($request->isMethod('POST')) {
            $this->verifierJeton($request);
            $this->remplir($e, $request, true);
            if ($e->getTitre() === '') {
                $this->addFlash('danger', 'Donnez un titre à la tâche.');
            } else {
                if ($nouvelle) {
                    $this->em->persist($e);
                }
                $this->em->flush();
                $n = $this->planning->generer($e, new \DateTimeImmutable(), !$nouvelle);
                $this->addFlash('success', $e->getFrequence() === Calendrier::UNE_FOIS ? 'Tâche enregistrée.' : \sprintf('Échéance enregistrée : %d tâche%s prévue%2$s sur les 13 prochains mois.', $n, $n > 1 ? 's' : ''));

                return $this->redirectToRoute('app_planning_index', ['date' => $e->getDebut() > new \DateTimeImmutable() ? $e->getDebut()->format('Y-m-d') : null]);
            }
        }
        $historique = [];
        if (!$nouvelle) {
            $historique = $this->em->getRepository(Tache::class)->createQueryBuilder('t')
                ->where('t.echeance = :e')->andWhere('t.statut = :fait')
                ->setParameter('e', $e)->setParameter('fait', Tache::FAIT)
                ->orderBy('t.prevueLe', 'DESC')->setMaxResults(12)->getQuery()->getResult();
        }
        $direction = $this->planning->direction();

        return $this->render('planning/echeance.html.twig', [
            'e' => $e,
            'nouvelle' => $nouvelle,
            'utilisateurs' => $direction ? $this->em->getRepository(User::class)->findBy(['actif' => true]) : [$this->moi()],
            'entreprises' => $entreprises->dansLOrdre(),
            'historique' => $historique,
            'direction' => $direction,
            'voitMontants' => $this->planning->voitMontants(),
            'jeton' => self::CSRF,
        ]);
    }

    private function remplir(Echeance $e, Request $request, bool $droits): Echeance
    {
        $r = $request->request;
        // getInt() refuse les champs vides : on lit les nombres nous-mêmes.
        $entier = static fn (string $cle): int => (int) $r->get($cle);
        $utilisateur = fn (int $id): ?User => $id > 0 ? $this->em->find(User::class, $id) : null;
        $e->setTitre((string) $r->get('titre'))
            ->setCategorie((string) $r->get('categorie'))
            ->setFrequence((string) $r->get('frequence'))
            ->setJourMois($r->get('jourMois') !== null && $r->get('jourMois') !== '' ? $entier('jourMois') : null)
            ->setJoursSemaine((array) $r->all('joursSemaine'))
            ->setHeure((string) $r->get('heure') ?: null)
            ->setHeureFin((string) $r->get('heureFin') ?: null)
            ->setFermeture((bool) $r->get('fermeture'))
            ->setDecalage((string) $r->get('decalage'))
            ->setDebut(\DateTimeImmutable::createFromFormat('!Y-m-d', (string) $r->get('debut')) ?: new \DateTimeImmutable('today'))
            ->setFinLe($r->get('fin') === 'date' ? (\DateTimeImmutable::createFromFormat('!Y-m-d', (string) $r->get('finLe')) ?: null) : null)
            ->setFinNombre($r->get('fin') === 'nombre' ? $entier('finNombre') : null)
            ->setEtapes((array) $r->all('etapes'))
            ->setRappels((array) $r->all('rappels'))
            ->setEntreprise($entier('entreprise') > 0 ? $this->em->find(Entreprise::class, $entier('entreprise')) : null);
        if ($droits) {
            // Hors direction, on ne crée des tâches que pour soi.
            $e->setResponsable($this->planning->direction() ? $utilisateur($entier('responsable')) : $this->moi())
                ->setCopie($this->planning->direction() ? $utilisateur($entier('copie')) : null);
            if ($this->planning->voitMontants()) {
                $e->setMontantEstime(preg_replace('/\D/', '', (string) $r->get('montant')) !== '' ? (int) preg_replace('/\D/', '', (string) $r->get('montant')) : null);
            }
        }

        return $e;
    }

    private function peutCocher(Tache $t): bool
    {
        return $this->planning->direction() || $t->concerne($this->moi());
    }

    private function voir(Tache $t): void
    {
        if (!$this->planning->peutVoir($t, $this->moi())) {
            throw $this->createAccessDeniedException('Cette tâche ne vous concerne pas.');
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
