<?php

namespace App\Form;

use App\Entity\AchatDetail;
use App\Entity\Articles;
use App\Repository\ArticlesRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\PositiveOrZero;

final class AchatDetailType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('article', EntityType::class, [
                'class' => Articles::class,
                'choice_label' => static fn(Articles $article): string =>
                    sprintf('%s — %s', $article->getReference(), $article->getDesignation()),

                'query_builder' => static function (
                    ArticlesRepository $repository
                ) {
                    return $repository->createQueryBuilder('a')
                        ->andWhere('a.actif = true')
                        ->orderBy('a.designation', 'ASC');
                },

                'placeholder' => 'Sélectionnez un article',

                'attr' => [
                    'class' => 'form-control js-select-search js-achat-article',
                    'data-placeholder' => 'Rechercher un article...',
                ],

                'constraints' => [
                    new NotNull(message: 'Sélectionnez un article.'),
                ],
            ])

            ->add('quantite', IntegerType::class, [
                'label' => 'Quantité',
                'attr' => [
                    'class' => 'form-control js-achat-quantite',
                    'min' => 1,
                ],
                'constraints' => [
                    new Positive(message: 'La quantité doit être supérieure à zéro.'),
                ],
            ])

            ->add('prixUnitaire', IntegerType::class, [
                'label' => 'Prix unitaire (FCFA)',
                'attr' => [
                    'class' => 'form-control js-achat-prix',
                    'min' => 0,
                ],
                'constraints' => [
                    new PositiveOrZero(message: 'Le prix unitaire ne peut pas être négatif.'),
                ],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AchatDetail::class,
        ]);
    }
}
