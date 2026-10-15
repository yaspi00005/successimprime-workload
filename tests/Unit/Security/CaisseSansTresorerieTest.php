<?php

namespace App\Tests\Unit\Security;

use App\Controller\MouvementTresorerieController;
use App\Entity\RoleUtilisateur;
use App\Entity\User;
use App\Twig\NavigationExtension;
use DoctrineMigrations\Version20261015090000;
use PHPUnit\Framework\TestCase;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

require_once __DIR__.'/../../../migrations/Version20261015090000.php';

/**
 * Décision de Yaya (15/10/2026) : le rôle « Caisse et commercial » ne voit
 * plus la trésorerie ni les soldes des comptes (ROLE_TRESORERIE_VOIR). La
 * caisse garde l'encaissement, les dépenses, les virements et la clôture
 * de sa caisse ; la Direction accorde la trésorerie à une personne.
 */
final class CaisseSansTresorerieTest extends TestCase
{
    /** Droits du rôle tels que Version20261010215311 les a créés. */
    private const CAISSE_AVANT = ['ROLE_CLIENT', 'ROLE_DEVIS', 'ROLE_COMMANDE', 'ROLE_FACTURE', 'ROLE_PAIEMENT_ENCAISSER', 'ROLE_TRESORERIE_SAISIR', 'ROLE_TRESORERIE_TRANSFERER', 'ROLE_JOURNAL_CAISSE', 'ROLE_STATS_PERSO', 'ROLE_STATS_VENTES', 'ROLE_PAIEMENT_VOIR', 'ROLE_TRESORERIE_VOIR'];

    public function testLaMigrationRetireSeulementLaTresorerieDuRole(): void
    {
        $apres = Version20261015090000::sans(self::CAISSE_AVANT);

        self::assertSame('ROLE_CAISSE_COMMANDE', Version20261015090000::ROLE);
        self::assertNotContains('ROLE_TRESORERIE_VOIR', $apres);
        // Tout le reste du métier de caisse est gardé, dans le même ordre.
        self::assertSame(array_values(array_diff(self::CAISSE_AVANT, ['ROLE_TRESORERIE_VOIR'])), $apres);
        foreach (['ROLE_PAIEMENT_ENCAISSER', 'ROLE_JOURNAL_CAISSE', 'ROLE_TRESORERIE_SAISIR', 'ROLE_TRESORERIE_TRANSFERER'] as $droit) {
            self::assertContains($droit, $apres);
        }
        // Rejouée, elle ne change plus rien.
        self::assertSame($apres, Version20261015090000::sans($apres));
    }

    public function testLeRetourEnArriereRemetLaTresorerieUneFois(): void
    {
        self::assertSame(self::CAISSE_AVANT, Version20261015090000::avec(Version20261015090000::sans(self::CAISSE_AVANT)));
        self::assertSame(self::CAISSE_AVANT, Version20261015090000::avec(self::CAISSE_AVANT));
    }

    public function testLeDroitAjouteSurLaFicheResteApresLaMigration(): void
    {
        $role = (new RoleUtilisateur())->setCode('ROLE_CAISSE_COMMANDE')->setNom('Caisse et commercial')
            ->setDroits(Version20261015090000::sans(self::CAISSE_AVANT));
        $caissiere = (new User())->setProfil($role);
        $comptable = (new User())->setProfil($role)->definirDroits([...$role->getDroits(), 'ROLE_TRESORERIE_VOIR']);

        self::assertNotContains('ROLE_TRESORERIE_VOIR', $caissiere->getRoles());
        self::assertContains('ROLE_TRESORERIE_VOIR', $comptable->getRoles());
        self::assertSame('ajoute', $comptable->etatDroit('ROLE_TRESORERIE_VOIR'));
    }

    /** Le menu de la caisse ne propose aucun écran qui lui répondrait « accès refusé ». */
    public function testFinancesDeLaCaisseSansTresorerie(): void
    {
        $onglets = array_column((new NavigationExtension())->ongletsAutorises('finances', Version20261015090000::sans(self::CAISSE_AVANT)), 'route');

        self::assertSame(['app_finances_vue_ensemble', 'app_finances_encaissements', 'app_caisse_index', 'app_finances_depenses'], $onglets);
        self::assertNotContains('app_finances_tresorerie', $onglets);
        self::assertNotContains('app_finances_fournisseurs', $onglets);
    }

    /** La saisie s'ouvre avec le droit de saisir ou de virer ; la liste et le détail restent à la trésorerie. */
    public function testReglesDAccesDesMouvements(): void
    {
        self::assertSame(['ROLE_TRESORERIE_SAISIR', 'ROLE_TRESORERIE_TRANSFERER'], self::roles('/gestion/tresorerie/mouvements/nouveau'));
        self::assertSame(['ROLE_TRESORERIE_VOIR'], self::roles('/gestion/tresorerie/mouvements'));
        self::assertSame(['ROLE_TRESORERIE_VOIR'], self::roles('/gestion/tresorerie/comptes'));
    }

    public function testAttributsDuControleurDesMouvements(): void
    {
        $classe = new \ReflectionClass(MouvementTresorerieController::class);
        self::assertSame([], $classe->getAttributes(IsGranted::class), 'plus de droit trésorerie sur toute la classe');

        foreach (['index', 'show', 'validateMovement', 'toggleVerification', 'cancel'] as $methode) {
            self::assertContains('ROLE_TRESORERIE_VOIR', self::exigences($methode), $methode);
        }

        $saisie = self::exigences('new');
        self::assertCount(1, $saisie);
        self::assertInstanceOf(Expression::class, $saisie[0]);
        self::assertStringContainsString('ROLE_TRESORERIE_SAISIR', (string) $saisie[0]);
        self::assertStringContainsString('ROLE_TRESORERIE_TRANSFERER', (string) $saisie[0]);
    }

    /** @return list<string|Expression> */
    public function testAllerANouvelleDepenseOuvreLeFormulaireDesDepenses(): void
    {
        $menu = (string) file_get_contents(__DIR__.'/../../../templates/partials/_menu.html.twig');

        // « Aller à… › Nouveau › Dépense » : Finances › Dépenses (reçu, approbation), pas la saisie d'un mouvement.
        self::assertMatchesRegularExpression('/path\(\'app_finances_depenses\'\) }}#saisir-depense"[^>]*><small>Nouveau<\/small>Dépense</', $menu);
        self::assertDoesNotMatchRegularExpression('/app_mouvement_tresorerie_new[^>]*><small>Nouveau<\/small>Dépense</', $menu);
    }

    private static function exigences(string $methode): array
    {
        return array_map(
            static fn (\ReflectionAttribute $a): string|Expression => $a->newInstance()->attribute,
            (new \ReflectionMethod(MouvementTresorerieController::class, $methode))->getAttributes(IsGranted::class),
        );
    }

    /** @return list<string> rôles de la première règle access_control qui correspond */
    private static function roles(string $chemin): array
    {
        $config = Yaml::parseFile(\dirname(__DIR__, 3).'/config/packages/security.yaml', Yaml::PARSE_CUSTOM_TAGS);
        foreach ($config['security']['access_control'] as $regle) {
            if (preg_match('{'.$regle['path'].'}', $chemin)) {
                return (array) $regle['roles'];
            }
        }
        self::fail('Aucune règle pour '.$chemin);
    }
}
