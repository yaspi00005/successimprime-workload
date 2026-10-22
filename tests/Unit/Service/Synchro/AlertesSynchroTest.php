<?php

namespace App\Tests\Unit\Service\Synchro;

use App\Entity\Notification;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Service\Synchro\AgentSynchro;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Alertes de panne de synchronisation dans la cloche des administrateurs :
 * une seule non lue par personne, retirée quand la liaison revient
 * (docs/synchronisation.md, « Alerte »).
 */
final class AlertesSynchroTest extends TestCase
{
    private const ROUTE = AgentSynchro::ALERTE_ROUTE;
    private const PREFIXE = AgentSynchro::ALERTE_PREFIXE;

    /** @var list<object> */
    private array $persistees = [];

    public function testUneNouvellePanneRemetAJourLAlerteNonLueAuLieuDEnAjouter(): void
    {
        $yaya = self::utilisateur(1, ['ROLE_ADMIN']);
        $awa = self::utilisateur(2, ['ROLE_ADMIN']);
        $graphiste = self::utilisateur(3, ['ROLE_GRAPHISTE']);
        $ancienne = self::alerte($yaya, 'Synchronisation : Pas de synchronisation réussie depuis 16 min : Site injoignable.', '-3 hours');
        $plusAncienne = self::alerte($yaya, 'Synchronisation : Pas de synchronisation réussie depuis 15 min.', '-1 day');
        $maintenant = new \DateTimeImmutable('2026-10-15 21:00:00');

        $service = $this->service([$yaya, $awa, $graphiste], [$ancienne, $plusAncienne]);
        $misesAJour = $service->notifierRolesSansDoublon(['ROLE_ADMIN'], 'Synchronisation : Pas de synchronisation réussie depuis 17 min : Site injoignable.', self::ROUTE, self::PREFIXE, $maintenant);

        // Yaya : la plus récente est reprise (texte et date), le doublon plus ancien est marqué lu, rien n'est ajouté.
        self::assertSame([$ancienne], $misesAJour);
        self::assertSame('Synchronisation : Pas de synchronisation réussie depuis 17 min : Site injoignable.', $ancienne->getMessage());
        self::assertEquals($maintenant, $ancienne->getDateCreation());
        self::assertFalse($ancienne->isLue());
        self::assertTrue($plusAncienne->isLue());

        // Awa n'avait rien : elle reçoit une notification (qui part sur son téléphone à l'enregistrement).
        self::assertCount(1, $this->persistees);
        self::assertSame($awa, $this->persistees[0]->getDestinataire());
        self::assertSame(self::ROUTE, $this->persistees[0]->getRoute());
        // Le graphiste n'est pas administrateur : rien pour lui.
    }

    public function testLeRetourDeLaLiaisonMarqueLuesLesAlertesEncoreNonLues(): void
    {
        $yaya = self::utilisateur(1, ['ROLE_ADMIN']);
        $awa = self::utilisateur(2, ['ROLE_ADMIN']);
        $alertes = [
            self::alerte($yaya, 'Synchronisation : Pas de synchronisation réussie depuis 2 h : Site injoignable.', '-10 minutes'),
            self::alerte($awa, 'Synchronisation : La synchronisation n\'a jamais réussi.', '-2 days'),
        ];

        $service = $this->service([$yaya, $awa], $alertes);

        self::assertSame(2, $service->marquerLuesParSorte(self::ROUTE, self::PREFIXE));
        self::assertTrue($alertes[0]->isLue());
        self::assertTrue($alertes[1]->isLue());
        self::assertSame([], $this->persistees);
    }

    public function testLeMessageDoitPorterLePrefixeDeSaSorte(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service([], [])->notifierRolesSansDoublon(['ROLE_ADMIN'], 'Pas de synchronisation', self::ROUTE, self::PREFIXE, new \DateTimeImmutable());
    }

    public function testUnMessageTropLongEstCoupeALaTailleDeLaColonne(): void
    {
        $cause = str_repeat('Délai dépassé en joignant le site. ', 20);
        $n = (new Notification())->setMessage(self::PREFIXE.'Pas de synchronisation réussie depuis 16 min : Site injoignable : '.$cause);

        self::assertSame(Notification::LONGUEUR_MAX, mb_strlen($n->getMessage()));
        self::assertStringEndsWith('…', $n->getMessage());
        self::assertStringStartsWith(self::PREFIXE, $n->getMessage());
        self::assertSame('Court', (new Notification())->setMessage('  Court ')->getMessage());
    }

    /**
     * @param list<User>         $utilisateurs actifs
     * @param list<Notification> $nonLues      alertes non lues de la sorte, les plus récentes d'abord
     */
    private function service(array $utilisateurs, array $nonLues): NotificationService
    {
        $depot = $this->createStub(NotificationRepository::class);
        $depot->method('findNonLuesParSorte')->willReturnCallback(static function (string $route, string $prefixe) use ($nonLues): array {
            self::assertSame([self::ROUTE, self::PREFIXE], [$route, $prefixe]);

            return $nonLues;
        });
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static function (string $classe) use ($depot): object {
            self::assertSame(Notification::class, $classe);

            return $depot;
        });
        $em->method('persist')->willReturnCallback(function (object $o): void {
            $this->persistees[] = $o;
        });
        $users = $this->createStub(UserRepository::class);
        $users->method('findBy')->willReturnCallback(static function (array $criteres) use ($utilisateurs): array {
            self::assertSame(['actif' => true], $criteres);

            return $utilisateurs;
        });

        return new NotificationService($em, $users);
    }

    /** @param list<string> $roles */
    private static function utilisateur(int $id, array $roles): User
    {
        $u = (new User())->setRoles($roles);
        (new \ReflectionProperty(User::class, 'id'))->setValue($u, $id);

        return $u;
    }

    private static function alerte(User $destinataire, string $message, string $age): Notification
    {
        $n = (new Notification())->setDestinataire($destinataire)->setMessage($message)->setRoute(self::ROUTE);
        (new \ReflectionProperty(Notification::class, 'dateCreation'))->setValue($n, new \DateTimeImmutable($age));

        return $n;
    }
}
