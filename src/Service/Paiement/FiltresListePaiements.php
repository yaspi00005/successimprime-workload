<?php

namespace App\Service\Paiement;

use App\Entity\Paiements;

/**
 * Filtres de la liste des paiements, réglés par les petites flèches des
 * en-têtes (docs/filtres-tableaux.md) : mode, statut, compte, période,
 * montant minimum et maximum, tri. Ils arrivent dans l'adresse et sont
 * nettoyés ici : une valeur inconnue ou mal écrite est ignorée.
 *
 * La liste montre les 100 derniers paiements ; dès qu'un filtre ou une
 * recherche est posé, elle va chercher dans tous les paiements (500 au
 * plus), pour que le filtre ne se limite pas aux lignes déjà affichées.
 */
final class FiltresListePaiements
{
    public const TRI_PAR_DEFAUT = 'recent';

    /** Tris possibles : du plus récent (par défaut), du plus ancien, par montant. */
    public const TRIS = ['recent', 'ancien', 'montant_desc', 'montant_asc'];

    /**
     * @param array<string, mixed> $parametres paramètres de l'adresse
     *
     * @return array{mode: ?string, statut: ?string, compte: ?int, du: ?\DateTimeImmutable, au: ?\DateTimeImmutable, min: ?int, max: ?int, tri: string}
     */
    public static function depuis(array $parametres): array
    {
        $texte = static fn (string $cle): string => \is_scalar($parametres[$cle] ?? null) ? trim((string) $parametres[$cle]) : '';

        $mode = $texte('mode');
        $statut = $texte('statut');
        $compte = $texte('compte');
        $tri = $texte('tri');

        return [
            'mode' => \in_array($mode, Paiements::MODES, true) ? $mode : null,
            'statut' => \in_array($statut, Paiements::STATUTS, true) ? $statut : null,
            'compte' => ctype_digit($compte) && (int) $compte > 0 ? (int) $compte : null,
            'du' => self::jour($texte('du')),
            'au' => self::jour($texte('au')),
            'min' => self::montant($texte('min')),
            'max' => self::montant($texte('max')),
            'tri' => \in_array($tri, self::TRIS, true) ? $tri : self::TRI_PAR_DEFAUT,
        ];
    }

    /**
     * Un filtre est-il posé (le tri seul n'en est pas un) ?
     *
     * @param array<string, mixed> $filtres
     */
    public static function actifs(array $filtres): bool
    {
        foreach (['mode', 'statut', 'compte', 'du', 'au', 'min', 'max'] as $cle) {
            if (null !== ($filtres[$cle] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Les filtres nettoyés, remis en paramètres d'adresse (sans les vides
     * ni le tri par défaut) : la case « Rechercher » les renvoie avec elle.
     *
     * @param array<string, mixed> $filtres
     *
     * @return array<string, string>
     */
    public static function enParametres(array $filtres): array
    {
        $parametres = [];
        foreach (['mode', 'statut', 'compte', 'du', 'au', 'min', 'max', 'tri'] as $cle) {
            $valeur = $filtres[$cle] ?? null;
            if (null === $valeur || ('tri' === $cle && self::TRI_PAR_DEFAUT === $valeur)) {
                continue;
            }
            $parametres[$cle] = $valeur instanceof \DateTimeInterface ? $valeur->format('Y-m-d') : (string) $valeur;
        }

        return $parametres;
    }

    /** Date du champ « jj/mm/aaaa » du navigateur, envoyée en aaaa-mm-jj. */
    private static function jour(string $valeur): ?\DateTimeImmutable
    {
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $valeur)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeur);

        return false !== $date && $date->format('Y-m-d') === $valeur ? $date : null;
    }

    /** Montant en francs entiers (« 25 000 » accepté), jamais négatif. */
    private static function montant(string $valeur): ?int
    {
        $chiffres = preg_replace('/[\s\x{00a0}\x{202f}]/u', '', $valeur) ?? '';
        if ('' === $chiffres || !ctype_digit($chiffres) || \strlen($chiffres) > 12) {
            return null;
        }

        return (int) $chiffres;
    }
}
