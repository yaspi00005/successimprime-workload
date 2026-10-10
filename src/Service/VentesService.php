<?php

namespace App\Service;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Devis;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Ecran Ventes « Devis et commandes » (maquette 21) : indicateurs du mois
 * et liste unique des devis et des commandes, du plus recent au plus ancien.
 */
final class VentesService
{
    public const ONGLETS = [
        'tous' => 'Tous',
        'devis' => 'Devis',
        'commandes' => 'Commandes',
        'a_relancer' => 'À relancer',
    ];

    /**
     * Un devis envoye sans reponse depuis ce nombre de jours est a relancer.
     */
    public const JOURS_AVANT_RELANCE = 3;

    public const PAR_PAGE = 25;

    private const ETAPES_COMMANDE = [
        'pao' => 'PAO',
        'bat' => 'BAT envoyé',
        'impression' => 'Impression',
        'finition' => 'Finition',
        'prete' => 'Prête',
    ];

    private const STATUTS_DEVIS_ACTIFS = [
        Devis::STATUT_ENVOYE,
        Devis::STATUT_ACCEPTE,
        Devis::STATUT_REFUSE,
        Devis::STATUT_EXPIRE,
        Devis::STATUT_CONVERTI,
    ];

    private const PRODUCTION_EN_COURS = [
        CommandesDetails::PRODUCTION_A_PRODUIRE,
        CommandesDetails::PRODUCTION_EN_COURS,
        CommandesDetails::PRODUCTION_TERMINEE,
        CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
        CommandesDetails::PRODUCTION_EN_LIVRAISON,
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Indicateurs de la maquette 21, calcules comme dans docs/indicateurs.md
     * (section 2) : taux de transformation = devis acceptes ÷ devis emis.
     *
     * @return array<string, mixed>
     */
    public function indicateurs(\DateTimeImmutable $maintenant): array
    {
        $debutMois = $maintenant->modify('first day of this month')->setTime(0, 0);
        $debutMoisPrecedent = $debutMois->modify('-1 month');
        $finComparaison = $maintenant->modify('-1 month');

        $devisMois = $this->compterDevis($debutMois, $maintenant);
        $devisAvant = $this->compterDevis($debutMoisPrecedent, $finComparaison);
        $tauxMois = self::taux($devisMois['convertis'], $devisMois['total']);
        $tauxAvant = self::taux($devisAvant['convertis'], $devisAvant['total']);
        $panierMois = $this->panierMoyen($debutMois, $maintenant);
        $panierAvant = $this->panierMoyen($debutMoisPrecedent, $finComparaison);

        return [
            'devisEnvoyes' => $devisMois['total'],
            'ecartDevis' => $devisMois['total'] - $devisAvant['total'],
            'tauxTransformation' => $tauxMois,
            'ecartTaux' => null !== $tauxMois && null !== $tauxAvant ? $tauxMois - $tauxAvant : null,
            'commandesEnCours' => $this->compterCommandesEnCours(null),
            'commandesEnRetard' => $this->compterCommandesEnCours($maintenant),
            'panierMoyen' => $panierMois,
            'variationPanier' => TableauDeBordService::variation($panierMois, $panierAvant),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function nombresParOnglet(\DateTimeImmutable $maintenant): array
    {
        $devis = (int) $this->requeteDevis('devis', $maintenant)->select('COUNT(d.id)')->getQuery()->getSingleScalarResult();
        $commandes = (int) $this->requeteCommandes()->select('COUNT(c.id)')->getQuery()->getSingleScalarResult();
        $aRelancer = (int) $this->requeteDevis('a_relancer', $maintenant)->select('COUNT(d.id)')->getQuery()->getSingleScalarResult();
        $devisNonConvertis = (int) $this->requeteDevis('tous', $maintenant)->select('COUNT(d.id)')->getQuery()->getSingleScalarResult();

        return [
            'tous' => $devisNonConvertis + $commandes,
            'devis' => $devis,
            'commandes' => $commandes,
            'a_relancer' => $aRelancer,
        ];
    }

    /**
     * Lignes de la page demandee, devis et commandes melanges par date.
     *
     * @return list<array<string, mixed>>
     */
    public function lignes(string $onglet, int $page, \DateTimeImmutable $maintenant, ?int $limite = self::PAR_PAGE): array
    {
        $fenetre = null === $limite ? null : $page * $limite;
        $lignes = [];

        if ('commandes' !== $onglet) {
            $requete = $this->requeteDevis($onglet, $maintenant)
                ->addSelect('cl', 'a', 'e', 'dd')
                ->leftJoin('d.clients', 'cl')
                ->leftJoin('d.agents', 'a')
                ->leftJoin('a.employe', 'e')
                ->leftJoin('d.devisDetails', 'dd')
                ->orderBy('d.dateDevis', 'DESC')
                ->addOrderBy('d.id', 'DESC');

            foreach ($this->premiers($requete, 'd', $fenetre) as $devis) {
                $lignes[] = $this->ligneDevis($devis, $maintenant);
            }
        }

        if (\in_array($onglet, ['tous', 'commandes'], true)) {
            $requete = $this->requeteCommandes()
                ->addSelect('cl', 'a', 'e', 'cd', 'cp')
                ->leftJoin('c.clients', 'cl')
                ->leftJoin('c.agents', 'a')
                ->leftJoin('a.employe', 'e')
                ->leftJoin('c.commandesDetails', 'cd')
                ->leftJoin('cd.controlesPrePresse', 'cp')
                ->orderBy('c.dateCommande', 'DESC')
                ->addOrderBy('c.id', 'DESC');

            foreach ($this->premiers($requete, 'c', $fenetre) as $commande) {
                $lignes[] = $this->ligneCommande($commande, $maintenant);
            }
        }

        usort($lignes, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);

        return null === $limite ? $lignes : \array_slice($lignes, ($page - 1) * $limite, $limite);
    }

    /**
     * Statut affiche d'un devis : « Envoyé · J+n » puis « À relancer ».
     *
     * @return array{libelle: string, ton: string}
     */
    public static function statutDevis(string $statut, ?\DateTimeInterface $date, \DateTimeImmutable $maintenant): array
    {
        if (Devis::STATUT_ENVOYE === $statut) {
            $jours = null === $date ? 0 : (int) $date->diff($maintenant->setTime(23, 59, 59))->days;

            return $jours >= self::JOURS_AVANT_RELANCE
                ? ['libelle' => 'À relancer', 'ton' => 'rouge']
                : ['libelle' => sprintf('Envoyé · J+%d', $jours), 'ton' => 'orange'];
        }

        return match ($statut) {
            Devis::STATUT_BROUILLON => ['libelle' => 'Brouillon', 'ton' => 'gris'],
            Devis::STATUT_ACCEPTE => ['libelle' => 'Accepté', 'ton' => 'vert'],
            Devis::STATUT_REFUSE => ['libelle' => 'Refusé', 'ton' => 'gris'],
            Devis::STATUT_EXPIRE => ['libelle' => 'Expiré', 'ton' => 'gris'],
            Devis::STATUT_CONVERTI => ['libelle' => 'Commandé', 'ton' => 'bleu'],
            default => ['libelle' => 'Annulé', 'ton' => 'gris'],
        };
    }

    /**
     * Statut affiche d'une commande : l'etape de la ligne la moins avancee.
     *
     * @param list<string> $etapesLignes cles de TableauDeBordService::COLONNES_PRODUCTION,
     *                                   ou 'en_livraison', 'livree', 'annulee', 'sans_production'
     *
     * @return array{libelle: string, ton: string}
     */
    public static function statutCommande(array $etapesLignes): array
    {
        $actives = array_values(array_diff($etapesLignes, ['annulee']));

        if ([] === $actives) {
            return $etapesLignes === [] ? ['libelle' => 'Vide', 'ton' => 'gris'] : ['libelle' => 'Annulée', 'ton' => 'gris'];
        }

        foreach (array_keys(self::ETAPES_COMMANDE) as $etape) {
            if (\in_array($etape, $actives, true)) {
                return ['libelle' => self::ETAPES_COMMANDE[$etape], 'ton' => 'prete' === $etape ? 'vert' : 'bleu'];
            }
        }

        if (\in_array('en_livraison', $actives, true)) {
            return ['libelle' => 'En livraison', 'ton' => 'vert'];
        }

        if (\in_array('sans_production', $actives, true)) {
            return ['libelle' => 'Prête', 'ton' => 'vert'];
        }

        return ['libelle' => 'Livrée', 'ton' => 'gris'];
    }

    public static function taux(int $partie, int $total): ?int
    {
        return $total > 0 ? (int) round($partie * 100 / $total) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function ligneDevis(Devis $devis, \DateTimeImmutable $maintenant): array
    {
        $details = [];

        foreach ($devis->getDevisDetails() as $detail) {
            $details[] = [$detail->getDesignation(), $detail->getQuantite()];
        }

        return [
            'type' => 'devis',
            'id' => $devis->getId(),
            'numero' => $devis->getNumero(),
            'date' => $devis->getDateDevis(),
            'client' => $devis->getClients()?->getNomComplet(),
            'objet' => self::objet($details),
            'agent' => self::agent($devis->getAgents()),
            'montant' => $devis->getTotalTtc(),
            'statut' => self::statutDevis($devis->getStatut(), $devis->getDateDevis(), $maintenant),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ligneCommande(Commandes $commande, \DateTimeImmutable $maintenant): array
    {
        $details = [];
        $etapes = [];

        foreach ($commande->getCommandesDetails() as $detail) {
            $details[] = [$detail->getDesignation(), $detail->getQuantite()];
            $etapes[] = self::etapeLigne($detail);
        }

        $statut = self::statutCommande($etapes);
        $dateLivraison = $commande->getDateLivraison();
        $enRetard = null !== $dateLivraison && $dateLivraison < $maintenant && !\in_array($statut['libelle'], ['Livrée', 'Annulée', 'Vide'], true);

        return [
            'type' => 'commande',
            'id' => $commande->getId(),
            'numero' => $commande->getNumero(),
            'date' => $commande->getDateCommande(),
            'client' => $commande->getClients()?->getNomComplet(),
            'objet' => self::objet($details),
            'agent' => self::agent($commande->getAgents()),
            'montant' => $commande->getTotalTtc(),
            'statut' => $statut,
            'enRetard' => $enRetard,
        ];
    }

    private static function etapeLigne(CommandesDetails $detail): string
    {
        $statut = $detail->getStatutProduction();

        return match (true) {
            CommandesDetails::PRODUCTION_ANNULEE === $statut => 'annulee',
            CommandesDetails::PRODUCTION_LIVREE === $statut => 'livree',
            CommandesDetails::PRODUCTION_EN_LIVRAISON === $statut => 'en_livraison',
            CommandesDetails::PRODUCTION_NON_REQUISE === $statut, !$detail->isProductionNecessaire() => 'sans_production',
            default => TableauDeBordService::colonnePourLigne(
                $statut,
                $detail->isPrePresseNecessaire(),
                $detail->isBatValide(),
                self::dernierControle($detail),
            ),
        };
    }

    private static function dernierControle(CommandesDetails $detail): ?string
    {
        $dernier = null;

        foreach ($detail->getControlesPrePresse() as $controle) {
            if (null === $dernier || $controle->getId() > $dernier->getId()) {
                $dernier = $controle;
            }
        }

        return $dernier?->getStatut();
    }

    /**
     * « Roll-up × 5 », suivi de « + n » quand il y a plusieurs lignes.
     *
     * @param list<array{0: ?string, 1: int}> $details
     */
    public static function objet(array $details): string
    {
        if ([] === $details) {
            return '—';
        }

        [$designation, $quantite] = $details[0];
        $texte = trim((string) $designation) ?: 'Article';

        if ($quantite > 1) {
            $texte .= ' × '.$quantite;
        }

        if (\count($details) > 1) {
            $texte .= sprintf(' + %d', \count($details) - 1);
        }

        return $texte;
    }

    private static function agent(?\App\Entity\User $utilisateur): string
    {
        if (null === $utilisateur) {
            return '—';
        }

        return $utilisateur->getEmploye()?->getPrenom() ?: (string) $utilisateur->getUsername();
    }

    /**
     * Les n premiers enregistrements d'une requete avec jointures sur des
     * collections : on pagine d'abord les identifiants.
     *
     * @return list<object>
     */
    private function premiers(QueryBuilder $requete, string $alias, ?int $limite): array
    {
        if (null !== $limite) {
            $identifiants = (clone $requete)
                ->select($alias.'.id')
                ->resetDQLPart('join')
                ->setMaxResults($limite)
                ->getQuery()
                ->getSingleColumnResult();

            if ([] === $identifiants) {
                return [];
            }

            $requete->andWhere($alias.'.id IN (:identifiants)')->setParameter('identifiants', $identifiants);
        }

        return $requete->getQuery()->getResult();
    }

    private function requeteDevis(string $onglet, \DateTimeImmutable $maintenant): QueryBuilder
    {
        $requete = $this->entityManager->createQueryBuilder()
            ->select('d')
            ->from(Devis::class, 'd')
            ->andWhere('d.deleted = false');

        return match ($onglet) {
            'a_relancer' => $requete
                ->andWhere('d.statut = :envoye')
                ->andWhere('d.dateDevis <= :limite')
                ->setParameter('envoye', Devis::STATUT_ENVOYE)
                ->setParameter('limite', $maintenant->setTime(23, 59, 59)->modify(sprintf('-%d days', self::JOURS_AVANT_RELANCE))),
            // Un devis commande apparait deja sous sa commande.
            'tous' => $requete
                ->andWhere('d.statut <> :converti')
                ->setParameter('converti', Devis::STATUT_CONVERTI),
            default => $requete,
        };
    }

    private function requeteCommandes(): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->select('c')
            ->from(Commandes::class, 'c')
            ->andWhere('c.deleted = false');
    }

    /**
     * @return array{total: int, convertis: int}
     */
    private function compterDevis(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $resultat = $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id) AS total')
            ->addSelect('SUM(CASE WHEN d.statut IN (:acceptes) OR d.commande IS NOT NULL THEN 1 ELSE 0 END) AS convertis')
            ->from(Devis::class, 'd')
            ->andWhere('d.deleted = false')
            ->andWhere('d.statut IN (:statuts)')
            ->andWhere('d.dateDevis BETWEEN :debut AND :fin')
            ->setParameter('acceptes', [Devis::STATUT_ACCEPTE, Devis::STATUT_CONVERTI])
            ->setParameter('statuts', self::STATUTS_DEVIS_ACTIFS)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleResult();

        return ['total' => (int) $resultat['total'], 'convertis' => (int) $resultat['convertis']];
    }

    private function panierMoyen(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return (int) round((float) $this->entityManager->createQueryBuilder()
            ->select('AVG(c.totalTtc)')
            ->from(Commandes::class, 'c')
            ->andWhere('c.deleted = false')
            ->andWhere('c.dateCommande BETWEEN :debut AND :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleScalarResult());
    }

    /**
     * Commandes dont au moins une ligne n'est pas encore livree ; avec une
     * date, seulement celles dont la livraison promise est depassee.
     */
    private function compterCommandesEnCours(?\DateTimeImmutable $retardAu): int
    {
        $requete = $this->entityManager->createQueryBuilder()
            ->select('COUNT(DISTINCT c.id)')
            ->from(Commandes::class, 'c')
            ->join('c.commandesDetails', 'cd')
            ->andWhere('c.deleted = false')
            ->andWhere('cd.statutProduction IN (:statuts)')
            ->setParameter('statuts', self::PRODUCTION_EN_COURS);

        if (null !== $retardAu) {
            $requete->andWhere('c.dateLivraison < :maintenant')->setParameter('maintenant', $retardAu);
        }

        return (int) $requete->getQuery()->getSingleScalarResult();
    }
}
