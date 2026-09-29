<?php

namespace App\Form;

use App\Entity\Paiements;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use App\Entity\CompteTresorerie;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use App\Entity\User;
use App\Repository\CompteTresorerieRepository;
use Doctrine\ORM\QueryBuilder;



class PaiementsType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add(
                'montant',
                TextType::class,
                [
                    'label' => 'Montant du versement',
                    'required' => true,

                    'attr' => [
                        'class' =>
                            'form-control paiement-montant-input',

                        'placeholder' =>
                            'Ex. 25 000',

                        'autocomplete' =>
                            'off',

                        'inputmode' =>
                            'numeric',
                    ],
                ]
            )

            ->add(
                'mode',
                ChoiceType::class,
                [
                    'label' => 'Mode de paiement',

                    'choices' =>
                        Paiements::getModesPourFormulaire(),

                    'placeholder' =>
                        'Sélectionner un mode de paiement',

                    'required' => true,
                ]
            )

                       ->add(
                'reference',
                TextType::class,
                [
                    'label' =>
                        'Référence de paiement',

                    'required' =>
                        false,

                    'attr' => [
                        'class' =>
                            'form-control',

                        'placeholder' =>
                            'Numéro de transaction, chèque…',

                        'maxlength' =>
                            255,

                        'autocomplete' =>
                            'off',
                    ],

                    'help' =>
                        'Facultative pour les paiements en espèces.',
                ]
            )

            /*
             * Frais mobile money : n'ont de sens que pour Orange
             * Money / Wave (affichage conditionnel géré en JS côté
             * template) -- le contrôleur les ignore de toute façon
             * pour tout autre mode, par sécurité.
             */
            ->add(
                'fraisRetraitInclus',
                CheckboxType::class,
                [
                    'label' => 'Frais de retrait à la charge du client',
                    'required' => false,
                    'attr' => [
                        'class' => 'custom-control-input',
                    ],
                ]
            )

            ->add(
                'fondsSoutienInclus',
                CheckboxType::class,
                [
                    'label' => 'Fonds de soutien à la charge du client',
                    'required' => false,
                    'attr' => [
                        'class' => 'custom-control-input',
                    ],
                ]
            );
            $estAdmin = (bool) $options['est_admin'];

$utilisateur =
    $options['utilisateur'];

if (!$utilisateur instanceof User) {
    throw new \LogicException(
        'L’utilisateur connecté doit être transmis au formulaire de paiement.'
    );
}

$builder->add(
    'compteTresorerie',
    EntityType::class,
    [
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
                            'compte.nom',
                            'ASC'
                        );

                /*
                 * ================================================
                 * ADMIN
                 * ================================================
                 *
                 * Paiement client :
                 *
                 * - comptes administratifs
                 * - comptes partagés
                 *
                 * On ne propose pas les caisses personnelles
                 * des employés à l'Admin.
                 * ================================================
                 */

                if ($estAdmin) {
                    return $qb
                        ->andWhere(
                            'compte.portee IN (:portees)'
                        )
                        ->setParameter(
                            'portees',
                            [
                                CompteTresorerie::PORTEE_ADMIN,
                                CompteTresorerie::PORTEE_PARTAGEE,
                            ]
                        );
                }

                /*
                 * ================================================
                 * CAISSIÈRE
                 * ================================================
                 *
                 * - sa caisse personnelle
                 * - Orange Money partagé
                 * - Wave partagé
                 * - les comptes bancaires (pour un chèque ou un
                 *   virement) : un compte bancaire est TOUJOURS un
                 *   compte "Admin" (contrainte métier, voir
                 *   CompteTresorerie), sinon il serait exclu par la
                 *   règle "jamais les comptes Admin" ci-dessous alors
                 *   que la caissière doit pouvoir choisir la banque.
                 *
                 * Jamais la caisse d'un autre agent.
                 * Jamais les autres comptes Admin.
                 * ================================================
                 */

                return $qb
                    ->andWhere(
                        '
                        compte.portee = :partagee
                        OR
                        (
                            compte.portee = :personnelle
                            AND
                            compte.proprietaire = :utilisateur
                        )
                        OR
                        compte.type = :typeBanque
                        '
                    )
                    ->setParameter(
                        'partagee',
                        CompteTresorerie::PORTEE_PARTAGEE
                    )
                    ->setParameter(
                        'personnelle',
                        CompteTresorerie::PORTEE_PERSONNELLE
                    )
                    ->setParameter(
                        'utilisateur',
                        $utilisateur
                    )
                    ->setParameter(
                        'typeBanque',
                        CompteTresorerie::TYPE_BANQUE
                    );
            },

        'choice_label' =>
            static function (
                CompteTresorerie $compte
            ): string {
                /*
                 * Banque : la caissière doit pouvoir choisir la
                 * banque (chèque/virement) sans voir son solde
                 * (même logique que MouvementTresorerieType).
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
                 * Caisse / Orange Money / Wave : on affiche le
                 * solde ici car le paiement va réellement
                 * créditer ce compte.
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

        'label' =>
            'Compte de trésorerie',

        'placeholder' =>
            'Sélectionner le compte recevant le paiement',

        'required' =>
            true,

        'attr' => [
            'class' =>
                'form-control custom-select',
        ],
    ]
);
            ;


        /*
         * ============================================================
         * TRANSFORMATION DU MONTANT
         * ============================================================
         *
         * Exemple :
         *
         * Affichage formulaire :
         * 25 000
         *
         * Valeur envoyée à l'entité :
         * 25000
         */
        $builder
            ->get('montant')
            ->addModelTransformer(
                new CallbackTransformer(

                    /*
                     * =================================================
                     * ENTITÉ -> FORMULAIRE
                     * =================================================
                     */
                    function (
                        mixed $montant
                    ): string {

                        if (
                            $montant === null
                            || (int) $montant === 0
                        ) {
                            return '';
                        }

                        return number_format(
                            (int) $montant,
                            0,
                            ',',
                            ' '
                        );
                    },


                    /*
                     * =================================================
                     * FORMULAIRE -> ENTITÉ
                     * =================================================
                     */
                    function (
                        mixed $montant
                    ): int {

                        if (
                            $montant === null
                            || trim(
                                (string) $montant
                            ) === ''
                        ) {
                            return 0;
                        }


                        /*
                         * Supprime :
                         *
                         * espace normal
                         * espace insécable
                         * espace fine insécable
                         */
                        $montant =
                            str_replace(
                                [
                                    ' ',
                                    "\xc2\xa0",
                                    "\xe2\x80\xaf",
                                ],
                                '',
                                (string) $montant
                            );


                        /*
                         * On ne conserve que les chiffres.
                         */
                        $montant =
                            preg_replace(
                                '/[^\d]/',
                                '',
                                $montant
                            );


                        return (int) $montant;
                    }
                )
            );
    }


    public function configureOptions(
    OptionsResolver $resolver
): void {
    $resolver->setDefaults([
        'data_class' =>
            Paiements::class,

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