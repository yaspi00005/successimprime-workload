<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\EmployesRepository;
use App\Repository\UserRepository;

use Doctrine\ORM\EntityManagerInterface;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

use Symfony\Component\Routing\Attribute\Route;

use Symfony\Component\Security\Http\Attribute\IsGranted;


#[Route('/user')]
final class UserController extends AbstractController
{
    /*
     * ============================================================
     * LISTE DES UTILISATEURS
     * ============================================================
     */

    #[Route(
        '',
        name: 'app_user_index',
        methods: ['GET']
    )]
    #[IsGranted(User::ROLE_ADMIN)]
    public function index(
        UserRepository $userRepository
    ): Response {

        return $this->render(
            'user/index.html.twig',
            [
                'users' =>
                    $userRepository->findBy(
                        [],
                        [
                            'dateAdd' => 'DESC',
                        ]
                    ),

                'rolesDisponibles' =>
                    User::getLibellesRoles(),
            ]
        );
    }


    /*
     * ============================================================
     * CRÉATION AJAX D'UN COMPTE
     * ============================================================
     *
     * Un employé peut exister sans User.
     *
     * Lors de la création :
     *
     * - username unique ;
     * - employé unique ;
     * - au moins un rôle ;
     * - mot de passe temporaire aléatoire ;
     * - changement obligatoire au premier accès.
     * ============================================================
     */

    #[Route(
        '/create/ajax',
        name: 'user_create_ajax',
        methods: ['POST']
    )]
    #[IsGranted(User::ROLE_ADMIN)]
    public function createAjax(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        EmployesRepository $employesRepository,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {

        /*
         * ========================================================
         * CSRF
         * ========================================================
         */
        if (
            !$this->isCsrfTokenValid(
                'create_user',
                (string) $request->request->get(
                    '_token'
                )
            )
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Jeton de sécurité invalide.',
                ],
                Response::HTTP_FORBIDDEN
            );
        }


        /*
         * ========================================================
         * DONNÉES
         * ========================================================
         */

        $username =
            trim(
                mb_strtolower(
                    (string) $request
                        ->request
                        ->get(
                            'username'
                        )
                )
            );


        $employeId =
            $request
                ->request
                ->getInt(
                    'employeId'
                );


        $roles =
            $request
                ->request
                ->all(
                    'roles'
                );


        $actif =
            $request
                ->request
                ->has(
                    'actif'
                );


        /*
         * ========================================================
         * USERNAME
         * ========================================================
         */

        if ($username === '') {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Le nom d’utilisateur est obligatoire.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }


        /*
         * Autorise :
         *
         * prenom.nom
         * yaya
         * yaya_diallo
         * yaya-diallo
         */
        if (
            !preg_match(
                '/^[a-z0-9._-]{3,80}$/',
                $username
            )
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Le nom d’utilisateur doit contenir entre 3 et 80 caractères : lettres sans accent, chiffres, point, tiret ou underscore.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }


        /*
         * ========================================================
         * USERNAME UNIQUE
         * ========================================================
         */

        if (
            $userRepository->findOneBy(
                [
                    'username' => $username,
                ]
            )
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Ce nom d’utilisateur existe déjà.',
                ],
                Response::HTTP_CONFLICT
            );
        }


        /*
         * ========================================================
         * EMPLOYÉ
         * ========================================================
         */

        if ($employeId <= 0) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Veuillez sélectionner un employé.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }


        $employe =
            $employesRepository->find(
                $employeId
            );


        if ($employe === null) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Employé introuvable.',
                ],
                Response::HTTP_NOT_FOUND
            );
        }


        /*
         * Un employé ne peut avoir
         * qu'un seul compte utilisateur.
         */
        if (
            $employe->getUser() !== null
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Cet employé possède déjà un compte utilisateur.',
                ],
                Response::HTTP_CONFLICT
            );
        }


        /*
         * ========================================================
         * RÔLES
         * ========================================================
         */

        if (
            !is_array($roles)
            ||
            count($roles) === 0
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Veuillez attribuer au moins un rôle.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }


        /*
         * Sécurité serveur.
         *
         * Même si quelqu'un modifie manuellement
         * le formulaire HTML, seuls les rôles connus
         * par l'entité User sont conservés.
         */
        $rolesValides =
            array_values(
                array_unique(
                    array_intersect(
                        $roles,
                        User::getRolesAutorises()
                    )
                )
            );


        if (
            count($rolesValides) === 0
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Aucun rôle valide n’a été sélectionné.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }


        /*
         * ========================================================
         * CRÉATION USER
         * ========================================================
         */

        $user =
            new User();


        $user->setUsername(
            $username
        );


        $user->setEmploye(
            $employe
        );


        /*
         * L'entité User refiltre également
         * les rôles.
         *
         * Si ROLE_ADMIN est présent,
         * elle conserve seulement ROLE_ADMIN.
         */
        $user->setRoles(
            $rolesValides
        );


        if ($actif) {

            $user->reactiver();

        } else {

            $user->desactiver(
                'Compte créé désactivé'
            );
        }


        /*
         * ========================================================
         * MOT DE PASSE TEMPORAIRE
         * ========================================================
         */

        $motDePasseTemporaire =
            $this->genererMotDePasseTemporaire();


        $passwordHash =
            $passwordHasher->hashPassword(
                $user,
                $motDePasseTemporaire
            );


        $user->definirMotDePasseTemporaire(
            $passwordHash
        );


        /*
         * ========================================================
         * ENREGISTREMENT
         * ========================================================
         */

        $entityManager->persist(
            $user
        );


        $entityManager->flush();


        /*
         * ========================================================
         * RÉPONSE
         * ========================================================
         *
         * Le mot de passe temporaire est retourné
         * une seule fois dans cette réponse.
         *
         * Il n'est PAS stocké en clair dans la BDD.
         * ========================================================
         */

        return $this->json(
            [
                'success' => true,

                'message' =>
                    'Compte utilisateur créé avec succès.',

                'user' => [
                    'id' =>
                        $user->getId(),

                    'username' =>
                        $user->getUsername(),

                    'roles' =>
                        $user->getRoles(),

                    'actif' =>
                        $user->isActif(),
                ],

                'temporaryPassword' =>
                    $motDePasseTemporaire,

                'mustChangePassword' =>
                    true,
            ],
            Response::HTTP_CREATED
        );
    }


    /*
     * ============================================================
     * INFORMATIONS / RÔLES D'UN UTILISATEUR
     * ============================================================
     */

    #[Route(
        '/{id}/roles',
        name: 'app_user_roles_get',
        methods: ['GET'],
        requirements: [
            'id' => '\d+',
        ]
    )]
    #[IsGranted(User::ROLE_ADMIN)]
    public function getUserRoles(
        User $user
    ): JsonResponse {

        return $this->json(
            [
                'success' => true,

                'user' => [
                    'id' =>
                        $user->getId(),

                    'username' =>
                        $user->getUsername(),

                    'roles' =>
                        $user->getRoles(),

                    'actif' =>
                        $user->isActif(),

                    'mustChangePassword' =>
                        $user->mustChangePassword(),

                    'lastLoginAt' =>
                        $user->getLastLoginAt()
                            ? $user
                                ->getLastLoginAt()
                                ->format(
                                    'd/m/Y H:i'
                                )
                            : null,

                    'disabledAt' =>
                        $user->getDisabledAt()
                            ? $user
                                ->getDisabledAt()
                                ->format(
                                    'd/m/Y H:i'
                                )
                            : null,

                    'motifDesactivation' =>
                        $user
                            ->getMotifDesactivation(),
                ],
            ]
        );
    }


    /*
     * ============================================================
     * MODIFIER LES RÔLES
     * ============================================================
     */

    #[Route(
        '/{id}/roles/update',
        name: 'app_user_roles_update',
        methods: ['POST'],
        requirements: [
            'id' => '\d+',
        ]
    )]
    #[IsGranted(User::ROLE_ADMIN)]
    public function updateUserRoles(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {

        /*
         * ========================================================
         * JSON
         * ========================================================
         */

        $data =
            json_decode(
                $request->getContent(),
                true
            );


        if (!is_array($data)) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Les données reçues sont invalides.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }


        /*
         * ========================================================
         * CSRF
         * ========================================================
         */

        $token =
            $data['_token']
            ?? null;


        if (
            !$this->isCsrfTokenValid(
                'update_user_roles_'
                . $user->getId(),
                $token
            )
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Jeton de sécurité invalide.',
                ],
                Response::HTTP_FORBIDDEN
            );
        }


        /*
         * ========================================================
         * RÔLES
         * ========================================================
         */

        $roles =
            $data['roles']
            ?? [];


        if (
            !is_array($roles)
            ||
            count($roles) === 0
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Veuillez sélectionner au moins un rôle.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }


        $rolesValides =
            array_values(
                array_unique(
                    array_intersect(
                        $roles,
                        User::getRolesAutorises()
                    )
                )
            );


        if (
            count($rolesValides) === 0
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Aucun rôle valide n’a été sélectionné.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }


        /*
         * ========================================================
         * PROTECTION DU PROPRE COMPTE ADMIN
         * ========================================================
         *
         * Un administrateur ne doit pas pouvoir
         * retirer accidentellement son propre rôle ADMIN.
         * ========================================================
         */

        if (
            $this->getUser() === $user
            &&
            $user->isAdmin()
            &&
            !in_array(
                User::ROLE_ADMIN,
                $rolesValides,
                true
            )
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Vous ne pouvez pas retirer votre propre rôle Administrateur.',
                ],
                Response::HTTP_FORBIDDEN
            );
        }


        /*
         * ========================================================
         * STATUT
         * ========================================================
         */

        $actif =
            (bool) (
                $data['actif']
                ?? false
            );


        /*
         * Interdiction de se désactiver soi-même.
         */
        if (
            $this->getUser() === $user
            &&
            !$actif
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Vous ne pouvez pas désactiver votre propre compte.',
                ],
                Response::HTTP_FORBIDDEN
            );
        }


        /*
         * ========================================================
         * APPLICATION
         * ========================================================
         */

        $user->setRoles(
            $rolesValides
        );


        if (
            $actif
            &&
            !$user->isActif()
        ) {

            $user->reactiver();

        } elseif (
            !$actif
            &&
            $user->isActif()
        ) {

            $user->desactiver(
                'Désactivation administrative'
            );
        }


        $user->setDateUpdate(
            new \DateTimeImmutable()
        );


        $entityManager->flush();


        return $this->json(
            [
                'success' => true,

                'message' =>
                    'Le compte utilisateur a été mis à jour avec succès.',

                'user' => [
                    'id' =>
                        $user->getId(),

                    'roles' =>
                        $user->getRoles(),

                    'actif' =>
                        $user->isActif(),
                ],
            ]
        );
    }


    /*
     * ============================================================
     * ACTIVER / DÉSACTIVER
     * ============================================================
     */

    #[Route(
        '/{id}/toggle-status',
        name: 'app_user_toggle_status',
        methods: ['POST'],
        requirements: [
            'id' => '\d+',
        ]
    )]
    #[IsGranted(User::ROLE_ADMIN)]
    public function toggleStatus(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager
    ): JsonResponse {

        $data =
            json_decode(
                $request->getContent(),
                true
            );


        if (!is_array($data)) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Les données reçues sont invalides.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }


        /*
         * ========================================================
         * CSRF
         * ========================================================
         */

        if (
            !$this->isCsrfTokenValid(
                'toggle_user_'
                . $user->getId(),
                $data['_token']
                ?? null
            )
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Jeton de sécurité invalide.',
                ],
                Response::HTTP_FORBIDDEN
            );
        }


        /*
         * ========================================================
         * PROTECTION PROPRE COMPTE
         * ========================================================
         */

        if (
            $this->getUser() === $user
            &&
            $user->isActif()
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Vous ne pouvez pas désactiver votre propre compte.',
                ],
                Response::HTTP_FORBIDDEN
            );
        }


        /*
         * ========================================================
         * ACTIVATION
         * ========================================================
         */

        if (!$user->isActif()) {

            $user->reactiver();


            $entityManager->flush();


            return $this->json(
                [
                    'success' => true,

                    'message' =>
                        'Le compte a été réactivé avec succès.',

                    'actif' => true,
                ]
            );
        }


        /*
         * ========================================================
         * DÉSACTIVATION
         * ========================================================
         */

        $motif =
            trim(
                (string) (
                    $data['motif']
                    ??
                    'Désactivation administrative'
                )
            );


        if ($motif === '') {

            $motif =
                'Désactivation administrative';
        }


        $user->desactiver(
            $motif
        );


        $entityManager->flush();


        return $this->json(
            [
                'success' => true,

                'message' =>
                    'Le compte a été désactivé avec succès.',

                'actif' => false,

                'disabledAt' =>
                    $user
                        ->getDisabledAt()
                        ?->format(
                            'd/m/Y H:i'
                        ),

                'motif' =>
                    $user
                        ->getMotifDesactivation(),
            ]
        );
    }


    /*
     * ============================================================
     * RÉINITIALISATION MOT DE PASSE PAR ADMIN
     * ============================================================
     *
     * Génère un nouveau mot de passe temporaire.
     *
     * Après reset :
     *
     * mustChangePassword = true
     *
     * Le mot de passe temporaire est retourné
     * uniquement dans cette réponse.
     * ============================================================
     */

    #[Route(
        '/{id}/reset-password',
        name: 'app_user_reset_password',
        methods: ['POST'],
        requirements: [
            'id' => '\d+',
        ]
    )]
    #[IsGranted(User::ROLE_ADMIN)]
    public function resetPassword(
        User $user,
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {

        $data =
            json_decode(
                $request->getContent(),
                true
            );


        if (!is_array($data)) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Les données reçues sont invalides.',
                ],
                Response::HTTP_BAD_REQUEST
            );
        }


        /*
         * ========================================================
         * CSRF
         * ========================================================
         */

        if (
            !$this->isCsrfTokenValid(
                'reset_password_'
                . $user->getId(),
                $data['_token']
                ?? null
            )
        ) {

            return $this->json(
                [
                    'success' => false,
                    'message' =>
                        'Jeton de sécurité invalide.',
                ],
                Response::HTTP_FORBIDDEN
            );
        }


        /*
         * ========================================================
         * MOT DE PASSE TEMPORAIRE
         * ========================================================
         */

        $motDePasseTemporaire =
            $this->genererMotDePasseTemporaire();


        $passwordHash =
            $passwordHasher->hashPassword(
                $user,
                $motDePasseTemporaire
            );


        $user->definirMotDePasseTemporaire(
            $passwordHash
        );


        $entityManager->flush();


        return $this->json(
            [
                'success' => true,

                'message' =>
                    'Le mot de passe a été réinitialisé avec succès.',

                'username' =>
                    $user->getUsername(),

                /*
                 * Visible UNE SEULE FOIS.
                 */
                'temporaryPassword' =>
                    $motDePasseTemporaire,

                'mustChangePassword' =>
                    true,
            ]
        );
    }


    /*
     * ============================================================
     * ANCIENNE ROUTE DE SUPPRESSION
     * ============================================================
     *
     * On NE SUPPRIME PLUS physiquement les comptes.
     *
     * Je conserve temporairement le nom de route
     * parce que ton ancien index Twig l'utilise encore.
     *
     * Cela évite de casser immédiatement la page.
     *
     * Le bouton sera supprimé du Twig ensuite.
     * ============================================================
     */

    #[Route(
        '/{id}/delete/ajax',
        name: 'app_user_delete_ajax',
        methods: ['DELETE'],
        requirements: [
            'id' => '\d+',
        ]
    )]
    #[IsGranted(User::ROLE_ADMIN)]
    public function deleteUserAjax(
        User $user,
        Request $request
    ): JsonResponse {

        return $this->json(
            [
                'success' => false,

                'message' =>
                    'La suppression définitive des comptes est désactivée. Désactivez le compte afin de conserver l’historique et la traçabilité.',
            ],
            Response::HTTP_FORBIDDEN
        );
    }


    /*
     * ============================================================
     * CHANGER SON PROPRE MOT DE PASSE
     * ============================================================
     */

    #[Route(
        '/mon-compte/changer-mot-de-passe',
        name: 'app_user_change_password',
        methods: ['GET', 'POST']
    )]
    #[IsGranted(User::ROLE_USER)]
    public function changePassword(
        Request $request,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher
    ): Response {

        /*
         * ========================================================
         * UTILISATEUR CONNECTÉ
         * ========================================================
         */

        $user =
            $this->getUser();


        if (!$user instanceof User) {

            throw $this
                ->createAccessDeniedException(
                    'Vous devez être connecté.'
                );
        }


        /*
         * ========================================================
         * AFFICHAGE GET
         * ========================================================
         */

        if (!$request->isMethod('POST')) {

            return $this->render(
                'security/change_password.html.twig',
                [
                    'user' => $user,
                ]
            );
        }


        /*
         * ========================================================
         * CSRF
         * ========================================================
         */

        if (
            !$this->isCsrfTokenValid(
                'change_password_'
                . $user->getId(),
                (string) $request
                    ->request
                    ->get(
                        '_token'
                    )
            )
        ) {

            $this->addFlash(
                'error',
                'Jeton de sécurité invalide.'
            );


            return $this->redirectToRoute(
                'app_user_change_password'
            );
        }


        /*
         * ========================================================
         * DONNÉES
         * ========================================================
         */

        $currentPassword =
            (string) $request
                ->request
                ->get(
                    'current_password'
                );


        $newPassword =
            (string) $request
                ->request
                ->get(
                    'new_password'
                );


        $confirmPassword =
            (string) $request
                ->request
                ->get(
                    'confirm_password'
                );


        /*
         * ========================================================
         * MOT DE PASSE ACTUEL
         * ========================================================
         */

        if (
            !$passwordHasher->isPasswordValid(
                $user,
                $currentPassword
            )
        ) {

            $this->addFlash(
                'error',
                'Le mot de passe actuel est incorrect.'
            );


            return $this->redirectToRoute(
                'app_user_change_password'
            );
        }


        /*
         * ========================================================
         * CONFIRMATION
         * ========================================================
         */

        if (
            $newPassword
            !==
            $confirmPassword
        ) {

            $this->addFlash(
                'error',
                'La confirmation du mot de passe ne correspond pas.'
            );


            return $this->redirectToRoute(
                'app_user_change_password'
            );
        }


        /*
         * ========================================================
         * POLITIQUE DU MOT DE PASSE
         * ========================================================
         */

        $erreur =
            $this->validerNouveauMotDePasse(
                $newPassword
            );


        if ($erreur !== null) {

            $this->addFlash(
                'error',
                $erreur
            );


            return $this->redirectToRoute(
                'app_user_change_password'
            );
        }


        /*
         * ========================================================
         * ANCIEN = NOUVEAU
         * ========================================================
         */

        if (
            $passwordHasher->isPasswordValid(
                $user,
                $newPassword
            )
        ) {

            $this->addFlash(
                'error',
                'Le nouveau mot de passe doit être différent du mot de passe actuel.'
            );


            return $this->redirectToRoute(
                'app_user_change_password'
            );
        }


        /*
         * ========================================================
         * HASH
         * ========================================================
         */

        $passwordHash =
            $passwordHasher->hashPassword(
                $user,
                $newPassword
            );


        /*
         * Cette méthode effectue automatiquement :
         *
         * password
         * mustChangePassword = false
         * passwordChangedAt = maintenant
         * dateUpdate = maintenant
         */
        $user->definirNouveauMotDePasse(
            $passwordHash
        );


        $entityManager->flush();


        $this->addFlash(
            'success',
            'Votre mot de passe a été modifié avec succès.'
        );


        /*
         * ========================================================
         * REDIRECTION
         * ========================================================
         *
         * IMPORTANT :
         *
         * si ton dashboard n'est pas app_home,
         * remplace cette route par la bonne.
         * ========================================================
         */

        return $this->redirectToRoute(
            'app_home'
        );
    }


    /*
     * ============================================================
     * GÉNÉRATION MOT DE PASSE TEMPORAIRE
     * ============================================================
     *
     * Exemple :
     *
     * A7m@R4x#2Pk9
     *
     * Nous garantissons :
     *
     * - majuscule ;
     * - minuscule ;
     * - chiffre ;
     * - caractère spécial.
     * ============================================================
     */

    private function genererMotDePasseTemporaire(
        int $longueur = 12
    ): string {

        /*
         * Longueur minimale de sécurité.
         */
        $longueur =
            max(
                12,
                $longueur
            );


        $majuscules =
            'ABCDEFGHJKLMNPQRSTUVWXYZ';

        $minuscules =
            'abcdefghijkmnopqrstuvwxyz';

        $chiffres =
            '23456789';

        $speciaux =
            '@#$%!?+-_';


        /*
         * Au moins un caractère
         * de chaque catégorie.
         */
        $password = [

            $majuscules[
                random_int(
                    0,
                    strlen($majuscules) - 1
                )
            ],

            $minuscules[
                random_int(
                    0,
                    strlen($minuscules) - 1
                )
            ],

            $chiffres[
                random_int(
                    0,
                    strlen($chiffres) - 1
                )
            ],

            $speciaux[
                random_int(
                    0,
                    strlen($speciaux) - 1
                )
            ],
        ];


        $tous =
            $majuscules
            . $minuscules
            . $chiffres
            . $speciaux;


        while (
            count($password)
            <
            $longueur
        ) {

            $password[] =
                $tous[
                    random_int(
                        0,
                        strlen($tous) - 1
                    )
                ];
        }


        /*
         * Mélange cryptographiquement
         * avec random_int plutôt qu'un simple shuffle().
         */
        for (
            $i = count($password) - 1;
            $i > 0;
            $i--
        ) {

            $j =
                random_int(
                    0,
                    $i
                );


            [
                $password[$i],
                $password[$j],
            ] = [
                $password[$j],
                $password[$i],
            ];
        }


        return implode(
            '',
            $password
        );
    }


    /*
     * ============================================================
     * VALIDATION NOUVEAU MOT DE PASSE
     * ============================================================
     */

    private function validerNouveauMotDePasse(
        string $password
    ): ?string {

        if (
            mb_strlen(
                $password
            ) < 8
        ) {

            return
                'Le nouveau mot de passe doit contenir au moins 8 caractères.';
        }


        if (
            !preg_match(
                '/[A-Z]/',
                $password
            )
        ) {

            return
                'Le nouveau mot de passe doit contenir au moins une lettre majuscule.';
        }


        if (
            !preg_match(
                '/[a-z]/',
                $password
            )
        ) {

            return
                'Le nouveau mot de passe doit contenir au moins une lettre minuscule.';
        }


        if (
            !preg_match(
                '/[0-9]/',
                $password
            )
        ) {

            return
                'Le nouveau mot de passe doit contenir au moins un chiffre.';
        }


        if (
            !preg_match(
                '/[^A-Za-z0-9]/',
                $password
            )
        ) {

            return
                'Le nouveau mot de passe doit contenir au moins un caractère spécial.';
        }


        return null;
    }
}