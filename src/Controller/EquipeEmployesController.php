<?php

namespace App\Controller;

use App\Entity\BulletinPaie;
use App\Entity\DemandeEmploye;
use App\Entity\Employes;
use App\Entity\JournalActivite;
use App\Entity\Pointage;
use App\Entity\RoleUtilisateur;
use App\Entity\User;
use App\Repository\BulletinPaieRepository;
use App\Repository\RoleUtilisateurRepository;
use App\Repository\UserRepository;
use App\Security\Droits;
use App\Service\EspaceEmploye\EspaceEmploye;
use App\Service\EspaceEmploye\Paie;
use App\Service\Equipe\AccesApplication;
use App\Service\Pointage\Pointages;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Réglages · Équipe · Employés (maquettes E01, E02, E03).
 *
 * Chaque employé a un dossier, sa présence et sa paie, qu'il utilise
 * l'application ou non. Le dossier est tenu par la RH (ROLE_RH) ; l'accès
 * à l'application (rôle, invitation) est donné par un administrateur.
 */
#[Route('/equipe/employes', name: 'app_equipe_employes_')]
#[IsGranted('ROLE_RH')]
final class EquipeEmployesController extends AbstractController
{
    private const FILTRES = ['tous' => 'Tous', 'avec' => 'Avec accès', 'sans' => 'Sans accès', 'invitation' => 'Invitation'];

    /** Ce que l'employé verra selon son rôle (maquette E02). */
    public const VUES = [
        'ROLE_PREPRESSE' => 'Mes tâches PAO',
        'ROLE_PRODUCTION_GERER' => 'Mes machines',
        'ROLE_LIVRAISON' => 'Ma tournée',
        'ROLE_ENTRETIEN' => 'Entretien',
        'ROLE_PAIEMENT_ENCAISSER' => 'Caisse',
        'ROLE_CLIENT' => 'Clients',
        'ROLE_TRESORERIE_VOIR' => 'Finances',
        'ROLE_STATS_GLOBAL' => 'Statistiques',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserRepository $users,
        private readonly RoleUtilisateurRepository $roles,
        private readonly AccesApplication $acces,
        private readonly Pointages $pointages,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $maintenant = new \DateTimeImmutable();
        $tous = $this->fiches($maintenant);

        $filtre = \array_key_exists($request->query->getString('filtre'), self::FILTRES) ? $request->query->getString('filtre') : 'tous';
        $contrat = $request->query->getString('contrat');
        $poste = $request->query->getString('poste');
        $recherche = mb_strtolower(trim($request->query->getString('q')));

        $nombres = ['tous' => \count($tous), 'avec' => 0, 'sans' => 0, 'invitation' => 0];
        $contrats = [];
        $telephones = 0;
        $invitations = [];
        foreach ($tous as $f) {
            $nombres[$f['groupe']] = ($nombres[$f['groupe']] ?? 0) + 1;
            $c = $f['employe']->getContrat() ?? 'autre';
            $contrats[$c] = ($contrats[$c] ?? 0) + 1;
            $telephones += $f['appareil'] !== null ? 1 : 0;
            if ($f['groupe'] === 'invitation') {
                $invitations[] = $f;
            }
        }

        $liste = array_values(array_filter($tous, static function (array $f) use ($filtre, $contrat, $poste, $recherche): bool {
            $e = $f['employe'];

            return ('tous' === $filtre || $f['groupe'] === $filtre)
                && ('' === $contrat || $e->getContrat() === $contrat)
                && ('' === $poste || $e->getFonction() === $poste)
                && ('' === $recherche || str_contains(mb_strtolower($e->getNomComplet().' '.$e->getFonction().' '.$e->getTelephone().' '.$f['utilisateur']?->getUsername()), $recherche));
        }));

        if ('csv' === $request->query->getString('format')) {
            return $this->exporter($liste);
        }

        $postes = array_values(array_unique(array_filter(array_map(static fn (array $f): ?string => $f['employe']->getFonction(), $tous))));
        sort($postes);

        return $this->render('equipe_employes/index.html.twig', [
            'fiches' => $liste,
            'filtre' => $filtre,
            'filtres' => self::FILTRES,
            'nombres' => $nombres,
            'contrats' => $contrats,
            'telephones' => $telephones,
            'invitations' => $invitations,
            'postes' => $postes,
            'poste' => $poste,
            'contrat' => $contrat,
            'recherche' => $request->query->getString('q'),
            'maintenant' => $maintenant,
        ]);
    }

