<?php

namespace App\Service;

use App\Entity\CommandeDetailFinition;
use App\Entity\DevisDetailFinition;
use App\Entity\Finition;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Montant d'une finition quand un devis ou une commande est enregistré
 * depuis la saisie du comptoir (finitions « manuelles » : prix x quantité,
 * le prix seul au forfait).
 *
 * Une finition déjà enregistrée dont ni la finition, ni le prix, ni le
 * mode, ni la quantité n'ont changé garde son montant : il a pu être
 * calculé autrement à sa création (commande en ligne, ancienne ligne
 * « automatique » : prix x surface x exemplaires), et un simple
 * « Enregistrer » ne doit pas changer le prix d'une commande déjà payée.
 */
final class MontantFinitionSaisie
{
    /**
     * @param array{finition: ?int, prixApplique: int, modeCalcul: string, quantite: int} $saisie
     * @param array<string, mixed> $origine valeurs lues en base, avec « montant » (vide pour une nouvelle finition)
     */
    public static function calculer(array $saisie, array $origine): int
    {
        if (
            [] !== $origine
            && ($origine['finition'] ?? null) === $saisie['finition']
            && (int) ($origine['prixApplique'] ?? -1) === $saisie['prixApplique']
            && (string) ($origine['modeCalcul'] ?? '') === $saisie['modeCalcul']
            && (int) ($origine['quantite'] ?? -1) === $saisie['quantite']
        ) {
            return (int) ($origine['montant'] ?? 0);
        }

        return 'forfait' === $saisie['modeCalcul']
            ? $saisie['prixApplique']
            : $saisie['prixApplique'] * $saisie['quantite'];
    }

    /**
     * Valeurs de la finition telles que lues en base (vide si elle est nouvelle).
     *
     * @return array<string, mixed>
     */
    public static function origine(CommandeDetailFinition|DevisDetailFinition $finition, EntityManagerInterface $entityManager): array
    {
        if (null === $finition->getId()) {
            return [];
        }

        $donnees = $entityManager->getUnitOfWork()->getOriginalEntityData($finition);

        if ([] === $donnees) {
            return [];
        }

        $finitionAvant = $donnees['finition'] ?? null;

        return [
            'finition' => $finitionAvant instanceof Finition ? $finitionAvant->getId() : null,
            'prixApplique' => $donnees['prixApplique'] ?? null,
            'modeCalcul' => $donnees['modeCalcul'] ?? null,
            'quantite' => $donnees['quantite'] ?? null,
            'montant' => $donnees['montant'] ?? null,
        ];
    }
}
