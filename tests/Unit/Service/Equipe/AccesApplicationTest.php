<?php

namespace App\Tests\Unit\Service\Equipe;

use App\Entity\Employes;
use App\Entity\User;
use App\Service\Equipe\AccesApplication;
use App\Service\Pointage\Pointages;
use PHPUnit\Framework\TestCase;

final class AccesApplicationTest extends TestCase
{
    public function testCodePin(): void
    {
        self::assertNull(AccesApplication::controlePin('1357', '1357'));
        self::assertNotNull(AccesApplication::controlePin('135', '135'), '4 chiffres');
        self::assertNotNull(AccesApplication::controlePin('13a7', '13a7'));
        self::assertNotNull(AccesApplication::controlePin('1357', '1358'), 'confirmation différente');
        self::assertNotNull(AccesApplication::controlePin('0000', '0000'), 'trop facile');
        self::assertNotNull(AccesApplication::controlePin('1234', '1234'), 'trop facile');
    }

    public function testCinqEssaisBloquentQuinzeMinutes(): void
    {
        $u = (new User())->setPin('hash');
        $maintenant = new \DateTimeImmutable('2026-10-12 08:00');
        for ($i = 0; $i < 4; ++$i) {
            $u->pinRate($maintenant);
        }
        self::assertNull($u->getPinBloqueJusqua());
        $u->pinRate($maintenant);
        self::assertEquals(new \DateTimeImmutable('2026-10-12 08:15'), $u->getPinBloqueJusqua());
        $u->pinReussi();
        self::assertNull($u->getPinBloqueJusqua());
    }

    public function testConnexionSelonLAcces(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-12 10:00');
        $u = new User();
        self::assertTrue($u->peutSeConnecter($maintenant), 'comptes existants : actifs');

        foreach ([User::ACCES_SANS, User::ACCES_INVITE, User::ACCES_SUSPENDU] as $acces) {
            self::assertFalse($u->setAcces($acces)->peutSeConnecter($maintenant), $acces);
        }

        $u->setAcces(User::ACCES_ACTIF);
        self::assertTrue($u->setFinAccesLe(new \DateTimeImmutable('2026-10-13'))->peutSeConnecter($maintenant));
        self::assertFalse($u->setFinAccesLe(new \DateTimeImmutable('2026-10-12'))->peutSeConnecter($maintenant), 'coupé le jour de la fin');
        self::assertFalse($u->setFinAccesLe(null)->desactiver('Départ')->peutSeConnecter($maintenant));
    }

    public function testInvitationEtStatut(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-12 10:00');
        $u = new User();
        $u->inviter(AccesApplication::empreinte('x'), $maintenant, $maintenant->modify('+7 days'));
        self::assertSame(User::ACCES_INVITE, $u->getAcces());
        $u->oublierInvitation();
        self::assertNull($u->getInvitationEmpreinte());
    }

    public function testHorairesPropres(): void
    {
        $e = (new Employes())->setHoraires('19:00', '07:00', [1, 3, 4, 5, 6, 7]);
        self::assertSame('tous les jours · 19 h – 7 h · repos le mardi', $e->libelleHoraires());
        self::assertSame('lun – sam · 8 h – 18 h 30', (new Employes())->setHoraires('08:00', '18:30', [1, 2, 3, 4, 5, 6])->libelleHoraires());
        self::assertNull((new Employes())->setHoraires('25:00', null, [])->libelleHoraires());

        $u = (new User())->setEmploye($e);
        $regles = Pointages::reglesDe($u, ['ouverture' => '08:00', 'fermeture' => '18:00', 'alerte_depart' => '20:00', 'jours' => 'lun-sam']);
        self::assertSame(['ouverture' => '19:00', 'fermeture' => '07:00', 'alerte_depart' => '09:00', 'jours' => '1,3,4,5,6,7'], $regles);
    }
}
