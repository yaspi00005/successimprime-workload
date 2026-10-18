<?php

namespace App\Twig;

use App\Service\Corbeille\Corbeille;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Attributs de la fenêtre de confirmation (public/js/confirmation.js,
 * docs/corbeille.md) pour une suppression :
 *
 *   <form … {{ confirmer_suppression('le devis ' ~ devis.numero) }}>
 *
 * donne « Supprimer le devis DEV-2026-0042 ? Il restera 30 jours dans la
 * corbeille. » avec les boutons « Annuler » / « Supprimer » (rouge).
 *
 * Options : verbe (« Retirer »…), detail (phrase de plus, avant la durée),
 * corbeille: false pour ce qui ne passe pas par la corbeille (« Cette
 * suppression est définitive. »), client: true dans l'espace client (le
 * client ne voit pas la corbeille).
 */
final class ConfirmationExtension extends AbstractExtension
{
    public function __construct(private readonly Corbeille $corbeille)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('confirmer_suppression', $this->attributs(...), ['is_safe' => ['html']]),
            new TwigFunction('corbeille_detail', $this->detail(...)),
        ];
    }

    /** @param array{verbe?: string, detail?: string, corbeille?: bool, client?: bool} $options */
    public function attributs(string $objet, array $options = []): string
    {
        $verbe = (string) ($options['verbe'] ?? 'Supprimer');
        $question = trim($verbe.' '.trim($objet)).' ?';
        $attributs = [
            'data-confirmer' => $question,
            'data-confirmer-detail' => trim(trim((string) ($options['detail'] ?? '')).' '.$this->detail($options)),
            'data-confirmer-bouton' => $verbe,
        ];
        $html = '';
        foreach ($attributs as $nom => $valeur) {
            $html .= \sprintf(' %s="%s"', $nom, htmlspecialchars($valeur, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8'));
        }

        return ltrim($html).' data-confirmer-danger';
    }

    /**
     * La phrase qui suit la question : combien de temps la fiche reste
     * récupérable.
     *
     * @param array{corbeille?: bool, client?: bool} $options
     */
    public function detail(array $options = []): string
    {
        if (false === ($options['corbeille'] ?? true)) {
            return 'Cette suppression est définitive.';
        }
        $duree = $this->corbeille->dureeTexte();

        return true === ($options['client'] ?? false)
            ? \sprintf('En cas d\'erreur, appelez Succès Imprim : c\'est récupérable pendant %s.', $duree)
            : \sprintf('Il restera %s dans la corbeille.', $duree);
    }
}
