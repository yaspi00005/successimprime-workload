<?php

namespace App\Tests\Unit\Service\Fichiers;

use App\Entity\CommandeDetailFichier;
use App\Entity\CommandesDetails;
use App\Service\Fichiers\ConservationFichiers;
use PHPUnit\Framework\TestCase;

final class ConservationFichiersTest extends TestCase
{
    private function fichier(?string $imprime): CommandeDetailFichier
    {
        $ligne = new CommandesDetails();
        if (null !== $imprime) {
            (new \ReflectionProperty(CommandesDetails::class, 'productionTermineeLe'))->setValue($ligne, new \DateTimeImmutable($imprime));
        }

        return (new CommandeDetailFichier())->setCommandeDetail($ligne);
    }

    public function testEffaceQuatreMoisApresImpression(): void
    {
        $le = ConservationFichiers::effacementLe($this->fichier('2026-03-10 15:00'), 4, 12, false);
        self::assertSame('2026-07-10', $le?->format('Y-m-d'));
    }

    public function testGarderUnAn(): void
    {
        $f = $this->fichier('2026-03-10 15:00');
        $f->garderPlusLongtemps('Awa (client)', new \DateTimeImmutable('2026-04-01'));
        self::assertSame('2027-03-10', ConservationFichiers::effacementLe($f, 4, 12, false)?->format('Y-m-d'));
        $f->annulerGarde();
        self::assertSame('2026-07-10', ConservationFichiers::effacementLe($f, 4, 12, false)?->format('Y-m-d'));
    }

    public function testJamaisEffacesMesProduitsPasImprimeLogoDejaEfface(): void
    {
        self::assertNull(ConservationFichiers::effacementLe($this->fichier('2026-03-10'), 4, 12, true), 'Mes produits');
        self::assertNull(ConservationFichiers::effacementLe($this->fichier(null), 4, 12, false), 'pas encore imprimé');
        self::assertNull(ConservationFichiers::effacementLe($this->fichier('2026-03-10')->setLogo(true), 4, 12, false), 'logo');
        self::assertNull(ConservationFichiers::effacementLe($this->fichier('2026-03-10')->marquerEfface(new \DateTimeImmutable()), 4, 12, false), 'déjà effacé');
    }
}
