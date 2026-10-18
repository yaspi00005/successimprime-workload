<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Nous sommes une imprimerie, pas une boutique » (Yaya, 18/10/2026) :
 * les textes par défaut déjà posés en base suivent les nouveaux, seulement
 * là où personne ne les a changés.
 *
 * - SMS d'anniversaire par défaut (ModeleAnniversaire::TEXTE_PAR_DEFAUT) ;
 * - lieu « Boutique et accueil » des biens (Immobilisation::LIEUX) ;
 * - étape « Balayer la boutique » du modèle de tâche « Fermeture »
 *   (Planning::MODELES), dans les échéances et les tâches déjà créées.
 *
 * Les noms saisis par les gens (comptes de trésorerie, points de pointage,
 * messages modèles) ne bougent pas.
 */
final class Version20261018181810 extends AbstractMigration
{
    private const SMS_AVANT = 'Succès Imprim : joyeux anniversaire {prénom} ! Cela fait {années} que vous nous faites confiance. Un cadeau vous attend en boutique jusqu\'au {fin}.';
    private const SMS_APRES = 'Succès Imprim : joyeux anniversaire {prénom} ! Cela fait {années} que vous nous faites confiance. Un cadeau vous attend à l\'imprimerie jusqu\'au {fin}.';

    public function getDescription(): string
    {
        return 'Imprimerie, pas boutique : textes par défaut encore inchangés (SMS anniversaire, lieu des biens, étape de fermeture)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE promotion_anniversaire_modele SET texte_sms = ? WHERE texte_sms = ?', [self::SMS_APRES, self::SMS_AVANT]);
        $this->addSql('UPDATE immobilisation SET lieu = ? WHERE lieu = ?', ['Accueil et comptoir', 'Boutique et accueil']);
        foreach (['echeance', 'tache'] as $table) {
            $this->addSql(\sprintf('UPDATE %s SET etapes = REPLACE(etapes, ?, ?) WHERE etapes LIKE ?', $table), ['"Balayer la boutique"', '"Balayer l\'accueil"', '%"Balayer la boutique"%']);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE promotion_anniversaire_modele SET texte_sms = ? WHERE texte_sms = ?', [self::SMS_AVANT, self::SMS_APRES]);
        $this->addSql('UPDATE immobilisation SET lieu = ? WHERE lieu = ?', ['Boutique et accueil', 'Accueil et comptoir']);
        foreach (['echeance', 'tache'] as $table) {
            $this->addSql(\sprintf('UPDATE %s SET etapes = REPLACE(etapes, ?, ?) WHERE etapes LIKE ?', $table), ['"Balayer l\'accueil"', '"Balayer la boutique"', '%"Balayer l\'accueil"%']);
        }
    }
}
