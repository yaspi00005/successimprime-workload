<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saisie des devis et commandes (docs/documents-livraisons.md, « Frais de
 * livraison et objet ») : frais de livraison choisis parmi les paliers
 * (compris dans les totaux) et objet du document, copiés du devis à la
 * commande puis à la facture. Conditions de paiement de chaque entreprise
 * émettrice, imprimées dans le bloc « NB » des factures : les entreprises
 * existantes reprennent la mention écrite jusqu'ici dans le modèle.
 */
final class Version20261016100000 extends AbstractMigration
{
    private const CONDITIONS = 'Conditions de paiement : paiement à réception de la facture. Les frais de transaction mobile money (Orange Money, Wave) sont à la charge du client.';

    public function getDescription(): string
    {
        return 'Devis, commandes et factures : frais de livraison et objet ; conditions de paiement des entreprises';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE commandes ADD frais_livraison INT DEFAULT 0 NOT NULL, ADD objet VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE devis ADD frais_livraison INT DEFAULT 0 NOT NULL, ADD objet VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE factures ADD frais_livraison INT DEFAULT 0 NOT NULL, ADD objet VARCHAR(150) DEFAULT NULL');
        $this->addSql('ALTER TABLE entreprise ADD conditions_paiement VARCHAR(500) DEFAULT NULL');
        $this->addSql('UPDATE entreprise SET conditions_paiement = :texte', ['texte' => self::CONDITIONS]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entreprise DROP conditions_paiement');
        $this->addSql('ALTER TABLE factures DROP frais_livraison, DROP objet');
        $this->addSql('ALTER TABLE devis DROP frais_livraison, DROP objet');
        $this->addSql('ALTER TABLE commandes DROP frais_livraison, DROP objet');
    }
}
