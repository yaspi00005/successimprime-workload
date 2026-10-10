<?php

namespace App\Service;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\Paiements;
use App\Entity\User;

/**
 * Fiche d'une commande (maquette C02) : etapes de suivi, etat de chaque
 * ligne et historique, reconstruits a partir des donnees de la commande.
 */
final class SuiviCommandeService
{
    private const RANG_LIGNE = [
        'pao' => 0,
        'bat' => 1,
        'impression' => 2,
        'finition' => 3,
        'prete' => 4,
        'sans_production' => 4,
        'en_livraison' => 5,
        'livree' => 6,
    ];

    /**
     * Les 7 etapes de la maquette, chacune 'faite', 'en_cours' ou 'a_venir'.
     *
     * @return list<array{libelle: string, detail: ?string, etat: string}>
     */
    public function etapes(Commandes $commande): array
    {
        $lignes = $this->lignesActives($commande);
        $rangs = array_map(fn (CommandesDetails $ligne): int => self::RANG_LIGNE[$this->etapeLigne($ligne)], $lignes);
        $rangMin = [] === $rangs ? 0 : min($rangs);

        $avecBat = array_values(array_filter($lignes, static fn (CommandesDetails $ligne): bool => $ligne->isPrePresseNecessaire()));
        $batValides = \count(array_filter($avecBat, fn (CommandesDetails $ligne): bool => $this->batValide($ligne)));
        $premierPaiement = $this->paiementsValides($commande)[0] ?? null;

        $faites = [
            true,
            null !== $premierPaiement,
            [] !== $lignes && $rangMin >= 1,
            [] !== $lignes && $batValides === \count($avecBat) && $rangMin >= 2,
            [] !== $lignes && $rangMin >= 3,
            [] !== $lignes && $rangMin >= 4,
            [] !== $lignes && $rangMin >= 6,
        ];

        $etapes = [
            ['libelle' => 'Confirmée', 'detail' => $commande->getDateCommande()?->format('d/m')],
            ['libelle' => 'Acompte payé', 'detail' => $premierPaiement?->getDate()?->format('d/m')],
            ['libelle' => 'PAO', 'detail' => null],
            ['libelle' => 'BAT validés', 'detail' => [] === $avecBat ? 'sans BAT' : sprintf('%d / %d', $batValides, \count($avecBat))],
            ['libelle' => 'Impression', 'detail' => null],
            ['libelle' => 'Finition', 'detail' => null],
            ['libelle' => 'Livrée', 'detail' => null],
        ];

        return self::marquerEtapes($etapes, $faites);
    }

    /**
     * L'etape « en cours » est la premiere non faite ; celles d'avant sont
     * faites meme si une etape facultative (l'acompte) manque.
     *
     * @param list<array{libelle: string, detail: ?string}> $etapes
     * @param list<bool>                                     $faites
     *
     * @return list<array{libelle: string, detail: ?string, etat: string}>
     */
    public static function marquerEtapes(array $etapes, array $faites): array
    {
        $courante = null;

        foreach ($faites as $rang => $faite) {
            if (!$faite && 1 !== $rang) {
                $courante = $rang;

                break;
            }
        }

        foreach ($etapes as $rang => &$etape) {
            $etape['etat'] = match (true) {
                $faites[$rang] => 'faite',
                $rang === $courante => 'en_cours',
                default => 'a_venir',
            };
        }

        return $etapes;
    }

    /**
     * Etape d'une ligne pour la fiche et ses etiquettes.
     *
     * @return array{etape: string, libelle: string, bat: ?string}
     */
    public function ligne(CommandesDetails $ligne): array
    {
        $etape = $this->etapeLigne($ligne);
        $controle = $this->dernierControle($ligne);

        $bat = null;

        if ($ligne->isPrePresseNecessaire()) {
            $bat = match (true) {
                $this->batValide($ligne) => 'BAT validé'.($controle?->getValideLe() ? ' '.$controle->getValideLe()->format('d/m H:i') : ''),
                ControlePrePresse::STATUT_EN_ATTENTE_BAT === $controle?->getStatut() => 'BAT envoyé',
                ControlePrePresse::STATUT_CORRECTION_REQUISE === $controle?->getStatut() => 'Correction demandée',
                default => 'BAT à envoyer',
            };
        }

        return [
            'etape' => $etape,
            'libelle' => match ($etape) {
                'pao' => 'PAO',
                'bat' => 'BAT envoyé',
                'impression' => CommandesDetails::PRODUCTION_EN_COURS === $ligne->getStatutProduction() ? 'Impression' : 'À imprimer',
                'finition' => 'Finition',
                'prete', 'sans_production' => 'Prête',
                'en_livraison' => 'En livraison',
                'livree' => 'Livrée',
                default => 'Annulée',
            },
            'bat' => $bat,
        ];
    }

