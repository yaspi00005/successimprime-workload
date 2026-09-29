<?php

namespace App\Form;

use App\Entity\Articles;
use App\Entity\Fournisseurs;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ArticlesType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add(
                'reference',
                TextType::class,
                [
                    'label' =>
                        'Référence',

                    'attr' => [
                        'class' =>
                            'form-control',

                        'placeholder' =>
                            'Ex. PAP-A4-170',
                    ],
                ]
            )

            ->add(
                'designation',
                TextType::class,
                [
                    'label' =>
                        'Désignation',

                    'attr' => [
                        'class' =>
                            'form-control',

                        'placeholder' =>
                            'Ex. Papier couché A4 170 g',
                    ],
                ]
            )

            ->add(
                'categorie',
                TextType::class,
                [
                    'label' =>
                        'Catégorie',

                    'required' =>
                        false,

                    'attr' => [
                        'class' =>
                            'form-control',

                        'placeholder' =>
                            'Papier, support, encre...',
                    ],
                ]
            )
->add(
    'vendable',
    CheckboxType::class,
    [
        'label' =>
            'Cet article peut être vendu directement',

        'required' =>
            false,
    ]
)
->add(
    'consommableProduction',
    CheckboxType::class,
    [
        'label' =>
            'Cet article peut aussi être retiré manuellement du stock en production (écran Consommables)',

        'required' =>
            false,
    ]
)
            ->add(
                'unite',
                ChoiceType::class,
                [
                    'label' =>
                        'Unité de gestion',

                    'choices' => [
                        'Unité' =>
                            'unite',

                        'Feuille' =>
                            'feuille',

                        'Paquet' =>
                            'paquet',

                        'Carton' =>
                            'carton',

                        'Rouleau' =>
                            'rouleau',

                        'Mètre' =>
                            'metre',

                        'Mètre carré' =>
                            'm2',

                        'Kilogramme' =>
                            'kg',

                        'Litre' =>
                            'litre',
                    ],

                    'attr' => [
                        'class' =>
                            'form-control',
                    ],
                ]
            )

            ->add(
                'stockMin',
                NumberType::class,
                [
                    'label' =>
                        'Seuil d’alerte',

                    'scale' =>
                        3,

                    'html5' =>
                        true,

                    'attr' => [
                        'class' =>
                            'form-control',

                        'min' =>
                            0,

                        'step' =>
                            '0.001',
                    ],
                ]
            )

            ->add(
                'prixAchat',
                IntegerType::class,
                [
                    'label' =>
                        'Prix d’achat',

                    'required' =>
                        false,

                    'attr' => [
                        'class' =>
                            'form-control',

                        'min' =>
                            0,
                    ],
                ]
            )

            ->add(
                'prixVente',
                IntegerType::class,
                [
                    'label' =>
                        'Prix de vente indicatif',

                    'required' =>
                        false,

                    'attr' => [
                        'class' =>
                            'form-control',

                        'min' =>
                            0,
                    ],
                ]
            )

            ->add(
                'fournisseur',
                EntityType::class,
                [
                    'class' => Fournisseurs::class,
                    'choice_label' => 'nom',

                    'query_builder' => static function (
                        \App\Repository\FournisseursRepository $repository
                    ) {
                        return $repository->createQueryBuilder('f')
                            ->andWhere('f.actif = true')
                            ->orderBy('f.nom', 'ASC');
                    },

                    'label' =>
                        'Fournisseur',

                    'placeholder' =>
                        'Aucun fournisseur',

                    'required' =>
                        false,

                    'attr' => [
                        'class' =>
                            'form-control js-select-search',
                    ],
                ]
            )

            ->add(
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
                    ],
                ]
            )

            ->add(
                'actif',
                CheckboxType::class,
                [
                    'label' =>
                        'Article actif',

                    'required' =>
                        false,
                ]
            );
    }


    public function configureOptions(
        OptionsResolver $resolver
    ): void {
        $resolver->setDefaults([
            'data_class' =>
                Articles::class,
        ]);
    }
}