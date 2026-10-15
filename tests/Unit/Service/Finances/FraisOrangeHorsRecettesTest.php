<?php

namespace App\Tests\Unit\Service\Finances;

use App\Entity\MouvementTresorerie;
use App\Service\Finances\Budgets;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Les frais Orange Money à prélever (payés par le client, débités par
 * Orange à la sortie) ne sont ni une recette ni une dépense : tableau de
 * bord, Finances, statistiques et budgets les laissent de côté ; les
 * soldes, eux, les comptent.
 */
final class FraisOrangeHorsRecettesTest extends TestCase
{
    public function testLesRequetesDesChiffresLesRetirent(): void
    {
        $qb = (new QueryBuilder($this->createStub(EntityManagerInterface::class)))
            ->select('SUM(m.montant)')
            ->from(MouvementTresorerie::class, 'm')
            ->andWhere('m.type = :type');

        $dql = MouvementTresorerie::exclureHorsActivite($qb)->getDQL();

        self::assertStringContainsString('m.categorie NOT IN (:horsActivite)', $dql);
        self::assertSame([MouvementTresorerie::CATEGORIE_FRAIS_ORANGE], $qb->getParameter('horsActivite')?->getValue());
        self::assertStringContainsString('x.categorie NOT IN', MouvementTresorerie::exclureHorsActivite((new QueryBuilder($this->createStub(EntityManagerInterface::class)))->select('x')->from(MouvementTresorerie::class, 'x'), 'x')->getDQL());
    }

    public function testNiChiffreDAffairesNiEncaissementDuTableau(): void
    {
        $frais = (new MouvementTresorerie())->setType(MouvementTresorerie::TYPE_ENCAISSEMENT)->setCategorie(MouvementTresorerie::CATEGORIE_FRAIS_ORANGE)->setMontant(500);
        $vente = (new MouvementTresorerie())->setType(MouvementTresorerie::TYPE_ENCAISSEMENT)->setCategorie(MouvementTresorerie::CATEGORIE_VENTE)->setMontant(25000);

        // Ce que comptent l'évolution financière (CA = estProduit) et les statistiques (hors activité retirée).
        $ca = 0;
        $encaisse = 0;
        foreach ([$vente, $frais] as $m) {
            if ($m->estHorsActivite()) {
                continue;
            }
            $encaisse += $m->getMontant();
            $ca += $m->estProduit() ? $m->getMontant() : 0;
        }
        self::assertSame(25000, $ca);
        self::assertSame(25000, $encaisse);
        self::assertFalse($frais->estProduit());
        self::assertFalse($vente->estHorsActivite());
    }

    public function testNiDepenseNiBudget(): void
    {
        self::assertContains(MouvementTresorerie::CATEGORIE_FRAIS_ORANGE, Budgets::HORS_DEPENSES);
        self::assertNull(Budgets::categoriePourMouvement(MouvementTresorerie::CATEGORIE_FRAIS_ORANGE));
        self::assertContains(MouvementTresorerie::CATEGORIE_FRAIS_ORANGE, MouvementTresorerie::CATEGORIES_HORS_ACTIVITE);
        self::assertContains(MouvementTresorerie::CATEGORIE_FRAIS_ORANGE, MouvementTresorerie::CATEGORIES_NEUTRES);
    }
}
