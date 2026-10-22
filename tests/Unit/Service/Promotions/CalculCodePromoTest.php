<?php

namespace App\Tests\Unit\Service\Promotions;

use App\Entity\Clients;
use App\Entity\CodePromo;
use App\Entity\Promotion;
use App\Service\Promotions\CalculCodePromo;
use PHPUnit\Framework\TestCase;

/** Règles des codes promo générés (docs/promotions.md, « Codes promo »). */
final class CalculCodePromoTest extends TestCase
{
    private \DateTimeImmutable $jour;

    protected function setUp(): void
    {
        $this->jour = new \DateTimeImmutable('2026-11-10');
    }

    /* ---------- Forme et génération ---------- */

    public function testAlphabetSansCaracteresAConfondre(): void
    {
        foreach (['0', 'O', '1', 'I', 'L'] as $ambigu) {
            self::assertStringNotContainsString($ambigu, CalculCodePromo::ALPHABET);
        }
        self::assertSame(31, \strlen(CalculCodePromo::ALPHABET));
        self::assertSame(\strlen(CalculCodePromo::ALPHABET), \count(array_unique(str_split(CalculCodePromo::ALPHABET))));
    }

    public function testCodesDeLotTiresAuHasardDansLAlphabet(): void
    {
        $motif = '/^SI['.CalculCodePromo::ALPHABET.']{6}$/';
        $codes = [];
        for ($i = 0; $i < 2000; ++$i) {
            $c = CalculCodePromo::genererCode();
            self::assertMatchesRegularExpression($motif, $c);
            $codes[$c] = true;
        }
        // 31^6 ≈ 887 millions de codes : 2 000 tirages ne se répètent (presque) jamais ; la base refuse de toute façon un doublon.
        self::assertGreaterThanOrEqual(1995, \count($codes));
        // Tous les caractères finissent par sortir : le tirage couvre l'alphabet.
        self::assertCount(31, array_unique(str_split(implode('', array_map(static fn (string $c): string => substr($c, 2), array_keys($codes))))));
    }

    public function testFormeCanoniqueEtCodePartage(): void
    {
        self::assertSame('SI7K4P2Q', CalculCodePromo::canonique(' si-7k4p 2q '));
        self::assertSame('RENTREE25', CalculCodePromo::canonique('Rentrée25'), 'les lettres accentuées tombent');
        self::assertSame('', CalculCodePromo::canonique(null));
        self::assertSame(30, \strlen(CalculCodePromo::canonique(str_repeat('A', 50))));

        self::assertNull(CalculCodePromo::erreurCodePartage('RENTREE25'));
        self::assertNotNull(CalculCodePromo::erreurCodePartage('ABC'));
        self::assertNotNull(CalculCodePromo::erreurCodePartage(str_repeat('A', 21)));
    }

    public function testCodeDeLotAfficheAvecSesTirets(): void
    {
        $lot = CodePromo::creer($this->promotion(), CodePromo::TYPE_LOT, 'SI7K4P2Q', 'x', $this->jour, $this->jour);
        self::assertSame('SI-7K4P-2Q', $lot->getCodeAffiche());
        $partage = CodePromo::creer($this->promotion(), CodePromo::TYPE_PARTAGE, 'RENTREE25', 'x', $this->jour, $this->jour);
        self::assertSame('RENTREE25', $partage->getCodeAffiche());
        // Le journal ne garde jamais le code d'un lot en clair.
        self::assertNull($lot->instantane()['code']);
        self::assertSame('RENTREE25', $partage->instantane()['code']);

        // Sur le document : le code retenu ; en ligne, sa copie n'a que l'empreinte, et le code tapé se montre.
        $commande = (new \App\Entity\Commandes())->setCodePromo($lot)->setCodeSaisi('SI7K4P2Q');
        self::assertSame('SI-7K4P-2Q', $commande->getLibelleCodePromo());
        $commande->setCodePromo((new CodePromo())->setEmpreinte('x'));
        self::assertSame('SI-7K4P-2Q', $commande->getLibelleCodePromo());
        self::assertSame('RENTREE25', (new \App\Entity\Devis())->setCodeSaisi('RENTREE25')->getLibelleCodePromo());
    }

