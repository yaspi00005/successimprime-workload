<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Paliers de prix et grilles (demandes de Yaya, docs/produits.md) :
 * - paliers par quantité (« de 1 à 100 pièces tel prix, de 101 à 200 tel
 *   prix ») et remises par surface (« dès 10 m², dès 20 m² ») de chaque
 *   configuration de produit ;
 * - grille Partenaire ou Association d'un client au prix standard (la
 *   remise de chaque grille se règle dans Paramètres › Grille tarifaire).
 * Rien n'est rempli : sans palier ni grille, les prix restent les mêmes.
 */
final class Version20261018230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Paliers de prix par quantité et par surface ; grilles Partenaire et Association des clients';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produit_configuration ADD paliers_quantite JSON DEFAULT NULL, ADD remises_surface JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE clients ADD grille_tarifaire VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE produit_configuration DROP paliers_quantite, DROP remises_surface');
        $this->addSql('ALTER TABLE clients DROP grille_tarifaire');
    }
}