    #[Route('/nouveau', name: 'nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request, UserPasswordHasherInterface $hasher): Response
    {
        return $this->formulaire(new Employes(), $request, $hasher);
    }

    #[Route('/{id}/modifier', name: 'modifier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function modifier(Employes $employe, Request $request, UserPasswordHasherInterface $hasher): Response
    {
        return $this->formulaire($employe, $request, $hasher);
    }

    /** Dossier d'un employé (maquette E03). */
    #[Route('/{id}', name: 'dossier', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function dossier(Employes $employe, EspaceEmploye $espace, Paie $paie, BulletinPaieRepository $bulletins): Response
    {
        $maintenant = new \DateTimeImmutable();
        $utilisateur = $employe->getUser();
        $mois = null;
        $presences = [];
        $demandes = [];
        $avances = [];
        $conge = null;
        $arriveeMoyenne = null;

        if ($utilisateur !== null) {
            $mois = $this->pointages->mois($utilisateur, $maintenant, $maintenant);
            $regles = $this->pointages->regles();
            $minutes = [];
            foreach ($mois['jours'] as $j) {
                foreach ($j['presences'] as $p) {
                    $a = $p['pointage']->getArriveeLe();
                    $minutes[] = (int) $a->format('G') * 60 + (int) $a->format('i');
                }
            }
            if ($minutes !== []) {
                $m = (int) round(array_sum($minutes) / \count($minutes));
                $arriveeMoyenne = \sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
            }
            $presences = array_map(fn (Pointage $p): array => ['pointage' => $p, 'calcul' => $this->pointages->calcul($p, $regles, $maintenant)],
                $this->em->getRepository(Pointage::class)->findBy(['utilisateur' => $utilisateur], ['arriveeLe' => 'DESC'], 10));
            $demandes = $this->em->getRepository(DemandeEmploye::class)->findBy(['utilisateur' => $utilisateur], ['creeLe' => 'DESC'], 8);
            $conge = $espace->conge($utilisateur);
            foreach ($this->em->getRepository(BulletinPaie::class)->findBy(['utilisateur' => $utilisateur], ['mois' => 'DESC'], 3) as $b) {
                foreach ($b->getLignes() as $l) {
                    if ($l['type'] === 'avance') {
                        $avances[] = ['bulletin' => $b, 'ligne' => $l];
                    }
                }
            }
        }

        $journal = $utilisateur === null ? [] : $this->em->getRepository(JournalActivite::class)->findBy(['entite' => 'User', 'entiteId' => $utilisateur->getId()], ['createdAt' => 'DESC'], 5);

        return $this->render('equipe_employes/dossier.html.twig', [
            'employe' => $employe,
            'utilisateur' => $utilisateur,
            'statut' => $utilisateur !== null ? $this->acces->statut($utilisateur, $maintenant) : 'sans',
            'appareil' => $utilisateur !== null ? $this->acces->appareil($utilisateur) : null,
            'mois' => $mois,
            'arriveeMoyenne' => $arriveeMoyenne,
            'presences' => $presences,
            'demandes' => $demandes,
            'avances' => $avances,
            'conge' => $conge,
            'versement' => $paie->dateVersement($maintenant),
            'maintenant' => $maintenant,
            'journal' => $journal,
        ]);
    }

