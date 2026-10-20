<?php

namespace App\Form;

use App\Service\Parametres\Parametres;
use App\Service\Parametres\ReglagesSaisie;
use App\Entity\Clients;
use App\Entity\Commandes;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Valid;
use App\Entity\CommandesDetails;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Doctrine\ORM\EntityRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\TextType;

class CommandesType extends AbstractType
{
    public function __construct(private readonly Security $security, private readonly ?Parametres $parametres = null, private readonly ?ReglagesSaisie $reglagesSaisie = null)
    {
    }

    /**
     * N'affiche que les clients actifs (statut = true, ou jamais
     * renseigné pour les anciens clients créés avant ce champ).
     *
     * $inclureClientId permet de garder visible, en modification, le
     * client déjà associé à la commande même s'il a été bloqué depuis
     * — sinon le formulaire d'édition casserait sur les anciennes
     * commandes.
     */
    private function optionsChampClients(?int $inclureClientId = null): array
    {
        return [
            'class' => Clients::class,
            'code_id' => true, // data-code : URL sans numéro lisible (docs/ids-chiffres.md)
            'query_builder' => static function (
                EntityRepository $er
            ) use ($inclureClientId) {
                $qb = $er->createQueryBuilder('c')
                    ->orderBy('c.nom', 'ASC');

                if ($inclureClientId !== null) {
                    $qb
                        ->andWhere('c.statut = :actif OR c.statut IS NULL OR c.id = :clientActuel')
                        ->setParameter('clientActuel', $inclureClientId);
                } else {
                    $qb->andWhere('c.statut = :actif OR c.statut IS NULL');
                }

                return $qb->setParameter('actif', true);
            },
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
            /*
             * Carte du client de la saisie (maquette C01). Le crédit
             * autorisé n'est écrit dans la page que pour qui voit les
             * paiements.
             */
            'choice_attr' => function (
                Clients $client
            ): array {
                $attr = [
                    'data-type-client' => $client->getTypeClient(),
                    // Grille de prix (Standard, Partenaire, Association, Revendeur) : aperçu des remises (docs/produits.md).
                    'data-grille' => $client->getGrille(),
                    'data-nom' => $client->getNomComplet(),
                    'data-compte' => $client->getTypeCompteLabel(),
                    'data-telephone' => (string) $client->getTelephone(),
                    'data-adresse' => trim(implode(', ', array_filter([$client->getAdresse(), $client->getVille()]))),
                    // Acompte de la fiche (%, vide = réglage) ; « 1 » si le client est dispensé d'acompte.
                    'data-acompte' => null !== $client->getAcompte() ? (string) $client->getAcompte() : '',
                    'data-sans-acompte' => $client->isSansAcompte() ? '1' : '0',
                ];

                if ($this->security->isGranted('ROLE_PAIEMENT_VOIR')) {
                    $attr['data-credit'] = (string) (int) $client->getPlafondCredit();
                }

                return $attr;
            },
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
        ];
    }

    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $builder
            ->add(
                'clients',
                EntityType::class,
                $this->optionsChampClients()
            )

            ->addEventListener(
                FormEvents::PRE_SET_DATA,
                function (FormEvent $event): void {
                    $commande = $event->getData();

                    $clientActuel = $commande instanceof Commandes
                        ? $commande->getClients()
                        : null;

                    if (
                        $clientActuel === null
                        || $clientActuel->isStatut() !== false
                    ) {
                        return;
                    }

                    $event->getForm()->add(
                        'clients',
                        EntityType::class,
                        $this->optionsChampClients(
                            $clientActuel->getId()
                        )
                    );
                }
            )

            ->add('dateLivraison', DateTimeType::class, [
                'label' => 'Date de livraison',
                'required' => false,
                'widget' => 'single_text',
                'html5' => true,
                /*
                 * Sans "input" explicite, DateTimeType produit un
                 * DateTime mutable a la soumission -- alors que la
                 * colonne dateLivraison est mappee en
                 * DATETIME_IMMUTABLE (voir Commandes::$dateLivraison).
                 * Le setter accepte DateTimeInterface sans erreur,
                 * mais Doctrine refuse ensuite la conversion au
                 * moment de l'enregistrement ("Impossible de
                 * convertir la valeur PHP de type DateTime...").
                 */
                'input' => 'datetime_immutable',
                'attr' => [
                    'class' => 'form-control',
                ],
            ])

