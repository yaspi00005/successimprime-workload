<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réglages › Espace client › « Paiement Orange Money en ligne » : jusqu'ici
 * l'interrupteur n'était pas appliqué et Orange Money marchait dès que les
 * clés étaient posées sur le serveur en ligne. Il l'est désormais
 * (OrangeMoney::estOuvert). Pour que le paiement de l'espace continue après
 * la mise à jour, un interrupteur resté coupé dans un réglage déjà
 * enregistré est rallumé ; la direction peut le couper ensuite.
 */
final class Version20261018110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Espace client : paiement Orange Money en ligne rallumé là où il marchait déjà (interrupteur désormais appliqué)';
    }

    public function up(Schema $schema): void
    {
        $lignes = $this->connection->fetchAllAssociative("SELECT id, valeurs FROM reglage WHERE cle = 'espace_client'");
        foreach ($lignes as $ligne) {
            $valeurs = json_decode((string) $ligne['valeurs'], true);
            if (!\is_array($valeurs) || !\array_key_exists('paiement_en_ligne', $valeurs) || $valeurs['paiement_en_ligne']) {
                continue;
            }
            $valeurs['paiement_en_ligne'] = true;
            $this->addSql('UPDATE reglage SET valeurs = ? WHERE id = ?', [json_encode($valeurs, \JSON_UNESCAPED_UNICODE), $ligne['id']]);
        }
    }

    public function down(Schema $schema): void
    {
        // Rien à défaire : l'ancien code ne lisait pas cet interrupteur.
    }
}
