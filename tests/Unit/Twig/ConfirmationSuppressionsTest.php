<?php

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Chaque suppression se confirme dans la fenêtre de l'application
 * (docs/corbeille.md, demande de Yaya du 16/10) : un seul mécanisme,
 * l'attribut data-confirmer lu par public/js/confirmation.js, posé à la
 * main ou par confirmer_suppression() dans les gabarits.
 *
 * Ce test relit les gabarits et les scripts : une suppression ajoutée plus
 * tard sans la question ne passe pas.
 */
final class ConfirmationSuppressionsTest extends TestCase
{
    private const RACINE = __DIR__.'/../../..';

    /** Route qui supprime ou retire quelque chose (nom de la route dans path()). */
    private const ROUTE_SUPPRESSION = '~_(supprimer|delete|delete_ajax|retirer|revoquer|ecarter|purger|vider|effacer)$|_supprimer_~';

    /** Texte d'un bouton qui supprime. */
    private const TEXTE_SUPPRESSION = '~^\s*(supprimer|retirer|effacer|révoquer|vider|écarter)\b~iu';

    /**
     * Bouton d'une suppression faite par un script : fiche du catalogue
     * (une ou les cochées), ligne entière d'un devis, d'une commande ou
     * d'une demande d'achat, scène de l'écran TV. Retirer une finition ou
     * un fournisseur dans un formulaire en cours n'est pas une suppression :
     * rien n'est enregistré avant « Enregistrer ».
     */
    private const BOUTON_SCRIPT = '~\b(btn-delete-[a-z-]+|[a-zA-Z]+MassDeleteButton|supprimer-detail|js-supprimer-ligne[a-z-]*|data-supprimer)\b~';

    /** Formulaires dont le bouton parle de retirer sans rien supprimer. */
    private const PAS_DES_SUPPRESSIONS = [
        'app_consommables_ajouter' => '« Retirer du stock » enregistre une sortie de stock',
        'app_mouvement_tresorerie_toggle_verification' => '« Retirer la vérification » décoche une case',
    ];

    /** Balise ouvrante, en sautant les > écrits dans une valeur entre guillemets. */
    private const BALISE = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';

    public function testAucuneBoiteNativeDuNavigateur(): void
    {
        $fautes = [];
        $fichiers = (new Finder())->files()->in([self::RACINE.'/templates', self::RACINE.'/public/js'])->name(['*.twig', '*.js']);
        foreach ($fichiers as $fichier) {
            foreach (explode("\n", $fichier->getContents()) as $n => $ligne) {
                if (preg_match('~(?<![\w.$])(?:window\.)?confirm\s*\(~', $ligne)) {
                    $fautes[] = $fichier->getRelativePathname().':'.($n + 1).' : '.trim($ligne);
                }
                if (str_contains($ligne, 'js-confirm-form')) {
                    $fautes[] = $fichier->getRelativePathname().':'.($n + 1).' : ancienne classe js-confirm-form (sans effet), à remplacer par data-confirmer';
                }
            }
        }

        self::assertSame([], $fautes, 'Une confirmation passe par data-confirmer ou SiConfirmation.demander (public/js/confirmation.js), jamais par la boîte du navigateur.');
    }

