<?php

namespace App\Tests\Unit\Entity;

use App\Entity\CommandeDetailFichier;
use App\Entity\CommandeDetailFinition;
use App\Entity\CommandesDetails;
use App\Service\Prepresse\DepotFichiers;
use App\Service\Prepresse\RegleDecoupe;
use PHPUnit\Framework\TestCase;

/** Fichier de découpe d'une ligne (docs/prepresse.md). */
final class CommandesDetailsDecoupeTest extends TestCase
{
    private static function fichier(CommandesDetails $ligne, string $nom, string $role): CommandeDetailFichier
    {
        $f = (new CommandeDetailFichier())->setJetonUpload(bin2hex(random_bytes(8)))->setNomOriginal($nom)->setNomStockage($nom)->setRole($role)->marquerUploadTermine();
        $ligne->addFichier($f);

        return $f;
    }

    public function testFinitionDeDecoupeDemandeLeFichier(): void
    {
        $ligne = new CommandesDetails();
        self::assertSame(RegleDecoupe::NON_REQUIS, $ligne->etatDecoupe());

        $ligne->addFinition((new CommandeDetailFinition())->setNomFinition('Découpe à la forme'));
        self::assertTrue($ligne->besoinFichierDecoupe());
        self::assertSame(RegleDecoupe::MANQUANT, $ligne->etatDecoupe());

        // Le fichier à imprimer ne compte pas comme fichier de découpe, et inversement.
        self::fichier($ligne, 'vinyle.pdf', CommandeDetailFichier::ROLE_IMPRESSION);
        self::assertSame(RegleDecoupe::MANQUANT, $ligne->etatDecoupe());
        $decoupe = self::fichier($ligne, 'vinyle_decoupe.pdf', CommandeDetailFichier::ROLE_DECOUPE);
        self::assertSame(RegleDecoupe::PRESENT, $ligne->etatDecoupe());
        self::assertSame($decoupe, $ligne->fichierDecoupe());
        self::assertSame(['vinyle.pdf'], array_map(static fn (CommandeDetailFichier $f): ?string => $f->getNomOriginal(), DepotFichiers::fichiersRecus($ligne)));
    }

    public function testCaseDeLaPrepresseEtDecoupeSansFichier(): void
    {
        $ligne = (new CommandesDetails())->setFichierDecoupeRequis(true);
        self::assertSame(RegleDecoupe::MANQUANT, $ligne->etatDecoupe());
        $ligne->setDecoupeSansFichier(true);
        self::assertSame(RegleDecoupe::SANS_FICHIER, $ligne->etatDecoupe());

        $ligne = (new CommandesDetails())->setFichierDecoupeRequis(false);
        $ligne->addFinition((new CommandeDetailFinition())->setNomFinition('Contour plotter'));
        self::assertSame(RegleDecoupe::NON_REQUIS, $ligne->etatDecoupe());
    }

    public function testRoleDuFichierDepose(): void
    {
        self::assertSame(CommandeDetailFichier::ROLE_DECOUPE, DepotFichiers::role('CMD-1_cut.pdf'));
        self::assertSame(CommandeDetailFichier::ROLE_IMPRESSION, DepotFichiers::role('CMD-1_5x7.pdf'));
        // Le choix de la fenêtre l'emporte sur le nom.
        self::assertSame(CommandeDetailFichier::ROLE_IMPRESSION, DepotFichiers::role('CMD-1_cut.pdf', CommandeDetailFichier::ROLE_IMPRESSION));
        self::assertSame(CommandeDetailFichier::ROLE_DECOUPE, DepotFichiers::role('contour.ai', 'nimporte'));
        $this->expectException(\InvalidArgumentException::class);
        (new CommandeDetailFichier())->setRole('autre');
    }
}
