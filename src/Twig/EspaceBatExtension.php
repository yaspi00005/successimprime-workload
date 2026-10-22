<?php

namespace App\Twig;

use App\Entity\CommandesDetails;
use App\Service\EspaceClient\DelaisCommande;
use App\Service\EspaceClient\EspaceClient;
use Twig\Attribute\AsTwigFunction;

/** Dates courtes de « Fichiers et BAT » (maquette DB04) : « lun. 16:00 », « jeu. 23/10 », « ce matin 08:40 ». */
final class EspaceBatExtension
{
    private const JOURS = [1 => 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];

    #[AsTwigFunction('limite_bat')]
    public function limite(\DateTimeImmutable $d): string
    {
        return DelaisCommande::limiteCourte($d);
    }

    #[AsTwigFunction('jour_court')]
    public function jourCourt(\DateTimeImmutable $d): string
    {
        return self::JOURS[(int) $d->format('N')].' '.$d->format('d/m');
    }

    #[AsTwigFunction('quand_bat')]
    public function quand(\DateTimeImmutable $d): string
    {
        $jour = $d->format('Y-m-d');

        return match (true) {
            $jour === date('Y-m-d') => ((int) $d->format('G') < 12 ? 'ce matin ' : 'aujourd\'hui ').$d->format('H:i'),
            $jour === date('Y-m-d', strtotime('-1 day')) => 'hier '.$d->format('H:i'),
            default => 'le '.$d->format('d/m'),
        };
    }

    #[AsTwigFunction('espace_taille')]
    public function taille(CommandesDetails $l): ?string
    {
        return EspaceClient::taille($l);
    }
}
