<?php

namespace App\Controller;

use App\Entity\Commandes;
use App\Entity\Devis;
use App\Entity\Employes;
use App\Entity\JournalActivite;
use App\Entity\RoleUtilisateur;
use App\Entity\User;
use App\Repository\RoleUtilisateurRepository;
use App\Repository\UserRepository;
use App\Security\Droits;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reglages · Utilisateurs et droits (maquettes U01, U02, U03, U06).
 *
 * Un utilisateur a un role de base ; sur sa fiche on coche ou decoche
 * des droits pour lui seul (ajoutes ou retires). Chaque changement est
 * trace dans le journal d'activite, avec l'avant et l'apres.
 */
#[Route('/reglages/utilisateurs', name: 'app_utilisateurs_')]
#[IsGranted('ROLE_ADMIN')]
final class UtilisateursController extends AbstractController
{
    private const FILTRES = ['tous' => 'Tous', 'actifs' => 'Actifs', 'desactives' => 'Désactivés'];

    public const MOTIFS = ['Fin de contrat', 'Démission', 'Congé long', 'Suspension', 'Autre'];

    /** Devis encore a suivre par leur agent. */
    private const DEVIS_OUVERTS = [Devis::STATUT_BROUILLON, Devis::STATUT_ENVOYE, Devis::STATUT_ACCEPTE];

    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleUtilisateurRepository $roles,
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tous = $this->users->findBy([], ['actif' => 'DESC', 'username' => 'ASC']);
        $filtre = \array_key_exists($request->query->getString('filtre'), self::FILTRES) ? $request->query->getString('filtre') : 'tous';
        $recherche = mb_strtolower(trim($request->query->getString('q')));

        $nombres = [
            'tous' => \count($tous),
            'actifs' => \count(array_filter($tous, static fn (User $u): bool => $u->isActif())),
        ];
        $nombres['desactives'] = $nombres['tous'] - $nombres['actifs'];

        $liste = array_filter($tous, static function (User $u) use ($filtre, $recherche): bool {
            if ('actifs' === $filtre && !$u->isActif() || 'desactives' === $filtre && $u->isActif()) {
                return false;
            }

            return '' === $recherche || str_contains(mb_strtolower(implode(' ', [
                $u->getUsername(),
                self::nomComplet($u),
                $u->getEmploye()?->getTelephone(),
                $u->getProfil()?->getNom(),
            ])), $recherche);
        });

        $aujourdhui = new \DateTimeImmutable('today');
        $roles = $this->roles->dansLOrdre();

