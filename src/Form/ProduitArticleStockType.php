<?php

namespace App\Form;

use App\Entity\Articles;
use App\Entity\ProduitArticleStock;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProduitArticleStockType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder

            ->add(
                'article',
                EntityType::class,
                [
                    'class' => Articles::class,
                    'choice_label' => 'nom',
                    'placeholder' => 'Sélectionner un article de stock',
                    'label' => 'Article de stock',
                ]
            )

            ->add(
                'coefficient',
                NumberType::class,
                [
                    'label' => 'Coefficient',
                    'scale' => 3,
                    'html5' => true,
                    'attr' => [
                        'min' => 0.001,
                        'step' => 0.001,
                    ],
                ]
            )

            ->add(
                'modeCalcul',
                ChoiceType::class,
                [
                    'label' => 'Mode de consommation',
                    'choices' => [
                        'Par quantité' =>
                            ProduitArticleStock::MODE_QUANTITE,

                        'Par unité' =>
                            ProduitArticleStock::MODE_UNITE,

                        'Par surface' =>
                            ProduitArticleStock::MODE_SURFACE,

                        'Par mètre' =>
                            ProduitArticleStock::MODE_METRE,

                        'Forfait' =>
                            ProduitArticleStock::MODE_FORFAIT,
                    ],
                ]
            )

            ->add(
                'obligatoire',
                CheckboxType::class,
                [
                    'required' => false,
                    'label' => 'Consommation obligatoire',
                ]
            )

            ->add(
                'actif',
                CheckboxType::class,
                [
                    'required' => false,
                    'label' => 'Actif',
                ]
            )

            ->add(
                'ordre',
                NumberType::class,
                [
                    'label' => 'Ordre',
                    'html5' => true,
                    'attr' => [
                        'min' => 0,
                        'step' => 1,
                    ],
                ]
            )

            ->add(
                'observation',
                TextareaType::class,
                [
                    'required' => false,
                    'label' => 'Observation',
                    'attr' => [
                        'rows' => 2,
                    ],
                ]
            );
    }

    public function configureOptions(
        OptionsResolver $resolver
    ): void {
        $resolver->setDefaults([
            'data_class' => ProduitArticleStock::class,
        ]);
    }
}