<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Entreprises émettrices (maquette I03) et code de suivi des factures
 * (maquette I02). Les trois émetteurs écrits jusqu'ici dans les PDF
 * deviennent des entreprises réglables, avec les mêmes coordonnées.
 */
final class Version20261010231429 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Entreprises émettrices des documents et code de suivi des factures';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE entreprise (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(30) NOT NULL, raison_sociale VARCHAR(150) NOT NULL, nom_commercial VARCHAR(150) DEFAULT NULL, mention VARCHAR(150) DEFAULT NULL, activite VARCHAR(150) DEFAULT NULL, adresse VARCHAR(255) DEFAULT NULL, telephone VARCHAR(60) DEFAULT NULL, email VARCHAR(150) DEFAULT NULL, site_web VARCHAR(150) DEFAULT NULL, rccm VARCHAR(60) DEFAULT NULL, nif VARCHAR(60) DEFAULT NULL, banque VARCHAR(100) DEFAULT NULL, compte_bancaire VARCHAR(80) DEFAULT NULL, marchand_orange_money VARCHAR(40) DEFAULT NULL, logo VARCHAR(255) DEFAULT NULL, logo_pied VARCHAR(255) DEFAULT NULL, signature VARCHAR(255) DEFAULT NULL, couleurs JSON NOT NULL, format_numero VARCHAR(60) DEFAULT NULL, compteur INT DEFAULT 0 NOT NULL, compteur_annee INT DEFAULT NULL, qr_suivi TINYINT DEFAULT 1 NOT NULL, qr_paiement TINYINT DEFAULT 1 NOT NULL, afficher_logo_pied TINYINT DEFAULT 1 NOT NULL, montant_en_lettres TINYINT DEFAULT 1 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, ordre INT DEFAULT 0 NOT NULL, UNIQUE INDEX UNIQ_D19FA6077153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE factures ADD code_suivi VARCHAR(9) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_647590BC2E9D3C3 ON factures (code_suivi)');

        // Coordonnées reprises des en-têtes de templates/factures/pdf.html.twig.
        $couleursSi = json_encode(['#0F4590', '#DE1028', '#FDC82A', '#E95B35', '#E8398A']);
        $couleursMdg = json_encode(['#0F766E', '#0F766E', '#14B8A6', '#5EEAD4', '#94A3B8']);
        $couleursDrepa = json_encode(['#1E3A8A', '#2563EB', '#60A5FA', '#93C5FD', '#BFDBFE']);
        $entreprises = [
            ['mdg_success', 'Madial Group SARL', 'Succès Imprim', 'Nom commercial de Madial Group SARL', 'Impression · Communication visuelle · Services graphiques', '+223 70 38 69 12', 'succesimprim.com', 'MA.BKO.2026.B.1404', '081148957D', 'ECOBANK MALI', '152248617001', 'images/logo-succes-imprim.png', 'images/logo-mdg.png', $couleursSi, 0],
            ['mdg', 'Madial Group SARL', null, 'Société mère', null, '+223 70 38 69 12', null, 'MA.BKO.2026.B.1404', '081148957D', 'ECOBANK MALI', '152248617001', 'images/logo-mdg.png', null, $couleursMdg, 1],
            ['drepa', 'DREPA TECHNOLOGIE', null, null, null, '+223 70 38 69 12', null, 'MA.BKO.2021.A.911', '081137682V', 'UBA MALI', 'ML206/01004/704040002757/27', null, null, $couleursDrepa, 2],
        ];

        foreach ($entreprises as [$code, $raison, $commercial, $mention, $activite, $telephone, $site, $rccm, $nif, $banque, $compte, $logo, $logoPied, $couleurs, $ordre]) {
            $this->addSql(
                'INSERT INTO entreprise (code, raison_sociale, nom_commercial, mention, activite, adresse, telephone, email, site_web, rccm, nif, banque, compte_bancaire, logo, logo_pied, couleurs, compteur, qr_suivi, qr_paiement, afficher_logo_pied, montant_en_lettres, actif, ordre)
                 VALUES (:code, :raison, :commercial, :mention, :activite, :adresse, :telephone, :email, :site, :rccm, :nif, :banque, :compte, :logo, :logoPied, :couleurs, 0, 1, 1, 1, 1, 1, :ordre)',
                [
                    'code' => $code, 'raison' => $raison, 'commercial' => $commercial, 'mention' => $mention, 'activite' => $activite,
                    'adresse' => 'Bamako, Sotuba ACI', 'telephone' => $telephone, 'email' => $code === 'drepa' ? 'drepatechno@gmail.com' : null,
                    'site' => $site, 'rccm' => $rccm, 'nif' => $nif, 'banque' => $banque, 'compte' => $compte,
                    'logo' => $logo, 'logoPied' => $logoPied, 'couleurs' => $couleurs, 'ordre' => $ordre,
                ]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE entreprise');
        $this->addSql('DROP INDEX UNIQ_647590BC2E9D3C3 ON factures');
        $this->addSql('ALTER TABLE factures DROP code_suivi');
    }
}
