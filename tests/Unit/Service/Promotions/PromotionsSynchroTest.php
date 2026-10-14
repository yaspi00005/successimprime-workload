<?php

namespace App\Tests\Unit\Service\Promotions;

use App\Entity\Promotion;
use App\Service\Synchro\CatalogueSynchro;
use PHPUnit\Framework\TestCase;

/** Ce que l'espace client reçoit des promotions (docs/promotions.md). */
final class PromotionsSynchroTest extends TestCase
{
    public function testLeCodeEtLaNoteRestentALaBoutique(): void
    {
        $t = CatalogueSynchro::types()['promotion'];
        self::assertSame(Promotion::class, $t['classe']);
        foreach (['code', 'note', 'creePar', 'creeLe', 'modifiePar', 'modifieLe'] as $champ) {
            self::assertNotContains($champ, $t['champs'], $champ);
            self::assertArrayNotHasKey($champ, $t['relations'], $champ);
        }
        // Le site reçoit, il ne renvoie rien.
        self::assertTrue(CatalogueSynchro::envoye('promotion', false));
        self::assertFalse(CatalogueSynchro::envoye('promotion', true));
        self::assertFalse(CatalogueSynchro::accepte('promotion', false));
        self::assertSame('promotion', CatalogueSynchro::typeDe(new Promotion()));
        // Les lignes gardent le libellé de leur promotion, pas son lien.
        foreach (['commande_ligne', 'devis_ligne'] as $type) {
            self::assertContains('libellePromotion', CatalogueSynchro::types()[$type]['champs']);
            self::assertNotContains('promotionRetiree', CatalogueSynchro::types()[$type]['champs']);
        }
    }

    public function testUnePromotionACodeResteEteinteEnLigne(): void
    {
        $filtre = CatalogueSynchro::types()['promotion']['filtres']['active'];
        $p = new Promotion();
        self::assertTrue($filtre(true, $p));
        self::assertFalse($filtre(false, $p), 'suspendue');
        $p->setCode('ETE');
        self::assertFalse($filtre(true, $p), 'le code se donne au comptoir');
    }
}
