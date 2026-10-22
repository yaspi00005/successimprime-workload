<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261012203427 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commande, devis et paiement sans agent possibles (saisis en ligne, site synchronisé).';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commandes CHANGE agents_id agents_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE devis CHANGE agents_id agents_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE paiements CHANGE encaisse_par_id encaisse_par_id INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE commandes CHANGE agents_id agents_id INT NOT NULL');
        $this->addSql('ALTER TABLE devis CHANGE agents_id agents_id INT NOT NULL');
        $this->addSql('ALTER TABLE paiements CHANGE encaisse_par_id encaisse_par_id INT NOT NULL');
    }
}
