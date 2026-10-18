<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Pièces au m² (docs/saisie-devis-commandes.md) : une ligne de commande ou
 * de devis au m² peut être facturée selon le nombre de pièces qui tiennent
 * sur 1 m², espacement compris. La ligne garde l'espacement retenu (mm) ;
 * vide, elle reste au calcul au m² habituel. Aucune ligne existante ne
 * change.
 *
 * Demande de devis du site : la surface donnée par le visiteur quand il a
 * indiqué sa quantité en m² (la quantité reste le nombre de pièces).
 */
final class Version20261018161500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pièces au m² : espacement gardé sur les lignes de commande et de devis, surface demandée sur les demandes du site';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commandes_details ADD espacement_pieces_m2 NUMERIC(4, 1) DEFAULT NULL');
        $this->addSql('ALTER TABLE devis_details ADD espacement_pieces_m2 NUMERIC(4, 1) DEFAULT NULL');
        $this->addSql('ALTER TABLE demande_devis ADD quantite_m2 NUMERIC(9, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE demande_devis DROP quantite_m2');
        $this->addSql('ALTER TABLE devis_details DROP espacement_pieces_m2');
        $this->addSql('ALTER TABLE commandes_details DROP espacement_pieces_m2');
    }
}
