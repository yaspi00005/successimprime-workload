<?php

namespace App\Tests\Unit\Service\Site;

use App\Entity\Commandes;
use App\Service\EspaceClient\SuiviSansCompte;
use App\Service\Site\SuiviSite;
use PHPUnit\Framework\TestCase;

final class SuiviSiteTest extends TestCase
{
    private \DateTimeImmutable $maintenant;

    protected function setUp(): void
    {
        // Mercredi.
        $this->maintenant = new \DateTimeImmutable('2026-10-14 11:30');
    }

    public function testLibellesDesEtapes(): void
    {
        self::assertSame('BAT validé', SuiviSite::libelle(['cle' => 'bat', 'libelle' => 'BAT validé par vous', 'court' => 'BAT', 'etat' => 'faite']));
        self::assertSame('Sans BAT', SuiviSite::libelle(['cle' => 'bat', 'libelle' => 'Sans BAT', 'court' => 'BAT', 'etat' => 'faite']));
        self::assertSame('BAT', SuiviSite::libelle(['cle' => 'bat', 'libelle' => 'BAT', 'court' => 'BAT', 'etat' => 'en_cours']));
        self::assertSame('Prête', SuiviSite::libelle(['cle' => 'prete', 'libelle' => 'Prête à retirer', 'court' => 'Prête', 'etat' => 'a_venir']));
    }

    public function testDatesCourtes(): void
    {
        self::assertSame('lun. 10:12', SuiviSite::quand(new \DateTimeImmutable('2026-10-12 10:12'), $this->maintenant));
        self::assertSame('mer.', SuiviSite::quand(new \DateTimeImmutable('2026-10-14 00:00'), $this->maintenant), 'sans heure connue');
        self::assertSame('02/10 09:05', SuiviSite::quand(new \DateTimeImmutable('2026-10-02 09:05'), $this->maintenant));
        self::assertNull(SuiviSite::quand(null, $this->maintenant));
    }

    public function testJourPrevu(): void
    {
        self::assertSame('aujourd\'hui', SuiviSite::jour(new \DateTimeImmutable('2026-10-14 17:00'), $this->maintenant));
        self::assertSame('demain', SuiviSite::jour(new \DateTimeImmutable('2026-10-15 09:00'), $this->maintenant));
        self::assertSame('vendredi', SuiviSite::jour(new \DateTimeImmutable('2026-10-16 09:00'), $this->maintenant));
        self::assertSame('le 24/10', SuiviSite::jour(new \DateTimeImmutable('2026-10-24 09:00'), $this->maintenant));
        self::assertNull(SuiviSite::jour(new \DateTimeImmutable('2026-10-13 09:00'), $this->maintenant), 'date passée : rien');
    }

    public function testPastilleSansMontant(): void
    {
        $commande = (new Commandes())->setDateLivraison(new \DateTimeImmutable('2026-10-15 16:00'));
        $carte = static fn (string $cle, array $extra = []): array => $extra + [
            'commande' => $commande, 'etapes' => [], 'courante' => ['cle' => $cle], 'annulee' => false, 'terminee' => false, 'batEnAttente' => false,
        ];

        self::assertSame(['texte' => 'En finition · prête demain', 'ton' => 'orange'], SuiviSite::pastille($carte('finition'), $this->maintenant));
        self::assertSame(['texte' => 'BAT à valider · prête demain', 'ton' => 'rouge'], SuiviSite::pastille($carte('bat', ['batEnAttente' => true]), $this->maintenant));
        self::assertSame(['texte' => 'Prête à retirer', 'ton' => 'vert'], SuiviSite::pastille($carte('livree'), $this->maintenant));
        self::assertSame(['texte' => 'Commande annulée', 'ton' => 'rouge'], SuiviSite::pastille($carte('impression', ['annulee' => true]), $this->maintenant));
        self::assertSame(['texte' => 'Retirée', 'ton' => 'vert'], SuiviSite::pastille($carte('impression', ['terminee' => true, 'courante' => null]), $this->maintenant));
    }

    public function testNumeroCourtRetrouveLaCommande(): void
    {
        self::assertTrue(SuiviSansCompte::correspond('0142', 'CMD-000142-10-2026'));
        self::assertTrue(SuiviSansCompte::correspond('cmd 142', 'W-CMD-000142-10-2026'));
        self::assertTrue(SuiviSansCompte::correspond('767', 'CMD-D00767'));
        self::assertFalse(SuiviSansCompte::correspond('1420', 'CMD-000142-10-2026'));
        self::assertFalse(SuiviSansCompte::correspond('0', 'CMD-000000'));
        self::assertFalse(SuiviSansCompte::correspond('%', 'CMD-000142'));
        self::assertSame('W-CMD-TEST-331', SuiviSansCompte::numeroPropre(' w-cmd-test-331 '));
    }
}
