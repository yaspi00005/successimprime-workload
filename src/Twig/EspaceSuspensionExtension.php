<?php

namespace App\Twig;

use App\Entity\Clients;
use App\Service\Clients\EditionClient;
use App\Service\EspaceClient\SuspensionsEspace;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Bloc « Accès à l'espace client » de la fiche client (ERP local) :
 * suspensions en cours, historique, personnes qu'on peut suspendre
 * (clients/_espace_suspension.html.twig). Les gestes passent par
 * EspaceSuspensionController.
 */
final class EspaceSuspensionExtension extends AbstractExtension
{
    public function __construct(
        private readonly SuspensionsEspace $suspensions,
        private readonly EditionClient $edition,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('espace_suspensions', $this->donnees(...))];
    }

    /**
     * @return array{actives: list<\App\Entity\SuspensionEspace>, levees: list<\App\Entity\SuspensionEspace>, personnes: list<array{telephone: string, nom: string, libelle: string}>, empechement: ?string, toutLeCompte: bool}
     */
    public function donnees(Clients $client): array
    {
        $actives = $this->suspensions->actives($client);

        return [
            'actives' => $actives,
            'levees' => array_values(array_filter($this->suspensions->historique($client), static fn ($s): bool => !$s->estActive())),
            'personnes' => SuspensionsEspace::personnes($client, $this->edition->contacts($client)),
            'empechement' => $this->suspensions->empechement(),
            'toutLeCompte' => [] !== array_filter($actives, static fn ($s): bool => $s->estToutLeCompte()),
        ];
    }
}
