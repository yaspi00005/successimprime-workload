<?php

namespace App\Tests\Unit\Security;

use App\Entity\Notification;
use App\Entity\RoleUtilisateur;
use App\Entity\User;
use App\Security\Droits;
use App\Security\LivreurSeul;
use App\Security\LivreurVoter;
use App\Service\Guide\CatalogueGuide;
use App\Service\NotificationService;
use App\Twig\NavigationExtension;
use DoctrineMigrations\Version20261016081500;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../../migrations/Version20261016081500.php';

/**
 * Le livreur ne voit que ses livraisons et sa tournée (décision de Yaya du
 * 15/10/2026) : qui est « livreur seul », les pages qui lui restent, son
 * menu, ses notifications, son guide et le rôle Livreur sans statistiques.
 */
final class LivreurSeulTest extends TestCase
{
    /** Droits du rôle Livreur tels que Version20261010215311 les a créés. */
    private const LIVREUR_AVANT = ['ROLE_LIVRAISON', 'ROLE_STATS_PERSO'];

    public function testQuiEstLivreurSeul(): void
    {
        self::assertTrue(LivreurSeul::est(['ROLE_LIVREUR', 'ROLE_LIVRAISON', 'ROLE_USER']));
        // Ses propres statistiques ne lui ouvrent rien de plus.
        self::assertTrue(LivreurSeul::est(['ROLE_LIVRAISON', 'ROLE_STATS_PERSO', 'ROLE_USER']));
        // Un autre droit coché pour la personne : il retrouve les écrans de ce droit.
        self::assertFalse(LivreurSeul::est(['ROLE_LIVRAISON', 'ROLE_CLIENT', 'ROLE_USER']));
        self::assertFalse(LivreurSeul::est(['ROLE_LIVRAISON', 'ROLE_ENTRETIEN']));
        // La Direction n'est jamais livreur seul ; sans le droit Livraisons non plus.
        self::assertFalse(LivreurSeul::est(['ROLE_ADMIN', 'ROLE_LIVRAISON', 'ROLE_USER']));
        self::assertFalse(LivreurSeul::est([...Droits::sansRestriction(), 'ROLE_ADMIN']));
        self::assertFalse(LivreurSeul::est(['ROLE_PRODUCTION_VOIR', 'ROLE_USER']));
        self::assertFalse(LivreurSeul::est(['ROLE_USER']));
    }

    public function testUnLivreurDeLaDemonstration(): void
    {
        $role = (new RoleUtilisateur())->setCode('ROLE_LIVREUR')->setNom('Livreur')->setDroits(Version20261016081500::sans(self::LIVREUR_AVANT));
        $bakary = (new User())->setProfil($role);
        self::assertTrue(LivreurSeul::est($bakary->getRoles()));
        self::assertTrue(LivreurVoter::livre($bakary->getRoles()));
        self::assertFalse(LivreurVoter::livre(['ROLE_ADMIN', 'ROLE_LIVRAISON']));
        self::assertFalse(LivreurVoter::livre(['ROLE_COMMANDE']));
    }

    public function testPagesPermises(): void
    {
        foreach (['app_livraisons_tournee', 'app_livraisons_tournee_etape', 'app_livraisons_tournee_echec', 'app_livraisons_tournee_terminee', 'app_livraisons_remise', 'app_livraisons_mes_livraisons', 'app_livraisons_bl_preuve', 'app_colis_qr',
            'app_home', 'app_mon_espace_index', 'app_pointer_index', 'app_user_change_password', 'app_aide_index', 'app_notification_index', 'app_logout', 'app_verification', '_wdt'] as $route) {
            self::assertTrue(LivreurSeul::routePermise($route), $route);
        }
        foreach (['app_livraisons_index', 'app_livraisons_a_retirer', 'app_livraisons_planning', 'app_livraisons_bl', 'app_livraisons_suivi', 'app_bons_livraison_index',
            'app_clients_index', 'app_commandes_show', 'app_ventes_index', 'app_statistiques_index', 'app_mes_statistiques', 'app_rapport_semaine', 'app_recherche',
            'app_messages_clients_index', 'app_planning_index', 'app_reclamation_index', 'app_user_index', 'app_verification_x',
            'app_atelier_production_etiquettes', 'app_atelier_production_controle', 'app_atelier_production_colis_scanner', 'app_atelier_operateur_scanner', 'app_colis_qr_x', null, ''] as $route) {
            self::assertFalse(LivreurSeul::routePermise($route), (string) $route);
        }
        foreach (LivreurSeul::MENU as $route) {
            self::assertTrue(LivreurSeul::routePermise($route), $route);
        }
    }

