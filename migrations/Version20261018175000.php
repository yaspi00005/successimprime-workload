<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Réponses de Yaya du 18/10/2026 :
 * - Q2 : le rôle « Caisse et commercial » reçoit d'office « Rendre l'argent
 *   du compte d'un client » (ROLE_SOLDE_CLIENT_REMBOURSER). Seuls les droits
 *   du rôle changent : un droit retiré à une personne sur sa fiche
 *   (user.droits_retires) reste retiré.
 */
final class Version20261018175000 extends AbstractMigration
{
    public const ROLE = 'ROLE_CAISSE_COMMANDE';

    public const DROIT = 'ROLE_SOLDE_CLIENT_REMBOURSER';

    public function getDescription(): string
    {
        return 'Réponses du 18/10 : la caisse rend l\'argent, rapport du jour à minuit, adresse de l\'ERP, opérations diverses de reprise, nom commercial des formats et finitions';
    }

    public const ADRESSE_ERP = 'http://192.168.10.65';

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE ecriture_diverse (id INT AUTO_INCREMENT NOT NULL, piece VARCHAR(40) NOT NULL, date_ecriture DATETIME NOT NULL, compte VARCHAR(8) NOT NULL, tiers VARCHAR(150) DEFAULT NULL, libelle VARCHAR(200) NOT NULL, debit INT NOT NULL, credit INT NOT NULL, origine VARCHAR(40) NOT NULL, cree_le DATETIME NOT NULL, commande_id INT DEFAULT NULL, paiement_id INT DEFAULT NULL, societe_id INT DEFAULT 1 NOT NULL, INDEX IDX_FA2780F882EA2E54 (commande_id), INDEX IDX_FA2780F82A4C4478 (paiement_id), INDEX IDX_FA2780F8FCF77503 (societe_id), INDEX idx_ecriture_diverse_date (date_ecriture), UNIQUE INDEX uniq_ecriture_diverse_piece_compte (piece, compte), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ecriture_diverse ADD CONSTRAINT FK_FA2780F882EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ecriture_diverse ADD CONSTRAINT FK_FA2780F82A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiements (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE ecriture_diverse ADD CONSTRAINT FK_FA2780F8FCF77503 FOREIGN KEY (societe_id) REFERENCES societe (id)');
        $this->addSql('ALTER TABLE finition ADD nom_commercial VARCHAR(120) DEFAULT NULL');
        $this->addSql('ALTER TABLE format ADD nom_commercial VARCHAR(120) DEFAULT NULL');

        $droits = $this->droitsDuRole();
        if (null !== $droits && $droits !== self::avec($droits)) {
            $this->addSql('UPDATE role_utilisateur SET droits = :droits WHERE code = :code', ['droits' => json_encode(self::avec($droits)), 'code' => self::ROLE]);
        }

        $rapports = $this->connection->fetchOne('SELECT valeurs FROM reglage WHERE cle = ?', ['rapports_mail']);
        if (false !== $rapports && null !== $rapports) {
            $valeurs = json_decode((string) $rapports, true);
            if (\is_array($valeurs)) {
                $avant = $valeurs;
                if (($valeurs['jour_heure'] ?? null) === '20:00') {
                    $valeurs['jour_heure'] = '00:00';
                }
                if (trim((string) ($valeurs['adresse_erp'] ?? '')) === '') {
                    $valeurs['adresse_erp'] = self::ADRESSE_ERP;
                }
                if ($valeurs !== $avant) {
                    $this->addSql('UPDATE reglage SET valeurs = ? WHERE cle = ?', [json_encode($valeurs, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE), 'rapports_mail']);
                }
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ecriture_diverse');
        $this->addSql('ALTER TABLE finition DROP nom_commercial');
        $this->addSql('ALTER TABLE format DROP nom_commercial');
        $droits = $this->droitsDuRole();
        if (null !== $droits && $droits !== self::sans($droits)) {
            $this->addSql('UPDATE role_utilisateur SET droits = :droits WHERE code = :code', ['droits' => json_encode(self::sans($droits)), 'code' => self::ROLE]);
        }
    }

    /**
     * @param list<string> $droits
     *
     * @return list<string>
     */
    public static function avec(array $droits): array
    {
        return \in_array(self::DROIT, $droits, true) ? array_values($droits) : [...array_values($droits), self::DROIT];
    }

    /**
     * @param list<string> $droits
     *
     * @return list<string>
     */
    public static function sans(array $droits): array
    {
        return array_values(array_filter($droits, static fn (string $d): bool => self::DROIT !== $d));
    }

    /** @return list<string>|null */
    private function droitsDuRole(): ?array
    {
        $json = $this->connection->fetchOne('SELECT droits FROM role_utilisateur WHERE code = ?', [self::ROLE]);
        if (false === $json || null === $json) {
            return null;
        }
        $droits = json_decode((string) $json, true);

        return \is_array($droits) ? array_values(array_filter($droits, 'is_string')) : null;
    }
}
