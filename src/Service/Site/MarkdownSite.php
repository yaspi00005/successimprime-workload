<?php

namespace App\Service\Site;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Markdown simplifié des pages du site vitrine, rendu côté serveur.
 *
 * Tout le texte est échappé : un HTML tapé dans l'admin s'affiche tel
 * quel, il n'est jamais interprété. Ce qui est compris :
 *  - « ## Titre » (section), « ### Titre » (carte ou question) ; « # » vaut
 *    « ## » (le seul h1 de la page est dans le bandeau) ;
 *  - paragraphes séparés par une ligne vide ; une ligne qui finit par deux
 *    espaces ou « \ » passe à la ligne ;
 *  - « - » liste à coches, « 1. » étapes numérotées, « > » encadré ;
 *  - **gras**, *italique* ou _italique_, [lien](https://…) (http, https,
 *    adresse du site, mailto:, tel:) ;
 *  - une ligne « Œillets · Ourlets · Découpe » (3 éléments ou plus séparés
 *    par « · ») devient une rangée de pastilles ;
 *  - dans une carte, une première ligne tout en italique (*Grand format*)
 *    devient son étiquette.
 */
final class MarkdownSite
{
    /** Au-delà, le texte est coupé : une page n'a pas à être plus longue. */
    public const LONGUEUR_MAX = 100_000;

    private const LIEN_DEBUT = "\u{E000}";
    private const LIEN_FIN = "\u{E001}";

    private static ?AsciiSlugger $slugger = null;

    /**
     * Le texte découpé en blocs, chacun avec son HTML déjà échappé.
     *
     * @return list<array{type: string, html?: string, texte?: string, niveau?: int, elements?: list<string>}>
     */
    public static function blocs(?string $markdown): array
    {
        $lignes = preg_split('/\R/u', self::nettoyer((string) $markdown)) ?: [];
        $n = \count($lignes);
        $blocs = [];

        for ($i = 0; $i < $n;) {
            $t = trim($lignes[$i]);
            if ('' === $t) {
                ++$i;
                continue;
            }
            if (preg_match('/^(#{1,6})\s+(.+?)\s*#*$/u', $t, $m)) {
                $blocs[] = ['type' => 'titre', 'niveau' => min(4, max(2, \strlen($m[1]))), 'texte' => $m[2], 'html' => self::enLigne($m[2])];
                ++$i;
                continue;
            }
            if (null !== self::puce($t) || null !== self::numero($t)) {
                $numerotee = null === self::puce($t);
                [$elements, $i] = self::liste($lignes, $i, $numerotee);
                $blocs[] = ['type' => $numerotee ? 'etapes' : 'liste', 'elements' => $elements];
                continue;
            }
            if (str_starts_with($t, '>')) {
                $morceaux = [];
                while ($i < $n && str_starts_with(trim($lignes[$i]), '>')) {
                    $morceaux[] = preg_replace('/^>\s?/u', '', trim($lignes[$i]));
                    ++$i;
                }
                $blocs[] = ['type' => 'note', 'texte' => trim(implode("\n", $morceaux)), 'html' => self::paragraphe($morceaux)];
                continue;
            }

            $morceaux = [];
            while ($i < $n && '' !== trim($lignes[$i]) && !self::debutDeBloc(trim($lignes[$i]))) {
                $morceaux[] = $lignes[$i];
                ++$i;
            }
            $texte = trim(implode("\n", array_map('trim', $morceaux)));
            $pastilles = self::pastilles($texte);
            $blocs[] = null !== $pastilles
                ? ['type' => 'pastilles', 'texte' => $texte, 'elements' => array_map(self::enLigne(...), $pastilles)]
                : ['type' => 'paragraphe', 'texte' => $texte, 'html' => self::paragraphe($morceaux)];
        }

        return $blocs;
    }

    /** Le texte entier en HTML sûr. */
    public static function html(?string $markdown): string
    {
        return self::rendreBlocs(self::blocs($markdown));
    }

    /**
     * @param list<array<string, mixed>> $blocs
     */
    public static function rendreBlocs(array $blocs): string
    {
        $html = '';
        foreach ($blocs as $bloc) {
            $html .= match ($bloc['type']) {
                'titre' => \sprintf('<h%1$d>%2$s</h%1$d>', $bloc['niveau'], $bloc['html']),
                'liste' => '<ul>'.self::elements($bloc['elements']).'</ul>',
                'etapes' => '<ol>'.self::elements($bloc['elements']).'</ol>',
                'note' => '<blockquote><p>'.$bloc['html'].'</p></blockquote>',
                'pastilles' => '<ul class="sc-pastilles">'.self::elements($bloc['elements']).'</ul>',
                default => '<p>'.$bloc['html'].'</p>',
            }."\n";
        }

        return $html;
    }

