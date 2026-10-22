<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013193027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jetons d\'accès à l\'API (empreinte seulement, docs/api.md)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE jeton_api (id INT AUTO_INCREMENT NOT NULL, nom VARCHAR(80) NOT NULL, empreinte VARCHAR(64) NOT NULL, debut VARCHAR(12) NOT NULL, cree_le DATETIME NOT NULL, expire_le DATETIME DEFAULT NULL, utilise_le DATETIME DEFAULT NULL, derniere_ip VARCHAR(45) DEFAULT NULL, revoque_le DATETIME DEFAULT NULL, utilisateur_id INT NOT NULL, cree_par_id INT DEFAULT NULL, INDEX IDX_B1E22F39FB88E14F (utilisateur_id), INDEX IDX_B1E22F39FC29C013 (cree_par_id), UNIQUE INDEX uniq_jeton_api_empreinte (empreinte), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE jeton_api ADD CONSTRAINT FK_B1E22F39FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE jeton_api ADD CONSTRAINT FK_B1E22F39FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE jeton_api DROP FOREIGN KEY FK_B1E22F39FB88E14F');
        $this->addSql('ALTER TABLE jeton_api DROP FOREIGN KEY FK_B1E22F39FC29C013');
        $this->addSql('DROP TABLE jeton_api');
    }
}
