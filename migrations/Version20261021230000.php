<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Impression en cours (PD04, docs/production-rouleau.md) et temps réels
 * (PD06, docs/production-colis.md) : pauses, pièces ratées, fin
 * d'impression et fin du rouleau ; début de chaque pièce ; temps réel par
 * étape d'un ordre ; article du stock posé avec une finition (œillets).
 * ERP local (la finition garde ses champs synchronisés, sans celui-ci).
 */
final class Version20261021230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Production : impression en cours, temps réels par étape, article d\'une finition';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finition ADD article_stock_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE finition ADD CONSTRAINT FK_2060F8265825957B FOREIGN KEY (article_stock_id) REFERENCES articles (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_2060F8265825957B ON finition (article_stock_id)');
        $this->addSql('ALTER TABLE ordre_production ADD temps_reels JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE piece_tirage ADD debutee_le DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE tirage_rouleau ADD pauses JSON DEFAULT NULL, ADD gaches JSON DEFAULT NULL, ADD imprime_le DATETIME DEFAULT NULL, ADD termine_le DATETIME DEFAULT NULL, ADD imprime_par_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tirage_rouleau ADD CONSTRAINT FK_8BFE74232E58089 FOREIGN KEY (imprime_par_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8BFE74232E58089 ON tirage_rouleau (imprime_par_id)');
        // Les rouleaux déjà démarrés : leur première pièce a commencé au démarrage.
        $this->addSql("UPDATE piece_tirage p INNER JOIN tirage_rouleau t ON t.id = p.tirage_id SET p.debutee_le = t.demarre_le WHERE p.statut <> 'a_imprimer' AND t.demarre_le IS NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finition DROP FOREIGN KEY FK_2060F8265825957B');
        $this->addSql('DROP INDEX IDX_2060F8265825957B ON finition');
        $this->addSql('ALTER TABLE finition DROP article_stock_id');
        $this->addSql('ALTER TABLE ordre_production DROP temps_reels');
        $this->addSql('ALTER TABLE piece_tirage DROP debutee_le');
        $this->addSql('ALTER TABLE tirage_rouleau DROP FOREIGN KEY FK_8BFE74232E58089');
        $this->addSql('DROP INDEX IDX_8BFE74232E58089 ON tirage_rouleau');
        $this->addSql('ALTER TABLE tirage_rouleau DROP pauses, DROP gaches, DROP imprime_le, DROP termine_le, DROP imprime_par_id');
    }
}
