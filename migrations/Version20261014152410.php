<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014152410 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Demande de conception : supports (9 de départ), demandes du client, images et crédit IA, paiement Orange Money d\'un acompte ou d\'un pack';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE compteur_ia_jour (jour DATE NOT NULL, images INT NOT NULL, PRIMARY KEY (jour)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE credit_ia (id INT AUTO_INCREMENT NOT NULL, solde INT NOT NULL, client_id INT NOT NULL, UNIQUE INDEX uniq_credit_ia_client (client_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE demande_conception (id INT AUTO_INCREMENT NOT NULL, numero VARCHAR(24) DEFAULT NULL, auteur VARCHAR(80) DEFAULT NULL, support_nom VARCHAR(80) NOT NULL, support_format VARCHAR(60) NOT NULL, largeur_cm DOUBLE PRECISION DEFAULT NULL, hauteur_cm DOUBLE PRECISION DEFAULT NULL, forfait INT NOT NULL, acompte_pourcent INT NOT NULL, corrections_incluses INT NOT NULL, delai_bat_heures INT NOT NULL, titre VARCHAR(120) DEFAULT NULL, sous_titre VARCHAR(120) DEFAULT NULL, date_heure VARCHAR(120) DEFAULT NULL, coordonnees VARCHAR(160) DEFAULT NULL, consignes LONGTEXT DEFAULT NULL, couleurs JSON NOT NULL, styles JSON NOT NULL, quantite INT DEFAULT NULL, pour_quand DATE DEFAULT NULL, exemple_lien VARCHAR(255) DEFAULT NULL, choix VARCHAR(10) DEFAULT NULL, statut VARCHAR(12) NOT NULL, etape INT NOT NULL, finalisation_graphiste TINYINT NOT NULL, nombre_images INT NOT NULL, cree_le DATETIME NOT NULL, modifie_le DATETIME NOT NULL, envoyee_le DATETIME DEFAULT NULL, prise_en_charge_le DATETIME DEFAULT NULL, prise_par VARCHAR(80) DEFAULT NULL, terminee_le DATETIME DEFAULT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, compte_id INT DEFAULT NULL, support_id INT DEFAULT NULL, commande_id INT DEFAULT NULL, paiement_acompte_id INT DEFAULT NULL, image_choisie_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_99ACF555B5B48B91 (public_id), INDEX IDX_99ACF55519EB6921 (client_id), INDEX IDX_99ACF555F2C56620 (compte_id), INDEX IDX_99ACF555315B405 (support_id), INDEX IDX_99ACF55582EA2E54 (commande_id), INDEX IDX_99ACF5555FAC1B46 (paiement_acompte_id), INDEX IDX_99ACF555C9B629EE (image_choisie_id), INDEX idx_demande_conception_statut (statut), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE demande_conception_element (demande_conception_id INT NOT NULL, commande_detail_fichier_id INT NOT NULL, INDEX IDX_AC40C36CBA9DD3C9 (demande_conception_id), INDEX IDX_AC40C36C4FB4C8B8 (commande_detail_fichier_id), PRIMARY KEY (demande_conception_id, commande_detail_fichier_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE image_ia (id INT AUTO_INCREMENT NOT NULL, numero INT NOT NULL, consigne VARCHAR(300) DEFAULT NULL, prompt LONGTEXT NOT NULL, fournisseur VARCHAR(20) NOT NULL, modele VARCHAR(60) NOT NULL, statut VARCHAR(10) NOT NULL, fichier VARCHAR(80) DEFAULT NULL, largeur INT DEFAULT NULL, hauteur INT DEFAULT NULL, prix INT NOT NULL, cree_le DATETIME NOT NULL, terminee_le DATETIME DEFAULT NULL, erreur VARCHAR(255) DEFAULT NULL, demande_id INT NOT NULL, client_id INT NOT NULL, INDEX IDX_4DCFD1E280E95E18 (demande_id), INDEX IDX_4DCFD1E219EB6921 (client_id), INDEX idx_image_ia_statut (statut, cree_le), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE mouvement_credit_ia (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(14) NOT NULL, montant INT NOT NULL, images INT NOT NULL, libelle VARCHAR(120) NOT NULL, auteur VARCHAR(80) DEFAULT NULL, etat VARCHAR(10) NOT NULL, cle VARCHAR(60) DEFAULT NULL, cree_le DATETIME NOT NULL, public_id BINARY(16) NOT NULL, client_id INT NOT NULL, commande_id INT DEFAULT NULL, paiement_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_4D56B67BB5B48B91 (public_id), INDEX IDX_4D56B67B19EB6921 (client_id), INDEX IDX_4D56B67B82EA2E54 (commande_id), INDEX IDX_4D56B67B2A4C4478 (paiement_id), INDEX idx_mouvement_credit_ia_etat (etat), UNIQUE INDEX uniq_mouvement_credit_ia_cle (cle), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_conception (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(30) NOT NULL, nom VARCHAR(80) NOT NULL, court VARCHAR(30) NOT NULL, format VARCHAR(60) NOT NULL, largeur_cm DOUBLE PRECISION DEFAULT NULL, hauteur_cm DOUBLE PRECISION DEFAULT NULL, forfait INT NOT NULL, acompte_pourcent INT NOT NULL, delai_bat_heures INT NOT NULL, corrections_incluses INT NOT NULL, couleur VARCHAR(7) NOT NULL, actif TINYINT NOT NULL, ordre INT NOT NULL, public_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_4DD43BD6B5B48B91 (public_id), UNIQUE INDEX uniq_support_conception_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE support_conception_configuration (support_conception_id INT NOT NULL, produit_configuration_id INT NOT NULL, INDEX IDX_F4DE82C75249750A (support_conception_id), INDEX IDX_F4DE82C7806A5250 (produit_configuration_id), PRIMARY KEY (support_conception_id, produit_configuration_id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE credit_ia ADD CONSTRAINT FK_BE6CDB7619EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE demande_conception ADD CONSTRAINT FK_99ACF55519EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE demande_conception ADD CONSTRAINT FK_99ACF555F2C56620 FOREIGN KEY (compte_id) REFERENCES compte_client (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_conception ADD CONSTRAINT FK_99ACF555315B405 FOREIGN KEY (support_id) REFERENCES support_conception (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_conception ADD CONSTRAINT FK_99ACF55582EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_conception ADD CONSTRAINT FK_99ACF5555FAC1B46 FOREIGN KEY (paiement_acompte_id) REFERENCES paiements (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_conception ADD CONSTRAINT FK_99ACF555C9B629EE FOREIGN KEY (image_choisie_id) REFERENCES commande_detail_fichier (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE demande_conception_element ADD CONSTRAINT FK_AC40C36CBA9DD3C9 FOREIGN KEY (demande_conception_id) REFERENCES demande_conception (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE demande_conception_element ADD CONSTRAINT FK_AC40C36C4FB4C8B8 FOREIGN KEY (commande_detail_fichier_id) REFERENCES commande_detail_fichier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE image_ia ADD CONSTRAINT FK_4DCFD1E280E95E18 FOREIGN KEY (demande_id) REFERENCES demande_conception (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE image_ia ADD CONSTRAINT FK_4DCFD1E219EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mouvement_credit_ia ADD CONSTRAINT FK_4D56B67B19EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE mouvement_credit_ia ADD CONSTRAINT FK_4D56B67B82EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE mouvement_credit_ia ADD CONSTRAINT FK_4D56B67B2A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiements (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE support_conception_configuration ADD CONSTRAINT FK_F4DE82C75249750A FOREIGN KEY (support_conception_id) REFERENCES support_conception (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE support_conception_configuration ADD CONSTRAINT FK_F4DE82C7806A5250 FOREIGN KEY (produit_configuration_id) REFERENCES produit_configuration (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction_orange_money ADD objet VARCHAR(20) DEFAULT \'commande\' NOT NULL, ADD pack_images INT DEFAULT NULL, ADD demande_conception_id INT DEFAULT NULL, ADD client_id INT DEFAULT NULL, CHANGE commande_id commande_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE transaction_orange_money ADD CONSTRAINT FK_E73FAA07BA9DD3C9 FOREIGN KEY (demande_conception_id) REFERENCES demande_conception (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transaction_orange_money ADD CONSTRAINT FK_E73FAA0719EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_E73FAA07BA9DD3C9 ON transaction_orange_money (demande_conception_id)');
        $this->addSql('CREATE INDEX IDX_E73FAA0719EB6921 ON transaction_orange_money (client_id)');

        // Les 9 supports de la maquette DC01, avec le même identifiant public au local et en ligne.
        $this->addSql("INSERT INTO support_conception (public_id, code, nom, court, format, largeur_cm, hauteur_cm, forfait, acompte_pourcent, delai_bat_heures, corrections_incluses, couleur, actif, ordre) VALUES "
            .implode(', ', [
                "(UNHEX(REPLACE('019a2a00-dc00-7000-8000-000000000001', '-', '')), 'kakemono', 'Kakémono / roll-up', 'Kakémono', '85 × 200 cm', 85, 200, 10000, 50, 24, 2, '#E0102B', 1, 1)",
                "(UNHEX(REPLACE('019a2a00-dc00-7000-8000-000000000002', '-', '')), 'bache', 'Bâche', 'Bâche', 'sur mesure', 300, 100, 15000, 50, 24, 2, '#2F5DA8', 1, 2)",
                "(UNHEX(REPLACE('019a2a00-dc00-7000-8000-000000000003', '-', '')), 'flyer', 'Flyer', 'Flyer', 'A5 · A4', 14.8, 21, 10000, 50, 24, 2, '#2A9D8F', 1, 3)",
                "(UNHEX(REPLACE('019a2a00-dc00-7000-8000-000000000004', '-', '')), 'carte_visite', 'Carte de visite', 'Carte', '85 × 55 mm', 8.5, 5.5, 5000, 50, 24, 2, '#333333', 1, 4)",
                "(UNHEX(REPLACE('019a2a00-dc00-7000-8000-000000000005', '-', '')), 'affiche', 'Affiche', 'Affiche', 'A3 · A2', 29.7, 42, 10000, 50, 24, 2, '#F26B3A', 1, 5)",
                "(UNHEX(REPLACE('019a2a00-dc00-7000-8000-000000000006', '-', '')), 'textile', 'T-shirt / textile', 'T-shirt', 'DTF · sublimation', 30, 40, 7500, 50, 24, 2, '#EC4899', 1, 6)",
                "(UNHEX(REPLACE('019a2a00-dc00-7000-8000-000000000007', '-', '')), 'enseigne', 'Enseigne', 'Enseigne', 'façade · vitrine', 300, 80, 20000, 50, 24, 2, '#3B9DDB', 1, 7)",
                "(UNHEX(REPLACE('019a2a00-dc00-7000-8000-000000000008', '-', '')), 'autocollant', 'Autocollant / vitrophanie', 'Autocollant', 'sur mesure', 50, 50, 10000, 50, 24, 2, '#9CA3AF', 1, 8)",
                "(UNHEX(REPLACE('019a2a00-dc00-7000-8000-000000000009', '-', '')), 'logo', 'Logo', 'Logo', 'création d\'identité', 30, 30, 50000, 50, 24, 2, '#D4A017', 1, 9)",
            ]));
    }

    public function down(Schema $schema): void
    {
        // D'abord ce qui pointe vers les tables retirées ; les paiements d'acompte ou de pack n'ont pas de commande avant le paiement.
        $this->addSql('ALTER TABLE transaction_orange_money DROP FOREIGN KEY FK_E73FAA07BA9DD3C9');
        $this->addSql('ALTER TABLE transaction_orange_money DROP FOREIGN KEY FK_E73FAA0719EB6921');
        $this->addSql('DROP INDEX IDX_E73FAA07BA9DD3C9 ON transaction_orange_money');
        $this->addSql('DROP INDEX IDX_E73FAA0719EB6921 ON transaction_orange_money');
        $this->addSql('DELETE FROM transaction_orange_money WHERE commande_id IS NULL');
        $this->addSql('ALTER TABLE transaction_orange_money DROP objet, DROP pack_images, DROP demande_conception_id, DROP client_id, CHANGE commande_id commande_id INT NOT NULL');
        $this->addSql('ALTER TABLE credit_ia DROP FOREIGN KEY FK_BE6CDB7619EB6921');
        $this->addSql('ALTER TABLE demande_conception DROP FOREIGN KEY FK_99ACF55519EB6921');
        $this->addSql('ALTER TABLE demande_conception DROP FOREIGN KEY FK_99ACF555F2C56620');
        $this->addSql('ALTER TABLE demande_conception DROP FOREIGN KEY FK_99ACF555315B405');
        $this->addSql('ALTER TABLE demande_conception DROP FOREIGN KEY FK_99ACF55582EA2E54');
        $this->addSql('ALTER TABLE demande_conception DROP FOREIGN KEY FK_99ACF5555FAC1B46');
        $this->addSql('ALTER TABLE demande_conception DROP FOREIGN KEY FK_99ACF555C9B629EE');
        $this->addSql('ALTER TABLE demande_conception_element DROP FOREIGN KEY FK_AC40C36CBA9DD3C9');
        $this->addSql('ALTER TABLE demande_conception_element DROP FOREIGN KEY FK_AC40C36C4FB4C8B8');
        $this->addSql('ALTER TABLE image_ia DROP FOREIGN KEY FK_4DCFD1E280E95E18');
        $this->addSql('ALTER TABLE image_ia DROP FOREIGN KEY FK_4DCFD1E219EB6921');
        $this->addSql('ALTER TABLE mouvement_credit_ia DROP FOREIGN KEY FK_4D56B67B19EB6921');
        $this->addSql('ALTER TABLE mouvement_credit_ia DROP FOREIGN KEY FK_4D56B67B82EA2E54');
        $this->addSql('ALTER TABLE mouvement_credit_ia DROP FOREIGN KEY FK_4D56B67B2A4C4478');
        $this->addSql('ALTER TABLE support_conception_configuration DROP FOREIGN KEY FK_F4DE82C75249750A');
        $this->addSql('ALTER TABLE support_conception_configuration DROP FOREIGN KEY FK_F4DE82C7806A5250');
        $this->addSql('DROP TABLE compteur_ia_jour');
        $this->addSql('DROP TABLE credit_ia');
        $this->addSql('DROP TABLE demande_conception');
        $this->addSql('DROP TABLE demande_conception_element');
        $this->addSql('DROP TABLE image_ia');
        $this->addSql('DROP TABLE mouvement_credit_ia');
        $this->addSql('DROP TABLE support_conception');
        $this->addSql('DROP TABLE support_conception_configuration');
    }
}
