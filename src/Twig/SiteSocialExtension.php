<?php

namespace App\Twig;

use App\Entity\ActionSociale;
use App\Entity\Commandes;
use App\Entity\CompteClient;
use App\Repository\ActionSocialeRepository;
use App\Service\Site\ActionsSociales;
use App\Service\Site\SoutienSite;
use App\Service\Sondages\Sondages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * « Nos actions sociales » et « Nous soutenir » dans les gabarits : bloc
 * de l'accueil, pied de page, carte du tableau de bord de l'espace client
 * et invitation d'une commande livrée (docs/site-vitrine.md).
 */
final class SiteSocialExtension
{
    public function __construct(
        private readonly ActionsSociales $partie,
        private readonly ActionSocialeRepository $actions,
        private readonly SoutienSite $soutien,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Actions montrées dans le bloc de l'accueil : aucune tant que la
     * partie n'a pas paru.
     *
     * @return list<ActionSociale>
     */
    #[AsTwigFunction('site_actions_sociales')]
    public function actionsSociales(int $nombre = ActionsSociales::ACCUEIL): array
    {
        return $this->partie->visibles() ? $this->actions->visibles($nombre) : [];
    }

    /**
     * Lien de l'avis Google et adresse du QR code (null : pas de QR), ou
     * null quand « Nous soutenir » est coupé.
     *
     * @return array{lien: string, qr: ?string}|null
     */
    #[AsTwigFunction('site_soutien')]
    public function soutien(): ?array
    {
        $lien = $this->soutien->lien();
        if (null === $lien) {
            return null;
        }

        return [
            'lien' => $lien,
            // L'adresse change avec le lien : un QR gardé par le navigateur ne mène jamais à l'ancien.
            'qr' => $this->soutien->avecQr() ? $this->urls->generate('app_site_soutien_qr', ['v' => substr(hash('sha256', $lien), 0, 10)]) : null,
        ];
    }

    /**
     * Carte « Nous soutenir » du tableau de bord : pour chaque personne,
     * tant qu'elle ne l'a pas fermée.
     *
     * @return array{lien: string, qr: ?string}|null
     */
    #[AsTwigFunction('espace_soutien')]
    public function espaceSoutien(CompteClient $compte): ?array
    {
        return null === $compte->getSoutienMasqueLe() ? $this->soutien() : null;
    }

    /**
     * Invitation sur une commande livrée ou retirée (confirmée, pas
     * annulée), quelle que soit la note donnée à l'atelier.
     *
     * @return array{lien: string, qr: ?string}|null
     */
    #[AsTwigFunction('espace_soutien_commande')]
    public function espaceSoutienCommande(Commandes $commande): ?array
    {
        return Sondages::estLivree($commande) ? $this->soutien() : null;
    }
}
