<?php

namespace App\Service;

use App\Entity\Commandes;
use App\Entity\Paiements;
use App\Repository\CommandesRepository;

final class ControleCreditClientService
{
    public function __construct(
        private readonly CommandesRepository $commandesRepository
    ) {
    }

    public function analyser(
        Commandes $commande
    ): array {
        /*
         * ============================================================
         * CLIENT
         * ============================================================
         */

        $client = $commande->getClients();

        if ($client === null) {
            return $this->resultat(
                autorise: false,
                motif: 'Aucun client n’est associé à cette commande.'
            );
        }


        /*
         * ============================================================
         * COMMANDE ACTUELLE
         * ============================================================
         *
         * IMPORTANT :
         *
         * Cette commande est NEUTRE pour le plafond de crédit.
         *
         * Elle sert uniquement à :
         * - connaître son montant ;
         * - connaître l'avance déjà versée ;
         * - appliquer la règle des nouveaux clients.
         *
         * Elle ne sera jamais ajoutée à l'encours antérieur.
         * ============================================================
         */

        $totalCommandeActuelle = max(
            0,
            (int) $commande->getTotalTtc()
        );


        /*
         * ============================================================
         * PAIEMENTS VALIDÉS DE LA COMMANDE ACTUELLE
         * ============================================================
         */

        $avanceCommandeActuelle = 0;

        foreach (
            $commande->getPaiements()
            as $paiement
        ) {
            /*
             * Seuls les paiements réellement validés
             * sont considérés comme encaissés.
             */
            if (
                $paiement->getStatut()
                !== Paiements::STATUT_VALIDE
            ) {
                continue;
            }

            $avanceCommandeActuelle += max(
                0,
                (int) $paiement->getMontant()
            );
        }


        $resteCommandeActuelle = max(
            0,
            $totalCommandeActuelle
            -
            $avanceCommandeActuelle
        );


        /*
         * ============================================================
         * ANCIENNETÉ CLIENT
         * ============================================================
         */

        $maintenant = new \DateTimeImmutable();

        $dateCreation = $client->getCreatedAt();

        $ancienneteMois = 0;

        /*
         * Par sécurité :
         * un client sans date de création est considéré
         * comme récent.
         */
        $clientMoinsDeTroisMois = true;


        if ($dateCreation !== null) {
            if (
                $dateCreation
                instanceof \DateTimeImmutable
            ) {
                $dateCreationImmutable =
                    $dateCreation;
            } else {
                $dateCreationImmutable =
                    \DateTimeImmutable::createFromMutable(
                        $dateCreation
                    );
            }


            $limiteTroisMois =
                $maintenant->modify(
                    '-3 months'
                );


            $clientMoinsDeTroisMois =
                $dateCreationImmutable
                >
                $limiteTroisMois;


            $difference =
                $dateCreationImmutable->diff(
                    $maintenant
                );


            $ancienneteMois =
                ($difference->y * 12)
                +
                $difference->m;
        }


        /*
         * ============================================================
         * PLAFOND DE CRÉDIT
         * ============================================================
         */

        $plafondCredit = max(
            0,
            (int) $client->getPlafondCredit()
        );


        /*
         * ============================================================
         * COMMANDES ANTÉRIEURES
         * ============================================================
         *
         * On récupère les commandes du même client.
         *
         * IMPORTANT :
         *
         * la commande actuellement contrôlée est totalement exclue.
         * ============================================================
         */

        $commandesClient =
            $this
                ->commandesRepository
                ->findBy(
                    [
                        'clients' =>
                            $client,

                        'deleted' =>
                            false,
                    ],
                    [
                        'dateCommande' =>
                            'ASC',
                    ]
                );


        $encoursAnterieur = 0;

        $nombreCommandesAnterieuresImpayees = 0;

        $detailsCommandesAnterieures = [];


        foreach (
            $commandesClient
            as $ancienneCommande
        ) {
            /*
             * ========================================================
             * EXCLUSION ABSOLUE DE LA COMMANDE ACTUELLE
             * ========================================================
             */

            if (
                $ancienneCommande->getId()
                ===
                $commande->getId()
            ) {
                continue;
            }


            /*
             * ========================================================
             * VÉRIFIER QU'ELLE EST RÉELLEMENT ANTÉRIEURE
             * ========================================================
             */

            if (
                !$this->estCommandeAnterieure(
                    $ancienneCommande,
                    $commande
                )
            ) {
                continue;
            }


            /*
             * ========================================================
             * TOTAL ANCIENNE COMMANDE
             * ========================================================
             */

            $totalAncienneCommande = max(
                0,
                (int) $ancienneCommande
                    ->getTotalTtc()
            );


            if (
                $totalAncienneCommande <= 0
            ) {
                continue;
            }


            /*
             * ========================================================
             * PAIEMENTS VALIDÉS UNIQUEMENT
             * ========================================================
             */

            $totalPayeAncienneCommande = 0;


            foreach (
                $ancienneCommande->getPaiements()
                as $paiement
            ) {
                if (
                    $paiement->getStatut()
                    !==
                    Paiements::STATUT_VALIDE
                ) {
                    continue;
                }


                $totalPayeAncienneCommande += max(
                    0,
                    (int) $paiement->getMontant()
                );
            }


            /*
             * ========================================================
             * RESTE À PAYER DE L'ANCIENNE COMMANDE
             * ========================================================
             */

            $resteAncienneCommande = max(
                0,
                $totalAncienneCommande
                -
                $totalPayeAncienneCommande
            );


            /*
             * Commande soldée :
             * aucune dette.
             */
            if (
                $resteAncienneCommande <= 0
            ) {
                continue;
            }


            /*
             * ========================================================
             * ENCOURS ANTÉRIEUR
             * ========================================================
             */

            $encoursAnterieur +=
                $resteAncienneCommande;


            ++$nombreCommandesAnterieuresImpayees;


            /*
             * Informations utiles pour affichage/audit.
             */
            $detailsCommandesAnterieures[] = [
                'id' =>
                    $ancienneCommande->getId(),

                'numero' =>
                    method_exists(
                        $ancienneCommande,
                        'getNumero'
                    )
                        ? $ancienneCommande->getNumero()
                        : null,

                'date' =>
                    $ancienneCommande
                        ->getDateCommande(),

                'total' =>
                    $totalAncienneCommande,

                'paye' =>
                    $totalPayeAncienneCommande,

                'reste' =>
                    $resteAncienneCommande,
            ];
        }


        /*
         * ============================================================
         * CRÉDIT DISPONIBLE
         * ============================================================
         *
         * La commande actuelle n'est PAS déduite ici.
         * ============================================================
         */

        $creditDisponible = max(
            0,
            $plafondCredit
            -
            $encoursAnterieur
        );


        /*
         * ============================================================
         * DÉPASSEMENT
         * ============================================================
         */

        $depassement = max(
            0,
            $encoursAnterieur
            -
            $plafondCredit
        );


        /*
         * ============================================================
         * RÈGLE 2
         *
         * ENCOURS DES COMMANDES ANTÉRIEURES
         * >
         * PLAFOND CLIENT
         *
         * => BLOQUÉ
         * ============================================================
         */

        if (
            $encoursAnterieur
            >
            $plafondCredit
        ) {
            return $this->resultat(
                autorise: false,

                motif: sprintf(
                    'Production bloquée : le client possède %s FCFA d’encours sur ses commandes antérieures, pour un plafond de crédit de %s FCFA.',
                    number_format(
                        $encoursAnterieur,
                        0,
                        ',',
                        ' '
                    ),
                    number_format(
                        $plafondCredit,
                        0,
                        ',',
                        ' '
                    )
                ),

                ancienneteMois:
                    $ancienneteMois,

                clientRecent:
                    $clientMoinsDeTroisMois,

                plafondCredit:
                    $plafondCredit,

                encoursAnterieur:
                    $encoursAnterieur,

                creditDisponible:
                    $creditDisponible,

                depassement:
                    $depassement,

                totalCommande:
                    $totalCommandeActuelle,

                avanceCommande:
                    $avanceCommandeActuelle,

                resteCommande:
                    $resteCommandeActuelle,

                nombreCommandesAnterieuresImpayees:
                    $nombreCommandesAnterieuresImpayees,

                commandesAnterieures:
                    $detailsCommandesAnterieures
            );
        }


        /*
         * ============================================================
         * AUTORISATION
         * ============================================================
         */

        return $this->resultat(
            autorise: true,

            motif: sprintf(
                'Production autorisée : l’encours antérieur du client est de %s FCFA sur un plafond de %s FCFA. La commande actuelle est exclue de ce calcul.',
                number_format(
                    $encoursAnterieur,
                    0,
                    ',',
                    ' '
                ),
                number_format(
                    $plafondCredit,
                    0,
                    ',',
                    ' '
                )
            ),

            ancienneteMois:
                $ancienneteMois,

            clientRecent:
                $clientMoinsDeTroisMois,

            plafondCredit:
                $plafondCredit,

            encoursAnterieur:
                $encoursAnterieur,

            creditDisponible:
                $creditDisponible,

            depassement:
                0,

            totalCommande:
                $totalCommandeActuelle,

            avanceCommande:
                $avanceCommandeActuelle,

            resteCommande:
                $resteCommandeActuelle,

            nombreCommandesAnterieuresImpayees:
                $nombreCommandesAnterieuresImpayees,

            commandesAnterieures:
                $detailsCommandesAnterieures
        );
    }


