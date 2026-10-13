<?php

namespace App\Service\Finances;

use App\Entity\DemandeEmploye;
use App\Entity\Depense;
use App\Entity\LigneReleveOrangeMoney;
use App\Entity\MouvementTresorerie;
use App\Entity\Paiements;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Finances du jour » (maquette F07), l'écran du téléphone de la
 * direction : trésorerie de chaque compte, encaissé et dépensé
 * aujourd'hui, facturé du mois, et ce qui attend une décision.
 *
 * Comme la vue d'ensemble, chaque chiffre n'est calculé que si la
 * personne a le droit de le voir. Dans « À décider », chacun ne voit que
 * ce qu'il peut trancher : dépenses à approuver et opérations à valider
 * pour la direction, demandes d'argent des employés pour les RH, reçus
 * Orange Money à associer pour qui encaisse. Sa propre demande d'argent
 * n'y figure pas : on ne décide pas pour soi.
 *
 * L'encaissé et le dépensé du jour comptent aussi les opérations encore
 * provisoires (saisies en ligne, décaissements en attente), signalées à
 * part : les soldes, eux, ne bougent qu'après validation.
 */
final class FinancesDuJour
{
    /** Au-delà, les dépenses à approuver sont regroupées en une ligne. */
    public const DEPENSES_DETAILLEES = 3;

