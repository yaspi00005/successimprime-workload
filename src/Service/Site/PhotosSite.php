<?php

namespace App\Service\Site;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Photos livrées avec le site vitrine (docs/site-vitrine.md, « Photos du
 * site ») :
 * - les visuels de présentation de la marque envoyés par Yaya le
 *   15/10/2026 : ils illustrent l'accueil, les équipements, les
 *   engagements… mais ne sont jamais montrés comme une réalisation ni
 *   comme un client (ni dans Réalisations, ni parmi les partenaires) ;
 * - les vraies photos du local envoyées le 17/10/2026 (LOCAL) : façade,
 *   allée, jardin et entrée, visages et plaques floutés.
 *
 * Fichiers : public/images/site/photos/<nom>-<largeur>.webp, réencodés
 * sans métadonnée, en plusieurs largeurs (le navigateur prend la plus
 * petite qui suffit). Pour remplacer une photo : mêmes noms, mêmes
 * proportions. Une photo absente n'est pas affichée (la page garde son
 * dessin ou son icône). Une image choisie dans Réglages › Site vitrine
 * passe toujours avant la photo livrée.
 */
final class PhotosSite
{
    public const DOSSIER = 'images/site/photos';

    /**
     * Nom => largeurs livrées (320 pour les vignettes des univers sur
     * téléphone), proportions (largeur et hauteur du plus grand fichier),
     * texte alternatif (vide : photo décorative), cadrage quand la photo
     * est recadrée (haut, bas ; sinon le centre) et légende courte (galerie
     * « Notre local »).
     */
    public const PHOTOS = [
        'enseigne-succes-imprim-bamako' => ['largeurs' => [480, 800, 1200], 'largeur' => 1200, 'hauteur' => 675, 'alt' => 'Façade de l\'imprimerie Succès Imprim, sa grande enseigne « Nous imprimons vos succès !! » et des passants dans une rue de Bamako au coucher du soleil'],
        'atelier-impression-grand-format' => ['largeurs' => [320, 480, 800, 1200], 'largeur' => 1200, 'hauteur' => 675, 'alt' => 'Un opérateur en polo bleu sort une bâche aux couleurs vives d\'une imprimante grand format, dans un atelier lumineux qui donne sur la ville'],
        'banniere-vinyle-succes-imprim' => ['largeurs' => [320, 480, 800], 'largeur' => 800, 'hauteur' => 600, 'alt' => 'Une imprimante grand format imprime une bannière en vinyle au logo Succès Imprim'],
        'banniere-grand-format-couleurs' => ['largeurs' => [320, 480, 800], 'largeur' => 800, 'hauteur' => 600, 'alt' => 'Une bannière aux vagues bleues, rouges et jaunes sort d\'une imprimante grand format'],
        'bat-valide-sur-telephone' => ['largeurs' => [480, 800, 1200], 'largeur' => 1200, 'hauteur' => 900, 'alt' => 'Une femme valide un BAT sur son téléphone, devant un ordinateur portable et des dépliants Succès Imprim', 'cadrage' => 'haut'],
        'depliants-imprimes-succes-imprim' => ['largeurs' => [320, 480, 800], 'largeur' => 800, 'hauteur' => 500, 'alt' => 'Dépliants imprimés au logo Succès Imprim sur un bureau, à côté d\'un téléphone qui affiche un BAT validé'],
        't-shirts-dtf-succes-imprim' => ['largeurs' => [320, 480, 800], 'largeur' => 800, 'hauteur' => 800, 'alt' => 'Une femme en polo Succès Imprim montre un t-shirt blanc imprimé au logo, devant des piles de polos et une presse à chaud', 'cadrage' => 'haut'],
        'vitrophanie-porte-vitrine' => ['largeurs' => [320, 480, 800], 'largeur' => 800, 'hauteur' => 600, 'alt' => 'Porte vitrée avec un adhésif au logo Succès Imprim et vitrine habillée de micro-perforé aux couleurs de la marque'],
        'fourgon-publicitaire-succes-imprim' => ['largeurs' => [320, 480, 800], 'largeur' => 800, 'hauteur' => 450, 'alt' => 'Fourgon habillé aux couleurs de Succès Imprim, bleu, rouge et jaune, avec le logo sur le côté'],
        'livraison-moto-bamako' => ['largeurs' => [480, 800, 1200], 'largeur' => 1200, 'hauteur' => 675, 'alt' => 'Un livreur à moto, gilet Succès Imprim, transporte un carton et un tube au logo dans une rue de Bamako au coucher du soleil'],
        'rubans-encre-couleurs' => ['largeurs' => [800, 1200, 1600], 'largeur' => 1600, 'hauteur' => 900, 'alt' => ''],
        // Vraies photos du local (17/10/2026) : personnes et plaques floutées, ombres éclaircies.
        'facade-local-succes-imprim-bamako' => ['largeurs' => [320, 480, 800, 1200], 'largeur' => 1200, 'hauteur' => 675, 'alt' => 'Façade de l\'imprimerie Succès Imprim à Bamako : maison bleu-gris au balcon rond, l\'entrée sous le balcon, entre palmiers et jardin', 'legende' => 'La façade et son balcon rond'],
        'allee-entree-succes-imprim' => ['largeurs' => [320, 480, 800, 1200], 'largeur' => 1200, 'hauteur' => 675, 'alt' => 'Allée pavée qui mène à l\'entrée de l\'imprimerie Succès Imprim, sous le balcon rond, entre les palmiers et le jardin', 'legende' => 'L\'allée qui mène à l\'entrée'],
        'jardin-entree-succes-imprim' => ['largeurs' => [320, 480, 800, 1200], 'largeur' => 1200, 'hauteur' => 675, 'alt' => 'Jardin devant l\'entrée de l\'imprimerie Succès Imprim : terrasse carrelée sous le balcon, palmiers ; les personnes devant la porte sont floutées', 'legende' => 'Le jardin et l\'entrée'],
    ];

