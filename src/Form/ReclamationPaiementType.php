<?php

namespace App\Form;

use App\Entity\CompteTresorerie;
use App\Entity\MouvementTresorerie;
use App\Entity\User;
use App\Repository\CompteTresorerieRepository;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formulaire (non lié à une entité) utilisé par la caisse pour payer
 * une réclamation validée : choix du compte à débiter et de la
 * catégorie financière du décaissement qui sera créé.
 */
class ReclamationPaiementType extends AbstractType
{
    public function buildForm(
        FormBuilderInterface $builder,
        array $options
    ): void {
        $estAdmin = (bool) $options['est_admin'];
        $utilisateur = $options['utilisateur'];

        $builder
            ->add('compteSource', EntityType::class, [
                'label' => 'Compte à débiter',
                'class' => CompteTresorerie::class,
                'query_builder' => static function (
                    CompteTresorerieRepository $repository
                ) use ($estAdmin, $utilisateur): QueryBuilder {
                    $qb = $repository->createQueryBuilder('compte')
                        ->andWhere('compte.actif = :actif')
                        ->setParameter('actif', true)
                        ->orderBy('compte.type', 'ASC')
                        ->addOrderBy('compte.nom', 'ASC');

                    /*
                     * Un admin peut débiter n'importe quel compte
                     * actif. Une caisse (ROLE_CAISSE_COMMANDE) ne
                     * doit pouvoir payer que depuis sa propre caisse
                     * ou un compte partagé -- jamais la caisse
                     * personnelle d'un autre agent, ni un compte
                     * réservé à l'administration.
                     */
                    if (!$estAdmin) {
                        $qb
                            ->andWhere(
                                '
                                compte.portee = :partagee
                                OR
                                (
                                    compte.portee = :personnelle
                                    AND
                                    compte.proprietaire = :utilisateur
                                )
                                '
                            )
                            ->setParameter('partagee', CompteTresorerie::PORTEE_PARTAGEE)
                            ->setParameter('personnelle', CompteTresorerie::PORTEE_PERSONNELLE)
                            ->setParameter('utilisateur', $utilisateur);
                    }

                    return $qb;
                },
                'choice_label' => static function (CompteTresorerie $compte): string {
                    return sprintf(
                        '%s — %s — %s FCFA',
                        $compte->getNom(),
                        $compte->getTypeLabel(),
                        number_format((int) $compte->getSoldeActuel(), 0, ',', ' ')
                    );
                },
                'placeholder' => 'Sélectionner le compte à débiter',
                'attr' => ['class' => 'form-select'],
                'constraints' => [
                    new Assert\NotNull(message: 'Sélectionnez le compte à débiter.'),
                ],
            ])

            ->add('categorie', ChoiceType::class, [
                'label' => 'Catégorie financière',
                'choices' => MouvementTresorerie::getCategoriesPourFormulaire(),
                'data' => MouvementTresorerie::CATEGORIE_ACHAT,
                'attr' => ['class' => 'form-select'],
                'constraints' => [
                    new Assert\NotBlank(message: 'Veuillez sélectionner une catégorie financière.'),
                ],
            ])

            ->add('modePaiement', ChoiceType::class, [
                'label' => 'Mode de paiement',
                'placeholder' => 'Sélectionner le mode',
                'required' => false,
                'choices' => [
                    'Espèces' => 'especes',
                    'Orange Money' => 'orange_money',
                    'Wave' => 'wave',
                    'Virement bancaire' => 'virement',
                ],
                'attr' => ['class' => 'form-select'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'est_admin' => false,
            'utilisateur' => null,
        ]);

        $resolver->setAllowedTypes('est_admin', 'bool');
        $resolver->setAllowedTypes('utilisateur', [User::class, 'null']);
    }
}
