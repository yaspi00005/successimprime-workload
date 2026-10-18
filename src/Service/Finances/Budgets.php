<?php

namespace App\Service\Finances;

use App\Entity\BudgetDepense;
use App\Entity\Depense;
use App\Entity\MouvementTresorerie;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Budget mensuel des dépenses par catégorie (maquette F03), hors
 * salaires : les salaires sont suivis à part dans la Paie.
 *
 * Ce qui est consommé dans le mois :
 * - les dépenses saisies dans Finances › Dépenses dès qu'elles sont
 *   engagées (à payer, validées ou payées ; pas celles à approuver ni
 *   les refusées), à leur date ;
 * - les autres décaissements validés en trésorerie, rangés par catégorie
 *   de mouvement. Un décaissement créé par le paiement d'une dépense
 *   n'est pas compté une seconde fois.
 * Les transferts, ajustements et remboursements de crédit ne sont pas des
 * dépenses du budget.
 */
final class Budgets
{
    /**
     * Catégorie de budget => libellé, catégories de mouvements comptées,
     * catégorie du mouvement créé au paiement, budget proposé par défaut.
     *
     * @var array<string, array{libelle: string, mouvements: list<string>, mouvement: string, defaut: int}>
     */
    public const CATEGORIES = [
        'matieres' => ['libelle' => 'Matières et consommables', 'mouvements' => [MouvementTresorerie::CATEGORIE_ACHAT], 'mouvement' => MouvementTresorerie::CATEGORIE_ACHAT, 'defaut' => 1200000],
        'loyer' => ['libelle' => 'Loyer', 'mouvements' => [MouvementTresorerie::CATEGORIE_LOYER], 'mouvement' => MouvementTresorerie::CATEGORIE_LOYER, 'defaut' => 450000],
        'electricite' => ['libelle' => 'Électricité et internet', 'mouvements' => [MouvementTresorerie::CATEGORIE_ELECTRICITE], 'mouvement' => MouvementTresorerie::CATEGORIE_ELECTRICITE, 'defaut' => 180000],
        'maintenance' => ['libelle' => 'Maintenance machines', 'mouvements' => [MouvementTresorerie::CATEGORIE_ENTRETIEN], 'mouvement' => MouvementTresorerie::CATEGORIE_ENTRETIEN, 'defaut' => 120000],
        'carburant' => ['libelle' => 'Carburant et livraison', 'mouvements' => [MouvementTresorerie::CATEGORIE_CARBURANT, MouvementTresorerie::CATEGORIE_TRANSPORT], 'mouvement' => MouvementTresorerie::CATEGORIE_CARBURANT, 'defaut' => 150000],
        'divers' => ['libelle' => 'Divers', 'mouvements' => [MouvementTresorerie::CATEGORIE_AUTRE_CHARGE, MouvementTresorerie::CATEGORIE_FRAIS_BANCAIRE], 'mouvement' => MouvementTresorerie::CATEGORIE_AUTRE_CHARGE, 'defaut' => 100000],
    ];

    /**
     * Sorties d'argent qui ne sont pas des charges du mois. Les frais
     * Orange Money débités au retrait sortent ce que les clients ont payé
     * en plus : ni dépense ni budget. L'argent rendu à un client sur son
     * compte (avance client) non plus.
     */
    public const HORS_DEPENSES = [
        MouvementTresorerie::CATEGORIE_TRANSFERT_INTERNE,
        MouvementTresorerie::CATEGORIE_AJUSTEMENT,
        MouvementTresorerie::CATEGORIE_REMBOURSEMENT_CREDIT,
        MouvementTresorerie::CATEGORIE_FRAIS_ORANGE,
        MouvementTresorerie::CATEGORIE_AVANCE_CLIENT,
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Catégorie de budget d'une catégorie de mouvement, ou null (salaires,
     * transferts, crédit...).
     */
    public static function categoriePourMouvement(string $categorieMouvement): ?string
    {
        foreach (self::CATEGORIES as $cle => $categorie) {
            if (\in_array($categorieMouvement, $categorie['mouvements'], true)) {
                return $cle;
            }
        }

        return null;
    }

    /**
     * Budget du mois de chaque catégorie : celui des Réglages, sinon le
     * montant proposé par défaut.
     *
     * @return array<string, int>
     */
    public function montants(): array
    {
        $montants = array_map(static fn (array $c): int => $c['defaut'], self::CATEGORIES);
        foreach ($this->em->getRepository(BudgetDepense::class)->findAll() as $budget) {
            if (isset($montants[$budget->getCategorie()])) {
                $montants[$budget->getCategorie()] = $budget->getMontantMensuel();
            }
        }

        return $montants;
    }

