<?php

namespace App\Form;

use App\Service\Parametres\Parametres;
use App\Service\Parametres\ReglagesSaisie;
use App\Entity\DevisDetails;
use App\Repository\EntrepriseRepository;
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
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;

class DevisType extends AbstractType
{
    public function __construct(private readonly EntrepriseRepository $entreprises, private readonly ?Parametres $parametres = null, private readonly ?ReglagesSaisie $reglagesSaisie = null)
    {
    }

     public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add('emetteur', ChoiceType::class, [
                'label' => 'Émetteur',
                // Entreprises de Réglages › Entreprises (maquette I03).
                'choices' => $this->entreprises->choixEmetteurs(($options['data'] ?? null)?->getEmetteur())
                    ?: Devis::getEmetteursPourFormulaire(),
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
                // Carte du client de la saisie (maquette 22).
                'choice_attr' => static fn (Clients $client): array => [
                    'data-type-client' => $client->getTypeClient(),
                    // Grille de prix (Standard, Partenaire, Association, Revendeur) : aperçu des remises (docs/produits.md).
                    'data-grille' => $client->getGrille(),
                    'data-nom' => $client->getNomComplet(),
                    'data-compte' => $client->getTypeCompteLabel(),
                    'data-telephone' => (string) $client->getTelephone(),
                ],
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

            /*
             * Frais de livraison (docs/documents-livraisons.md) : « Sans
             * frais » ou un palier des Réglages, jamais un montant tapé ;
             * un autre montant envoyé est refusé. Ils suivent à la commande.
             */
            ->add('fraisLivraison', ChoiceType::class, [
                'label' => 'Frais de livraison',
                'choices' => ReglagesSaisie::choixFrais(
                    $this->reglagesSaisie?->paliers() ?? ReglagesSaisie::lirePaliers(ReglagesSaisie::PALIERS_DEFAUT) ?? [],
                    ($options['data'] ?? null) instanceof Devis ? $options['data']->getFraisLivraison() : 0
                ),
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'empty_data' => '0',
                'invalid_message' => 'Frais de livraison non permis : choisissez « Sans frais » ou un montant proposé.',
            ])

            // Objet du devis (« Impressions »…), imprimé sur le devis puis sur la facture.
            ->add('objet', TextType::class, [
                'label' => 'Objet',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'maxlength' => 150,
                    'list' => 'objets-suggeres',
                    'autocomplete' => 'off',
                    'placeholder' => 'Impressions, prestations informatiques…',
                ],
                'constraints' => [
                    new Length(max: 150, maxMessage: 'L\'objet tient en {{ limit }} caractères au plus.'),
                ],
            ])

            // « À l'attention de » : la personne du client qui demande (une entreprise en a souvent plusieurs).
            ->add('interlocuteur', TextType::class, [
                'label' => 'À l\'attention de',
                'required' => false,
                'empty_data' => null,
                'attr' => ['maxlength' => 80],
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

            /*
             * Code promo tapé au comptoir (docs/promotions.md) : il ne
             * sert qu'à l'enregistrement, pour les promotions qui en
             * demandent un. Les lignes qui l'ont eu le gardent ensuite.
             */
            ->add('codePromo', TextType::class, [
                'label' => 'Code promo',
                'mapped' => false,
                'required' => false,
                'attr' => [
                    'class' => 'form-control js-code-promo',
                    'maxlength' => 30,
                    'autocomplete' => 'off',
                    'placeholder' => 'Facultatif',
                ],
            ])
            // Le code retenu (ou tapé en ligne) revient dans le champ : l'enregistrer de nouveau le garde.
            ->addEventListener(\Symfony\Component\Form\FormEvents::POST_SET_DATA, static function (\Symfony\Component\Form\FormEvent $event): void {
                $document = $event->getData();
                if ($document instanceof \App\Entity\Devis && null !== $document->getLibelleCodePromo()) {
                    $event->getForm()->get('codePromo')->setData($document->getLibelleCodePromo());
                }
            })

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

            /*
             * Le statut ne se choisit plus dans le formulaire (maquette
             * GC04) : « Enregistrer le brouillon », « Envoyer au client »,
             * puis la réponse du client ou la fiche (docs/devis.md).
             */

            // En-tête et conditions du devis (maquette GC04), imprimés sur le PDF et montrés au client.
            ->add('validiteJours', ChoiceType::class, [
                'label' => 'Valable',
                'choices' => array_combine(array_map(static fn (int $j): string => $j.' jours', Devis::VALIDITES), Devis::VALIDITES),
                'attr' => ['class' => 'form-select js-validite'],
            ])
            ->add('delaiRealisation', TextType::class, [
                'label' => 'Délai de réalisation',
                'required' => false,
                'empty_data' => null,
                'attr' => ['maxlength' => 80, 'placeholder' => '5 jours ouvrés', 'list' => 'delais-suggeres'],
                'constraints' => [new Length(max: 80)],
            ])
            ->add('acomptePourcentage', ChoiceType::class, [
                'label' => 'Acompte',
                'choices' => array_combine(array_map(static fn (int $p): string => 0 === $p ? 'Pas d\'acompte' : $p.' % à l\'acceptation', Devis::ACOMPTES), Devis::ACOMPTES),
                'attr' => ['class' => 'form-select form-select-sm js-acompte-pourcentage'],
            ])
            ->add('conditionSolde', TextType::class, [
                'label' => 'Solde',
                'required' => false,
                'empty_data' => null,
                'attr' => ['maxlength' => 120, 'placeholder' => 'à la livraison'],
                'constraints' => [new Length(max: 120)],
            ])
            ->add('conditionFichier', TextType::class, [
                'label' => 'Fichier',
                'required' => false,
                'empty_data' => null,
                'attr' => ['maxlength' => 120, 'placeholder' => 'fichier fourni par le client'],
                'constraints' => [new Length(max: 120)],
            ])
            ->add('conditionGarantie', TextType::class, [
                'label' => 'Garantie',
                'required' => false,
                'empty_data' => null,
                'attr' => ['maxlength' => 120, 'placeholder' => 'facultatif, ex. 12 mois sur les LED'],
                'constraints' => [new Length(max: 120)],
            ])
            ->add('canauxEnvoi', ChoiceType::class, [
                'label' => 'Envoyer par',
                'choices' => array_flip(Devis::CANAUX_LIBELLES),
                'multiple' => true,
                'expanded' => true,
            ])
            // Mot facultatif joint à l'envoi : il part dans les messages de l'espace client.
            ->add('messageClient', TextareaType::class, [
                'label' => 'Message au client',
                'mapped' => false,
                'required' => false,
                'attr' => ['rows' => 2, 'maxlength' => 600, 'placeholder' => 'Facultatif : un mot avec le devis'],
                'constraints' => [new Length(max: 600)],
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
                // Nouvelle ligne : TVA par défaut de Paramètres › Taxes.
                'prototype_data' => (new DevisDetails())->setTva($this->parametres?->tvaParDefaut() ?? 0),
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

