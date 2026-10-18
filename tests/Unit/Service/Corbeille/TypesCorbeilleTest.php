<?php

namespace App\Tests\Unit\Service\Corbeille;

use App\Security\Droits;
use App\Service\Corbeille\TypesCorbeille;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Types\Type;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Yaml\Yaml;

/**
 * Listes de la corbeille (docs/corbeille.md) : chaque table nommée existe
 * vraiment, chaque secret nommé est une vraie colonne (une faute de frappe
 * ferait copier le secret), chaque droit existe.
 */
final class TypesCorbeilleTest extends TestCase
{
    private const RACINE = __DIR__.'/../../../..';

    /** Tables techniques sans entité (créées par Symfony ou Doctrine). */
    private const TABLES_SANS_ENTITE = ['messenger_messages', 'doctrine_migration_versions', 'sessions', 'cache_items', 'rememberme_token'];

    /** @var array<string, ClassMetadata<object>>|null */
    private static ?array $tables = null;

    public function testChaqueTableNommeeExiste(): void
    {
        $tables = self::tables();
        $inconnues = [];
        foreach ([
            'EXCLUES' => array_diff(TypesCorbeille::EXCLUES, self::TABLES_SANS_ENTITE),
            'RATTACHEES' => TypesCorbeille::RATTACHEES,
            'SENSIBLES' => array_keys(TypesCorbeille::SENSIBLES),
            'TYPES' => array_keys(TypesCorbeille::TYPES),
            'NON_RESTAURABLES' => array_keys(TypesCorbeille::NON_RESTAURABLES),
        ] as $liste => $noms) {
            foreach ($noms as $nom) {
                if (!isset($tables[$nom])) {
                    $inconnues[] = $liste.' : '.$nom;
                }
            }
        }

        self::assertSame([], $inconnues);
    }

    public function testChaqueSecretEstUneVraieColonne(): void
    {
        $tables = self::tables();
        $inconnues = [];
        foreach (TypesCorbeille::SENSIBLES as $table => $colonnes) {
            $mappees = $tables[$table]->getColumnNames();
            foreach (array_keys($colonnes) as $colonne) {
                if (!\in_array($colonne, $mappees, true)) {
                    $inconnues[] = $table.'.'.$colonne;
                }
            }
        }

        self::assertSame([], $inconnues);
        self::assertSame([true, 'aleatoire'], TypesCorbeille::sensible('user', 'password'));
        self::assertSame([false, null], TypesCorbeille::sensible('user', 'username'));
    }

    public function testChaqueDroitExiste(): void
    {
        $roles = array_values((new \ReflectionClass(Droits::class))->getConstants());
        $hierarchie = Yaml::parseFile(self::RACINE.'/config/packages/security.yaml', Yaml::PARSE_CUSTOM_TAGS)['security']['role_hierarchy'] ?? [];
        array_push($roles, ...array_keys($hierarchie));
        foreach ($hierarchie as $enfants) {
            array_push($roles, ...(array) $enfants);
        }
        $inconnus = [];
        foreach (TypesCorbeille::TYPES as $table => [, , $droit]) {
            if (!\in_array($droit, $roles, true)) {
                $inconnus[] = $table.' : '.$droit;
            }
        }

        self::assertSame([], $inconnus);
        self::assertSame(TypesCorbeille::DIRECTION, TypesCorbeille::droit('table_sans_type'), 'sans type : Direction seulement');
    }

    public function testLesTablesTechniquesNEntrentJamais(): void
    {
        foreach (['corbeille', 'journal_activite', 'synchro_journal', 'notifications', 'jeton_api', 'sessions'] as $table) {
            self::assertTrue(TypesCorbeille::exclue($table), $table);
        }
        self::assertFalse(TypesCorbeille::exclue('clients'));
    }

    public function testLesLiensRefaitsALEnregistrementSontRattaches(): void
    {
        // Enregistrer un produit refait sa nomenclature : retirer un article n'ouvre pas d'entrée.
        foreach (['produit_article_stock', 'article_fournisseur', 'campagne_destinataire', 'note_article_sondage'] as $table) {
            self::assertTrue(TypesCorbeille::rattachee($table), $table);
        }
        self::assertFalse(TypesCorbeille::rattachee('produits'));
    }

    public function testLibelle(): void
    {
        self::assertSame('Client « Awa Traoré »', TypesCorbeille::libelle('clients', ['prenom' => 'Awa', 'nom' => 'Traoré', 'code' => 'CLI-7'], '7'));
        self::assertSame('Client « Imprim Sahel »', TypesCorbeille::libelle('clients', ['raison_sociale' => 'Imprim Sahel', 'nom' => 'Traoré'], '7'));
        self::assertSame('Devis « n° 42 »', TypesCorbeille::libelle('devis', ['numero' => '  '], '42'));
        self::assertSame('Table inconnue « Essai »', TypesCorbeille::libelle('table_inconnue', ['nom' => 'Essai'], '1'));
        self::assertSame('Promotion client « n° 8 · 900070 »', TypesCorbeille::libelle('promotion_client', ['promotion_id' => 8, 'clients_id' => 900070], '{"promotion_id":8,"clients_id":900070}'), 'clé à plusieurs colonnes, sans JSON');
        self::assertSame('Promotion « '.str_repeat('é', 119).'… »', TypesCorbeille::libelle('promotion', ['nom' => str_repeat('é', 200)], '1'), 'titre coupé à 120 caractères');
    }

