<?php

namespace App\Form;

use App\Entity\CompteTresorerie;
use App\Entity\DecaissementRecurrent;
use App\Entity\MouvementTresorerie;
use App\Repository\CompteTresorerieRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

final class DecaissementRecurrentType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('libelle', TextType::class, [
                'label' => 'Libellé',
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Ex : Frais de tenue de compte, Échéance crédit matériel...',
                ],
                'constraints' => [
                    new NotBlank(message: 'Le libellé est obligatoire.'),
                ],
            ])

            ->add('compteSource', EntityType::class, [
                'label' => 'Compte à débiter',
                'class' => CompteTresorerie::class,
                'query_builder' => static function (
                    CompteTresorerieRepository $repository
                ): QueryBuilder {
                    return $repository->createQueryBuilder('compte')
                        ->andWhere('compte.actif = :actif')
                        ->setParameter('actif', true)
                        ->orderBy('compte.nom', 'ASC');
                },
                'choice_label' => static fn (CompteTresorerie $compte): string => (string) $compte,
                'placeholder' => 'Sélectionnez un compte...',
                'attr' => ['class' => 'form-control'],
            ])

            ->add('categorie', ChoiceType::class, [
                'label' => 'Catégorie',
                'choices' => array_flip(array_intersect_key(
                    MouvementTresorerie::CATEGORIES_LABELS,
                    array_flip(MouvementTresorerie::getCategoriesDecaissementRecurrent())
                )),
                'attr' => ['class' => 'form-control'],
            ])

            ->add('montant', IntegerType::class, [
                'label' => 'Montant (FCFA)',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                    'placeholder' => 'Exemple : 15000',
                ],
            ])

            ->add('frequence', ChoiceType::class, [
                'label' => 'Fréquence',
                'choices' => array_flip(DecaissementRecurrent::FREQUENCES_LABELS),
                'expanded' => true,
                'attr' => ['class' => 'form-control'],
            ])

            ->add('jourDuMois', IntegerType::class, [
                'label' => 'Jour du mois',
                'help' => 'Entre 1 et 28, pour rester valable tous les mois (y compris février).',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 1,
                    'max' => 28,
                ],
            ])

            ->add('moisDeLAnnee', ChoiceType::class, [
                'label' => 'Mois (si fréquence annuelle)',
                'required' => false,
                'placeholder' => '—',
                'choices' => [
                    'Janvier' => 1, 'Février' => 2, 'Mars' => 3, 'Avril' => 4,
                    'Mai' => 5, 'Juin' => 6, 'Juillet' => 7, 'Août' => 8,
                    'Septembre' => 9, 'Octobre' => 10, 'Novembre' => 11, 'Décembre' => 12,
                ],
                'attr' => ['class' => 'form-control'],
            ])

            ->add('dateDebut', DateType::class, [
                'label' => 'À partir du',
                'widget' => 'single_text',
                'attr' => ['class' => 'form-control'],
            ])

            ->add('actif', CheckboxType::class, [
                'label' => 'Charge active',
                'required' => false,
            ])

            ->add('notes', TextareaType::class, [
                'label' => 'Notes (facultatif)',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 2,
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DecaissementRecurrent::class,
        ]);
    }
}
