<?php

namespace App\Service\Site;

use App\Repository\ActionSocialeRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * « Nos actions sociales » (demande de Yaya du 16/10/2026) : la partie ne
 * paraît sur le site (page, bloc de l'accueil, lien du pied de page, plan
 * du site) qu'à partir de la première action publiée avec un texte et au
 * moins une photo. Avant, la page répond « introuvable ».
 *
 * La réponse est gardée le temps d'une requête (toutes les pages la
 * demandent pour le pied de page).
 */
final class ActionsSociales implements SourcePlanSite, ResetInterface
{
    /** Actions montrées dans le bloc de l'accueil. */
    public const ACCUEIL = 3;

    public const ROUTE = 'app_site_actions_sociales';

    private ?bool $visibles = null;

    public function __construct(private readonly ActionSocialeRepository $actions)
    {
    }

    /** Au moins une action montrée : la partie paraît. */
    public function visibles(): bool
    {
        return $this->visibles ??= $this->actions->existeVisible();
    }

    /**
     * Dans le plan du site seulement quand la page existe, avec la date de
     * la dernière action modifiée. Ses photos n'y sont pas listées : ce
     * sont souvent des personnes (des enfants), on ne les pousse pas vers
     * la recherche d'images.
     */
    public function pagesDuPlan(): iterable
    {
        if ($this->visibles()) {
            yield ['route' => self::ROUTE, 'modifieLe' => $this->actions->derniereModification(), 'priorite' => '0.5'];
        }
    }

    public function reset(): void
    {
        $this->visibles = null;
    }
}