    /** Ordre de la maquette : dépenses, encaissements, demandes des employés, puis le reste. */
    private const ORDRE = ['depense' => 0, 'depenses' => 0, 'encaissements' => 1, 'demande' => 2, 'decaissements' => 3, 'orange_money' => 4];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VueEnsemble $vue,
        private readonly ReglagesFinances $reglages,
        private readonly Encaissements $encaissements,
    ) {
    }

    /**
     * @param array{tresorerie: bool, ca: bool, paiements: bool, marges: bool, admin: bool, saisie?: bool, rh?: bool, encaisser?: bool} $droits
     *
     * @return array<string, mixed>
     */
    public function donnees(\DateTimeImmutable $maintenant, array $droits, ?User $moi = null): array
    {
        $aujourdhui = $maintenant->setTime(0, 0);
        $demain = $aujourdhui->modify('+1 day');
        $donnees = [
            'aujourdhui' => $aujourdhui,
            'comptes' => null,
            'total' => null,
            'encaisse' => null,
            'depense' => null,
            'facture' => null,
            'a_decider' => null,
        ];

        if ($droits['tresorerie']) {
            $comptes = $this->vue->comptes();
            $donnees['comptes'] = $comptes;
            $donnees['total'] = array_sum(array_column($comptes, 'solde'));
            $donnees['depense'] = $this->depenseDuJour($aujourdhui, $demain);
        }

        if ($droits['paiements']) {
            $donnees['encaisse'] = $this->encaisseDuJour($aujourdhui, $demain);
        }

        if ($droits['ca']) {
            [$debut, $fin] = Periode::bornes($aujourdhui);
            $montant = $this->vue->facture($debut, $fin);
            $objectif = $this->reglages->objectifCa();
            $donnees['facture'] = ['montant' => $montant, 'objectif' => $objectif, 'part' => self::part($montant, $objectif), 'mois' => Periode::MOIS[(int) $aujourdhui->format('n')]];
        }

        if ($droits['admin'] || ($droits['rh'] ?? false) || ($droits['encaisser'] ?? false)) {
            $donnees['a_decider'] = self::trier($this->aDecider($droits, $moi));
        }

        return $donnees;
    }

    /** Part de l'objectif atteinte, en %, ou null sans objectif. */
    public static function part(int $montant, int $objectif): ?int
    {
        return $objectif > 0 ? (int) round($montant * 100 / $objectif) : null;
    }

    /** « 2 encaissements en ligne », « 1 encaissement à valider ». */
    public static function libelleEncaissements(int $nombre, int $enLigne): string
    {
        $s = $nombre > 1 ? 's' : '';

        return $enLigne === $nombre ? \sprintf('%d encaissement%s en ligne', $nombre, $s) : \sprintf('%d encaissement%s à valider', $nombre, $s);
    }

    /**
     * Range les lignes « À décider » dans l'ordre de la maquette ; à type
     * égal, la plus ancienne d'abord.
     *
     * @param list<array{type: string, date: \DateTimeImmutable}&array<string, mixed>> $lignes
     *
     * @return list<array<string, mixed>>
     */
    public static function trier(array $lignes): array
    {
        usort($lignes, static fn (array $a, array $b): int => [self::ORDRE[$a['type']] ?? 9, $a['date']] <=> [self::ORDRE[$b['type']] ?? 9, $b['date']]);

        return $lignes;
    }

    /**
     * Ce qui attend une décision de la personne.
     *
     * @param array<string, bool> $droits
     *
     * @return list<array{type: string, titre: string, detail: ?string, montant: int, couleur: string, route: string, parametres: array<string, mixed>, date: \DateTimeImmutable}>
     */
    private function aDecider(array $droits, ?User $moi): array
    {
        $lignes = [];

        if ($droits['admin']) {
            /** @var list<Depense> $depenses */
            $depenses = $this->em->getRepository(Depense::class)->findBy(['statut' => Depense::A_APPROUVER], ['creeLe' => 'ASC']);
            if (\count($depenses) > self::DEPENSES_DETAILLEES) {
                $lignes[] = [
                    'type' => 'depenses', 'titre' => \sprintf('%d dépenses à approuver', \count($depenses)), 'detail' => 'la plus ancienne : '.$depenses[0]->getObjet(),
                    'montant' => array_sum(array_map(static fn (Depense $d): int => $d->getMontant(), $depenses)), 'couleur' => 'rose',
                    'route' => 'app_finances_depenses', 'parametres' => [], 'date' => $depenses[0]->getCreeLe(),
                ];
            } else {
                foreach ($depenses as $d) {
                    $lignes[] = [
                        'type' => 'depense', 'titre' => 'Dépense · '.$d->getObjet(), 'detail' => $d->getPayeA(),
                        'montant' => $d->getMontant(), 'couleur' => 'rose',
                        'route' => 'app_finances_depenses_voir', 'parametres' => ['id' => $d->getId(), 'depuis' => 'jour'], 'date' => $d->getCreeLe(),
                    ];
                }
            }

            // Opérations provisoires de « À valider » : rien ne compte dans les soldes avant.
            $paiements = $this->em->createQueryBuilder()
                ->select('COUNT(p.id) AS nombre', 'COALESCE(SUM(p.montant), 0) AS montant', 'SUM(CASE WHEN p.encaissePar IS NULL THEN 1 ELSE 0 END) AS en_ligne', 'MIN(p.date) AS premier')
                ->from(Paiements::class, 'p')
                ->andWhere('p.statut = :attente')
                ->setParameter('attente', Paiements::STATUT_EN_ATTENTE)
                ->getQuery()
                ->getSingleResult();
            if ((int) $paiements['nombre'] > 0) {
                $lignes[] = [
                    'type' => 'encaissements', 'titre' => self::libelleEncaissements((int) $paiements['nombre'], (int) $paiements['en_ligne']), 'detail' => 'à vérifier avant de compter',
                    'montant' => (int) $paiements['montant'], 'couleur' => 'bleu',
                    'route' => 'app_a_valider_index', 'parametres' => [], 'date' => new \DateTimeImmutable((string) $paiements['premier']),
                ];
            }

            $decaissements = $this->em->createQueryBuilder()
                ->select('COUNT(m.id) AS nombre', 'COALESCE(SUM(m.montant), 0) AS montant', 'MIN(m.dateOperation) AS premier')
                ->from(MouvementTresorerie::class, 'm')
                ->andWhere('m.statut = :attente')
                ->andWhere('m.type = :sortie')
                ->setParameter('attente', MouvementTresorerie::STATUT_EN_ATTENTE)
                ->setParameter('sortie', MouvementTresorerie::TYPE_DECAISSEMENT)
                ->getQuery()
                ->getSingleResult();
            if ((int) $decaissements['nombre'] > 0) {
                $nombre = (int) $decaissements['nombre'];
                $lignes[] = [
                    'type' => 'decaissements', 'titre' => \sprintf('%d décaissement%s à valider', $nombre, $nombre > 1 ? 's' : ''), 'detail' => 'dépenses payées, à confirmer',
                    'montant' => (int) $decaissements['montant'], 'couleur' => 'violet',
                    'route' => 'app_a_valider_index', 'parametres' => [], 'date' => new \DateTimeImmutable((string) $decaissements['premier']),
                ];
            }
        }

        if ($droits['rh'] ?? false) {
            // Frais médicaux et réclamations de sommes dues, en attente de réponse.
            $requete = $this->em->createQueryBuilder()
                ->select('d', 'u', 'e')
                ->from(DemandeEmploye::class, 'd')
                ->innerJoin('d.utilisateur', 'u')
                ->leftJoin('u.employe', 'e')
                ->andWhere('d.etat = :attente')
                ->andWhere('d.type IN (:sommes)')
                ->andWhere('d.montant > 0')
                ->setParameter('attente', DemandeEmploye::EN_ATTENTE)
                ->setParameter('sommes', [DemandeEmploye::FRAIS_MEDICAUX, DemandeEmploye::RECLAMATION])
                ->orderBy('d.creeLe', 'ASC');
            if (null !== $moi) {
                $requete->andWhere('d.utilisateur != :moi')->setParameter('moi', $moi);
            }
            /** @var list<DemandeEmploye> $demandes */
            $demandes = $requete->getQuery()->getResult();
            foreach ($demandes as $d) {
                $u = $d->getUtilisateur();
                $prenom = $u->getEmploye()?->getPrenom();
                $lignes[] = [
                    'type' => 'demande', 'titre' => ($d->libelleCategorie() ?? $d->libelleType()).' · '.(null !== $prenom && '' !== trim($prenom) ? $prenom : $u->getUserIdentifier()),
                    'detail' => $d->getMotif(), 'montant' => (int) $d->getMontant(), 'couleur' => 'canard',
                    'route' => 'app_demandes_index', 'parametres' => [], 'date' => $d->getCreeLe(),
                ];
            }
        }

        if ($droits['encaisser'] ?? false) {
            $releve = $this->encaissements->aAssocier();
            if ([] !== $releve) {
                $nombre = \count($releve);
                $lignes[] = [
                    'type' => 'orange_money', 'titre' => \sprintf('%d reçu%s Orange Money à associer', $nombre, $nombre > 1 ? 's' : ''), 'detail' => 'à rattacher à une commande',
                    'montant' => array_sum(array_map(static fn (LigneReleveOrangeMoney $l): int => $l->getMontant(), $releve)), 'couleur' => 'orange',
                    'route' => 'app_finances_encaissements', 'parametres' => [], 'date' => min(array_map(static fn (LigneReleveOrangeMoney $l): \DateTimeImmutable => $l->getRecuLe(), $releve)),
                ];
            }
        }

        return $lignes;
    }

    /**
     * Paiements clients du jour, validés ou encore en attente (même règle
     * que l'écran Encaissements). Le paiement par le solde du client ne
     * fait pas entrer d'argent.
     *
     * @return array{montant: int, nombre: int, en_attente: int}
     */
    private function encaisseDuJour(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $r = $this->em->createQueryBuilder()
            ->select('COUNT(p.id) AS nombre', 'COALESCE(SUM(p.montant), 0) AS montant', 'COALESCE(SUM(CASE WHEN p.statut = :attente THEN p.montant ELSE 0 END), 0) AS en_attente')
            ->from(Paiements::class, 'p')
            ->andWhere('p.statut IN (:statuts)')
            ->andWhere('p.mode != :solde')
            ->andWhere('p.date >= :debut AND p.date < :fin')
            ->setParameter('attente', Paiements::STATUT_EN_ATTENTE)
            ->setParameter('statuts', [Paiements::STATUT_VALIDE, Paiements::STATUT_EN_ATTENTE])
            ->setParameter('solde', Paiements::MODE_SOLDE_CLIENT)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleResult();

        return ['montant' => (int) $r['montant'], 'nombre' => (int) $r['nombre'], 'en_attente' => (int) $r['en_attente']];
    }

    /**
     * Décaissements du jour, validés ou en attente, hors transferts entre
     * comptes, ajustements et remboursements de crédit (même règle que
     * les budgets).
     *
     * @return array{montant: int, nombre: int, en_attente: int}
     */
    private function depenseDuJour(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $r = $this->em->createQueryBuilder()
            ->select('COUNT(m.id) AS nombre', 'COALESCE(SUM(m.montant), 0) AS montant', 'COALESCE(SUM(CASE WHEN m.statut = :attente THEN m.montant ELSE 0 END), 0) AS en_attente')
            ->from(MouvementTresorerie::class, 'm')
            ->andWhere('m.type = :sortie')
            ->andWhere('m.statut IN (:statuts)')
            ->andWhere('m.categorie NOT IN (:hors)')
            ->andWhere('m.dateOperation >= :debut AND m.dateOperation < :fin')
            ->setParameter('sortie', MouvementTresorerie::TYPE_DECAISSEMENT)
            ->setParameter('attente', MouvementTresorerie::STATUT_EN_ATTENTE)
            ->setParameter('statuts', [MouvementTresorerie::STATUT_VALIDE, MouvementTresorerie::STATUT_EN_ATTENTE])
            ->setParameter('hors', Budgets::HORS_DEPENSES)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleResult();

        return ['montant' => (int) $r['montant'], 'nombre' => (int) $r['nombre'], 'en_attente' => (int) $r['en_attente']];
    }
}
