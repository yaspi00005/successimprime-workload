<?php

namespace App\Form;

use App\Entity\ModeleMessage;
use App\Entity\RegleRappelPaiement;
use App\Repository\ModeleMessageRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class RegleRappelPaiementType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('nom', TextType::class, [
                'label' => 'Nom de la règle',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : Rappel J+7',
                ],
                'constraints' => [
                    new NotBlank(message: 'Le nom de la règle est obligatoire.'),
                ],
            ])

            ->add('delaiJours', IntegerType::class, [
                'label' => 'Fréquence (tous les combien de jours)',
                'help' => 'Le rappel part à J+N après la commande, puis se répète tous les N jours tant qu\'elle n\'est pas soldée.',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                    'placeholder' => 'Exemple : 7',
                ],
            ])

            ->add('modeleMessage', EntityType::class, [
                'label' => 'Modèle de message',
                'class' => ModeleMessage::class,
                'query_builder' => static function (
                    ModeleMessageRepository $repository
                ): QueryBuilder {
                    return $repository->createQueryBuilder('modele')
                        ->andWhere('modele.actif = :actif')
                        ->setParameter('actif', true)
                        ->orderBy('modele.nom', 'ASC');
                },
                'choice_label' => static fn (ModeleMessage $modele): string => sprintf(
                    '%s (%s)',
                    $modele->getNom(),
                    $modele->getCanalLabel()
                ),
                'placeholder' => 'Sélectionnez un modèle...',
                'attr' => ['class' => 'form-control'],
            ])

            ->add('actif', CheckboxType::class, [
                'label' => 'Règle active',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegleRappelPaiement::class,
        ]);
    }
}
