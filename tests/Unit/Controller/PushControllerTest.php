<?php

namespace App\Tests\Unit\Controller;

use App\Controller\PushController;
use PHPUnit\Framework\TestCase;

final class PushControllerTest extends TestCase
{
    public function testSeulsLesServicesDeNotificationSontAcceptes(): void
    {
        self::assertTrue(PushController::adresseValable('https://fcm.googleapis.com/fcm/send/abc'));
        self::assertTrue(PushController::adresseValable('https://web.push.apple.com/QGx'));
        self::assertTrue(PushController::adresseValable('https://updates.push.services.mozilla.com/wpush/v2/x'));
        self::assertFalse(PushController::adresseValable('http://fcm.googleapis.com/fcm/send/abc'));
        self::assertFalse(PushController::adresseValable('https://127.0.0.1/admin'));
        self::assertFalse(PushController::adresseValable('https://googleapis.com.evil.net/x'));
        self::assertFalse(PushController::adresseValable('https://evilgoogleapis.com/x'));
    }
}
