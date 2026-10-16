<?php

namespace App\Service\EspaceClient;

use App\Entity\Commandes;
use App\Entity\Factures;

/**
 * Une ligne de « À payer » (maquette FP01) : la facture d'une commande, son
 * acompte, son reste quand elle est prête, ou le solde d'ouverture. Le
 * montant demandé tient compte de ce qui attend déjà la validation de la
 * boutique ; il est recalculé par le serveur à chaque paiement.
 */
final class ElementAPayer
{
    public const FACTURE = 'facture';
    public const ACOMPTE = 'acompte';
    public const COMMANDE = 'commande';
    public const SOLDE = 'solde';

    /** État de l'échéance : la couleur de la ligne et de sa pastille. */
    public const ECHUE = 'echue';
    public const AUJOURDHUI = 'aujourdhui';
    public const A_VENIR = 'a_venir';
    public const A_PAYER = 'a_payer';

    public function __construct(
        public readonly Commandes $commande,
        public readonly ?Factures $facture,
        public readonly string $nature,
        /** Ce qui est demandé maintenant. */
        public readonly int $montant,
        /** Ce qui reste dû sur la commande, hors paiements en validation. */
        public readonly int $reste,
        public readonly \DateTimeImmutable $echeance,
        public readonly string $etat,
        public readonly ?int $pourcentage = null,
        /** L'atelier a commencé : l'acompte ne « lance » plus la production. */
        public readonly bool $productionLancee = false,
    ) {
    }

    /** Clé du formulaire : la commande, par son identifiant public. */
    public function cle(): string
    {
        return self::cleDe($this->commande);
    }

    public static function cleDe(Commandes $commande): string
    {
        return 'c-'.$commande->getPublicId()->toRfc4122();
    }

    public function estFacture(): bool { return self::FACTURE === $this->nature; }
    public function estAcompte(): bool { return self::ACOMPTE === $this->nature; }
    public function estSolde(): bool { return self::SOLDE === $this->nature; }

    /** Le montant demandé n'est qu'une partie de la commande (acompte). */
    public function estPartiel(): bool
    {
        return $this->montant < $this->commande->getTotalTtc();
    }

    /** Ce que la boutique a déjà validé sur la commande. */
    public function dejaPaye(): int
    {
        return $this->commande->getTotalPaye();
    }
}
