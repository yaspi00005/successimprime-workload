<?php

namespace App\Form;

use App\Entity\CompteTresorerie;
use App\Entity\User;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CompteTresorerieType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        /*
         * ============================================================
         * CODE
         * ============================================================
         */

        $builder->add(
            'code',
            TextType::class,
            [
                'label' =>
                    'Code du compte',

                'required' =>
                    true,

                'attr' => [
                    'class' =>
                        'form-control',

                    'placeholder' =>
                        'Ex : CAISSE-MARIAM',
                ],

                'help' =>
                    'Code interne unique du compte.',

                'constraints' => [
                    new Assert\NotBlank(
                        message:
                            'Le code du compte est obligatoire.'
                    ),
                ],
            ]
        );


        /*
         * ============================================================
         * NOM
         * ============================================================
         */

        $builder->add(
            'nom',
            TextType::class,
            [
                'label' =>
                    'Nom du compte',

                'required' =>
                    true,

                'attr' => [
                    'class' =>
                        'form-control',

                    'placeholder' =>
                        'Ex : Caisse Mariam',
                ],

                'constraints' => [
                    new Assert\NotBlank(
                        message:
                            'Le nom du compte est obligatoire.'
                    ),
                ],
            ]
        );


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
                    'Type de compte',

                'choices' =>
                    CompteTresorerie
                        ::getTypesPourFormulaire(),

                'required' =>
                    true,

                'placeholder' =>
                    'Sélectionner le type',

                'attr' => [
                    'class' =>
                        'form-select',

                    'data-compte-type' =>
                        'true',
                ],

                'help' =>
                    'Caisse, Orange Money, Wave ou compte bancaire.',
            ]
        );


        /*
         * ============================================================
         * PORTÉE
         * ============================================================
         *
         * Caisse :
         *
         * - personnelle
         * - administration
         *
         * Orange Money :
         *
         * - partagée automatiquement
         *
         * Wave :
         *
         * - partagée automatiquement
         *
         * Banque :
         *
         * - administration automatiquement
         * ============================================================
         */

        $builder->add(
            'portee',
            ChoiceType::class,
            [
                'label' =>
                    'Portée du compte',

                'choices' => [
                    'Compte personnel' =>
                        CompteTresorerie::PORTEE_PERSONNELLE,

                    'Compte partagé' =>
                        CompteTresorerie::PORTEE_PARTAGEE,

                    'Compte administration' =>
                        CompteTresorerie::PORTEE_ADMIN,
                ],

                'required' =>
                    true,

                'attr' => [
                    'class' =>
                        'form-select',

                    'data-compte-portee' =>
                        'true',
                ],

                'help' =>
                    'La portée détermine qui est autorisé à utiliser ce compte.',
            ]
        );


        /*
         * ============================================================
         * PROPRIÉTAIRE
         * ============================================================
         *
         * Utilisé uniquement pour :
         *
         * Caisse + portée personnelle.
         * ============================================================
         */

        $builder->add(
            'proprietaire',
            EntityType::class,
            [
                'label' =>
                    'Propriétaire du compte',

                'class' =>
                    User::class,

                'required' =>
                    false,

                'placeholder' =>
                    'Sélectionner un utilisateur',

                'query_builder' =>
                    function (
                        EntityRepository $repository
                    ) {
                        return $repository
                            ->createQueryBuilder('u')
                            ->andWhere(
                                'u.actif = :actif'
                            )
                            ->setParameter(
                                'actif',
                                true
                            )
                            ->orderBy(
                                'u.username',
                                'ASC'
                            );
                    },

                'choice_label' =>
    function (
        User $user
    ): string {
        return
            $user->getUsername()
            ?: 'Utilisateur #'
                . $user->getId();
    },

                'attr' => [
                    'class' =>
                        'form-select',

                    'data-compte-proprietaire' =>
                        'true',
                ],

                'help' =>
                    'Obligatoire uniquement pour une caisse personnelle.',
            ]
        );


        /*
         * ============================================================
         * NUMÉRO DE COMPTE
         * ============================================================
         */

        $builder->add(
            'numeroCompte',
            TextType::class,
            [
                'label' =>
                    'Numéro / référence du compte',

                'required' =>
                    false,

                'attr' => [
                    'class' =>
                        'form-control',

                    'placeholder' =>
                        'Numéro Orange Money, Wave ou compte bancaire',
                ],
            ]
        );


        /*
         * ============================================================
         * TITULAIRE
         * ============================================================
         */

        $builder->add(
            'titulaireCompte',
            TextType::class,
            [
                'label' =>
                    'Titulaire du compte',

                'required' =>
                    false,

                'attr' => [
                    'class' =>
                        'form-control',

                    'placeholder' =>
                        'Nom du titulaire',
                ],
            ]
        );


        /*
         * ============================================================
         * NOM DE LA BANQUE
         * ============================================================
         */

        $builder->add(
            'nomBanque',
            TextType::class,
            [
                'label' =>
                    'Nom de la banque',

                'required' =>
                    false,

                'attr' => [
                    'class' =>
                        'form-control',

                    'placeholder' =>
                        'Ex : BDM, Ecobank...',
                ],

                'help' =>
                    'Utilisé uniquement pour un compte bancaire.',
            ]
        );


        /*
         * ============================================================
         * SOLDE INITIAL
         * ============================================================
         */

        $builder->add(
            'soldeInitial',
            IntegerType::class,
            [
                'label' =>
                    'Solde initial',

                'required' =>
                    true,

                'attr' => [
                    'class' =>
                        'form-control',

                    'min' =>
                        0,

                    'step' =>
                        1,

                    'placeholder' =>
                        '0',
                ],

                'help' =>
                    'Montant présent lors de l’ouverture du compte.',

                'constraints' => [
                    new Assert\PositiveOrZero(
                        message:
                            'Le solde initial ne peut pas être négatif.'
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
                    'FCFA (XOF)' =>
                        'XOF',
                ],

                'required' =>
                    true,

                'attr' => [
                    'class' =>
                        'form-select',
                ],
            ]
        );


        /*
         * ============================================================
         * AUTORISER LE DÉCOUVERT
         * ============================================================
         */

        $builder->add(
            'autoriserDecouvert',
            CheckboxType::class,
            [
                'label' =>
                    'Autoriser le découvert',

                'required' =>
                    false,

                'help' =>
                    'Si cette option est désactivée, aucun décaissement ne pourra dépasser le solde disponible.',
            ]
        );


        /*
         * ============================================================
         * ACTIF
         * ============================================================
         */

        $builder->add(
            'actif',
            CheckboxType::class,
            [
                'label' =>
                    'Compte actif',

                'required' =>
                    false,
            ]
        );


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
                    'Description / observation',

                'required' =>
                    false,

                'attr' => [
                    'class' =>
                        'form-control',

                    'rows' =>
                        3,

                    'placeholder' =>
                        'Informations complémentaires sur le compte...',
                ],
            ]
        );


        /*
         * ============================================================
         * NORMALISATION SERVEUR AVANT SOUMISSION
         * ============================================================
         *
         * Très important :
         *
         * même si quelqu'un modifie manuellement le HTML,
         * les règles sont imposées côté serveur.
         * ============================================================
         */

        $builder->addEventListener(
            FormEvents::PRE_SUBMIT,
            function (
                FormEvent $event
            ): void {
                $data =
                    $event->getData();

                if (!is_array($data)) {
                    return;
                }


                $type =
                    strtolower(
                        trim(
                            (string) (
                                $data['type']
                                ?? ''
                            )
                        )
                    );


                /*
                 * ====================================================
                 * ORANGE MONEY
                 * ====================================================
                 *
                 * Toujours partagé.
                 * Aucun propriétaire individuel.
                 * ====================================================
                 */

                if (
                    $type ===
                    CompteTresorerie::TYPE_ORANGE_MONEY
                ) {
                    $data['portee'] =
                        CompteTresorerie::PORTEE_PARTAGEE;

                    $data['proprietaire'] =
                        null;
                }


                /*
                 * ====================================================
                 * WAVE
                 * ====================================================
                 */

                if (
                    $type ===
                    CompteTresorerie::TYPE_WAVE
                ) {
                    $data['portee'] =
                        CompteTresorerie::PORTEE_PARTAGEE;

                    $data['proprietaire'] =
                        null;
                }


                /*
                 * ====================================================
                 * BANQUE
                 * ====================================================
                 *
                 * Toujours administration.
                 * ====================================================
                 */

                if (
                    $type ===
                    CompteTresorerie::TYPE_BANQUE
                ) {
                    $data['portee'] =
                        CompteTresorerie::PORTEE_ADMIN;

                    $data['proprietaire'] =
                        null;
                }


                /*
                 * ====================================================
                 * CAISSE
                 * ====================================================
                 *
                 * Une caisse peut être :
                 *
                 * - personnelle
                 * - administration
                 *
                 * On refuse une caisse partagée.
                 * ====================================================
                 */

                if (
                    $type ===
                    CompteTresorerie::TYPE_CAISSE
                ) {
                    $portee =
                        strtolower(
                            trim(
                                (string) (
                                    $data['portee']
                                    ?? ''
                                )
                            )
                        );


                    /*
                     * Sécurité :
                     *
                     * une caisse "partagée"
                     * n'est pas admise dans notre modèle.
                     */

                    if (
                        !in_array(
                            $portee,
                            [
                                CompteTresorerie::PORTEE_PERSONNELLE,
                                CompteTresorerie::PORTEE_ADMIN,
                            ],
                            true
                        )
                    ) {
                        $data['portee'] =
                            CompteTresorerie::PORTEE_ADMIN;

                        $data['proprietaire'] =
                            null;
                    }


                    /*
                     * Caisse Admin :
                     * aucun propriétaire.
                     */

                    if (
                        (
                            $data['portee']
                            ?? null
                        )
                        ===
                        CompteTresorerie::PORTEE_ADMIN
                    ) {
                        $data['proprietaire'] =
                            null;
                    }
                }


                $event->setData(
                    $data
                );
            }
        );
    }


    /*
     * ============================================================
     * OPTIONS
     * ============================================================
     */

    public function configureOptions(
        OptionsResolver $resolver
    ): void {
        $resolver->setDefaults([
            'data_class' =>
                CompteTresorerie::class,
        ]);
    }
}