    /*
     * ================================================================
     * COMMANDE ANTÉRIEURE ?
     * ================================================================
     *
     * On utilise :
     *
     * 1. dateCommande
     * 2. puis ID pour départager deux commandes créées
     *    exactement à la même date.
     * ================================================================
     */

    private function estCommandeAnterieure(
        Commandes $ancienneCommande,
        Commandes $commandeActuelle
    ): bool {
        $dateAncienne =
            $ancienneCommande
                ->getDateCommande();

        $dateActuelle =
            $commandeActuelle
                ->getDateCommande();


        /*
         * Si les deux dates existent.
         */
        if (
            $dateAncienne !== null
            &&
            $dateActuelle !== null
        ) {
            if (
                $dateAncienne
                <
                $dateActuelle
            ) {
                return true;
            }


            if (
                $dateAncienne
                >
                $dateActuelle
            ) {
                return false;
            }


            /*
             * Même date / même heure :
             * l'identifiant le plus ancien gagne.
             */
            return (
                $ancienneCommande->getId()
                ??
                PHP_INT_MAX
            )
            <
            (
                $commandeActuelle->getId()
                ??
                PHP_INT_MAX
            );
        }


        /*
         * Si les dates ne permettent pas
         * de départager :
         * utilisation de l'identifiant.
         */
        return (
            $ancienneCommande->getId()
            ??
            PHP_INT_MAX
        )
        <
        (
            $commandeActuelle->getId()
            ??
            PHP_INT_MAX
        );
    }


