<?php

namespace App\Form;

use App\Entity\Fournisseurs;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class FournisseursType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom du fournisseur',
                'attr' => [
                    'class' => 'form-control',
                ],
                'constraints' => [
                    new NotBlank(
                        message: 'Le nom du fournisseur est obligatoire.'
                    ),
                ],
            ])

            ->add('telephone', IntegerType::class, [
                'label' => 'Téléphone',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
            ])

            ->add('email', EmailType::class, [
                'label' => 'E-mail',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                ],
            ])

            ->add('adresse', TextareaType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                ],
            ])

            // Fiche fournisseur (maquette SU02, docs/achats.md).
            ->add('fournit', TextType::class, [
                'label' => 'Ce qu\'il fournit',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Bâches 440 g et 510 g'],
            ])

            ->add('ville', TextType::class, [
                'label' => 'Ville',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Bamako'],
            ])

            ->add('pays', TextType::class, [
                'label' => 'Pays',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Mali'],
            ])

            ->add('contactNom', TextType::class, [
                'label' => 'Contact',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'M. Ndiaye'],
            ])

            ->add('contactTelephone', TextType::class, [
                'label' => 'Téléphone du contact (WhatsApp)',
                'required' => false,
                'help' => 'Avec l\'indicatif pour l\'étranger : +221 77 …',
                'attr' => ['class' => 'form-control', 'placeholder' => '+221 77 000 00 00'],
            ])

            ->add('conditionsPaiement', TextType::class, [
                'label' => 'Conditions de paiement',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'virement, 50 % à la commande'],
            ])

            ->add('acomptePourcentage', IntegerType::class, [
                'label' => 'Acompte à la commande (%)',
                'required' => false,
                'empty_data' => '0',
                'attr' => ['class' => 'form-control', 'min' => 0, 'max' => 100],
            ])

            ->add('transport', TextType::class, [
                'label' => 'Transport',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'camion groupé Dakar → Bamako'],
            ])

            ->add('delaiPromisJours', IntegerType::class, [
                'label' => 'Délai promis (jours)',
                'required' => false,
                'help' => 'De la commande à l\'arrivée à Bamako. Le délai réel se calcule sur les livraisons.',
                'attr' => ['class' => 'form-control', 'min' => 0],
            ])

            ->add('couleur', TextType::class, [
                'label' => 'Couleur de la pastille',
                'required' => false,
                'help' => 'Code couleur comme #E95B35. Vide : une couleur tirée du nom.',
                'attr' => ['class' => 'form-control', 'placeholder' => '#E95B35', 'pattern' => '#[0-9A-Fa-f]{6}'],
            ])

            ->add('actif', CheckboxType::class, [
                'label' => 'Fournisseur actif',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Fournisseurs::class,
        ]);
    }
}
