<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Achats, réception (docs/achats.md, maquette SU05) : quantités
 * facturées et casse ligne par ligne, frais réels, bon de livraison et
 * facture du fournisseur, paiement du solde bloqué, réclamations au
 * fournisseur, et la dette fournisseur reliée à sa commande d'achat.
 */
final class Version20261020050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Achats : réception détaillée, réclamations fournisseurs et paiement bloqué';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE achat_detail
            ADD quantite_facturee INT DEFAULT NULL,
            ADD casse_motif VARCHAR(60) DEFAULT NULL,
            ADD cout_revient_prevu INT DEFAULT NULL,
            ADD stock_avant NUMERIC(14, 3) DEFAULT NULL,
            ADD quantite_entree NUMERIC(14, 3) DEFAULT NULL,
            ADD stock_entre_le DATETIME DEFAULT NULL');

        $this->addSql('ALTER TABLE achats
            ADD bl_numero VARCHAR(50) DEFAULT NULL,
            ADD bl_date DATE DEFAULT NULL,
            ADD facture_numero VARCHAR(50) DEFAULT NULL,
            ADD facture_montant INT DEFAULT NULL,
            ADD facture_date DATE DEFAULT NULL,
            ADD frais_transport_prevu INT DEFAULT NULL,
            ADD frais_transit_prevu INT DEFAULT NULL,
            ADD paiement_bloque TINYINT(1) DEFAULT 0 NOT NULL,
            ADD paiement_bloque_motif VARCHAR(255) DEFAULT NULL,
            ADD paiement_force_note VARCHAR(255) DEFAULT NULL,
            ADD paiement_force_le DATETIME DEFAULT NULL,
            ADD paiement_force_par_id INT DEFAULT NULL,
            ADD receptionne_par_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE achats ADD CONSTRAINT FK_ACHATS_PAIEMENT_FORCE_PAR FOREIGN KEY (paiement_force_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE achats ADD CONSTRAINT FK_ACHATS_RECEPTIONNE_PAR FOREIGN KEY (receptionne_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_9920924EECA2AE18 ON achats (paiement_force_par_id)');
        $this->addSql('CREATE INDEX IDX_9920924E4A76A654 ON achats (receptionne_par_id)');

        $this->addSql('CREATE TABLE reclamation_fournisseur (
            id INT AUTO_INCREMENT NOT NULL,
            achat_id INT NOT NULL,
            ligne_id INT DEFAULT NULL,
            article_id INT DEFAULT NULL,
            quantite INT NOT NULL,
            montant INT DEFAULT 0 NOT NULL,
            photo VARCHAR(60) DEFAULT NULL,
            statut VARCHAR(20) NOT NULL,
            message LONGTEXT DEFAULT NULL,
            message_envoye_le DATETIME DEFAULT NULL,
            cree_le DATETIME NOT NULL,
            cree_par_id INT DEFAULT NULL,
            cloturee_le DATETIME DEFAULT NULL,
            cloturee_par_id INT DEFAULT NULL,
            INDEX IDX_2ACA7AA3FE95D117 (achat_id),
            INDEX IDX_2ACA7AA35A438E76 (ligne_id),
            INDEX IDX_2ACA7AA37294869C (article_id),
            INDEX IDX_2ACA7AA3FC29C013 (cree_par_id),
            INDEX IDX_2ACA7AA3FD9AF8EE (cloturee_par_id),
            INDEX idx_reclamation_fournisseur_statut (statut),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE reclamation_fournisseur ADD CONSTRAINT FK_RECLAMATION_FOURNISSEUR_ACHAT FOREIGN KEY (achat_id) REFERENCES achats (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reclamation_fournisseur ADD CONSTRAINT FK_RECLAMATION_FOURNISSEUR_LIGNE FOREIGN KEY (ligne_id) REFERENCES achat_detail (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reclamation_fournisseur ADD CONSTRAINT FK_RECLAMATION_FOURNISSEUR_ARTICLE FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reclamation_fournisseur ADD CONSTRAINT FK_RECLAMATION_FOURNISSEUR_CREE_PAR FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reclamation_fournisseur ADD CONSTRAINT FK_RECLAMATION_FOURNISSEUR_CLOTUREE_PAR FOREIGN KEY (cloturee_par_id) REFERENCES `user` (id) ON DELETE SET NULL');

        // La facture du fournisseur à payer (Finances › Dépenses) reliée à sa commande d'achat.
        $this->addSql('ALTER TABLE depense ADD achat_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE depense ADD CONSTRAINT FK_DEPENSE_ACHAT FOREIGN KEY (achat_id) REFERENCES achats (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_34059757FE95D117 ON depense (achat_id)');

        // Les réceptions déjà faites : tout est entré en stock, la quantité commandée compte.
        $this->addSql("UPDATE achat_detail d JOIN achats a ON a.id = d.achat_id SET d.stock_entre_le = a.date_reception WHERE a.statut = 'recu' AND a.date_reception IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE depense DROP FOREIGN KEY FK_DEPENSE_ACHAT');
        $this->addSql('DROP INDEX IDX_34059757FE95D117 ON depense');
        $this->addSql('ALTER TABLE depense DROP achat_id');
        $this->addSql('DROP TABLE reclamation_fournisseur');
        $this->addSql('ALTER TABLE achats DROP FOREIGN KEY FK_ACHATS_PAIEMENT_FORCE_PAR');
        $this->addSql('ALTER TABLE achats DROP FOREIGN KEY FK_ACHATS_RECEPTIONNE_PAR');
        $this->addSql('DROP INDEX IDX_9920924EECA2AE18 ON achats');
        $this->addSql('DROP INDEX IDX_9920924E4A76A654 ON achats');
        $this->addSql('ALTER TABLE achats DROP bl_numero, DROP bl_date, DROP facture_numero, DROP facture_montant, DROP facture_date, DROP frais_transport_prevu, DROP frais_transit_prevu, DROP paiement_bloque, DROP paiement_bloque_motif, DROP paiement_force_note, DROP paiement_force_le, DROP paiement_force_par_id, DROP receptionne_par_id');
        $this->addSql('ALTER TABLE achat_detail DROP quantite_facturee, DROP casse_motif, DROP cout_revient_prevu, DROP stock_avant, DROP quantite_entree, DROP stock_entre_le');
    }
}
