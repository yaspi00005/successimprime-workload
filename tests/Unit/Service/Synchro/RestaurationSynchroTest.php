<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Entity\JourFerie;
use App\Entity\Partenaire;
use App\Entity\SynchroJournal;
use App\Service\Synchro\JournalSynchro;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Corbeille (docs/corbeille.md) : une fiche remise en place depuis la
 * corbeille de l'ERP est écrite sans Doctrine ; JournalSynchro la note
 * pour le prochain envoi au site, comme une fiche neuve.
 */
final class RestaurationSynchroTest extends TestCase
{
    public function testUneFicheSuivieRestaureeRepartAuProchainEnvoi(): void
    {
        $partenaire = new Partenaire();
        $persistes = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $o) use (&$persistes): void {
            $persistes[] = $o;
        });

        (new JournalSynchro(false))->noterRestauration($em, [$partenaire, new JourFerie(new \DateTimeImmutable('2026-01-01'), 'Jour de l\'an')]);

        self::assertCount(1, $persistes, 'le jour férié n\'est pas suivi par la synchronisation');
        self::assertInstanceOf(SynchroJournal::class, $persistes[0]);
        self::assertSame('site_partenaire', $persistes[0]->getType());
        self::assertSame($partenaire->getPublicId()->toRfc4122(), $persistes[0]->getPublicId());
        self::assertSame(SynchroJournal::MAJ, $persistes[0]->getOperation());
    }

    public function testRienPendantLApplicationDeDonneesRecues(): void
    {
        $journal = new JournalSynchro(false);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $journal->sansEcho(static fn () => $journal->noterRestauration($em, [new Partenaire()]));
    }
}