    /** « Saisir une absence » et « Enregistrer une avance » (maquette E03) : la RH saisit à la place de l'employé. */
    #[Route('/{id}/saisir', name: 'saisir', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function saisir(Employes $employe, Request $request, EspaceEmploye $espace, Paie $paie, BulletinPaieRepository $bulletins): Response
    {
        if (!$this->isCsrfTokenValid('dossier-employe-'.$employe->getId(), $request->getPayload()->getString('_token'))) {
            throw $this->createAccessDeniedException('Jeton invalide.');
        }
        $utilisateur = $employe->getUser();
        $moi = $this->getUser();
        if ($utilisateur === null || !$moi instanceof User) {
            $this->addFlash('danger', 'Ce dossier n\'a pas encore de compte : enregistrez la fiche une fois.');

            return $this->redirectToRoute('app_equipe_employes_dossier', ['id' => $employe->getId()]);
        }
        $maintenant = new \DateTimeImmutable();
        $donnees = $request->getPayload();

        if ('avance' === $donnees->getString('quoi')) {
            $montant = (int) preg_replace('/\D+/', '', $donnees->getString('montant'));
            $bulletin = $this->em->getRepository(BulletinPaie::class)->findOneBy(['utilisateur' => $utilisateur, 'mois' => BulletinPaie::premierDuMois($maintenant)]);
            if ($bulletin === null) {
                $salaire = (int) $employe->getSalaires();
                if ($salaire <= 0) {
                    $this->addFlash('danger', 'Indiquez d\'abord le salaire sur la fiche : l\'avance est retenue sur le bulletin du mois.');

                    return $this->redirectToRoute('app_equipe_employes_dossier', ['id' => $employe->getId()]);
                }
                $bulletin = new BulletinPaie($utilisateur, $maintenant, $salaire, $maintenant);
                $this->em->persist($bulletin);
            }
            $erreur = $paie->ajouterLigne($bulletin, 'avance', 'Avance du '.$maintenant->format('d/m').($donnees->getString('motif') !== '' ? ' · '.$donnees->getString('motif') : ''), $montant, $moi);
            if ($erreur !== null) {
                $this->addFlash('danger', $erreur);
            } else {
                $this->em->flush();
                $this->addFlash('success', \sprintf('Avance de %s F notée sur le bulletin de %s. La sortie d\'argent se saisit en Trésorerie.', number_format($montant, 0, ',', ' '), $maintenant->format('m/Y')));
            }

            return $this->redirectToRoute('app_equipe_employes_dossier', ['id' => $employe->getId()]);
        }

        $type = 'conge' === $donnees->getString('quoi') ? DemandeEmploye::CONGE : DemandeEmploye::ABSENCE;
        $demande = $espace->deposer($utilisateur, $type, [
            'du' => $donnees->getString('du'),
            'au' => $donnees->getString('au'),
            'motif' => $donnees->getString('motif'),
            'demi' => $donnees->has('demi'),
        ], null, $maintenant);
        if (\is_string($demande)) {
            $this->addFlash('danger', $demande);
        } else {
            // Une absence saisie par la RH est acceptée d'emblée ; un congé suit la validation habituelle.
            if ($type === DemandeEmploye::ABSENCE) {
                $espace->decider($demande, true, null, 'Saisie par la RH', $moi, $maintenant);
            }
            $this->addFlash('success', $demande->libelleType().' enregistré'.($type === DemandeEmploye::ABSENCE ? 'e' : ', à valider dans Demandes').'.');
        }

        return $this->redirectToRoute('app_equipe_employes_dossier', ['id' => $employe->getId()]);
    }

