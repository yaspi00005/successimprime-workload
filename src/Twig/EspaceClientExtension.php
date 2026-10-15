<?php

namespace App\Twig;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Paiements;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Paiement\FraisOrangeMoney;
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

    /**
     * Taux des frais Orange Money tel qu'il est réglé : « 1 % », « 1,5 % »,
     * le signe collé au nombre (espace insécable) pour ne pas aller seul à
     * la ligne sur téléphone.
     */
    #[AsTwigFilter('frais_taux')]
    public function tauxFrais(float $taux): string
    {
        return str_replace(' %', "\u{00A0}%", FraisOrangeMoney::taux($taux));
    }

    /**
     * Paiements Orange Money de la commande avec leurs frais (payés en
     * plus, hors commande), les plus récents d'abord, sans les annulés.
     *
     * @return list<Paiements>
     */
    #[AsTwigFilter('espace_paiements_om')]
    public function paiementsOrangeMoney(Commandes $c): array
    {
        $liste = array_values(array_filter(
            $c->getPaiements()->toArray(),
            static fn (Paiements $p): bool => Paiements::MODE_ORANGE_MONEY === $p->getMode()
                && \in_array($p->getStatut(), [Paiements::STATUT_EN_ATTENTE, Paiements::STATUT_VALIDE], true)
                && $p->getMontantTotalEncaisse() > (int) $p->getMontant(),
        ));
        usort($liste, static fn (Paiements $a, Paiements $b): int => $b->getDate() <=> $a->getDate());

        return $liste;
    }
}
