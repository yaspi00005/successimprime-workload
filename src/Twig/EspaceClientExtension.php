<?php

namespace App\Twig;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Paiement\OrangeMoney;
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

    /** Reste à payer en ligne : ce qui est payé par Orange Money et attend la caisse n'est plus demandé. */
    #[AsTwigFilter('espace_a_payer')]
    public function aPayer(Commandes $c): int
    {
        return OrangeMoney::aPayer($c);
    }

    #[AsTwigFilter('espace_en_validation')]
    public function enValidation(Commandes $c): int
    {
        return OrangeMoney::enValidation($c);
    }
}
