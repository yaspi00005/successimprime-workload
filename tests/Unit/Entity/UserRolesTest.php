<?php

namespace App\Tests\Unit\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class UserRolesTest extends TestCase
{
    public function testLesInformationsSensiblesSAjoutentAuProfil(): void
    {
        $user = (new User())->setRoles(['ROLE_GRAPHISTE', 'ROLE_COUT_MARGE', 'ROLE_STATS_VENTES', 'ROLE_INCONNU']);

        self::assertSame(['ROLE_GRAPHISTE', 'ROLE_STATS_VENTES', 'ROLE_COUT_MARGE'], array_values(array_diff($user->getRoles(), ['ROLE_USER'])));
    }

    public function testUneInformationSensibleSeuleNeSuffitPas(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new User())->setRoles(['ROLE_PAIEMENT_VOIR']);
    }

    public function testLAdministrateurResteSeul(): void
    {
        $user = (new User())->setRoles(['ROLE_ADMIN', 'ROLE_COUT_MARGE']);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }
}