    /**
     * Evenements de la commande, du plus recent au plus ancien. Les
     * paiements n'y figurent que pour qui a le droit de les voir.
     *
     * @return list<array{date: \DateTimeInterface, texte: string}>
     */
    public function historique(Commandes $commande, bool $avecPaiements = true): array
    {
        $evenements = [];

        if ($commande->getDateCommande()) {
            $evenements[] = ['date' => $commande->getDateCommande(), 'texte' => 'Commande créée'.self::par($commande->getAgents())];
        }

        foreach ($avecPaiements ? $commande->getPaiements() : [] as $paiement) {
            if (!$paiement->getDate() || $paiement->estAnnule()) {
                continue;
            }

            $evenements[] = [
                'date' => $paiement->getDate(),
                'texte' => sprintf(
                    '%s F reçus en %s%s',
                    number_format($paiement->getMontant(), 0, ',', ' '),
                    Paiements::MODES_LABELS[$paiement->getMode()] ?? $paiement->getMode(),
                    $paiement->estValide() ? ', validés' : ($paiement->estRejete() ? ', rejetés' : ', en attente de validation')
                ),
            ];
        }

        foreach ($commande->getCommandesDetails() as $ligne) {
            $nom = $ligne->getDesignation() ?: 'Travail';

            foreach ($ligne->getControlesPrePresse() as $controle) {
                if ($controle->getTraiteLe() && ControlePrePresse::STATUT_EN_ATTENTE_BAT === $controle->getStatut()) {
                    $evenements[] = ['date' => $controle->getTraiteLe(), 'texte' => sprintf('BAT envoyé au client : %s', $nom)];
                }

                if ($controle->getValideLe()) {
                    $evenements[] = ['date' => $controle->getValideLe(), 'texte' => sprintf('BAT validé : %s', $nom)];
                }
            }

            if ($ligne->getProductionDebuteLe()) {
                $evenements[] = ['date' => $ligne->getProductionDebuteLe(), 'texte' => sprintf('Impression lancée : %s%s', $nom, self::par($ligne->getProductionDebuteePar()))];
            }

            if ($ligne->getProductionTermineeLe()) {
                $evenements[] = ['date' => $ligne->getProductionTermineeLe(), 'texte' => sprintf('Production terminée : %s', $nom)];
            }
        }

        usort($evenements, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return $evenements;
    }

    /**
     * @return list<Paiements>
     */
    public function paiementsValides(Commandes $commande): array
    {
        $paiements = array_values(array_filter(
            $commande->getPaiements()->toArray(),
            static fn (Paiements $paiement): bool => $paiement->estValide()
        ));

        usort($paiements, static fn (Paiements $a, Paiements $b): int => $a->getDate() <=> $b->getDate());

        return $paiements;
    }

    /**
     * @return list<CommandesDetails>
     */
    private function lignesActives(Commandes $commande): array
    {
        return array_values(array_filter(
            $commande->getCommandesDetails()->toArray(),
            static fn (CommandesDetails $ligne): bool => CommandesDetails::PRODUCTION_ANNULEE !== $ligne->getStatutProduction()
        ));
    }

    private function etapeLigne(CommandesDetails $ligne): string
    {
        $statut = $ligne->getStatutProduction();

        return match (true) {
            CommandesDetails::PRODUCTION_ANNULEE === $statut => 'annulee',
            CommandesDetails::PRODUCTION_LIVREE === $statut => 'livree',
            CommandesDetails::PRODUCTION_EN_LIVRAISON === $statut => 'en_livraison',
            CommandesDetails::PRODUCTION_NON_REQUISE === $statut, !$ligne->isProductionNecessaire() => 'sans_production',
            default => TableauDeBordService::colonnePourLigne(
                $statut,
                $ligne->isPrePresseNecessaire(),
                $ligne->isBatValide(),
                $this->dernierControle($ligne)?->getStatut(),
            ),
        };
    }

    private function batValide(CommandesDetails $ligne): bool
    {
        return $ligne->isBatValide() || \in_array(
            $this->dernierControle($ligne)?->getStatut(),
            [ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION],
            true
        );
    }

    private function dernierControle(CommandesDetails $ligne): ?ControlePrePresse
    {
        $dernier = null;

        foreach ($ligne->getControlesPrePresse() as $controle) {
            if (null === $dernier || $controle->getId() > $dernier->getId()) {
                $dernier = $controle;
            }
        }

        return $dernier;
    }

    private static function par(?User $utilisateur): string
    {
        if (null === $utilisateur) {
            return '';
        }

        return ' par '.($utilisateur->getEmploye()?->getPrenom() ?: $utilisateur->getUsername());
    }
}