    /**
     * Nouvel employé et accès à l'application (maquette E02).
     */
    private function formulaire(Employes $employe, Request $request, UserPasswordHasherInterface $hasher): Response
    {
        $admin = $this->isGranted('ROLE_ADMIN');
        $utilisateur = $employe->getUser();
        $maintenant = new \DateTimeImmutable();
        $roles = array_values(array_filter($this->roles->dansLOrdre(), static fn (RoleUtilisateur $r): bool => !$r->estAdmin()));
        $statut = $utilisateur !== null ? $this->acces->statut($utilisateur, $maintenant) : 'sans';
        $avecAcces = \in_array($statut, ['actif', 'invite', 'expire', 'suspendu'], true);

        $saisie = [
            'prenom' => $employe->getPrenom() ?? '',
            'nom' => $employe->getNom() ?? '',
            'telephone' => $employe->getTelephone() ?? '',
            'naissance' => $employe->getDateNaissances()?->format('Y-m-d') ?? '',
            'adresse' => $employe->getAdresses() ?? '',
            'urgence' => $employe->getContactUrgence() ?? '',
            'poste' => $employe->getFonction() ?? '',
            'contrat' => $employe->getContrat() ?? '',
            'debut' => $employe->getDateEmbauches()?->format('Y-m-d') ?? $maintenant->format('Y-m-d'),
            'fin' => $employe->getFinPrevue()?->format('Y-m-d') ?? '',
            'remuneration' => $employe->getTypeRemuneration() ?? 'salaire',
            'montant' => $employe->getSalaires() ? (string) $employe->getSalaires() : '',
            'paiement' => $employe->getModePaiement() ?? '',
            'debut_heure' => $employe->getHeureDebut() ?? '',
            'fin_heure' => $employe->getHeureFin() ?? '',
            'jours' => $employe->listeJoursTravail() ?? [],
            'responsable' => $employe->getResponsable()?->getId() ?? 0,
            'acces' => $employe->getId() === null ? true : $avecAcces,
            'profil' => $utilisateur?->getProfil()?->getId() ?? 0,
            'identifiant' => $utilisateur?->getUsername() ?? '',
        ];
        $erreurs = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('fiche-employe', $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton invalide.');
            }
            $d = $request->getPayload();
            foreach (['prenom', 'nom', 'telephone', 'naissance', 'adresse', 'urgence', 'poste', 'contrat', 'debut', 'fin', 'remuneration', 'montant', 'paiement', 'debut_heure', 'fin_heure', 'identifiant'] as $champ) {
                $saisie[$champ] = trim($d->getString($champ));
            }
            $saisie['jours'] = array_map('intval', $d->all('jours'));
            $saisie['responsable'] = $d->getInt('responsable');
            if ($admin) {
                $saisie['acces'] = $d->has('acces');
                $saisie['profil'] = $d->getInt('profil');
            }

            $date = static fn (string $v): ?\DateTimeImmutable => '' === $v ? null : (\DateTimeImmutable::createFromFormat('!Y-m-d', $v) ?: null);
            if ('' === $saisie['prenom'] && '' === $saisie['nom']) {
                $erreurs['nom'] = 'Le nom est obligatoire.';
            }
            if ('' === $saisie['telephone']) {
                $erreurs['telephone'] = 'Le téléphone est obligatoire.';
            }
            if (!isset(Employes::CONTRATS[$saisie['contrat']])) {
                $erreurs['contrat'] = 'Choisissez le contrat.';
            }
            foreach (['naissance', 'debut', 'fin'] as $champ) {
                if ('' !== $saisie[$champ] && $date($saisie[$champ]) === null) {
                    $erreurs[$champ] = 'Date invalide.';
                }
            }
            if (('' === $saisie['debut_heure']) !== ('' === $saisie['fin_heure'])) {
                $erreurs['horaires'] = 'Indiquez l\'heure d\'arrivée et l\'heure de départ, ou aucune des deux.';
            }
            if ($date($saisie['fin']) !== null && $date($saisie['debut']) !== null && $date($saisie['fin']) < $date($saisie['debut'])) {
                $erreurs['fin'] = 'La fin est avant le début.';
            }
            $profil = $this->roles->find($saisie['profil']);
            if ($admin && $saisie['acces'] && (!$profil instanceof RoleUtilisateur || $profil->estAdmin())) {
                $erreurs['profil'] = 'Choisissez le rôle.';
            }
            if ($admin && $saisie['acces'] && '' !== $saisie['identifiant']) {
                $pris = $this->users->findOneBy(['username' => $saisie['identifiant']]);
                if (!preg_match('/^[a-z0-9._-]{3,40}$/', $saisie['identifiant'])) {
                    $erreurs['identifiant'] = 'Lettres minuscules, chiffres et points, 3 caractères au moins.';
                } elseif ($pris !== null && $pris !== $utilisateur) {
                    $erreurs['identifiant'] = 'Cet identifiant est déjà pris.';
                }
            }

            if ([] === $erreurs) {
                $nouveau = $employe->getId() === null;
                $employe->setPrenom($saisie['prenom'])->setNom($saisie['nom'])->setTelephone($saisie['telephone'])
                    ->setFonction('' !== $saisie['poste'] ? $saisie['poste'] : Employes::CONTRATS[$saisie['contrat']])
                    ->setContrat($saisie['contrat'])->setFinPrevue($date($saisie['fin']))
                    ->setTypeRemuneration($saisie['remuneration'])->setModePaiement($saisie['paiement'] ?: null)
                    ->setHoraires($saisie['debut_heure'] ?: null, $saisie['fin_heure'] ?: null, $saisie['jours'] ?: null)->setContactUrgence($saisie['urgence'])
                    ->setResponsable($saisie['responsable'] > 0 ? $this->users->find($saisie['responsable']) : null);
                $employe->setAdresses($saisie['adresse']);
                if (($n = $date($saisie['naissance'])) !== null) {
                    $employe->setDateNaissances(\DateTime::createFromImmutable($n));
                }
                if (($n = $date($saisie['debut'])) !== null) {
                    $employe->setDateEmbauches(\DateTime::createFromImmutable($n));
                }
                $montant = (int) preg_replace('/\D+/', '', $saisie['montant']);
                if ($montant > 0) {
                    $employe->setSalaires($montant);
                }
                $this->em->persist($employe);

                // Un compte pour chaque employé : pointage, paie et demandes y sont rattachés.
                if ($utilisateur === null) {
                    $utilisateur = (new User())->setUsername($this->identifiantLibre($admin && '' !== $saisie['identifiant'] ? $saisie['identifiant'] : self::identifiant($saisie['prenom'], $saisie['nom'], $saisie['telephone'])))->setEmploye($employe);
                    $utilisateur->definirMotDePasseTemporaire($hasher->hashPassword($utilisateur, bin2hex(random_bytes(16))));
                    $utilisateur->setMustChangePassword(false);
                    $utilisateur->setAcces(User::ACCES_SANS);
                    $employe->setUser($utilisateur);
                    $this->em->persist($utilisateur);
                }
                $utilisateur->setFinAccesLe($date($saisie['fin']));
                if ($admin && $saisie['acces']) {
                    $utilisateur->setProfil($profil);
                    if ('' !== $saisie['identifiant'] && $saisie['identifiant'] !== $utilisateur->getUsername()) {
                        $utilisateur->setUsername($saisie['identifiant']);
                    }
                }
                $this->em->flush();

                $moi = $this->getUser() instanceof User ? $this->getUser() : null;
                if ($admin && $saisie['acces'] && \in_array($utilisateur->getAcces(), [User::ACCES_SANS], true)) {
                    $jeton = $this->acces->inviter($utilisateur, $moi, $maintenant);

                    $request->getSession()->set(AccesController::SESSION_INVITATION.$utilisateur->getId(), $jeton);

                    return $this->redirectToRoute('app_acces_invitation', ['id' => $utilisateur->getId()]);
                }
                if ($admin && !$saisie['acces'] && $utilisateur->getAcces() !== User::ACCES_SANS) {
                    $this->acces->retirer($utilisateur, $moi);
                }

                $this->addFlash('success', \sprintf('%s de %s enregistré%s.', $nouveau ? 'Dossier' : 'Fiche', $employe->getNomComplet(), !$admin && $nouveau ? ' (sans accès : un administrateur peut lui en donner un)' : ''));

                return $this->redirectToRoute('app_equipe_employes_dossier', ['id' => $employe->getId()]);
            }
        }