        return $this->render('utilisateurs/index.html.twig', [
            'utilisateurs' => $liste,
            'filtre' => $filtre,
            'filtres' => self::FILTRES,
            'nombres' => $nombres,
            'recherche' => $request->query->getString('q'),
            'indicateurs' => [
                'actifs' => $nombres['actifs'],
                'comptes' => $nombres['tous'],
                'connectesAujourdhui' => \count(array_filter($tous, static fn (User $u): bool => $u->isActif() && $u->getLastLoginAt() >= $aujourdhui)),
                'roles' => \count($roles),
                'rolesPersonnalises' => \count(array_filter($roles, static fn (RoleUtilisateur $r): bool => !$r->isSysteme())),
                'droitsPersonnalises' => \count(array_filter($tous, static fn (User $u): bool => $u->isActif() && $u->nombreDroitsPersonnalises() > 0)),
            ],
        ]);
    }

    #[Route('/{id}', name: 'fiche', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function fiche(User $utilisateur, Request $request): Response
    {
        $roles = $this->roles->dansLOrdre();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('droits-utilisateur-' . $utilisateur->getId(), $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton invalide.');
            }

            $profil = $this->roles->find($request->getPayload()->getInt('profil'));

            if (!$profil instanceof RoleUtilisateur) {
                $this->addFlash('danger', 'Choisissez un rôle de base.');

                return $this->redirectToRoute('app_utilisateurs_fiche', ['id' => $utilisateur->getId()]);
            }

            if ($utilisateur === $this->getUser() && !$profil->estAdmin()) {
                $this->addFlash('danger', 'Vous ne pouvez pas retirer votre propre rôle Administrateur.');

                return $this->redirectToRoute('app_utilisateurs_fiche', ['id' => $utilisateur->getId()]);
            }

            $avant = self::photoDroits($utilisateur);
            $utilisateur->setProfil($profil);
            $utilisateur->definirDroits($profil->estAdmin() ? [] : $request->getPayload()->all('droits'));
            $utilisateur->setObjectifVentesMois((int) preg_replace('/\D+/', '', $request->getPayload()->getString('objectif')) ?: null);
            $apres = self::photoDroits($utilisateur);

            if ($avant !== $apres) {
                $this->tracer('User', (int) $utilisateur->getId(), 'droits', $avant, $apres);
            }

            $this->em->flush();
            $this->addFlash('success', sprintf('Droits de %s enregistrés.', self::nomComplet($utilisateur)));

            return $this->redirectToRoute('app_utilisateurs_fiche', ['id' => $utilisateur->getId()]);
        }

        $journal = $this->em->getRepository(JournalActivite::class)->findBy(
            ['entite' => 'User', 'entiteId' => $utilisateur->getId()],
            ['createdAt' => 'DESC'],
            10
        );

        return $this->render('utilisateurs/fiche.html.twig', [
            'utilisateur' => $utilisateur,
            'nom' => self::nomComplet($utilisateur),
            'roles' => $roles,
            'droitsParGroupe' => Droits::parGroupe(),
            'droitsDesRoles' => array_combine(
                array_map(static fn (RoleUtilisateur $r): int => (int) $r->getId(), $roles),
                array_map(static fn (RoleUtilisateur $r): array => $r->getDroits(), $roles)
            ),
            'historique' => $journal,
            'catalogue' => Droits::catalogue(),
        ]);
    }

    #[Route('/roles', name: 'roles', methods: ['GET', 'POST'])]
    public function roles(Request $request): Response
    {
        $roles = $this->roles->dansLOrdre();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('roles-et-droits', $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton invalide.');
            }

            $nouveauNom = trim($request->getPayload()->getString('nouveau_nom'));

            if ('' !== $nouveauNom) {
                return $this->creerRole($nouveauNom, $this->roles->find($request->getPayload()->getInt('copier')), $roles);
            }

            $coches = $request->getPayload()->all('droits');

            foreach ($roles as $role) {
                if ($role->estAdmin()) {
                    continue;
                }

                $avant = $role->getDroits();
                $role->setDroits($coches[$role->getId()] ?? []);

                if ($avant !== $role->getDroits()) {
                    $this->tracer('RoleUtilisateur', (int) $role->getId(), 'droits', ['role' => $role->getNom(), 'droits' => $avant], ['role' => $role->getNom(), 'droits' => $role->getDroits()]);
                }
            }

            $this->em->flush();
            $this->addFlash('success', 'Rôles enregistrés. Les personnes qui les ont reçoivent les nouveaux droits à leur prochaine page.');

            return $this->redirectToRoute('app_utilisateurs_roles');
        }

        return $this->render('utilisateurs/roles.html.twig', [
            'roles' => $roles,
            'personnes' => $this->roles->nombresDePersonnes(),
            'droitsParGroupe' => Droits::parGroupe(),
        ]);
    }

    /**
     * Nouvel utilisateur (maquette U04, partie gauche) : la fiche
     * employe minimale et le compte sont crees ensemble. Le mot de
     * passe temporaire s'affiche une fois, avec un lien WhatsApp.
     */
    #[Route('/nouveau', name: 'nouveau', methods: ['GET', 'POST'])]
    public function nouveau(Request $request, UserPasswordHasherInterface $hasher): Response
    {
        $roles = array_values(array_filter($this->roles->dansLOrdre(), static fn (RoleUtilisateur $r): bool => !$r->estAdmin()));
        $saisie = ['prenom' => '', 'nom' => '', 'telephone' => '', 'email' => '', 'profil' => 0, 'changerMotDePasse' => true];
        $erreurs = [];

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('nouvel-utilisateur', $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton invalide.');
            }

            $donnees = $request->getPayload();
            $saisie = [
                'prenom' => trim($donnees->getString('prenom')),
                'nom' => trim($donnees->getString('nom')),
                'telephone' => trim($donnees->getString('telephone')),
                'email' => trim($donnees->getString('email')),
                'profil' => $donnees->getInt('profil'),
                'changerMotDePasse' => $donnees->has('changer_mot_de_passe'),
            ];

            $profil = $this->roles->find($saisie['profil']);

            if ('' === $saisie['prenom'] && '' === $saisie['nom']) {
                $erreurs['nom'] = 'Le nom est obligatoire.';
            }

            if ('' === $saisie['telephone']) {
                $erreurs['telephone'] = 'Le téléphone est obligatoire.';
            }

            if ('' !== $saisie['email'] && !filter_var($saisie['email'], \FILTER_VALIDATE_EMAIL)) {
                $erreurs['email'] = 'Adresse e-mail invalide.';
            }

            if (!$profil instanceof RoleUtilisateur || $profil->estAdmin()) {
                $erreurs['profil'] = 'Choisissez un rôle.';
            }

            if ([] === $erreurs) {
                $employe = (new Employes())
                    ->setPrenom($saisie['prenom'])
                    ->setNom($saisie['nom'])
                    ->setTelephone($saisie['telephone'])
                    ->setFonction($profil->getNom());

                if ('' !== $saisie['email']) {
                    $employe->setEmail($saisie['email']);
                }

                $motDePasse = self::motDePasseTemporaire();
                $utilisateur = (new User())->setUsername($this->identifiantLibre($saisie['prenom'], $saisie['nom'], $saisie['telephone']))->setEmploye($employe);
                $utilisateur->setProfil($profil);
                $utilisateur->definirMotDePasseTemporaire($hasher->hashPassword($utilisateur, $motDePasse));

                if (!$saisie['changerMotDePasse']) {
                    $utilisateur->setMustChangePassword(false);
                }

                $this->em->persist($employe);
                $this->em->persist($utilisateur);
                $this->em->flush();
                $this->tracer('User', (int) $utilisateur->getId(), 'creation', null, self::photoDroits($utilisateur));
                $this->em->flush();

                return $this->render('utilisateurs/acces_cree.html.twig', [
                    'utilisateur' => $utilisateur,
                    'nom' => self::nomComplet($utilisateur),
                    'motDePasse' => $motDePasse,
                    'whatsapp' => self::numeroWhatsApp($saisie['telephone']),
                    'adresse' => $request->getSchemeAndHttpHost() . $this->generateUrl('app_login'),
                ]);
            }
        }

        return $this->render('utilisateurs/nouveau.html.twig', [
            'roles' => $roles,
            'saisie' => $saisie,
            'erreurs' => $erreurs,
        ], new Response(status: [] === $erreurs ? 200 : 422));
    }

    /**
     * Desactiver (maquette U04, partie droite) : motif, transfert des
     * devis et commandes ouverts a un collegue. Le compte n'est jamais
     * supprime ; la personne est deconnectee a sa prochaine page.
     */
    #[Route('/{id}/desactiver', name: 'desactiver', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function desactiver(User $utilisateur, Request $request): Response
    {
        if ($utilisateur === $this->getUser()) {
            $this->addFlash('danger', 'Vous ne pouvez pas désactiver votre propre compte.');

            return $this->redirectToRoute('app_utilisateurs_fiche', ['id' => $utilisateur->getId()]);
        }

        $devis = array_values(array_filter(
            $this->em->getRepository(Devis::class)->findBy(['agents' => $utilisateur]),
            static fn (Devis $d): bool => \in_array($d->getStatut(), self::DEVIS_OUVERTS, true)
        ));
        $commandes = array_values(array_filter(
            $this->em->getRepository(Commandes::class)->findBy(['agents' => $utilisateur]),
            static fn (Commandes $c): bool => \in_array($c->getStatutTravaux(), ['vide', 'preparation', 'livraison'], true)
        ));
        $collegues = array_values(array_filter(
            $this->users->findBy(['actif' => true], ['username' => 'ASC']),
            static fn (User $u): bool => $u !== $utilisateur && ($u->isAdmin() || \in_array('ROLE_COMMANDE', $u->getRoles(), true))
        ));

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('desactiver-utilisateur-' . $utilisateur->getId(), $request->getPayload()->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton invalide.');
            }

            $motif = $request->getPayload()->getString('motif');
            $precision = trim($request->getPayload()->getString('precision'));
            $motif = trim((\in_array($motif, self::MOTIFS, true) ? $motif : 'Autre') . ('' !== $precision ? ' : ' . $precision : ''));
            $repreneur = $this->users->find($request->getPayload()->getInt('repreneur'));
            $aTransferer = \count($devis) + \count($commandes);

            if ($aTransferer > 0 && !\in_array($repreneur, $collegues, true)) {
                $this->addFlash('danger', 'Choisissez à qui transférer ses devis et commandes ouverts.');

                return $this->redirectToRoute('app_utilisateurs_desactiver', ['id' => $utilisateur->getId()]);
            }

            foreach ([...$devis, ...$commandes] as $dossier) {
                $dossier->setAgents($repreneur);
            }

            $utilisateur->desactiver(mb_substr($motif, 0, 255));
            $this->tracer('User', (int) $utilisateur->getId(), 'desactivation', null, [
                'motif' => $motif,
                'transfert' => $aTransferer > 0 ? sprintf('%d devis et %d commandes à %s', \count($devis), \count($commandes), self::nomComplet($repreneur)) : null,
            ]);
            $this->em->flush();
            $this->addFlash('success', sprintf('Compte de %s désactivé%s.', self::nomComplet($utilisateur), $aTransferer > 0 ? sprintf(', %d dossier%s transféré%s à %s', $aTransferer, $aTransferer > 1 ? 's' : '', $aTransferer > 1 ? 's' : '', self::nomComplet($repreneur)) : ''));

            return $this->redirectToRoute('app_utilisateurs_index');
        }

        return $this->render('utilisateurs/desactiver.html.twig', [
            'utilisateur' => $utilisateur,
            'nom' => self::nomComplet($utilisateur),
            'devis' => $devis,
            'commandes' => $commandes,
            'collegues' => array_combine(
                array_map(static fn (User $u): int => (int) $u->getId(), $collegues),
                array_map(static fn (User $u): string => self::nomComplet($u), $collegues)
            ),
            'motifs' => self::MOTIFS,
        ]);
    }

    /**
     * Identifiant cree tout seul, court : initiale du prenom et nom sans
     * accents, 10 caracteres au plus (mtoure), suivi d'un chiffre s'il
     * est deja pris (mtoure2).
     */
    private function identifiantLibre(string $prenom, string $nom, string $telephone): string
    {
        $base = self::identifiant($prenom, $nom);

        if (\strlen($base) < 3) {
            $base = substr((string) preg_replace('/\D+/', '', $telephone), -8);
        }

        $identifiant = $base;

        for ($i = 2; null !== $this->users->findOneBy(['username' => $identifiant]); ++$i) {
            $identifiant = $base . $i;
        }

        return $identifiant;
    }

    public static function identifiant(string $prenom, string $nom): string
    {
        $simplifier = static fn (string $mot): string => (string) preg_replace('/[^a-z0-9]+/', '', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $mot)));
        $prenom = $simplifier($prenom);
        $nom = $simplifier($nom);

        return substr('' !== $nom ? substr($prenom, 0, 1) . $nom : $prenom, 0, 10);
    }

    private static function motDePasseTemporaire(): string
    {
        $lettres = 'abcdefghjkmnpqrstuvwxyz';
        $chiffres = '23456789';
        $mot = '';

        for ($i = 0; $i < 4; ++$i) {
            $mot .= $lettres[random_int(0, \strlen($lettres) - 1)];
        }

        $mot = ucfirst($mot) . '-';

        for ($i = 0; $i < 4; ++$i) {
            $mot .= $chiffres[random_int(0, \strlen($chiffres) - 1)];
        }

        return $mot . '!';
    }

    /**
     * Numero au format wa.me : chiffres seulement, indicatif du Mali
     * (223) ajoute a un numero local a 8 chiffres.
     */
    public static function numeroWhatsApp(string $telephone): string
    {
        $chiffres = (string) preg_replace('/\D+/', '', $telephone);

        return 8 === \strlen($chiffres) ? '223' . $chiffres : ltrim($chiffres, '0');
    }

    /**
     * @param list<RoleUtilisateur> $roles
     */
    private function creerRole(string $nom, ?RoleUtilisateur $modele, array $roles): Response
    {
        $code = 'ROLE_PERSO_' . strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $nom) ?: $nom), '_'));

        if (null !== $this->roles->findOneBy(['code' => $code])) {
            $this->addFlash('danger', sprintf('Le rôle « %s » existe déjà.', $nom));

            return $this->redirectToRoute('app_utilisateurs_roles');
        }

        $role = (new RoleUtilisateur())
            ->setCode(mb_substr($code, 0, 60))
            ->setNom(mb_substr($nom, 0, 80))
            ->setDroits($modele && !$modele->estAdmin() ? $modele->getDroits() : [])
            ->setOrdre(\count($roles) + 10);

        $this->em->persist($role);
        $this->em->flush();
        $this->tracer('RoleUtilisateur', (int) $role->getId(), 'creation', null, ['role' => $role->getNom(), 'droits' => $role->getDroits()]);
        $this->em->flush();
        $this->addFlash('success', sprintf('Rôle « %s » créé. Cochez ses droits puis enregistrez.', $role->getNom()));

        return $this->redirectToRoute('app_utilisateurs_roles');
    }

    /**
     * @param array<string, mixed>|null $avant
     * @param array<string, mixed>|null $apres
     */
    private function tracer(string $entite, int $id, string $action, ?array $avant, ?array $apres): void
    {
        $auteur = $this->getUser();

        $this->em->persist((new JournalActivite())
            ->setEntite($entite)
            ->setEntiteId($id)
            ->setAction($action)
            ->setDonneesAvant($avant)
            ->setDonneesApres($apres)
            ->setUtilisateur($auteur instanceof User ? $auteur : null));
    }

    /**
     * @return array{role: ?string, ajoutes: list<string>, retires: list<string>}
     */
    private static function photoDroits(User $utilisateur): array
    {
        return [
            'role' => $utilisateur->getProfil()?->getNom(),
            'ajoutes' => $utilisateur->getDroitsAjoutes(),
            'retires' => $utilisateur->getDroitsRetires(),
        ];
    }

    public static function nomComplet(User $utilisateur): string
    {
        $employe = $utilisateur->getEmploye();
        $nom = trim(($employe?->getPrenom() ?? '') . ' ' . ($employe?->getNom() ?? ''));

        return '' !== $nom ? $nom : (string) $utilisateur->getUsername();
    }
}
