<?php

namespace App\Twig;

use App\Entity\Commandes;
use App\Service\NotificationsEquipe\AccordsPlafond;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fenêtre bloquante « Plafond de crédit dépassé » (maquette NT02) : ses
 * données, pour la commande refusée à la confirmation (message flash
 * « plafond_bloquant », gabarit notification/_plafond_bloquant.html.twig).
 */
final class PlafondExtension extends AbstractExtension
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccordsPlafond $accords,
        private readonly Security $security,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('fenetre_plafond', $this->fenetre(...))];
    }

    /** @return array<string, mixed>|null */
    public function fenetre(mixed $id): ?array
    {
        $commande = is_numeric($id) ? $this->em->find(Commandes::class, (int) $id) : null;
        if (!$commande instanceof Commandes || !$this->security->isGranted('ROLE_COMMANDE')) {
            return null;
        }
        $analyse = $this->accords->analyser($commande);

        return [
            'commande' => $commande,
            'texte' => AccordsPlafond::texte($analyse, $commande->getClients()?->getNomComplet() ?? 'Le client', $this->security->isGranted('ROLE_PAIEMENT_VOIR')),
            'peutEncaisser' => $this->security->isGranted('ROLE_PAIEMENT_ENCAISSER'),
            'direction' => $this->security->isGranted('ROLE_ADMIN'),
            'enCours' => $this->accords->demandeEnCours($commande),
        ];
    }
}
