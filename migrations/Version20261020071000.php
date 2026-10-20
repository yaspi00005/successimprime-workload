<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fichier de découpe (docs/prepresse.md) : le rôle de chaque fichier d'une
 * ligne (impression, ou découpe pour le contour du plotter), la case
 * « Fichier de découpe requis » de la prépresse et « Découpe sans fichier
 * séparé ». Les fichiers déjà là sont des fichiers d'impression.
 */
final class Version20261020071000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Prépresse : fichier de découpe à côté du fichier d\'impression';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE commande_detail_fichier ADD role VARCHAR(20) DEFAULT 'impression' NOT NULL");
        $this->addSql('ALTER TABLE commandes_details ADD fichier_decoupe_requis TINYINT(1) DEFAULT NULL, ADD decoupe_sans_fichier TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commande_detail_fichier DROP role');
        $this->addSql('ALTER TABLE commandes_details DROP fichier_decoupe_requis, DROP decoupe_sans_fichier');
    }
}
