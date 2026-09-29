<?php

namespace App\Form;

use App\Entity\Achats;
use App\Entity\Fournisseurs;
use App\Repository\FournisseursRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Component\Validator\Constraints\Valid;

final class AchatType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('fournisseur', EntityType::class, [
                'class' => Fournisseurs::class,
                'choice_label' => 'nom',

                'query_builder' => static function (
                    FournisseursRepository $repository
                ) {
                    return $repository->createQueryBuilder('f')
                        ->andWhere('f.actif = true')
                        ->orderBy('f.nom', 'ASC');
                },

                'placeholder' => 'Sélectionnez un fournisseur',

                'attr' => [
                    'class' => 'form-control js-select-search',
                    'data-placeholder' => 'Rechercher un fournisseur...',
                ],

                'constraints' => [
                    new NotNull(message: 'Sélectionnez un fournisseur.'),
                ],
            ])

            ->add('tva', IntegerType::class, [
                'label' => 'TVA (%)',
                'required' => false,
                'empty_data' => '0',
                'attr' => [
                    'class' => 'form-control js-achat-tva',
                    'min' => 0,
                ],
                'constraints' => [
                    new PositiveOrZero(message: 'La TVA ne peut pas être négative.'),
                ],
            ])

            ->add('observation', TextareaType::class, [
                'label' => 'Observation',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
            ])

            ->add('lignes', CollectionType::class, [
                'entry_type' => AchatDetailType::class,
                'label' => false,
                'required' => true,
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_name' => '__ligne__',
                'constraints' => [
                    new Count(
                        min: 1,
                        minMessage: 'Ajoutez au moins un article à acheter.'
                    ),
                    new Valid(),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Achats::class,
        ]);
    }
}
