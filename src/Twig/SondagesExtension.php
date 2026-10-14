<?php

namespace App\Twig;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Service\EspaceClient\EspaceClient;
use App\Service\Sondages\AnalyseSondages;
use App\Service\Sondages\Sondages;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Les sondages dans les pages des autres écrans : avis sur une commande
 * livrée (espace client), avis à donner, tableau de bord direction et
 * note de l'écran TV.
 */
final class SondagesExtension
{
    public function __construct(
        private readonly Sondages $sondages,
        private readonly AnalyseSondages $analyse,
        private readonly EspaceClient $espace,
    ) {
    }

    /**
     * Avis du client sur sa commande : déjà donné, à donner, ou rien.
     *
     * @return array{reponse: ?\App\Entity\ReponseSondage, possible: bool}
     */
    #[AsTwigFunction('avis_commande')]
    public function avisCommande(Commandes $commande): array
    {
        $reponse = $this->sondages->reponseCommande($commande);

        return ['reponse' => $reponse, 'possible' => null === $reponse && $this->sondages->peutNoter($commande, new \DateTimeImmutable())];
    }

    /** Commandes livrées à noter et sondages ouverts au client. */
    #[AsTwigFunction('avis_a_donner')]
    public function avisADonner(Clients $client): int
    {
        $maintenant = new \DateTimeImmutable();

        return \count($this->sondages->commandesANoter($this->espace->commandes($client, 30), $maintenant)) + \count($this->sondages->sondagesPour($client, $maintenant));
    }

    /** @return array<string, mixed> */
    #[AsTwigFunction('satisfaction_direction')]
    public function satisfactionDirection(): array
    {
        return $this->analyse->resumeDirection(new \DateTimeImmutable());
    }

    /** @return array{moyenne: float, nombre: int}|null */
    #[AsTwigFunction('satisfaction_ecran_tv')]
    public function satisfactionEcranTv(): ?array
    {
        return $this->analyse->noteEcranTv(new \DateTimeImmutable());
    }

    /** « 4,3 » : une note sur 5 avec une décimale. */
    #[AsTwigFilter('note_sur_5')]
    public function note(?float $note): string
    {
        return null === $note ? '—' : number_format($note, 1, ',', ' ');
    }
}
