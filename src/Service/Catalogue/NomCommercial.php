<?php

namespace App\Service\Catalogue;

use App\Entity\AUnNomCommercial;
use App\Entity\ProduitConfiguration;
use App\Service\Import\Valeurs;

/**
 * Nom commercial des éléments du catalogue (demande de Yaya du 16/10/2026,
 * docs/noms-commerciaux.md) : « Vinyle adhésif » reste la désignation
 * technique, « Autocollant » est le nom que tout le monde connaît.
 *
 * - Côté client (site, espace client, devis, commande, facture) : le nom
 *   commercial d'abord, la désignation technique à côté, plus petite.
 * - Côté ERP : les deux, la désignation technique d'abord.
 * - Sans nom commercial : la désignation seule, jamais de parenthèses vides.
 * - Recherche : l'un ou l'autre nom, sans tenir compte des accents, des
 *   majuscules ni du pluriel (« autocollants » trouve « Vinyle adhésif »).
 *
 * Tout est statique et pur pour être testé sans base.
 */
final class NomCommercial
{
    /** Longueur de la colonne nom_commercial. */
    public const LONGUEUR = 120;

    /**
     * Nom saisi ou importé : caractères de contrôle et balises retirés,
     * espaces réduits, 120 caractères au plus ; vide : null.
     */
    public static function nettoyer(?string $nom): ?string
    {
        if (null === $nom) {
            return null;
        }
        $nom = strip_tags($nom);
        $nom = (string) preg_replace('/[\x00-\x1F\x7F\x{200B}-\x{200D}\x{FEFF}]+/u', ' ', $nom);
        $nom = trim((string) preg_replace('/\s+/u', ' ', $nom));
        if ('' === $nom) {
            return null;
        }

        return mb_substr($nom, 0, self::LONGUEUR);
    }

    /**
     * Le nom commercial à montrer : null s'il est vide ou s'il redit la
     * désignation (« Bâche » pour « bache »).
     */
    public static function utile(?string $commercial, ?string $technique): ?string
    {
        $commercial = self::nettoyer($commercial);
        if (null === $commercial) {
            return null;
        }

        return self::cle($commercial) === self::cle($technique) ? null : $commercial;
    }

    /**
     * Les deux noms d'un élément pour un client : « nom » en premier (le nom
     * commercial s'il y en a un), « technique » à côté (null sinon).
     *
     * @return array{nom: string, technique: ?string}
     */
    public static function client(?string $commercial, ?string $technique): array
    {
        $technique = trim((string) $technique);
        $commercial = self::utile($commercial, $technique);
        if (null === $commercial) {
            return ['nom' => $technique, 'technique' => null];
        }

        return ['nom' => $commercial, 'technique' => '' !== $technique ? $technique : null];
    }

    /**
     * Les deux noms pour l'ERP : la désignation technique d'abord, le nom
     * commercial ensuite (null s'il n'y en a pas).
     *
     * @return array{nom: string, commercial: ?string}
     */
    public static function interne(?string $commercial, ?string $technique): array
    {
        $technique = trim((string) $technique);

        return ['nom' => $technique, 'commercial' => self::utile($commercial, $technique)];
    }

    /** « Autocollant (Vinyle adhésif) », ou « Vinyle adhésif » seul : pour une liste déroulante, un titre. */
    public static function texteClient(?string $commercial, ?string $technique): string
    {
        $n = self::client($commercial, $technique);

        return null === $n['technique'] ? $n['nom'] : $n['nom'].' ('.$n['technique'].')';
    }

    /** « Vinyle adhésif (Autocollant) », ou « Vinyle adhésif » seul. */
    public static function texteInterne(?string $commercial, ?string $technique): string
    {
        $n = self::interne($commercial, $technique);

        return null === $n['commercial'] ? $n['nom'] : $n['nom'].' ('.$n['commercial'].')';
    }

    /** @return array{nom: string, technique: ?string} */
    public static function clientDe(?AUnNomCommercial $element): array
    {
        return self::client($element?->getNomCommercial(), $element?->getNomTechnique());
    }

    /** @return array{nom: string, commercial: ?string} */
    public static function interneDe(?AUnNomCommercial $element): array
    {
        return self::interne($element?->getNomCommercial(), $element?->getNomTechnique());
    }

