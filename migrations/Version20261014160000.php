<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sociétés, étape 1 (docs/societes.md) : la table societe et la colonne
 * societe_id sur les entreprises émettrices et sur les documents qui
 * touchent à l'argent ou au stock.
 *
 * Les sociétés viennent des entreprises regroupées par NIF (à défaut, par
 * raison sociale) : mdg_success et mdg partagent celui de Madial Group SARL,
 * la société principale (code IMP, id 1, par défaut) ; drepa a le sien. Une
 * base sans entreprise reçoit seulement la société principale. L'identifiant
 * public est tiré du code, comme pour les entreprises : le même au local et
 * en ligne, où cette migration tourne aussi.
 *
 * Les colonnes valent 1 par défaut (la société principale) : les lignes
 * existantes y sont rattachées dès l'ajout, puis la règle de rattachement
 * corrige celles d'un autre émetteur, avant les clés étrangères. La même
 * règle vit dans App\Service\Societes\SocieteCourante et RattachementSql ;
 * celle-ci reste figée sur les tables de ce jour.
 */
final class Version20261014160000 extends AbstractMigration
{
    /** Société d'une base sans entreprise : les valeurs de Version20261010231429. */
    private const PRINCIPALE = ['raison_sociale' => 'Madial Group SARL', 'rccm' => 'MA.BKO.2026.B.1404', 'nif' => '081148957D', 'adresse' => 'Bamako, Sotuba ACI'];

    /** Table => [clé étrangère, index] (noms donnés par Doctrine). */
    private const TABLES = [
        'entreprise' => ['FK_D19FA60FCF77503', 'IDX_D19FA60FCF77503'],
        'devis' => ['FK_8B27C52BFCF77503', 'IDX_8B27C52BFCF77503'],
        'factures' => ['FK_647590BFCF77503', 'IDX_647590BFCF77503'],
        'commandes' => ['FK_35D4282CFCF77503', 'IDX_35D4282CFCF77503'],
        'paiements' => ['FK_E1B02E12FCF77503', 'IDX_E1B02E12FCF77503'],
        'compte_tresorerie' => ['FK_E738ED06FCF77503', 'IDX_E738ED06FCF77503'],
        'mouvement_tresorerie' => ['FK_516E7468FCF77503', 'IDX_516E7468FCF77503'],
        'decaissement_recurrent' => ['FK_23DC19F6FCF77503', 'IDX_23DC19F6FCF77503'],
        'rapprochement_bancaire' => ['FK_D4D082D0FCF77503', 'IDX_D4D082D0FCF77503'],
        'cloture_caisse' => ['FK_21B102FDFCF77503', 'IDX_21B102FDFCF77503'],
        'depense' => ['FK_34059757FCF77503', 'IDX_34059757FCF77503'],
        'articles' => ['FK_BFDD3168FCF77503', 'IDX_BFDD3168FCF77503'],
        'stock_entrees' => ['FK_3D445A02FCF77503', 'IDX_3D445A02FCF77503'],
        'stock_sorties' => ['FK_5127734BFCF77503', 'IDX_5127734BFCF77503'],
        'achats' => ['FK_9920924EFCF77503', 'IDX_9920924EFCF77503'],
        'immobilisation' => ['FK_B1563E27FCF77503', 'IDX_B1563E27FCF77503'],
        'dotation_amortissement' => ['FK_18F6EE9FCF77503', 'IDX_18F6EE9FCF77503'],
    ];

