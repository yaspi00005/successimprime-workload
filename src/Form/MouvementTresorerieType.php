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
         * Sans le droit « Trésorerie et soldes » (ROLE_TRESORERIE_VOIR),
         * aucun solde de compte dans les listes : la caisse saisit sans
         * voir l'argent des comptes.
         */
        $voirSoldes =
            (bool) $options['voir_soldes'];


        /*
         * Comptes Orange Money (ComptesOrangeMoney) : solde, frais à
         * prélever par Orange et part transférable, connus seulement
         * de qui voit les soldes.
         */
        $etatsOrange =
            $voirSoldes
                ? (array) $options['etats_orange']
                : [];

        $transferable =
            static fn (
                CompteTresorerie $compte
            ): ?int => $etatsOrange[(int) $compte->getId()]['transferable'] ?? null;


        /*
         * ============================================================
         * TYPES DE MOUVEMENTS
         * ============================================================
         */

        $peutSaisir =
            (bool) $options['peut_saisir'];

        $peutTransferer =
            (bool) $options['peut_transferer'];

        $typesMouvements =
            self::typesPermis(
                $estAdmin,
                $peutSaisir,
                $peutTransferer
            );


        /*
         * Comptes proposés : seulement ceux que l'enregistrement
         * acceptera (mêmes règles que le contrôleur). Chaque compte
         * porte les types pour lesquels il est permis (data-types) :
         * le formulaire masque les autres selon le type choisi.
         */
        $typesDuCompte =
            static fn (
                mixed $compte,
                bool $commeSource
            ): array => $compte instanceof CompteTresorerie
                ? self::typesPourCompte(
                    $compte,
                    $commeSource,
                    $estAdmin,
                    $peutSaisir,
                    $peutTransferer,
                    $utilisateur
                )
                : [];


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
         * L'argent ne peut sortir que de :
         *
         * 1. sa caisse personnelle
         * 2. un compte partagé (caisse imprimerie, Orange
         *    Money, Wave)
         *
         * Jamais d'un compte administratif (la banque y
         * comprise) ni de la caisse d'un autre agent :
         * l'enregistrement le refuserait.
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
                'utilisateur',
                $utilisateur
            );
    },


                'choice_filter' =>
                    static fn (
                        mixed $compte
                    ): bool => [] !== $typesDuCompte(
                        $compte,
                        true
                    ),


                /*
                 * Orange Money : data-orange fait paraître la ligne des
                 * frais prélevés par Orange ; data-transferable (avec
                 * le droit de voir les soldes) pré-remplit le montant.
                 */
                'choice_attr' =>
                    static fn (
                        mixed $compte
                    ): array => [
                        'data-types' => implode(
                            ' ',
                            $typesDuCompte(
                                $compte,
                                true
                            )
                        ),
                    ] + (
                        $compte instanceof CompteTresorerie && $compte->estOrangeMoney()
                            ? array_filter([
                                'data-orange' => '1',
                                'data-transferable' => $transferable($compte),
                                'data-a-prelever' => $etatsOrange[(int) $compte->getId()]['a_prelever'] ?? null,
                            ], static fn (mixed $v): bool => null !== $v)
                            : []
                    ),


                /*
                 * ====================================================
                 * LIBELLÉ SOURCE
                 * ====================================================
                 */

                'choice_label' =>
                    static fn (
                        CompteTresorerie $compte
                    ): string => self::libelleSource(
                        $compte,
                        $voirSoldes,
                        $transferable($compte)
                    ),


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
                    static fn (
                        CompteTresorerie $compte
                    ): string => self::libelleDestination(
                        $compte,
                        $estAdmin,
                        $voirSoldes,
                        $utilisateur
                    ),


                'choice_filter' =>
                    static fn (
                        mixed $compte
                    ): bool => [] !== $typesDuCompte(
                        $compte,
                        false
                    ),


                'choice_attr' =>
                    static fn (
                        mixed $compte
                    ): array => [
                        'data-types' => implode(
                            ' ',
                            $typesDuCompte(
                                $compte,
                                false
                            )
                        ),
                    ],


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
         * FRAIS PRÉLEVÉS PAR ORANGE
         * ============================================================
         *
         * Quand l'argent sort d'un compte Orange Money (transfert vers
         * la banque ou la caisse, retrait, chèque d'Orange), Orange
         * débite le fonds de soutien et le frais de retrait. Pré-rempli
         * aux taux de Réglages › Paiements, corrigé avec ce qu'Orange a
         * vraiment débité. Non lié à l'entité : le contrôleur en fait
         * un mouvement à part (ComptesOrangeMoney).
         * ============================================================
         */

        $builder->add(
            'fraisOrange',
            TextType::class,
            [
                'label' =>
                    'Fonds de soutien et frais de retrait prélevés par Orange',

                'mapped' =>
                    false,

                'required' =>
                    false,

                'attr' => [
                    'class' =>
                        'form-control montant-input text-right',

                    'inputmode' =>
                        'numeric',

                    'autocomplete' =>
                        'off',

                    'data-frais-orange' =>
                        'true',

                    'data-taux-fonds' =>
                        (float) ($options['taux_orange']['fonds'] ?? 0),

                    'data-taux-retrait' =>
                        (float) ($options['taux_orange']['retrait'] ?? 0),
                ],

                'help' =>
                    'Calculé avec les taux de Réglages › Paiements : corrigez-le avec ce qu’Orange a vraiment débité.',

                'constraints' => [
                    new Assert\Regex(
                        pattern:
                            '/^[0-9\s]*$/',

                        message:
                            'Les frais doivent contenir uniquement des chiffres.'
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

            /*
             * Par défaut, ni solde ni type de mouvement : le
             * contrôleur dit ce que la personne a le droit de voir
             * et de saisir.
             */
            'voir_soldes' =>
                false,

            'peut_saisir' =>
                false,

            'peut_transferer' =>
                false,

            /*
             * Comptes Orange Money : compte => solde, frais à prélever,
             * transférable (vide sans le droit de voir les soldes) ; taux
             * des frais d'Orange pour pré-remplir la ligne des frais.
             */
            'etats_orange' =>
                [],

            'taux_orange' =>
                [
                    'fonds' => 0.0,
                    'retrait' => 0.0,
                ],
        ]);


        $resolver->setAllowedTypes(
            'etats_orange',
            'array'
        );


        $resolver->setAllowedTypes(
            'taux_orange',
            'array'
        );


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


        foreach (['voir_soldes', 'peut_saisir', 'peut_transferer'] as $option) {
            $resolver->setAllowedTypes(
                $option,
                'bool'
            );
        }
    }


    /*
     * ================================================================
     * TYPES PERMIS
     * ================================================================
     *
     * Encaissement et décaissement : droit de saisie
     * (ROLE_TRESORERIE_SAISIR). Transfert : droit de virement
     * (ROLE_TRESORERIE_TRANSFERER). L'administrateur : tout.
     * ================================================================
     */

    /**
     * @return array<string, string> libellé => type
     */
    public static function typesPermis(
        bool $estAdmin,
        bool $peutSaisir,
        bool $peutTransferer
    ): array {
        $types = [];

        if ($estAdmin || $peutSaisir) {
            $types['Encaissement'] =
                MouvementTresorerie::TYPE_ENCAISSEMENT;

            $types['Décaissement'] =
                MouvementTresorerie::TYPE_DECAISSEMENT;
        }

        if ($estAdmin || $peutTransferer) {
            $types['Transfert'] =
                MouvementTresorerie::TYPE_TRANSFERT;
        }

        return $types;
    }


    /*
     * ================================================================
     * COMPTES PERMIS
     * ================================================================
     *
     * Les types de mouvement pour lesquels la personne peut utiliser
     * ce compte, comme source ou comme destination. Mêmes règles que
     * le contrôle à l'enregistrement, qui reste en place
     * (MouvementTresorerieController::verifierMouvementAutorise) :
     *
     * - entrée ou sortie (droit de saisie) : sa caisse personnelle,
     *   un compte partagé ; un compte administratif pour la Direction ;
     * - virement (droit de virement), source : sa caisse personnelle,
     *   un compte partagé ; un compte administratif pour la Direction ;
     * - virement, destination : toute caisse personnelle (remise en
     *   main propre), un compte partagé, la banque ; les autres
     *   comptes administratifs pour la Direction seulement.
     *
     * Jamais la caisse personnelle d'un autre agent comme source,
     * même pour la Direction.
     * ================================================================
     */

    /**
     * @return list<string>
     */
    public static function typesPourCompte(
        CompteTresorerie $compte,
        bool $commeSource,
        bool $estAdmin,
        bool $peutSaisir,
        bool $peutTransferer,
        User $utilisateur
    ): array {
        $sien =
            $compte->appartientA(
                $utilisateur
            );

        $entreeOuSortie =
            ($estAdmin || $peutSaisir)
            && match (true) {
                $compte->estPersonnel() => $sien,
                $compte->estPartage() => true,
                default => $compte->estAdministratif() && $estAdmin,
            };

        $virement =
            ($estAdmin || $peutTransferer)
            && match (true) {
                $compte->estPersonnel() => $commeSource ? $sien : true,
                $compte->estPartage() => true,
                default => $compte->estAdministratif()
                    && ($estAdmin || (!$commeSource && $compte->estCompteBancaire())),
            };

        $types = [];

        if ($entreeOuSortie) {
            $types[] = $commeSource
                ? MouvementTresorerie::TYPE_DECAISSEMENT
                : MouvementTresorerie::TYPE_ENCAISSEMENT;
        }

        if ($virement) {
            $types[] = MouvementTresorerie::TYPE_TRANSFERT;
        }

        return $types;
    }


    /*
     * ================================================================
     * LIBELLÉS DES COMPTES
     * ================================================================
     *
     * Sans le droit de voir la trésorerie : le nom et la sorte de
     * compte, jamais le solde (pas même celui de la caisse).
     * ================================================================
     */

    public static function libelleSource(
        CompteTresorerie $compte,
        bool $voirSoldes,
        ?int $transferable = null
    ): string {
        /*
         * Banque :
         * on n'affiche pas forcément son solde
         * à la caissière.
         */
        if (
            !$voirSoldes
            ||
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
         * le solde peut être utile. Orange Money : aussi la part
         * transférable (sans les frais qu'Orange va débiter).
         */
        return sprintf(
            '%s — %s — %s FCFA%s',
            $compte->getNom(),
            $compte->getTypeLabel(),
            number_format(
                (int) $compte->getSoldeActuel(),
                0,
                ',',
                ' '
            ),
            $transferable === null
                ? ''
                : sprintf(
                    ' (transférable %s)',
                    number_format(
                        $transferable,
                        0,
                        ',',
                        ' '
                    )
                )
        );
    }


    public static function libelleDestination(
        CompteTresorerie $compte,
        bool $estAdmin,
        bool $voirSoldes,
        User $utilisateur
    ): string {
        /*
         * ============================================
         * ADMIN
         * ============================================
         *
         * Peut voir les soldes.
         * ============================================
         */

        if ($estAdmin && $voirSoldes) {
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
         *
         * Le solde seulement avec le droit de voir la
         * trésorerie.
         * ============================================
         */

        if (!$voirSoldes) {
            return sprintf(
                '%s — %s',
                $compte->getNom(),
                $compte->getTypeLabel()
            );
        }

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
    }
}
