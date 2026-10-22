<?php

namespace App\Service\Prepresse;

use App\Entity\ControlePrePresse;

/**
 * Où en est une ligne, et une commande, à la prépresse (maquettes PQ01 et
 * PQ03, docs/prepresse.md). Classe pure : la liste, le tableau et les
 * relances en tirent leurs onglets, colonnes et badges.
 *
 * Une ligne est :
 * - « sans fichier » tant qu'aucun fichier ne lui est rattaché ;
 * - « à contrôler » quand le fichier est là et pas encore contrôlé (ou
 *   que le contrôle est commencé) ;
 * - « retour » quand une correction est demandée (contrôle ou machiniste) ;
 * - « BAT chez le client » quand le BAT attend la réponse du client ;
 * - « prête » quand le contrôle est validé (ou envoyé en production).
 *
 * Une commande appartient à chaque onglet où elle a au moins une ligne :
 * la commande de 6 formats avec 2 fichiers est « sans fichier » (4
 * manquent) et « à contrôler » (2 arrivés).
 */
final class EtatPrepresse
{
    public const SANS_FICHIER = 'sans_fichier';
    public const A_CONTROLER = 'a_controler';
    public const RETOUR = 'retour';
    public const BAT = 'bat';
    public const PRET = 'pret';

    /** Onglets de la liste (PQ01), dans l'ordre. */
    public const ONGLETS = [
        self::SANS_FICHIER => 'Sans fichier',
        self::A_CONTROLER => 'À contrôler',
        self::BAT => 'BAT en attente',
        self::RETOUR => 'Retours',
        'tout' => 'Tout',
    ];

    /** Colonnes du tableau (PQ03), dans l'ordre. */
    public const COLONNES = [
        self::SANS_FICHIER => 'Sans fichier',
        self::A_CONTROLER => 'À contrôler',
        self::BAT => 'BAT chez le client',
        self::PRET => 'Prêt pour la production',
    ];

    /** Statut d'une ligne : libellé et couleur du badge. */
    public const STATUTS = [
        self::SANS_FICHIER => ['Fichier manquant', 'rouge'],
        self::A_CONTROLER => ['À contrôler', 'jaune'],
        self::RETOUR => ['Correction demandée', 'violet'],
        self::BAT => ['BAT chez le client', 'bleu'],
        self::PRET => ['Prêt pour la production', 'vert'],
    ];

    /** Un petit format, pour le conseil de montage sur une planche : 30 cm au plus de chaque côté. */
    public const PETIT_FORMAT_MM = 300;

    public static function ligne(bool $aFichier, ?string $statutControle, bool $retourOuvert): string
    {
        if ($retourOuvert || ControlePrePresse::STATUT_CORRECTION_REQUISE === $statutControle || ControlePrePresse::STATUT_REJETE === $statutControle) {
            return self::RETOUR;
        }

        return match ($statutControle) {
            ControlePrePresse::STATUT_EN_ATTENTE_BAT => self::BAT,
            ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION => self::PRET,
            default => $aFichier ? self::A_CONTROLER : self::SANS_FICHIER,
        };
    }

    /**
     * Onglets de la commande d'après les états de ses lignes.
     *
     * @param list<string> $etats
     *
     * @return list<string>
     */
    public static function onglets(array $etats): array
    {
        $onglets = [];
        foreach ([self::SANS_FICHIER, self::A_CONTROLER, self::BAT, self::RETOUR, self::PRET] as $etat) {
            if (\in_array($etat, $etats, true)) {
                $onglets[] = $etat;
            }
        }

        return $onglets;
    }

