<?php

namespace App\Tests\Unit\Service\Guide;

use App\Controller\AideController;
use App\Security\Droits;
use App\Service\Guide\CatalogueGuide;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Routing\AttributeRouteControllerLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Routing\Loader\AttributeDirectoryLoader;
use Symfony\Component\Routing\RouteCollection;

/**
 * Guide utilisateur (docs/guide-utilisateur.md) : chaque fiche a ses
 * droits, son écran, son texte et ses captures ; le filtre par rôle suit
 * les droits des comptes de démonstration.
 */
final class CatalogueGuideTest extends TestCase
{
    /** Droits des rôles de base, tels que la base de démonstration les a (role_utilisateur). */
    private const CAISSE = ['ROLE_CLIENT', 'ROLE_DEVIS', 'ROLE_COMMANDE', 'ROLE_FACTURE', 'ROLE_PAIEMENT_ENCAISSER', 'ROLE_TRESORERIE_SAISIR', 'ROLE_TRESORERIE_TRANSFERER', 'ROLE_JOURNAL_CAISSE', 'ROLE_STATS_PERSO', 'ROLE_STATS_VENTES', 'ROLE_PAIEMENT_VOIR'];
    private const GRAPHISTE = ['ROLE_CLIENT', 'ROLE_DEVIS', 'ROLE_COMMANDE', 'ROLE_FACTURE', 'ROLE_PREPRESSE', 'ROLE_PRODUCTION_VOIR', 'ROLE_PRODUCTION_GERER', 'ROLE_STATS_PERSO'];
    private const PRODUCTION = ['ROLE_PRODUCTION_VOIR', 'ROLE_PRODUCTION_GERER', 'ROLE_STATS_PERSO'];
    private const LIVREUR = ['ROLE_LIVRAISON', 'ROLE_STATS_PERSO'];
    /** demo.awa : rôle production, la production retirée, l'entretien ajouté. */
    private const ENTRETIEN = ['ROLE_STATS_PERSO', 'ROLE_ENTRETIEN'];

    private static function dossier(): string
    {
        return \dirname(__DIR__, 4);
    }

    /**
     * isGranted d'une personne : ses droits, la connexion, sa fiche employé ;
     * l'administrateur hérite de tout (role_hierarchy).
     *
     * @param list<string> $droits
     *
     * @return callable(string): bool
     */
    private static function accorde(array $droits, bool $admin = false, bool $employe = true): callable
    {
        return static fn (string $droit): bool => $admin
            || 'ROLE_USER' === $droit
            || (CatalogueGuide::PERSONNEL === $droit && $employe)
            || \in_array($droit, $droits, true);
    }

    /**
     * @param list<string> $droits
     *
     * @return list<string>
     */
    private static function cles(array $droits, bool $admin = false, bool $employe = true): array
    {
        return array_column(CatalogueGuide::visibles(self::accorde($droits, $admin, $employe), $admin ? ['ROLE_ADMIN'] : $droits), 'cle');
    }

    public function testClesEtCapturesUniques(): void
    {
        $cles = array_column(CatalogueGuide::fiches(), 'cle');
        self::assertSame(array_unique($cles), $cles, 'deux fiches ont la même clé');
        self::assertGreaterThanOrEqual(30, \count($cles));
        // Les adresses fixes du guide ne peuvent pas servir de clé de fiche.
        foreach (['imprimer', 'capture', 'formation'] as $reserve) {
            self::assertNotContains($reserve, $cles);
        }

        $noms = [];
        foreach (CatalogueGuide::fiches() as $fiche) {
            $noms = [...$noms, ...array_column($fiche['captures'], 'nom')];
        }
        self::assertSame(array_unique($noms), $noms, 'une capture sert à deux fiches');
    }

