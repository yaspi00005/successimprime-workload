<?php

namespace App\Tests\Unit\Service\MessagerieInterne;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Service\MessagerieInterne\CartesCommande;
use PHPUnit\Framework\TestCase;

/** Carte de commande dans un message (MI01) : jamais de montant sans le droit Paiements. */
final class CartesCommandeTest extends TestCase
{
    private static function commande(): Commandes
    {
        $c = (new Commandes())->setNumero('CMD-PM0182')->setTotalTtc(24960)->setResteAPayer(10000)
            ->setClients((new Clients())->setRaisonSociale('École Les Étoiles'));
        $c->addCommandesDetail((new CommandesDetails())->setDesignation('Kakémonos')->setQuantite(4));

        return $c;
    }

    public function testSansLeDroitPaiementsLaCarteNaPasDeMontant(): void
    {
        $carte = CartesCommande::carteDe(self::commande(), ['libelle' => 'Au prépresse', 'ton' => 'rose'], false, false);
        self::assertNull($carte['total']);
        self::assertNull($carte['reste']);
        self::assertFalse($carte['lien']);
        self::assertSame('CMD-PM0182', $carte['numero']);
        self::assertSame('Kakémonos × 4', $carte['article']);
        self::assertSame('Au prépresse', $carte['statut']);
        self::assertStringNotContainsString('24960', json_encode($carte, \JSON_THROW_ON_ERROR));
    }

    public function testAvecLeDroitPaiementsLesMontantsSontLa(): void
    {
        $carte = CartesCommande::carteDe(self::commande(), ['libelle' => 'Prête', 'ton' => 'vert'], true, true);
        self::assertSame(24960, $carte['total']);
        self::assertIsInt($carte['reste']);
        self::assertTrue($carte['lien']);
    }

    public function testReferencesCitees(): void
    {
        self::assertSame(['CM-0182', 'CMD-PM0173'], CartesCommande::references('le fichier de CM-0182 et cmd-pm0173, pas XCMD-12 ni CMD-1'));
        self::assertSame([], CartesCommande::references('rien ici'));
        self::assertTrue(CartesCommande::memeNombre('CMD-PM0182', '0182'));
        self::assertFalse(CartesCommande::memeNombre('CMD-PM1182', '182'));
        self::assertFalse(CartesCommande::memeNombre('CMD-PM0183', '0182'));
    }
}
