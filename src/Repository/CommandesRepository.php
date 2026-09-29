<?php

namespace App\Repository;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

final class CommandesRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Commandes::class);
    }

    /**
     * Toutes les commandes non soldées (impayées ou partiellement
     * payées), quelle que soit leur ancienneté : utilisé par
     * RappelPaiementService, qui calcule lui-même l'ancienneté en
     * jours de chaque commande pour savoir si une règle de rappel
     * "tous les N jours" tombe aujourd'hui. Le tri par statutTravaux
     * (annulée exclue) se fait ensuite en PHP, comme pour le PDF des
     * impayés (getStatutTravaux() est calculé, pas une colonne).
     *
     * @return array<int, Commandes>
     */
    public function findToutesNonSoldees(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.clients', 'cl')
            ->addSelect('cl')
            ->andWhere('c.deleted = :deleted')
            ->andWhere('c.statutPaiement != :statutPaye')
            ->setParameter('deleted', false)
            ->setParameter('statutPaye', Commandes::PAIEMENT_PAYE)
            ->getQuery()
            ->getResult();
    }

    /**
     * Sans recherche :
     * - statut = true : paiement en attente ;
     * - ou etat = true : travaux en cours.
     *
     * Avec une recherche active, toutes les commandes peuvent être retrouvées.
     */
    public function rechercherPourIndex(array $filtres): array
    {
        $qb = $this->createQueryBuilder('c')
            ->leftJoin('c.clients', 'cl')
            ->addSelect('cl')
            ->leftJoin('c.commandesDetails', 'd')
            ->addSelect('d')
            ->andWhere('c.deleted = false')
            ->distinct();

        $rechercheActive = $this->rechercheEstActive($filtres);
        $affichage = $filtres['affichage'] ?? 'actives';

        /*
         * Affichage par défaut :
         * paiement en attente OU travaux en cours.
         *
         * Les champs booléens c.statut et c.etat ne sont jamais mis à
         * jour après la création de la commande (aucun setStatut()
         * ni setEtat() n'est appelé ailleurs dans le code) : ils
         * restent bloqués à true pour toujours, ce qui rendait ce
         * filtre inopérant et laissait apparaître indéfiniment les
         * commandes déjà payées et déjà livrées. Le paiement réel est
         * lu depuis c.statutPaiement (mis à jour à chaque validation
         * de paiement, voir CommandesController), et les travaux
         * réels à partir du statut de production des lignes (comme
         * pour Commandes::getStatutTravaux()).
         */
        if (!$rechercheActive && $affichage !== 'toutes') {
            $qb
                ->andWhere(
                    $qb->expr()->orX(
                        'c.statutPaiement != :statutPayeDefaut',
                        $qb->expr()->andX(
                            'd.statutProduction IS NOT NULL',
                            'd.statutProduction NOT IN (:statutsTermines)'
                        )
                    )
                )
                ->setParameter('statutPayeDefaut', Commandes::PAIEMENT_PAYE)
                ->setParameter(
                    'statutsTermines',
                    [
                        CommandesDetails::PRODUCTION_LIVREE,
                        CommandesDetails::PRODUCTION_ANNULEE,
                    ]
                );
        }

        /*
         * Recherche par numéro de commande,
         * nom du client ou téléphone.
         */
        $q = trim((string) ($filtres['q'] ?? ''));

        if ($q !== '') {
            $recherche = $qb->expr()->orX(
                'LOWER(cl.nom) LIKE LOWER(:q)',
                'cl.telephone LIKE :q'
            );

            if (ctype_digit($q)) {
                $recherche->add('c.id = :commandeId');
                $qb->setParameter('commandeId', (int) $q);
            }

            $qb
                ->andWhere($recherche)
                ->setParameter('q', '%' . $q . '%');
        }

        /*
         * Filtre par client.
         */
        if (!empty($filtres['client'])) {
            $qb
                ->andWhere('cl.id = :client')
                ->setParameter('client', (int) $filtres['client']);
        }

        /*
         * Filtre par statut de paiement.
         *
         * 1 = paiement en attente
         * 0 = paiement terminé
         */
        if (($filtres['statut'] ?? '') !== '') {
            $qb
                ->andWhere('c.statut = :statut')
                ->setParameter(
                    'statut',
                    (string) $filtres['statut'] === '1'
                );
        }

        /*
         * Filtre par état des travaux (préparation / livraison / livrée),
         * calculé comme Commandes::getStatutTravaux() : basé sur le
         * statut de production réel des lignes, pas sur c.etat (jamais
         * mis à jour après création).
         */
        $etatFiltre = (string) ($filtres['etat'] ?? '');

        if ($etatFiltre !== '') {
            $groupePreparation = [
                CommandesDetails::PRODUCTION_A_PRODUIRE,
                CommandesDetails::PRODUCTION_EN_COURS,
                CommandesDetails::PRODUCTION_TERMINEE,
                CommandesDetails::PRODUCTION_NON_REQUISE,
            ];

            $groupeLivraison = [
                CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
                CommandesDetails::PRODUCTION_EN_LIVRAISON,
            ];

            if ($etatFiltre === 'preparation') {
                $qb
                    ->andWhere($qb->expr()->exists(
                        'SELECT 1 FROM App\Entity\CommandesDetails detatPrepa'
                            . ' WHERE detatPrepa.commande = c'
                            . ' AND detatPrepa.statutProduction IN (:groupePreparation)'
                    ))
                    ->setParameter('groupePreparation', $groupePreparation);
            } elseif ($etatFiltre === 'livraison') {
                $qb
                    ->andWhere($qb->expr()->not(
                        $qb->expr()->exists(
                            'SELECT 1 FROM App\Entity\CommandesDetails detatPrepa2'
                                . ' WHERE detatPrepa2.commande = c'
                                . ' AND detatPrepa2.statutProduction IN (:groupePreparation)'
                        )
                    ))
                    ->andWhere($qb->expr()->exists(
                        'SELECT 1 FROM App\Entity\CommandesDetails detatLivr'
                            . ' WHERE detatLivr.commande = c'
                            . ' AND detatLivr.statutProduction IN (:groupeLivraison)'
                    ))
                    ->setParameter('groupePreparation', $groupePreparation)
                    ->setParameter('groupeLivraison', $groupeLivraison);
            } elseif ($etatFiltre === 'livree') {
                $qb
                    ->andWhere($qb->expr()->not(
                        $qb->expr()->exists(
                            'SELECT 1 FROM App\Entity\CommandesDetails detatNonLivr'
                                . ' WHERE detatNonLivr.commande = c'
                                . ' AND detatNonLivr.statutProduction NOT IN (:groupeLivreeOuAnnulee)'
                        )
                    ))
                    ->andWhere($qb->expr()->exists(
                        'SELECT 1 FROM App\Entity\CommandesDetails detatActive'
                            . ' WHERE detatActive.commande = c'
                            . ' AND detatActive.statutProduction != :statutAnnulee'
                    ))
                    ->setParameter(
                        'groupeLivreeOuAnnulee',
                        [
                            CommandesDetails::PRODUCTION_LIVREE,
                            CommandesDetails::PRODUCTION_ANNULEE,
                        ]
                    )
                    ->setParameter(
                        'statutAnnulee',
                        CommandesDetails::PRODUCTION_ANNULEE
                    );
            }
        }

        /*
         * Filtre par situation réelle du paiement, lue directement
         * depuis c.statutPaiement (impayee/partielle/payee), mise à
         * jour à chaque validation de paiement. Le champ
         * c.montantApayer utilisé auparavant ici n'est qu'une copie
         * figée du devis d'origine, jamais mise à jour ensuite : le
         * filtre ne retournait donc presque aucun résultat correct.
         */
        if (
            in_array(
                $filtres['paiement'] ?? '',
                [
                    Commandes::PAIEMENT_IMPAYE,
                    Commandes::PAIEMENT_PARTIEL,
                    Commandes::PAIEMENT_PAYE,
                ],
                true
            )
        ) {
            $qb
                ->andWhere('c.statutPaiement = :statutPaiementFiltre')
                ->setParameter(
                    'statutPaiementFiltre',
                    $filtres['paiement']
                );
        }

        /*
         * Période.
         */
        if (!empty($filtres['date_debut'])) {
            try {
                $dateDebut = new \DateTimeImmutable(
                    $filtres['date_debut'] . ' 00:00:00'
                );

                $qb
                    ->andWhere('c.dateCommande >= :dateDebut')
                    ->setParameter('dateDebut', $dateDebut);
            } catch (\Exception) {
                // La date invalide est ignorée.
            }
        }

        if (!empty($filtres['date_fin'])) {
            try {
                $dateFin = new \DateTimeImmutable(
                    $filtres['date_fin'] . ' 23:59:59'
                );

                $qb
                    ->andWhere('c.dateCommande <= :dateFin')
                    ->setParameter('dateFin', $dateFin);
            } catch (\Exception) {
                // La date invalide est ignorée.
            }
        }

        /*
         * Montants.
         */
        if (
            isset($filtres['montant_min'])
            && $filtres['montant_min'] !== ''
        ) {
            $qb
                ->andWhere('c.totalTtc >= :montantMin')
                ->setParameter(
                    'montantMin',
                    (int) $filtres['montant_min']
                );
        }

        if (
            isset($filtres['montant_max'])
            && $filtres['montant_max'] !== ''
        ) {
            $qb
                ->andWhere('c.totalTtc <= :montantMax')
                ->setParameter(
                    'montantMax',
                    (int) $filtres['montant_max']
                );
        }

        /*
         * Tri des résultats.
         */
        match ($filtres['tri'] ?? 'recent') {
            'ancien' => $qb->orderBy('c.id', 'ASC'),

            'montant_desc' => $qb
                ->orderBy('c.totalTtc', 'DESC'),

            'reste_desc' => $qb
                ->addSelect(
                    '(c.totalTtc - COALESCE(c.montantApayer, 0))
                     AS HIDDEN resteAPayer'
                )
                ->orderBy('resteAPayer', 'DESC'),

            default => $qb->orderBy('c.id', 'DESC'),
        };

        return $qb->getQuery()->getResult();
    }

    /**
     * Commandes traitées et chiffre d'affaires généré, groupés par agent.
     *
     * Doctrine n'autorise pas de mélanger une entité complète et des
     * fonctions d'agrégation (COUNT/SUM) dans un même SELECT sans
     * sélectionner aussi l'alias racine : on sélectionne donc les
     * champs de l'agent un par un plutôt que l'entité User entière.
     *
     * @return array<int, array{
     *     agentId: int,
     *     agentUsername: string,
     *     agentNom: ?string,
     *     agentPrenom: ?string,
     *     agentPhotos: ?string,
     *     nbCommandes: int,
     *     caGenere: int
     * }>
     */
    public function statistiquesParAgent(
        ?\DateTimeInterface $debut = null,
        ?\DateTimeInterface $fin = null
    ): array {
        $qb = $this->createQueryBuilder('c')
            ->select('a.id AS agentId')
            ->addSelect('a.username AS agentUsername')
            ->addSelect('e.nom AS agentNom')
            ->addSelect('e.prenom AS agentPrenom')
            ->addSelect('e.photos AS agentPhotos')
            ->addSelect('COUNT(c.id) AS nbCommandes')
            ->addSelect('COALESCE(SUM(c.totalTtc), 0) AS caGenere')
            ->join('c.agents', 'a')
            ->leftJoin('a.employe', 'e')
            ->andWhere('c.deleted = false')
            ->groupBy('a.id')
            ->addGroupBy('e.id')
            ->orderBy('caGenere', 'DESC');

        if ($debut) {
            $qb
                ->andWhere('c.dateCommande >= :debut')
                ->setParameter('debut', $debut);
        }

        if ($fin) {
            $qb
                ->andWhere('c.dateCommande <= :fin')
                ->setParameter('fin', $fin);
        }

        $resultats = $qb->getQuery()->getResult();

        foreach ($resultats as &$ligne) {
            $ligne['agentId'] = (int) $ligne['agentId'];
            $ligne['nbCommandes'] = (int) $ligne['nbCommandes'];
            $ligne['caGenere'] = (int) $ligne['caGenere'];
        }

        return $resultats;
    }

    /**
     * Nombre de commandes (non supprimées) sur une période.
     */
    public function compterCommandes(
        ?\DateTimeInterface $debut = null,
        ?\DateTimeInterface $fin = null
    ): int {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.deleted = false');

        if ($debut) {
            $qb
                ->andWhere('c.dateCommande >= :debut')
                ->setParameter('debut', $debut);
        }

        if ($fin) {
            $qb
                ->andWhere('c.dateCommande <= :fin')
                ->setParameter('fin', $fin);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Détecte un doublon probable : même agent, même client,
     * même montant TTC, enregistré il y a moins de $secondes.
     *
     * Sert de filet de sécurité côté serveur contre un double
     * clic ou une double soumission du formulaire.
     */
    public function trouverDoublonRecent(
        User $agent,
        Clients $client,
        int $totalTtc,
        \DateTimeInterface $depuis
    ): ?Commandes {
        return $this->createQueryBuilder('c')
            ->andWhere('c.deleted = false')
            ->andWhere('c.agents = :agent')
            ->andWhere('c.clients = :client')
            ->andWhere('c.totalTtc = :totalTtc')
            ->andWhere('c.dateCommande >= :depuis')
            ->setParameter('agent', $agent)
            ->setParameter('client', $client)
            ->setParameter('totalTtc', $totalTtc)
            ->setParameter('depuis', $depuis)
            ->orderBy('c.dateCommande', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function rechercheEstActive(array $filtres): bool
    {
        $champsRecherche = [
            'q',
            'client',
            'statut',
            'etat',
            'paiement',
            'date_debut',
            'date_fin',
            'montant_min',
            'montant_max',
        ];

        foreach ($champsRecherche as $nom) {
            $valeur = $filtres[$nom] ?? null;

            /*
             * Attention : la chaîne "0" est une valeur valide
             * pour statut et etat.
             */
            if ($valeur !== null && $valeur !== '') {
                return true;
            }
        }

        return false;
    }
}