    /**
     * Enregistre les budgets mensuels (direction).
     *
     * @param array<string, int> $montants
     */
    public function enregistrer(array $montants, ?User $par, \DateTimeImmutable $maintenant): void
    {
        $existants = [];
        foreach ($this->em->getRepository(BudgetDepense::class)->findAll() as $budget) {
            $existants[$budget->getCategorie()] = $budget;
        }
        foreach (array_keys(self::CATEGORIES) as $cle) {
            if (!isset($montants[$cle])) {
                continue;
            }
            $budget = $existants[$cle] ?? new BudgetDepense($cle);
            if ($budget->getId() !== null && $budget->getMontantMensuel() === max(0, $montants[$cle])) {
                continue;
            }
            $budget->modifier($montants[$cle], $par, $maintenant);
            $this->em->persist($budget);
        }
        $this->em->flush();
    }

    /**
     * Dépensé entre deux dates, par catégorie de budget.
     *
     * @return array<string, int>
     */
    public function consommes(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $consommes = array_fill_keys(array_keys(self::CATEGORIES), 0);
        foreach ($this->sortiesParCategorie($debut, $fin) as $categorieMouvement => $montant) {
            $cle = self::categoriePourMouvement($categorieMouvement);
            if (null !== $cle) {
                $consommes[$cle] += $montant;
            }
        }
        foreach ($this->depensesEngagees($debut, $fin) as $cle => $montant) {
            $consommes[isset($consommes[$cle]) ? $cle : 'divers'] += $montant;
        }

        return $consommes;
    }

    /**
     * Lignes du budget du mois, dans l'ordre des catégories.
     *
     * @param array<string, int> $budgets
     * @param array<string, int> $consommes
     *
     * @return list<array{cle: string, libelle: string, budget: int, consomme: int, part: int, etat: string}>
     */
    public static function lignes(array $budgets, array $consommes): array
    {
        $lignes = [];
        foreach (self::CATEGORIES as $cle => $categorie) {
            $budget = $budgets[$cle] ?? 0;
            $consomme = $consommes[$cle] ?? 0;
            $part = $budget > 0 ? (int) round($consomme * 100 / $budget) : ($consomme > 0 ? 100 : 0);
            $lignes[] = ['cle' => $cle, 'libelle' => $categorie['libelle'], 'budget' => $budget, 'consomme' => $consomme, 'part' => $part, 'etat' => self::etat($part)];
        }

        return $lignes;
    }

    /**
     * Couleur de la jauge : vert jusqu'à 80 %, orange jusqu'à 100 %,
     * rouge au-delà ; « plein » quand le budget est pile atteint (loyer payé).
     */
    public static function etat(int $part): string
    {
        return match (true) {
            $part > 100 => 'depasse',
            100 === $part => 'plein',
            $part > 80 => 'attention',
            default => 'ok',
        };
    }

    /**
     * Dépenses engagées entre deux dates, par catégorie de budget.
     *
     * @return array<string, int>
     */
    public function depensesEngagees(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $totaux = [];
        foreach ($this->em->createQueryBuilder()
            ->select('d.categorie', 'SUM(d.montant) AS total')
            ->from(Depense::class, 'd')
            ->andWhere('d.statut IN (:engagees)')
            // Dette fournisseur du démarrage (docs/import-donnees.md) : pas une charge du mois.
            ->andWhere('d.soldeOuverture = false')
            ->andWhere('d.dateDepense >= :debut AND d.dateDepense < :fin')
            ->setParameter('engagees', Depense::ENGAGEES)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->groupBy('d.categorie')
            ->getQuery()
            ->getArrayResult() as $l) {
            $totaux[(string) $l['categorie']] = (int) $l['total'];
        }

        return $totaux;
    }

    /**
     * Total des décaissements validés de chaque catégorie de mouvement,
     * hors paiements de dépenses saisies (déjà comptées à part).
     *
     * @return array<string, int>
     */
    public function sortiesParCategorie(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('m.categorie', 'SUM(m.montant) AS total')
            ->from(MouvementTresorerie::class, 'm')
            ->leftJoin(Depense::class, 'depense', 'WITH', 'depense.mouvement = m')
            ->andWhere('depense.id IS NULL')
            ->andWhere('m.type = :sortie')
            ->andWhere('m.statut = :valide')
            ->andWhere('m.dateOperation >= :debut AND m.dateOperation < :fin')
            ->setParameter('sortie', MouvementTresorerie::TYPE_DECAISSEMENT)
            ->setParameter('valide', MouvementTresorerie::STATUT_VALIDE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->groupBy('m.categorie')
            ->getQuery()
            ->getArrayResult();

        $totaux = [];
        foreach ($lignes as $l) {
            $totaux[(string) $l['categorie']] = (int) $l['total'];
        }

        return $totaux;
    }

    /**
     * Toutes les charges du mois (salaires compris), pour le graphique
     * chiffre d'affaires et dépenses.
     */
    public function chargesTotales(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        $total = array_sum($this->depensesEngagees($debut, $fin));
        foreach ($this->sortiesParCategorie($debut, $fin) as $categorie => $montant) {
            if (!\in_array($categorie, self::HORS_DEPENSES, true)) {
                $total += $montant;
            }
        }

        return $total;
    }
}
