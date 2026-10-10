<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010202901 extends AbstractMigration
{
    /**
     * Table => nom de l'index unique genere par Doctrine.
     */
    private const TABLES = [
        'categorie_produit' => 'UNIQ_76264285B5B48B91',
        'commande_detail_fichier' => 'UNIQ_39E2DA10B5B48B91',
        'commandes_details' => 'UNIQ_B48B83DAB5B48B91',
        'controle_pre_presse' => 'UNIQ_FA26187B5B48B91',
        'devis' => 'UNIQ_8B27C52BB5B48B91',
        'devis_details' => 'UNIQ_E0C890D6B5B48B91',
        'factures' => 'UNIQ_647590BB5B48B91',
        'finition' => 'UNIQ_2060F826B5B48B91',
        'format' => 'UNIQ_DEBA72DFB5B48B91',
        'paiements' => 'UNIQ_E1B02E12B5B48B91',
        'produit_configuration' => 'UNIQ_8BBC0309B5B48B91',
        'produits' => 'UNIQ_BE2DDF8CB5B48B91',
        'reclamations' => 'UNIQ_1CAD6B76B5B48B91',
        'supports' => 'UNIQ_BD329C87B5B48B91',
        'types_impression' => 'UNIQ_11320021B5B48B91',
    ];

    public function getDescription(): string
    {
        return 'Ajoute un identifiant public UUID (public_id) aux donnees qui seront synchronisees '
            .'avec l application en ligne, et le remplit pour les lignes existantes.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table => $index) {
            $this->addSql(sprintf('ALTER TABLE %s ADD public_id BINARY(16) DEFAULT NULL', $table));
            $this->addSql(sprintf("UPDATE %s SET public_id = UNHEX(REPLACE(UUID(), '-', '')) WHERE public_id IS NULL", $table));
            $this->addSql(sprintf('ALTER TABLE %s MODIFY public_id BINARY(16) NOT NULL', $table));
            $this->addSql(sprintf('CREATE UNIQUE INDEX %s ON %s (public_id)', $index, $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table => $index) {
            $this->addSql(sprintf('DROP INDEX %s ON %s', $index, $table));
            $this->addSql(sprintf('ALTER TABLE %s DROP public_id', $table));
        }
    }
}
