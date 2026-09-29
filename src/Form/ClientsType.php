<?php

namespace App\Form;

use App\Entity\Clients;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;

class ClientsType extends AbstractType
{

    public function __construct(
        private readonly Security $security
    ) {}
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $typesClient = [
            'Particulier (B2C)' => 'B2C',
        ];

        if ($this->security->isGranted('ROLE_ADMIN')) {
            $typesClient['Entreprise (B2B)'] = 'B2B';
        }

        $builder
            ->add('typeClient', ChoiceType::class, [
                'label' => 'Type de client',
                'choices' => $typesClient,
                'placeholder' => 'Sélectionnez le type de client',
                'attr' => [
                    'class' => 'form-control client-type-select',
                ],
                'help' => 'Sert uniquement à la tarification (B2B/B2C), sans lien avec les informations d’entreprise ci-dessous.',
            ])

            ->add('typeCompte', ChoiceType::class, [
                'label' => 'Type de compte',
                'choices' => array_flip(Clients::TYPES_COMPTE_LABELS),
                'attr' => [
                    'class' => 'form-control client-account-type-select',
                ],
                'help' => 'Détermine si les informations d’entreprise (raison sociale, NIF, RCCM) sont demandées.',
            ])

            ->add('raisonSociale', TextType::class, [
                'label' => 'Raison sociale',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Nom de l’entreprise',
                ],
            ])

            ->add('nom', TextType::class, [
                'label' => 'Nom',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Nom du client',
                ],
            ])

            ->add('prenom', TextType::class, [
                'label' => 'Prénom',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Prénom du client',
                ],
            ])

            ->add('telephone', TelType::class, [
                'label' => 'Téléphone principal',
                'required' => true,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Exemple : 76 00 00 00',
                ],
            ])
->add('statut', CheckboxType::class, [
    'label' => 'Client actif',
    'required' => false,
    'attr' => [
        'class' => 'custom-control-input',
    ],
    'label_attr' => [
        'class' => 'custom-control-label',
    ],
])
->add('recevoirSms', CheckboxType::class, [
    'label' => 'Peut recevoir des SMS',
    'required' => false,
    'attr' => [
        'class' => 'custom-control-input',
    ],
    'label_attr' => [
        'class' => 'custom-control-label',
    ],
])
            ->add('telephone2', TelType::class, [
                'label' => 'Deuxième téléphone',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Facultatif',
                ],
            ])

            
            ->add('email', EmailType::class, [
                'label' => 'Adresse email',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'client@exemple.com',
                ],
            ])

            ->add('adresse', TextType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Adresse du client',
                ],
            ])

            ->add('ville', TextType::class, [
                'label' => 'Ville',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Bamako',
                ],
            ])

            ->add('nif', TextType::class, [
                'label' => 'NIF',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Numéro d’identification fiscale',
                ],
            ])

            ->add('rccm', TextType::class, [
                'label' => 'RCCM',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'placeholder' => 'Numéro RCCM',
                ],
            ])

            ->add('plafondCredit', IntegerType::class, [
                'label' => 'Plafond de crédit',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'step' => 1,
                    'placeholder' => 'Montant en FCFA',
                ],
            ])

            ->add('observation', TextareaType::class, [
                'label' => 'Observation',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'rows' => 3,
                    'placeholder' => 'Informations complémentaires',
                ],
            ]);
       
    }

    public function configureOptions(
        OptionsResolver $resolver
    ): void {
        $resolver->setDefaults([
            'data_class' => Clients::class,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'client_form',
        ]);
    }
}
