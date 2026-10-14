<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Décision de Yaya du 14/10/2026 : les graphistes ne font plus de factures
 * et ne voient plus les créances ni les paiements. On retire « Factures » du
 * rôle Graphiste PAO, et « Factures » et « Paiements (voir) » des droits
 * ajoutés aux graphistes (Version20261010215311 leur avait gardé « Paiements
 * (voir) » de l'ancien profil). Chaque changement est noté dans le journal
 * d'activité, comme depuis Réglages › Utilisateurs et droits ; le droit peut
 * être rendu à une personne précise depuis sa fiche.
 */
final class Version20261014124500 extends AbstractMigration
{
    private const ROLE = 'ROLE_GRAPHISTE';
    private const RETIRE_DU_ROLE = ['ROLE_FACTURE'];
    private const RETIRES_DES_PERSONNES = ['ROLE_FACTURE', 'ROLE_PAIEMENT_VOIR'];

    public function getDescription(): string
    {
        return 'Graphistes : retrait des droits Factures et Paiements (voir)';
    }

    public function up(Schema $schema): void
    {
        $role = $this->connection->fetchAssociative('SELECT id, nom, droits FROM role_utilisateur WHERE code = ?', [self::ROLE]);
        if (false === $role) {
            return;
        }

        $avant = self::liste($role['droits']);
        $apres = array_values(array_diff($avant, self::RETIRE_DU_ROLE));
        if ($apres !== $avant) {
            $this->addSql('UPDATE role_utilisateur SET droits = ? WHERE id = ?', [json_encode($apres), $role['id']]);
            $this->tracer('RoleUtilisateur', (int) $role['id'], ['role' => $role['nom'], 'droits' => $avant], ['role' => $role['nom'], 'droits' => $apres]);
        }

        $personnes = $this->connection->fetchAllAssociative('SELECT id, droits_ajoutes, droits_retires FROM `user` WHERE profil_id = ?', [$role['id']]);
        foreach ($personnes as $personne) {
            $ajoutes = self::liste($personne['droits_ajoutes']);
            $restants = array_values(array_diff($ajoutes, self::RETIRES_DES_PERSONNES));
            if ($restants === $ajoutes) {
                continue;
            }
            $retires = self::liste($personne['droits_retires']);
            $this->addSql('UPDATE `user` SET droits_ajoutes = ? WHERE id = ?', [json_encode($restants), $personne['id']]);
            $this->tracer(
                'User',
                (int) $personne['id'],
                ['role' => $role['nom'], 'ajoutes' => $ajoutes, 'retires' => $retires],
                ['role' => $role['nom'], 'ajoutes' => $restants, 'retires' => $retires],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $role = $this->connection->fetchAssociative('SELECT id, droits FROM role_utilisateur WHERE code = ?', [self::ROLE]);
        if (false === $role) {
            return;
        }

        $droits = self::liste($role['droits']);
        if (!\in_array('ROLE_FACTURE', $droits, true)) {
            $this->addSql('UPDATE role_utilisateur SET droits = ? WHERE id = ?', [json_encode([...$droits, 'ROLE_FACTURE']), $role['id']]);
        }

        foreach ($this->connection->fetchAllAssociative('SELECT id, droits_ajoutes FROM `user` WHERE profil_id = ?', [$role['id']]) as $personne) {
            $ajoutes = self::liste($personne['droits_ajoutes']);
            if (!\in_array('ROLE_PAIEMENT_VOIR', $ajoutes, true)) {
                $this->addSql('UPDATE `user` SET droits_ajoutes = ? WHERE id = ?', [json_encode([...$ajoutes, 'ROLE_PAIEMENT_VOIR']), $personne['id']]);
            }
        }
    }

    /**
     * @param array<string, mixed> $avant
     * @param array<string, mixed> $apres
     */
    private function tracer(string $entite, int $id, array $avant, array $apres): void
    {
        $this->addSql(
            'INSERT INTO journal_activite (entite, entite_id, action, donnees_avant, donnees_apres, created_at, utilisateur_id) VALUES (?, ?, ?, ?, ?, NOW(), NULL)',
            [$entite, $id, 'droits', json_encode($avant, \JSON_UNESCAPED_UNICODE), json_encode($apres, \JSON_UNESCAPED_UNICODE)]
        );
    }

    /**
     * @return list<string>
     */
    private static function liste(mixed $json): array
    {
        $valeurs = \is_string($json) ? json_decode($json, true) : null;

        return \is_array($valeurs) ? array_values(array_filter($valeurs, 'is_string')) : [];
    }
}
