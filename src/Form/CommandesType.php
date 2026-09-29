<?php

namespace App\Form;

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
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Valid;
use App\Entity\CommandesDetails;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Doctrine\ORM\EntityRepository;

class CommandesType extends AbstractType
{
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
            'choice_attr' => static function (
                Clients $client
            ): array {
                return [
                    'data-type-client' => $client->getTypeClient(),
                ];
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