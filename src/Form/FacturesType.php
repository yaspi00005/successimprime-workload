<?php

namespace App\Form;

use App\Entity\Factures;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FacturesType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder

            ->add(
                'typeDocument',
                ChoiceType::class,
                [
                    'label' => 'Type de document',
                    'choices' =>
                        Factures::getTypesDocumentPourFormulaire(),

                    'placeholder' =>
                        'Sélectionner le type de document',

                    'required' => true,
                ]
            )

            ->add(
                'emetteur',
                ChoiceType::class,
                [
                    'label' => 'Émetteur',
                    'choices' =>
                        Factures::getEmetteursPourFormulaire(),

                    'required' => true,
                ]
            )

            ->add(
                'dateEcheance',
                DateType::class,
                [
                    'label' => 'Date d’échéance',

                    'widget' => 'single_text',

                    'required' => false,
                ]
            )

            ->add(
                'surfacturation',
                CheckboxType::class,
                [
                    'label' =>
                        'Appliquer une majoration commerciale',

                    'required' => false,
                ]
            )

            ->add(
                'tauxSurfacturation',
                NumberType::class,
                [
                    'label' =>
                        'Taux de majoration (%)',

                    'required' => false,

                    'scale' => 2,

                    'html5' => true,

                    'attr' => [
                        'min' => 0,
                        'step' => '0.01',
                        'placeholder' => 'Ex. 15',
                    ],
                ]
            )

            ->add(
                'montantSurfacturation',
                IntegerType::class,
                [
                    'label' =>
                        'Montant fixe de majoration',

                    'required' => false,

                    'attr' => [
                        'min' => 0,
                        'placeholder' => 'Ex. 50 000',
                    ],
                ]
            )

            ->add(
                'motifSurfacturation',
                TextareaType::class,
                [
                    'label' =>
                        'Motif de la majoration',

                    'required' => false,

                    'attr' => [
                        'rows' => 3,

                        'placeholder' =>
                            'Motif ou information interne...',
                    ],
                ]
            )

            ->add(
                'observation',
                TextareaType::class,
                [
                    'label' => 'Observation',

                    'required' => false,

                    'attr' => [
                        'rows' => 4,

                        'placeholder' =>
                            'Observation à conserver sur le document...',
                    ],
                ]
            )

            /*
             * Facturation à un tiers : le demandeur n'est pas
             * toujours celui qui paie.
             */

            ->add('facturerAUnTiers', CheckboxType::class, [
                'label' => 'Facturer à quelqu’un d’autre que le client',
                'required' => false,
                'attr' => [
                    'class' => 'js-facturer-a-un-tiers',
                ],
            ])

            ->add('nomFacturation', TextType::class, [
                'label' => 'Nom du payeur',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : SOTELMA SA',
                ],
            ])

            ->add('adresseFacturation', TextType::class, [
                'label' => 'Adresse de facturation',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
            ])

            ->add('telephoneFacturation', TelType::class, [
                'label' => 'Téléphone du payeur',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
            ])

            ->add('emailFacturation', EmailType::class, [
                'label' => 'Email du payeur',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
            ]);
    }

    public function configureOptions(
        OptionsResolver $resolver
    ): void {
        $resolver->setDefaults([
            'data_class' => Factures::class,
        ]);
    }
}