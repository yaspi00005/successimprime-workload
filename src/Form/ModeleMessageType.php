<?php

namespace App\Form;

use App\Entity\ModeleMessage;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ModeleMessageType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom du modèle',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : Rappel J+7, Promo fêtes de fin d’année...',
                ],
                'constraints' => [
                    new NotBlank(message: 'Le nom du modèle est obligatoire.'),
                ],
            ])

            ->add('canal', ChoiceType::class, [
                'label' => 'Canal',
                'choices' => array_flip(ModeleMessage::CANAUX_LABELS),
                'attr' => ['class' => 'form-control js-canal-modele'],
            ])

            ->add('sujet', TextType::class, [
                'label' => 'Sujet (email uniquement)',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Objet du message, si canal = Email',
                ],
            ])

            ->add('contenu', TextareaType::class, [
                'label' => 'Contenu du message',
                'help' => 'Variables disponibles : {{nom}}, {{numeroCommande}}, {{montant}}, {{reste}}, {{dateCommande}}.',
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 5,
                    'placeholder' => 'Bonjour {{nom}}, votre commande {{numeroCommande}} a un reste à payer de {{reste}} FCFA.',
                ],
                'constraints' => [
                    new NotBlank(message: 'Le contenu du message est obligatoire.'),
                ],
            ])

            ->add('actif', CheckboxType::class, [
                'label' => 'Modèle actif',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ModeleMessage::class,
        ]);
    }
}
