<?php

namespace App\Form;

use App\Entity\CategorieProduit;
use App\Entity\Finition;
use App\Entity\Format;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;

class ProduitsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom')
            ->add('description')
            ->add('publie')
            ->add('ordre')
            ->add('code')
            ->add('prixBase')
            ->add('personnalisable')
            ->add(
    'articlesStock',
    CollectionType::class,
    [
        'entry_type' => ProduitArticleStockType::class,
        'entry_options' => [
            'label' => false,
        ],

        'allow_add' => true,
        'allow_delete' => true,
        'by_reference' => false,

        'prototype' => true,

        'label' => false,
    ]
)
            ->add(
                'gestionStock',
                CheckboxType::class,
                [
                    'required' => false,
                    'label' => 'Produit géré en stock',
                    'help' => 'Active le contrôle et les mouvements automatiques de stock pour ce produit.',
                ]
            )
            ->add('articleStock',
                EntityType::class,
                [
                    'class' => Articles::class,
                    'choice_label' => 'nom',
                    'required' => false,
                    'placeholder' => 'Sélectionnez l’article de stock',
                    'label' => 'Article associé au stock',
                ]
            )
            ->add('actif')
            ->add('categorieProduit', EntityType::class, [
                'class' => CategorieProduit::class,
                'choice_label' => 'id',
            ])
            ->add('typesImpressions', EntityType::class, [
                'class' => TypesImpression::class,
                'choice_label' => 'id',
                'multiple' => true,
            ])
            ->add('supports', EntityType::class, [
                'class' => Supports::class,
                'choice_label' => 'id',
                'multiple' => true,
            ])
            ->add('formats', EntityType::class, [
                'class' => Format::class,
                'choice_label' => 'id',
                'multiple' => true,
            ])
            ->add('finitions', EntityType::class, [
                'class' => Finition::class,
                'choice_label' => 'id',
                'multiple' => true,
            ])
            ->add('prixB2B', MoneyType::class, [
                'label' => 'Prix net B2B',
                'currency' => 'XOF',
                'required' => false,
                'html5' => true,
                'attr' => [
                    'min' => 0,
                    'step' => 1,
                    'placeholder' => 'Laisser vide si aucun tarif B2B',
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Produits::class,
        ]);
    }
}
