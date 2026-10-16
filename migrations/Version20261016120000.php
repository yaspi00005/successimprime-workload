<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remise globale (docs/factures-entreprises.md, « Remise globale ») : le
 * total TTC d'une commande ou d'un devis devient ce que le client doit,
 * remise globale déduite (App\Service\TotauxDocument). Jusqu'ici il gardait
 * le montant avant remise : le reste à payer ne baissait pas et une facture
 * créée depuis la commande comptait la remise en TVA.
 *
 * Aucun montant demandé ne change : le nouveau total TTC est le « montant à
 * payer » déjà calculé (total TTC − remise). Le reste à payer et l'état du
 * paiement des commandes concernées sont refaits depuis les paiements
 * validés. Les factures ne sont jamais modifiées : celles qui ont compté la
 * remise en TVA sont seulement comptées ici (à corriger par un avoir).
 */
final class Version20261016120000 extends AbstractMigration
{
    /** Paiements validés de chaque commande. */
    private const PAYES = "SELECT commande_id, SUM(montant) AS paye FROM paiements WHERE statut = 'valide' GROUP BY commande_id";

    public function getDescription(): string
    {
        return 'Commandes et devis : total TTC remise globale déduite ; reste à payer et état du paiement refaits';
    }

    public function up(Schema $schema): void
    {
        // Factures déjà créées avec la remise comptée en TVA : signalées, jamais réécrites.
        $factures = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM factures WHERE remise > 0 AND montant_tva >= remise AND total_ttc >= total_ht');
        if ($factures > 0) {
            $this->write(\sprintf('<comment>%d facture(s) ont compté la remise globale en TVA : elles ne sont pas modifiées (voir docs/factures-entreprises.md, « Remise globale »).</comment>', $factures));
        }

        $this->addSql('UPDATE commandes SET montant_apayer = GREATEST(0, total_ttc - remise) WHERE remise > 0');
        $this->addSql('UPDATE commandes SET total_ttc = montant_apayer WHERE remise > 0');
        $this->addSql(self::situation('c.total_ttc', 'GREATEST(0, c.total_ttc - COALESCE(p.paye, 0))'));
        $this->addSql('UPDATE devis SET montant_apayer = GREATEST(0, total_ttc - remise) WHERE remise > 0');
        $this->addSql('UPDATE devis SET total_ttc = montant_apayer WHERE remise > 0');
    }

    public function down(Schema $schema): void
    {
        // Ancien sens : total TTC avant remise (état du paiement comparé à lui), montant à payer après remise.
        $this->addSql('UPDATE devis SET total_ttc = total_ttc + remise WHERE remise > 0');
        $this->addSql('UPDATE commandes SET total_ttc = total_ttc + remise WHERE remise > 0');
        $this->addSql(self::situation('c.total_ttc', 'GREATEST(0, c.montant_apayer - COALESCE(p.paye, 0))'));
    }

    /** Reste à payer et état du paiement des commandes avec une remise globale. */
    private static function situation(string $du, string $reste): string
    {
        return 'UPDATE commandes c LEFT JOIN ('.self::PAYES.') p ON p.commande_id = c.id'
            .' SET c.reste_apayer = '.$reste.','
            ." c.statut_paiement = CASE WHEN COALESCE(p.paye, 0) <= 0 THEN 'impayee'"
            ." WHEN COALESCE(p.paye, 0) < $du THEN 'partielle' ELSE 'payee' END"
            .' WHERE c.remise > 0';
    }
}
