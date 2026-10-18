<?php

namespace App\Tests\Unit\Service\Corbeille;

use App\Service\Corbeille\Instantane;
use App\Service\Corbeille\SchemaCorbeille;
use PHPUnit\Framework\TestCase;

/**
 * Copie prise juste avant une suppression (docs/corbeille.md) : la fiche,
 * ce que la base supprime avec elle, les liens à rebrancher, sans les
 * tables techniques ni les secrets.
 */
final class InstantaneTest extends TestCase
{
    public function testLeSchemaEstLuDepuisLaBase(): void
    {
        $schema = new SchemaCorbeille(BaseEssai::connexion());

        self::assertSame(['id'], $schema->clePrimaire('clients'));
        self::assertSame(['promotion_id', 'client_id'], $schema->clePrimaire('promotion_client'));
        self::assertFalse($schema->existe('table_inconnue'));
        $regles = [];
        foreach ($schema->referencesVers('clients') as $fk) {
            $regles[$fk['table']] = $fk['regle'];
        }
        self::assertSame('CASCADE', $regles['adresse_livraison']);
        self::assertSame('SET NULL', $regles['action_caisse']);
    }

    public function testUnClientPartAvecCeQueLaBaseSupprimeAvecLui(): void
    {
        $c = BaseEssai::connexion();
        BaseEssai::remplir($c);

        $groupes = BaseEssai::instantane($c)->capturer([['table' => 'clients', 'cle' => ['id' => 7]]]);

        self::assertCount(1, $groupes, 'une seule entrée pour la suppression');
        $groupe = $groupes[0];
        self::assertSame('clients', $groupe[0]['table'], 'la fiche d\'abord : elle revient avant ce qui dépend d\'elle');
        self::assertTrue($groupe[0]['racine']);
        self::assertSame(['id' => 7], $groupe[0]['cle']);

        $tables = array_map(static fn (array $l): string => $l['table'], $groupe);
        sort($tables);
        self::assertSame(['adresse_livraison', 'adresse_livraison', 'clients', 'compte_client', 'promotion_client'], $tables, 'adresses, accès et lien de promotion partent avec le client ; pas la notification (table technique)');
        self::assertCount(1, array_filter($groupe, static fn (array $l): bool => $l['racine']));

        // L'action de caisse reste en place (SET NULL) : son lien est gardé pour la restauration.
        self::assertSame([['table' => 'action_caisse', 'valeurs' => ['client_id' => 7], 'cle' => ['id' => 80]]], $groupe[0]['liens']);
    }

    public function testDeuxSuppressionsSansLienFontDeuxEntrees(): void
    {
        $c = BaseEssai::connexion();
        BaseEssai::remplir($c);
        $c->insert('promotion', ['id' => 6, 'nom' => 'Tabaski']);

        $groupes = BaseEssai::instantane($c)->capturer([['table' => 'adresse_livraison', 'cle' => ['id' => 71]], ['table' => 'promotion', 'cle' => ['id' => 6]]]);

        self::assertCount(2, $groupes);
        self::assertSame(['adresse_livraison', 'promotion'], [$groupes[0][0]['table'], $groupes[1][0]['table']]);
    }

    public function testDesElementsRattachesSeulsNeSontPasGardes(): void
    {
        $c = BaseEssai::connexion();
        BaseEssai::remplir($c);
        $c->insert('devis', ['id' => 3, 'numero' => 'DEV-2026-0042', 'clients_id' => 7]);
        $c->insert('devis_details', ['id' => 31, 'devis_id' => 3, 'designation' => 'Cartes de visite']);

        // Une ligne retirée en modifiant le devis : une modification, pas une entrée de la corbeille.
        self::assertSame([], BaseEssai::instantane($c)->capturer([['table' => 'devis_details', 'cle' => ['id' => 31]]]));

        // Le devis entier, lui, est gardé avec sa ligne.
        $groupes = BaseEssai::instantane($c)->capturer([['table' => 'devis', 'cle' => ['id' => 3]]]);
        self::assertCount(1, $groupes);
        self::assertSame(['devis', 'devis_details'], array_map(static fn (array $l): string => $l['table'], $groupes[0]));
    }

