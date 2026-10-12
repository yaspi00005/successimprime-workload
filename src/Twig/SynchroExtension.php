<?php

namespace App\Twig;

use App\Service\Synchro\AgentSynchro;
use App\Service\Synchro\Synchro;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Pastille « Synchronisé avec … » en bas du menu (maquette 39), pour les
 * administrateurs de l'ERP local.
 */
final class SynchroExtension extends AbstractExtension
{
    public function __construct(private readonly Synchro $synchro)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('pastille_synchro', $this->pastille(...))];
    }

    /** @return array{site: string, reussite: ?\DateTimeImmutable, panne: bool}|null */
    public function pastille(): ?array
    {
        if ($this->synchro->estEnLigne() || !$this->synchro->estConfiguree()) {
            return null;
        }
        $reussite = $this->synchro->dateEtat(AgentSynchro::ETAT_REUSSITE);

        return [
            'site' => parse_url($this->synchro->url(), \PHP_URL_HOST) ?: 'le site',
            'reussite' => $reussite,
            'panne' => Synchro::alerte(true, $reussite, $this->synchro->dateEtat(AgentSynchro::ETAT_MISE_EN_ROUTE), new \DateTimeImmutable(), null) !== null,
        ];
    }
}
