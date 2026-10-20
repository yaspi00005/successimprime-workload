<?php

namespace App\Form\Extension;

use App\Routing\CodeId;
use App\Routing\IdsChiffres;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\ChoiceList\View\ChoiceGroupView;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Option « code_id » d'une liste de fiches (EntityType) : chaque choix
 * reçoit data-code, le code chiffré de son numéro (docs/ids-chiffres.md).
 * Le script de la page s'en sert pour écrire une URL (« /produit/{code}/… »)
 * sans numéro lisible ; la valeur envoyée par le formulaire reste le numéro.
 */
final class CodeIdChoixExtension extends AbstractTypeExtension
{
    public function __construct(private readonly CodeId $codes)
    {
    }

    public static function getExtendedTypes(): iterable
    {
        return [EntityType::class];
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('code_id', false);
        $resolver->setAllowedTypes('code_id', 'bool');
    }

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        if (!$options['code_id']) {
            return;
        }
        foreach (['preferred_choices', 'choices'] as $cle) {
            $this->coder($view->vars[$cle] ?? []);
        }
    }

    /** @param array<ChoiceView|ChoiceGroupView> $choix */
    private function coder(iterable $choix): void
    {
        foreach ($choix as $c) {
            if ($c instanceof ChoiceGroupView) {
                $this->coder($c->choices);
            } elseif ($c instanceof ChoiceView && null !== ($id = IdsChiffres::numero($c->value))) {
                $c->attr['data-code'] = $this->codes->coder($id);
            }
        }
    }
}