    public function testUnFichierNeSortJamaisDeSonDossier(): void
    {
        self::assertSame([
            ['chemin' => 'var/uploads/commandes/passwd', 'colonne' => 'nom_stockage'],
            ['chemin' => 'var/uploads/commandes/apercus/passwd', 'colonne' => 'nom_stockage'],
        ], TypesCorbeille::fichiers('commande_detail_fichier', ['nom_stockage' => '../../../etc/passwd']));
        self::assertSame([], TypesCorbeille::fichiers('commande_detail_fichier', ['nom_stockage' => '..']));
        self::assertSame([], TypesCorbeille::fichiers('depense', ['justificatif' => '../../.env.local']));
        self::assertSame([['chemin' => 'var/uploads/depenses/2026/10/'.str_repeat('a', 32).'.pdf', 'colonne' => 'justificatif']], TypesCorbeille::fichiers('depense', ['justificatif' => '2026/10/'.str_repeat('a', 32).'.pdf']));
        self::assertSame([], TypesCorbeille::fichiers('image_ia', ['fichier' => 'a.png', 'client_id' => '../1']));
        self::assertSame([], TypesCorbeille::fichiers('site_image', ['public_id' => '*']));
        self::assertSame([], TypesCorbeille::fichiers('clients', ['nom_stockage' => 'a.pdf']), 'seules les tables connues ont des fichiers');
    }

    public function testUnFichierRetireAvantEnregistrementNEstPasGarde(): void
    {
        self::assertTrue(TypesCorbeille::ignoree('commande_detail_fichier', ['commande_detail_id' => null, 'nom_stockage' => 'a.pdf']));
        self::assertFalse(TypesCorbeille::ignoree('commande_detail_fichier', ['commande_detail_id' => 40]));
        self::assertFalse(TypesCorbeille::ignoree('clients', []));
    }

    public function testChampsLisibles(): void
    {
        self::assertSame('Numéro', TypesCorbeille::nomColonne('numero'));
        self::assertSame('Facturer à un tiers', TypesCorbeille::nomColonne('facturer_aun_tiers'));
        self::assertSame('Total TTC', TypesCorbeille::nomColonne('total_ttc'));
        self::assertSame('État', TypesCorbeille::nomColonne('etat'), 'majuscule accentuée');
        self::assertSame('Créé le', TypesCorbeille::nomColonne('created_at'), 'colonne technique en anglais');
        self::assertSame('Moyen préféré', TypesCorbeille::nomColonne('moyen_prefere'));
        self::assertSame('Textile, Hôtellerie', TypesCorbeille::valeurLisible('["Textile","Hôtellerie"]'), 'liste rangée en JSON');
        self::assertSame('[{"a":1}]', TypesCorbeille::valeurLisible('[{"a":1}]'), 'objets : laissés tels quels');
        self::assertSame('Client', TypesCorbeille::nomLien('client_id', 'clients'));
        self::assertSame('Compte du personnel', TypesCorbeille::nomLien('user_id', 'user'));
        self::assertSame('Créé par', TypesCorbeille::nomLien('cree_par_id', 'user'), 'deux liens vers le personnel, deux noms');
        self::assertSame('Société', TypesCorbeille::type('societe'), 'table sans type : nommée d\'après elle, avec ses accents');
        self::assertSame('Compte fidélité', TypesCorbeille::type('compte_fidelite'), 'élément d\'un client supprimé avec lui');
        self::assertSame('Ligne relevé Orange Money', TypesCorbeille::type('ligne_releve_orange_money'));
        self::assertSame('Contrôle pré presse fichier', TypesCorbeille::type('controle_pre_presse_fichier'));
        self::assertSame('DEV-2026-0042', TypesCorbeille::titre('devis', ['numero' => 'DEV-2026-0042']));
        self::assertNull(TypesCorbeille::titre('devis', ['numero' => ' ']));

        self::assertSame('23/09/2026 à 11:44', TypesCorbeille::valeurLisible('2026-09-23 11:44:00'));
        self::assertSame('23/09/2026', TypesCorbeille::valeurLisible('2026-09-23'));
        self::assertSame('23/09/2026', TypesCorbeille::valeurLisible('2026-09-23 00:00:00'));
        self::assertSame('oui', TypesCorbeille::valeurLisible('1', 'boolean'));
        self::assertSame('non', TypesCorbeille::valeurLisible(0, 'boolean'));
        self::assertSame('1', TypesCorbeille::valeurLisible('1', 'integer'), 'un nombre reste un nombre');
        self::assertSame('500 000', TypesCorbeille::valeurLisible('500000.00', 'decimal', true));
        self::assertSame('1 250,50', TypesCorbeille::valeurLisible('1250.5', 'decimal', true));
        self::assertSame('DEV-2026-0042', TypesCorbeille::valeurLisible('DEV-2026-0042'));
    }

    public function testMontants(): void
    {
        foreach (['montant_ttc', 'prix_unitaire', 'total_ht', 'salaire_base', 'reste_a_payer', 'forfait', 'minimum_commande', 'taux_surfacturation'] as $colonne) {
            self::assertTrue(TypesCorbeille::estMontant($colonne), $colonne);
        }
        self::assertFalse(TypesCorbeille::estMontant('designation'));
    }

    /** @return array<string, ClassMetadata<object>> table => métadonnées */
    private static function tables(): array
    {
        if (null === self::$tables) {
            if (!Type::hasType('uuid')) {
                Type::addType('uuid', UuidType::class);
            }
            $config = ORMSetup::createAttributeMetadataConfiguration([self::RACINE.'/src/Entity'], true);
            $config->setNamingStrategy(new UnderscoreNamingStrategy(\CASE_LOWER, true));
            $config->enableNativeLazyObjects(true);
            $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
            self::$tables = [];
            foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
                self::$tables[$meta->getTableName()] = $meta;
                foreach ($meta->getAssociationMappings() as $association) {
                    if (isset($association['joinTable']['name'])) {
                        self::$tables[$association['joinTable']['name']] ??= $meta;
                    }
                }
            }
        }

        return self::$tables;
    }
}
