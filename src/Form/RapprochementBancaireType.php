<?php

namespace App\Form;

use App\Entity\CompteTresorerie;
use App\Entity\RapprochementBancaire;
use Doctrine\ORM\EntityRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class RapprochementBancaireType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('compteTresorerie', EntityType::class, [
                'class' => CompteTresorerie::class,

                /*
                 * Remplacez "libelle" par "nom"
                 * si votre entité utilise getNom().
                 */
                'choice_label' => static function (
                    CompteTresorerie $compte
                ): string {
                    return sprintf(
                        '%s%s',
                        $compte->getNom(),
                        $compte->getNumeroCompte()
                            ? ' — ' . $compte->getNumeroCompte()
                            : ''
                    );
                },

                'query_builder' => static function (
                    EntityRepository $repository
                ) {
                    return $repository
                        ->createQueryBuilder('compte')
                        ->andWhere('compte.type = :type')
                        ->setParameter(
                            'type',
                            CompteTresorerie::TYPE_BANQUE
                        )
                        ->orderBy('compte.nom', 'ASC');
                },

                'placeholder' => 'Sélectionner un compte bancaire',
                'label' => 'Compte bancaire',
                'required' => true,
                'attr' => [
                    'class' => 'form-select',
                ],
                'row_attr' => [
                    'class' => 'col-md-6 mb-3',
                ],
            ])

            ->add('dateDebut', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Date de début',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                ],
                'row_attr' => [
                    'class' => 'col-md-3 mb-3',
                ],
            ])

            ->add('dateFin', DateType::class, [
                'widget' => 'single_text',
                'label' => 'Date de fin',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                ],
                'row_attr' => [
                    'class' => 'col-md-3 mb-3',
                ],
            ])

            ->add('soldeOuvertureReleve', IntegerType::class, [
                'label' => 'Solde d’ouverture du relevé',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'step' => 1,
                    'placeholder' => 'Exemple : 500000',
                ],
                'help' => 'Montant en FCFA indiqué au début du relevé.',
                'row_attr' => [
                    'class' => 'col-md-6 mb-3',
                ],
            ])

            ->add('soldeClotureReleve', IntegerType::class, [
                'label' => 'Solde de clôture du relevé',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'step' => 1,
                    'placeholder' => 'Exemple : 750000',
                ],
                'help' => 'Montant en FCFA indiqué à la fin du relevé.',
                'row_attr' => [
                    'class' => 'col-md-6 mb-3',
                ],
            ])

            ->add('observation', TextareaType::class, [
                'label' => 'Observation',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder' =>
                        'Ajouter une observation si nécessaire…',
                ],
                'row_attr' => [
                    'class' => 'col-md-12 mb-3',
                ],
            ]);
    }

    public function configureOptions(
        OptionsResolver $resolver
    ): void {
        $resolver->setDefaults([
            'data_class' => RapprochementBancaire::class,
        ]);
    }
}