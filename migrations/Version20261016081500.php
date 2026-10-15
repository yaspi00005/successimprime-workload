<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Livreurs (docs/documents-livraisons.md, « Écrans du livreur ») :
 *
 * - échec d'une livraison sur le bon : quand, quel livreur, pourquoi, et
 *   s'il attend encore un nouveau passage ;
 * - le rôle Livreur perd « Ses propres statistiques » (ventes et
 *   encaissements de la personne) : le livreur ne voit que ses livraisons
 *   et sa tournée. Les droits ajoutés à une personne ne sont pas touchés.
 */
final class Version20261016081500 extends AbstractMigration
{
    public const ROLE = 'ROLE_LIVREUR';

    public const DROIT = 'ROLE_STATS_PERSO';

    public function getDescription(): string
    {
        return 'Livraisons : échec noté par le livreur ; rôle Livreur sans « Ses propres statistiques »';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_livraison ADD echec_le DATETIME DEFAULT NULL, ADD echec_motif VARCHAR(255) DEFAULT NULL, ADD echec_en_cours TINYINT DEFAULT 0 NOT NULL, ADD echec_par_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bon_livraison ADD CONSTRAINT FK_31A531A4AA69B42E FOREIGN KEY (echec_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_31A531A4AA69B42E ON bon_livraison (echec_par_id)');

        $droits = $this->droitsDuRole();
        if (null !== $droits && $droits !== self::sans($droits)) {
            $this->addSql('UPDATE role_utilisateur SET droits = :droits WHERE code = :code', ['droits' => json_encode(self::sans($droits)), 'code' => self::ROLE]);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_livraison DROP FOREIGN KEY FK_31A531A4AA69B42E');
        $this->addSql('DROP INDEX IDX_31A531A4AA69B42E ON bon_livraison');
        $this->addSql('ALTER TABLE bon_livraison DROP echec_le, DROP echec_motif, DROP echec_en_cours, DROP echec_par_id');

        $droits = $this->droitsDuRole();
        if (null !== $droits && $droits !== self::avec($droits)) {
            $this->addSql('UPDATE role_utilisateur SET droits = :droits WHERE code = :code', ['droits' => json_encode(self::avec($droits)), 'code' => self::ROLE]);
        }
    }

    /**
     * @param list<string> $droits
     *
     * @return list<string>
     */
    public static function sans(array $droits): array
    {
        return array_values(array_filter($droits, static fn (string $d): bool => self::DROIT !== $d));
    }

    /**
     * Droits du rôle avec ses propres statistiques (à la fin, comme à la création du rôle).
     *
     * @param list<string> $droits
     *
     * @return list<string>
     */
    public static function avec(array $droits): array
    {
        return \in_array(self::DROIT, $droits, true) ? array_values($droits) : [...array_values($droits), self::DROIT];
    }

    /** @return list<string>|null */
    private function droitsDuRole(): ?array
    {
        $json = $this->connection->fetchOne('SELECT droits FROM role_utilisateur WHERE code = ?', [self::ROLE]);

        return false === $json || null === $json ? null : array_values((array) json_decode((string) $json, true));
    }
}