    /** Vraies photos du local, dans l'ordre de la galerie « Notre local » (page Contact). */
    public const LOCAL = ['facade-local-succes-imprim-bamako', 'allee-entree-succes-imprim', 'jardin-entree-succes-imprim'];

    /** Photo de la façade : bandeau « Venez nous voir » de l'accueil et de « Qui sommes-nous ? », fiche de l'imprimerie pour Google. */
    public const FACADE = 'facade-local-succes-imprim-bamako';

    /** Photo de l'allée : en grand au-dessus de la carte de la page Contact. */
    public const ALLEE = 'allee-entree-succes-imprim';

    /** Univers de l'accueil (SiteController::FAMILLES) => photos possibles, dans l'ordre de préférence. */
    public const FAMILLES = [
        'grand' => ['banniere-grand-format-couleurs', 'banniere-vinyle-succes-imprim', 'atelier-impression-grand-format'],
        'petit' => ['depliants-imprimes-succes-imprim'],
        'textile' => ['t-shirts-dtf-succes-imprim'],
        'signaletique' => ['vitrophanie-porte-vitrine', 'fourgon-publicitaire-succes-imprim'],
    ];

    /** Mots d'une carte (une machine de « Nos équipements »…) => photos possibles, dans l'ordre de priorité. */
    public const MOTS = [
        't-shirts-dtf-succes-imprim' => ['textile', 'dtf', 't-shirt', 'tee-shirt', 'polo', 'casquette', 'sérigraphie', 'serigraphie', 'flocage', 'presse à chaud'],
        'vitrophanie-porte-vitrine' => ['vitrine', 'vitrophanie', 'vitre', 'adhésif', 'adhesif', 'micro-perfor', 'autocollant', 'roll-up', 'enseigne', 'signalétique', 'signaletique', 'découpe vinyle'],
        'fourgon-publicitaire-succes-imprim' => ['véhicule', 'vehicule', 'covering', 'voiture', 'camion', 'fourgon'],
        'depliants-imprimes-succes-imprim' => ['petit format', 'numérique', 'numerique', 'laser', 'flyer', 'dépliant', 'depliant', 'brochure', 'carte de visite', 'cartes de visite', 'papier'],
        'banniere-vinyle-succes-imprim' => ['grand format', 'bâche', 'bache', 'banderole', 'solvant', 'éco-solvant', 'traceur', 'vinyle'],
        'banniere-grand-format-couleurs' => ['grand format', 'bâche', 'bache', 'banderole', 'solvant', 'éco-solvant', 'traceur', 'vinyle'],
        'atelier-impression-grand-format' => ['grand format', 'bâche', 'bache', 'banderole', 'solvant', 'éco-solvant', 'traceur', 'imprimante'],
    ];

