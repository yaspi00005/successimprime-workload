<?php

namespace App\Controller;

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
