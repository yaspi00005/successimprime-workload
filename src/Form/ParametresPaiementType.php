<?php

namespace App\Form;

use App\Entity\ParametresPaiement;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class ParametresPaiementType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Même plafond que la tuile Paiements : le site en ligne refuse un taux plus haut.
        foreach ([
            'tauxFondsSoutien' => ['Fonds de soutien (%)', 'Prélèvement de l\'État sur les retraits mobile money, payé par le client en plus du montant.'],
            'tauxFraisRetrait' => ['Frais de retrait (%)', 'Frais de retrait d\'Orange Money, payés par le client en plus du montant.'],
        ] as $champ => [$libelle, $aide]) {
            $builder->add($champ, NumberType::class, [
                'label' => $libelle,
                'html5' => true,
                'scale' => 2,
                'constraints' => [new Assert\Range(min: 0, max: 20)],
                'attr' => [
                    'class' => 'form-control',
                    'min' => 0,
                    'max' => 20,
                    'step' => '0.01',
                ],
                'help' => $aide.' Orange Money : compté d\'office dans l\'espace client, coché d\'office à la caisse. '
                    .'Wave : seulement si la case est cochée. 0 : la ligne disparaît.',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ParametresPaiement::class,
        ]);
    }
}
