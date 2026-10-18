<?php

namespace App\Tests\Unit\Service\Corbeille;

use App\Service\Corbeille\Instantane;
use App\Service\Corbeille\Restauration;
use App\Service\Corbeille\SchemaCorbeille;
use App\Service\Corbeille\TypesCorbeille;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Restauration (docs/corbeille.md) : les lignes reviennent avec leurs
 * identifiants d'origine, les liens vidés sont rebranchés, et rien n'est
 * écrit quand une fiche liée manque.
 */
final class RestaurationTest extends TestCase
{
    public function testLeClientRevientAvecSesEnfantsEtSesLiens(): void
    {
        $c = BaseEssai::connexion();
        BaseEssai::remplir($c);
        [$lignes, $liens] = $this->supprimer($c, 'clients', 7);

        self::assertFalse($c->fetchOne('SELECT 1 FROM clients WHERE id = 7'));
        self::assertNull($c->fetchOne('SELECT client_id FROM action_caisse WHERE id = 80'), 'la base a vidé le lien');
        self::assertSame(0, (int) $c->fetchOne('SELECT COUNT(*) FROM notifications'), 'partie avec le client, sans copie');

        $restauration = new Restauration($c, new SchemaCorbeille($c));
        self::assertNull($restauration->empechement($lignes, static fn (): ?string => null));
        $c->transactional(static function () use ($restauration, $lignes, $liens): void {
            $restauration->inserer($lignes);
            self::assertSame(1, $restauration->rebrancher($liens));
        });

        self::assertSame('CLI-7', $c->fetchOne('SELECT code FROM clients WHERE id = 7'));
        self::assertSame(hex2bin('0192aabbccddeeff00112233445566ff'), $c->fetchOne('SELECT public_id FROM clients WHERE id = 7'));
        self::assertSame([71, 72], array_map('intval', $c->fetchFirstColumn('SELECT id FROM adresse_livraison WHERE client_id = 7 ORDER BY id')), 'mêmes identifiants');
        self::assertSame(1, (int) $c->fetchOne('SELECT COUNT(*) FROM promotion_client WHERE client_id = 7 AND promotion_id = 5'));
        self::assertSame(7, (int) $c->fetchOne('SELECT client_id FROM action_caisse WHERE id = 80'), 'lien rebranché');
        self::assertNull($c->fetchOne('SELECT mot_de_passe FROM compte_client WHERE id = 70'), 'le mot de passe ne revient pas : le client en refait un');
    }

    public function testUnLienChangeDepuisNEstPasEcrase(): void
    {
        $c = BaseEssai::connexion();
        BaseEssai::remplir($c);
        $c->insert('clients', ['id' => 8, 'code' => 'CLI-8', 'nom' => 'Diarra']);
        [$lignes, $liens] = $this->supprimer($c, 'clients', 7);
        $c->update('action_caisse', ['client_id' => 8], ['id' => 80]);

        $restauration = new Restauration($c, new SchemaCorbeille($c));
        $restauration->inserer($lignes);

        self::assertSame(0, $restauration->rebrancher($liens));
        self::assertSame(8, (int) $c->fetchOne('SELECT client_id FROM action_caisse WHERE id = 80'));
    }

    public function testRefusSiLaFicheRattacheeNExistePlus(): void
    {
        $c = BaseEssai::connexion();
        BaseEssai::remplir($c);
        [$lignes] = $this->supprimer($c, 'adresse_livraison', 71);
        // Le client est ensuite supprimé, puis effacé pour de bon.
        $c->delete('clients', ['id' => 7]);

        $restauration = new Restauration($c, new SchemaCorbeille($c));
        $message = $restauration->empechement($lignes, static fn (): ?string => null);

        self::assertSame('Adresse de livraison « Boutique » ne peut pas revenir : client n° 7 auquel il était rattaché n\'existe plus. Rien n\'a été restauré.', $message);
    }

    public function testRefusSiLaFicheRattacheeEstAussiDansLaCorbeille(): void
    {
        $c = BaseEssai::connexion();
        BaseEssai::remplir($c);
        [$lignes] = $this->supprimer($c, 'adresse_livraison', 71);
        $c->delete('clients', ['id' => 7]);

        $restauration = new Restauration($c, new SchemaCorbeille($c));
        $message = $restauration->empechement($lignes, static fn (string $table, string $numero): ?string => 'clients' === $table && '7' === $numero ? 'Client « Awa Traoré »' : null);

        self::assertStringContainsString('est aussi dans la corbeille (« Client « Awa Traoré » »). Restaurez-le d\'abord.', (string) $message);
    }

    public function testRefusSiLaFicheEstDejaRevenue(): void
    {
        $c = BaseEssai::connexion();
        BaseEssai::remplir($c);
        [$lignes] = $this->supprimer($c, 'adresse_livraison', 71);
        $c->insert('adresse_livraison', ['id' => 71, 'client_id' => 7, 'libelle' => 'Refaite', 'adresse' => 'ACI']);

        $message = (new Restauration($c, new SchemaCorbeille($c)))->empechement($lignes, static fn (): ?string => null);

        self::assertSame('Adresse de livraison « Boutique » est déjà de nouveau en place (n° 71) : rien n\'a été restauré.', $message);
    }

    public function testUnNumeroDejaRepris(): void
    {
        $c = BaseEssai::connexion();
        BaseEssai::remplir($c);
        [$lignes] = $this->supprimer($c, 'clients', 7);
        $c->insert('clients', ['id' => 9, 'code' => 'CLI-7', 'nom' => 'Autre']);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('une autre fiche porte déjà le même numéro');
        $c->transactional(static fn () => (new Restauration($c, new SchemaCorbeille($c)))->inserer($lignes));
    }

    public function testUnConsommableRetireNeRevientPas(): void
    {
        $message = TypesCorbeille::NON_RESTAURABLES['stock_sorties'];
        self::assertStringContainsString('stock a été recrédité', $message);
    }

    /**
     * Copie (comme CaptureCorbeille avant l'enregistrement), passage par le
     * JSON de la table corbeille, puis suppression par la base.
     *
     * @return array{0: list<array{table: string, cle: array<string, mixed>, donnees: array<string, mixed>, libelle: string}>, 1: list<array<string, mixed>>}
     */
    private function supprimer(Connection $c, string $table, int $id): array
    {
        $groupes = BaseEssai::instantane($c)->capturer([['table' => $table, 'cle' => ['id' => $id]]]);
        self::assertCount(1, $groupes);
        $lignes = [];
        $liens = [];
        foreach ($groupes[0] as $l) {
            $json = json_decode((string) json_encode(Instantane::encoder($l['table'], $l['donnees']), \JSON_THROW_ON_ERROR), true);
            $lignes[] = [
                'table' => $l['table'],
                'cle' => $l['cle'],
                'donnees' => Instantane::decoder($l['table'], $json),
                'libelle' => TypesCorbeille::libelle($l['table'], $l['donnees'], Instantane::cleLisible($l['cle'])),
            ];
            array_push($liens, ...$l['liens']);
        }
        $c->delete($table, ['id' => $id]);

        return [$lignes, $liens];
    }
}
