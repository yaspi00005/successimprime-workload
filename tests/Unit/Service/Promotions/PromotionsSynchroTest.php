<?php

namespace App\Tests\Unit\Service\Promotions;

use App\Entity\CodePromo;
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

    public function testUnCodePromoNeDescendQuEnEmpreinte(): void
    {
        $t = CatalogueSynchro::types()['code_promo'];
        self::assertSame(CodePromo::class, $t['classe']);
        // Jamais le code en clair, ni le lot, ni qui l'a créé ou désactivé.
        foreach (['code', 'lot', 'creePar', 'desactivePar', 'desactiveLe'] as $champ) {
            self::assertNotContains($champ, $t['champs'], $champ);
            self::assertArrayNotHasKey($champ, $t['relations'], $champ);
        }
        self::assertSame(['empreinte', 'type', 'debut', 'expiration', 'utilisationsMax', 'utilisationsParClient', 'utilisations', 'active', 'creeLe'], $t['champs']);
        self::assertSame(['promotion' => 'promotion'], $t['relations']);
        self::assertTrue(CatalogueSynchro::envoye('code_promo', false));
        self::assertFalse(CatalogueSynchro::envoye('code_promo', true), 'un code ne se crée pas en ligne');
        self::assertFalse(CatalogueSynchro::accepte('code_promo', false));
        self::assertSame('code_promo', CatalogueSynchro::typeDe(new CodePromo()));
        // Sans empreinte (pas de clé de synchronisation), le code ne part pas.
        self::assertFalse($t['pret'](new CodePromo()));
        self::assertTrue($t['pret']((new CodePromo())->setEmpreinte(str_repeat('a', 64))));

        // Avant les commandes et les devis qui le citent, après sa promotion.
        $ordre = array_keys(CatalogueSynchro::types());
        self::assertLessThan(array_search('code_promo', $ordre, true), array_search('promotion', $ordre, true));
        self::assertLessThan(array_search('commande', $ordre, true), array_search('code_promo', $ordre, true));
        self::assertLessThan(array_search('devis', $ordre, true), array_search('code_promo', $ordre, true));
    }

    public function testLesDocumentsGardentLeCodeTapeEtLAvantageMaisPasLeMotif(): void
    {
        foreach (['commande', 'devis'] as $type) {
            $t = CatalogueSynchro::types()[$type];
            self::assertContains('codeSaisi', $t['champs']);
            self::assertContains('remiseCodePromo', $t['champs']);
            self::assertNotContains('motifCodePromo', $t['champs'], 'le motif est pour l\'équipe');
            self::assertSame('code_promo', $t['relations']['codePromo']);
            self::assertContains('codePromo', $t['facultatives']);
        }
        // Une commande passée en ligne arrive sans code retenu : seule l'imprimerie le retient, en la confirmant.
        self::assertArrayHasKey('codePromo', CatalogueSynchro::types()['commande']['imposeAuLocal']);
        self::assertNull(CatalogueSynchro::types()['commande']['imposeAuLocal']['codePromo']);
        self::assertArrayNotHasKey('codePromo', CatalogueSynchro::types()['commande']['remontees'] ?? []);
        self::assertArrayNotHasKey('remiseCodePromo', CatalogueSynchro::types()['commande']['remontees'] ?? []);
        foreach (['commande_ligne', 'devis_ligne'] as $type) {
            self::assertContains('remiseCodePromo', CatalogueSynchro::types()[$type]['champs']);
        }
        $promotion = CatalogueSynchro::types()['promotion']['champs'];
        foreach (['avantageCommande', 'valeurCommande', 'plafondCommande', 'minimumCommande', 'aCodes'] as $champ) {
            self::assertContains($champ, $promotion);
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
        // Avec des codes générés, elle reste active : le site vérifie le code tapé par le client.
        $p->setCode(null)->setACodes(true);
        self::assertTrue($filtre(true, $p));
        self::assertFalse($p->estSansCode(), 'mais elle ne paraît pas dans « Mes promotions »');
    }
}