    /**
     * La seule action mise en avant sur la ligne de la commande (PQ01) :
     * « Associer » sans client joignable (après le dépôt), « Déposer (N) »
     * quand il manque des fichiers, sinon « Contrôler », puis « Relancer »
     * pour un BAT, « Voir » pour une commande prête.
     *
     * @return array{action: string, libelle: string, secondaire: ?string}
     */
    public static function action(int $manquants, int $aControler, int $retours, int $bats, bool $clientJoignable): array
    {
        $secondaire = $clientJoignable ? ($manquants > 0 || $bats > 0 ? 'relancer' : null) : 'associer';
        if ($retours > 0) {
            return ['action' => 'corriger', 'libelle' => 'Corriger', 'secondaire' => $manquants > 0 ? 'deposer' : $secondaire];
        }
        if ($manquants > 0) {
            return ['action' => 'deposer', 'libelle' => $manquants > 1 ? 'Déposer ('.$manquants.')' : 'Déposer', 'secondaire' => $secondaire];
        }
        if ($aControler > 0) {
            return ['action' => 'controler', 'libelle' => 'Contrôler', 'secondaire' => $clientJoignable ? null : 'associer'];
        }
        if ($bats > 0) {
            return ['action' => 'relancer', 'libelle' => 'Relancer', 'secondaire' => $clientJoignable ? null : 'associer'];
        }

        return ['action' => 'voir', 'libelle' => 'Voir', 'secondaire' => null];
    }

    /** « 12 j », « 2 h 10 », « 35 min ». */
    public static function age(\DateTimeInterface $depuis, \DateTimeInterface $maintenant): string
    {
        $minutes = max(0, intdiv($maintenant->getTimestamp() - $depuis->getTimestamp(), 60));
        if ($minutes >= 1440) {
            return intdiv($minutes, 1440).' j';
        }
        if ($minutes >= 60) {
            return \sprintf('%d h %02d', intdiv($minutes, 60), $minutes % 60);
        }

        return $minutes.' min';
    }

    /** Couleur du badge d'âge : rouge au-delà de 3 jours, orange au-delà d'un jour. */
    public static function couleurAge(\DateTimeInterface $depuis, \DateTimeInterface $maintenant): string
    {
        $heures = ($maintenant->getTimestamp() - $depuis->getTimestamp()) / 3600;

        return $heures >= 72 ? 'rouge' : ($heures >= 24 ? 'orange' : 'jaune');
    }

    /**
     * Conseil de montage : plusieurs petits formats du même support
     * (« 6 petits formats du même vinyle »). Le groupe le plus nombreux, à
     * partir de 2.
     *
     * @param list<array{support: string, largeurMm: ?float, hauteurMm: ?float}> $formats
     *
     * @return array{nombre: int, support: string}|null
     */
    public static function petitsFormats(array $formats): ?array
    {
        $groupes = [];
        foreach ($formats as $f) {
            $l = (float) ($f['largeurMm'] ?? 0);
            $h = (float) ($f['hauteurMm'] ?? 0);
            if ('' === trim($f['support']) || $l <= 0 || $h <= 0 || max($l, $h) > self::PETIT_FORMAT_MM) {
                continue;
            }
            $cle = mb_strtolower(trim($f['support']));
            $groupes[$cle] ??= ['nombre' => 0, 'support' => trim($f['support'])];
            ++$groupes[$cle]['nombre'];
        }
        usort($groupes, static fn (array $a, array $b): int => $b['nombre'] <=> $a['nombre']);

        return isset($groupes[0]) && $groupes[0]['nombre'] >= 2 ? $groupes[0] : null;
    }

    /**
     * « Vinyle adhésif blanc + découpe · 6 formats · 150 pièces ».
     *
     * @param list<string> $designations désignations des lignes, sans les dimensions
     */
    public static function resume(array $designations, int $quantite, string $unite = 'pièces'): array
    {
        $noms = array_values(array_unique(array_filter(array_map('trim', $designations), static fn (string $d): bool => '' !== $d)));
        $titre = $noms[0] ?? 'Travail';
        if (\count($noms) > 1) {
            $titre .= ' + '.(\count($noms) - 1).' autre'.(\count($noms) > 2 ? 's' : '');
        }
        $n = \count($designations);

        return [
            'titre' => $titre,
            'detail' => ($n > 1 ? $n.' formats' : '1 format').' · '.number_format($quantite, 0, ',', ' ').' '.$unite,
        ];
    }

    /** « Vinyle adhésif (7,5 x 8 cm) » → « Vinyle adhésif ». */
    public static function sansDimensions(string $designation): string
    {
        return trim((string) preg_replace('/\s*\([0-9]+(?:,[0-9]+)?\s*x\s*[0-9]+(?:,[0-9]+)?\s*cm\)\s*$/u', '', $designation));
    }
}
