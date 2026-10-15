<?php

namespace App\Twig;

use App\Entity\Annonce;
use App\Repository\AnnonceRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Bandes d'annonces en haut du site public et de l'espace client
 * (site/_annonces.html.twig). Sans annonce à montrer, la page n'a ni
 * style, ni script, ni bande en plus.
 */
final class AnnoncesExtension extends AbstractExtension implements ResetInterface
{
    /** @var array<string, list<Annonce>> déjà lues pendant cette requête */
    private array $lues = [];

    public function __construct(
        private readonly AnnonceRepository $annonces,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Une requête suivante du même processus relit les annonces. */
    public function reset(): void
    {
        $this->lues = [];
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('site_annonces', $this->visibles(...))];
    }

    /**
     * Annonces à montrer aujourd'hui (Annonce::SITE ou Annonce::ESPACE).
     * Une base pas encore à jour ne casse jamais la page : pas de bande.
     *
     * @return list<Annonce>
     */
    public function visibles(string $cible): array
    {
        if (!\in_array($cible, [Annonce::SITE, Annonce::ESPACE], true)) {
            return [];
        }
        try {
            return $this->lues[$cible] ??= $this->annonces->visibles($cible, new \DateTimeImmutable());
        } catch (\Throwable $e) {
            $this->logger->warning('Annonces non lues : {erreur}', ['erreur' => $e->getMessage()]);

            return $this->lues[$cible] = [];
        }
    }
}