    public function testUneLigneRetireeAvecSesFichiersEstGardeeSousSonNom(): void
    {
        $c = BaseEssai::connexion();
        $c->insert('commandes_details', ['id' => 40, 'designation' => 'Bâche 3 x 1 m']);
        $c->insert('commande_detail_fichier', ['id' => 41, 'commande_detail_id' => 40, 'nom_original' => 'logo.pdf', 'nom_stockage' => 'abc.pdf']);

        $groupes = BaseEssai::instantane($c)->capturer([['table' => 'commandes_details', 'cle' => ['id' => 40]]]);

        self::assertCount(1, $groupes, 'le fichier du client ne se perd pas avec la ligne');
        self::assertSame('commandes_details', $groupes[0][0]['table']);
        self::assertTrue($groupes[0][0]['racine']);
    }

    public function testUneTableTechniqueNEntreJamais(): void
    {
        $c = BaseEssai::connexion();
        BaseEssai::remplir($c);

        self::assertSame([], BaseEssai::instantane($c)->capturer([['table' => 'notifications', 'cle' => ['id' => 90]]]));
        self::assertSame([], BaseEssai::instantane($c)->capturer([['table' => 'corbeille', 'cle' => ['id' => 1]]]));
    }

    public function testLesSecretsNeSontJamaisCopies(): void
    {
        $compte = Instantane::encoder('compte_client', ['id' => 70, 'telephone' => '+22376000007', 'mot_de_passe' => '$2y$13$empreinte']);
        self::assertArrayNotHasKey('mot_de_passe', $compte);

        $personnel = Instantane::encoder('user', ['id' => 3, 'username' => 'awa', 'password' => '$2y$13$x', 'pin' => '$2y$13$y', 'invitation_empreinte' => 'abc']);
        self::assertSame(['id' => 3, 'username' => 'awa'], $personnel);

        // À la restauration : vide, ou une valeur neuve (l'ancien mot de passe ne marche plus).
        $remis = Instantane::decoder('user', $personnel);
        self::assertNull($remis['pin']);
        self::assertNull($remis['invitation_empreinte']);
        self::assertIsString($remis['password']);
        self::assertSame(24, \strlen($remis['password']));
        self::assertArrayHasKey('mot_de_passe', Instantane::decoder('compte_client', $compte));
        self::assertNull(Instantane::decoder('compte_client', $compte)['mot_de_passe']);
    }

    public function testLesValeursBinairesFontLAllerRetour(): void
    {
        $uuid = hex2bin('0192aabbccddeeff00112233445566ff');
        $gros = random_bytes(Instantane::TAILLE_MAX_EN_LIGNE + 10);
        $ranges = [];

        $encode = Instantane::encoder('clients', ['id' => 7, 'public_id' => $uuid, 'image' => "\xff".$gros], static function (string $octets) use (&$ranges): string {
            $ranges['a/b.bin'] = $octets;

            return 'a/b.bin';
        });
        self::assertSame(['__base64' => base64_encode($uuid)], $encode['public_id']);
        self::assertSame(['__fichier' => 'a/b.bin'], $encode['image'], 'un contenu lourd est rangé à part');
        self::assertNotFalse(json_encode($encode));

        $decode = Instantane::decoder('clients', json_decode((string) json_encode($encode), true), static fn (string $relatif): string => $ranges[$relatif]);
        self::assertSame($uuid, $decode['public_id']);
        self::assertSame("\xff".$gros, $decode['image']);
    }

    public function testCleLisible(): void
    {
        self::assertSame('42', Instantane::cleLisible(['id' => 42]));
        self::assertSame('{"promotion_id":5,"client_id":7}', Instantane::cleLisible(['promotion_id' => 5, 'client_id' => 7]));
    }
}