    /**
     * Ligne d'un devis, d'une commande ou d'une facture : sa désignation
     * enregistrée reste la référence (elle ne change pas quand le catalogue
     * change) ; le nom commercial du produit ou de l'article vendu passe
     * devant. Une désignation qui le contient déjà (saisie à la main,
     * commande en ligne) est montrée seule.
     *
     * @return array{nom: string, technique: ?string}
     */
    public static function ligne(?string $designation, ?AUnNomCommercial $element): array
    {
        $designation = trim((string) $designation);
        if ('' === $designation) {
            return self::clientDe($element);
        }
        $commercial = self::nettoyer($element?->getNomCommercial());
        if (null === $commercial) {
            return ['nom' => $designation, 'technique' => null];
        }
        // Désignation reprise de la liste de l'ERP : « Vinyle adhésif (Autocollant) ».
        $sansCommercial = self::sansParentheses($designation, $commercial);
        if ('' !== $sansCommercial && $sansCommercial !== $designation) {
            return ['nom' => $commercial, 'technique' => $sansCommercial];
        }
        if (str_contains(' '.self::cle($designation).' ', ' '.self::cle($commercial).' ')) {
            return ['nom' => $designation, 'technique' => null];
        }

        return ['nom' => $commercial, 'technique' => $designation];
    }

    /** La désignation sans la parenthèse qui redit le nom commercial : « Vinyle adhésif (Autocollant) » → « Vinyle adhésif ». */
    private static function sansParentheses(string $designation, string $commercial): string
    {
        $cle = self::cle($commercial);
        $reste = (string) preg_replace_callback('/\s*\(([^()]*)\)/u', static fn (array $m): string => self::cle($m[1]) === $cle ? '' : $m[0], $designation);

        return trim((string) preg_replace('/\s+/u', ' ', $reste));
    }

    /** Ligne en texte : « Autocollant · Vinyle adhésif (100 x 50 cm) ». */
    public static function texteLigne(?string $designation, ?AUnNomCommercial $element): string
    {
        $n = self::ligne($designation, $element);

        return null === $n['technique'] ? $n['nom'] : $n['nom'].' · '.$n['technique'];
    }

    /**
     * Élément du catalogue vendu par une ligne (devis, commande, bon de
     * livraison) : l'article de stock pour une vente d'article, sinon le
     * produit ; rien pour une saisie libre. Une création de l'Atelier 3D
     * donne le produit de sa configuration.
     */
    public static function elementDeLigne(object $ligne): ?AUnNomCommercial
    {
        // Ligne d'un bon de livraison : celle de la commande qu'elle livre.
        if (method_exists($ligne, 'getCommandeDetail')) {
            $detail = $ligne->getCommandeDetail();

            return null !== $detail ? self::elementDeLigne($detail) : null;
        }
        $type = method_exists($ligne, 'getTypeLigne') ? $ligne->getTypeLigne() : null;
        if ('libre' === $type) {
            return null;
        }
        if ('article' === $type && method_exists($ligne, 'getArticle')) {
            return $ligne->getArticle();
        }
        $produit = method_exists($ligne, 'getProduit') ? $ligne->getProduit() : null;
        if ($produit instanceof AUnNomCommercial) {
            return $produit;
        }
        $article = method_exists($ligne, 'getArticle') ? $ligne->getArticle() : null;
        if ($article instanceof AUnNomCommercial) {
            return $article;
        }
        // Création de l'Atelier 3D (« Mes créations ») : le produit de sa configuration.
        $configuration = method_exists($ligne, 'getConfiguration') ? $ligne->getConfiguration() : null;

        return $configuration instanceof ProduitConfiguration ? $configuration->getProduit() : null;
    }

