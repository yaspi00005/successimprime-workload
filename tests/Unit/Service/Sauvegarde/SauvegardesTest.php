<?php

namespace App\Tests\Unit\Service\Sauvegarde;

use App\Service\Sauvegarde\Sauvegardes;
use PHPUnit\Framework\TestCase;

final class SauvegardesTest extends TestCase
{
    private static function d(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s);
    }

    public function testCompleteUneFoisParJourApresLHeure(): void
    {
        // Jamais de complète : on la fait tout de suite.
        self::assertTrue(Sauvegardes::aFaire(self::d('2026-10-12 10:00'), null, null, '02:00', 6));
        // Complète faite cette nuit à 2 h, base à 8 h : rien à 10 h.
        self::assertNull(Sauvegardes::aFaire(self::d('2026-10-12 10:00'), self::d('2026-10-12 02:00'), self::d('2026-10-12 08:00'), '02:00', 6));
        // 14 h : 6 h après la base de 8 h.
        self::assertFalse(Sauvegardes::aFaire(self::d('2026-10-12 14:00'), self::d('2026-10-12 02:00'), self::d('2026-10-12 08:00'), '02:00', 6));
        // La tâche de 8 h passe même si celle de 2 h a fini à 2 h 01.
        self::assertFalse(Sauvegardes::aFaire(self::d('2026-10-12 08:00'), self::d('2026-10-12 02:01'), self::d('2026-10-12 02:01'), '02:00', 6));
        // 1 h du matin : la complète d'hier 2 h compte encore.
        self::assertNull(Sauvegardes::aFaire(self::d('2026-10-13 01:00'), self::d('2026-10-12 02:00'), self::d('2026-10-12 20:00'), '02:00', 6));
        // 2 h : nouvelle complète.
        self::assertTrue(Sauvegardes::aFaire(self::d('2026-10-13 02:00'), self::d('2026-10-12 02:00'), self::d('2026-10-12 20:00'), '02:00', 6));
        // PC éteint la nuit : la complète est faite au démarrage à 8 h.
        self::assertTrue(Sauvegardes::aFaire(self::d('2026-10-13 08:00'), self::d('2026-10-12 08:00'), self::d('2026-10-12 18:00'), '02:00', 6));
    }

    public function testAlerte(): void
    {
        $maintenant = self::d('2026-10-12 12:00');
        self::assertNull(Sauvegardes::alerte($maintenant, self::d('2026-10-12 08:00'), null, null));
        self::assertNull(Sauvegardes::alerte($maintenant, self::d('2026-10-12 08:00'), null, 'ok'));
        self::assertSame('Aucune sauvegarde n\'a encore été faite.', Sauvegardes::alerte($maintenant, null, null, null));
        self::assertSame('Pas de sauvegarde depuis 30 heures.', Sauvegardes::alerte($maintenant, self::d('2026-10-11 06:00'), null, null));
        self::assertSame('Pas de sauvegarde depuis 3 jours.', Sauvegardes::alerte($maintenant, self::d('2026-10-09 06:00'), null, null));
        self::assertStringContainsString('disque plein', (string) Sauvegardes::alerte($maintenant, self::d('2026-10-12 08:00'), ['message' => 'disque plein'], null));
        self::assertStringContainsString('second disque', (string) Sauvegardes::alerte($maintenant, self::d('2026-10-12 08:00'), null, 'dossier introuvable'));
    }

    public function testTailleEtDate(): void
    {
        self::assertSame('512 o', Sauvegardes::taille(512));
        self::assertSame('1,5 Ko', Sauvegardes::taille(1536));
        self::assertSame('2,0 Go', Sauvegardes::taille(2 * 1024 ** 3));
        self::assertSame('12/10/2026 à 18 h 06', Sauvegardes::dateLisible('20261012-180617'));
    }

    public function testTousLesDossiersDeFichiersSontCouverts(): void
    {
        // Un nouveau dossier d'envoi sous public/uploads ou var/uploads est couvert d'office.
        self::assertContains('public/uploads', Sauvegardes::DOSSIERS);
        self::assertContains('var/uploads', Sauvegardes::DOSSIERS);
        self::assertContains('var/pointage', Sauvegardes::DOSSIERS);
        self::assertNotContains('var/sauvegardes', Sauvegardes::DOSSIERS);
    }
}