    /**
     * Le texte rangé pour les gabarits : l'introduction (avant le premier
     * « ## »), puis les sections, chacune avec ses blocs et ses cartes
     * (« ### »). Une carte va jusqu'à la carte ou la section suivante.
     *
     * @return array{intro: list<array<string, mixed>>, sections: list<array{titre: string, html: string, ancre: string, blocs: list<array<string, mixed>>, cartes: list<array{titre: string, html: string, ancre: string, etiquette: ?string, blocs: list<array<string, mixed>>}>}>}
     */
    public static function sections(?string $markdown): array
    {
        $intro = [];
        $sections = [];
        $section = null;
        $carte = null;
        $ancres = [];
        $ancre = static function (string $titre) use (&$ancres): string {
            $base = self::ancre($titre) ?: 'section';
            $ancres[$base] = ($ancres[$base] ?? 0) + 1;

            return 1 === $ancres[$base] ? $base : $base.'-'.$ancres[$base];
        };
        $fermerCarte = static function () use (&$section, &$carte): void {
            if (null !== $carte) {
                $section['cartes'][] = $carte;
                $carte = null;
            }
        };

        foreach (self::blocs($markdown) as $bloc) {
            $niveau = 'titre' === $bloc['type'] ? $bloc['niveau'] : 0;
            if (2 === $niveau || (3 === $niveau && null === $section)) {
                $fermerCarte();
                if (null !== $section) {
                    $sections[] = $section;
                }
                $titre = 2 === $niveau ? self::texteDe($bloc['html']) : '';
                $section = ['titre' => $titre, 'html' => 2 === $niveau ? $bloc['html'] : '', 'ancre' => $ancre($titre), 'blocs' => [], 'cartes' => []];
                if (2 === $niveau) {
                    continue;
                }
            }
            if (3 === $niveau) {
                $fermerCarte();
                $titre = self::texteDe($bloc['html']);
                $carte = ['titre' => $titre, 'html' => $bloc['html'], 'ancre' => $ancre($titre), 'etiquette' => null, 'blocs' => []];
                continue;
            }
            if (null !== $carte) {
                if ([] === $carte['blocs'] && null === $carte['etiquette'] && 'paragraphe' === $bloc['type'] && preg_match('/^([*_])([^*_\n]{1,40})\1(?:\n|$)/u', $bloc['texte'], $m)) {
                    $carte['etiquette'] = trim($m[2]);
                    // Le texte tapé juste sous l'étiquette reste le premier paragraphe de la carte.
                    $suite = trim(mb_substr($bloc['texte'], mb_strlen($m[0])));
                    if ('' === $suite) {
                        continue;
                    }
                    $bloc = ['type' => 'paragraphe', 'texte' => $suite, 'html' => self::paragraphe(explode("\n", $suite))];
                }
                $carte['blocs'][] = $bloc;
            } elseif (null !== $section) {
                $section['blocs'][] = $bloc;
            } else {
                $intro[] = $bloc;
            }
        }
        $fermerCarte();
        if (null !== $section) {
            $sections[] = $section;
        }

        return ['intro' => $intro, 'sections' => $sections];
    }

    /** Texte brut, sans balise (description, données structurées). */
    public static function texte(?string $markdown): string
    {
        return self::texteDe(self::html($markdown));
    }

    /**
     * @param list<array<string, mixed>> $blocs
     */
    public static function texteDesBlocs(array $blocs): string
    {
        return self::texteDe(self::rendreBlocs($blocs));
    }