    /**
     * Configuration du catalogue pour un client : produit · support ·
     * format, chaque partie sous son nom commercial ; « technique » reprend
     * les désignations remplacées. Un support qui porte le même nom
     * commercial que son produit garde sa désignation.
     *
     * @return array{nom: string, technique: ?string, texte: string}
     */
    public static function configuration(?ProduitConfiguration $config): array
    {
        if (null === $config) {
            return ['nom' => '—', 'technique' => null, 'texte' => '—'];
        }
        $noms = [];
        $techniques = [];
        $textes = [];
        $produit = self::clientDe($config->getProduit());
        $parties = [$produit];
        if (null !== $config->getSupport()) {
            $support = self::clientDe($config->getSupport());
            if (null !== $produit['technique'] && null !== $support['technique'] && self::cle($support['nom']) === self::cle($produit['nom'])) {
                $support = ['nom' => $support['technique'], 'technique' => null];
            }
            $parties[] = $support;
        }
        if (null !== $config->getFormat()) {
            $parties[] = ['nom' => (string) $config->getFormat()->getNom(), 'technique' => null];
        }
        foreach ($parties as $i => $p) {
            if ('' === $p['nom'] && 0 < $i) {
                continue;
            }
            $noms[] = $p['nom'];
            $textes[] = null === $p['technique'] ? $p['nom'] : $p['nom'].' ('.$p['technique'].')';
            if (null !== $p['technique']) {
                $techniques[] = $p['technique'];
            }
        }

        return [
            'nom' => implode(' · ', $noms),
            'technique' => [] === $techniques ? null : implode(' · ', $techniques),
            'texte' => implode(' · ', $textes),
        ];
    }

    /** Configuration en texte : « Autocollant (Vinyle adhésif) · Vinyle blanc · Sur mesure ». */
    public static function texteConfiguration(?ProduitConfiguration $config): string
    {
        return self::configuration($config)['texte'];
    }

    /**
     * Configuration pour l'ERP, au format de ProduitConfiguration::__toString
     * avec le nom commercial entre parenthèses : « Vinyle adhésif
     * (Autocollant) — Solvant grand format — Vinyle blanc — Sur mesure ».
     * La liste « Configuration » de la saisie des devis et des commandes la
     * cherche ainsi par l'un ou l'autre nom.
     */
    public static function texteConfigurationInterne(?ProduitConfiguration $config): string
    {
        if (null === $config) {
            return '—';
        }
        $partie = static fn (?AUnNomCommercial $e, string $defaut): string => null === $e || '' === trim($e->getNomTechnique()) ? $defaut : self::texteInterne($e->getNomCommercial(), $e->getNomTechnique());

        return \sprintf(
            '%s — %s — %s — %s',
            $partie($config->getProduit(), 'Produit'),
            $partie($config->getTypeImpression(), 'Type d’impression'),
            $partie($config->getSupport(), 'Support'),
            $config->getFormat()?->getNom() ?? 'Format',
        );
    }

    /**
     * Clé de recherche : les noms donnés, en minuscules, sans accents, sans
     * ponctuation et au singulier (« vinyle adhesif autocollant »). Pour un
     * attribut data-recherche lu par un script (même calcul en JavaScript :
     * public/js/recherche-catalogue.js), ou une comparaison côté serveur.
     */
    public static function cle(?string ...$textes): string
    {
        return trim(implode(' ', array_filter(array_map(static fn (?string $t): string => self::singulier(Valeurs::cle((string) $t)), $textes), static fn (string $t): bool => '' !== $t)));
    }

    /**
     * Mots d'une clé au singulier : le « s » ou le « x » final d'un mot de
     * quatre lettres ou plus tombe (« autocollants », « vinyles »,
     * « panneaux » → « panneau »). Appliqué des deux côtés de la recherche,
     * il suffit que ce soit pareil, pas que ce soit du bon français.
     */
    public static function singulier(string $cle): string
    {
        return (string) preg_replace('/([a-z]{3})[sx](?=\s|$)/', '$1', $cle);
    }

    /**
     * La recherche se trouve-t-elle dans l'un des noms ? Sans accents, ni
     * majuscules, ni pluriel ; une recherche vide trouve tout.
     */
    public static function correspond(?string $recherche, ?string ...$textes): bool
    {
        $mot = self::cle($recherche);
        if ('' === $mot) {
            return true;
        }
        foreach ($textes as $texte) {
            if (null !== $texte && str_contains(self::cle($texte), $mot)) {
                return true;
            }
        }

        return false;
    }

    /** L'un des deux noms de l'élément contient-il la recherche ? */
    public static function elementCorrespond(?string $recherche, ?AUnNomCommercial $element): bool
    {
        return null !== $element && self::correspond($recherche, $element->getNomTechnique(), $element->getNomCommercial());
    }
}
