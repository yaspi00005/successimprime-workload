<?php

namespace App\Service\Finances;

use App\Entity\MouvementTresorerie;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Budget mensuel des dépenses par catégorie (maquette F03), hors
 * salaires : les salaires sont suivis à part dans la Paie.
 *
 * Chaque catégorie de budget regroupe des catégories de mouvements de
 * trésorerie. Ce qui est consommé dans le mois, ce sont les décaissements
 * validés de ces catégories. Les transferts, ajustements et
 * remboursements de crédit ne sont pas des dépenses du budget.
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
        'loyer' => ['libelle' => 'Loyer', 'mouvements' => [MouvementTresorerie::CATEGORIE_LOYER], 'mouvement' => MouvementTresorerie::CATEGORIE_LOYER, 'defaut' => 350000],
        'electricite' => ['libelle' => 'Électricité et internet', 'mouvements' => [MouvementTresorerie::CATEGORIE_ELECTRICITE], 'mouvement' => MouvementTresorerie::CATEGORIE_ELECTRICITE, 'defaut' => 180000],
        'maintenance' => ['libelle' => 'Maintenance machines', 'mouvements' => [MouvementTresorerie::CATEGORIE_ENTRETIEN], 'mouvement' => MouvementTresorerie::CATEGORIE_ENTRETIEN, 'defaut' => 120000],
        'carburant' => ['libelle' => 'Carburant et livraison', 'mouvements' => [MouvementTresorerie::CATEGORIE_CARBURANT, MouvementTresorerie::CATEGORIE_TRANSPORT], 'mouvement' => MouvementTresorerie::CATEGORIE_CARBURANT, 'defaut' => 150000],
        'divers' => ['libelle' => 'Divers', 'mouvements' => [MouvementTresorerie::CATEGORIE_AUTRE_CHARGE, MouvementTresorerie::CATEGORIE_FRAIS_BANCAIRE], 'mouvement' => MouvementTresorerie::CATEGORIE_AUTRE_CHARGE, 'defaut' => 100000],
    ];

    /** Sorties d'argent qui ne sont pas des charges du mois. */
    public const HORS_DEPENSES = [
        MouvementTresorerie::CATEGORIE_TRANSFERT_INTERNE,
        MouvementTresorerie::CATEGORIE_AJUSTEMENT,
        MouvementTresorerie::CATEGORIE_REMBOURSEMENT_CREDIT,
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
     * Budget du mois de chaque catégorie.
     *
     * @return array<string, int>
     */
    public function montants(): array
    {
        return array_map(static fn (array $c): int => $c['defaut'], self::CATEGORIES);
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
     * Total des décaissements validés de chaque catégorie de mouvement.
     *
     * @return array<string, int>
     */
    public function sortiesParCategorie(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('m.categorie', 'SUM(m.montant) AS total')
            ->from(MouvementTresorerie::class, 'm')
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
        $total = 0;
        foreach ($this->sortiesParCategorie($debut, $fin) as $categorie => $montant) {
            if (!\in_array($categorie, self::HORS_DEPENSES, true)) {
                $total += $montant;
            }
        }

        return $total;
    }
}
