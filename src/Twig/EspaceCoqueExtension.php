<?php

namespace App\Twig;

use App\Entity\Clients;
use App\Entity\PropositionClient;
use App\Service\EspaceClient\CoqueEspace;
use App\Service\EspaceClient\FilNotifications;
use App\Service\EspaceClient\PropositionsClient;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

/**
 * Coque de l'espace client (maquettes EC01 à EC05) : menu, pastilles,
 * cloche et carte du conseiller, calculés une fois par page selon les
 * droits de la personne connectée (CoqueEspace).
 */
final class EspaceCoqueExtension
{
    public function __construct(
        private readonly CoqueEspace $coque,
        private readonly PropositionsClient $propositions,
    ) {
    }

    /** @return array<string, mixed>|null */
    #[AsTwigFunction('espace_coque')]
    public function coque(): ?array
    {
        return $this->coque->donnees();
    }

    /** « 09:40 » aujourd'hui, « hier », sinon « 10/10 ». */
    #[AsTwigFilter('espace_quand')]
    public function quand(\DateTimeInterface $date): string
    {
        return FilNotifications::quand($date, new \DateTimeImmutable());
    }

    /**
     * @param list<array<string, mixed>> $fil
     *
     * @return list<array{libelle: string, notifications: list<array<string, mixed>>}>
     */
    #[AsTwigFunction('espace_par_jour')]
    public function parJour(array $fil): array
    {
        return FilNotifications::parJour($fil, new \DateTimeImmutable());
    }

    /**
     * Fiche client (ERP) : changements de raison sociale ou de NIF demandés
     * en ligne, en attente.
     *
     * @return list<PropositionClient>
     */
    #[AsTwigFunction('propositions_client')]
    public function propositionsClient(Clients $client): array
    {
        return $this->propositions->enAttente($client);
    }
}
