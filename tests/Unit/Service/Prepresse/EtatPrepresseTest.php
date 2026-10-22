<?php

namespace App\Tests\Unit\Service\Prepresse;

use App\Entity\ControlePrePresse;
use App\Service\Prepresse\EtatPrepresse;
use PHPUnit\Framework\TestCase;

/** États d'une commande en prépresse (PQ01, PQ03) : état des lignes, action, âge, conseil de montage. */
final class EtatPrepresseTest extends TestCase
{
    public function testEtatDUneLigne(): void
    {
        self::assertSame(EtatPrepresse::SANS_FICHIER, EtatPrepresse::ligne(false, null, false));
        self::assertSame(EtatPrepresse::A_CONTROLER, EtatPrepresse::ligne(true, null, false));
        self::assertSame(EtatPrepresse::A_CONTROLER, EtatPrepresse::ligne(true, ControlePrePresse::STATUT_A_CONTROLER, false));
        self::assertSame(EtatPrepresse::BAT, EtatPrepresse::ligne(true, ControlePrePresse::STATUT_EN_ATTENTE_BAT, false));
        self::assertSame(EtatPrepresse::PRET, EtatPrepresse::ligne(true, ControlePrePresse::STATUT_VALIDE, false));
        self::assertSame(EtatPrepresse::RETOUR, EtatPrepresse::ligne(true, ControlePrePresse::STATUT_ENVOYE_PRODUCTION, true));
    }

    public function testUneSeuleActionParCommande(): void
    {
        self::assertSame(['action' => 'deposer', 'libelle' => 'Déposer (4)', 'secondaire' => 'relancer'], EtatPrepresse::action(4, 2, 0, 0, true));
        self::assertSame('associer', EtatPrepresse::action(1, 0, 0, 0, false)['secondaire']);
        self::assertSame('controler', EtatPrepresse::action(0, 1, 0, 1, true)['action']);
        self::assertSame('corriger', EtatPrepresse::action(0, 0, 1, 0, true)['action']);
        self::assertSame('relancer', EtatPrepresse::action(0, 0, 0, 1, true)['action']);
        self::assertSame('voir', EtatPrepresse::action(0, 0, 0, 0, true)['action']);
    }

    public function testAgeEtCouleur(): void
    {
        $maintenant = new \DateTimeImmutable('2026-10-19 12:00');
        self::assertSame('12 j', EtatPrepresse::age($maintenant->modify('-12 days'), $maintenant));
        self::assertSame('2 h 10', EtatPrepresse::age($maintenant->modify('-130 minutes'), $maintenant));
        self::assertSame('35 min', EtatPrepresse::age($maintenant->modify('-35 minutes'), $maintenant));
        self::assertSame('rouge', EtatPrepresse::couleurAge($maintenant->modify('-4 days'), $maintenant));
        self::assertSame('orange', EtatPrepresse::couleurAge($maintenant->modify('-2 days'), $maintenant));
        self::assertSame('jaune', EtatPrepresse::couleurAge($maintenant->modify('-2 hours'), $maintenant));
    }

    public function testPetitsFormatsDuMemeSupport(): void
    {
        $vinyle = static fn (float $l, float $h): array => ['support' => 'Vinyle', 'largeurMm' => $l, 'hauteurMm' => $h];
        self::assertSame(['nombre' => 3, 'support' => 'Vinyle'], EtatPrepresse::petitsFormats([$vinyle(75, 80), $vinyle(55, 70), $vinyle(55, 55), ['support' => 'Bâche', 'largeurMm' => 3000.0, 'hauteurMm' => 1000.0]]));
        self::assertNull(EtatPrepresse::petitsFormats([$vinyle(75, 80), $vinyle(850, 2000)]));
    }

    public function testResume(): void
    {
        self::assertSame(['titre' => 'Étiquettes vinyle', 'detail' => '6 formats · 150 pièces'], EtatPrepresse::resume(array_fill(0, 6, 'Étiquettes vinyle'), 150));
        self::assertSame('Vinyle adhésif', EtatPrepresse::sansDimensions('Vinyle adhésif (7,5 x 8 cm)'));
    }
}