    public function testPageDArrivee(): void
    {
        self::assertSame('app_livraisons_tournee', LivreurSeul::accueil(false, true));
        self::assertSame('app_livraisons_tournee', LivreurSeul::accueil(false, false));
        // En ligne, la tournée n'existe pas.
        self::assertSame('app_mon_espace_index', LivreurSeul::accueil(true, true));
        self::assertSame('app_user_change_password', LivreurSeul::accueil(true, false));
    }

    public function testMenuDuRoleLivreur(): void
    {
        $droits = Version20261016081500::sans(self::LIVREUR_AVANT);
        $menu = (new NavigationExtension())->menu(null, RoleUtilisateur::MENU_COURT, $droits);
        self::assertSame(['Ma tournée', 'Mes livraisons'], array_column($menu['entrees'], 'libelle'));
    }

    public function testNotificationsDuLivreurSeul(): void
    {
        $livreur = (new User())->setProfil((new RoleUtilisateur())->setCode('ROLE_LIVREUR')->setNom('Livreur')->setDroits(['ROLE_LIVRAISON']));
        // « Production terminée » mène à la commande : pas pour lui.
        self::assertFalse(NotificationService::peutRecevoir($livreur, 'app_commandes_show', false));
        self::assertFalse(NotificationService::peutRecevoir($livreur, 'app_planning_tache', true));
        self::assertTrue(NotificationService::peutRecevoir($livreur, 'app_livraisons_tournee', false));
        self::assertTrue(NotificationService::peutRecevoir($livreur, 'app_mon_espace_index', true));
        // Sans lien : seulement si elle lui est adressée.
        self::assertTrue(NotificationService::peutRecevoir($livreur, null, true));
        self::assertFalse(NotificationService::peutRecevoir($livreur, null, false));

        $caisse = (new User())->setProfil((new RoleUtilisateur())->setCode('ROLE_CAISSE_COMMANDE')->setNom('Caisse')->setDroits(['ROLE_COMMANDE']));
        self::assertTrue(NotificationService::peutRecevoir($caisse, 'app_commandes_show', false));

        $visibles = LivreurSeul::notificationsVisibles([
            (new Notification())->setMessage('Production terminée')->setRoute('app_commandes_show'),
            (new Notification())->setMessage('Votre demande est acceptée')->setRoute('app_mon_espace_index'),
            (new Notification())->setMessage('Sans lien'),
        ]);
        self::assertSame(['Votre demande est acceptée', 'Sans lien'], array_map(static fn (Notification $n): ?string => $n->getMessage(), $visibles));
    }

    public function testGuideDuLivreurSeul(): void
    {
        $roles = ['ROLE_LIVREUR', 'ROLE_LIVRAISON', 'ROLE_USER'];
        $accorde = static fn (string $droit): bool => \in_array($droit, $roles, true) || 'PERSONNEL' === $droit;
        $cles = array_column(CatalogueGuide::visibles($accorde, $roles), 'cle');
        self::assertContains('tournee', $cles);
        self::assertContains('remise', $cles);
        self::assertContains('se-connecter', $cles);
        self::assertNotContains('a-retirer', $cles);

        // Avec un autre droit, la fiche des retraits revient.
        $autres = [...$roles, 'ROLE_CLIENT'];
        $accordeAutres = static fn (string $droit): bool => \in_array($droit, $autres, true);
        self::assertContains('a-retirer', array_column(CatalogueGuide::visibles($accordeAutres, $autres), 'cle'));
    }

    public function testRoleLivreurSansSesStatistiques(): void
    {
        self::assertSame('ROLE_LIVREUR', Version20261016081500::ROLE);
        self::assertSame(['ROLE_LIVRAISON'], Version20261016081500::sans(self::LIVREUR_AVANT));
        // Sans effet sur un rôle déjà modifié ; la descente remet le droit, une seule fois.
        self::assertSame(['ROLE_LIVRAISON'], Version20261016081500::sans(['ROLE_LIVRAISON']));
        self::assertSame(self::LIVREUR_AVANT, Version20261016081500::avec(Version20261016081500::sans(self::LIVREUR_AVANT)));
        self::assertSame(self::LIVREUR_AVANT, Version20261016081500::avec(self::LIVREUR_AVANT));
    }
}