    /** Une ligne : texte échappé, puis gras, italique et liens. */
    public static function enLigne(string $texte): string
    {
        $html = htmlspecialchars($texte, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
        $liens = [];
        $html = (string) preg_replace_callback('/\[([^\]\n]+)\]\(([^)\s]+)\)/u', static function (array $m) use (&$liens): string {
            $url = self::lienSur(html_entity_decode($m[2], \ENT_QUOTES | \ENT_HTML5, 'UTF-8'));
            $libelle = self::emphase($m[1]);
            $liens[] = null === $url ? $libelle : \sprintf(
                '<a href="%s"%s>%s</a>',
                htmlspecialchars($url, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8'),
                preg_match('#^https?://#i', $url) ? ' rel="noopener"' : '',
                $libelle,
            );

            return self::LIEN_DEBUT.(\count($liens) - 1).self::LIEN_FIN;
        }, $html);
        $html = self::emphase($html);

        return (string) preg_replace_callback('/'.self::LIEN_DEBUT.'(\d+)'.self::LIEN_FIN.'/u', static fn (array $m): string => $liens[(int) $m[1]] ?? '', $html);
    }

    /**
     * Adresse de lien permise, ou null : web (http, https), page du site,
     * ancre, e-mail ou téléphone. Jamais javascript: ni data:.
     */
    public static function lienSur(string $url): ?string
    {
        $url = trim($url);
        $permis = [
            '#^https?://[^\s<>"\'`]+$#i',
            '#^/(?!/)[^\s<>"\'`]*$#',
            '#^\#[A-Za-z0-9_-]+$#',
            '#^mailto:[^\s<>"\'`@]+@[^\s<>"\'`@]+$#i',
            '#^tel:\+?[0-9][0-9 .-]{3,20}$#',
        ];
        foreach ($permis as $motif) {
            if (preg_match($motif, $url)) {
                return $url;
            }
        }

        return null;
    }

    /** « Fonds perdus & marges » donne « fonds-perdus-marges ». */
    public static function ancre(string $texte): string
    {
        self::$slugger ??= new AsciiSlugger('fr');

        return trim(mb_substr(self::$slugger->slug(mb_strtolower($texte))->toString(), 0, 80), '-');
    }

    private static function nettoyer(string $markdown): string
    {
        $markdown = mb_substr($markdown, 0, self::LONGUEUR_MAX);
        // Caractères de contrôle et marques internes retirés ; les tabulations comptent comme des espaces.
        $markdown = (string) preg_replace('/[\x{E000}\x{E001}\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace("\t", '    ', $markdown));

        return $markdown;
    }

    private static function emphase(string $html): string
    {
        $html = (string) preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $html);
        $html = (string) preg_replace('/(?<![*\p{L}\p{N}])\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?![*\p{L}\p{N}])/u', '<em>$1</em>', $html);

        return (string) preg_replace('/(?<![\p{L}\p{N}_])_(?=\S)(.+?)(?<=\S)_(?![\p{L}\p{N}_])/u', '<em>$1</em>', $html);
    }

    /** Lignes d'un paragraphe : deux espaces ou « \ » en fin de ligne passent à la ligne. */
    private static function paragraphe(array $lignes): string
    {
        $texte = '';
        $separateur = '';
        foreach ($lignes as $ligne) {
            $texte .= $separateur.rtrim(trim($ligne), '\\');
            $separateur = str_ends_with($ligne, '  ') || str_ends_with(rtrim($ligne), '\\') ? "\n" : ' ';
        }

        return str_replace("\n", '<br>', self::enLigne(trim($texte)));
    }

    private static function puce(string $ligne): ?string
    {
        return preg_match('/^[-*+]\s+(.+)$/u', $ligne, $m) ? $m[1] : null;
    }

    private static function numero(string $ligne): ?string
    {
        return preg_match('/^\d{1,3}[.)]\s+(.+)$/u', $ligne, $m) ? $m[1] : null;
    }

    private static function debutDeBloc(string $ligne): bool
    {
        return preg_match('/^#{1,6}\s/u', $ligne) || str_starts_with($ligne, '>') || null !== self::puce($ligne) || null !== self::numero($ligne);
    }

    /**
     * Éléments d'une liste, à partir de la ligne $i. Une ligne en retrait
     * continue l'élément ; une ligne vide suivie d'un élément du même genre
     * ne coupe pas la liste.
     *
     * @param list<string> $lignes
     *
     * @return array{0: list<string>, 1: int}
     */
    private static function liste(array $lignes, int $i, bool $numerotee): array
    {
        $n = \count($lignes);
        $elements = [];
        while ($i < $n) {
            $t = trim($lignes[$i]);
            $contenu = $numerotee ? self::numero($t) : self::puce($t);
            if (null !== $contenu) {
                $elements[] = [$contenu];
                ++$i;
                continue;
            }
            if ('' !== $t && [] !== $elements && preg_match('/^\s+/', $lignes[$i]) && !self::debutDeBloc($t)) {
                $elements[\count($elements) - 1][] = $t;
                ++$i;
                continue;
            }
            if ('' === $t) {
                $j = $i;
                while ($j < $n && '' === trim($lignes[$j])) {
                    ++$j;
                }
                if ($j < $n && null !== ($numerotee ? self::numero(trim($lignes[$j])) : self::puce(trim($lignes[$j])))) {
                    $i = $j;
                    continue;
                }
            }
            break;
        }

        return [array_map(static fn (array $l): string => self::paragraphe($l), $elements), $i];
    }

    /**
     * « Œillets · Ourlets · Découpe » : 3 éléments courts ou plus.
     *
     * @return list<string>|null
     */
    private static function pastilles(string $texte): ?array
    {
        if (str_contains($texte, "\n")) {
            return null;
        }
        $elements = array_map('trim', preg_split('/\s+·\s+/u', $texte) ?: []);
        if (\count($elements) < 3) {
            return null;
        }
        foreach ($elements as $element) {
            if ('' === $element || mb_strlen($element) > 40) {
                return null;
            }
        }

        return $elements;
    }

    /** @param list<string> $elements */
    private static function elements(array $elements): string
    {
        return implode('', array_map(static fn (string $e): string => '<li>'.$e.'</li>', $elements));
    }

    private static function texteDe(string $html): string
    {
        $html = (string) preg_replace('#<br\s*/?>|</(p|li|h[2-4]|blockquote)>#i', ' ', $html);

        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8')));
    }
}
