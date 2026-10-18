<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Retouche prépresse (docs/prepresse-retouche.md) : un fichier généré par
 * l'outil garde ses réglages (dimensions, mode, contour, sortie, contrôles)
 * et, s'il a été validé malgré une alerte, la remarque montrée au client
 * avec le BAT. Aucun fichier existant ne change.
 */
final class Version20261018174000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Retouche prépresse : réglages et remarque du BAT sur les fichiers des commandes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commande_detail_fichier ADD retouche JSON DEFAULT NULL, ADD remarque_bat VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commande_detail_fichier DROP retouche, DROP remarque_bat');
    }
}