    /*
     * ================================================================
     * FORMAT STANDARD DU RÉSULTAT
     * ================================================================
     */

    private function resultat(
        bool $autorise,
        string $motif,

        int $ancienneteMois = 0,
        bool $clientRecent = false,

        int $plafondCredit = 0,

        int $encoursAnterieur = 0,
        int $creditDisponible = 0,
        int $depassement = 0,

        int $totalCommande = 0,
        int $avanceCommande = 0,
        int $resteCommande = 0,

        int $nombreCommandesAnterieuresImpayees = 0,

        array $commandesAnterieures = []
    ): array {
        return [
            'autorise' =>
                $autorise,

            'motif' =>
                $motif,

            'ancienneteMois' =>
                $ancienneteMois,

            'clientRecent' =>
                $clientRecent,

            'plafondCredit' =>
                $plafondCredit,

            /*
             * UNIQUEMENT les anciennes commandes.
             */
            'encoursAnterieur' =>
                $encoursAnterieur,

            'creditDisponible' =>
                $creditDisponible,

            'depassement' =>
                $depassement,

            /*
             * Informations commande actuelle.
             *
             * Elles sont affichables,
             * mais NE PARTICIPENT PAS au plafond.
             */
            'totalCommande' =>
                $totalCommande,

            'avanceCommande' =>
                $avanceCommande,

            'resteCommande' =>
                $resteCommande,

            'nombreCommandesAnterieuresImpayees' =>
                $nombreCommandesAnterieuresImpayees,

            'commandesAnterieures' =>
                $commandesAnterieures,
        ];
    }
}