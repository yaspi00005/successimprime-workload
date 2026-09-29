<?php

namespace App\Service\Statistiques;

use App\Entity\Commandes;
use App\Repository\CommandesRepository;

/**
 * Suivi des commandes non soldees (impayees/partiellement payees),
 * classees par anciennete. Utilise par la page "Statistiques des
 * commandes" et par l'onglet Accueil.
 */
final class StatistiquesCommandesService
{
    private const TRANCHES_ANCIENNETE = [
        ['libelle' => '0 à 7 jours', 'min' => 0, 'max' => 7],
        ['libelle' => '8 à 30 jours', 'min' => 8, 'max' => 30],
        ['libelle' => '31 à 90 jours', 'min' => 31, 'max' => 90],
        ['libelle' => 'Plus de 90 jours', 'min' => 91, 'max' => null],
    ];

    public function __construct(
        private readonly CommandesRepository $commandesRepository
    ) {
    }

    /**
     * @return array{0: list<array{commande: Commandes, reste: int, jours: int}>, 1: list<array{libelle: string, nombre: int, montant: int}>}
     */
    public function commandesNonSoldees(int $limite = 100): array
    {
        $commandes = $this->commandesRepository->findToutesNonSoldees();

        $maintenant = new \DateTimeImmutable('today');

        $lignes = [];

        foreach ($commandes as $commande) {
            if (!$commande instanceof Commandes || $commande->getStatutTravaux() === 'annulee') {
                continue;
            }

            $paye = 0;

            foreach ($commande->getPaiements() as $paiement) {
                if ($paiement->estAnnule()) {
                    continue;
                }

                $paye += (int) $paiement->getMontant();
            }

            $reste = max(0, (int) $commande->getTotalTtc() - $paye);

            if ($reste <= 0) {
                continue;
            }

            $dateCommande = \DateTimeImmutable::createFromInterface($commande->getDateCommande());
            $jours = max(0, $dateCommande->diff($maintenant)->days);

            $lignes[] = [
                'commande' => $commande,
                'reste' => $reste,
                'jours' => $jours,
            ];
        }

        usort($lignes, static fn (array $a, array $b): int => $b['jours'] <=> $a['jours']);

        $parTranche = [];

        foreach (self::TRANCHES_ANCIENNETE as $tranche) {
            $parTranche[] = [
                'libelle' => $tranche['libelle'],
                'nombre' => 0,
                'montant' => 0,
            ];
        }

        foreach ($lignes as $ligne) {
            foreach (self::TRANCHES_ANCIENNETE as $index => $tranche) {
                $dansLaTranche = $ligne['jours'] >= $tranche['min']
                    && ($tranche['max'] === null || $ligne['jours'] <= $tranche['max']);

                if ($dansLaTranche) {
                    ++$parTranche[$index]['nombre'];
                    $parTranche[$index]['montant'] += $ligne['reste'];

                    break;
                }
            }
        }

        return [array_slice($lignes, 0, $limite), $parTranche];
    }
}
