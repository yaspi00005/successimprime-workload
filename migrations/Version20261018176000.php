<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Mes produits et panier » (maquettes MP01 à MP07, docs/mes-produits.md) :
 * le client ajoute ses produits avec leur image, le prépresse y lie le
 * fichier traité du dossier de l'atelier (référence et empreinte SHA-256,
 * une par taille validée), prix par quantité. La ligne de commande garde la
 * référence du fichier lié retenu. Fiche client : « dispensé d'acompte ».
 *
 * Les produits déjà enregistrés gardent leur fonctionnement (origine
 * « atelier », BAT validé = prêt à imprimer à leur format).
 */
final class Version20261018176000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mes produits et panier : image, type, fichiers liés et paliers des produits du client ; fichier lié des lignes ; client dispensé d\'acompte';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE clients ADD sans_acompte TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE commandes_details ADD fichier_lie JSON DEFAULT NULL');
        $this->addSql("ALTER TABLE produit_client ADD origine VARCHAR(10) DEFAULT 'atelier' NOT NULL, ADD type VARCHAR(60) DEFAULT NULL, ADD rond TINYINT DEFAULT 0 NOT NULL, ADD image_remplacee_le DATETIME DEFAULT NULL, ADD etat_lien VARCHAR(12) DEFAULT 'a_preparer' NOT NULL, ADD fichiers_lies JSON DEFAULT NULL, ADD paliers JSON DEFAULT NULL, ADD image_id INT DEFAULT NULL");
        $this->addSql('ALTER TABLE produit_client ADD CONSTRAINT FK_3016CF6F3DA5256D FOREIGN KEY (image_id) REFERENCES commande_detail_fichier (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_3016CF6F3DA5256D ON produit_client (image_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produit_client DROP FOREIGN KEY FK_3016CF6F3DA5256D');
        $this->addSql('DROP INDEX IDX_3016CF6F3DA5256D ON produit_client');
        $this->addSql('ALTER TABLE produit_client DROP origine, DROP type, DROP rond, DROP image_remplacee_le, DROP etat_lien, DROP fichiers_lies, DROP paliers, DROP image_id');
        $this->addSql('ALTER TABLE commandes_details DROP fichier_lie');
        $this->addSql('ALTER TABLE clients DROP sans_acompte');
    }
}
