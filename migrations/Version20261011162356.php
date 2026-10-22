<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261011162356 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Livraisons : tournée, étapes, preuves et encaissement provisoire sur le bon de livraison';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_livraison ADD prevu_le DATE DEFAULT NULL, ADD creneau VARCHAR(20) DEFAULT NULL, ADD ordre_tournee INT DEFAULT NULL, ADD charge_le DATETIME DEFAULT NULL, ADD en_route_le DATETIME DEFAULT NULL, ADD arrive_le DATETIME DEFAULT NULL, ADD photos JSON DEFAULT NULL, ADD signature VARCHAR(100) DEFAULT NULL, ADD signataire VARCHAR(120) DEFAULT NULL, ADD encaisse_montant INT DEFAULT NULL, ADD encaisse_mode VARCHAR(30) DEFAULT NULL, ADD encaisse_le DATETIME DEFAULT NULL, ADD livreur_id INT DEFAULT NULL, ADD paiement_livraison_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bon_livraison ADD CONSTRAINT FK_31A531A4F8646701 FOREIGN KEY (livreur_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE bon_livraison ADD CONSTRAINT FK_31A531A430E2C008 FOREIGN KEY (paiement_livraison_id) REFERENCES paiements (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_31A531A4F8646701 ON bon_livraison (livreur_id)');
        $this->addSql('CREATE INDEX IDX_31A531A430E2C008 ON bon_livraison (paiement_livraison_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bon_livraison DROP FOREIGN KEY FK_31A531A4F8646701');
        $this->addSql('ALTER TABLE bon_livraison DROP FOREIGN KEY FK_31A531A430E2C008');
        $this->addSql('DROP INDEX IDX_31A531A4F8646701 ON bon_livraison');
        $this->addSql('DROP INDEX IDX_31A531A430E2C008 ON bon_livraison');
        $this->addSql('ALTER TABLE bon_livraison DROP prevu_le, DROP creneau, DROP ordre_tournee, DROP charge_le, DROP en_route_le, DROP arrive_le, DROP photos, DROP signature, DROP signataire, DROP encaisse_montant, DROP encaisse_mode, DROP encaisse_le, DROP livreur_id, DROP paiement_livraison_id');
    }
}
