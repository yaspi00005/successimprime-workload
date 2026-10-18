<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Nouveau calcul des points du pointage (docs/pointage.md, « Calcul des
 * points ») : l'horaire type de chaque employé (accueil et caisse, ou
 * atelier et autres ; vide = d'après son poste) et la date à partir de
 * laquelle le nouveau calcul s'applique, le jour de la migration. Les
 * journées d'avant gardent l'ancien barème : les mois passés ne changent
 * pas sans qu'on le décide (Paramètres › Points du pointage).
 */
final class Version20261018160500 extends AbstractMigration
{
    private const TUILE = 'points_pointage';

    public function getDescription(): string
    {
        return 'Pointage : horaire type de l\'employé et date de début du nouveau calcul des points';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE employes ADD horaire_type VARCHAR(10) DEFAULT NULL');
        // Date de l'application (fuseau du serveur PHP), comme celle des pointages.
        $this->addSql('INSERT IGNORE INTO reglage (cle, valeurs) VALUES (?, ?)', [self::TUILE, json_encode(['debut' => (new \DateTimeImmutable())->format('Y-m-d')], \JSON_THROW_ON_ERROR)]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DELETE FROM reglage WHERE cle = ?', [self::TUILE]);
        $this->addSql('ALTER TABLE employes DROP horaire_type');
    }
}
