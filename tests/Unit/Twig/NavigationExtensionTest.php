<?php

namespace App\Tests\Unit\Twig;

use App\Entity\Employes;
use App\Entity\RoleUtilisateur;
use App\Entity\User;
use App\Security\Droits;
use App\Security\LivreurVoter;
use App\Twig\NavigationExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class NavigationExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function routesProvider(): iterable
    {
        yield 'tableau de bord' => ['app_home', 'accueil'];
        yield 'statistiques' => ['app_statistiques_globales_index', 'accueil'];
        yield 'devis' => ['app_devis_new', 'ventes'];
        yield 'commande' => ['app_commandes_show', 'ventes'];
        yield 'production' => ['app_production_index', 'atelier'];
        yield 'stock' => ['app_stock_etat_index', 'atelier'];
        yield 'fournisseurs (achats)' => ['app_fournisseur_show', 'atelier'];
        yield 'commandes d\'achat' => ['app_achat_edit', 'atelier'];
        yield 'réceptions (achats)' => ['app_reception_show', 'atelier'];
        yield 'facture' => ['app_factures_show', 'finances'];
        yield 'caisse' => ['app_caisse_index', 'finances'];
        yield 'à valider' => ['app_a_valider_index', 'finances'];
        yield 'créances' => ['app_creances_index', 'finances'];
        yield 'trésorerie' => ['app_tresorerie_index', 'finances'];
        yield 'comptabilité' => ['app_comptabilite_index', 'finances'];
        yield 'vue d\'ensemble financière' => ['app_finances_vue_ensemble', 'finances'];
        yield 'rentabilité' => ['app_finances_rentabilite', 'finances'];
        yield 'encaissements' => ['app_finances_encaissements', 'finances'];
        yield 'trésorerie 90 jours' => ['app_finances_tresorerie', 'finances'];
        yield 'fournisseurs' => ['app_finances_fournisseurs', 'finances'];
        yield 'dépenses' => ['app_finances_depenses', 'finances'];
        yield 'soldes clients avant clients' => ['app_clients_soldes', 'finances'];
        yield 'fiche client' => ['app_clients_show', 'clients'];
        yield 'campagne' => ['app_campagne_index', 'clients'];
        yield 'produits' => ['app_produits_index', 'ventes'];
        yield 'utilisateurs' => ['app_user_index', 'reglages'];
        yield 'rôles et menus' => ['app_utilisateurs_roles_menus', 'reglages'];
        yield 'employés dans Équipe' => ['app_employes_index', 'equipe'];
        yield 'pointage dans Équipe' => ['app_pointage_jour', 'equipe'];
        yield 'équipes en direct avant production' => ['app_atelier_equipes', 'equipe'];
        yield 'paie' => ['app_paie_voir', 'equipe'];
        yield 'écran hors menu' => ['app_ecran_inconnu', null];
        yield 'route vide' => ['', null];
        yield 'sans route' => [null, null];
    }

    #[DataProvider('routesProvider')]
    public function testSectionPourRoute(?string $route, ?string $section): void
    {
        self::assertSame($section, (new NavigationExtension())->sectionPourRoute($route));
    }

    public function testLibelleSection(): void
    {
        $extension = new NavigationExtension();

        self::assertSame('Réglages', $extension->libelleSection('reglages'));
        self::assertNull($extension->libelleSection(null));
    }

    public function testChaqueSectionAUnEcranDEntree(): void
    {
        $extension = new NavigationExtension();

        foreach (array_keys(NavigationExtension::SECTIONS) as $section) {
            self::assertNotEmpty($extension->ongletsSection($section), $section);
        }

        self::assertSame([], $extension->ongletsSection(null));
    }

    public function testOngletActif(): void
    {
        $extension = new NavigationExtension();

        self::assertSame(0, $extension->ongletActif('app_home'));
        self::assertSame(0, $extension->ongletActif('app_devis_show'));
        self::assertSame(1, $extension->ongletActif('app_livraisons_a_retirer'));
        self::assertSame(4, $extension->ongletActif('app_livraisons_index'));
        self::assertSame(2, $extension->ongletActif('app_livraisons_bl'));
        self::assertSame(3, $extension->ongletActif('app_livraisons_remise'));
        self::assertSame(3, $extension->ongletActif('app_livraisons_tournee_echec'));
        // Le préfixe le plus précis gagne sur « Livraisons » (app_livraisons_).
        self::assertSame(5, $extension->ongletActif('app_livraisons_mes_livraisons'));
        self::assertSame(6, $extension->ongletActif('app_livraisons_suivi'));
        self::assertSame(3, $extension->ongletActif('app_clients_soldes'));
        self::assertSame(0, $extension->ongletActif('app_finances_vue_ensemble'));
        self::assertSame(1, $extension->ongletActif('app_factures_show'));
        self::assertSame(2, $extension->ongletActif('app_creances_index'));
        self::assertSame(5, $extension->ongletActif('app_finances_depenses_voir'));
        self::assertSame(7, $extension->ongletActif('app_mouvement_tresorerie_index'));
        self::assertSame(8, $extension->ongletActif('app_finances_rentabilite'));
        self::assertSame(9, $extension->ongletActif('app_comptabilite_index'));
        self::assertSame(6, $extension->ongletActif('app_finances_fournisseurs'));
        self::assertSame(7, $extension->ongletActif('app_finances_tresorerie'));
        self::assertSame(2, $extension->ongletActif('app_paiements_show'));
        self::assertSame(0, $extension->ongletActif('app_reglages_parametres_index'));
        self::assertSame(2, $extension->ongletActif('app_couts_revient_index'));
        self::assertSame(0, $extension->ongletActif('app_employes_index'));
        self::assertSame(0, $extension->ongletActif('app_equipe_employes_dossier'));
        self::assertSame(0, $extension->ongletActif('app_acces_index'));
        self::assertSame(3, $extension->ongletActif('app_utilisateurs_roles_menus'));
        self::assertSame(1, $extension->ongletActif('app_pointage_jour'));
        self::assertSame(4, $extension->ongletActif('app_paie_voir'));
        self::assertSame(3, $extension->ongletActif('app_demandes_conges'));
        self::assertSame(5, $extension->ongletActif('app_actions_equipe_journal'));
        self::assertSame(7, $extension->ongletActif('app_actions_voir'));
        // Achats (docs/achats.md) : « Fournisseurs », « Commandes d'achat », « Réceptions » et « Réapprovisionnement » juste après « Articles et stock ».
        self::assertSame(11, $extension->ongletActif('app_stock_articles_fiche'));
        self::assertSame(12, $extension->ongletActif('app_fournisseur_show'));
        self::assertSame(13, $extension->ongletActif('app_achat_new'));
        self::assertSame(14, $extension->ongletActif('app_reception_valider'));
        self::assertSame(15, $extension->ongletActif('app_reappro_proposition'));
        self::assertSame(16, $extension->ongletActif('app_machines_index'));
        self::assertNull($extension->ongletActif('app_ecran_inconnu'));
    }

    public function testSeptModulesDansLOrdreDesMaquettes(): void
    {
        self::assertSame(['accueil', 'ventes', 'clients', 'atelier', 'finances', 'equipe', 'reglages'], array_keys(NavigationExtension::SECTIONS));
    }

    public function testMenuEnVoletsPourLaDirection(): void
    {
        $droits = [...Droits::sansRestriction(), 'ROLE_ADMIN'];
        $menu = (new NavigationExtension())->menu('app_paiements_index', RoleUtilisateur::MENU_VOLETS, $droits);

        self::assertSame('volets', $menu['style']);
        self::assertSame(array_keys(NavigationExtension::SECTIONS), array_column($menu['entrees'], 'cle'));
        $finances = $menu['entrees'][4];
        self::assertTrue($finances['actif']);
        self::assertContains('app_finances_encaissements', array_column(array_filter($finances['items'], static fn (array $i): bool => $i['actif']), 'route'));
        // Reglages s'ouvre d'un clic sur ses tuiles, sans volet.
        self::assertSame([], $menu['entrees'][6]['items']);
    }

    public function testMenuCourtSelonLesDroits(): void
    {
        $extension = new NavigationExtension();
        // Le livreur seul : sa tournée et ses livraisons, rien d'autre (même avec ses statistiques cochées).
        $livreur = $extension->menu(null, RoleUtilisateur::MENU_COURT, ['ROLE_LIVRAISON', 'ROLE_STATS_PERSO']);
        self::assertSame(['app_livraisons_tournee', 'app_livraisons_mes_livraisons'], array_column($livreur['entrees'], 'route'));
        self::assertSame(['app_livraisons_tournee', 'app_livraisons_mes_livraisons'], array_column($extension->menu(null, RoleUtilisateur::MENU_VOLETS, ['ROLE_LIVRAISON'])['entrees'][0]['items'], 'route'));

        // Un autre droit coché : il n'est plus livreur seul et retrouve ses écrans.
        $livreurClients = array_column($extension->menu(null, RoleUtilisateur::MENU_COURT, ['ROLE_LIVRAISON', 'ROLE_CLIENT'])['entrees'], 'route');
        self::assertSame(['app_home', 'app_livraisons_tournee', 'app_livraisons_mes_livraisons', 'app_livraisons_a_retirer', 'app_clients_index', 'app_messages_clients_index'], $livreurClients);

        $caisse = array_column($extension->menu(null, RoleUtilisateur::MENU_COURT, ['ROLE_CLIENT', 'ROLE_COMMANDE', 'ROLE_PAIEMENT_ENCAISSER', 'ROLE_PAIEMENT_VOIR'])['entrees'], 'route');
        self::assertSame(['app_home', 'app_caisse_index', 'app_actions_index', 'app_clients_index', 'app_messages_clients_index', 'app_ventes_index', 'app_finances_encaissements', 'app_livraisons_suivi'], $caisse);
        self::assertNotContains('app_stock_articles_index', $caisse, 'stock réservé à la Direction');
        self::assertNotContains('app_fournisseur_index', $caisse, 'achats réservés à la Direction');
        self::assertNotContains('app_achat_index', $caisse, 'achats réservés à la Direction');
        self::assertNotContains('app_reception_index', $caisse, 'réceptions réservées à la Direction');
        self::assertNotContains('app_livraisons_mes_livraisons', $caisse);

        // Prépresse : le suivi des livraisons, sans les écrans du livreur.
        $prepresse = array_column($extension->menu(null, RoleUtilisateur::MENU_COURT, ['ROLE_PREPRESSE'])['entrees'], 'route');
        self::assertContains('app_livraisons_suivi', $prepresse);
        self::assertNotContains('app_livraisons_tournee', $prepresse);

        // La Direction suit les livraisons mais n'a pas « Mes livraisons ».
        $direction = $extension->menu(null, RoleUtilisateur::MENU_VOLETS, [...Droits::sansRestriction(), 'ROLE_ADMIN']);
        $ventes = array_column($direction['entrees'][1]['items'], 'route');
        self::assertContains('app_livraisons_suivi', $ventes);
        self::assertNotContains('app_livraisons_mes_livraisons', $ventes);
    }

    public function testBarreMobileDuLivreur(): void
    {
        $role = (new RoleUtilisateur())->setCode('ROLE_LIVREUR')->setNom('Livreur')->setDroits(['ROLE_LIVRAISON']);
        $utilisateur = (new User())->setProfil($role)->setEmploye(new Employes());
        $extension = $this->extensionPour($utilisateur);

        $barre = $extension->barreMobile('app_livraisons_tournee');
        self::assertSame(['Tournée', 'Livraisons', 'Pointage'], array_column($barre['entrees'], 'libelle'));
        self::assertSame(['app_livraisons_tournee', 'app_livraisons_mes_livraisons', 'app_pointer_index'], array_column($barre['entrees'], 'route'));
        self::assertTrue($barre['entrees'][0]['actif']);
        self::assertSame('Moi', $barre['fin']['libelle']);
        self::assertSame('app_mon_espace_index', $barre['fin']['route']);
    }

    public function testBarreMobileDeLaCaisse(): void
    {
        $role = (new RoleUtilisateur())->setCode('ROLE_CAISSE_COMMANDE')->setNom('Caisse')->setDroits(['ROLE_CLIENT', 'ROLE_COMMANDE', 'ROLE_PAIEMENT_ENCAISSER']);
        $barre = $this->extensionPour((new User())->setProfil($role))->barreMobile('app_home');

        self::assertSame(['Accueil', 'Caisse', 'Actions', 'Clients'], array_column($barre['entrees'], 'libelle'));
        self::assertSame('Plus', $barre['fin']['libelle']);
        self::assertNull($barre['fin']['route']);
    }

    private function extensionPour(User $utilisateur): NavigationExtension
    {
        $droits = $utilisateur->getRoles();
        $autorisations = new class($droits) implements AuthorizationCheckerInterface {
            /** @param list<string> $droits */
            public function __construct(private readonly array $droits)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                if (LivreurVoter::LIVREUR === $attribute) {
                    return LivreurVoter::livre($this->droits);
                }

                return \in_array($attribute, $this->droits, true);
            }
        };
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($utilisateur);

        return new NavigationExtension($autorisations, $security);
    }

    public function testGraphisteSansEncaissements(): void
    {
        $extension = new NavigationExtension();
        $libelles = static fn (array $droits): array => array_column($extension->ongletsAutorises('finances', $droits), 'libelle');

        $graphiste = $libelles(['ROLE_DEVIS', 'ROLE_COMMANDE', 'ROLE_FACTURE', 'ROLE_PREPRESSE']);
        self::assertNotContains('Encaissements', $graphiste);
        self::assertContains('Factures et créances', $graphiste);

        $caisse = $libelles(['ROLE_FACTURE', 'ROLE_PAIEMENT_VOIR', 'ROLE_PAIEMENT_ENCAISSER']);
        self::assertContains('Encaissements', $caisse);
        self::assertNotContains('Factures et créances', $caisse);
    }
}
