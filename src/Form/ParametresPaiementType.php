<?php

namespace App\Form;

use App\Entity\ParametresPaiement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ParametresPaiementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tauxFraisRetrait', NumberType::class, [
                'label' => 'Frais de retrait (%)',
                'html5' => true,
                'scale' => 2,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 100,
                    'step' => '0.01',
                ],
                'help' => 'Pourcentage facturé au client en plus du prix de la commande '
                    . 'quand il paie par Orange Money ou Wave.',
            ])
            ->add('tauxFondsSoutien', NumberType::class, [
                'label' => 'Fonds de soutien (%)',
                'html5' => true,
                'scale' => 2,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 100,
                    'step' => '0.01',
                ],
                'help' => 'Pourcentage facturé au client en plus du prix de la commande '
                    . 'quand il paie par Orange Money ou Wave.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ParametresPaiement::class,
        ]);
    }
}
