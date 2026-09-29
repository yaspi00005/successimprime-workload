<?php

namespace App\Service;

use App\Entity\Commandes;
use App\Entity\MouvementTresorerie;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Calcule l'evolution (chiffre d'affaires, nombre de commandes,
 * encaissements, decaissements) sur une fenetre glissante decoupee
 * par jour/semaine/mois/annee. Utilise par le tableau de bord
 * d'accueil et par les ecrans de statistiques detailles, pour
 * eviter de dupliquer ces requetes.
 */
final class EvolutionFinanciereService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EvolutionTemporelleService $evolutionTemporelleService
    ) {
    }

    /**
     * @return array{labels: list<string>, ca: list<int>, commandes: list<int>, encaissements: list<int>, decaissements: list<int>}
     */
    public function calculer(string $granularite, ?int $nombrePoints = null): array
    {
        /*
         * En vue "année", on affiche tout l'historique disponible
         * plutôt qu'une fenêtre fixe de 5 ans : sinon les données les
         * plus anciennes (import, reprise d'historique...) restent
         * invisibles même si elles existent bien en base.
         */
        if ($nombrePoints === null && $granularite === 'annee') {
            $anneeActuelle = (int) (new \DateTimeImmutable('now'))->format('Y');
            $nombrePoints = max(1, min(30, $anneeActuelle - $this->premiereAnneeAvecDonnees() + 1));
        }

        [$cles, $labels, $debutFenetre] = $this->evolutionTemporelleService->genererPaniers(
            $granularite,
            $nombrePoints
        );

        $ca = array_fill_keys($cles, 0);
        $commandes = array_fill_keys($cles, 0);
        $encaissements = array_fill_keys($cles, 0);
        $decaissements = array_fill_keys($cles, 0);

        /*
         * ============================================================
         * NOMBRE DE COMMANDES
         * ============================================================
         *
         * Simple comptage : reste basé sur la table Commandes, seule
         * source pour ce nombre. Le suivi détaillé "commande par
         * commande" n'existe que depuis sa mise en place -- le nombre
         * de commandes est donc naturellement nul avant cette date,
         * contrairement à la trésorerie qui remonte plus loin.
         * ============================================================
         */

        $listeCommandes = $this->entityManager
            ->getRepository(Commandes::class)
            ->createQueryBuilder('c')
            ->andWhere('c.dateCommande >= :debut')
            ->andWhere('c.deleted = false')
            ->setParameter('debut', $debutFenetre)
            ->getQuery()
            ->getResult();

        foreach ($listeCommandes as $commande) {
            if (!$commande instanceof Commandes || $commande->getStatutTravaux() === 'annulee') {
                continue;
            }

            $cle = $this->evolutionTemporelleService->clePourDate($commande->getDateCommande(), $granularite);

            if (isset($commandes[$cle])) {
                ++$commandes[$cle];
            }
        }

        /*
         * ============================================================
         * CHIFFRE D'AFFAIRES / ENCAISSEMENTS / DÉCAISSEMENTS
         * ============================================================
         *
         * Tout vient de MouvementTresorerie (et non de Commandes ou
         * Paiements) : le suivi détaillé des commandes n'existe que
         * depuis sa mise en place récente, alors que la trésorerie
         * (journal de caisse) remonte à l'historique complet -- s'appuyer
         * sur les commandes rendait le CA nul avant cette date alors que
         * l'argent, lui, avait bien été encaissé. Le chiffre d'affaires
         * retenu est celui des encaissements "produit" (estProduit()) :
         * ventes et autres produits réels, hors transferts internes et
         * ajustements neutres.
         * ============================================================
         */

        $listeMouvements = $this->entityManager
            ->getRepository(MouvementTresorerie::class)
            ->createQueryBuilder('m')
            ->andWhere('m.dateOperation >= :debut')
            ->andWhere('m.type IN (:types)')
            ->setParameter('debut', $debutFenetre)
            ->setParameter('types', [
                MouvementTresorerie::TYPE_ENCAISSEMENT,
                MouvementTresorerie::TYPE_DECAISSEMENT,
            ])
            ->getQuery()
            ->getResult();

        foreach ($listeMouvements as $mouvement) {
            if (!$mouvement instanceof MouvementTresorerie || !$mouvement->isValide()) {
                continue;
            }

            $cle = $this->evolutionTemporelleService->clePourDate($mouvement->getDateOperation(), $granularite);
            $montant = (int) $mouvement->getMontant();

            if ($mouvement->getType() === MouvementTresorerie::TYPE_ENCAISSEMENT) {
                if (isset($encaissements[$cle])) {
                    $encaissements[$cle] += $montant;
                }

                if ($mouvement->estProduit() && isset($ca[$cle])) {
                    $ca[$cle] += $montant;
                }
            } elseif (isset($decaissements[$cle])) {
                $decaissements[$cle] += $montant;
            }
        }

        return [
            'labels' => $labels,
            'ca' => array_values($ca),
            'commandes' => array_values($commandes),
            'encaissements' => array_values($encaissements),
            'decaissements' => array_values($decaissements),
        ];
    }

    /**
     * Année de la donnée la plus ancienne (commande ou mouvement de
     * trésorerie), pour dimensionner la fenêtre "année" sur
     * l'historique réel plutôt que sur un nombre fixe d'années.
     */
    private function premiereAnneeAvecDonnees(): int
    {
        $anneeActuelle = (int) (new \DateTimeImmutable('now'))->format('Y');

        $premiereDateCommande = $this->entityManager
            ->getRepository(Commandes::class)
            ->createQueryBuilder('c')
            ->select('MIN(c.dateCommande)')
            ->andWhere('c.deleted = false')
            ->getQuery()
            ->getSingleScalarResult();

        $premiereDateMouvement = $this->entityManager
            ->getRepository(MouvementTresorerie::class)
            ->createQueryBuilder('m')
            ->select('MIN(m.dateOperation)')
            ->getQuery()
            ->getSingleScalarResult();

        $dates = array_filter([$premiereDateCommande, $premiereDateMouvement]);

        if ($dates === []) {
            return $anneeActuelle;
        }

        try {
            $anneeMin = min(array_map(
                static fn (string $date): int => (int) (new \DateTimeImmutable($date))->format('Y'),
                $dates
            ));
        } catch (\Exception) {
            return $anneeActuelle;
        }

        return min($anneeMin, $anneeActuelle);
    }
}