    public function testChaqueFicheEstComplete(): void
    {
        $connus = [...array_keys(Droits::catalogue()), 'ROLE_USER', 'ROLE_ADMIN', CatalogueGuide::PERSONNEL];
        foreach (CatalogueGuide::fiches() as $fiche) {
            $cle = $fiche['cle'];
            self::assertMatchesRegularExpression('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $cle);
            self::assertArrayHasKey($fiche['groupe'], CatalogueGuide::GROUPES, $cle);
            self::assertNotSame('', trim($fiche['titre']), $cle);
            self::assertNotSame('', trim($fiche['but']), $cle);
            // Au moins un droit, et seulement des droits qui existent.
            self::assertNotEmpty($fiche['droits'], $cle.' : aucun droit');
            foreach ($fiche['droits'] as $droit) {
                foreach ((array) $droit as $possible) {
                    self::assertContains($possible, $connus, $cle.' : droit inconnu');
                }
            }
            self::assertNotEmpty($fiche['captures'], $cle.' : aucune capture');
            self::assertLessThanOrEqual(2, \count($fiche['captures']), $cle.' : plus de 2 captures');

            $gabarit = self::dossier().'/templates/aide/fiches/'.$cle.'.html.twig';
            self::assertFileExists($gabarit);
            $texte = (string) file_get_contents($gabarit);
            self::assertStringContainsString('{% block etapes %}', $texte, $cle);
            self::assertStringContainsString('{% block savoir %}', $texte, $cle);
            self::assertStringContainsString('<ol>', $texte, $cle.' : étapes non numérotées');
        }
        // Aucun gabarit orphelin.
        $gabarits = array_map(static fn (string $f): string => basename($f, '.html.twig'), glob(self::dossier().'/templates/aide/fiches/*.html.twig') ?: []);
        sort($gabarits);
        $cles = array_column(CatalogueGuide::fiches(), 'cle');
        sort($cles);
        self::assertSame($cles, $gabarits);
    }

    public function testLesRoutesDesFichesExistent(): void
    {
        $routes = (new AttributeDirectoryLoader(new FileLocator(), new AttributeRouteControllerLoader()))->load(self::dossier().'/src/Controller', 'attribute');
        self::assertInstanceOf(RouteCollection::class, $routes);
        $noms = array_keys($routes->all());

        foreach (CatalogueGuide::fiches() as $fiche) {
            self::assertContains($fiche['route'], $noms, $fiche['cle'].' : route inconnue');
            // « Ouvrir l'écran » : une adresse sans paramètre à remplir.
            $route = $routes->get($fiche['route']);
            foreach ($route->compile()->getPathVariables() as $variable) {
                self::assertTrue($route->hasDefault($variable), $fiche['cle'].' : la route demande « '.$variable.' »');
            }
            // Chaque début d'écran désigne au moins une route.
            foreach ($fiche['ecrans'] ?? [] as $prefixe) {
                self::assertNotEmpty(array_filter($noms, static fn (string $n): bool => str_starts_with($n, $prefixe)), $fiche['cle'].' : écran '.$prefixe);
            }
        }
        foreach (['app_aide_index', 'app_aide_imprimer', 'app_aide_formation', 'app_aide_capture', 'app_aide_fiche'] as $aide) {
            self::assertContains($aide, $noms);
        }
        // « formation » et « imprimer » passent avant la fiche, qui prendrait leur adresse.
        $ordre = array_flip($noms);
        self::assertLessThan($ordre['app_aide_fiche'], $ordre['app_aide_formation']);
        self::assertLessThan($ordre['app_aide_fiche'], $ordre['app_aide_imprimer']);
    }

    public function testLesCapturesExistentHorsDuDossierPublic(): void
    {
        $dossier = self::dossier().AideController::DOSSIER_CAPTURES;
        self::assertStringNotContainsString('/public/', $dossier);

        $total = 0;
        foreach (CatalogueGuide::captures() as $nom => $capture) {
            $fichier = $dossier.'/'.$nom.'.webp';
            self::assertFileExists($fichier);
            self::assertSame('image/webp', (getimagesize($fichier) ?: [])['mime'] ?? null, $nom);
            $largeur = (getimagesize($fichier) ?: [0])[0];
            // Téléphone 390 px, bureau 1440 px.
            self::assertSame($capture['mobile'] ? 390 : 1440, $largeur, $nom);
            $total += (int) filesize($fichier);
        }
        self::assertLessThan(4 * 1024 * 1024, $total, 'les captures dépassent 4 Mo');

        // Aucune image en trop dans le dossier.
        $fichiers = array_map(static fn (string $f): string => basename($f, '.webp'), glob($dossier.'/*.webp') ?: []);
        sort($fichiers);
        $noms = array_keys(CatalogueGuide::captures());
        sort($noms);
        self::assertSame($noms, $fichiers);
    }

    public function testCaisseSansDirectionNiLivreur(): void
    {
        $cles = self::cles(self::CAISSE);

        foreach (['se-connecter', 'devis-faire', 'encaisser', 'facturer-envoyer', 'caisse-cloture', 'caisse-virement', 'depense-saisir', 'encaissement-livreur', 'acces-espace-client', 'pointer'] as $attendue) {
            self::assertContains($attendue, $cles);
        }
        foreach (['tableau-de-bord', 'a-valider', 'finances', 'utilisateur-nouveau', 'import-donnees', 'tournee', 'remise', 'controle-fichiers', 'ma-file', 'entretien-taches'] as $refusee) {
            self::assertNotContains($refusee, $cles);
        }
        // Sans le droit de virer (demo.moussa), pas de fiche du virement.
        self::assertNotContains('caisse-virement', self::cles(array_values(array_diff(self::CAISSE, ['ROLE_TRESORERIE_TRANSFERER']))));
    }

    public function testGraphisteEncaisseSeulementAvecLeDroitAjoute(): void
    {
        $cles = self::cles(self::GRAPHISTE);
        foreach (['devis-faire', 'devis-en-commande', 'facturer-envoyer', 'controle-fichiers', 'poste-bat', 'montage', 'creations-studio', 'tableau-production'] as $attendue) {
            self::assertContains($attendue, $cles);
        }
        foreach (['encaisser', 'caisse-cloture', 'encaissements-suivre', 'a-valider', 'tournee'] as $refusee) {
            self::assertNotContains($refusee, $cles);
        }

        $avecEncaissements = self::cles([...self::GRAPHISTE, 'ROLE_PAIEMENT_ENCAISSER']);
        self::assertContains('encaisser', $avecEncaissements);
        self::assertSame(['premiers-pas', 'graphiste', 'caisse', 'mon-espace'], CatalogueGuide::groupesAImprimer('ROLE_GRAPHISTE', ['ROLE_PAIEMENT_ENCAISSER']));
    }

    public function testLivreurProductionEntretien(): void
    {
        $livreur = self::cles(self::LIVREUR);
        self::assertContains('tournee', $livreur);
        self::assertContains('remise', $livreur);
        self::assertContains('pointer', $livreur);
        foreach (['devis-faire', 'client-trouver-creer', 'encaisser', 'encaissement-livreur', 'ma-file', 'a-valider'] as $refusee) {
            self::assertNotContains($refusee, $livreur);
        }

        $production = self::cles(self::PRODUCTION);
        self::assertContains('ma-file', $production);
        self::assertContains('planning-machines', $production);
        self::assertNotContains('devis-faire', $production);
        self::assertNotContains('controle-fichiers', $production);

        $entretien = self::cles(self::ENTRETIEN);
        self::assertContains('entretien-taches', $entretien);
        self::assertNotContains('ma-file', $entretien);
        // Premiers pas : seulement la connexion, le reste demande les ventes.
        self::assertSame(['se-connecter'], array_values(array_filter($entretien, static fn (string $c): bool => 'premiers-pas' === CatalogueGuide::fiche($c)['groupe'])));
    }

    /** Fiches du 19-20/10 : facturation, hors imprimerie, achats, prépresse par commande. */
    public function testFichesDesNouveauxEcransParRole(): void
    {
        $caisse = self::cles(self::CAISSE);
        foreach (['factures-liste', 'facture-paiement', 'avoir-faire', 'documents-lies', 'hors-imprimerie'] as $attendue) {
            self::assertContains($attendue, $caisse);
        }
        foreach (['fournisseurs', 'commande-achat', 'reception-achat', 'statistiques-activite', 'prepresse-depot', 'prepresse-relances'] as $refusee) {
            self::assertNotContains($refusee, $caisse);
        }

        // Le graphiste facture sans encaisser : la liste, l'avoir et les documents liés, pas le paiement.
        $graphiste = self::cles(self::GRAPHISTE);
        foreach (['prepresse-depot', 'prepresse-tableau', 'prepresse-relances', 'prepresse-retirer', 'factures-liste', 'documents-lies', 'hors-imprimerie'] as $attendue) {
            self::assertContains($attendue, $graphiste);
        }
        foreach (['facture-paiement', 'fournisseurs', 'reception-achat'] as $refusee) {
            self::assertNotContains($refusee, $graphiste);
        }

        foreach ([self::PRODUCTION, self::LIVREUR] as $droits) {
            $cles = self::cles($droits);
            foreach (['factures-liste', 'facture-paiement', 'avoir-faire', 'hors-imprimerie', 'prepresse-depot', 'fournisseurs', 'commande-achat', 'reception-achat', 'statistiques-activite'] as $refusee) {
                self::assertNotContains($refusee, $cles);
            }
        }

        // Achats : la Direction seule ; les statistiques par activité avec les statistiques globales.
        $direction = self::cles([], true);
        foreach (['fournisseurs', 'commande-achat', 'reception-achat', 'statistiques-activite'] as $attendue) {
            self::assertContains($attendue, $direction);
            self::assertSame('direction', CatalogueGuide::fiche($attendue)['groupe']);
        }
        self::assertContains('statistiques-activite', self::cles(['ROLE_STATS_GLOBAL']));

        // « ? Aide » depuis l'avoir ou la prépresse propose la bonne fiche.
        self::assertContains('avoir-faire', array_column(CatalogueGuide::pourEcran(CatalogueGuide::visibles(self::accorde(self::CAISSE), self::CAISSE), 'app_factures_avoir'), 'cle'));
        self::assertContains('prepresse-retirer', array_column(CatalogueGuide::pourEcran(CatalogueGuide::visibles(self::accorde(self::GRAPHISTE), self::GRAPHISTE), 'app_controle_pre_presse_retirees'), 'cle'));
    }

    public function testMonEspaceDemandeUneFicheEmploye(): void
    {
        self::assertContains('pointer', self::cles(self::LIVREUR));
        self::assertNotContains('pointer', self::cles(self::LIVREUR, false, false));
        self::assertNotContains('absence-conge', self::cles(self::LIVREUR, false, false));
    }

    public function testLaDirectionVoitTout(): void
    {
        self::assertSame(array_column(CatalogueGuide::fiches(), 'cle'), self::cles([], true));
    }

    public function testModeVoirGardeSeulementLesFichesDeConsultation(): void
    {
        $lecture = ['ROLE_CLIENT', 'ROLE_DEVIS', 'ROLE_COMMANDE', 'ROLE_FACTURE', Droits::LECTURE_VENTES];
        $cles = self::cles($lecture);

        self::assertContains('ventes-suivre', $cles);
        self::assertNotContains('devis-faire', $cles);
        self::assertNotContains('devis-en-commande', $cles);
        self::assertNotContains('facturer-envoyer', $cles);
    }

    public function testFicheSansDroitJamaisVisible(): void
    {
        $fiche = CatalogueGuide::fiche('se-connecter');
        self::assertNotNull($fiche);
        $fiche['droits'] = [];
        self::assertFalse(CatalogueGuide::autorisee($fiche, self::accorde([], true)));
        self::assertNull(CatalogueGuide::fiche('inconnue'));
    }

    public function testGroupesDeMonGuideEtOrdre(): void
    {
        self::assertSame(['premiers-pas', 'caisse', 'mon-espace'], CatalogueGuide::groupesAImprimer('ROLE_CAISSE_COMMANDE'));
        self::assertSame(['premiers-pas', 'livreur', 'mon-espace'], CatalogueGuide::groupesAImprimer('ROLE_LIVREUR', ['ROLE_STATS_PERSO']));
        self::assertSame(['premiers-pas', 'production', 'entretien', 'mon-espace'], CatalogueGuide::groupesAImprimer('ROLE_PRODUCTION', ['ROLE_ENTRETIEN']));
        // Rôle créé dans « Rôles et menus » : tout ce qui est visible.
        self::assertNull(CatalogueGuide::groupesAImprimer('ROLE_SUR_MESURE'));
        self::assertNull(CatalogueGuide::groupesAImprimer(null));

        $fiches = CatalogueGuide::visibles(self::accorde(self::GRAPHISTE), self::GRAPHISTE);
        // Le graphiste ouvre aussi les fiches de vente de la caisse (commande, client), pas celles de l'argent.
        self::assertSame(['graphiste', 'premiers-pas', 'caisse', 'production', 'mon-espace'], array_keys(CatalogueGuide::parGroupe($fiches, ['graphiste'])));
        self::assertSame(['premiers-pas', 'caisse', 'graphiste', 'production', 'mon-espace'], array_keys(CatalogueGuide::parGroupe($fiches)));
    }

    public function testFichesPourLEcranQuitte(): void
    {
        $fiches = CatalogueGuide::visibles(self::accorde(self::CAISSE), self::CAISSE);

        self::assertContains('encaisser', array_column(CatalogueGuide::pourEcran($fiches, 'app_caisse_index'), 'cle'));
        self::assertContains('devis-faire', array_column(CatalogueGuide::pourEcran($fiches, 'app_devis_new'), 'cle'));
        self::assertSame([], CatalogueGuide::pourEcran($fiches, ''));
        self::assertSame([], CatalogueGuide::pourEcran($fiches, null));
    }

    public function testLeTexteDesFichesNeDonneAucunMontant(): void
    {
        // Les fiches s'impriment et s'affichent à des rôles sans les montants : pas de somme écrite.
        foreach (CatalogueGuide::fiches() as $fiche) {
            $texte = (string) file_get_contents(self::dossier().'/templates/aide/fiches/'.$fiche['cle'].'.html.twig');
            self::assertDoesNotMatchRegularExpression('/\d[\d\s\x{202F}\x{00A0}]*\s?(F|FCFA)\b/u', $texte, $fiche['cle']);
        }
    }
}
