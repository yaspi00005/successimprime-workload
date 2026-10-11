<?php

namespace App\Twig;

use App\Service\Parametres\Parametres;
use App\Service\Pointage\CalculPointage;
use App\Service\Pointage\Pointages;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * reglage('paiements', 'acompte') : valeur d'un réglage de l'écran
 * Paramètres, pour les gabarits qui en dépendent.
 */
final class ReglagesExtension extends AbstractExtension
{
    public function __construct(private readonly Parametres $parametres)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('reglage', $this->parametres->valeur(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            // « 9 h 14 », « 18 min » (pointage).
            new TwigFilter('duree_pointage', CalculPointage::duree(...)),
            new TwigFilter('nom_pointage', Pointages::nom(...)),
            // « samedi 10 octobre 2026 » (motif ICU simplifié, voir dateFr).
            new TwigFilter('date_fr', self::dateFr(...)),
        ];
    }

    private const JOURS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'];
    private const MOIS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

    /**
     * Date en français sans dépendre de l'extension intl (absente sur
     * certains serveurs). Motifs ICU reconnus : EEEE, EEE, MMMM, MM, dd,
     * d, yyyy, y, HH, mm ; le reste est recopié.
     */
    public static function dateFr(\DateTimeInterface $date, string $motif): string
    {
        return (string) preg_replace_callback('/EEEE|EEE|MMMM|MM|dd|d|yyyy|y|HH|mm/', static fn (array $m): string => match ($m[0]) {
            'EEEE' => self::JOURS[(int) $date->format('N') - 1],
            'EEE' => mb_substr(self::JOURS[(int) $date->format('N') - 1], 0, 3).'.',
            'MMMM' => self::MOIS[(int) $date->format('n') - 1],
            'MM' => $date->format('m'),
            'dd' => $date->format('d'),
            'd' => $date->format('j'),
            'yyyy', 'y' => $date->format('Y'),
            'HH' => $date->format('H'),
            'mm' => $date->format('i'),
        }, $motif);
    }
}