    public function testChaqueFormulaireDeSuppressionDemandeConfirmation(): void
    {
        $fautes = [];
        foreach ($this->gabarits() as $chemin => $contenu) {
            preg_match_all('~<form\b('.self::BALISE.')>(.*?)</form>~s', $contenu, $formulaires, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($formulaires as $f) {
                [$ouverture, $corps] = [$f[1][0], $f[2][0]];
                // Une fenêtre <dialog> se ferme sans rien envoyer.
                if (str_contains($ouverture, 'method="dialog"') || !$this->supprime($ouverture, $corps)) {
                    continue;
                }
                if (self::confirme($ouverture) || preg_match('~<(button|input)\b'.self::BALISE.'(data-confirmer|confirmer_suppression\()~', $corps)) {
                    continue;
                }
                $fautes[] = $chemin.':'.(substr_count(substr($contenu, 0, $f[0][1]), "\n") + 1);
            }
        }

        self::assertSame([], $fautes, 'Formulaire de suppression sans data-confirmer ni confirmer_suppression().');
    }

    public function testChaqueBoutonDeSuppressionParScriptDemandeConfirmation(): void
    {
        $fautes = [];
        foreach ($this->gabarits() as $chemin => $contenu) {
            preg_match_all('~<(button|a)\b('.self::BALISE.')>~', $contenu, $boutons, \PREG_SET_ORDER | \PREG_OFFSET_CAPTURE);
            foreach ($boutons as $b) {
                $attributs = $b[2][0];
                if (preg_match(self::BOUTON_SCRIPT, $attributs) && !self::confirme($attributs) && !str_contains($attributs, 'form=')) {
                    $fautes[] = $chemin.':'.(substr_count(substr($contenu, 0, $b[0][1]), "\n") + 1);
                }
            }
        }

        self::assertSame([], $fautes, 'Bouton de suppression (script) sans data-confirmer ni confirmer_suppression().');
    }

    /** Un lien ne supprime jamais : la suppression passe par un formulaire POST avec jeton. */
    public function testAucunLienNeSupprime(): void
    {
        $fautes = [];
        foreach ($this->gabarits() as $chemin => $contenu) {
            preg_match_all('~<a\b'.self::BALISE.'href="\{\{\s*path\(\s*\'([a-z0-9_]+)\'~', $contenu, $liens, \PREG_SET_ORDER);
            foreach ($liens as $l) {
                if (preg_match(self::ROUTE_SUPPRESSION, $l[1])) {
                    $fautes[] = $chemin.' : '.$l[1];
                }
            }
        }

        self::assertSame([], $fautes);
    }

    /** Les deux coques de l'application chargent la fenêtre ; elle n'utilise rien d'un autre site. */
    public function testLaFenetreEstChargeeEtSansCdn(): void
    {
        foreach (['base.html.twig', 'espace/app/_app.html.twig'] as $coque) {
            self::assertStringContainsString("asset('js/confirmation.js')", (string) file_get_contents(self::RACINE.'/templates/'.$coque), $coque);
        }
        $script = (string) file_get_contents(self::RACINE.'/public/js/confirmation.js');
        self::assertDoesNotMatchRegularExpression('~https?://~', $script);
        self::assertStringContainsString("'Escape'", $script, 'Échap annule');
        self::assertStringContainsString('aria-modal', $script);
        self::assertStringContainsString('prefers-reduced-motion', (string) file_get_contents(self::RACINE.'/public/css/confirmation.css'));
    }

    private function supprime(string $ouverture, string $corps): bool
    {
        if (preg_match('~action="\{\{\s*path\(\s*\'([a-z0-9_]+)\'(.*?)\)\s*\}\}"~', $ouverture, $m)) {
            if (isset(self::PAS_DES_SUPPRESSIONS[$m[1]])) {
                return false;
            }
            if (preg_match(self::ROUTE_SUPPRESSION, $m[1]) || preg_match('~action:\s*\'(retirer|supprimer)\'~', $m[2])) {
                return true;
            }
        }
        if (preg_match('~name="supprimer"~', $corps)) {
            return true;
        }
        // Boutons qui envoient ce formulaire (ni type="button", ni form="…" vers un autre).
        preg_match_all('~<button\b('.self::BALISE.')>(.*?)</button>~s', $corps, $boutons, \PREG_SET_ORDER);
        foreach ($boutons as [, $attributs, $texte]) {
            if (str_contains($attributs, 'type="button"') || str_contains($attributs, 'form="')) {
                continue;
            }
            if (preg_match(self::TEXTE_SUPPRESSION, html_entity_decode(strip_tags($texte)))) {
                return true;
            }
        }

        return false;
    }

    private static function confirme(string $attributs): bool
    {
        return str_contains($attributs, 'data-confirmer') || str_contains($attributs, 'confirmer_suppression(');
    }

    /** @return array<string, string> */
    private function gabarits(): array
    {
        $gabarits = [];
        foreach ((new Finder())->files()->in(self::RACINE.'/templates')->name('*.twig') as $fichier) {
            // Les commentaires Twig ne comptent pas.
            $gabarits[$fichier->getRelativePathname()] = (string) preg_replace_callback('~\{#.*?#\}~s', static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $fichier->getContents());
        }
        ksort($gabarits);

        return $gabarits;
    }
}
