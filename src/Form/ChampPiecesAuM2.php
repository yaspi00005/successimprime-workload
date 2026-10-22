<?php

namespace App\Form;

use App\Entity\CommandesDetails;
use App\Entity\DevisDetails;
use App\Service\PiecesParMetreCarre;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Case « Pièces au m² » d'une ligne de commande ou de devis
 * (docs/saisie-devis-commandes.md) : un champ caché « 1 » / « 0 » tenu par
 * public/js/pieces-m2.js. Le serveur ne reprend de la page que ce choix :
 * l'espacement vient de la ligne (déjà choisie) ou du réglage, la surface
 * facturée est refaite depuis la largeur, la longueur et la quantité.
 *
 * Un envoi sans le champ (autre écran, ancienne page) ne change rien.
 */
final class ChampPiecesAuM2
{
    public const NOM = 'piecesAuM2';

    public static function ajouter(FormBuilderInterface $builder, ?PiecesParMetreCarre $piecesM2): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
            $ligne = $event->getData();
            $event->getForm()->add(self::NOM, HiddenType::class, [
                'mapped' => false,
                'required' => false,
                'data' => ($ligne instanceof CommandesDetails || $ligne instanceof DevisDetails) && null !== $ligne->getEspacementPiecesM2() ? '1' : '0',
                'attr' => ['class' => 'js-pm2-choix', 'data-detail-field' => self::NOM],
            ]);
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($piecesM2): void {
            $ligne = $event->getData();
            $form = $event->getForm();
            if (!($ligne instanceof CommandesDetails || $ligne instanceof DevisDetails) || !$form->has(self::NOM)) {
                return;
            }

            $choix = self::choix($form->get(self::NOM)->getData());
            if (null !== $choix) {
                $ligne->choisirPiecesAuM2($choix, $piecesM2?->espacement() ?? PiecesParMetreCarre::ESPACEMENT_DEFAUT);
            }
        });
    }

    /** « 1 » : oui, « 0 » : non, autre chose (champ absent) : on ne change rien. */
    public static function choix(mixed $valeur): ?bool
    {
        return match ($valeur) {
            '1' => true,
            '0' => false,
            default => null,
        };
    }
}
