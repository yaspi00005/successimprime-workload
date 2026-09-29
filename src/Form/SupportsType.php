<?php

namespace App\Form;

use App\Entity\Finition;
use App\Entity\Format;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SupportsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('nom')
            ->add('description')
            ->add('publie')
            ->add('ordre')
            ->add('typesImpressions', EntityType::class, [
                'class' => TypesImpression::class,
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
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Supports::class,
        ]);
    }
}
