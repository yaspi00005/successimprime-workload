<?php

namespace App\Form;

use App\Entity\Articles;
use App\Entity\CompteTresorerie;
use App\Entity\Depense;
use App\Entity\Fournisseurs;
use App\Repository\ArticlesRepository;
use App\Repository\FournisseursRepository;
use App\Service\Finances\Budgets;
use App\Service\Finances\Depenses;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;

/**
 * Saisie rapide d'une dépense (maquette F03) : photo du reçu obligatoire,
 * catégorie de budget, montant, compte « Payé avec » (vide = pas encore
 * payée) et ce à quoi elle est liée.
 */
final class DepenseType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('justificatif', FileType::class, [
                'label' => 'Photo du reçu ou de la facture',
                'mapped' => false,
                'required' => true,
                'attr' => ['accept' => 'image/jpeg,image/png,image/webp,application/pdf', 'class' => 'form-control'],
                'constraints' => [
                    new NotNull(message: 'La photo du reçu est obligatoire.'),
                    new File(
                        maxSize: '8M',
                        mimeTypes: array_keys(Depenses::TYPES_JUSTIFICATIF),
                        mimeTypesMessage: 'Le reçu doit être une photo (JPEG, PNG, WebP) ou un PDF.',
                        maxSizeMessage: 'Le reçu dépasse 8 Mo.',
                    ),
                ],
            ])
            ->add('categorie', ChoiceType::class, [
                'label' => 'Catégorie',
                'choices' => array_flip(array_map(static fn (array $c): string => $c['libelle'], Budgets::CATEGORIES)),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('montant', IntegerType::class, [
                'label' => 'Montant (F)',
                'data' => $options['montant'],
                'attr' => ['class' => 'form-control', 'inputmode' => 'numeric', 'min' => 1, 'placeholder' => '12 000'],
                'constraints' => [new NotBlank(message: 'Le montant est obligatoire.'), new Positive(message: 'Le montant doit être positif.')],
            ])
            ->add('compte', EntityType::class, [
                'label' => 'Payé avec',
                'class' => CompteTresorerie::class,
                'choices' => $options['comptes'],
                'choice_label' => static fn (CompteTresorerie $c): string => (string) $c->getNom(),
                'required' => false,
                'placeholder' => 'Pas encore payée',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('lieA', TextType::class, [
                'label' => 'Lié à',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Tournée, commande, machine…', 'maxlength' => 120],
            ])
            ->add('objet', TextType::class, [
                'label' => 'Objet',
                'attr' => ['class' => 'form-control', 'placeholder' => 'Carburant moto livraison', 'maxlength' => 150],
                'constraints' => [new NotBlank(message: 'Indiquez l\'objet de la dépense.'), new Length(max: 150)],
            ])
            ->add('fournisseur', EntityType::class, [
                'label' => 'Fournisseur',
                'class' => Fournisseurs::class,
                'query_builder' => static fn (FournisseursRepository $r): QueryBuilder => $r->createQueryBuilder('f')->andWhere('f.actif = true')->orderBy('f.nom', 'ASC'),
                'choice_label' => static fn (Fournisseurs $f): string => (string) $f->getNom(),
                'required' => false,
                'placeholder' => 'Autre (préciser à côté)',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('beneficiaire', TextType::class, [
                'label' => 'Payé à',
                'required' => false,
                'attr' => ['class' => 'form-control', 'placeholder' => 'Station Bakary', 'maxlength' => 100],
            ])
            ->add('article', EntityType::class, [
                'label' => 'Article acheté',
                'class' => Articles::class,
                'query_builder' => static fn (ArticlesRepository $r): QueryBuilder => $r->createQueryBuilder('a')->andWhere('a.actif = true')->orderBy('a.designation', 'ASC'),
                'choice_label' => static fn (Articles $a): string => (string) $a->getDesignation(),
                'required' => false,
                'placeholder' => '—',
                'attr' => ['class' => 'form-select'],
            ])
            ->add('quantite', NumberType::class, [
                'label' => 'Quantité',
                'required' => false,
                'scale' => 3,
                'attr' => ['class' => 'form-control', 'inputmode' => 'decimal', 'placeholder' => '3'],
            ])
            ->add('dateDepense', DateType::class, [
                'label' => 'Date',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['class' => 'form-control'],
                'constraints' => [new NotNull(message: 'La date est obligatoire.'), new LessThanOrEqual(value: 'today', message: 'La date ne peut pas être dans le futur.')],
            ])
            ->add('echeance', DateType::class, [
                'label' => 'À payer le',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
                'attr' => ['class' => 'form-control'],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Note',
                'required' => false,
                'attr' => ['class' => 'form-control', 'rows' => 2],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Depense::class, 'comptes' => [], 'montant' => null]);
        $resolver->setAllowedTypes('montant', ['null', 'int']);
        $resolver->setAllowedTypes('comptes', 'array');
    }
}
