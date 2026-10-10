<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les informations sensibles se cochent maintenant par utilisateur au
 * lieu d'etre heritees du profil. On les ajoute aux comptes existants
 * pour qu'ils gardent ce qu'ils voyaient : indicateurs commerciaux pour
 * la caisse, paiements pour les graphistes.
 */
final class Version20261010220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Informations sensibles cochees par utilisateur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE `user` SET roles = JSON_ARRAY_APPEND(roles, '$', 'ROLE_STATS_VENTES')
            WHERE JSON_CONTAINS(roles, '"ROLE_CAISSE_COMMANDE"') AND NOT JSON_CONTAINS(roles, '"ROLE_STATS_VENTES"')
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE `user` SET roles = JSON_ARRAY_APPEND(roles, '$', 'ROLE_PAIEMENT_VOIR')
            WHERE JSON_CONTAINS(roles, '"ROLE_GRAPHISTE"') AND NOT JSON_CONTAINS(roles, '"ROLE_PAIEMENT_VOIR"')
            SQL);
    }

    public function down(Schema $schema): void
    {
        foreach (['ROLE_STATS_VENTES', 'ROLE_COUT_MARGE', 'ROLE_PAIEMENT_VOIR'] as $role) {
            $this->addSql(sprintf(
                "UPDATE `user` SET roles = JSON_REMOVE(roles, JSON_UNQUOTE(JSON_SEARCH(roles, 'one', '%s'))) WHERE JSON_SEARCH(roles, 'one', '%1\$s') IS NOT NULL",
                $role
            ));
        }
    }
}