    public function testEmpreintePourLeSiteSansLeCode(): void
    {
        $e = CalculCodePromo::empreinte('SI7K4P2Q', 'cle-a');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $e);
        self::assertSame($e, CalculCodePromo::empreinte('SI7K4P2Q', 'cle-a'));
        self::assertNotSame($e, CalculCodePromo::empreinte('SI7K4P2Q', 'cle-b'), 'sans la clé, rien à retrouver');
        self::assertNotSame($e, hash('sha256', 'SI7K4P2Q'));
        self::assertStringNotContainsString('SI7K4P2Q', $e);
    }

    /* ---------- Validité ---------- */

    public function testChaqueRegleDeValidite(): void
    {
        $p = $this->promotion();
        $code = $this->code($p, max: 5, parClient: 1);

        self::assertNull($this->refus($code));
        self::assertSame('client', CalculCodePromo::refus($code, null, $this->jour, 0, 0, false, ['tous']));

        $code->desactiver(null, $this->jour);
        self::assertSame('desactive', $this->refus($code));
        // Déjà sur le document : il le garde.
        self::assertNull(CalculCodePromo::refus($code, false, $this->jour, 0, 0, true, ['tous']));
        $code->reactiver();
        $p->setActive(false);
        self::assertSame('desactive', $this->refus($code), 'promotion suspendue');
        $p->setActive(true);

        $code->setDebut(new \DateTimeImmutable('2026-11-15'));
        self::assertSame('pas_encore', $this->refus($code));
        $code->setDebut(null);

        $code->setExpiration(new \DateTimeImmutable('2026-11-09'));
        self::assertSame('expire', $this->refus($code));
        // Le jour d'expiration compte encore (jugé à la date du document).
        $code->setExpiration(new \DateTimeImmutable('2026-11-10'));
        self::assertNull($this->refus($code));
        // Jamais après la fin de la promotion.
        $code->setExpiration(new \DateTimeImmutable('2026-12-31'));
        $p->setFin(new \DateTimeImmutable('2026-11-09'));
        self::assertSame('expire', $this->refus($code));
        $p->setFin(new \DateTimeImmutable('2026-11-30'));

        self::assertSame('b2b', CalculCodePromo::refus($code, true, $this->jour, 0, 0, false, ['tous']));

        $p->setSegment('nouveaux');
        self::assertSame('segment', $this->refus($code));
        self::assertNull(CalculCodePromo::refus($code, false, $this->jour, 0, 0, false, ['nouveaux']));
        $p->setSegment(Promotion::SEGMENT_LISTE);
        self::assertSame('segment', CalculCodePromo::refus($code, false, $this->jour, 0, 0, false, [], false));
        self::assertNull(CalculCodePromo::refus($code, false, $this->jour, 0, 0, false, [], true));
        $p->setSegment(Promotion::SEGMENT_TOUS);

        self::assertSame('epuise', $this->refus($code, autres: 5));
        self::assertSame('deja_utilise', $this->refus($code, autres: 1, duClient: 1));
    }

    public function testDeuxUtilisationsDeSuite(): void
    {
        // Code partagé : 2 en tout, 1 par client.
        $partage = $this->code($this->promotion(), max: 2, parClient: 1);
        self::assertNull($this->refus($partage, autres: 0, duClient: 0), 'première commande');
        self::assertSame('deja_utilise', $this->refus($partage, autres: 1, duClient: 1), 'le même client, juste après');

        // « Commandes par client » de la promotion : pour tous ses codes ensemble (deux coupons d'un même lot).
        $p = $partage->getPromotion();
        $p->setUtilisationsParClient(1);
        self::assertNull(CalculCodePromo::refus($partage, false, $this->jour, 0, 0, false, ['tous'], false, 0));
        self::assertSame('promotion_utilisee', CalculCodePromo::refus($partage, false, $this->jour, 0, 0, false, ['tous'], false, 1));
        self::assertSame(CalculCodePromo::GENERIQUE.' Vous avez déjà profité de cette offre.', CalculCodePromo::message('promotion_utilisee', false));
        $p->setUtilisationsParClient(null);
        self::assertNull(CalculCodePromo::refus($partage, false, $this->jour, 0, 0, false, ['tous'], false, 3));
        self::assertNull($this->refus($partage, autres: 1, duClient: 0), 'un autre client');
        self::assertSame('epuise', $this->refus($partage, autres: 2, duClient: 0), 'troisième commande');

        // Code d'un lot : une seule utilisation, quel que soit le client.
        $lot = CodePromo::creer($this->promotion(), CodePromo::TYPE_LOT, 'SI7K4P2Q', 'x', new \DateTimeImmutable('2026-11-30'), $this->jour);
        self::assertSame(1, $lot->getUtilisationsMax());
        self::assertNull($this->refus($lot));
        self::assertSame('epuise', $this->refus($lot, autres: 1));
    }

    public function testEtatPourLaListe(): void
    {
        $code = $this->code($this->promotion(), max: 2, parClient: 1);
        self::assertSame('actif', $code->etat($this->jour));
        $code->setUtilisations(2);
        self::assertSame('epuise', $code->etat($this->jour));
        self::assertSame('expire', $code->etat(new \DateTimeImmutable('2026-12-01')));
        $code->desactiver(null, $this->jour);
        self::assertSame('desactive', $code->etat($this->jour));
    }

    /* ---------- Avantage sur la commande ---------- */

    public function testPourcentagePlafonneEtMontantFixe(): void
    {
        $p = $this->promotion()->setAvantageCommande(Promotion::COMMANDE_POURCENTAGE)->setValeurCommande(10);
        self::assertSame(5000, CalculCodePromo::montantCommande($p, 50000));
        $p->setPlafondCommande(3000);
        self::assertSame(3000, CalculCodePromo::montantCommande($p, 50000));
        $p->setPlafondCommande(null)->setValeurCommande(95);
        self::assertSame(45000, CalculCodePromo::montantCommande($p, 50000), '90 % au plus');

        $p->setAvantageCommande(Promotion::COMMANDE_MONTANT)->setValeurCommande(5000);
        self::assertSame(5000, CalculCodePromo::montantCommande($p, 50000));
        self::assertSame(3000, CalculCodePromo::montantCommande($p, 3000), 'jamais plus que la commande');
        self::assertSame(0, CalculCodePromo::montantCommande($p, 0));

        self::assertSame(0, CalculCodePromo::montantCommande($this->promotion(), 50000), 'pas d\'avantage sur la commande');
    }

    public function testMinimumDeCommande(): void
    {
        $p = $this->promotion();
        self::assertTrue(CalculCodePromo::minimumAtteint($p, 0));
        $p->setMinimumCommande(20000);
        self::assertFalse(CalculCodePromo::minimumAtteint($p, 19999));
        self::assertTrue(CalculCodePromo::minimumAtteint($p, 20000));
    }

    public function testRepartitionExacteAuProrata(): void
    {
        $parts = CalculCodePromo::repartir(1000, ['a' => 10000, 'b' => 20000, 'c' => 3333]);
        self::assertSame(1000, array_sum($parts));
        self::assertSame(['a' => 300, 'b' => 600, 'c' => 100], $parts);

        // Plus forts restes : 100 F sur trois lignes égales.
        $parts = CalculCodePromo::repartir(100, [1 => 500, 2 => 500, 3 => 500]);
        self::assertSame(100, array_sum($parts));
        self::assertSame([1 => 34, 2 => 33, 3 => 33], $parts);

        // Jamais plus que la ligne, jamais plus que le total.
        $parts = CalculCodePromo::repartir(5000, ['x' => 1000, 'y' => 0]);
        self::assertSame(['x' => 1000, 'y' => 0], $parts);
        self::assertSame(['x' => 0], CalculCodePromo::repartir(0, ['x' => 1000]));
    }

    /* ---------- Messages ---------- */

    public function testMessagesSansDireSiLeCodeExiste(): void
    {
        $generique = 'Ce code n\'est pas valable pour cette commande.';
        // Au client : la phrase générale, et la raison seulement quand elle tient à sa commande.
        self::assertSame($generique, CalculCodePromo::message('inconnu', false));
        self::assertSame($generique, CalculCodePromo::message('epuise', false));
        self::assertSame($generique, CalculCodePromo::message('desactive', false));
        self::assertSame($generique, CalculCodePromo::message('segment', false));
        self::assertSame($generique.' Ce code a expiré.', CalculCodePromo::message('expire', false));
        self::assertSame($generique.' Vous avez déjà utilisé ce code.', CalculCodePromo::message('deja_utilise', false));
        self::assertStringContainsString('minimal', CalculCodePromo::message('minimum', false));
        // À l'équipe : la raison, sauf pour un code inconnu.
        self::assertSame('Code promo : '.$generique, CalculCodePromo::message('inconnu', true));
        self::assertStringContainsString('épuisé', CalculCodePromo::message('epuise', true));
        // La raison seule (sous le champ, motif sur la fiche) : sans le préfixe, et un code inconnu reste général.
        self::assertSame('Code épuisé : toutes ses utilisations sont prises.', CalculCodePromo::raison('epuise'));
        self::assertSame($generique, CalculCodePromo::raison('inconnu'));
        self::assertSame($generique, CalculCodePromo::raison('cle-inconnue'));
        // Aucun montant dans les messages.
        foreach (array_keys(CalculCodePromo::REFUS_EQUIPE) as $refus) {
            self::assertDoesNotMatchRegularExpression('/\d+\s?F\b/', CalculCodePromo::message($refus, false));
        }
    }

    /* ---------- Outils ---------- */

    private function promotion(): Promotion
    {
        return (new Promotion())
            ->setNom('Rentrée')
            ->setDebut(new \DateTimeImmutable('2026-11-01'))
            ->setFin(new \DateTimeImmutable('2026-11-30'));
    }

    private function code(Promotion $p, int $max, int $parClient): CodePromo
    {
        return CodePromo::creer($p, CodePromo::TYPE_PARTAGE, 'RENTREE25', 'x', new \DateTimeImmutable('2026-11-30'), $this->jour)
            ->setUtilisationsMax($max)->setUtilisationsParClient($parClient);
    }

    private function refus(CodePromo $code, int $autres = 0, int $duClient = 0): ?string
    {
        return CalculCodePromo::refus($code, false, $this->jour, $autres, $duClient, false, ['tous', 'particuliers']);
    }

    /** Pour qui lit le test : un client particulier n'est pas un revendeur B2B. */
    public function testUnParticulierNEstPasB2b(): void
    {
        self::assertFalse((new Clients())->isB2B());
    }
}
