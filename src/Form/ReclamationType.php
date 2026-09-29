<?php

namespace App\Form;

use App\Entity\Reclamation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ReclamationType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('motif', TextareaType::class, [
                'label' => 'Motif de la demande',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' => 'Expliquez la raison de cet achat et pourquoi un remboursement est demandé.',
                ],
                'constraints' => [
                    new Assert\NotBlank(
                        message: 'Veuillez indiquer le motif de la demande.'
                    ),
                ],
            ])

            ->add('montant', IntegerType::class, [
                'label' => 'Montant à rembourser (FCFA)',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                    'placeholder' => 'Exemple : 15000',
                ],
                'constraints' => [
                    new Assert\NotNull(
                        message: 'Veuillez indiquer le montant.'
                    ),
                    new Assert\Positive(
                        message: 'Le montant doit être supérieur à zéro.'
                    ),
                ],
            ])

            ->add('justificatifFichier', FileType::class, [
                'label' => 'Justificatif (reçu, facture...) — si possible',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'accept' => '.pdf,.png,.jpg,.jpeg',
                ],
                'constraints' => [
                    new Assert\File(
                        maxSize: '5M',
                        mimeTypes: [
                            'application/pdf',
                            'image/png',
                            'image/jpeg',
                        ],
                        maxSizeMessage: 'Le justificatif ne doit pas dépasser {{ limit }} {{ suffix }}.',
                        mimeTypesMessage: 'Le justificatif doit être un PDF ou une image (PNG/JPG).'
                    ),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Reclamation::class,
        ]);
    }
}
