<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Graphistes PAO : pas d'accès aux encaissements (demande de Yaya du
 * 18/10/2026). Le droit « Paiements » (ROLE_PAIEMENT_VOIR) leur avait été
 * ajouté sur leur fiche lors du passage aux droits par personne
 * (Version20261010215311, ce qu'ils voyaient avant) : il est retiré de la
 * fiche de chaque graphiste, et du rôle s'il y avait été coché.
 * Ils gardent devis, commandes, factures et créances.
 */
final class Version20261018220000 extends AbstractMigration
{
    public const ROLE = 'ROLE_GRAPHISTE';

    public const DROIT = 'ROLE_PAIEMENT_VOIR';

    public function getDescription(): string
    {
        return 'Graphistes PAO : retrait du droit Paiements (pas d\'accès aux encaissements)';
    }

    public function up(Schema $schema): void
    {
        $role = $this->connection->fetchAssociative('SELECT id, droits FROM role_utilisateur WHERE code = ?', [self::ROLE]);
        if (false === $role) {
            return;
        }
        $droits = json_decode((string) $role['droits'], true) ?: [];
        if (\in_array(self::DROIT, $droits, true)) {
            $this->addSql('UPDATE role_utilisateur SET droits = ? WHERE id = ?', [json_encode(self::sans($droits)), $role['id']]);
        }
        foreach ($this->connection->fetchAllAssociative('SELECT id, droits_ajoutes FROM `user` WHERE profil_id = ?', [$role['id']]) as $u) {
            $ajoutes = json_decode((string) $u['droits_ajoutes'], true) ?: [];
            if (\in_array(self::DROIT, $ajoutes, true)) {
                $this->addSql('UPDATE `user` SET droits_ajoutes = ? WHERE id = ?', [json_encode(self::sans($ajoutes)), $u['id']]);
            }
        }
    }

    public function down(Schema $schema): void
    {
        // Les personnes qui avaient le droit ne sont pas connues : rien à remettre.
    }

    /**
     * @param list<string> $droits
     *
     * @return list<string>
     */
    private static function sans(array $droits): array
    {
        return array_values(array_filter($droits, static fn (string $d): bool => self::DROIT !== $d));
    }
}
