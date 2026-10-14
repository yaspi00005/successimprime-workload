<?php

namespace App\Tests\Unit\Service\Fidelite;

use App\Entity\Commandes;
use App\Entity\CompteFidelite;
use App\Entity\MouvementFidelite;
use App\Entity\Paiements;
use App\Entity\User;
use App\Service\Fidelite\Fidelite;
use App\Service\Synchro\CatalogueSynchro;
use PHPUnit\Framework\TestCase;

/**
 * Relevé montré à la boutique et au client, résumé reçu par le site, et ce
 * qui part en ligne (docs/fidelite.md).
 */
final class FideliteTest extends TestCase
{
    private static function mouvement(string $type, int $points, string $date, ?Paiements $p = null, ?int $mult = null): MouvementFidelite
    {
        return (new MouvementFidelite())->setType($type)->setPoints($points)->setDate(new \DateTimeImmutable($date))->setPaiement($p)->setMultiplicateur($mult);
    }

    public function testLesGainsDunPaiementSontReunis(): void
    {
        $p = new Paiements();
        (new \ReflectionProperty(Paiements::class, 'id'))->setValue($p, 12);
        $agent = new User();
        $mouvements = [
            self::mouvement(MouvementFidelite::AJUSTEMENT, 500, '2026-10-12 10:00')->setAgent($agent)->setMotif('Geste commercial'),
            self::mouvement(MouvementFidelite::GAIN, 300, '2026-10-10 09:00', $p, 2),
            self::mouvement(MouvementFidelite::GAIN, 0, '2026-10-10 09:00', $p, 2),
            self::mouvement(MouvementFidelite::GAIN, 200, '2026-10-10 09:00', $p, 2),
            self::mouvement(MouvementFidelite::GAIN, 0, '2026-10-01 09:00', null, 0)->setCommande(new Commandes()),
        ];

        $boutique = Fidelite::historique($mouvements, false);
        self::assertCount(3, $boutique);
        self::assertSame(500, $boutique[1]['points']);
        self::assertSame(3, $boutique[1]['lignes']);
        self::assertSame('Payé le jour même ×2', $boutique[1]['libelle']);
        self::assertSame('Geste commercial', $boutique[0]['motif']);
        self::assertSame($agent, $boutique[0]['agent']);
        self::assertSame('Payé après le délai', $boutique[2]['libelle']);

        // Le client : ni agent, ni motif, ni ligne à 0 point.
        $client = Fidelite::historique($mouvements, true, 40, 48);
        self::assertCount(2, $client);
        self::assertNull($client[0]['motif']);
        self::assertNull($client[0]['agent']);
        self::assertSame(500, $client[1]['points']);
        self::assertSame('Payé dans les 48 h', Fidelite::libelle(self::mouvement(MouvementFidelite::GAIN, 10, 'now', null, 1), 48));
    }

    public function testResumeRecuParLeSite(): void
    {
        $vide = Fidelite::resumeEspace(null);
        self::assertSame(0, $vide['disponible']);
        self::assertSame(5.0, $vide['m2']['seuil']);
        self::assertFalse($vide['m2']['eligible']);
        self::assertSame(25000.0, $vide['autre']['seuil']);
        self::assertSame(2, $vide['multiplicateurJour']);
        self::assertNull($vide['majLe']);

        $compte = new CompteFidelite();
        $compte->majResume(['exclu' => false, 'solde' => 1250, 'soldeF' => 1500, 'm2' => 3.2, 'montant' => 30000, 'seuilM2' => 5.0, 'seuilMontant' => 25000,
            'pointsParM2' => 100.0, 'tauxAutre' => 2.0, 'multiplicateurJour' => 2, 'delaiHeures' => 72, 'expirant' => 300, 'expiration' => new \DateTimeImmutable('2026-11-01')], new \DateTimeImmutable());
        $r = Fidelite::resumeEspace($compte);
        self::assertSame(1250, $r['disponible']);
        self::assertSame(1500, $r['disponibleF']);
        self::assertSame(64, $r['m2']['part']);
        self::assertFalse($r['m2']['eligible']);
        self::assertTrue($r['autre']['eligible']);
        self::assertSame(300, $r['expirant']);
    }

    public function testCeQuiPartEnLigne(): void
    {
        $types = CatalogueSynchro::types();
        foreach (['fidelite_compte', 'fidelite_mouvement', 'promo_reveil'] as $type) {
            self::assertSame(CatalogueSynchro::DESCENDANT, $types[$type]['sens'], $type);
            self::assertFalse(CatalogueSynchro::accepte($type, false), $type.' ne remonte pas');
            foreach (['agent', 'motif', 'motifPerso', 'persoPar', 'creePar', 'ligne', 'paiement', 'produit', 'regle', 'cible', 'montant', 'montantRemise', 'pointsParM2', 'tauxAutre', 'seuilM2', 'seuilMontant'] as $champ) {
                self::assertNotContains($champ, $types[$type]['champs'], $type.'.'.$champ);
                self::assertArrayNotHasKey($champ, $types[$type]['relations'], $type.'.'.$champ);
            }
        }
        self::assertSame(['type', 'points', 'date', 'expireLe', 'multiplicateur'], $types['fidelite_mouvement']['champs']);
        // Une ligne à 0 point ne part pas.
        self::assertFalse($types['fidelite_mouvement']['pret']((new MouvementFidelite())->setPoints(0)));
        self::assertTrue($types['fidelite_mouvement']['pret']((new MouvementFidelite())->setPoints(-40)));
        self::assertSame('fidelite_mouvement', CatalogueSynchro::typeDe(new MouvementFidelite()));
    }
}