        $droitsDesRoles = [];
        foreach ($roles as $r) {
            $droitsDesRoles[$r->getId()] = $r->getDroits();
        }

        return $this->render('equipe_employes/form.html.twig', [
            'employe' => $employe,
            'utilisateur' => $utilisateur,
            'statut' => $statut,
            'saisie' => $saisie,
            'erreurs' => $erreurs,
            'roles' => $roles,
            'droitsDesRoles' => $droitsDesRoles,
            'vues' => self::VUES,
            'responsables' => array_filter($this->users->findBy(['actif' => true], ['username' => 'ASC']), static fn (User $u): bool => $u->getEmploye() !== null),
            'admin' => $admin,
        ], new Response(status: [] === $erreurs ? 200 : 422));
    }

    /**
     * Employés en poste, avec leur accès (cartes E01).
     *
     * @return list<array{employe: Employes, utilisateur: ?User, statut: string, groupe: string, appareil: mixed}>
     */
    public function fiches(\DateTimeImmutable $maintenant): array
    {
        $employes = $this->em->getRepository(Employes::class)->createQueryBuilder('e')
            ->leftJoin('e.user', 'u')->addSelect('u')
            ->orderBy('e.prenom', 'ASC')->addOrderBy('e.nom', 'ASC')
            ->getQuery()->getResult();

        $fiches = [];
        foreach ($employes as $e) {
            $u = $e->getUser();
            if ($u !== null && (!$u->isActif() || \in_array('ROLE_ESPACE_CLIENT', $u->getRoles(), true))) {
                continue;
            }
            $statut = $u !== null ? $this->acces->statut($u, $maintenant) : 'sans';
            if ($statut === 'retire') {
                continue;
            }
            $fiches[] = [
                'employe' => $e,
                'utilisateur' => $u,
                'statut' => $statut,
                'groupe' => match ($statut) { 'actif', 'suspendu' => 'avec', 'invite', 'expire' => 'invitation', default => 'sans' },
                'appareil' => $u !== null ? $this->acces->appareil($u) : null,
            ];
        }

        return $fiches;
    }

    /** @param list<array<string, mixed>> $fiches */
    private function exporter(array $fiches): StreamedResponse
    {
        $reponse = new StreamedResponse(static function () use ($fiches): void {
            $f = fopen('php://output', 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, ['Nom', 'Poste', 'Contrat', 'Téléphone', 'Accès', 'Rôle', 'Identifiant', 'Dernière connexion'], ';', '"', '');
            foreach ($fiches as $x) {
                $e = $x['employe'];
                fputcsv($f, [
                    $e->getNomComplet(), $e->getFonction(), $e->libelleContrat(), $e->getTelephone(),
                    AccesApplication::STATUTS[$x['statut']] ?? $x['statut'],
                    $x['utilisateur']?->getProfil()?->getNom(), $x['utilisateur']?->getUsername(),
                    $x['utilisateur']?->getLastLoginAt()?->format('d/m/Y H:i'),
                ], ';', '"', '');
            }
            fclose($f);
        });
        $reponse->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $reponse->headers->set('Content-Disposition', 'attachment; filename="employes-'.date('Y-m-d').'.csv"');

        return $reponse;
    }

    /** Identifiant au format de la maquette : prenom.nom, sans accents. */
    public static function identifiant(string $prenom, string $nom, string $telephone = ''): string
    {
        $simplifier = static fn (string $mot): string => trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $mot))), '-');
        $base = implode('.', array_filter([$simplifier($prenom), $simplifier($nom)]));
        $base = substr($base, 0, 30);

        return \strlen($base) >= 3 ? $base : 'employe'.substr((string) preg_replace('/\D+/', '', $telephone), -4);
    }

    private function identifiantLibre(string $base): string
    {
        $identifiant = $base;
        for ($i = 2; $this->users->findOneBy(['username' => $identifiant]) !== null; ++$i) {
            $identifiant = $base.$i;
        }

        return $identifiant;
    }

    /** Libellés « Ce qu'il verra » pour un ensemble de droits. @return array<string, bool> */
    public static function vues(array $droits): array
    {
        $vues = ['Pointage' => true, 'Mes points' => true, 'Mes congés' => true, 'Mes bulletins' => true];
        foreach (self::VUES as $droit => $libelle) {
            $vues[$libelle] = \in_array($droit, $droits, true);
        }

        return $vues;
    }
}
