<?php

namespace App\Form;

use App\Entity\Format;
use App\Entity\ProduitConfiguration;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProduitConfigurationType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('active')
            ->add('ordre')
            ->add('description')
            ->add('prixBase')
            ->add('modeCalcul')
            ->add('quantiteMinimale')
            ->add('quantiteMaximale')

            ->add('prixB2B', MoneyType::class, [
                'label' => 'Prix net B2B',
                'currency' => 'XOF',
                'required' => false,
                'html5' => true,
                'attr' => [
                    'min' => 0,
                    'step' => 1,
                    'placeholder' =>
                        'Laisser vide si aucun tarif B2B',
                ],
            ])

            ->add('produit', EntityType::class, [
                'class' => Produits::class,
                'choice_label' => 'nom',
                'label' => 'Produit',
                'placeholder' => 'Sélectionnez un produit',
                'attr' => [
                    'class' => 'form-control js-select-search',
                    'data-placeholder' =>
                        'Rechercher un produit...',
                ],
            ])

            ->add('typeImpression', EntityType::class, [
                'class' => TypesImpression::class,
                'choice_label' => 'nom',
                'label' => 'Type d’impression',
               // 'placeholder' =>
                    'Sélectionnez un type d’impression',
                'attr' => [
                    'class' => 'form-control js-select-search',
                ],
            ])

            ->add('support', EntityType::class, [
                'class' => Supports::class,
                'choice_label' => 'nom',
                'label' => 'Support',
                'placeholder' => 'Sélectionnez un support',
                'attr' => [
                    'class' => 'form-control js-select-search',
                ],
            ])

            ->add('format', EntityType::class, [
                'class' => Format::class,
                'choice_label' => 'nom',
                'label' => 'Format',
                'placeholder' => 'Sélectionnez un format',
                'attr' => [
                    'class' => 'form-control js-select-search',
                ],
            ]);
    }

    public function configureOptions(
        OptionsResolver $resolver
    ): void {
        $resolver->setDefaults([
            'data_class' => ProduitConfiguration::class,
        ]);
    }
}