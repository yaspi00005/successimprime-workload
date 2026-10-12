<?php

namespace App\Tests\Unit\Service\Planning;

use App\Entity\CompteTresorerie;
use App\Entity\Echeance;
use App\Entity\MouvementTresorerie;
use App\Entity\Tache;
use App\Entity\User;
use App\Service\Planning\Planning;
use PHPUnit\Framework\TestCase;

final class DepensePrepareeTest extends TestCase
{
    private static function tache(string $categorie, ?string $depense = null): Tache
    {
        $e = (new Echeance())->setTitre('Paiement du loyer')->setCategorie($categorie)->setMontantEstime(450000)->setCategorieDepense($depense);

        return (new Tache(new \DateTimeImmutable('2026-11-05')))->reprendre($e);
    }

    public function testLaDepenseReprendLeMontantEtAttendLaValidation(): void
    {
        $compte = new CompteTresorerie();
        $m = Planning::depense(self::tache('fournisseurs'), $compte, new User(), new \DateTimeImmutable('2026-11-05 10:00'));

        self::assertSame(MouvementTresorerie::TYPE_DECAISSEMENT, $m->getType());
        self::assertSame(450000, $m->getMontant());
        self::assertSame($compte, $m->getCompteSource());
        self::assertSame(MouvementTresorerie::CATEGORIE_ACHAT, $m->getCategorie());
        self::assertSame('Planning · Paiement du loyer (05/11/2026)', $m->getLibelle());
    }

    public function testLaCategorieChoisieSurLEcheanceGagne(): void
    {
        $m = Planning::depense(self::tache('administratif', MouvementTresorerie::CATEGORIE_LOYER), new CompteTresorerie(), new User(), new \DateTimeImmutable());

        self::assertSame(MouvementTresorerie::CATEGORIE_LOYER, $m->getCategorie());
    }

    public function testUneCategorieInconnueEstIgnoree(): void
    {
        self::assertNull((new Echeance())->setCategorieDepense('vacances')->getCategorieDepense());
    }
}
