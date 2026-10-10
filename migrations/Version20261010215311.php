<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Roles modifiables et droits a cocher par personne (maquettes U02, U03).
 *
 * Les cinq profils deviennent des roles en base avec les droits qu'ils
 * avaient dans security.yaml. Chaque compte est rattache a son role, et
 * ce qu'il voyait en plus (statistiques globales, paiements pour les
 * graphistes) devient un droit ajoute pour lui.
 */
final class Version20261010215311 extends AbstractMigration
{
    private const ROLES = [
        ['ROLE_ADMIN', 'Administrateur', [], 0],
        ['ROLE_CAISSE_COMMANDE', 'Caisse et commercial', ['ROLE_CLIENT', 'ROLE_DEVIS', 'ROLE_COMMANDE', 'ROLE_FACTURE', 'ROLE_PAIEMENT_ENCAISSER', 'ROLE_TRESORERIE_SAISIR', 'ROLE_TRESORERIE_TRANSFERER', 'ROLE_JOURNAL_CAISSE', 'ROLE_STATS_PERSO', 'ROLE_STATS_VENTES', 'ROLE_PAIEMENT_VOIR', 'ROLE_TRESORERIE_VOIR'], 1],
        ['ROLE_GRAPHISTE', 'Graphiste PAO', ['ROLE_CLIENT', 'ROLE_DEVIS', 'ROLE_COMMANDE', 'ROLE_FACTURE', 'ROLE_PREPRESSE', 'ROLE_PRODUCTION_VOIR', 'ROLE_PRODUCTION_GERER', 'ROLE_STATS_PERSO'], 2],
        ['ROLE_PRODUCTION', 'Opérateur production', ['ROLE_PRODUCTION_VOIR', 'ROLE_PRODUCTION_GERER', 'ROLE_STATS_PERSO'], 3],
        ['ROLE_LIVREUR', 'Livreur', ['ROLE_LIVRAISON', 'ROLE_STATS_PERSO'], 4],
    ];

    /** Ce que les graphistes voyaient avant que les paiements deviennent un droit a cocher. */
    private const AVANT_POUR_GRAPHISTE = ['ROLE_PAIEMENT_VOIR'];

    private const CASES_SENSIBLES = ['ROLE_STATS_GLOBAL', 'ROLE_STATS_VENTES', 'ROLE_COUT_MARGE', 'ROLE_PAIEMENT_VOIR'];

    public function getDescription(): string
    {
        return 'Roles modifiables et droits ajoutes ou retires par utilisateur';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE role_utilisateur (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(60) NOT NULL, nom VARCHAR(80) NOT NULL, droits JSON NOT NULL, systeme TINYINT DEFAULT 0 NOT NULL, ordre INT DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_2F4B3B3A77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE `user` ADD droits_ajoutes JSON DEFAULT \'[]\' NOT NULL, ADD droits_retires JSON DEFAULT \'[]\' NOT NULL, ADD profil_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD CONSTRAINT FK_8D93D649275ED078 FOREIGN KEY (profil_id) REFERENCES role_utilisateur (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_8D93D649275ED078 ON `user` (profil_id)');

        foreach (self::ROLES as [$code, $nom, $droits, $ordre]) {
            $this->addSql(
                'INSERT INTO role_utilisateur (code, nom, droits, systeme, ordre) VALUES (?, ?, ?, 1, ?)',
                [$code, $nom, json_encode($droits), $ordre]
            );
        }
    }

    public function postUp(Schema $schema): void
    {
        $roles = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, code, droits FROM role_utilisateur') as $role) {
            $roles[$role['code']] = ['id' => (int) $role['id'], 'droits' => json_decode($role['droits'], true)];
        }

        foreach ($this->connection->fetchAllAssociative('SELECT id, roles FROM `user`') as $user) {
            $anciens = json_decode((string) $user['roles'], true) ?: [];
            $code = \in_array('ROLE_ADMIN', $anciens, true) ? 'ROLE_ADMIN' : (array_values(array_intersect(array_keys($roles), $anciens))[0] ?? null);

            if (null === $code) {
                continue;
            }

            $ajoutes = array_intersect(self::CASES_SENSIBLES, $anciens);

            if ('ROLE_GRAPHISTE' === $code) {
                $ajoutes = array_merge($ajoutes, self::AVANT_POUR_GRAPHISTE);
            }

            $ajoutes = 'ROLE_ADMIN' === $code ? [] : array_values(array_unique(array_diff($ajoutes, $roles[$code]['droits'])));

            $this->connection->executeStatement(
                'UPDATE `user` SET profil_id = ?, roles = ?, droits_ajoutes = ? WHERE id = ?',
                [$roles[$code]['id'], json_encode([$code]), json_encode($ajoutes), $user['id']]
            );
        }
    }

    public function down(Schema $schema): void
    {
        // Les droits ajoutes repartent dans la colonne roles, comme avant.
        $this->addSql('UPDATE `user` SET roles = JSON_MERGE_PRESERVE(roles, JSON_EXTRACT(droits_ajoutes, \'$\')) WHERE JSON_LENGTH(droits_ajoutes) > 0');
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D649275ED078');
        $this->addSql('DROP INDEX IDX_8D93D649275ED078 ON `user`');
        $this->addSql('ALTER TABLE `user` DROP droits_ajoutes, DROP droits_retires, DROP profil_id');
        $this->addSql('DROP TABLE role_utilisateur');
    }
}
