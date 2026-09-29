<?php

namespace App\Form;

use App\Entity\Clients;
use App\Entity\Devis;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Valid;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotNull;

class DevisType extends AbstractType
{
     public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('emetteur', ChoiceType::class, [
                'label' => 'Émetteur',
                'choices' => Devis::getEmetteursPourFormulaire(),
                'required' => true,
            ])

            ->add('clients', EntityType::class, [
                'class' => Clients::class,
                'choice_label' => static function (
                    Clients $client
                ): string {
                    $telephone = $client->getTelephone()
                        ?: 'Sans téléphone';

                    $prenom = trim(
                        (string) $client->getPrenom()
                    );

                    $nom = trim(
                        (string) $client->getNom()
                    );

                    $raisonSociale = trim(
                        (string) $client->getRaisonSociale()
                    );

                    $libelle = trim(sprintf(
                        '%s — %s %s',
                        $telephone,
                        $prenom,
                        $nom
                    ));

                    if ($raisonSociale !== '') {
                        $libelle .= ' — ' . $raisonSociale;
                    }

                    return $libelle;
                },
                'placeholder' => 'Sélectionnez un client',
                'required' => true,
                'attr' => [
                    'class' => 'form-select js-select-search',
                    'data-placeholder'
                        => 'Téléphone, prénom ou nom...',
                ],
                'constraints' => [
                    new NotNull(
                        message: 'Veuillez sélectionner un client.'
                    ),
                ],
            ])

           

            ->add('remise', IntegerType::class, [
                'label' => 'Remise globale',
                'required' => false,
                'empty_data' => '0',
                'attr' => [
                    'class' => 'form-control js-remise-commande',
                    'min' => 0,
                    'step' => 1,
                ],
                'constraints' => [
                    new GreaterThanOrEqual(
                        value: 0,
                        message: 'La remise ne peut pas être négative.'
                    ),
                ],
            ])

            ->add('tva', IntegerType::class, [
                'label' => 'TVA',
                'required' => false,
                'empty_data' => '0',
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'step' => 1,
                    'readonly' => true,
                ],
            ])

            ->add('totalHt', IntegerType::class, [
                'label' => 'Total HT',
                'required' => false,
                'empty_data' => '0',
                'attr' => [
                    'class' => 'form-control js-total-ht-commande',
                    'readonly' => true,
                    'min' => 0,
                ],
            ])

            ->add('totalTtc', IntegerType::class, [
                'label' => 'Total TTC',
                'required' => false,
                'empty_data' => '0',
                'attr' => [
                    'class' => 'form-control js-total-ttc-commande',
                    'readonly' => true,
                    'min' => 0,
                ],
            ])

            ->add('montantApayer', IntegerType::class, [
                'label' => 'Montant à payer',
                'required' => false,
                'empty_data' => '0',
                'attr' => [
                    'class'
                        => 'form-control js-montant-a-payer-commande',
                    'readonly' => true,
                    'min' => 0,
                ],
            ])

            ->add('observation', TextareaType::class, [
                'label' => 'Observation',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 4,
                    'placeholder'
                        => 'Informations complémentaires...',
                ],
            ])

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
            ])

            /*
             * Ces champs ne doivent normalement pas être affichés
             * dans le formulaire utilisateur.
             */

           

            ->add('etat', CheckboxType::class, [
                'label' => 'État actif',
                'required' => false,
            ])

            ->add('statut', ChoiceType::class, [
                'label' => 'Statut',
                'choices' => [
                    'Brouillon' => Devis::STATUT_BROUILLON,
                    'Envoyé' => Devis::STATUT_ENVOYE,
                    'Accepté' => Devis::STATUT_ACCEPTE,
                    'Refusé' => Devis::STATUT_REFUSE,
                    'Expiré' => Devis::STATUT_EXPIRE,
                    'Converti en commande' => Devis::STATUT_CONVERTI,
                    'Annulé' => Devis::STATUT_ANNULE,
                ],
                'attr' => [
                    'class' => 'form-control',
                ],
            ])

            /*
             * Une commande contient plusieurs travaux.
             */
            ->add('devisDetails', CollectionType::class, [
    'entry_type' => DevisDetailsType::class,
    'entry_options' => [
        'label' => false,
        'embedded' => true,
    ],
    'allow_add' => true,
    'allow_delete' => true,
    'delete_empty' => true,
    'by_reference' => false,
    'prototype' => true,
    'prototype_name' => '__detail__',
    'label' => false,
    'required' => true,
    'constraints' => [
        new Valid(),
    ],
]);
    }

    public function configureOptions(
        OptionsResolver $resolver
    ): void {
        $resolver->setDefaults([
            'data_class' => Devis::class,
        ]);
    }
}

