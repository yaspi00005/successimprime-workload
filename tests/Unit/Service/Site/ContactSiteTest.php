<?php

namespace App\Tests\Unit\Service\Site;

use App\Service\Parametres\CatalogueParametres;
use App\Service\ActionsCaisse\HeuresTravail;
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

    public function testRepereDeLaCarte(): void
    {
        // Par défaut : le repère de la fiche Google de l'imprimerie.
        $defaut = (string) CatalogueParametres::champ('site_contact', 'lien_plan')['defaut'];
        self::assertSame(['latitude' => 12.6517571, 'longitude' => -7.9299376], ContactSite::repere($defaut));
        self::assertSame('https://www.google.com/maps/dir/?api=1&destination=12.6517571,-7.9299376', ContactSite::lien($defaut));

        // Lien long de la fiche : le repère (!3d…!4d…), pas le centre de la vue (@…).
        self::assertSame(['latitude' => 12.6517571, 'longitude' => -7.9299376], ContactSite::repere('https://www.google.com/maps/place/Succ%C3%A8s+Imprim/@12.6517571,-7.9325125,17z/data=!3m1!4b1!4m6!3m5!1s0xe51cd45a9a50e9b:0x85d0df3e6b3309bd!8m2!3d12.6517571!4d-7.9299376'));
        // Lien court : bouton Itinéraire et plan dessiné, pas de carte.
        self::assertNull(ContactSite::repere('https://maps.app.goo.gl/S53cwaekd2L3FHP86'));
        self::assertNull(ContactSite::repere(''));
        self::assertNull(ContactSite::repere('http://www.google.com/maps/@12.6517571,-7.9299376,17z'), 'pas de lien d\'itinéraire en http');
    }

    public function testItineraireToujoursDisponible(): void
    {
        // Position réglée : l'itinéraire de Google Maps jusqu'à l'imprimerie, comme la page Contact.
        $defaut = (string) CatalogueParametres::champ('site_contact', 'lien_plan')['defaut'];
        self::assertSame('https://www.google.com/maps/dir/?api=1&destination=12.6517571,-7.9299376', ContactSite::itineraireDe($defaut, 'Succès Imprim', 'Bamako, Sotuba ACI'));
        self::assertSame('https://maps.app.goo.gl/S53cwaekd2L3FHP86', ContactSite::itineraireDe('https://maps.app.goo.gl/S53cwaekd2L3FHP86', 'Succès Imprim', null));

        // Rien de réglé (ou un réglage refusé) : une recherche sur le nom et l'adresse.
        self::assertSame('https://www.google.com/maps/search/?api=1&query=Succ%C3%A8s%20Imprim%20Bamako%2C%20Sotuba%20ACI', ContactSite::itineraireDe('', 'Succès Imprim', "Bamako,  Sotuba\nACI"));
        self::assertSame(ContactSite::recherche('Succès Imprim', null), ContactSite::itineraireDe('javascript:alert(1)', 'Succès Imprim', null));
        self::assertSame('https://www.google.com/maps/search/?api=1&query=Succ%C3%A8s%20Imprim%20Bamako%2C%20Mali', ContactSite::recherche('Succès Imprim', null));
        self::assertSame('https://www.google.com/maps/search/?api=1&query=Succ%C3%A8s%20Imprim%20Sotuba%20ACI%2C%20Bamako%2C%20Mali', ContactSite::recherche('Succès Imprim', 'Sotuba ACI'), 'Bamako ajouté');
        self::assertStringNotContainsString('<', ContactSite::recherche('Succès Imprim', '"><script>'), 'adresse encodée');
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
        $etat = static fn (string $quand, string $jours = 'lun-sam'): array => array_intersect_key(ContactSite::etat($jours, '08:00', '18:00', new \DateTimeImmutable($quand)), ['ouvert' => 1, 'etat' => 1]);

        self::assertSame(['ouvert' => true, 'etat' => "Ouvert maintenant, jusqu'à 18\u{a0}h"], $etat('2026-10-14 10:00'));
        self::assertSame(['ouvert' => false, 'etat' => "Fermé, ouvre aujourd'hui à 8\u{a0}h"], $etat('2026-10-14 06:30'));
        self::assertSame(['ouvert' => false, 'etat' => "Fermé, ouvre demain à 8\u{a0}h"], $etat('2026-10-14 18:00'));
        self::assertSame(['ouvert' => false, 'etat' => "Fermé, ouvre lundi à 8\u{a0}h"], $etat('2026-10-17 19:00'), 'samedi soir');
        self::assertSame(['ouvert' => false, 'etat' => "Fermé, ouvre lundi à 8\u{a0}h"], $etat('2026-10-16 19:00', 'lun-ven'), 'vendredi soir');
        self::assertTrue($etat('2026-10-18 10:00', 'lun-dim')['ouvert'], 'dimanche quand tous les jours');
    }

    public function testJourFerieFerme(): void
    {
        // Lundi 12/10/2026 déclaré férié : fermé, ouvre mardi.
        $heures = new HeuresTravail('lun-sam', '08:00', '18:00', '12:00', '13:00', '12/10/2026');
        $etat = ContactSite::etat('lun-sam', '08:00', '18:00', new \DateTimeImmutable('2026-10-12 10:00'), $heures->estOuvre(...));
        self::assertFalse($etat['ouvert']);
        self::assertSame("Fermé, ouvre demain à 8\u{a0}h", $etat['etat']);
        self::assertFalse($etat['exceptionnel']);
    }

    public function testJoursFermesEtTexteCourt(): void
    {
        self::assertSame('Dimanche : fermé', ContactSite::joursFermes('lun-sam'));
        self::assertSame('Samedi et dimanche : fermé', ContactSite::joursFermes('lun-ven'));
        self::assertNull(ContactSite::joursFermes('lun-dim'));
        self::assertSame("Lun – sam 8\u{a0}h – 18\u{a0}h · dim. fermé", ContactSite::texteCourt('lun-sam', '08:00', '18:00'));
    }
}
