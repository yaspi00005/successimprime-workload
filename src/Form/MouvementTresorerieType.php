<?php

namespace App\Form;

use App\Entity\CompteTresorerie;
use App\Entity\MouvementTresorerie;
use App\Entity\User;
use App\Repository\CompteTresorerieRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class MouvementTresorerieType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        /*
         * ============================================================
         * CONTEXTE
         * ============================================================
         */

        $estAdmin =
            (bool) $options['est_admin'];

        $utilisateur =
            $options['utilisateur'];

        if (
            !$utilisateur instanceof User
        ) {
            throw new \LogicException(
                'L’utilisateur connecté doit être transmis au formulaire de trésorerie.'
            );
        }


        /*
         * ============================================================
         * TYPES DE MOUVEMENTS
         * ============================================================
         */

        $typesMouvements = [
            'Encaissement' =>
                MouvementTresorerie::TYPE_ENCAISSEMENT,

            'Décaissement' =>
                MouvementTresorerie::TYPE_DECAISSEMENT,

            'Transfert' =>
                MouvementTresorerie::TYPE_TRANSFERT,
        ];


        /*
         * ============================================================
         * MODES DE PAIEMENT
         * ============================================================
         */

        $modesCaisse = [
            'Espèces' =>
                'especes',

            'Orange Money' =>
                'orange_money',

            'Wave' =>
                'wave',

            'Virement / dépôt bancaire' =>
                'virement',

            'Chèque' =>
                'cheque',
        ];


        $modesAdmin = [
            'Espèces' =>
                'especes',

            'Orange Money' =>
                'orange_money',

            'Wave' =>
                'wave',

            'Virement bancaire' =>
                'virement',

            'Chèque' =>
                'cheque',

            'Carte bancaire' =>
                'carte_bancaire',

            'Autre' =>
                'autre',
        ];


        /*
         * ============================================================
         * TYPE
         * ============================================================
         */

        $builder->add(
            'type',
            ChoiceType::class,
            [
                'label' =>
                    'Type de mouvement',

                'choices' =>
                    $typesMouvements,

                'placeholder' =>
                    'Sélectionner le type de mouvement',

                'attr' => [
                    'class' =>
                        'form-select',

                    'data-mouvement-type' =>
                        'true',
                ],

                'constraints' => [
                    new Assert\NotBlank(
                        message:
                            'Veuillez sélectionner le type de mouvement.'
                    ),
                ],
            ]
        );


        /*
         * ============================================================
         * CATÉGORIE FINANCIÈRE
         * ============================================================
         */

        $categories =
            MouvementTresorerie
                ::getCategoriesPourFormulaire();


        /*
         * Pour un utilisateur non Admin :
         *
         * - pas de salaire ;
         * - pas d'ajustement technique ;
         * - pas de transfert_interne manuel.
         *
         * Le transfert_interne sera imposé automatiquement
         * par le contrôleur lorsque type = transfert.
         */
        if (!$estAdmin) {
            unset(
                $categories[
                    MouvementTresorerie::CATEGORIES_LABELS[
                        MouvementTresorerie::CATEGORIE_SALAIRE
                    ]
                ],
                
                $categories[
                    MouvementTresorerie::CATEGORIES_LABELS[
                        MouvementTresorerie::CATEGORIE_AJUSTEMENT
                    ]
                ]
            );
        }


        $builder->add(
            'categorie',
            ChoiceType::class,
            [
                'label' =>
                    'Catégorie financière',

                'choices' =>
                    $categories,

                'placeholder' =>
                    'Sélectionner la catégorie',

                'attr' => [
                    'class' =>
                        'form-select',

                    'data-categorie-financiere' =>
                        'true',
                ],

                'help' =>
                    'La catégorie permet de classer automatiquement le mouvement dans le rapport mensuel.',

                'constraints' => [
                    new Assert\NotBlank(
                        message:
                            'Veuillez sélectionner une catégorie financière.'
                    ),
                ],
            ]
        );


        /*
         * ============================================================
         * CONFIDENTIEL
         * ============================================================
         *
         * ADMIN UNIQUEMENT.
         * ============================================================
         */

        if ($estAdmin) {
            $builder->add(
                'confidentiel',
                CheckboxType::class,
                [
                    'label' =>
                        'Mouvement confidentiel',

                    'required' =>
                        false,

                    'help' =>
                        'Si cette case est cochée, le mouvement sera visible uniquement par les administrateurs.',

                    'attr' => [
                        'class' =>
                            'custom-control-input',
                    ],

                    'label_attr' => [
                        'class' =>
                            'custom-control-label',
                    ],
                ]
            );
        }


        /*
         * ============================================================
         * COMPTE SOURCE
         * ============================================================
         *
         * NON ADMIN :
         *
         * source autorisée dans le SELECT :
         *
         * - sa caisse personnelle ;
         * - Orange Money partagé ;
         * - Wave partagé.
         *
         *
         * ADMIN :
         *
         * source :
         *
         * - comptes Admin ;
         * - comptes partagés.
         *
         * IMPORTANT :
         *
         * les caisses personnelles des employés
         * ne sont PAS proposées comme source à l'Admin.
         *
         * L'Admin peut les consulter mais ne peut pas
         * retirer directement leur argent.
         * ============================================================
         */

        $builder->add(
            'compteSource',
            EntityType::class,
            [
                'label' =>
                    'Compte source',

                'class' =>
                    CompteTresorerie::class,

                'query_builder' =>
    static function (
        CompteTresorerieRepository $repository
    ) use (
        $estAdmin,
        $utilisateur
    ): QueryBuilder {
        $qb =
            $repository
                ->createQueryBuilder('compte')
                ->andWhere(
                    'compte.actif = :actif'
                )
                ->setParameter(
                    'actif',
                    true
                )
                ->orderBy(
                    'compte.type',
                    'ASC'
                )
                ->addOrderBy(
                    'compte.nom',
                    'ASC'
                );

        /*
         * ====================================================
         * ADMIN
         * ====================================================
         *
         * - comptes administratifs
         * - comptes partagés
         * - SA PROPRE caisse personnelle (l'Admin est aussi un
         *   agent et peut avoir sa propre caisse)
         *
         * On évite les caisses personnelles des AUTRES agents :
         * même Admin ne peut pas retirer directement l'argent
         * d'une caisse qui ne lui appartient pas.
         */
        if ($estAdmin) {
            return $qb
                ->andWhere(
                    '
                    compte.portee IN (:portees)

                    OR

                    (
                        compte.portee = :personnelle
                        AND
                        compte.proprietaire = :utilisateur
                    )
                    '
                )
                ->setParameter(
                    'portees',
                    [
                        CompteTresorerie::PORTEE_ADMIN,
                        CompteTresorerie::PORTEE_PARTAGEE,
                    ]
                )
                ->setParameter(
                    'personnelle',
                    CompteTresorerie::PORTEE_PERSONNELLE
                )
                ->setParameter(
                    'utilisateur',
                    $utilisateur
                );
        }

        /*
         * ====================================================
         * CAISSIÈRE
         * ====================================================
         *
         * Elle peut recevoir un paiement sur :
         *
         * 1. sa caisse personnelle
         * 2. Orange Money partagé
         * 3. Wave partagé
         * 4. les comptes bancaires de l'entreprise
         *
         * Elle ne voit PAS :
         *
         * - la caisse personnelle d'un autre agent
         * - la caisse Administration
         */
        return $qb
            ->andWhere(
                '
                (
                    compte.portee = :personnelle
                    AND
                    compte.proprietaire = :utilisateur
                )

                OR

                compte.portee = :partagee

                OR

                (
                    compte.portee = :admin
                    AND
                    compte.type = :typeBanque
                )
                '
            )
            ->setParameter(
                'personnelle',
                CompteTresorerie::PORTEE_PERSONNELLE
            )
            ->setParameter(
                'partagee',
                CompteTresorerie::PORTEE_PARTAGEE
            )
            ->setParameter(
                'admin',
                CompteTresorerie::PORTEE_ADMIN
            )
            ->setParameter(
                'typeBanque',
                CompteTresorerie::TYPE_BANQUE
            )
            ->setParameter(
                'utilisateur',
                $utilisateur
            );
    },


                /*
                 * ====================================================
                 * LIBELLÉ SOURCE
                 * ====================================================
                 */

                'choice_label' =>
    static function (
        CompteTresorerie $compte
    ): string {
        /*
         * Banque :
         * on n'affiche pas forcément son solde
         * à la caissière.
         */
        if (
            $compte->getType()
            === CompteTresorerie::TYPE_BANQUE
        ) {
            return sprintf(
                '%s — %s',
                $compte->getNom(),
                $compte->getTypeLabel()
            );
        }

        /*
         * Caisse / Orange Money / Wave :
         * le solde peut être utile.
         */
        return sprintf(
            '%s — %s — %s FCFA',
            $compte->getNom(),
            $compte->getTypeLabel(),
            number_format(
                (int) $compte->getSoldeActuel(),
                0,
                ',',
                ' '
            )
        );
    },


                'placeholder' =>
                    'Sélectionner le compte à débiter',

                'required' =>
                    false,

                'attr' => [
                    'class' =>
                        'form-select',

                    'data-compte-source' =>
                        'true',
                ],

                'row_attr' => [
                    'data-compte-source-row' =>
                        'true',
                ],
            ]
        );


        /*
         * ============================================================
         * COMPTE DESTINATION
         * ============================================================
         *
         * ADMIN :
         *
         * peut voir :
         *
         * - comptes Admin ;
         * - comptes partagés ;
         * - caisses personnelles.
         *
         * Cela permet par exemple :
         *
         * Caisse Administration
         *      ↓
         * Caisse Mariam
         *
         *
         * NON ADMIN :
         *
         * peut voir comme destination :
         *
         * - n'importe quelle caisse personnelle (la sienne ou celle
         *   d'un collègue) ;
         * - les comptes partagés ;
         * - les comptes bancaires Admin.
         *
         * Cela permet :
         *
         * Caisse Mariam
         *      ↓
         * Caisse Ahmed
         *
         * (remise en main propre entre deux agents), ou vers la
         * banque.
         * ============================================================
         */

        $builder->add(
            'compteDestination',
            EntityType::class,
            [
                'label' =>
                    'Compte destination',

                'class' =>
                    CompteTresorerie::class,

                'query_builder' =>
                    static function (
                        CompteTresorerieRepository $repository
                    ) use (
                        $estAdmin,
                        $utilisateur
                    ): QueryBuilder {
                        $qb =
                            $repository
                                ->createQueryBuilder(
                                    'compte'
                                )
                                ->andWhere(
                                    'compte.actif = :actif'
                                )
                                ->setParameter(
                                    'actif',
                                    true
                                )
                                ->orderBy(
                                    'compte.nom',
                                    'ASC'
                                );


                        /*
                         * ============================================
                         * ADMIN
                         * ============================================
                         *
                         * Toutes les destinations actives.
                         *
                         * Il peut ainsi approvisionner
                         * une caisse personnelle.
                         * ============================================
                         */

                        if ($estAdmin) {
                            return $qb;
                        }


                        /*
                         * ============================================
                         * NON ADMIN
                         * ============================================
                         *
                         * Autorisés dans la liste :
                         *
                         * - n'importe quelle caisse personnelle (la
                         *   sienne ou celle d'un collègue -- permet
                         *   une remise en main propre entre deux
                         *   agents, enregistrée comme un transfert) ;
                         * - les comptes partagés (communs) ;
                         * - les comptes bancaires.
                         *
                         * Jamais :
                         * - un compte administratif non bancaire
                         *   (ex : Caisse Administration).
                         * ============================================
                         */

                        return $qb
                            ->andWhere(
                                '
                                compte.portee = :personnelle

                                OR

                                compte.portee = :partagee

                                OR

                                (
                                    compte.portee = :admin
                                    AND
                                    compte.type = :typeBanque
                                )
                                '
                            )
                            ->setParameter(
                                'personnelle',
                                CompteTresorerie
                                    ::PORTEE_PERSONNELLE
                            )
                            ->setParameter(
                                'partagee',
                                CompteTresorerie
                                    ::PORTEE_PARTAGEE
                            )
                            ->setParameter(
                                'admin',
                                CompteTresorerie
                                    ::PORTEE_ADMIN
                            )
                            ->setParameter(
                                'typeBanque',
                                CompteTresorerie
                                    ::TYPE_BANQUE
                            );
                    },


                /*
                 * ====================================================
                 * LIBELLÉ DESTINATION
                 * ====================================================
                 */

                'choice_label' =>
                    static function (
                        CompteTresorerie $compte
                    ) use (
                        $estAdmin,
                        $utilisateur
                    ): string {
                        /*
                         * ============================================
                         * ADMIN
                         * ============================================
                         *
                         * Peut voir les soldes.
                         * ============================================
                         */

                        if ($estAdmin) {
                            return sprintf(
                                '%s — %s — %s FCFA',
                                $compte->getNom(),
                                $compte
                                    ->getPorteeLabel(),
                                number_format(
                                    (int)
                                    $compte
                                        ->getSoldeActuel(),
                                    0,
                                    ',',
                                    ' '
                                )
                            );
                        }


                        /*
                         * ============================================
                         * COMPTE ADMIN
                         * ============================================
                         *
                         * Pour une caissière :
                         *
                         * nom visible pour permettre la remise,
                         * mais aucun solde exposé.
                         * ============================================
                         */

                        if (
                            $compte
                                ->estAdministratif()
                        ) {
                            if (
                                $compte
                                    ->estCompteBancaire()
                            ) {
                                return sprintf(
                                    '%s — Banque',
                                    $compte->getNom()
                                );
                            }


                            return sprintf(
                                '%s — Administration',
                                $compte->getNom()
                            );
                        }


                        /*
                         * ============================================
                         * CAISSE PERSONNELLE D'UN AUTRE AGENT
                         * ============================================
                         *
                         * Permet la remise en main propre entre deux
                         * agents sans exposer le solde de la caisse
                         * d'un collègue.
                         * ============================================
                         */

                        if (
                            $compte->getPortee()
                            === CompteTresorerie::PORTEE_PERSONNELLE
                            &&
                            $compte->getProprietaire()
                            !== $utilisateur
                        ) {
                            return sprintf(
                                '%s — Caisse personnelle',
                                $compte->getNom()
                            );
                        }


                        /*
                         * ============================================
                         * SON PROPRE COMPTE PERSONNEL / PARTAGÉ
                         * ============================================
                         */

                        return sprintf(
                            '%s — %s FCFA',
                            $compte->getNom(),
                            number_format(
                                (int)
                                $compte
                                    ->getSoldeActuel(),
                                0,
                                ',',
                                ' '
                            )
                        );
                    },


                'placeholder' =>
                    'Sélectionner le compte à créditer',

                'required' =>
                    false,

                'attr' => [
                    'class' =>
                        'form-select',

                    'data-compte-destination' =>
                        'true',
                ],

                'row_attr' => [
                    'data-compte-destination-row' =>
                        'true',
                ],
            ]
        );


        /*
         * ============================================================
         * MONTANT
         * ============================================================
         */

        $builder->add(
            'montant',
            TextType::class,
            [
                'label' =>
                    'Montant de l’opération',

                'attr' => [
                    'class' =>
                        'form-control montant-input',

                    'inputmode' =>
                        'numeric',

                    'autocomplete' =>
                        'off',

                    'placeholder' =>
                        'Exemple : 25 000',

                    'data-montant' =>
                        'true',
                ],

                'help' =>
                    'Saisissez uniquement un montant entier en FCFA.',

                'constraints' => [
                    new Assert\NotBlank(
                        message:
                            'Veuillez saisir le montant de l’opération.'
                    ),

                    new Assert\Regex(
                        pattern:
                            '/^[0-9\s]+$/',

                        message:
                            'Le montant doit contenir uniquement des chiffres.'
                    ),
                ],
            ]
        );


        /*
         * ============================================================
         * DEVISE
         * ============================================================
         */

        $builder->add(
            'devise',
            ChoiceType::class,
            [
                'label' =>
                    'Devise',

                'choices' => [
                    'Franc CFA (XOF)' =>
                        'XOF',
                ],

                'attr' => [
                    'class' =>
                        'form-select',
                ],
            ]
        );


        /*
         * ============================================================
         * MODE DE PAIEMENT
         * ============================================================
         */

        $builder->add(
            'modePaiement',
            ChoiceType::class,
            [
                'label' =>
                    'Mode de paiement',

                'placeholder' =>
                    'Sélectionner le mode',

                'required' =>
                    false,

                'choices' =>
                    $estAdmin
                        ? $modesAdmin
                        : $modesCaisse,

                'attr' => [
                    'class' =>
                        'form-select',
                ],
            ]
        );


        /*
         * ============================================================
         * RÉFÉRENCE EXTERNE
         * ============================================================
         */

        $builder->add(
            'referenceExterne',
            TextType::class,
            [
                'label' =>
                    'Référence externe',

                'required' =>
                    false,

                'help' =>
                    'Numéro de transaction, reçu, référence Orange Money, Wave, banque ou autre justificatif.',

                'attr' => [
                    'class' =>
                        'form-control',

                    'maxlength' =>
                        100,

                    'placeholder' =>
                        'Exemple : OM123456 / reçu / référence banque',
                ],
            ]
        );


        /*
         * ============================================================
         * LIBELLÉ
         * ============================================================
         */

        $builder->add(
            'libelle',
            TextType::class,
            [
                'label' =>
                    'Libellé',

                'attr' => [
                    'class' =>
                        'form-control',

                    'maxlength' =>
                        255,

                    'placeholder' =>
                        'Motif principal du mouvement',
                ],

                'constraints' => [
                    new Assert\NotBlank(
                        message:
                            'Veuillez saisir le libellé du mouvement.'
                    ),
                ],
            ]
        );


        /*
         * ============================================================
         * DATE OPÉRATION
         * ============================================================
         *
         * ADMIN UNIQUEMENT.
         *
         * Un utilisateur non-admin ne doit pas pouvoir antidater
         * ou postdater un mouvement : la date est toujours celle
         * de l'enregistrement (imposée côté contrôleur).
         * ============================================================
         */

        if ($estAdmin) {
            $builder->add(
                'dateOperation',
                DateTimeType::class,
                [
                    'label' =>
                        'Date de l’opération',

                    'widget' =>
                        'single_text',

                    'input' =>
                        'datetime_immutable',

                    'help' =>
                        'Réservé à l’administrateur. Pour les autres utilisateurs, la date actuelle est appliquée automatiquement.',

                    'attr' => [
                        'class' =>
                            'form-control',
                    ],
                ]
            );
        }


        /*
         * ============================================================
         * DESCRIPTION
         * ============================================================
         */

        $builder->add(
            'description',
            TextareaType::class,
            [
                'label' =>
                    'Description ou observation',

                'required' =>
                    false,

                'attr' => [
                    'class' =>
                        'form-control',

                    'rows' =>
                        4,

                    'placeholder' =>
                        'Informations complémentaires',
                ],
            ]
        );


        /*
         * ============================================================
         * TRANSFORMER MONTANT
         * ============================================================
         */

        $builder
            ->get('montant')
            ->addModelTransformer(
                new CallbackTransformer(
                    /*
                     * =================================================
                     * PHP / BASE
                     * →
                     * FORMULAIRE
                     * =================================================
                     */

                    static function (
                        mixed $montant
                    ): string {
                        if (
                            $montant === null
                            ||
                            $montant === ''
                        ) {
                            return '';
                        }


                        return number_format(
                            (int)
                            $montant,
                            0,
                            ',',
                            ' '
                        );
                    },


                    /*
                     * =================================================
                     * FORMULAIRE
                     * →
                     * PHP
                     * =================================================
                     */

                    static function (
                        mixed $montant
                    ): ?int {
                        if (
                            $montant === null
                            ||
                            trim(
                                (string)
                                $montant
                            ) === ''
                        ) {
                            return null;
                        }


                        $montantNettoye =
                            preg_replace(
                                '/[^\d]/',
                                '',
                                (string)
                                $montant
                            );


                        if (
                            $montantNettoye === ''
                        ) {
                            return null;
                        }


                        return
                            (int)
                            $montantNettoye;
                    }
                )
            );
    }


    /*
     * ================================================================
     * OPTIONS
     * ================================================================
     */

    public function configureOptions(
        OptionsResolver $resolver
    ): void {
        $resolver->setDefaults([
            'data_class' =>
                MouvementTresorerie::class,

            'est_admin' =>
                false,

            'utilisateur' =>
                null,
        ]);


        $resolver->setAllowedTypes(
            'est_admin',
            'bool'
        );


        $resolver->setAllowedTypes(
            'utilisateur',
            [
                User::class,
                'null',
            ]
        );
    }
}