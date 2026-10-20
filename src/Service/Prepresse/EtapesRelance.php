<?php

namespace App\Service\Prepresse;

/**
 * Relances des fichiers manquants (maquette PQ04, docs/prepresse.md) :
 * J+1, J+3, J+7 et J+15 comptés depuis la commande.
 *
 * - J+1 : message WhatsApp avec la liste des fichiers manquants. L'ERP
 *   n'envoie pas WhatsApp lui-même (pas d'API Meta) : il prépare le lien
 *   wa.me et prévient la prépresse ;
 * - J+3 : le même message, plus un message dans l'espace client ;
 * - J+7 : appel par la caisse (ligne de « Mes actions »), la réponse est
 *   notée sur la fiche du client ;
 * - J+15 : la commande est mise en pause et sa date de livraison retirée
 *   (remise quand le fichier arrive).
 *
 * Une étape ne se fait qu'une fois par commande. Si plusieurs sont dues
 * d'un coup (relances allumées tard, tâche arrêtée), seule la plus
 * avancée est faite : le client ne reçoit pas trois messages à la suite.
 * Classe pure.
 */
final class EtapesRelance
{
    public const J0 = 'J0';
    public const J1 = 'J1';
    public const J3 = 'J3';
    public const J7 = 'J7';
    public const J15 = 'J15';
    public const MANUELLE = 'manuelle';

    /** Étape => jours depuis la commande. */
    public const JOURS = [self::J1 => 1, self::J3 => 3, self::J7 => 7, self::J15 => 15];

    public const LIBELLES = [
        self::J0 => 'Avant J+1',
        self::J1 => 'J+1',
        self::J3 => 'J+3',
        self::J7 => 'J+7',
        self::J15 => 'J+15',
        self::MANUELLE => 'Relance',
    ];

    /** Clé du réglage (tuile « relances_fichiers ») qui allume chaque étape. */
    public const REGLAGES = [self::J1 => 'j1', self::J3 => 'j3', self::J7 => 'j7', self::J15 => 'j15'];

    /** Canaux de chaque étape. */
    public const CANAUX = [
        self::J1 => ['whatsapp'],
        self::J3 => ['whatsapp', 'espace'],
        self::J7 => ['appel'],
        self::J15 => ['pause'],
    ];

    /** Jours entiers écoulés depuis la commande (0 le jour même). */
    public static function jours(\DateTimeInterface $depuis, \DateTimeInterface $maintenant): int
    {
        return max(0, intdiv($maintenant->getTimestamp() - $depuis->getTimestamp(), 86400));
    }

    /**
     * L'étape atteinte à cet âge (J0 avant le premier jour).
     */
    public static function etapeAtteinte(int $jours): string
    {
        $atteinte = self::J0;
        foreach (self::JOURS as $etape => $seuil) {
            if ($jours >= $seuil) {
                $atteinte = $etape;
            }
        }

        return $atteinte;
    }

    /**
     * L'étape automatique à faire maintenant, ou null : la plus avancée des
     * étapes dues et allumées, si elle n'est pas déjà faite et qu'aucune
     * étape plus avancée ne l'est.
     *
     * @param list<string>        $faites  étapes déjà faites pour cette commande
     * @param array<string, bool> $actives étape => allumée dans les réglages
     */
    public static function aFaire(\DateTimeInterface $depuis, \DateTimeInterface $maintenant, array $faites, array $actives): ?string
    {
        $jours = self::jours($depuis, $maintenant);
        $due = null;
        foreach (self::JOURS as $etape => $seuil) {
            if ($jours >= $seuil && ($actives[$etape] ?? false)) {
                $due = $etape;
            }
        }
        if (null === $due || \in_array($due, $faites, true)) {
            return null;
        }
        // Une étape plus avancée déjà faite (à la main) : on ne revient pas en arrière.
        $rang = array_search($due, array_keys(self::JOURS), true);
        foreach ($faites as $faite) {
            $rangFait = array_search($faite, array_keys(self::JOURS), true);
            if (false !== $rangFait && $rangFait > $rang) {
                return null;
            }
        }

        return $due;
    }

    /**
     * « J+1 ✓ · J+3 ✓ · J+7 » : les trois étapes de message, cochées si faites.
     *
     * @param list<string> $faites
     *
     * @return list<array{etape: string, libelle: string, faite: bool}>
     */
    public static function frise(array $faites): array
    {
        $frise = [];
        foreach ([self::J1, self::J3, self::J7] as $etape) {
            $frise[] = ['etape' => $etape, 'libelle' => self::LIBELLES[$etape], 'faite' => \in_array($etape, $faites, true)];
        }

        return $frise;
    }

    /**
     * Le message au client : « Bonjour Fofana, votre commande CMD-000378
     * (étiquettes vinyle, 6 formats) attend encore 4 fichiers : 5,5 × 7 ·
     * 5,5 × 5. Envoyez-les ici : <lien> ou en réponse. Merci ! Succès Imprim ».
     * Avec un fichier de découpe qui manque : « … attend encore 1 fichier :
     * 5,5 × 7 cm, et le fichier de découpe : 5,5 × 5 cm. Envoyez-les… ».
     *
     * @param list<string> $manquants formats sans fichier
     * @param list<string> $decoupes  formats sans fichier de découpe
     */
    public static function message(string $nomClient, string $numero, string $travail, int $formats, array $manquants, ?string $lien, array $decoupes = []): string
    {
        $n = \count($manquants);
        $parties = [];
        if ($n > 0) {
            $parties[] = (1 === $n ? '1 fichier' : $n.' fichiers').self::liste($manquants);
        }
        if ([] !== $decoupes) {
            $parties[] = (1 === \count($decoupes) ? 'le fichier de découpe' : 'les fichiers de découpe').self::liste($decoupes);
        }
        if ([] === $parties) {
            $parties[] = '0 fichiers';
        }
        $total = $n + \count($decoupes);
        $nom = '' !== trim($nomClient) ? ' '.trim($nomClient) : '';
        $detail = trim($travail).($formats > 1 ? ', '.$formats.' formats' : '');

        return \sprintf(
            'Bonjour%s, votre commande %s%s attend encore %s. Envoyez-%s ici : %s ou en réponse. Merci ! Succès Imprim',
            $nom,
            $numero,
            '' !== $detail ? ' ('.$detail.')' : '',
            implode(', et ', $parties),
            1 === $total ? 'le' : 'les',
            $lien ?? 'notre espace client',
        );
    }

    /** « : 5,5 × 7 · 5,5 × 5 cm » (l'unité une seule fois quand il n'y a que des dimensions). */
    private static function liste(array $formats): string
    {
        if ([] === $formats) {
            return '';
        }
        $liste = ' : '.implode(' · ', $formats);
        if ([] === array_filter($formats, static fn (string $m): bool => !preg_match('/^\d+(?:,\d+)? × \d+(?:,\d+)?$/u', $m))) {
            $liste .= ' cm';
        }

        return $liste;
    }
}
