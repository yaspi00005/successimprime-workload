<?php

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\JournalActivite;
use App\Entity\User;
use App\EventSubscriber\AuditSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class AuditSubscriberTest extends TestCase
{
    /** Cas vécu le 15/10 : « Synchroniser maintenant » vide Doctrine, puis un paiement reçu est journalisé. */
    public function testPersonneConnecteeDetacheeRattacheeParSaReference(): void
    {
        $connectee = $this->utilisateur(7);
        $reference = $this->utilisateur(7);
        $journal = (new JournalActivite())->setEntite('Paiements')->setEntiteId(1)->setAction(JournalActivite::ACTION_CREATION)->setUtilisateur($connectee);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(false);
        $em->expects(self::once())->method('getReference')->with(User::class, 7)->willReturn($reference);
        $em->expects(self::once())->method('persist')->with($journal);

        $this->postFlush($em, $journal);

        self::assertSame($reference, $journal->getUtilisateur());
    }

    public function testPersonneConnecteeSuivieGardee(): void
    {
        $connectee = $this->utilisateur(7);
        $journal = (new JournalActivite())->setEntite('Paiements')->setEntiteId(1)->setAction(JournalActivite::ACTION_CREATION)->setUtilisateur($connectee);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('contains')->willReturn(true);
        $em->expects(self::never())->method('getReference');

        $this->postFlush($em, $journal);

        self::assertSame($connectee, $journal->getUtilisateur());
    }

    private function postFlush(EntityManagerInterface $em, JournalActivite $journal): void
    {
        $abonne = new AuditSubscriber($this->createStub(Security::class));
        (new \ReflectionProperty(AuditSubscriber::class, 'enAttente'))->setValue($abonne, [$journal]);
        $abonne->postFlush(new PostFlushEventArgs($em));
    }

    private function utilisateur(int $id): User
    {
        $utilisateur = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($utilisateur, $id);

        return $utilisateur;
    }
}
