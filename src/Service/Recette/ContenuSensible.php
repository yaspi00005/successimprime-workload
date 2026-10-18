<?php

namespace App\Service\Recette;

/**
 * Repère dans une page ce qui ne doit pas s'afficher sans le droit qui va
 * avec : montants en francs (classés selon les mots qui les précèdent),
 * photos et journées de pointage. C'est un tri pour la recette : chaque montant trouvé
 * est relu par une personne avant d'être déclaré fuite.
 */
final class ContenuSensible
{
    /** Montant écrit à la française : « 500 000 F », « 12 500 FCFA », « 0 F ». */
    private const MONTANT = '/(?<![\p{L}\p{N},.\/:])(\d{1,3}(?:[ \x{00A0}\x{202F}.]\d{3})+|\d+)(?:,\d+)?[ \x{00A0}\x{202F}]?(?:F CFA|FCFA|F)(?![\p{L}\p{N}])/u';

    /**
     * Sorte de montant d'après les mots qui le précèdent (60 caractères),
     * du plus précis au plus général, avec le droit qui permet de le voir.
     * « vente » sert quand aucun mot ne correspond.
     *
     * @var array<string, array{motif: string, droits: list<string>}>
     */
    public const CATEGORIES = [
        'marge' => ['motif' => '/marge|co[uû]t(s)? de revient|co[uû]t matière|prix d.achat|rentabilit/iu', 'droits' => ['ROLE_COUT_MARGE']],
        'salaire' => ['motif' => '/(?<!hors )salaire|net à payer|\bbrut\b|bulletin|\bpaie\b|avance sur|retenue/iu', 'droits' => ['ROLE_RH']],
        'tresorerie' => ['motif' => '/trésorerie|solde (du|des) compte|banque|orange money|dépense|budget|fournisseur|décaissement|virement/iu', 'droits' => ['ROLE_TRESORERIE_VOIR']],
        'chiffre' => ['motif' => '/chiffre d.affaires|\bCA\b|objectif|facturé (ce|du) mois|panier moyen|encaissé (ce|du) mois|créances/iu', 'droits' => ['ROLE_STATS_GLOBAL', 'ROLE_STATS_VENTES', 'ROLE_STATS_PERSO']],
        'paiement' => ['motif' => '/paiement|payé|encaiss|reste à payer|acompte|versé|solde|caisse/iu', 'droits' => ['ROLE_PAIEMENT_VOIR', 'ROLE_PAIEMENT_ENCAISSER']],
        'vente' => ['motif' => '/./u', 'droits' => ['ROLE_COMMANDE', 'ROLE_DEVIS', 'ROLE_FACTURE', 'ROLE_PAIEMENT_VOIR', 'ROLE_PAIEMENT_ENCAISSER']],
    ];

    /** Droits qui donnent à voir au moins une sorte de montant. */
    public const DROITS_MONTANTS = [
        'ROLE_COMMANDE', 'ROLE_DEVIS', 'ROLE_FACTURE', 'ROLE_PAIEMENT_VOIR', 'ROLE_PAIEMENT_ENCAISSER',
        'ROLE_TRESORERIE_VOIR', 'ROLE_TRESORERIE_SAISIR', 'ROLE_STATS_GLOBAL', 'ROLE_STATS_VENTES', 'ROLE_STATS_PERSO',
        'ROLE_COUT_MARGE', 'ROLE_COMPTABILITE', 'ROLE_RH',
    ];

    /** Adresse d'une photo de pointage (PointerController::voirPhoto). */
    private const PHOTO_POINTAGE = '#/pointer/photo/(\d+)/(arrivee|depart)#';

    /** Journée de pointage affichée (data-si-pointage : Mon pointage, pointages du jour, fiche du mois). */
    private const JOURNEE_POINTAGE = '#\bdata-si-pointage="(\d+)"#';

    /** Texte visible d'une page HTML, sans scripts, styles ni balises, espaces simplifiés. */
    public static function texteVisible(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|template|svg|noscript)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('#<!--.*?-->#s', ' ', $html);
        // Les cellules et les blocs restent séparés une fois les balises retirées.
        $html = (string) preg_replace('#<(br|/p|/div|/td|/th|/li|/h\d|/tr|/span|/a|/label|/option)\b[^>]*>#i', '$0 ', $html);
        $texte = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $texte));
    }

    /**
     * Montants trouvés dans un texte, chacun avec ce qui le précède (60
     * caractères au plus, sans remonter au montant d'avant) et sa sorte.
     *
     * @return list<array{montant: string, contexte: string, categorie: string}>
     */
    public static function montants(string $texte): array
    {
        if (!preg_match_all(self::MONTANT, $texte, $m, \PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $trouves = [];
        $fin = 0;
        foreach ($m[0] as [$montant, $position]) {
            // Ce qui précède, sans remonter au-delà du montant précédent.
            $debut = max($fin, $position - 60);
            $avant = mb_scrub(substr($texte, $debut, $position - $debut), 'UTF-8');
            $fin = $position + \strlen($montant);
            $trouves[] = [
                'montant' => trim($montant),
                'contexte' => trim($avant).' '.trim($montant),
                'categorie' => self::categorie($avant),
            ];
        }

        return $trouves;
    }

    /**
     * Sorte du montant : celle dont un mot est le plus près du montant (à
     * égalité, la plus précise) ; « vente » sans aucun mot.
     */
    public static function categorie(string $avant): string
    {
        $meilleure = 'vente';
        $fin = -1;
        foreach (self::CATEGORIES as $nom => $definition) {
            if ('vente' === $nom || !preg_match_all($definition['motif'], $avant, $m, \PREG_OFFSET_CAPTURE)) {
                continue;
            }
            $derniere = end($m[0]);
            $position = $derniere[1] + \strlen($derniere[0]);
            if ($position > $fin) {
                $meilleure = $nom;
                $fin = $position;
            }
        }

        return $meilleure;
    }

    /**
     * Montants qu'une personne ne devrait pas voir, d'après ses droits : sans
     * aucun droit sur les montants, tous ; sinon ceux d'une sorte dont elle
     * n'a aucun des droits.
     *
     * @param list<string> $droits rôles de la personne (User::getRoles())
     *
     * @return list<array{montant: string, contexte: string, categorie: string}>
     */
    public static function montantsInterdits(string $texte, array $droits): array
    {
        if (\in_array('ROLE_ADMIN', $droits, true)) {
            return [];
        }
        $aucun = [] === array_intersect(self::DROITS_MONTANTS, $droits);

        return array_values(array_filter(self::montants($texte), static fn (array $m): bool => $aucun || [] === array_intersect(self::CATEGORIES[$m['categorie']]['droits'], $droits)));
    }

    /**
     * Pointages dont la photo est affichée dans la page.
     *
     * @return list<int>
     */
    public static function photosPointage(string $html): array
    {
        preg_match_all(self::PHOTO_POINTAGE, $html, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }

    /**
     * Pointages dont la journée (heures, points) est affichée dans la page.
     *
     * @return list<int>
     */
    public static function journeesPointage(string $html): array
    {
        preg_match_all(self::JOURNEE_POINTAGE, $html, $m);

        return array_values(array_unique(array_map('intval', $m[1])));
    }
}
