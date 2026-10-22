<?php

namespace App\Tests\Unit\Entity;

use App\Entity\EvaluationPrime;
use App\Entity\User;
use App\Service\Primes\Primes;
use PHPUnit\Framework\TestCase;

final class EvaluationPrimeTest extends TestCase
{
    private const RESULTAT = ['score' => 88, 'taux' => 75, 'primeCible' => 40_000, 'prime' => 30_000, 'criteres' => [['cle' => 'productivite', 'note' => 88]]];

    public function testLaValidationFigeLEvaluation(): void
    {
        $e = $this->brouillon()->appliquer(self::RESULTAT, 'production')->apprecier(85, ' Sérieux ');
        self::assertTrue($e->estModifiable());
        self::assertSame('Sérieux', $e->getAppreciationTexte());

        $e->valider(new User(), new \DateTimeImmutable('2026-10-02'));

        self::assertTrue($e->estValidee());
        self::assertFalse($e->estModifiable());
        $this->expectException(\LogicException::class);
        $e->appliquer(['score' => 99] + self::RESULTAT, 'production');
    }

    public function testApresValidationOnNePeutPlusApprecierNiSuspendre(): void
    {
        $e = $this->brouillon()->appliquer(self::RESULTAT, 'production')->valider(new User(), new \DateTimeImmutable());

        foreach ([static fn () => $e->apprecier(10, 'non'), static fn () => $e->suspendre(true, 'faute'), static fn () => $e->valider(new User(), new \DateTimeImmutable())] as $action) {
            try {
                $action();
                self::fail('Une évaluation validée ne change plus.');
            } catch (\LogicException) {
                self::assertSame(88, $e->getScore());
            }
        }
    }

    /** Recalcul après validation : nouvelle version avec motif, la précédente reste en vigueur jusqu'à la validation de la suivante. */
    public function testRecalculEnNouvelleVersion(): void
    {
        $directrice = new User();
        $v1 = $this->brouillon()->appliquer(self::RESULTAT, 'production')->apprecier(80, 'Bien')->suspendre(true, 'Écart de caisse non justifié');
        $v1->valider($directrice, new \DateTimeImmutable('2026-10-02'));

        $v2 = $v1->nouvelleVersion('Facture du 28 saisie en retard', $directrice, new \DateTimeImmutable('2026-10-05'));

        self::assertSame(2, $v2->getVersion());
        self::assertTrue($v2->estModifiable());
        self::assertSame('Facture du 28 saisie en retard', $v2->getMotif());
        self::assertSame($v1->getAgent(), $v2->getAgent());
        self::assertEquals($v1->getMois(), $v2->getMois());
        self::assertSame(80, $v2->getAppreciationNote(), 'appréciation reprise');
        self::assertTrue($v2->isSuspendue(), 'suspension reprise');
        self::assertSame(30_000, $v2->getPrime(), 'résultats repris jusqu\'au recalcul');
        self::assertTrue($v1->estValidee(), 'la version 1 reste en vigueur');

        $v2->appliquer(['score' => 92, 'taux' => 100, 'prime' => 40_000] + self::RESULTAT, 'production')->valider($directrice, new \DateTimeImmutable('2026-10-05'));
        $v1->remplacer();

        self::assertSame(EvaluationPrime::REMPLACEE, $v1->getStatut());
        self::assertTrue($v2->estValidee());
        self::assertSame(92, $v2->getScore());
        self::assertSame(88, $v1->getScore(), 'la photo de la version 1 ne bouge pas');
    }

    public function testSeuleUneEvaluationValideeSeRecalcule(): void
    {
        $this->expectException(\LogicException::class);
        $this->brouillon()->nouvelleVersion('Motif', new User(), new \DateTimeImmutable());
    }

    public function testLaPrimeSeValideApresLEvaluation(): void
    {
        $e = $this->brouillon()->appliquer(self::RESULTAT, 'production');
        try {
            $e->validerPrime(new User(), new \DateTimeImmutable());
            self::fail('La prime attend la validation de l\'évaluation.');
        } catch (\LogicException) {
            self::assertFalse($e->primeEstValidee());
        }
        $e->valider(new User(), new \DateTimeImmutable('2026-10-02'))->validerPrime(new User(), new \DateTimeImmutable('2026-10-03'));
        self::assertTrue($e->primeEstValidee());
    }

    /** Statut affiché dans N01. */
    public function testStatutDeLaVueDEnsemble(): void
    {
        $septembre = new \DateTimeImmutable('2026-09-01');
        $maintenant = new \DateTimeImmutable('2026-10-14');
        $fiche = static fn (?EvaluationPrime $e, ?EvaluationPrime $vigueur = null): array => ['evaluation' => $e, 'enVigueur' => $vigueur, 'fige' => null !== $e && !$e->estModifiable()];

        self::assertSame(['Appréciation à saisir', 'orange'], Primes::statut($fiche(null), $septembre, $maintenant));
        $brouillon = $this->brouillon()->apprecier(80, null);
        self::assertSame(['À valider', 'orange'], Primes::statut($fiche($brouillon), $septembre, $maintenant));
        self::assertSame(['Mois en cours', 'gris'], Primes::statut($fiche($brouillon), new \DateTimeImmutable('2026-10-01'), $maintenant));
        $brouillon->valider(new User(), $maintenant);
        self::assertSame(['Prêt', 'vert'], Primes::statut($fiche($brouillon, $brouillon), $septembre, $maintenant));
        $brouillon->validerPrime(new User(), $maintenant);
        self::assertSame(['Prime validée', 'bleu'], Primes::statut($fiche($brouillon, $brouillon), $septembre, $maintenant));
    }

    /** Mois sans aucune donnée ni évaluation commencée : hors classement, pas de note à 0. */
    public function testSansActiviteHorsClassement(): void
    {
        $vide = ['evaluation' => null, 'resultat' => ['score' => null]];
        self::assertTrue(Primes::sansActivite($vide));
        self::assertFalse(Primes::sansActivite(['resultat' => ['score' => 42]] + $vide), 'une mesure suffit');
        self::assertFalse(Primes::sansActivite(['evaluation' => $this->brouillon()] + $vide), 'évaluation commencée par le responsable');
    }

    private function brouillon(): EvaluationPrime
    {
        return new EvaluationPrime(new User(), new \DateTimeImmutable('2026-09-01'), 'production', 1, new \DateTimeImmutable('2026-10-01'), null);
    }
}