            ->add('modeLivraison', ChoiceType::class, [
                'label' => 'Livraison',
                'choices' => [
                    'Retrait à l\'imprimerie' => Commandes::LIVRAISON_RETRAIT,
                    'Livraison' => Commandes::LIVRAISON_DOMICILE,
                ],
                'expanded' => true,
            ])

            ->add('adresseLivraison', TextType::class, [
                'label' => 'Adresse',
                'required' => false,
                'attr' => [
                    'class' => 'form-control',
                    'maxlength' => 255,
                    'placeholder' => 'Adresse ou repère',
                ],
            ])

            /*
             * Frais de livraison (docs/documents-livraisons.md) : « Sans
             * frais » ou un palier des Réglages, jamais un montant tapé.
             * Un autre montant envoyé est refusé ; en retrait à l'imprimerie, les
             * frais retombent à 0 (voir POST_SUBMIT ci-dessous).
             */
            ->add('fraisLivraison', ChoiceType::class, [
                'label' => 'Frais de livraison',
                'choices' => ReglagesSaisie::choixFrais(
                    $this->reglagesSaisie?->paliers() ?? ReglagesSaisie::lirePaliers(ReglagesSaisie::PALIERS_DEFAUT) ?? [],
                    ($options['data'] ?? null) instanceof Commandes ? $options['data']->getFraisLivraison() : 0
                ),
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'empty_data' => '0',
                'invalid_message' => 'Frais de livraison non permis : choisissez « Sans frais » ou un montant proposé.',
            ])
            ->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event): void {
                $commande = $event->getData();
                if ($commande instanceof Commandes && !$commande->estALivrer()) {
                    $commande->setFraisLivraison(0);
                }
            })

            // Objet de la commande (« Impressions »…), imprimé sur la facture.
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

            // Vente hors imprimerie (docs/hors-imprimerie.md) : comptée à part dans les statistiques ;
            // ses lignes ne passent ni par la prépresse ni par l'atelier (HorsImprimerieListener).
            ->add('horsImprimerie', CheckboxType::class, [
                'label' => 'Hors imprimerie',
                'required' => false,
                'help' => 'Vente de matériel, informatique… : comptée à part de l\'imprimerie dans les statistiques',
            ])

            // « Commandé par » : la personne du client qui commande (une entreprise en a souvent plusieurs).
            // Pour une commande passée en ligne, c'est déjà le nom de la personne connectée.
            ->add('demandeePar', TextType::class, [
                'label' => 'Commandé par',
                'required' => false,
                'empty_data' => null,
                'attr' => ['maxlength' => 80],
            ])
            // Passée en ligne : c'est la personne connectée, le nom ne se change pas à l'imprimerie.
            ->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
                $commande = $event->getData();
                if ($commande instanceof Commandes && $commande->isEnLigne() && null !== $commande->getDemandeePar()) {
                    $event->getForm()->add('demandeePar', TextType::class, ['label' => 'Commandé par', 'required' => false, 'disabled' => true]);
                }
            })

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
                if ($document instanceof \App\Entity\Commandes && null !== $document->getLibelleCodePromo()) {
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
             * Ces champs ne doivent normalement pas être affichés
             * dans le formulaire utilisateur.
             */

            ->add('statut', CheckboxType::class, [
                'label' => 'Commande active',
                'required' => false,
            ])

            ->add('etat', CheckboxType::class, [
                'label' => 'État actif',
                'required' => false,
            ])

            /*
             * Une commande contient plusieurs travaux.
             */
            ->add('commandesDetails', CollectionType::class, [
                'entry_type' => CommandesDetailsType::class,
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
                'prototype_data' => (new CommandesDetails())->setTva($this->parametres?->tvaParDefaut() ?? 0),
                'label' => false,
                'required' => true,
                'constraints' => [
                    new Valid(),
                ],
            ])


            ;
    }

    public function configureOptions(
        OptionsResolver $resolver
    ): void {
        $resolver->setDefaults([
            'data_class' => Commandes::class,
        ]);
    }
}