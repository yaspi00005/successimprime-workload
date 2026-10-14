<?php

namespace App\Tests\Unit\Service\Site;

use App\Service\Site\ContactSite;
use PHPUnit\Framework\TestCase;

final class ContactSiteTest extends TestCase
{
    public function testLienDuPlan(): void
    {
        self::assertNull(ContactSite::lien(''), 'pas encore réglé : plan caché');
        self::assertSame('https://www.google.com/maps/dir/?api=1&destination=12.6392,-8.0029', ContactSite::lien(' 12.6392, -8.0029 '));
        self::assertSame('https://maps.app.goo.gl/AbCd123', ContactSite::lien('https://maps.app.goo.gl/AbCd123'));
        self::assertNull(ContactSite::lien('http://maps.example/plan'), 'https seulement');
        self::assertNull(ContactSite::lien('javascript:alert(1)'));
        self::assertNull(ContactSite::lien('https://exemple.ml/"><script>'));
        self::assertNull(ContactSite::lien('120.5, 9'), 'latitude impossible');
    }

    public function testTexteDesHoraires(): void
    {
        self::assertSame("Lun – sam : 8\u{a0}h – 18\u{a0}h", ContactSite::texteHoraires('lun-sam', '08:00', '18:00'));
        self::assertSame("Lun – ven : 7\u{a0}h\u{a0}30 – 17\u{a0}h", ContactSite::texteHoraires('lun-ven', '07:30', '17:00'));
        self::assertSame("Tous les jours : 9\u{a0}h – 19\u{a0}h", ContactSite::texteHoraires('lun-dim', '09:00', '19:00'));
    }

    public function testOuvertOuFerme(): void
    {
        // 14/10/2026 est un mercredi.
        $etat = static fn (string $quand, string $jours = 'lun-sam'): array => ContactSite::etat($jours, '08:00', '18:00', new \DateTimeImmutable($quand));

        self::assertSame(['ouvert' => true, 'etat' => "Ouvert maintenant, jusqu'à 18\u{a0}h"], $etat('2026-10-14 10:00'));
        self::assertSame(['ouvert' => false, 'etat' => "Fermé, ouvre aujourd'hui à 8\u{a0}h"], $etat('2026-10-14 06:30'));
        self::assertSame(['ouvert' => false, 'etat' => "Fermé, ouvre demain à 8\u{a0}h"], $etat('2026-10-14 18:00'));
        self::assertSame(['ouvert' => false, 'etat' => "Fermé, ouvre lundi à 8\u{a0}h"], $etat('2026-10-17 19:00'), 'samedi soir');
        self::assertSame(['ouvert' => false, 'etat' => "Fermé, ouvre lundi à 8\u{a0}h"], $etat('2026-10-16 19:00', 'lun-ven'), 'vendredi soir');
        self::assertTrue($etat('2026-10-18 10:00', 'lun-dim')['ouvert'], 'dimanche quand tous les jours');
    }
}