    /** @var array<string, bool> fichiers déjà cherchés sur le disque */
    private array $presents = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/public')] private readonly string $dossierPublic,
    ) {
    }

    /**
     * Photo prête pour la page, ou null si elle n'est pas connue ou si son
     * fichier manque.
     *
     * @return array{nom: string, src: string, fichiers: list<array{chemin: string, largeur: int}>, largeur: int, hauteur: int, alt: string, cadrage: ?string, legende: ?string}|null
     */
    public function photo(?string $nom): ?array
    {
        $definition = self::PHOTOS[$nom] ?? null;
        if (null === $definition || null === $nom) {
            return null;
        }
        $fichiers = [];
        foreach ($definition['largeurs'] as $largeur) {
            $chemin = self::chemin($nom, $largeur);
            if ($this->present($chemin)) {
                $fichiers[] = ['chemin' => $chemin, 'largeur' => $largeur];
            }
        }
        if ([] === $fichiers) {
            return null;
        }
        // Pour un navigateur sans srcset : la largeur de 800 px, sinon la plus proche.
        usort($fichiers, static fn (array $a, array $b): int => $a['largeur'] <=> $b['largeur']);
        $src = $fichiers[0]['chemin'];
        foreach ($fichiers as $f) {
            if ($f['largeur'] <= 800) {
                $src = $f['chemin'];
            }
        }

        return [
            'nom' => $nom,
            'src' => $src,
            'fichiers' => $fichiers,
            'largeur' => $definition['largeur'],
            'hauteur' => $definition['hauteur'],
            'alt' => $definition['alt'],
            'cadrage' => $definition['cadrage'] ?? null,
            'legende' => $definition['legende'] ?? null,
        ];
    }

    /**
     * Galerie « Notre local » : les vraies photos dont les fichiers sont
     * présents, dans l'ordre de LOCAL.
     *
     * @return list<array{nom: string, src: string, fichiers: list<array{chemin: string, largeur: int}>, largeur: int, hauteur: int, alt: string, cadrage: ?string, legende: ?string}>
     */
    public function local(): array
    {
        return array_values(array_filter(array_map($this->photo(...), self::LOCAL)));
    }

    /**
     * Photos des univers de l'accueil, d'après leur famille : la première
     * possible qui n'est pas déjà dans la page, sinon null (l'icône reste).
     *
     * @param list<string> $familles
     * @param list<string> $dejaPrises photos déjà montrées ailleurs dans la page
     *
     * @return list<?string>
     */
    public function pourFamilles(array $familles, array $dejaPrises = []): array
    {
        return $this->choisir(array_map(static fn (string $f): array => self::FAMILLES[$f] ?? [], $familles), $dejaPrises);
    }

    /**
     * Photos d'une liste de cartes, d'après les mots de leur titre, puis du
     * reste de leur texte : chaque photo une seule fois dans la page ; une
     * carte sans photo libre garde son dessin.
     *
     * @param list<array{titre: string, texte?: string}> $cartes
     * @param list<string>                               $dejaPrises
     *
     * @return list<?string>
     */
    public function pourCartes(array $cartes, array $dejaPrises = []): array
    {
        $candidates = [];
        foreach ($cartes as $carte) {
            $candidates[] = array_values(array_unique([...self::selonMots($carte['titre']), ...self::selonMots($carte['texte'] ?? '')]));
        }

        return $this->choisir($candidates, $dejaPrises);
    }

    /**
     * Photo de chaque carte des sections d'une page (MarkdownSite::sections),
     * comme pourCartes : titre de la carte, puis son étiquette et son texte.
     * Rangées comme les sections et leurs cartes : photos[section][carte].
     *
     * @param list<array{cartes?: list<array{titre: string, etiquette?: ?string, blocs?: list<array<string, mixed>>}>}> $sections
     * @param list<string>                                                                                             $dejaPrises
     *
     * @return list<list<?string>>
     */
    public function pourSections(array $sections, array $dejaPrises = []): array
    {
        $cartes = [];
        foreach ($sections as $section) {
            foreach ($section['cartes'] ?? [] as $c) {
                $cartes[] = ['titre' => (string) $c['titre'], 'texte' => trim(($c['etiquette'] ?? '').' '.MarkdownSite::texteDesBlocs($c['blocs'] ?? []))];
            }
        }
        $choix = $this->pourCartes($cartes, $dejaPrises);
        $rangees = [];
        foreach ($sections as $section) {
            $rangees[] = array_splice($choix, 0, \count($section['cartes'] ?? []));
        }

        return $rangees;
    }

    /** @return list<string> photos dont un mot apparaît dans le texte, dans l'ordre de MOTS */
    public static function selonMots(string $texte): array
    {
        $texte = mb_strtolower($texte);
        $photos = [];
        foreach (self::MOTS as $photo => $mots) {
            foreach ($mots as $mot) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($mot, '/').'/u', $texte)) {
                    $photos[] = $photo;
                    break;
                }
            }
        }

        return $photos;
    }

    public static function chemin(string $nom, int $largeur): string
    {
        return self::DOSSIER.'/'.$nom.'-'.$largeur.'.webp';
    }

    /**
     * @param list<list<string>> $candidates par élément, les photos possibles dans l'ordre
     * @param list<string>       $dejaPrises
     *
     * @return list<?string>
     */
    private function choisir(array $candidates, array $dejaPrises): array
    {
        $prises = array_fill_keys($dejaPrises, true);
        $choix = [];
        foreach ($candidates as $possibles) {
            $retenue = null;
            foreach ($possibles as $photo) {
                if (!isset($prises[$photo]) && null !== $this->photo($photo)) {
                    $retenue = $photo;
                    $prises[$photo] = true;
                    break;
                }
            }
            $choix[] = $retenue;
        }

        return $choix;
    }

    private function present(string $chemin): bool
    {
        return $this->presents[$chemin] ??= is_file($this->dossierPublic.'/'.$chemin);
    }
}
