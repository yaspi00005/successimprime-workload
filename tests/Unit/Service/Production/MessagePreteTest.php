<?php

namespace App\Tests\Unit\Service\Production;

use App\Service\Production\MessagePrete;
use App\Service\Production\NomsProduits;
use PHPUnit\Framework\TestCase;

/**
 * Message WhatsApp « commande prête » (PD06, docs/production-colis.md) :
 * livraison ou retrait, jour et créneau lisibles, code de réception, jamais
 * de montant, aucun lien pour une fiche de démonstration.
 */
final class MessagePreteTest extends TestCase
{
    private static function auj(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-10-20 11:04');
    }

    public function testLivraisonDemainAvecLeCode(): void
    {
        $texte = MessagePrete::texte([['nom' => NomsProduits::reconnaitre('Bâche'), 'quantite' => 2]], 'CMD-000183', true, new \DateTimeImmutable('2026-10-21'), '10-12', '4827', self::auj());

        self::assertSame('Vos 2 bâches sont prêtes. Livraison demain entre 10 h et 12 h. Votre code de réception : 4827.', $texte);
    }

    public function testDateDeLivraisonPasseeNonAnnoncee(): void
    {
        $texte = MessagePrete::texte([['nom' => NomsProduits::reconnaitre('Bâche'), 'quantite' => 2]], 'CMD-000183', true, new \DateTimeImmutable('2026-10-19'), '10-12', null, self::auj());

        self::assertSame('Vos 2 bâches sont prêtes. Nous vous appelons pour fixer l\'heure de la livraison.', $texte);
    }

    public function testRetraitALImprimerie(): void
    {
        self::assertSame(
            'Votre kakémono est prêt. Vous pouvez le retirer à l\'imprimerie.',
            MessagePrete::texte([['nom' => NomsProduits::reconnaitre('Kakémono'), 'quantite' => 1]], 'CMD-000430', false, null, null, '1234', self::auj())
        );
        self::assertSame(
            'Vos 2 kakémonos sont prêts. Vous pouvez les retirer à l\'imprimerie.',
            MessagePrete::texte([['nom' => NomsProduits::reconnaitre('Kakémono'), 'quantite' => 2]], 'CMD-000186', false, null, null, null, self::auj())
        );
    }

    public function testProduitInconnuOuPlusieursLignes(): void
    {
        self::assertSame(
            'Votre commande CMD-000190 est prête. Vous pouvez la retirer à l\'imprimerie.',
            MessagePrete::texte([['nom' => null, 'quantite' => 3]], 'CMD-000190', false, null, null, null, self::auj())
        );
        self::assertStringStartsWith(
            'Vos 2 bâches et 1 000 flyers sont prêts.',
            MessagePrete::texte([['nom' => NomsProduits::reconnaitre('Bâche'), 'quantite' => 2], ['nom' => NomsProduits::reconnaitre('Flyers'), 'quantite' => 1000]], 'CMD-1', true, null, null, null, self::auj())
        );
    }

    public function testJamaisDeMontant(): void
    {
        $texte = MessagePrete::texte([['nom' => null, 'quantite' => 1]], 'reste 15 000 F', true, new \DateTimeImmutable('2026-10-24'), '09:00-11:00', null, self::auj());

        self::assertStringNotContainsString('15 000 F', $texte);
        self::assertStringContainsString('Livraison le samedi 24/10 entre 9 h et 11 h.', $texte);
        self::assertStringNotContainsString('code', $texte);
    }

    public function testJoursEtCreneaux(): void
    {
        self::assertSame('aujourd\'hui', MessagePrete::jour(new \DateTimeImmutable('2026-10-20'), self::auj()));
        self::assertSame('le mercredi 28/10', MessagePrete::jour(new \DateTimeImmutable('2026-10-28'), self::auj()));
        self::assertSame('entre 10 h et 12 h', MessagePrete::creneau('10 h – 12 h'));
        self::assertSame('entre 14 h 30 et 16 h', MessagePrete::creneau('14h30-16h'));
        self::assertSame('(dans la matinée)', MessagePrete::creneau('dans la matinée'));
        self::assertNull(MessagePrete::creneau(''));
    }

    public function testAucunLienPourUneFicheDeDemonstration(): void
    {
        self::assertNull(MessagePrete::lien('76 12 34 56', 'Vos 2 bâches sont prêtes.', true));
        self::assertNull(MessagePrete::lien(null, 'Vos 2 bâches sont prêtes.', false));
        self::assertSame('https://wa.me/22376123456?text=Vos%202%20b%C3%A2ches%20sont%20pr%C3%AAtes.', MessagePrete::lien('76 12 34 56', 'Vos 2 bâches sont prêtes.', false));
    }
}
