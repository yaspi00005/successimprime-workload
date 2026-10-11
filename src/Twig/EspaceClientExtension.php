<?php

namespace App\Twig;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Service\EspaceClient\EspaceClient;
use Twig\Attribute\AsTwigFilter;

/** Titres courts de l'espace client : « Banderole 300 × 100 × 2 ». */
final class EspaceClientExtension
{
    #[AsTwigFilter('espace_titre')]
    public function titre(Commandes $c): string
    {
        return EspaceClient::titre($c);
    }

    #[AsTwigFilter('espace_titre_ligne')]
    public function titreLigne(CommandesDetails $l): string
    {
        return EspaceClient::titreLigne($l);
    }
}
