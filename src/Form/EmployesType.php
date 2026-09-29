<?php

namespace App\Form;

use App\Entity\Employes;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;

class EmployesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
    ->add('prenom', TextType::class, [
        'attr' => [
            'class' => 'form-control',
            'placeholder' => 'Entrez le prénom de l’employé'
        ]
    ])

    ->add('nom', TextType::class, [
        'attr' => [
            'class' => 'form-control',
            'placeholder' => 'Entrez le nom de l’employé'
        ]
    ])

    ->add('dateNaissances', DateType::class, [
        'widget' => 'single_text',
        'attr' => [
            'class' => 'form-control',
            'placeholder' => 'Date de naissance'
        ]
    ])
      ->add('dateEmbauches', DateType::class, [
        'widget' => 'single_text',
        'attr' => [
            'class' => 'form-control',
            'placeholder' => 'Date de naissance'
        ]
    ])
    ->add('adresses', TextareaType::class, [
    'required' => false,
    'attr' => [
        'class' => 'form-control',
        'placeholder' => 'Adresse complète de l’employé',
        'rows' => 4,
    ],
])

    ->add('telephone', TextType::class, [
        'attr' => [
            'class' => 'form-control',
            'placeholder' => 'Téléphone'
        ]
    ])

    ->add('email', EmailType::class, [
        'attr' => [
            'class' => 'form-control',
            'placeholder' => 'Email'
        ]
    ])

    ->add('photos', FileType::class, [
        'required' => false,
        'mapped' => false,
        'attr' => [
            'class' => 'dropify',
            'data-height' => '300'
        ]
    ])

    ->add('cin', FileType::class, [
        'required' => false,
        'mapped' => false,
        'attr' => [
            'class' => 'dropify',
            'data-height' => '300'
        ]
    ])

    ->add('fonction', TextType::class, [
        'attr' => [
            'class' => 'form-control',
            'placeholder' => 'Fonction'
        ]
    ])

    ->add('salaires', MoneyType::class, [
        'currency' => 'XOF',
        'attr' => [
            'class' => 'form-control',
            'placeholder' => 'Salaire'
        ]
    ])

    ->add('matricules', TextType::class, [
        'attr' => [
            'class' => 'form-control',
            'placeholder' => 'Matricule'
        ]
    ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Employes::class,
        ]);
    }
}
