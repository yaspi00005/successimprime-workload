<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260907180000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Autorise plusieurs rappels de paiement pour la meme commande "
            . "et la meme regle : une regle 'tous les N jours' doit pouvoir se "
            . "redeclencher a chaque echeance (J+N, J+2N, J+3N...) tant que la "
            . "commande n'est pas soldee, ce que l'ancienne contrainte "
            . "d'unicite empechait.";
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'ALTER TABLE rappel_paiement_envoye DROP INDEX uniq_commande_regle'
        );

        $this->addSql(
            'CREATE INDEX idx_rappel_commande_regle ON rappel_paiement_envoye (commande_id, regle_id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql(
            'DROP INDEX idx_rappel_commande_regle ON rappel_paiement_envoye'
        );

        $this->addSql(
            'ALTER TABLE rappel_paiement_envoye ADD UNIQUE INDEX uniq_commande_regle (commande_id, regle_id)'
        );
    }
}
