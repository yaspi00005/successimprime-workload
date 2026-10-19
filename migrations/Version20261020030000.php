<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Achats (docs/achats.md, maquettes SU01 à SU03) : conditions des
 * fournisseurs, commande d'achat avec frais de transport et de transit,
 * validation par la direction, coût de revient par ligne.
 */
final class Version20261020030000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Achats : fiche fournisseur, frais et validation des commandes d\'achat';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fournisseurs
            ADD contact_nom VARCHAR(100) DEFAULT NULL,
            ADD contact_telephone VARCHAR(30) DEFAULT NULL,
            ADD ville VARCHAR(80) DEFAULT NULL,
            ADD pays VARCHAR(80) DEFAULT NULL,
            ADD fournit VARCHAR(255) DEFAULT NULL,
            ADD conditions_paiement VARCHAR(150) DEFAULT NULL,
            ADD acompte_pourcentage SMALLINT DEFAULT 0 NOT NULL,
            ADD transport VARCHAR(150) DEFAULT NULL,
            ADD delai_promis_jours INT DEFAULT NULL,
            ADD couleur VARCHAR(7) DEFAULT NULL');

        $this->addSql('ALTER TABLE achats
            ADD date_commande DATETIME DEFAULT NULL,
            ADD date_promise DATE DEFAULT NULL,
            ADD frais_transport INT DEFAULT 0 NOT NULL,
            ADD frais_transport_libelle VARCHAR(150) DEFAULT NULL,
            ADD frais_transport_base VARCHAR(100) DEFAULT NULL,
            ADD frais_transit INT DEFAULT 0 NOT NULL,
            ADD frais_transit_libelle VARCHAR(150) DEFAULT NULL,
            ADD frais_transit_base VARCHAR(100) DEFAULT NULL,
            ADD acompte_pourcentage SMALLINT DEFAULT 0 NOT NULL,
            ADD valide_le DATETIME DEFAULT NULL,
            ADD valide_par_id INT DEFAULT NULL,
            ADD cree_par_id INT DEFAULT NULL,
            ADD origine_alerte_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE achats ADD CONSTRAINT FK_ACHATS_VALIDE_PAR FOREIGN KEY (valide_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE achats ADD CONSTRAINT FK_ACHATS_CREE_PAR FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE achats ADD CONSTRAINT FK_ACHATS_ORIGINE_ALERTE FOREIGN KEY (origine_alerte_id) REFERENCES articles (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9920924E6AF12ED9 ON achats (valide_par_id)');
        $this->addSql('CREATE INDEX IDX_9920924EFC29C013 ON achats (cree_par_id)');
        $this->addSql('CREATE INDEX IDX_9920924E12194EE1 ON achats (origine_alerte_id)');

        // Les anciens bons de commande : commandés à la date de la demande.
        $this->addSql('UPDATE achats SET date_commande = date_demande WHERE date_commande IS NULL');

        $this->addSql('ALTER TABLE achat_detail
            ADD quantite_stock NUMERIC(14, 3) DEFAULT NULL,
            ADD cout_revient_unitaire INT DEFAULT NULL,
            ADD quantite_recue INT DEFAULT NULL,
            ADD quantite_abimee INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE achats DROP FOREIGN KEY FK_ACHATS_VALIDE_PAR');
        $this->addSql('ALTER TABLE achats DROP FOREIGN KEY FK_ACHATS_CREE_PAR');
        $this->addSql('ALTER TABLE achats DROP FOREIGN KEY FK_ACHATS_ORIGINE_ALERTE');
        $this->addSql('DROP INDEX IDX_9920924E6AF12ED9 ON achats');
        $this->addSql('DROP INDEX IDX_9920924EFC29C013 ON achats');
        $this->addSql('DROP INDEX IDX_9920924E12194EE1 ON achats');
        $this->addSql('ALTER TABLE achats DROP date_commande, DROP date_promise, DROP frais_transport, DROP frais_transport_libelle, DROP frais_transport_base, DROP frais_transit, DROP frais_transit_libelle, DROP frais_transit_base, DROP acompte_pourcentage, DROP valide_le, DROP valide_par_id, DROP cree_par_id, DROP origine_alerte_id');
        $this->addSql('ALTER TABLE achat_detail DROP quantite_stock, DROP cout_revient_unitaire, DROP quantite_recue, DROP quantite_abimee');
        $this->addSql('ALTER TABLE fournisseurs DROP contact_nom, DROP contact_telephone, DROP ville, DROP pays, DROP fournit, DROP conditions_paiement, DROP acompte_pourcentage, DROP transport, DROP delai_promis_jours, DROP couleur');
    }
}
