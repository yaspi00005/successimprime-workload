<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Suivi des devis (maquettes GC03, GC04, DT01, DT03, docs/devis.md) :
 * validité, délai de réalisation et conditions (acompte, solde, fichier,
 * garantie), canaux d'envoi, versions envoyées, ouvertures par le client,
 * réponse (motif de refus, demande de modification) et relances WhatsApp.
 *
 * Les devis déjà envoyés reçoivent leur date d'envoi (date du devis) et
 * une date de validité à 15 jours : la tâche app:devis:relances passe
 * ensuite « expiré » ceux dont la validité est dépassée.
 */
final class Version20261018172000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Suivi des devis : validité, conditions, versions, ouvertures, réponse du client et relances';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE devis ADD version INT DEFAULT 1 NOT NULL, ADD versions JSON DEFAULT NULL, ADD validite_jours INT DEFAULT 15 NOT NULL, ADD date_validite DATE DEFAULT NULL, ADD delai_realisation VARCHAR(80) DEFAULT NULL, ADD acompte_pourcentage INT DEFAULT 50 NOT NULL, ADD condition_solde VARCHAR(120) DEFAULT NULL, ADD condition_fichier VARCHAR(120) DEFAULT NULL, ADD condition_garantie VARCHAR(120) DEFAULT NULL, ADD canaux_envoi JSON DEFAULT NULL, ADD envoye_le DATETIME DEFAULT NULL, ADD envoye_par VARCHAR(80) DEFAULT NULL, ADD nombre_vues INT DEFAULT 0 NOT NULL, ADD premiere_vue_le DATETIME DEFAULT NULL, ADD derniere_vue_le DATETIME DEFAULT NULL, ADD vu_par VARCHAR(80) DEFAULT NULL, ADD reponse_le DATETIME DEFAULT NULL, ADD repondu_par VARCHAR(80) DEFAULT NULL, ADD motif_refus VARCHAR(255) DEFAULT NULL, ADD demande_modification LONGTEXT DEFAULT NULL, ADD demande_modification_le DATETIME DEFAULT NULL, ADD relances JSON DEFAULT NULL');
        $this->addSql("UPDATE devis SET envoye_le = date_devis, date_validite = DATE(DATE_ADD(date_devis, INTERVAL 15 DAY)) WHERE statut <> 'brouillon'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE devis SET statut = 'envoye' WHERE statut = 'vu'");
        $this->addSql('ALTER TABLE devis DROP version, DROP versions, DROP validite_jours, DROP date_validite, DROP delai_realisation, DROP acompte_pourcentage, DROP condition_solde, DROP condition_fichier, DROP condition_garantie, DROP canaux_envoi, DROP envoye_le, DROP envoye_par, DROP nombre_vues, DROP premiere_vue_le, DROP derniere_vue_le, DROP vu_par, DROP reponse_le, DROP repondu_par, DROP motif_refus, DROP demande_modification, DROP demande_modification_le, DROP relances');
    }
}
