<?php

namespace App\Service\Atelier;

use App\Entity\JournalMachine;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\StockSorties;
use App\Entity\User;
use App\Service\NotificationService;
use App\Service\NotificationsEquipe\NotificationsEquipe;
use App\Service\StockService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gestes de l'opérateur sur un ordre de production (maquette AT05) :
 * démarrer, pause, reprendre, terminer avec la gâche. Même suite que les
 * écrans de production du poste (ProductionController) : ligne de commande
 * et ordre ensemble, sortie de stock sur la quantité traitée, compteur
 * d'usure de la machine, notification de fin.
 */
final class ActionsOrdre
{
    /** Motifs de gâche proposés à la fin d'un travail. */
    public const MOTIFS_GACHE = ['reglage' => 'Réglage', 'bourrage' => 'Bourrage', 'couleur' => 'Couleur', 'tete' => 'Tête bouchée', 'autre' => 'Autre'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockService $stock,
        private readonly NotificationService $notifications,
        private readonly ?NotificationsEquipe $equipe = null,
    ) {
    }

    /** $machine : celle choisie au démarrage (poste de production, poste machiniste), sinon celle de l'ordre. */
    public function demarrer(OrdreProduction $ordre, User $operateur, ?Machines $machine = null): void
    {
        $machine ??= $ordre->getMachine();
        if (!$ordre->estADemarrer()) {
            throw new \LogicException('Seul un travail en attente peut être démarré.');
        }
        $detail = $ordre->getCommandeDetail() ?? throw new \LogicException('Aucune ligne de commande n\'est liée à ce travail.');
        // Une pièce à refaire (contrôle final, PD06) garde ce qui a déjà été produit.
        if ($ordre->getPiecesARefaire() !== null) {
            $detail->demarrerReprise($operateur, $machine);
        } else {
            $detail->demarrerProduction($operateur, $machine);
        }
        $ordre->demarrer($operateur, $machine);
        $this->em->flush();
    }

    public function pause(OrdreProduction $ordre): void
    {
        $ordre->mettreEnPause();
        $this->em->flush();
    }

    public function reprendre(OrdreProduction $ordre, User $operateur): void
    {
        if (!$ordre->estEnPause()) {
            throw new \LogicException('Seul un travail en pause peut être repris.');
        }
        $ordre->demarrer($operateur, $ordre->getMachine());
        $this->em->flush();
    }

    /**
     * Termine un travail : $imprime pièces sorties de la machine, dont $gache
     * pièces à jeter. La gâche est aussi notée au journal de la machine (en
     * m² quand la pièce a des dimensions) avec son motif.
     */
    public function terminer(OrdreProduction $ordre, User $operateur, int $imprime, int $gache, ?string $motif, ?string $observation): void
    {
        if (!$ordre->estEnCours()) {
            throw new \LogicException('Le travail doit être en cours pour être terminé (reprenez-le s\'il est en pause).');
        }
        if ($imprime < 1 || $gache < 0 || $gache > $imprime) {
            throw new \InvalidArgumentException('Indiquez les pièces imprimées et la gâche (pas plus que l\'imprimé).');
        }
        $detail = $ordre->getCommandeDetail() ?? throw new \LogicException('Aucune ligne de commande n\'est liée à ce travail.');
        if (!$detail->estEnProduction()) {
            throw new \LogicException('La ligne de commande n\'est pas en production.');
        }
        $libelleMotif = null !== $motif ? (self::MOTIFS_GACHE[$motif] ?? null) : null;
        $note = trim(implode(' · ', array_filter([$gache > 0 && null !== $libelleMotif ? 'Gâche : '.$libelleMotif : null, trim((string) $observation)])));

        // Une reprise sort sa matière sous sa propre référence (PROD-000123-R1) : la sortie du premier passage n'est pas rejouée.
        $reprise = $ordre->getPiecesARefaire() !== null;
        $this->stock->consommerPourDetail($detail, StockSorties::ORIGINE_PRODUCTION, $ordre->referenceStock(), (float) $imprime);
        $detail->terminerProduction($operateur, $imprime + ($reprise ? $detail->getQuantiteProduite() : 0), $gache + ($reprise ? $detail->getQuantiteRebut() : 0), $note);
        $ordre->terminer($operateur, $imprime, $gache, $note);

        $machine = $ordre->getMachine();
        $surfacePiece = self::surfacePiece($ordre);
        if ($machine instanceof Machines) {
            $machine->enregistrerUsage($surfacePiece * $imprime, (float) $imprime);
            if ($gache > 0) {
                $this->em->persist(new JournalMachine($machine, $operateur, JournalMachine::GACHE, new \DateTimeImmutable(), $ordre, $surfacePiece > 0 ? round($surfacePiece * $gache, 2) : null, mb_substr(sprintf('%d pièce%s%s', $gache, $gache > 1 ? 's' : '', null !== $libelleMotif ? ' · '.$libelleMotif : ''), 0, 255)));
            }
        }

        $commande = $detail->getCommande();
        if (null !== $commande) {
            $this->commandePrete($commande);
        }
        $this->em->flush();
    }

    /** Notification « Commande prête » (niveau info) aux équipes livraison et commerciale. */
    private function commandePrete(\App\Entity\Commandes $commande): void
    {
        $numero = $commande->getNumero() ?? ('#'.$commande->getId());
        if (null !== $this->equipe) {
            $this->equipe->notifier('commande_prete', sprintf('Commande %s prête', $numero), ['ROLE_LIVRAISON', 'ROLE_COMMERCIAL'], 'Production terminée : prête pour la suite.', 'Voir', 'app_commandes_show', ['id' => $commande->getId()], 'commande_prete:'.$commande->getId());

            return;
        }
        $this->notifications->notifierRoles(['ROLE_LIVRAISON', 'ROLE_COMMERCIAL'], sprintf('Production terminée pour la commande %s : prête pour la suite.', $numero), 'app_commandes_show', ['id' => $commande->getId()]);
    }

    /** Surface d'une pièce en m² : dimensions de la ligne, sinon celles du format. */
    public static function surfacePiece(OrdreProduction $ordre): float
    {
        $detail = $ordre->getCommandeDetail();
        if (null === $detail) {
            return 0.0;
        }
        $largeur = (float) ($detail->getLargeur() ?? 0);
        $longueur = (float) ($detail->getLongueur() ?? 0);

        return $largeur > 0 && $longueur > 0 ? $largeur * $longueur : ($detail->getFormat()?->surfaceM2() ?? 0.0);
    }
}
