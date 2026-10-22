<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261010202721 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Migration de base : schema complet au 10 octobre 2026. Remplace les migrations '
            .'precedentes. En production, ne pas l executer : la marquer comme deja faite selon '
            .'la procedure de docs/migration-de-base.md.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE achat_detail (
              id INT AUTO_INCREMENT NOT NULL,
              quantite INT NOT NULL,
              prix_unitaire INT NOT NULL,
              total_ht INT DEFAULT 0 NOT NULL,
              achat_id INT NOT NULL,
              article_id INT NOT NULL,
              INDEX IDX_EA7F1F56FE95D117 (achat_id),
              INDEX IDX_EA7F1F567294869C (article_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE achats (
              id INT AUTO_INCREMENT NOT NULL,
              numero VARCHAR(50) DEFAULT NULL,
              statut VARCHAR(20) NOT NULL,
              date_demande DATETIME NOT NULL,
              date_reception DATETIME DEFAULT NULL,
              observation LONGTEXT DEFAULT NULL,
              total_ht INT DEFAULT 0 NOT NULL,
              tva INT DEFAULT 0 NOT NULL,
              total_ttc INT DEFAULT 0 NOT NULL,
              fournisseur_id INT NOT NULL,
              UNIQUE INDEX UNIQ_9920924EF55AE19E (numero),
              INDEX IDX_9920924E670C757F (fournisseur_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE articles (
              id INT AUTO_INCREMENT NOT NULL,
              reference VARCHAR(100) NOT NULL,
              designation VARCHAR(255) NOT NULL,
              categorie VARCHAR(100) DEFAULT NULL,
              unite VARCHAR(30) NOT NULL,
              stock NUMERIC(14, 3) DEFAULT '0.000' NOT NULL,
              stock_min NUMERIC(14, 3) DEFAULT '0.000' NOT NULL,
              prix_achat INT DEFAULT NULL,
              prix_vente INT DEFAULT NULL,
              description LONGTEXT DEFAULT NULL,
              actif TINYINT DEFAULT 1 NOT NULL,
              vendable TINYINT DEFAULT 1 NOT NULL,
              consommable_production TINYINT DEFAULT 0 NOT NULL,
              fournisseur_id INT DEFAULT NULL,
              INDEX IDX_BFDD3168670C757F (fournisseur_id),
              UNIQUE INDEX uniq_article_reference (reference),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE bon_livraison (
              id INT AUTO_INCREMENT NOT NULL,
              numero VARCHAR(50) NOT NULL,
              cree_le DATETIME NOT NULL,
              valide_le DATETIME DEFAULT NULL,
              livre_le DATETIME DEFAULT NULL,
              statut VARCHAR(30) NOT NULL,
              nom_receptionnaire VARCHAR(255) DEFAULT NULL,
              telephone_receptionnaire VARCHAR(50) DEFAULT NULL,
              adresse_livraison VARCHAR(255) DEFAULT NULL,
              observation LONGTEXT DEFAULT NULL,
              gestion_stock TINYINT DEFAULT 0 NOT NULL,
              coefficient_stock NUMERIC(10, 3) DEFAULT '1.000' NOT NULL,
              commande_id INT NOT NULL,
              cree_par_id INT DEFAULT NULL,
              valide_par_id INT DEFAULT NULL,
              livre_par_id INT DEFAULT NULL,
              article_stock_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_31A531A4F55AE19E (numero),
              INDEX IDX_31A531A482EA2E54 (commande_id),
              INDEX IDX_31A531A4FC29C013 (cree_par_id),
              INDEX IDX_31A531A46AF12ED9 (valide_par_id),
              INDEX IDX_31A531A4E2C6415F (livre_par_id),
              INDEX IDX_31A531A45825957B (article_stock_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE bon_livraison_ligne (
              id INT AUTO_INCREMENT NOT NULL,
              designation VARCHAR(255) NOT NULL,
              quantite_commandee INT NOT NULL,
              quantite_livree INT NOT NULL,
              unite VARCHAR(50) DEFAULT NULL,
              observation VARCHAR(255) DEFAULT NULL,
              bon_livraison_id INT NOT NULL,
              commande_detail_id INT NOT NULL,
              INDEX IDX_9C189341D8D16068 (bon_livraison_id),
              INDEX IDX_9C189341C8DC59F9 (commande_detail_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE campagne (
              id INT AUTO_INCREMENT NOT NULL,
              nom VARCHAR(255) NOT NULL,
              statut VARCHAR(20) NOT NULL,
              sender_name VARCHAR(20) DEFAULT NULL,
              nombre_total INT NOT NULL,
              date_creation DATETIME NOT NULL,
              modele_message_id INT NOT NULL,
              cree_par_id INT DEFAULT NULL,
              INDEX IDX_539B5D16A02D04D8 (modele_message_id),
              INDEX IDX_539B5D16FC29C013 (cree_par_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE campagne_destinataire (
              id INT AUTO_INCREMENT NOT NULL,
              statut VARCHAR(20) NOT NULL,
              erreur LONGTEXT DEFAULT NULL,
              date_envoi DATETIME DEFAULT NULL,
              campagne_id INT NOT NULL,
              client_id INT DEFAULT NULL,
              INDEX IDX_BAD1F48516227374 (campagne_id),
              INDEX IDX_BAD1F48519EB6921 (client_id),
              INDEX idx_campagne_destinataire_statut (statut),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE categorie_produit (
              id INT AUTO_INCREMENT NOT NULL,
              nom VARCHAR(255) NOT NULL,
              description VARCHAR(500) NOT NULL,
              publie TINYINT NOT NULL,
              ordre INT NOT NULL,
              UNIQUE INDEX UNIQ_762642856C6E55B5 (nom),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE clients (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(50) NOT NULL,
              raison_sociale VARCHAR(100) DEFAULT NULL,
              nom VARCHAR(50) NOT NULL,
              prenom VARCHAR(50) NOT NULL,
              telephone VARCHAR(30) NOT NULL,
              telephone2 VARCHAR(30) DEFAULT NULL,
              email VARCHAR(255) DEFAULT NULL,
              adresse VARCHAR(255) DEFAULT NULL,
              ville VARCHAR(100) DEFAULT NULL,
              nif VARCHAR(30) DEFAULT NULL,
              rccm VARCHAR(50) DEFAULT NULL,
              type_client VARCHAR(10) DEFAULT 'B2C' NOT NULL,
              type_compte VARCHAR(20) DEFAULT 'particulier' NOT NULL,
              plafond_credit INT DEFAULT NULL,
              solde_credit INT DEFAULT 0 NOT NULL,
              observation LONGTEXT DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME NOT NULL,
              statut TINYINT NOT NULL,
              recevoir_sms TINYINT DEFAULT 1 NOT NULL,
              public_id BINARY(16) NOT NULL,
              UNIQUE INDEX UNIQ_C82E7477153098 (code),
              UNIQUE INDEX UNIQ_C82E74450FF010 (telephone),
              UNIQUE INDEX UNIQ_C82E74B5B48B91 (public_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE commande_detail_fichier (
              id INT AUTO_INCREMENT NOT NULL,
              jeton_upload VARCHAR(64) NOT NULL,
              morceaux_recus INT DEFAULT 0 NOT NULL,
              nombre_morceaux INT DEFAULT NULL,
              termine_le DATETIME DEFAULT NULL,
              nom_original VARCHAR(255) NOT NULL,
              nom_stockage VARCHAR(255) NOT NULL,
              chemin VARCHAR(500) DEFAULT NULL,
              type_mime VARCHAR(100) DEFAULT NULL,
              taille INT DEFAULT NULL,
              statut VARCHAR(30) NOT NULL,
              origine VARCHAR(30) NOT NULL,
              etat VARCHAR(30) NOT NULL,
              deja_traite TINYINT DEFAULT 0 NOT NULL,
              fichier_production TINYINT DEFAULT 0 NOT NULL,
              actif TINYINT DEFAULT 1 NOT NULL,
              version INT DEFAULT 1 NOT NULL,
              quantite_aproduire INT DEFAULT NULL,
              face VARCHAR(30) DEFAULT NULL,
              designation VARCHAR(255) DEFAULT NULL,
              groupe_fichier VARCHAR(100) DEFAULT NULL,
              observation LONGTEXT DEFAULT NULL,
              cree_le DATETIME NOT NULL,
              commande_detail_id INT DEFAULT NULL,
              fichier_source_id INT DEFAULT NULL,
              ajoute_par_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_39E2DA1052C87F80 (jeton_upload),
              INDEX IDX_39E2DA10C8DC59F9 (commande_detail_id),
              INDEX IDX_39E2DA10B130D9F1 (fichier_source_id),
              INDEX IDX_39E2DA10DAA76F43 (ajoute_par_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE commande_detail_finition (
              id INT AUTO_INCREMENT NOT NULL,
              nom_finition VARCHAR(255) NOT NULL,
              obligatoire TINYINT DEFAULT 0 NOT NULL,
              prix_applique INT NOT NULL,
              mode_calcul VARCHAR(30) NOT NULL,
              quantite INT NOT NULL,
              montant INT NOT NULL,
              commande_detail_id INT NOT NULL,
              configuration_finition_id INT DEFAULT NULL,
              finition_id INT NOT NULL,
              INDEX IDX_B07D7138C8DC59F9 (commande_detail_id),
              INDEX IDX_B07D7138A0C197D7 (configuration_finition_id),
              INDEX IDX_B07D7138CB56F5AF (finition_id),
              UNIQUE INDEX uniq_commande_detail_finition (commande_detail_id, finition_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE commandes (
              id INT AUTO_INCREMENT NOT NULL,
              numero VARCHAR(50) DEFAULT NULL,
              date_commande DATETIME NOT NULL,
              date_livraison DATETIME DEFAULT NULL,
              recupere_par_client TINYINT DEFAULT 0 NOT NULL,
              modifie_le DATETIME DEFAULT NULL,
              remise INT DEFAULT 0 NOT NULL,
              tva INT DEFAULT 0 NOT NULL,
              total_ht INT DEFAULT 0 NOT NULL,
              total_ttc INT DEFAULT 0 NOT NULL,
              montant_apayer INT DEFAULT 0 NOT NULL,
              observation LONGTEXT DEFAULT NULL,
              deleted TINYINT DEFAULT 0 NOT NULL,
              statut TINYINT DEFAULT 1 NOT NULL,
              etat TINYINT DEFAULT 1 NOT NULL,
              statut_paiement VARCHAR(20) DEFAULT 'impayee' NOT NULL,
              reste_apayer INT NOT NULL,
              total_paye INT NOT NULL,
              public_id BINARY(16) NOT NULL,
              bonus_plafond_applique TINYINT DEFAULT 0 NOT NULL,
              montant_bonus_plafond INT DEFAULT 0 NOT NULL,
              clients_id INT NOT NULL,
              agents_id INT NOT NULL,
              modifie_par_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_35D4282CF55AE19E (numero),
              UNIQUE INDEX UNIQ_35D4282CB5B48B91 (public_id),
              INDEX IDX_35D4282CAB014612 (clients_id),
              INDEX IDX_35D4282C709770DC (agents_id),
              INDEX IDX_35D4282C553B2554 (modifie_par_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE commandes_details (
              id INT AUTO_INCREMENT NOT NULL,
              mode_configuration VARCHAR(20) DEFAULT 'automatique' NOT NULL,
              designation VARCHAR(255) NOT NULL,
              largeur NUMERIC(12, 4) DEFAULT NULL,
              longueur NUMERIC(12, 4) DEFAULT NULL,
              surface NUMERIC(12, 4) DEFAULT NULL,
              quantite INT DEFAULT 1 NOT NULL,
              prix_unitaire NUMERIC(15, 4) DEFAULT '0.0000' NOT NULL,
              cout_revient INT DEFAULT 0 NOT NULL,
              remise INT DEFAULT 0 NOT NULL,
              tva INT DEFAULT 0 NOT NULL,
              total_ht INT DEFAULT 0 NOT NULL,
              total_ttc INT DEFAULT 0 NOT NULL,
              profil_couleurs VARCHAR(20) DEFAULT NULL,
              resolution VARCHAR(50) DEFAULT NULL,
              grammage VARCHAR(100) DEFAULT NULL,
              epaisseur VARCHAR(50) DEFAULT NULL,
              recto_verso TINYINT DEFAULT 0 NOT NULL,
              nombre_faces INT DEFAULT 1 NOT NULL,
              laminage VARCHAR(255) DEFAULT NULL,
              oeillets TINYINT DEFAULT 0 NOT NULL,
              decoupe TINYINT DEFAULT 0 NOT NULL,
              pliage VARCHAR(100) DEFAULT NULL,
              emballage TINYINT DEFAULT 0 NOT NULL,
              bat_valide TINYINT DEFAULT 0 NOT NULL,
              etat TINYINT DEFAULT 1 NOT NULL,
              priorite VARCHAR(30) DEFAULT 'normale' NOT NULL,
              temps_estime INT DEFAULT NULL,
              temps_reel INT DEFAULT NULL,
              fichier VARCHAR(255) DEFAULT NULL,
              observation LONGTEXT DEFAULT NULL,
              statut_production VARCHAR(30) DEFAULT 'a_produire' NOT NULL,
              statut_production_avant_annulation VARCHAR(30) DEFAULT NULL,
              production_debute_le DATETIME DEFAULT NULL,
              production_terminee_le DATETIME DEFAULT NULL,
              quantite_produite INT DEFAULT 0 NOT NULL,
              quantite_rebut INT DEFAULT 0 NOT NULL,
              observation_production LONGTEXT DEFAULT NULL,
              quantite_livree INT DEFAULT 0 NOT NULL,
              mode_saisie VARCHAR(30) DEFAULT 'automatique' NOT NULL,
              mode_calcul VARCHAR(30) DEFAULT 'unite' NOT NULL,
              pre_presse_necessaire TINYINT DEFAULT 1 NOT NULL,
              production_necessaire TINYINT DEFAULT 1 NOT NULL,
              type_ligne VARCHAR(20) DEFAULT 'produit' NOT NULL,
              commande_id INT NOT NULL,
              produit_id INT DEFAULT NULL,
              produit_configuration_id INT DEFAULT NULL,
              type_impression_id INT DEFAULT NULL,
              support_id INT DEFAULT NULL,
              machine_id INT DEFAULT NULL,
              format_id INT DEFAULT NULL,
              production_debutee_par_id INT DEFAULT NULL,
              production_terminee_par_id INT DEFAULT NULL,
              article_id INT DEFAULT NULL,
              INDEX IDX_B48B83DA82EA2E54 (commande_id),
              INDEX IDX_B48B83DAF347EFB (produit_id),
              INDEX IDX_B48B83DA806A5250 (produit_configuration_id),
              INDEX IDX_B48B83DAD357F183 (type_impression_id),
              INDEX IDX_B48B83DA315B405 (support_id),
              INDEX IDX_B48B83DAF6B75B26 (machine_id),
              INDEX IDX_B48B83DAD629F605 (format_id),
              INDEX IDX_B48B83DAE87137C3 (production_debutee_par_id),
              INDEX IDX_B48B83DAF889D27D (production_terminee_par_id),
              INDEX IDX_B48B83DA7294869C (article_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE compte_tresorerie (
              id INT AUTO_INCREMENT NOT NULL,
              code VARCHAR(50) NOT NULL,
              nom VARCHAR(150) NOT NULL,
              type VARCHAR(30) NOT NULL,
              portee VARCHAR(20) DEFAULT 'admin' NOT NULL,
              nom_banque VARCHAR(150) DEFAULT NULL,
              numero_compte VARCHAR(100) DEFAULT NULL,
              titulaire_compte VARCHAR(150) DEFAULT NULL,
              solde_initial INT DEFAULT 0 NOT NULL,
              solde_actuel INT DEFAULT 0 NOT NULL,
              devise VARCHAR(10) DEFAULT 'XOF' NOT NULL,
              autoriser_decouvert TINYINT DEFAULT 0 NOT NULL,
              actif TINYINT DEFAULT 1 NOT NULL,
              description LONGTEXT DEFAULT NULL,
              date_creation DATETIME NOT NULL,
              date_modification DATETIME DEFAULT NULL,
              proprietaire_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_E738ED0677153098 (code),
              INDEX idx_compte_tresorerie_type (type),
              INDEX idx_compte_tresorerie_portee (portee),
              INDEX idx_compte_tresorerie_proprietaire (proprietaire_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE consommation_encres (
              id INT AUTO_INCREMENT NOT NULL,
              encre INT DEFAULT 0 NOT NULL,
              quantite INT DEFAULT 0 NOT NULL,
              cout INT DEFAULT 0 NOT NULL,
              production_id INT NOT NULL,
              INDEX IDX_31C495E1ECC6147F (production_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE controle_pre_presse (
              id INT AUTO_INCREMENT NOT NULL,
              statut VARCHAR(30) NOT NULL,
              format_conforme TINYINT DEFAULT 0 NOT NULL,
              dimensions_conformes TINYINT DEFAULT 0 NOT NULL,
              resolution_conforme TINYINT DEFAULT 0 NOT NULL,
              profil_couleurs_conforme TINYINT DEFAULT 0 NOT NULL,
              fonds_perdus_conformes TINYINT DEFAULT 0 NOT NULL,
              marges_securite_conformes TINYINT DEFAULT 0 NOT NULL,
              polices_conformes TINYINT DEFAULT 0 NOT NULL,
              orthographe_verifiee TINYINT DEFAULT 0 NOT NULL,
              orientation_conforme TINYINT DEFAULT 0 NOT NULL,
              nombre_pages_conforme TINYINT DEFAULT 0 NOT NULL,
              recto_verso_conforme TINYINT DEFAULT 0 NOT NULL,
              support_conforme TINYINT DEFAULT 0 NOT NULL,
              quantite_conforme TINYINT DEFAULT 0 NOT NULL,
              fichier_deja_traite TINYINT DEFAULT 0 NOT NULL,
              correction_necessaire TINYINT DEFAULT 0 NOT NULL,
              bat_necessaire TINYINT DEFAULT 0 NOT NULL,
              bat_valide TINYINT DEFAULT 0 NOT NULL,
              anomalies LONGTEXT DEFAULT NULL,
              corrections_effectuees LONGTEXT DEFAULT NULL,
              observation LONGTEXT DEFAULT NULL,
              pre_presse_necessaire TINYINT DEFAULT 1 NOT NULL,
              cree_le DATETIME NOT NULL,
              controle_le DATETIME DEFAULT NULL,
              traite_le DATETIME DEFAULT NULL,
              valide_le DATETIME DEFAULT NULL,
              envoye_production_le DATETIME DEFAULT NULL,
              commande_detail_id INT NOT NULL,
              controle_par_id INT DEFAULT NULL,
              traite_par_id INT DEFAULT NULL,
              valide_par_id INT DEFAULT NULL,
              envoye_production_par_id INT DEFAULT NULL,
              INDEX IDX_FA26187C8DC59F9 (commande_detail_id),
              INDEX IDX_FA26187BA5AD30 (controle_par_id),
              INDEX IDX_FA26187167FABE8 (traite_par_id),
              INDEX IDX_FA261876AF12ED9 (valide_par_id),
              INDEX IDX_FA261878FBD9DB1 (envoye_production_par_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE controle_pre_presse_fichier (
              controle_pre_presse_id INT NOT NULL,
              fichier_id INT NOT NULL,
              INDEX IDX_4D8558C4C6055018 (controle_pre_presse_id),
              INDEX IDX_4D8558C4F915CFE (fichier_id),
              PRIMARY KEY (
                controle_pre_presse_id, fichier_id
              )
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE conversation_participants (
              id INT AUTO_INCREMENT NOT NULL,
              date_derniere_lecture DATETIME DEFAULT NULL,
              conversation_id INT NOT NULL,
              utilisateur_id INT NOT NULL,
              INDEX IDX_21821ED39AC0396 (conversation_id),
              INDEX IDX_21821ED3FB88E14F (utilisateur_id),
              UNIQUE INDEX uniq_conversation_utilisateur (conversation_id, utilisateur_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE conversations (
              id INT AUTO_INCREMENT NOT NULL,
              date_creation DATETIME NOT NULL,
              date_dernier_message DATETIME DEFAULT NULL,
              jeton VARCHAR(32) NOT NULL,
              UNIQUE INDEX UNIQ_C2521BF12CF647B (jeton),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE decaissement_recurrent (
              id INT AUTO_INCREMENT NOT NULL,
              libelle VARCHAR(255) NOT NULL,
              categorie VARCHAR(30) NOT NULL,
              montant INT NOT NULL,
              frequence VARCHAR(20) NOT NULL,
              jour_du_mois INT NOT NULL,
              mois_de_lannee INT DEFAULT NULL,
              actif TINYINT NOT NULL,
              date_debut DATE NOT NULL,
              prochaine_date_execution DATE NOT NULL,
              derniere_date_execution DATE DEFAULT NULL,
              date_creation DATETIME NOT NULL,
              notes LONGTEXT DEFAULT NULL,
              compte_source_id INT NOT NULL,
              cree_par_id INT DEFAULT NULL,
              INDEX IDX_23DC19F656B22253 (compte_source_id),
              INDEX IDX_23DC19F6FC29C013 (cree_par_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE devis (
              id INT AUTO_INCREMENT NOT NULL,
              numero VARCHAR(50) DEFAULT NULL,
              emetteur VARCHAR(30) DEFAULT 'mdg_success' NOT NULL,
              date_devis DATETIME NOT NULL,
              remise INT DEFAULT 0 NOT NULL,
              tva INT DEFAULT 0 NOT NULL,
              total_ht INT DEFAULT 0 NOT NULL,
              total_ttc INT DEFAULT 0 NOT NULL,
              montant_apayer INT DEFAULT 0 NOT NULL,
              observation LONGTEXT DEFAULT NULL,
              deleted TINYINT DEFAULT 0 NOT NULL,
              statut VARCHAR(30) NOT NULL,
              etat TINYINT DEFAULT 1 NOT NULL,
              token_authenticite VARCHAR(64) DEFAULT NULL,
              facturer_aun_tiers TINYINT DEFAULT 0 NOT NULL,
              nom_facturation VARCHAR(150) DEFAULT NULL,
              adresse_facturation VARCHAR(255) DEFAULT NULL,
              telephone_facturation VARCHAR(30) DEFAULT NULL,
              email_facturation VARCHAR(255) DEFAULT NULL,
              clients_id INT NOT NULL,
              agents_id INT NOT NULL,
              commande_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_8B27C52BF55AE19E (numero),
              UNIQUE INDEX UNIQ_8B27C52B10B51704 (token_authenticite),
              INDEX IDX_8B27C52BAB014612 (clients_id),
              INDEX IDX_8B27C52B709770DC (agents_id),
              UNIQUE INDEX UNIQ_8B27C52B82EA2E54 (commande_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE devis_detail_finition (
              id INT AUTO_INCREMENT NOT NULL,
              nom_finition VARCHAR(255) NOT NULL,
              obligatoire TINYINT DEFAULT 0 NOT NULL,
              prix_applique INT NOT NULL,
              mode_calcul VARCHAR(30) NOT NULL,
              quantite INT NOT NULL,
              montant INT NOT NULL,
              devis_detail_id INT NOT NULL,
              configuration_finition_id INT DEFAULT NULL,
              finition_id INT NOT NULL,
              INDEX IDX_BE70DB30A903C9C1 (devis_detail_id),
              INDEX IDX_BE70DB30A0C197D7 (configuration_finition_id),
              INDEX IDX_BE70DB30CB56F5AF (finition_id),
              UNIQUE INDEX uniq_devis_detail_configuration_finition (
                devis_detail_id, configuration_finition_id
              ),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE devis_details (
              id INT AUTO_INCREMENT NOT NULL,
              mode_configuration VARCHAR(20) DEFAULT 'automatique' NOT NULL,
              designation VARCHAR(255) NOT NULL,
              largeur NUMERIC(12, 4) DEFAULT NULL,
              longueur NUMERIC(12, 4) DEFAULT NULL,
              surface NUMERIC(12, 4) DEFAULT NULL,
              quantite INT DEFAULT 1 NOT NULL,
              prix_unitaire NUMERIC(15, 4) DEFAULT '0.0000' NOT NULL,
              cout_revient INT DEFAULT 0 NOT NULL,
              remise INT DEFAULT 0 NOT NULL,
              tva INT DEFAULT 0 NOT NULL,
              total_ht INT DEFAULT 0 NOT NULL,
              total_ttc INT DEFAULT 0 NOT NULL,
              profil_couleurs VARCHAR(20) DEFAULT NULL,
              resolution VARCHAR(50) DEFAULT NULL,
              grammage VARCHAR(100) DEFAULT NULL,
              epaisseur VARCHAR(50) DEFAULT NULL,
              recto_verso TINYINT DEFAULT 0 NOT NULL,
              nombre_faces INT DEFAULT 1 NOT NULL,
              laminage VARCHAR(255) DEFAULT NULL,
              oeillets TINYINT DEFAULT 0 NOT NULL,
              decoupe TINYINT DEFAULT 0 NOT NULL,
              pliage VARCHAR(100) DEFAULT NULL,
              emballage TINYINT DEFAULT 0 NOT NULL,
              type_ligne VARCHAR(20) DEFAULT 'produit' NOT NULL,
              mode_saisie VARCHAR(30) DEFAULT 'automatique' NOT NULL,
              bat_valide TINYINT DEFAULT 0 NOT NULL,
              etat TINYINT DEFAULT 1 NOT NULL,
              priorite VARCHAR(30) DEFAULT 'normale' NOT NULL,
              temps_estime INT DEFAULT NULL,
              temps_reel INT DEFAULT NULL,
              fichier VARCHAR(255) DEFAULT NULL,
              observation LONGTEXT DEFAULT NULL,
              mode_calcul VARCHAR(30) DEFAULT 'unite' NOT NULL,
              devis_id INT NOT NULL,
              produit_id INT DEFAULT NULL,
              produit_configuration_id INT DEFAULT NULL,
              type_impression_id INT DEFAULT NULL,
              support_id INT DEFAULT NULL,
              format_id INT DEFAULT NULL,
              article_id INT DEFAULT NULL,
              INDEX IDX_E0C890D641DEFADA (devis_id),
              INDEX IDX_E0C890D6F347EFB (produit_id),
              INDEX IDX_E0C890D6806A5250 (produit_configuration_id),
              INDEX IDX_E0C890D6D357F183 (type_impression_id),
              INDEX IDX_E0C890D6315B405 (support_id),
              INDEX IDX_E0C890D6D629F605 (format_id),
              INDEX IDX_E0C890D67294869C (article_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE employes (
              id INT AUTO_INCREMENT NOT NULL,
              nom VARCHAR(50) NOT NULL,
              prenom VARCHAR(50) NOT NULL,
              fonction VARCHAR(255) NOT NULL,
              telephone VARCHAR(50) NOT NULL,
              email VARCHAR(255) NOT NULL,
              photos VARCHAR(255) NOT NULL,
              cin VARCHAR(255) NOT NULL,
              salaires INT NOT NULL,
              date_naissances DATE NOT NULL,
              matricules VARCHAR(100) NOT NULL,
              date_embauches DATE NOT NULL,
              adresses VARCHAR(255) NOT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE etiquette (
              id INT AUTO_INCREMENT NOT NULL,
              numero VARCHAR(50) NOT NULL,
              token VARCHAR(128) NOT NULL,
              fichier_qr VARCHAR(255) NOT NULL,
              statut VARCHAR(30) NOT NULL,
              cree_le DATETIME NOT NULL,
              imprime_le DATETIME DEFAULT NULL,
              associe_le DATETIME DEFAULT NULL,
              lot_id INT NOT NULL,
              commande_id INT DEFAULT NULL,
              commande_detail_id INT DEFAULT NULL,
              associe_par_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_1E0E195AF55AE19E (numero),
              UNIQUE INDEX UNIQ_1E0E195A5F37A13B (token),
              INDEX IDX_1E0E195AA8CBA5F7 (lot_id),
              INDEX IDX_1E0E195A276FAE2C (associe_par_id),
              INDEX idx_etiquette_statut (statut),
              INDEX idx_etiquette_commande (commande_id),
              INDEX idx_etiquette_commande_detail (commande_detail_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE factures (
              id INT AUTO_INCREMENT NOT NULL,
              numero VARCHAR(50) DEFAULT NULL,
              type_document VARCHAR(20) DEFAULT 'proforma' NOT NULL,
              comptabilisee TINYINT DEFAULT 0 NOT NULL,
              emetteur VARCHAR(30) DEFAULT 'mdg_success' NOT NULL,
              pdf_fichier VARCHAR(255) DEFAULT NULL,
              pdf_genere_le DATETIME DEFAULT NULL,
              pdf_hash VARCHAR(64) DEFAULT NULL,
              date_facture DATETIME NOT NULL,
              date_echeance DATE DEFAULT NULL,
              total_ht INT DEFAULT 0 NOT NULL,
              remise INT DEFAULT 0 NOT NULL,
              tva INT DEFAULT 0 NOT NULL,
              montant_tva INT DEFAULT 0 NOT NULL,
              surfacturation TINYINT DEFAULT 0 NOT NULL,
              taux_surfacturation NUMERIC(8, 2) DEFAULT NULL,
              montant_surfacturation INT DEFAULT 0 NOT NULL,
              motif_surfacturation LONGTEXT DEFAULT NULL,
              total_ttc INT DEFAULT 0 NOT NULL,
              montant_paye INT DEFAULT 0 NOT NULL,
              reste_apayer INT DEFAULT 0 NOT NULL,
              statut_paiement VARCHAR(20) DEFAULT 'impayee' NOT NULL,
              etat VARCHAR(20) DEFAULT 'brouillon' NOT NULL,
              observation LONGTEXT DEFAULT NULL,
              token_authenticite VARCHAR(64) DEFAULT NULL,
              facturer_aun_tiers TINYINT DEFAULT 0 NOT NULL,
              nom_facturation VARCHAR(150) DEFAULT NULL,
              adresse_facturation VARCHAR(255) DEFAULT NULL,
              telephone_facturation VARCHAR(30) DEFAULT NULL,
              email_facturation VARCHAR(255) DEFAULT NULL,
              created_at DATETIME NOT NULL,
              updated_at DATETIME DEFAULT NULL,
              commande_id INT NOT NULL,
              UNIQUE INDEX UNIQ_647590B10B51704 (token_authenticite),
              INDEX idx_facture_commande (commande_id),
              INDEX idx_facture_type_document (type_document),
              INDEX idx_facture_comptabilisee (comptabilisee),
              UNIQUE INDEX uniq_facture_numero (numero),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE finition (
              id INT AUTO_INCREMENT NOT NULL,
              nom VARCHAR(255) NOT NULL,
              publie TINYINT NOT NULL,
              ordre INT NOT NULL,
              description LONGTEXT NOT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE finition_types_impression (
              finition_id INT NOT NULL,
              types_impression_id INT NOT NULL,
              INDEX IDX_4EB60CFECB56F5AF (finition_id),
              INDEX IDX_4EB60CFE9719B876 (types_impression_id),
              PRIMARY KEY (
                finition_id, types_impression_id
              )
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE format (
              id INT AUTO_INCREMENT NOT NULL,
              nom VARCHAR(150) NOT NULL,
              largeur NUMERIC(10, 2) NOT NULL,
              hauteur NUMERIC(10, 2) NOT NULL,
              unite VARCHAR(10) NOT NULL,
              description LONGTEXT NOT NULL,
              publie TINYINT NOT NULL,
              ordre INT NOT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE format_types_impression (
              format_id INT NOT NULL,
              types_impression_id INT NOT NULL,
              INDEX IDX_5CE0BAD8D629F605 (format_id),
              INDEX IDX_5CE0BAD89719B876 (types_impression_id),
              PRIMARY KEY (format_id, types_impression_id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE fournisseurs (
              id INT AUTO_INCREMENT NOT NULL,
              nom VARCHAR(255) NOT NULL,
              telephone INT DEFAULT NULL,
              email VARCHAR(255) DEFAULT NULL,
              adresse LONGTEXT DEFAULT NULL,
              actif TINYINT DEFAULT 1 NOT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE journal_activite (
              id INT AUTO_INCREMENT NOT NULL,
              entite VARCHAR(100) NOT NULL,
              entite_id INT NOT NULL,
              action VARCHAR(20) NOT NULL,
              donnees_avant JSON DEFAULT NULL,
              donnees_apres JSON DEFAULT NULL,
              created_at DATETIME NOT NULL,
              utilisateur_id INT DEFAULT NULL,
              INDEX IDX_68C5F8ACFB88E14F (utilisateur_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ligne_rapprochement_bancaire (
              id INT AUTO_INCREMENT NOT NULL,
              date_releve DATE DEFAULT NULL,
              reference_bancaire VARCHAR(100) DEFAULT NULL,
              montant_releve INT NOT NULL,
              pointee TINYINT DEFAULT 0 NOT NULL,
              observation LONGTEXT DEFAULT NULL,
              date_creation DATETIME NOT NULL,
              rapprochement_bancaire_id INT NOT NULL,
              mouvement_tresorerie_id INT NOT NULL,
              INDEX IDX_83FBB11264AED8C9 (rapprochement_bancaire_id),
              INDEX IDX_83FBB112C1DCAF54 (mouvement_tresorerie_id),
              UNIQUE INDEX uniq_ligne_rapprochement_mouvement (
                rapprochement_bancaire_id, mouvement_tresorerie_id
              ),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE lot_etiquette (
              id INT AUTO_INCREMENT NOT NULL,
              numero VARCHAR(30) NOT NULL,
              quantite INT NOT NULL,
              prefixe VARCHAR(20) NOT NULL,
              dossier VARCHAR(255) NOT NULL,
              fichier_zip VARCHAR(255) DEFAULT NULL,
              statut VARCHAR(30) NOT NULL,
              cree_le DATETIME NOT NULL,
              termine_le DATETIME DEFAULT NULL,
              genere_par_id INT NOT NULL,
              UNIQUE INDEX UNIQ_3F18271EF55AE19E (numero),
              UNIQUE INDEX UNIQ_3F18271E3D48E037 (dossier),
              INDEX IDX_3F18271E9DEC084D (genere_par_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE machines (
              id INT AUTO_INCREMENT NOT NULL,
              nom VARCHAR(255) NOT NULL,
              marque VARCHAR(100) NOT NULL,
              modeles VARCHAR(100) NOT NULL,
              numero_serie VARCHAR(100) NOT NULL,
              type_machine VARCHAR(100) NOT NULL,
              largeur_impression VARCHAR(10) NOT NULL,
              nb_tetes INT NOT NULL,
              date_achat DATE NOT NULL,
              date_mise_service DATE NOT NULL,
              compteur_m2 DOUBLE PRECISION DEFAULT NULL,
              compteur_heures INT NOT NULL,
              compteur_feuilles INT DEFAULT 0 NOT NULL,
              etat VARCHAR(20) NOT NULL,
              numero_machine VARCHAR(50) DEFAULT NULL,
              adresse_ip VARCHAR(45) NOT NULL,
              mode_facturation VARCHAR(20) DEFAULT 'metre_carre' NOT NULL,
              prix_achat INT DEFAULT NULL,
              duree_amortissement_mois INT DEFAULT NULL,
              revenu_avant_suivi INT DEFAULT 0 NOT NULL,
              date_debut_suivi DATE DEFAULT NULL,
              UNIQUE INDEX UNIQ_F1CE8DEDD351C125 (numero_machine),
              UNIQUE INDEX UNIQ_F1CE8DED573D0821 (adresse_ip),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE maintenance (
              id INT AUTO_INCREMENT NOT NULL,
              date DATETIME NOT NULL,
              type VARCHAR(255) NOT NULL,
              description VARCHAR(255) NOT NULL,
              cout VARCHAR(100) NOT NULL,
              technicien VARCHAR(255) NOT NULL,
              machine_id INT DEFAULT NULL,
              INDEX IDX_2F84F8E9F6B75B26 (machine_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE messages_chat (
              id INT AUTO_INCREMENT NOT NULL,
              contenu LONGTEXT NOT NULL,
              date_envoi DATETIME NOT NULL,
              piece_jointe_fichier VARCHAR(255) DEFAULT NULL,
              piece_jointe_nom_original VARCHAR(255) DEFAULT NULL,
              conversation_id INT NOT NULL,
              auteur_id INT NOT NULL,
              INDEX IDX_B770B8179AC0396 (conversation_id),
              INDEX IDX_B770B81760BB6FE6 (auteur_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE modele_message (
              id INT AUTO_INCREMENT NOT NULL,
              nom VARCHAR(255) NOT NULL,
              canal VARCHAR(20) NOT NULL,
              sujet VARCHAR(255) DEFAULT NULL,
              contenu LONGTEXT NOT NULL,
              actif TINYINT NOT NULL,
              date_creation DATETIME NOT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE mouvement_tresorerie (
              id INT AUTO_INCREMENT NOT NULL,
              reference VARCHAR(50) NOT NULL,
              type VARCHAR(30) NOT NULL,
              categorie VARCHAR(50) DEFAULT 'ajustement' NOT NULL,
              impact_resultat TINYINT DEFAULT 0 NOT NULL,
              confidentiel TINYINT DEFAULT 0 NOT NULL,
              montant INT NOT NULL,
              devise VARCHAR(10) DEFAULT 'XOF' NOT NULL,
              mode_paiement VARCHAR(50) DEFAULT NULL,
              reference_externe VARCHAR(100) DEFAULT NULL,
              libelle VARCHAR(255) NOT NULL,
              description LONGTEXT DEFAULT NULL,
              statut VARCHAR(30) NOT NULL,
              date_operation DATETIME NOT NULL,
              date_creation DATETIME NOT NULL,
              date_validation DATETIME DEFAULT NULL,
              date_annulation DATETIME DEFAULT NULL,
              motif_annulation LONGTEXT DEFAULT NULL,
              verifie TINYINT DEFAULT 0 NOT NULL,
              date_verification DATETIME DEFAULT NULL,
              compte_source_id INT DEFAULT NULL,
              compte_destination_id INT DEFAULT NULL,
              verifie_par_id INT DEFAULT NULL,
              paiement_id INT DEFAULT NULL,
              agent_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_516E7468AEA34913 (reference),
              INDEX IDX_516E746856B22253 (compte_source_id),
              INDEX IDX_516E74684105B733 (compte_destination_id),
              INDEX IDX_516E7468A5CB0CEF (verifie_par_id),
              UNIQUE INDEX UNIQ_516E74682A4C4478 (paiement_id),
              INDEX IDX_516E74683414710B (agent_id),
              INDEX idx_mouvement_type (type),
              INDEX idx_mouvement_statut (statut),
              INDEX idx_mouvement_date (date_operation),
              INDEX idx_mouvement_categorie (categorie),
              INDEX idx_mouvement_impact_resultat (impact_resultat),
              INDEX idx_mouvement_confidentiel (confidentiel),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE notifications (
              id INT AUTO_INCREMENT NOT NULL,
              message VARCHAR(255) NOT NULL,
              route VARCHAR(100) DEFAULT NULL,
              route_parametres JSON DEFAULT NULL,
              lue TINYINT DEFAULT 0 NOT NULL,
              date_creation DATETIME NOT NULL,
              destinataire_id INT NOT NULL,
              INDEX IDX_6000B0D3A4F84F6E (destinataire_id),
              INDEX idx_notification_destinataire_lue (destinataire_id, lue),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ordre_production (
              id INT AUTO_INCREMENT NOT NULL,
              numero VARCHAR(30) NOT NULL,
              numero_etiquette VARCHAR(50) DEFAULT NULL,
              etiquette_affectee_le DATETIME DEFAULT NULL,
              statut VARCHAR(30) NOT NULL,
              priorite VARCHAR(20) NOT NULL,
              quantite INT DEFAULT NULL,
              quantite_produite INT DEFAULT 0 NOT NULL,
              quantite_rebut INT DEFAULT 0 NOT NULL,
              instructions LONGTEXT DEFAULT NULL,
              observation_fin LONGTEXT DEFAULT NULL,
              cree_le DATETIME NOT NULL,
              date_limite DATETIME DEFAULT NULL,
              demarre_le DATETIME DEFAULT NULL,
              termine_le DATETIME DEFAULT NULL,
              transmis_le DATETIME DEFAULT NULL,
              debut_prevu_le DATETIME DEFAULT NULL,
              fin_prevue_le DATETIME DEFAULT NULL,
              duree_estimee_minutes INT DEFAULT NULL,
              duree_pause_minutes INT DEFAULT 0 NOT NULL,
              pause_debute_le DATETIME DEFAULT NULL,
              etiquette_affectee_par_id INT DEFAULT NULL,
              commande_detail_id INT NOT NULL,
              controle_pre_presse_id INT NOT NULL,
              machine_id INT DEFAULT NULL,
              cree_par_id INT DEFAULT NULL,
              operateur_id INT DEFAULT NULL,
              termine_par_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_D1C3E844F55AE19E (numero),
              UNIQUE INDEX UNIQ_D1C3E844531D6FC5 (numero_etiquette),
              INDEX IDX_D1C3E8442A05A43E (etiquette_affectee_par_id),
              INDEX IDX_D1C3E844C8DC59F9 (commande_detail_id),
              INDEX IDX_D1C3E844F6B75B26 (machine_id),
              INDEX IDX_D1C3E844FC29C013 (cree_par_id),
              INDEX IDX_D1C3E8443F192FC (operateur_id),
              INDEX IDX_D1C3E84461168D84 (termine_par_id),
              UNIQUE INDEX uniq_ordre_controle (controle_pre_presse_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ordre_production_fichier (
              ordre_production_id INT NOT NULL,
              commande_detail_fichier_id INT NOT NULL,
              INDEX IDX_514FAAF685180A13 (ordre_production_id),
              INDEX IDX_514FAAF64FB4C8B8 (commande_detail_fichier_id),
              PRIMARY KEY (
                ordre_production_id, commande_detail_fichier_id
              )
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE paiements (
              id INT AUTO_INCREMENT NOT NULL,
              montant INT DEFAULT 0 NOT NULL,
              mode VARCHAR(30) NOT NULL,
              statut VARCHAR(20) NOT NULL,
              date DATETIME NOT NULL,
              date_validation DATETIME DEFAULT NULL,
              date_annulation DATETIME DEFAULT NULL,
              reference VARCHAR(255) DEFAULT NULL,
              numero_cheque VARCHAR(100) DEFAULT NULL,
              banque_emettrice VARCHAR(150) DEFAULT NULL,
              titulaire_cheque VARCHAR(150) DEFAULT NULL,
              date_emission_cheque DATE DEFAULT NULL,
              date_encaissement_prevue DATE DEFAULT NULL,
              justificatif VARCHAR(255) DEFAULT NULL,
              observation LONGTEXT DEFAULT NULL,
              motif_rejet LONGTEXT DEFAULT NULL,
              motif_annulation LONGTEXT DEFAULT NULL,
              frais_retrait_inclus TINYINT DEFAULT 0 NOT NULL,
              fonds_soutien_inclus TINYINT DEFAULT 0 NOT NULL,
              montant_frais_retrait INT DEFAULT 0 NOT NULL,
              montant_fonds_soutien INT DEFAULT 0 NOT NULL,
              commande_id INT NOT NULL,
              compte_tresorerie_id INT DEFAULT NULL,
              encaisse_par_id INT NOT NULL,
              valide_par_id INT DEFAULT NULL,
              annule_par_id INT DEFAULT NULL,
              facture_id INT DEFAULT NULL,
              INDEX IDX_E1B02E12A11E8C5A (compte_tresorerie_id),
              INDEX IDX_E1B02E12A4FBCD6F (encaisse_par_id),
              INDEX IDX_E1B02E126AF12ED9 (valide_par_id),
              INDEX IDX_E1B02E12F376B95 (annule_par_id),
              INDEX IDX_E1B02E127F2DEE08 (facture_id),
              INDEX idx_paiement_commande (commande_id),
              INDEX idx_paiement_compte_statut (compte_tresorerie_id, statut),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE parametres_paiement (
              id INT AUTO_INCREMENT NOT NULL,
              taux_frais_retrait DOUBLE PRECISION DEFAULT '1' NOT NULL,
              taux_fonds_soutien DOUBLE PRECISION DEFAULT '1' NOT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE production (
              id INT AUTO_INCREMENT NOT NULL,
              date_debut DATETIME NOT NULL,
              date_fin DATETIME NOT NULL,
              temps INT NOT NULL,
              m2_imprimes INT NOT NULL,
              etat TINYINT NOT NULL,
              gestion_stock TINYINT DEFAULT 0 NOT NULL,
              commande_details_id INT DEFAULT NULL,
              machine_id INT DEFAULT NULL,
              INDEX IDX_D3EDB1E023D82BC4 (commande_details_id),
              INDEX IDX_D3EDB1E0F6B75B26 (machine_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE produit_article_stock (
              id INT AUTO_INCREMENT NOT NULL,
              coefficient NUMERIC(12, 3) DEFAULT '1.000' NOT NULL,
              mode_calcul VARCHAR(30) DEFAULT 'quantite' NOT NULL,
              actif TINYINT DEFAULT 1 NOT NULL,
              obligatoire TINYINT DEFAULT 1 NOT NULL,
              observation LONGTEXT DEFAULT NULL,
              ordre INT DEFAULT 10 NOT NULL,
              produit_id INT NOT NULL,
              article_id INT NOT NULL,
              INDEX IDX_12DF82AFF347EFB (produit_id),
              INDEX IDX_12DF82AF7294869C (article_id),
              UNIQUE INDEX uniq_produit_article_stock (produit_id, article_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE produit_configuration (
              id INT AUTO_INCREMENT NOT NULL,
              active TINYINT NOT NULL,
              ordre INT NOT NULL,
              description LONGTEXT DEFAULT NULL,
              prix_base INT DEFAULT NULL,
              mode_calcul VARCHAR(30) NOT NULL,
              mode_dimension VARCHAR(20) DEFAULT 'format' NOT NULL,
              largeur_defaut NUMERIC(10, 3) DEFAULT NULL,
              longueur_defaut NUMERIC(10, 3) DEFAULT NULL,
              quantite_minimale INT NOT NULL,
              quantite_maximale INT DEFAULT NULL,
              prix_b2_b INT DEFAULT NULL,
              produit_id INT NOT NULL,
              type_impression_id INT NOT NULL,
              support_id INT NOT NULL,
              format_id INT DEFAULT NULL,
              INDEX IDX_8BBC0309F347EFB (produit_id),
              INDEX IDX_8BBC0309D357F183 (type_impression_id),
              INDEX IDX_8BBC0309315B405 (support_id),
              INDEX IDX_8BBC0309D629F605 (format_id),
              UNIQUE INDEX uniq_produit_configuration (
                produit_id, type_impression_id, support_id,
                format_id
              ),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE produit_configuration_finition (
              id INT AUTO_INCREMENT NOT NULL,
              obligatoire TINYINT NOT NULL,
              selectionnee_par_defaut TINYINT NOT NULL,
              payante TINYINT NOT NULL,
              active TINYINT NOT NULL,
              prix INT NOT NULL,
              mode_calcul VARCHAR(30) NOT NULL,
              quantite_minimale INT NOT NULL,
              quantite_maximale INT DEFAULT NULL,
              ordre INT NOT NULL,
              description VARCHAR(500) DEFAULT NULL,
              produit_configuration_id INT NOT NULL,
              finition_id INT NOT NULL,
              INDEX IDX_BF2ECD31806A5250 (produit_configuration_id),
              INDEX IDX_BF2ECD31CB56F5AF (finition_id),
              UNIQUE INDEX uniq_configuration_finition (
                produit_configuration_id, finition_id
              ),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE produits (
              id INT AUTO_INCREMENT NOT NULL,
              nom VARCHAR(255) NOT NULL,
              description VARCHAR(1000) NOT NULL,
              publie TINYINT DEFAULT 1 NOT NULL,
              ordre INT DEFAULT 10 NOT NULL,
              code VARCHAR(50) NOT NULL,
              prix_base DOUBLE PRECISION DEFAULT NULL,
              prix_b2_b DOUBLE PRECISION DEFAULT NULL,
              personnalisable TINYINT DEFAULT 1 NOT NULL,
              actif TINYINT DEFAULT 1 NOT NULL,
              mode_calcul VARCHAR(30) DEFAULT 'unite' NOT NULL,
              gestion_stock TINYINT DEFAULT 0 NOT NULL,
              categorie_produit_id INT NOT NULL,
              article_stock_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_BE2DDF8C6C6E55B5 (nom),
              UNIQUE INDEX UNIQ_BE2DDF8C77153098 (code),
              INDEX IDX_BE2DDF8C91FDB457 (categorie_produit_id),
              INDEX IDX_BE2DDF8C5825957B (article_stock_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE produits_types_impression (
              produits_id INT NOT NULL,
              types_impression_id INT NOT NULL,
              INDEX IDX_1ADC26C2CD11A2CF (produits_id),
              INDEX IDX_1ADC26C29719B876 (types_impression_id),
              PRIMARY KEY (
                produits_id, types_impression_id
              )
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE produits_supports (
              produits_id INT NOT NULL,
              supports_id INT NOT NULL,
              INDEX IDX_42046455CD11A2CF (produits_id),
              INDEX IDX_4204645597185C1E (supports_id),
              PRIMARY KEY (produits_id, supports_id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE produits_format (
              produits_id INT NOT NULL,
              format_id INT NOT NULL,
              INDEX IDX_4FAB693CCD11A2CF (produits_id),
              INDEX IDX_4FAB693CD629F605 (format_id),
              PRIMARY KEY (produits_id, format_id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE produits_finition (
              produits_id INT NOT NULL,
              finition_id INT NOT NULL,
              INDEX IDX_DF5600F4CD11A2CF (produits_id),
              INDEX IDX_DF5600F4CB56F5AF (finition_id),
              PRIMARY KEY (produits_id, finition_id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE rappel_paiement_envoye (
              id INT AUTO_INCREMENT NOT NULL,
              canal VARCHAR(20) NOT NULL,
              date_envoi DATETIME NOT NULL,
              commande_id INT NOT NULL,
              regle_id INT NOT NULL,
              INDEX IDX_DEAFEF8E82EA2E54 (commande_id),
              INDEX IDX_DEAFEF8E8E12947B (regle_id),
              INDEX idx_rappel_commande_regle (commande_id, regle_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE rapprochement_bancaire (
              id INT AUTO_INCREMENT NOT NULL,
              reference VARCHAR(50) NOT NULL,
              date_debut DATE NOT NULL,
              date_fin DATE NOT NULL,
              solde_ouverture_releve INT NOT NULL,
              solde_cloture_releve INT NOT NULL,
              solde_comptable INT NOT NULL,
              ecart INT NOT NULL,
              statut VARCHAR(20) DEFAULT 'brouillon' NOT NULL,
              observation LONGTEXT DEFAULT NULL,
              date_creation DATETIME NOT NULL,
              date_validation DATETIME DEFAULT NULL,
              date_cloture DATETIME DEFAULT NULL,
              compte_tresorerie_id INT NOT NULL,
              cree_par_id INT DEFAULT NULL,
              valide_par_id INT DEFAULT NULL,
              INDEX IDX_D4D082D0A11E8C5A (compte_tresorerie_id),
              INDEX IDX_D4D082D0FC29C013 (cree_par_id),
              INDEX IDX_D4D082D06AF12ED9 (valide_par_id),
              UNIQUE INDEX uniq_rapprochement_reference (reference),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE reclamations (
              id INT AUTO_INCREMENT NOT NULL,
              reference VARCHAR(50) NOT NULL,
              motif LONGTEXT NOT NULL,
              montant INT NOT NULL,
              justificatif VARCHAR(255) DEFAULT NULL,
              statut VARCHAR(30) NOT NULL,
              date_creation DATETIME NOT NULL,
              date_validation DATETIME DEFAULT NULL,
              motif_refus LONGTEXT DEFAULT NULL,
              date_refus DATETIME DEFAULT NULL,
              date_paiement DATETIME DEFAULT NULL,
              notification_lue TINYINT DEFAULT 0 NOT NULL,
              agent_id INT NOT NULL,
              valide_par_id INT DEFAULT NULL,
              refuse_par_id INT DEFAULT NULL,
              paye_par_id INT DEFAULT NULL,
              mouvement_tresorerie_id INT DEFAULT NULL,
              UNIQUE INDEX UNIQ_1CAD6B76AEA34913 (reference),
              INDEX IDX_1CAD6B763414710B (agent_id),
              INDEX IDX_1CAD6B766AF12ED9 (valide_par_id),
              INDEX IDX_1CAD6B76BED2696A (refuse_par_id),
              INDEX IDX_1CAD6B764D50E957 (paye_par_id),
              UNIQUE INDEX UNIQ_1CAD6B76C1DCAF54 (mouvement_tresorerie_id),
              INDEX idx_reclamation_statut (statut),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE regle_rappel_paiement (
              id INT AUTO_INCREMENT NOT NULL,
              nom VARCHAR(255) NOT NULL,
              delai_jours INT NOT NULL,
              actif TINYINT NOT NULL,
              date_creation DATETIME NOT NULL,
              modele_message_id INT NOT NULL,
              INDEX IDX_20002786A02D04D8 (modele_message_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE stock_entrees (
              id INT AUTO_INCREMENT NOT NULL,
              quantites INT NOT NULL,
              prix INT NOT NULL,
              date DATETIME NOT NULL,
              article_id INT DEFAULT NULL,
              INDEX IDX_3D445A027294869C (article_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE stock_reservation (
              id INT AUTO_INCREMENT NOT NULL,
              quantite NUMERIC(14, 3) NOT NULL,
              statut VARCHAR(30) DEFAULT 'active' NOT NULL,
              date_reservation DATETIME NOT NULL,
              date_liberation DATETIME DEFAULT NULL,
              date_consommation DATETIME DEFAULT NULL,
              observation VARCHAR(255) DEFAULT NULL,
              article_id INT NOT NULL,
              commande_detail_id INT NOT NULL,
              INDEX idx_stock_reservation_article (article_id),
              INDEX idx_stock_reservation_detail (commande_detail_id),
              INDEX idx_stock_reservation_statut (statut),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE stock_sorties (
              id INT AUTO_INCREMENT NOT NULL,
              quantite INT NOT NULL,
              date DATETIME NOT NULL,
              origine VARCHAR(30) DEFAULT NULL,
              reference_origine VARCHAR(100) DEFAULT NULL,
              article_id INT DEFAULT NULL,
              commande_detail_id INT DEFAULT NULL,
              INDEX IDX_5127734B7294869C (article_id),
              INDEX IDX_5127734BC8DC59F9 (commande_detail_id),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE supports (
              id INT AUTO_INCREMENT NOT NULL,
              nom VARCHAR(255) NOT NULL,
              description LONGTEXT NOT NULL,
              publie TINYINT NOT NULL,
              ordre INT NOT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE support_types_impression (
              support_id INT NOT NULL,
              types_impression_id INT NOT NULL,
              INDEX IDX_EE1AA144315B405 (support_id),
              INDEX IDX_EE1AA1449719B876 (types_impression_id),
              PRIMARY KEY (support_id, types_impression_id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE support_format (
              support_id INT NOT NULL,
              format_id INT NOT NULL,
              INDEX IDX_5640922F315B405 (support_id),
              INDEX IDX_5640922FD629F605 (format_id),
              PRIMARY KEY (support_id, format_id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE support_finition (
              support_id INT NOT NULL,
              finition_id INT NOT NULL,
              INDEX IDX_94D67319315B405 (support_id),
              INDEX IDX_94D67319CB56F5AF (finition_id),
              PRIMARY KEY (support_id, finition_id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE types_impression (
              id INT AUTO_INCREMENT NOT NULL,
              nom VARCHAR(255) NOT NULL,
              description VARCHAR(255) NOT NULL,
              publie TINYINT NOT NULL,
              ordre INT NOT NULL,
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE `user` (
              id INT AUTO_INCREMENT NOT NULL,
              username VARCHAR(180) NOT NULL,
              roles JSON NOT NULL,
              password VARCHAR(255) NOT NULL,
              actif TINYINT DEFAULT 1 NOT NULL,
              date_add DATETIME NOT NULL,
              date_update DATETIME NOT NULL,
              must_change_password TINYINT DEFAULT 1 NOT NULL,
              password_changed_at DATETIME DEFAULT NULL,
              last_login_at DATETIME DEFAULT NULL,
              disabled_at DATETIME DEFAULT NULL,
              motif_desactivation VARCHAR(255) DEFAULT NULL,
              employe_id INT NOT NULL,
              UNIQUE INDEX UNIQ_8D93D6491B65292 (employe_id),
              UNIQUE INDEX UNIQ_IDENTIFIER_USERNAME (username),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_messages (
              id BIGINT AUTO_INCREMENT NOT NULL,
              body LONGTEXT NOT NULL,
              headers LONGTEXT NOT NULL,
              queue_name VARCHAR(190) NOT NULL,
              created_at DATETIME NOT NULL,
              available_at DATETIME NOT NULL,
              delivered_at DATETIME DEFAULT NULL,
              INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (
                queue_name, available_at, delivered_at,
                id
              ),
              PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              achat_detail
            ADD
              CONSTRAINT FK_EA7F1F56FE95D117 FOREIGN KEY (achat_id) REFERENCES achats (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              achat_detail
            ADD
              CONSTRAINT FK_EA7F1F567294869C FOREIGN KEY (article_id) REFERENCES articles (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              achats
            ADD
              CONSTRAINT FK_9920924E670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseurs (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              articles
            ADD
              CONSTRAINT FK_BFDD3168670C757F FOREIGN KEY (fournisseur_id) REFERENCES fournisseurs (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              bon_livraison
            ADD
              CONSTRAINT FK_31A531A482EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              bon_livraison
            ADD
              CONSTRAINT FK_31A531A4FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              bon_livraison
            ADD
              CONSTRAINT FK_31A531A46AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              bon_livraison
            ADD
              CONSTRAINT FK_31A531A4E2C6415F FOREIGN KEY (livre_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              bon_livraison
            ADD
              CONSTRAINT FK_31A531A45825957B FOREIGN KEY (article_stock_id) REFERENCES articles (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              bon_livraison_ligne
            ADD
              CONSTRAINT FK_9C189341D8D16068 FOREIGN KEY (bon_livraison_id) REFERENCES bon_livraison (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              bon_livraison_ligne
            ADD
              CONSTRAINT FK_9C189341C8DC59F9 FOREIGN KEY (commande_detail_id) REFERENCES commandes_details (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              campagne
            ADD
              CONSTRAINT FK_539B5D16A02D04D8 FOREIGN KEY (modele_message_id) REFERENCES modele_message (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              campagne
            ADD
              CONSTRAINT FK_539B5D16FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              campagne_destinataire
            ADD
              CONSTRAINT FK_BAD1F48516227374 FOREIGN KEY (campagne_id) REFERENCES campagne (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              campagne_destinataire
            ADD
              CONSTRAINT FK_BAD1F48519EB6921 FOREIGN KEY (client_id) REFERENCES clients (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commande_detail_fichier
            ADD
              CONSTRAINT FK_39E2DA10C8DC59F9 FOREIGN KEY (commande_detail_id) REFERENCES commandes_details (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commande_detail_fichier
            ADD
              CONSTRAINT FK_39E2DA10B130D9F1 FOREIGN KEY (fichier_source_id) REFERENCES commande_detail_fichier (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commande_detail_fichier
            ADD
              CONSTRAINT FK_39E2DA10DAA76F43 FOREIGN KEY (ajoute_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commande_detail_finition
            ADD
              CONSTRAINT FK_B07D7138C8DC59F9 FOREIGN KEY (commande_detail_id) REFERENCES commandes_details (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commande_detail_finition
            ADD
              CONSTRAINT FK_B07D7138A0C197D7 FOREIGN KEY (configuration_finition_id) REFERENCES produit_configuration_finition (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commande_detail_finition
            ADD
              CONSTRAINT FK_B07D7138CB56F5AF FOREIGN KEY (finition_id) REFERENCES finition (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commandes
            ADD
              CONSTRAINT FK_35D4282CAB014612 FOREIGN KEY (clients_id) REFERENCES clients (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commandes
            ADD
              CONSTRAINT FK_35D4282C709770DC FOREIGN KEY (agents_id) REFERENCES `user` (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commandes
            ADD
              CONSTRAINT FK_35D4282C553B2554 FOREIGN KEY (modifie_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commandes_details
            ADD
              CONSTRAINT FK_B48B83DA82EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commandes_details
            ADD
              CONSTRAINT FK_B48B83DAF347EFB FOREIGN KEY (produit_id) REFERENCES produits (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commandes_details
            ADD
              CONSTRAINT FK_B48B83DA806A5250 FOREIGN KEY (produit_configuration_id) REFERENCES produit_configuration (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commandes_details
            ADD
              CONSTRAINT FK_B48B83DAD357F183 FOREIGN KEY (type_impression_id) REFERENCES types_impression (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commandes_details
            ADD
              CONSTRAINT FK_B48B83DA315B405 FOREIGN KEY (support_id) REFERENCES supports (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commandes_details
            ADD
              CONSTRAINT FK_B48B83DAF6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commandes_details
            ADD
              CONSTRAINT FK_B48B83DAD629F605 FOREIGN KEY (format_id) REFERENCES format (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commandes_details
            ADD
              CONSTRAINT FK_B48B83DAE87137C3 FOREIGN KEY (production_debutee_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commandes_details
            ADD
              CONSTRAINT FK_B48B83DAF889D27D FOREIGN KEY (production_terminee_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              commandes_details
            ADD
              CONSTRAINT FK_B48B83DA7294869C FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              compte_tresorerie
            ADD
              CONSTRAINT FK_E738ED0676C50E4A FOREIGN KEY (proprietaire_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              consommation_encres
            ADD
              CONSTRAINT FK_31C495E1ECC6147F FOREIGN KEY (production_id) REFERENCES production (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              controle_pre_presse
            ADD
              CONSTRAINT FK_FA26187C8DC59F9 FOREIGN KEY (commande_detail_id) REFERENCES commandes_details (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              controle_pre_presse
            ADD
              CONSTRAINT FK_FA26187BA5AD30 FOREIGN KEY (controle_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              controle_pre_presse
            ADD
              CONSTRAINT FK_FA26187167FABE8 FOREIGN KEY (traite_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              controle_pre_presse
            ADD
              CONSTRAINT FK_FA261876AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              controle_pre_presse
            ADD
              CONSTRAINT FK_FA261878FBD9DB1 FOREIGN KEY (envoye_production_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              controle_pre_presse_fichier
            ADD
              CONSTRAINT FK_4D8558C4C6055018 FOREIGN KEY (controle_pre_presse_id) REFERENCES controle_pre_presse (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              controle_pre_presse_fichier
            ADD
              CONSTRAINT FK_4D8558C4F915CFE FOREIGN KEY (fichier_id) REFERENCES commande_detail_fichier (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              conversation_participants
            ADD
              CONSTRAINT FK_21821ED39AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              conversation_participants
            ADD
              CONSTRAINT FK_21821ED3FB88E14F FOREIGN KEY (utilisateur_id) REFERENCES `user` (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              decaissement_recurrent
            ADD
              CONSTRAINT FK_23DC19F656B22253 FOREIGN KEY (compte_source_id) REFERENCES compte_tresorerie (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              decaissement_recurrent
            ADD
              CONSTRAINT FK_23DC19F6FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              devis
            ADD
              CONSTRAINT FK_8B27C52BAB014612 FOREIGN KEY (clients_id) REFERENCES clients (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              devis
            ADD
              CONSTRAINT FK_8B27C52B709770DC FOREIGN KEY (agents_id) REFERENCES `user` (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              devis
            ADD
              CONSTRAINT FK_8B27C52B82EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              devis_detail_finition
            ADD
              CONSTRAINT FK_BE70DB30A903C9C1 FOREIGN KEY (devis_detail_id) REFERENCES devis_details (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              devis_detail_finition
            ADD
              CONSTRAINT FK_BE70DB30A0C197D7 FOREIGN KEY (configuration_finition_id) REFERENCES produit_configuration_finition (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              devis_detail_finition
            ADD
              CONSTRAINT FK_BE70DB30CB56F5AF FOREIGN KEY (finition_id) REFERENCES finition (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              devis_details
            ADD
              CONSTRAINT FK_E0C890D641DEFADA FOREIGN KEY (devis_id) REFERENCES devis (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              devis_details
            ADD
              CONSTRAINT FK_E0C890D6F347EFB FOREIGN KEY (produit_id) REFERENCES produits (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              devis_details
            ADD
              CONSTRAINT FK_E0C890D6806A5250 FOREIGN KEY (produit_configuration_id) REFERENCES produit_configuration (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              devis_details
            ADD
              CONSTRAINT FK_E0C890D6D357F183 FOREIGN KEY (type_impression_id) REFERENCES types_impression (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              devis_details
            ADD
              CONSTRAINT FK_E0C890D6315B405 FOREIGN KEY (support_id) REFERENCES supports (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              devis_details
            ADD
              CONSTRAINT FK_E0C890D6D629F605 FOREIGN KEY (format_id) REFERENCES format (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              devis_details
            ADD
              CONSTRAINT FK_E0C890D67294869C FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              etiquette
            ADD
              CONSTRAINT FK_1E0E195AA8CBA5F7 FOREIGN KEY (lot_id) REFERENCES lot_etiquette (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              etiquette
            ADD
              CONSTRAINT FK_1E0E195A82EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              etiquette
            ADD
              CONSTRAINT FK_1E0E195AC8DC59F9 FOREIGN KEY (commande_detail_id) REFERENCES commandes_details (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              etiquette
            ADD
              CONSTRAINT FK_1E0E195A276FAE2C FOREIGN KEY (associe_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              factures
            ADD
              CONSTRAINT FK_647590B82EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              finition_types_impression
            ADD
              CONSTRAINT FK_4EB60CFECB56F5AF FOREIGN KEY (finition_id) REFERENCES finition (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              finition_types_impression
            ADD
              CONSTRAINT FK_4EB60CFE9719B876 FOREIGN KEY (types_impression_id) REFERENCES types_impression (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              format_types_impression
            ADD
              CONSTRAINT FK_5CE0BAD8D629F605 FOREIGN KEY (format_id) REFERENCES format (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              format_types_impression
            ADD
              CONSTRAINT FK_5CE0BAD89719B876 FOREIGN KEY (types_impression_id) REFERENCES types_impression (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              journal_activite
            ADD
              CONSTRAINT FK_68C5F8ACFB88E14F FOREIGN KEY (utilisateur_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ligne_rapprochement_bancaire
            ADD
              CONSTRAINT FK_83FBB11264AED8C9 FOREIGN KEY (rapprochement_bancaire_id) REFERENCES rapprochement_bancaire (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ligne_rapprochement_bancaire
            ADD
              CONSTRAINT FK_83FBB112C1DCAF54 FOREIGN KEY (mouvement_tresorerie_id) REFERENCES mouvement_tresorerie (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              lot_etiquette
            ADD
              CONSTRAINT FK_3F18271E9DEC084D FOREIGN KEY (genere_par_id) REFERENCES `user` (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              maintenance
            ADD
              CONSTRAINT FK_2F84F8E9F6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              messages_chat
            ADD
              CONSTRAINT FK_B770B8179AC0396 FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              messages_chat
            ADD
              CONSTRAINT FK_B770B81760BB6FE6 FOREIGN KEY (auteur_id) REFERENCES `user` (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              mouvement_tresorerie
            ADD
              CONSTRAINT FK_516E746856B22253 FOREIGN KEY (compte_source_id) REFERENCES compte_tresorerie (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              mouvement_tresorerie
            ADD
              CONSTRAINT FK_516E74684105B733 FOREIGN KEY (compte_destination_id) REFERENCES compte_tresorerie (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              mouvement_tresorerie
            ADD
              CONSTRAINT FK_516E7468A5CB0CEF FOREIGN KEY (verifie_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              mouvement_tresorerie
            ADD
              CONSTRAINT FK_516E74682A4C4478 FOREIGN KEY (paiement_id) REFERENCES paiements (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              mouvement_tresorerie
            ADD
              CONSTRAINT FK_516E74683414710B FOREIGN KEY (agent_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              notifications
            ADD
              CONSTRAINT FK_6000B0D3A4F84F6E FOREIGN KEY (destinataire_id) REFERENCES `user` (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ordre_production
            ADD
              CONSTRAINT FK_D1C3E8442A05A43E FOREIGN KEY (etiquette_affectee_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ordre_production
            ADD
              CONSTRAINT FK_D1C3E844C8DC59F9 FOREIGN KEY (commande_detail_id) REFERENCES commandes_details (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ordre_production
            ADD
              CONSTRAINT FK_D1C3E844C6055018 FOREIGN KEY (controle_pre_presse_id) REFERENCES controle_pre_presse (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ordre_production
            ADD
              CONSTRAINT FK_D1C3E844F6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ordre_production
            ADD
              CONSTRAINT FK_D1C3E844FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ordre_production
            ADD
              CONSTRAINT FK_D1C3E8443F192FC FOREIGN KEY (operateur_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ordre_production
            ADD
              CONSTRAINT FK_D1C3E84461168D84 FOREIGN KEY (termine_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ordre_production_fichier
            ADD
              CONSTRAINT FK_514FAAF685180A13 FOREIGN KEY (ordre_production_id) REFERENCES ordre_production (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              ordre_production_fichier
            ADD
              CONSTRAINT FK_514FAAF64FB4C8B8 FOREIGN KEY (commande_detail_fichier_id) REFERENCES commande_detail_fichier (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              paiements
            ADD
              CONSTRAINT FK_E1B02E1282EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              paiements
            ADD
              CONSTRAINT FK_E1B02E12A11E8C5A FOREIGN KEY (compte_tresorerie_id) REFERENCES compte_tresorerie (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              paiements
            ADD
              CONSTRAINT FK_E1B02E12A4FBCD6F FOREIGN KEY (encaisse_par_id) REFERENCES `user` (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              paiements
            ADD
              CONSTRAINT FK_E1B02E126AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              paiements
            ADD
              CONSTRAINT FK_E1B02E12F376B95 FOREIGN KEY (annule_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              paiements
            ADD
              CONSTRAINT FK_E1B02E127F2DEE08 FOREIGN KEY (facture_id) REFERENCES factures (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              production
            ADD
              CONSTRAINT FK_D3EDB1E023D82BC4 FOREIGN KEY (commande_details_id) REFERENCES commandes_details (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              production
            ADD
              CONSTRAINT FK_D3EDB1E0F6B75B26 FOREIGN KEY (machine_id) REFERENCES machines (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produit_article_stock
            ADD
              CONSTRAINT FK_12DF82AFF347EFB FOREIGN KEY (produit_id) REFERENCES produits (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produit_article_stock
            ADD
              CONSTRAINT FK_12DF82AF7294869C FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produit_configuration
            ADD
              CONSTRAINT FK_8BBC0309F347EFB FOREIGN KEY (produit_id) REFERENCES produits (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produit_configuration
            ADD
              CONSTRAINT FK_8BBC0309D357F183 FOREIGN KEY (type_impression_id) REFERENCES types_impression (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produit_configuration
            ADD
              CONSTRAINT FK_8BBC0309315B405 FOREIGN KEY (support_id) REFERENCES supports (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produit_configuration
            ADD
              CONSTRAINT FK_8BBC0309D629F605 FOREIGN KEY (format_id) REFERENCES format (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produit_configuration_finition
            ADD
              CONSTRAINT FK_BF2ECD31806A5250 FOREIGN KEY (produit_configuration_id) REFERENCES produit_configuration (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produit_configuration_finition
            ADD
              CONSTRAINT FK_BF2ECD31CB56F5AF FOREIGN KEY (finition_id) REFERENCES finition (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produits
            ADD
              CONSTRAINT FK_BE2DDF8C91FDB457 FOREIGN KEY (categorie_produit_id) REFERENCES categorie_produit (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produits
            ADD
              CONSTRAINT FK_BE2DDF8C5825957B FOREIGN KEY (article_stock_id) REFERENCES articles (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produits_types_impression
            ADD
              CONSTRAINT FK_1ADC26C2CD11A2CF FOREIGN KEY (produits_id) REFERENCES produits (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produits_types_impression
            ADD
              CONSTRAINT FK_1ADC26C29719B876 FOREIGN KEY (types_impression_id) REFERENCES types_impression (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produits_supports
            ADD
              CONSTRAINT FK_42046455CD11A2CF FOREIGN KEY (produits_id) REFERENCES produits (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produits_supports
            ADD
              CONSTRAINT FK_4204645597185C1E FOREIGN KEY (supports_id) REFERENCES supports (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produits_format
            ADD
              CONSTRAINT FK_4FAB693CCD11A2CF FOREIGN KEY (produits_id) REFERENCES produits (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produits_format
            ADD
              CONSTRAINT FK_4FAB693CD629F605 FOREIGN KEY (format_id) REFERENCES format (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produits_finition
            ADD
              CONSTRAINT FK_DF5600F4CD11A2CF FOREIGN KEY (produits_id) REFERENCES produits (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              produits_finition
            ADD
              CONSTRAINT FK_DF5600F4CB56F5AF FOREIGN KEY (finition_id) REFERENCES finition (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              rappel_paiement_envoye
            ADD
              CONSTRAINT FK_DEAFEF8E82EA2E54 FOREIGN KEY (commande_id) REFERENCES commandes (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              rappel_paiement_envoye
            ADD
              CONSTRAINT FK_DEAFEF8E8E12947B FOREIGN KEY (regle_id) REFERENCES regle_rappel_paiement (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              rapprochement_bancaire
            ADD
              CONSTRAINT FK_D4D082D0A11E8C5A FOREIGN KEY (compte_tresorerie_id) REFERENCES compte_tresorerie (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              rapprochement_bancaire
            ADD
              CONSTRAINT FK_D4D082D0FC29C013 FOREIGN KEY (cree_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              rapprochement_bancaire
            ADD
              CONSTRAINT FK_D4D082D06AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              reclamations
            ADD
              CONSTRAINT FK_1CAD6B763414710B FOREIGN KEY (agent_id) REFERENCES `user` (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              reclamations
            ADD
              CONSTRAINT FK_1CAD6B766AF12ED9 FOREIGN KEY (valide_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              reclamations
            ADD
              CONSTRAINT FK_1CAD6B76BED2696A FOREIGN KEY (refuse_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              reclamations
            ADD
              CONSTRAINT FK_1CAD6B764D50E957 FOREIGN KEY (paye_par_id) REFERENCES `user` (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              reclamations
            ADD
              CONSTRAINT FK_1CAD6B76C1DCAF54 FOREIGN KEY (mouvement_tresorerie_id) REFERENCES mouvement_tresorerie (id) ON DELETE
            SET
              NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              regle_rappel_paiement
            ADD
              CONSTRAINT FK_20002786A02D04D8 FOREIGN KEY (modele_message_id) REFERENCES modele_message (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              stock_entrees
            ADD
              CONSTRAINT FK_3D445A027294869C FOREIGN KEY (article_id) REFERENCES articles (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              stock_reservation
            ADD
              CONSTRAINT FK_9D06EF617294869C FOREIGN KEY (article_id) REFERENCES articles (id) ON DELETE RESTRICT
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              stock_reservation
            ADD
              CONSTRAINT FK_9D06EF61C8DC59F9 FOREIGN KEY (commande_detail_id) REFERENCES commandes_details (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              stock_sorties
            ADD
              CONSTRAINT FK_5127734B7294869C FOREIGN KEY (article_id) REFERENCES articles (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              stock_sorties
            ADD
              CONSTRAINT FK_5127734BC8DC59F9 FOREIGN KEY (commande_detail_id) REFERENCES commandes_details (id)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              support_types_impression
            ADD
              CONSTRAINT FK_EE1AA144315B405 FOREIGN KEY (support_id) REFERENCES supports (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              support_types_impression
            ADD
              CONSTRAINT FK_EE1AA1449719B876 FOREIGN KEY (types_impression_id) REFERENCES types_impression (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              support_format
            ADD
              CONSTRAINT FK_5640922F315B405 FOREIGN KEY (support_id) REFERENCES supports (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              support_format
            ADD
              CONSTRAINT FK_5640922FD629F605 FOREIGN KEY (format_id) REFERENCES format (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              support_finition
            ADD
              CONSTRAINT FK_94D67319315B405 FOREIGN KEY (support_id) REFERENCES supports (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              support_finition
            ADD
              CONSTRAINT FK_94D67319CB56F5AF FOREIGN KEY (finition_id) REFERENCES finition (id) ON DELETE CASCADE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE
              `user`
            ADD
              CONSTRAINT FK_8D93D6491B65292 FOREIGN KEY (employe_id) REFERENCES employes (id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE achat_detail DROP FOREIGN KEY FK_EA7F1F56FE95D117');
        $this->addSql('ALTER TABLE achat_detail DROP FOREIGN KEY FK_EA7F1F567294869C');
        $this->addSql('ALTER TABLE achats DROP FOREIGN KEY FK_9920924E670C757F');
        $this->addSql('ALTER TABLE articles DROP FOREIGN KEY FK_BFDD3168670C757F');
        $this->addSql('ALTER TABLE bon_livraison DROP FOREIGN KEY FK_31A531A482EA2E54');
        $this->addSql('ALTER TABLE bon_livraison DROP FOREIGN KEY FK_31A531A4FC29C013');
        $this->addSql('ALTER TABLE bon_livraison DROP FOREIGN KEY FK_31A531A46AF12ED9');
        $this->addSql('ALTER TABLE bon_livraison DROP FOREIGN KEY FK_31A531A4E2C6415F');
        $this->addSql('ALTER TABLE bon_livraison DROP FOREIGN KEY FK_31A531A45825957B');
        $this->addSql('ALTER TABLE bon_livraison_ligne DROP FOREIGN KEY FK_9C189341D8D16068');
        $this->addSql('ALTER TABLE bon_livraison_ligne DROP FOREIGN KEY FK_9C189341C8DC59F9');
        $this->addSql('ALTER TABLE campagne DROP FOREIGN KEY FK_539B5D16A02D04D8');
        $this->addSql('ALTER TABLE campagne DROP FOREIGN KEY FK_539B5D16FC29C013');
        $this->addSql('ALTER TABLE campagne_destinataire DROP FOREIGN KEY FK_BAD1F48516227374');
        $this->addSql('ALTER TABLE campagne_destinataire DROP FOREIGN KEY FK_BAD1F48519EB6921');
        $this->addSql('ALTER TABLE commande_detail_fichier DROP FOREIGN KEY FK_39E2DA10C8DC59F9');
        $this->addSql('ALTER TABLE commande_detail_fichier DROP FOREIGN KEY FK_39E2DA10B130D9F1');
        $this->addSql('ALTER TABLE commande_detail_fichier DROP FOREIGN KEY FK_39E2DA10DAA76F43');
        $this->addSql('ALTER TABLE commande_detail_finition DROP FOREIGN KEY FK_B07D7138C8DC59F9');
        $this->addSql('ALTER TABLE commande_detail_finition DROP FOREIGN KEY FK_B07D7138A0C197D7');
        $this->addSql('ALTER TABLE commande_detail_finition DROP FOREIGN KEY FK_B07D7138CB56F5AF');
        $this->addSql('ALTER TABLE commandes DROP FOREIGN KEY FK_35D4282CAB014612');
        $this->addSql('ALTER TABLE commandes DROP FOREIGN KEY FK_35D4282C709770DC');
        $this->addSql('ALTER TABLE commandes DROP FOREIGN KEY FK_35D4282C553B2554');
        $this->addSql('ALTER TABLE commandes_details DROP FOREIGN KEY FK_B48B83DA82EA2E54');
        $this->addSql('ALTER TABLE commandes_details DROP FOREIGN KEY FK_B48B83DAF347EFB');
        $this->addSql('ALTER TABLE commandes_details DROP FOREIGN KEY FK_B48B83DA806A5250');
        $this->addSql('ALTER TABLE commandes_details DROP FOREIGN KEY FK_B48B83DAD357F183');
        $this->addSql('ALTER TABLE commandes_details DROP FOREIGN KEY FK_B48B83DA315B405');
        $this->addSql('ALTER TABLE commandes_details DROP FOREIGN KEY FK_B48B83DAF6B75B26');
        $this->addSql('ALTER TABLE commandes_details DROP FOREIGN KEY FK_B48B83DAD629F605');
        $this->addSql('ALTER TABLE commandes_details DROP FOREIGN KEY FK_B48B83DAE87137C3');
        $this->addSql('ALTER TABLE commandes_details DROP FOREIGN KEY FK_B48B83DAF889D27D');
        $this->addSql('ALTER TABLE commandes_details DROP FOREIGN KEY FK_B48B83DA7294869C');
        $this->addSql('ALTER TABLE compte_tresorerie DROP FOREIGN KEY FK_E738ED0676C50E4A');
        $this->addSql('ALTER TABLE consommation_encres DROP FOREIGN KEY FK_31C495E1ECC6147F');
        $this->addSql('ALTER TABLE controle_pre_presse DROP FOREIGN KEY FK_FA26187C8DC59F9');
        $this->addSql('ALTER TABLE controle_pre_presse DROP FOREIGN KEY FK_FA26187BA5AD30');
        $this->addSql('ALTER TABLE controle_pre_presse DROP FOREIGN KEY FK_FA26187167FABE8');
        $this->addSql('ALTER TABLE controle_pre_presse DROP FOREIGN KEY FK_FA261876AF12ED9');
        $this->addSql('ALTER TABLE controle_pre_presse DROP FOREIGN KEY FK_FA261878FBD9DB1');
        $this->addSql('ALTER TABLE controle_pre_presse_fichier DROP FOREIGN KEY FK_4D8558C4C6055018');
        $this->addSql('ALTER TABLE controle_pre_presse_fichier DROP FOREIGN KEY FK_4D8558C4F915CFE');
        $this->addSql('ALTER TABLE conversation_participants DROP FOREIGN KEY FK_21821ED39AC0396');
        $this->addSql('ALTER TABLE conversation_participants DROP FOREIGN KEY FK_21821ED3FB88E14F');
        $this->addSql('ALTER TABLE decaissement_recurrent DROP FOREIGN KEY FK_23DC19F656B22253');
        $this->addSql('ALTER TABLE decaissement_recurrent DROP FOREIGN KEY FK_23DC19F6FC29C013');
        $this->addSql('ALTER TABLE devis DROP FOREIGN KEY FK_8B27C52BAB014612');
        $this->addSql('ALTER TABLE devis DROP FOREIGN KEY FK_8B27C52B709770DC');
        $this->addSql('ALTER TABLE devis DROP FOREIGN KEY FK_8B27C52B82EA2E54');
        $this->addSql('ALTER TABLE devis_detail_finition DROP FOREIGN KEY FK_BE70DB30A903C9C1');
        $this->addSql('ALTER TABLE devis_detail_finition DROP FOREIGN KEY FK_BE70DB30A0C197D7');
        $this->addSql('ALTER TABLE devis_detail_finition DROP FOREIGN KEY FK_BE70DB30CB56F5AF');
        $this->addSql('ALTER TABLE devis_details DROP FOREIGN KEY FK_E0C890D641DEFADA');
        $this->addSql('ALTER TABLE devis_details DROP FOREIGN KEY FK_E0C890D6F347EFB');
        $this->addSql('ALTER TABLE devis_details DROP FOREIGN KEY FK_E0C890D6806A5250');
        $this->addSql('ALTER TABLE devis_details DROP FOREIGN KEY FK_E0C890D6D357F183');
        $this->addSql('ALTER TABLE devis_details DROP FOREIGN KEY FK_E0C890D6315B405');
        $this->addSql('ALTER TABLE devis_details DROP FOREIGN KEY FK_E0C890D6D629F605');
        $this->addSql('ALTER TABLE devis_details DROP FOREIGN KEY FK_E0C890D67294869C');
        $this->addSql('ALTER TABLE etiquette DROP FOREIGN KEY FK_1E0E195AA8CBA5F7');
        $this->addSql('ALTER TABLE etiquette DROP FOREIGN KEY FK_1E0E195A82EA2E54');
        $this->addSql('ALTER TABLE etiquette DROP FOREIGN KEY FK_1E0E195AC8DC59F9');
        $this->addSql('ALTER TABLE etiquette DROP FOREIGN KEY FK_1E0E195A276FAE2C');
        $this->addSql('ALTER TABLE factures DROP FOREIGN KEY FK_647590B82EA2E54');
        $this->addSql('ALTER TABLE finition_types_impression DROP FOREIGN KEY FK_4EB60CFECB56F5AF');
        $this->addSql('ALTER TABLE finition_types_impression DROP FOREIGN KEY FK_4EB60CFE9719B876');
        $this->addSql('ALTER TABLE format_types_impression DROP FOREIGN KEY FK_5CE0BAD8D629F605');
        $this->addSql('ALTER TABLE format_types_impression DROP FOREIGN KEY FK_5CE0BAD89719B876');
        $this->addSql('ALTER TABLE journal_activite DROP FOREIGN KEY FK_68C5F8ACFB88E14F');
        $this->addSql('ALTER TABLE ligne_rapprochement_bancaire DROP FOREIGN KEY FK_83FBB11264AED8C9');
        $this->addSql('ALTER TABLE ligne_rapprochement_bancaire DROP FOREIGN KEY FK_83FBB112C1DCAF54');
        $this->addSql('ALTER TABLE lot_etiquette DROP FOREIGN KEY FK_3F18271E9DEC084D');
        $this->addSql('ALTER TABLE maintenance DROP FOREIGN KEY FK_2F84F8E9F6B75B26');
        $this->addSql('ALTER TABLE messages_chat DROP FOREIGN KEY FK_B770B8179AC0396');
        $this->addSql('ALTER TABLE messages_chat DROP FOREIGN KEY FK_B770B81760BB6FE6');
        $this->addSql('ALTER TABLE mouvement_tresorerie DROP FOREIGN KEY FK_516E746856B22253');
        $this->addSql('ALTER TABLE mouvement_tresorerie DROP FOREIGN KEY FK_516E74684105B733');
        $this->addSql('ALTER TABLE mouvement_tresorerie DROP FOREIGN KEY FK_516E7468A5CB0CEF');
        $this->addSql('ALTER TABLE mouvement_tresorerie DROP FOREIGN KEY FK_516E74682A4C4478');
        $this->addSql('ALTER TABLE mouvement_tresorerie DROP FOREIGN KEY FK_516E74683414710B');
        $this->addSql('ALTER TABLE notifications DROP FOREIGN KEY FK_6000B0D3A4F84F6E');
        $this->addSql('ALTER TABLE ordre_production DROP FOREIGN KEY FK_D1C3E8442A05A43E');
        $this->addSql('ALTER TABLE ordre_production DROP FOREIGN KEY FK_D1C3E844C8DC59F9');
        $this->addSql('ALTER TABLE ordre_production DROP FOREIGN KEY FK_D1C3E844C6055018');
        $this->addSql('ALTER TABLE ordre_production DROP FOREIGN KEY FK_D1C3E844F6B75B26');
        $this->addSql('ALTER TABLE ordre_production DROP FOREIGN KEY FK_D1C3E844FC29C013');
        $this->addSql('ALTER TABLE ordre_production DROP FOREIGN KEY FK_D1C3E8443F192FC');
        $this->addSql('ALTER TABLE ordre_production DROP FOREIGN KEY FK_D1C3E84461168D84');
        $this->addSql('ALTER TABLE ordre_production_fichier DROP FOREIGN KEY FK_514FAAF685180A13');
        $this->addSql('ALTER TABLE ordre_production_fichier DROP FOREIGN KEY FK_514FAAF64FB4C8B8');
        $this->addSql('ALTER TABLE paiements DROP FOREIGN KEY FK_E1B02E1282EA2E54');
        $this->addSql('ALTER TABLE paiements DROP FOREIGN KEY FK_E1B02E12A11E8C5A');
        $this->addSql('ALTER TABLE paiements DROP FOREIGN KEY FK_E1B02E12A4FBCD6F');
        $this->addSql('ALTER TABLE paiements DROP FOREIGN KEY FK_E1B02E126AF12ED9');
        $this->addSql('ALTER TABLE paiements DROP FOREIGN KEY FK_E1B02E12F376B95');
        $this->addSql('ALTER TABLE paiements DROP FOREIGN KEY FK_E1B02E127F2DEE08');
        $this->addSql('ALTER TABLE production DROP FOREIGN KEY FK_D3EDB1E023D82BC4');
        $this->addSql('ALTER TABLE production DROP FOREIGN KEY FK_D3EDB1E0F6B75B26');
        $this->addSql('ALTER TABLE produit_article_stock DROP FOREIGN KEY FK_12DF82AFF347EFB');
        $this->addSql('ALTER TABLE produit_article_stock DROP FOREIGN KEY FK_12DF82AF7294869C');
        $this->addSql('ALTER TABLE produit_configuration DROP FOREIGN KEY FK_8BBC0309F347EFB');
        $this->addSql('ALTER TABLE produit_configuration DROP FOREIGN KEY FK_8BBC0309D357F183');
        $this->addSql('ALTER TABLE produit_configuration DROP FOREIGN KEY FK_8BBC0309315B405');
        $this->addSql('ALTER TABLE produit_configuration DROP FOREIGN KEY FK_8BBC0309D629F605');
        $this->addSql('ALTER TABLE produit_configuration_finition DROP FOREIGN KEY FK_BF2ECD31806A5250');
        $this->addSql('ALTER TABLE produit_configuration_finition DROP FOREIGN KEY FK_BF2ECD31CB56F5AF');
        $this->addSql('ALTER TABLE produits DROP FOREIGN KEY FK_BE2DDF8C91FDB457');
        $this->addSql('ALTER TABLE produits DROP FOREIGN KEY FK_BE2DDF8C5825957B');
        $this->addSql('ALTER TABLE produits_types_impression DROP FOREIGN KEY FK_1ADC26C2CD11A2CF');
        $this->addSql('ALTER TABLE produits_types_impression DROP FOREIGN KEY FK_1ADC26C29719B876');
        $this->addSql('ALTER TABLE produits_supports DROP FOREIGN KEY FK_42046455CD11A2CF');
        $this->addSql('ALTER TABLE produits_supports DROP FOREIGN KEY FK_4204645597185C1E');
        $this->addSql('ALTER TABLE produits_format DROP FOREIGN KEY FK_4FAB693CCD11A2CF');
        $this->addSql('ALTER TABLE produits_format DROP FOREIGN KEY FK_4FAB693CD629F605');
        $this->addSql('ALTER TABLE produits_finition DROP FOREIGN KEY FK_DF5600F4CD11A2CF');
        $this->addSql('ALTER TABLE produits_finition DROP FOREIGN KEY FK_DF5600F4CB56F5AF');
        $this->addSql('ALTER TABLE rappel_paiement_envoye DROP FOREIGN KEY FK_DEAFEF8E82EA2E54');
        $this->addSql('ALTER TABLE rappel_paiement_envoye DROP FOREIGN KEY FK_DEAFEF8E8E12947B');
        $this->addSql('ALTER TABLE rapprochement_bancaire DROP FOREIGN KEY FK_D4D082D0A11E8C5A');
        $this->addSql('ALTER TABLE rapprochement_bancaire DROP FOREIGN KEY FK_D4D082D0FC29C013');
        $this->addSql('ALTER TABLE rapprochement_bancaire DROP FOREIGN KEY FK_D4D082D06AF12ED9');
        $this->addSql('ALTER TABLE reclamations DROP FOREIGN KEY FK_1CAD6B763414710B');
        $this->addSql('ALTER TABLE reclamations DROP FOREIGN KEY FK_1CAD6B766AF12ED9');
        $this->addSql('ALTER TABLE reclamations DROP FOREIGN KEY FK_1CAD6B76BED2696A');
        $this->addSql('ALTER TABLE reclamations DROP FOREIGN KEY FK_1CAD6B764D50E957');
        $this->addSql('ALTER TABLE reclamations DROP FOREIGN KEY FK_1CAD6B76C1DCAF54');
        $this->addSql('ALTER TABLE regle_rappel_paiement DROP FOREIGN KEY FK_20002786A02D04D8');
        $this->addSql('ALTER TABLE stock_entrees DROP FOREIGN KEY FK_3D445A027294869C');
        $this->addSql('ALTER TABLE stock_reservation DROP FOREIGN KEY FK_9D06EF617294869C');
        $this->addSql('ALTER TABLE stock_reservation DROP FOREIGN KEY FK_9D06EF61C8DC59F9');
        $this->addSql('ALTER TABLE stock_sorties DROP FOREIGN KEY FK_5127734B7294869C');
        $this->addSql('ALTER TABLE stock_sorties DROP FOREIGN KEY FK_5127734BC8DC59F9');
        $this->addSql('ALTER TABLE support_types_impression DROP FOREIGN KEY FK_EE1AA144315B405');
        $this->addSql('ALTER TABLE support_types_impression DROP FOREIGN KEY FK_EE1AA1449719B876');
        $this->addSql('ALTER TABLE support_format DROP FOREIGN KEY FK_5640922F315B405');
        $this->addSql('ALTER TABLE support_format DROP FOREIGN KEY FK_5640922FD629F605');
        $this->addSql('ALTER TABLE support_finition DROP FOREIGN KEY FK_94D67319315B405');
        $this->addSql('ALTER TABLE support_finition DROP FOREIGN KEY FK_94D67319CB56F5AF');
        $this->addSql('ALTER TABLE `user` DROP FOREIGN KEY FK_8D93D6491B65292');
        $this->addSql('DROP TABLE achat_detail');
        $this->addSql('DROP TABLE achats');
        $this->addSql('DROP TABLE articles');
        $this->addSql('DROP TABLE bon_livraison');
        $this->addSql('DROP TABLE bon_livraison_ligne');
        $this->addSql('DROP TABLE campagne');
        $this->addSql('DROP TABLE campagne_destinataire');
        $this->addSql('DROP TABLE categorie_produit');
        $this->addSql('DROP TABLE clients');
        $this->addSql('DROP TABLE commande_detail_fichier');
        $this->addSql('DROP TABLE commande_detail_finition');
        $this->addSql('DROP TABLE commandes');
        $this->addSql('DROP TABLE commandes_details');
        $this->addSql('DROP TABLE compte_tresorerie');
        $this->addSql('DROP TABLE consommation_encres');
        $this->addSql('DROP TABLE controle_pre_presse');
        $this->addSql('DROP TABLE controle_pre_presse_fichier');
        $this->addSql('DROP TABLE conversation_participants');
        $this->addSql('DROP TABLE conversations');
        $this->addSql('DROP TABLE decaissement_recurrent');
        $this->addSql('DROP TABLE devis');
        $this->addSql('DROP TABLE devis_detail_finition');
        $this->addSql('DROP TABLE devis_details');
        $this->addSql('DROP TABLE employes');
        $this->addSql('DROP TABLE etiquette');
        $this->addSql('DROP TABLE factures');
        $this->addSql('DROP TABLE finition');
        $this->addSql('DROP TABLE finition_types_impression');
        $this->addSql('DROP TABLE format');
        $this->addSql('DROP TABLE format_types_impression');
        $this->addSql('DROP TABLE fournisseurs');
        $this->addSql('DROP TABLE journal_activite');
        $this->addSql('DROP TABLE ligne_rapprochement_bancaire');
        $this->addSql('DROP TABLE lot_etiquette');
        $this->addSql('DROP TABLE machines');
        $this->addSql('DROP TABLE maintenance');
        $this->addSql('DROP TABLE messages_chat');
        $this->addSql('DROP TABLE modele_message');
        $this->addSql('DROP TABLE mouvement_tresorerie');
        $this->addSql('DROP TABLE notifications');
        $this->addSql('DROP TABLE ordre_production');
        $this->addSql('DROP TABLE ordre_production_fichier');
        $this->addSql('DROP TABLE paiements');
        $this->addSql('DROP TABLE parametres_paiement');
        $this->addSql('DROP TABLE production');
        $this->addSql('DROP TABLE produit_article_stock');
        $this->addSql('DROP TABLE produit_configuration');
        $this->addSql('DROP TABLE produit_configuration_finition');
        $this->addSql('DROP TABLE produits');
        $this->addSql('DROP TABLE produits_types_impression');
        $this->addSql('DROP TABLE produits_supports');
        $this->addSql('DROP TABLE produits_format');
        $this->addSql('DROP TABLE produits_finition');
        $this->addSql('DROP TABLE rappel_paiement_envoye');
        $this->addSql('DROP TABLE rapprochement_bancaire');
        $this->addSql('DROP TABLE reclamations');
        $this->addSql('DROP TABLE regle_rappel_paiement');
        $this->addSql('DROP TABLE stock_entrees');
        $this->addSql('DROP TABLE stock_reservation');
        $this->addSql('DROP TABLE stock_sorties');
        $this->addSql('DROP TABLE supports');
        $this->addSql('DROP TABLE support_types_impression');
        $this->addSql('DROP TABLE support_format');
        $this->addSql('DROP TABLE support_finition');
        $this->addSql('DROP TABLE types_impression');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
