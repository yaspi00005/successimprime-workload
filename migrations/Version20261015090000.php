<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rôle « Caisse et commercial » : la trésorerie et les soldes des comptes
 * (ROLE_TRESORERIE_VOIR) ne font plus partie de ses droits par défaut
 * (décision de Yaya du 15/10/2026). La caisse garde l'encaissement, les
 * dépenses et recettes, les virements et la clôture de sa caisse.
 *
 * Seuls les droits du rôle changent : les droits ajoutés ou retirés à une
 * personne sur sa fiche (user.droits_ajoutes, user.droits_retires) restent
 * tels quels, c'est là que la Direction accorde la trésorerie à quelqu'un.
 */
final class Version20261015090000 extends AbstractMigration
{
    public const ROLE = 'ROLE_CAISSE_COMMANDE';

    public const DROIT = 'ROLE_TRESORERIE_VOIR';

    public function getDescription(): string
    {
        return 'Rôle Caisse et commercial : sans « Trésorerie et soldes » par défaut (la Direction l\'accorde à une personne)';
    }

    public function up(Schema $schema): void
    {
        $droits = $this->droitsDuRole();
        if (null !== $droits && $droits !== self::sans($droits)) {
            $this->addSql('UPDATE role_utilisateur SET droits = :droits WHERE code = :code', ['droits' => json_encode(self::sans($droits)), 'code' => self::ROLE]);
        }
    }

    public function down(Schema $schema): void
    {
        $droits = $this->droitsDuRole();
        if (null !== $droits && $droits !== self::avec($droits)) {
            $this->addSql('UPDATE role_utilisateur SET droits = :droits WHERE code = :code', ['droits' => json_encode(self::avec($droits)), 'code' => self::ROLE]);
        }
    }

    /**
     * Droits du rôle sans la trésorerie, les autres dans leur ordre.
     *
     * @param list<string> $droits
     *
     * @return list<string>
     */
    public static function sans(array $droits): array
    {
        return array_values(array_filter($droits, static fn (string $d): bool => self::DROIT !== $d));
    }

    /**
     * Droits du rôle avec la trésorerie (à la fin, comme à la création du rôle).
     *
     * @param list<string> $droits
     *
     * @return list<string>
     */
    public static function avec(array $droits): array
    {
        return \in_array(self::DROIT, $droits, true) ? array_values($droits) : [...array_values($droits), self::DROIT];
    }

    /**
     * Droits enregistrés pour le rôle, ou null s'il n'existe pas dans cette base.
     *
     * @return list<string>|null
     */
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