    public function getDescription(): string
    {
        return 'Sociétés (étape 1) : table societe tirée des entreprises émettrices, société des entreprises et des documents d\'argent et de stock';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE societe (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(10) NOT NULL, raison_sociale VARCHAR(150) NOT NULL, rccm VARCHAR(60) DEFAULT NULL, nif VARCHAR(60) DEFAULT NULL, adresse VARCHAR(255) DEFAULT NULL, par_defaut TINYINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, public_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_19653DBD77153098 (code), UNIQUE INDEX UNIQ_19653DBDB5B48B91 (public_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $entreprises = $this->connection->fetchAllAssociative('SELECT code, raison_sociale, nif, rccm, adresse FROM entreprise ORDER BY ordre ASC, id ASC');
        foreach (self::societes($entreprises) as $i => $societe) {
            // La principale garde l'id 1 : c'est la valeur par défaut des colonnes societe_id.
            $this->addSql(
                "INSERT INTO societe (id, code, raison_sociale, rccm, nif, adresse, par_defaut, actif, public_id)
                 VALUES (:id, :code, :raison, :rccm, :nif, :adresse, :defaut, 1, UNHEX(MD5(CONCAT('societe:', :cle))))",
                ['id' => 0 === $i ? 1 : null, 'code' => $societe['code'], 'cle' => $societe['code'], 'raison' => $societe['raison_sociale'], 'rccm' => $societe['rccm'],
                    'nif' => $societe['nif'], 'adresse' => $societe['adresse'], 'defaut' => 0 === $i ? 1 : 0]
            );
        }

        foreach (array_keys(self::TABLES) as $table) {
            $this->addSql(\sprintf('ALTER TABLE %s ADD societe_id INT DEFAULT 1 NOT NULL', $table));
        }

        foreach (self::societes($entreprises) as $societe) {
            foreach ($societe['emetteurs'] as $code) {
                $this->addSql('UPDATE entreprise SET societe_id = (SELECT id FROM societe WHERE code = :societe) WHERE code = :code', ['societe' => $societe['code'], 'code' => $code]);
            }
        }

        // Règle de rattachement : l'émetteur d'abord, puis ce qui en découle.
        $this->addSql('UPDATE devis d JOIN entreprise e ON e.code = d.emetteur SET d.societe_id = e.societe_id');
        $this->addSql('UPDATE factures f JOIN entreprise e ON e.code = f.emetteur SET f.societe_id = e.societe_id');
        $this->addSql("UPDATE commandes c JOIN (SELECT commande_id, MIN(id) AS premiere FROM factures WHERE etat <> 'annulee' GROUP BY commande_id) p ON p.commande_id = c.id JOIN factures f ON f.id = p.premiere SET c.societe_id = f.societe_id");
        $this->addSql("UPDATE commandes c JOIN devis d ON d.commande_id = c.id SET c.societe_id = d.societe_id WHERE NOT EXISTS (SELECT 1 FROM factures f WHERE f.commande_id = c.id AND f.etat <> 'annulee')");
        $this->addSql('UPDATE paiements p JOIN factures f ON f.id = p.facture_id SET p.societe_id = f.societe_id');
        $this->addSql('UPDATE paiements p JOIN commandes c ON c.id = p.commande_id SET p.societe_id = c.societe_id WHERE p.facture_id IS NULL');
        // Comptes, articles, achats et biens restent à la société principale ; ce qui en dépend la suit
        // (un mouvement suit son compte, pas son paiement). Seul un mouvement sans compte suit son paiement.
        $this->addSql('UPDATE mouvement_tresorerie m JOIN paiements p ON p.id = m.paiement_id SET m.societe_id = p.societe_id WHERE m.compte_source_id IS NULL AND m.compte_destination_id IS NULL');

        foreach (self::TABLES as $table => [$cle, $index]) {
            $this->addSql(\sprintf('CREATE INDEX %s ON %s (societe_id)', $index, $table));
            $this->addSql(\sprintf('ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (societe_id) REFERENCES societe (id)', $table, $cle));
        }
    }

    public function down(Schema $schema): void
    {
        // Les clés vers societe partent avant la table.
        foreach (self::TABLES as $table => [$cle, $index]) {
            $this->addSql(\sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $cle));
            $this->addSql(\sprintf('DROP INDEX %s ON %s', $index, $table));
            $this->addSql(\sprintf('ALTER TABLE %s DROP societe_id', $table));
        }
        $this->addSql('DROP TABLE societe');
    }

    /**
     * Les sociétés à créer : une par NIF, à défaut par raison sociale, dans
     * l'ordre des entreprises. La première est la société principale (code
     * IMP) ; les autres prennent le code de leur premier émetteur (DREPA).
     *
     * @param list<array{code: string, raison_sociale: string, nif: ?string, rccm: ?string, adresse: ?string}> $entreprises dans l'ordre des réglages
     *
     * @return list<array{code: string, raison_sociale: string, rccm: ?string, nif: ?string, adresse: ?string, emetteurs: list<string>}>
     */
    public static function societes(array $entreprises): array
    {
        $groupes = [];
        foreach ($entreprises as $e) {
            $nif = strtoupper((string) preg_replace('/\s+/', '', (string) ($e['nif'] ?? '')));
            $cle = '' !== $nif ? 'nif:'.$nif : 'raison:'.mb_strtolower(trim((string) preg_replace('/\s+/', ' ', (string) $e['raison_sociale'])));
            $groupes[$cle] ??= ['raison_sociale' => trim((string) $e['raison_sociale']), 'rccm' => null, 'nif' => null, 'adresse' => null, 'emetteurs' => []];
            foreach (['rccm', 'nif', 'adresse'] as $champ) {
                $valeur = trim((string) ($e[$champ] ?? ''));
                $groupes[$cle][$champ] ??= '' !== $valeur ? $valeur : null;
            }
            $groupes[$cle]['emetteurs'][] = (string) $e['code'];
        }
        if ([] === $groupes) {
            $groupes[] = [...self::PRINCIPALE, 'emetteurs' => []];
        }

        $societes = [];
        $codes = [];
        foreach (array_values($groupes) as $i => $groupe) {
            $base = 0 === $i ? 'IMP' : (substr(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $groupe['emetteurs'][0])), 0, 8) ?: 'SOC');
            $code = $base;
            for ($n = 2; isset($codes[$code]); ++$n) {
                $code = $base.$n;
            }
            $codes[$code] = true;
            $societes[] = ['code' => $code, ...$groupe];
        }

        return $societes;
    }
}
