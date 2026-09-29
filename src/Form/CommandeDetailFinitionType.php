<?php

namespace App\Form;

use App\Entity\CommandeDetailFinition;
use App\Entity\Finition;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use App\Entity\ProduitConfigurationFinition;

class CommandeDetailFinitionType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('finition', EntityType::class, [
                'class' => Finition::class,
                'choice_label' => 'nom',
                'label' => 'Finition',
                'placeholder' => 'Sélectionnez une finition',
                'required' => true,
                'attr' => [
                    'class' =>
                        'form-select js-finition-manuelle-select',
                ],
            ])

            ->add('prixApplique', IntegerType::class, [
    'label' => 'Prix appliqué',
    'required' => true,
    'empty_data' => '0',
    'attr' => [
        'class' => 'form-control js-finition-prix',
        'min' => 0,
        'step' => 1,
        'inputmode' => 'numeric',
    ],
])

            ->add('modeCalcul', ChoiceType::class, [
                'label' => 'Mode de calcul',
                'required' => true,
                'choices' => [
                    'Forfait' => 'forfait',
                    'Unité' => 'unite',
                    'Heure' => 'heure',
                    'Feuille' => 'feuille',
                    'Exemplaire' => 'exemplaire',
                    'Mètre linéaire' => 'metre',
                    'Mètre carré' => 'metre_carre',
                    'Point' => 'point',
                    'Face' => 'face',
                ],
                'attr' => [
                    'class' =>
                        'form-select js-finition-mode-calcul',
                ],
            ])

          ->add('quantite', IntegerType::class, [
    'label' => 'Quantité',
    'required' => true,
    'empty_data' => '1',
    'attr' => [
        'class' => 'form-control js-finition-quantite',
        'min' => 1,
        'step' => 1,
        'inputmode' => 'numeric',
    ],
])->add('configurationFinition', EntityType::class, [
    'class' => ProduitConfigurationFinition::class,
    'choice_label' => static function (
        ProduitConfigurationFinition $regle
    ): string {
        return $regle->getFinition()?->getNom()
            ?? 'Finition';
    },
    'label' => false,
    'placeholder' => false,
    'required' => false,
    'attr' => [
        'class' => 'js-configuration-finition',
    ],
]);
    }

    public function configureOptions(
        OptionsResolver $resolver
    ): void {
        $resolver->setDefaults([
            'data_class' => CommandeDetailFinition::class,
        ]);
    }
}