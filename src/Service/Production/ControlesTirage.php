<?php

namespace App\Service\Production;

/**
 * « Avant d'appuyer sur Imprimer » (maquette PD03, fiche de production) :
 * les points à contrôler sur la machine avant de lancer un rouleau.
 *
 * Chaque point est « ok » (vert), « bloquant » (rouge : à corriger),
 * « afaire » (à cocher par le machiniste) ou « surveiller » (orange,
 * jamais bloquant). « Démarrer » ne se débloque que sans point bloquant ni
 * point à faire. Classe pure : tout ce qu'il faut savoir lui est donné.
 */
final class ControlesTirage
{
    public const OK = 'ok';
    public const BLOQUANT = 'bloquant';
    public const A_FAIRE = 'afaire';
    public const SURVEILLER = 'surveiller';

    /** Points que le machiniste coche lui-même (clé => libellé du bouton). */
    public const A_COCHER = [
        'support' => 'Support vérifié',
        'profil' => 'Profil choisi',
        'mode' => 'Mode choisi',
        'sechage' => 'Séchage réglé',
        'miroir' => 'Sens vérifié',
    ];

    public const NOMS_ENCRES = ['C' => 'cyan', 'M' => 'magenta', 'J' => 'jaune', 'N' => 'noir', 'B' => 'blanc'];

    /** Mots qui signalent une couleur à surveiller dans une remarque. */
    private const MOTS_COULEUR = ['rouge', 'bleu', 'vert', 'jaune', 'orange', 'violet', 'rose', 'noir', 'blanc', 'dore', 'argent', 'marron', 'turquoise', 'bordeaux', 'pantone', 'charte', 'teinte', 'couleur', 'couleurs'];

    /**
     * @param array<string, mixed>                                                                                                            $coches   état coché du tirage (TirageRouleau::getControles)
     * @param array{fichier: string, mode: string, vitesse: ?float, chauffe: ?int, sechage: ?int, miroir: bool}|null                          $profil   profil d'impression retenu, null : aucun
     * @param array{le: \DateTimeImmutable, bouchees: int}|null                                                                               $buses    test de buses du jour sur cette machine
     * @param array<string, int>                                                                                                              $encres   niveaux en %
     * @param list<string>                                                                                                                    $couleurs couleurs sensibles repérées (« rouge Awa Mode »)
     *
     * @return list<array{cle: string, libelle: string, detail: string, etat: string}>
     */
    public static function evaluer(string $support, array $coches, ?array $profil, ?array $buses, array $encres, int $seuilEncre, array $couleurs, ?\DateTimeImmutable $encresLe = null, bool $miroirMachine = false): array
    {
        $coche = static fn (string $cle): bool => !empty($coches[$cle]);
        $points = [];

        $scan = isset($coches['support_code']) && '' !== (string) $coches['support_code'] ? ' · rouleau scanné ('.$coches['support_code'].')' : '';
        $points[] = ['cle' => 'support', 'libelle' => 'Support chargé = support commandé', 'detail' => $support.($coche('support') ? $scan : ' · à confirmer : scannez le rouleau ou cochez après avoir lu son étiquette'), 'etat' => $coche('support') ? self::OK : self::A_FAIRE];

        if (null === $profil) {
            $points[] = ['cle' => 'profil', 'libelle' => 'Profil couleur', 'detail' => 'Profil à régler : le chef d\'atelier le règle dans « Machines et dossiers »', 'etat' => self::BLOQUANT];
            $points[] = ['cle' => 'mode', 'libelle' => 'Mode', 'detail' => 'Mode à régler avec le profil', 'etat' => self::BLOQUANT];
        } else {
            $points[] = ['cle' => 'profil', 'libelle' => 'Profil couleur', 'detail' => '' !== $profil['fichier'] ? $profil['fichier'] : 'profil du support', 'etat' => $coche('profil') ? self::OK : self::A_FAIRE];
            $mode = implode(' · ', array_filter([$profil['mode'], null !== $profil['vitesse'] ? self::nombre($profil['vitesse']).' m²/h' : null]));
            $points[] = ['cle' => 'mode', 'libelle' => 'Mode', 'detail' => '' !== $mode ? $mode : 'mode du profil', 'etat' => $coche('mode') ? self::OK : self::A_FAIRE];
        }

        if (null === $buses) {
            $points[] = ['cle' => 'buses', 'libelle' => 'Test de buses', 'detail' => 'pas encore fait aujourd\'hui sur cette machine', 'etat' => self::A_FAIRE];
        } else {
            $b = $buses['bouchees'];
            $points[] = ['cle' => 'buses', 'libelle' => 'Test de buses', 'detail' => \sprintf('fait à %s · %d buse%s bouchée%s', $buses['le']->format('H:i'), $b, $b > 1 ? 's' : '', $b > 1 ? 's' : '').($b > 0 ? ' : nettoyer et refaire le test' : ''), 'etat' => 0 === $b ? self::OK : self::SURVEILLER];
        }

        $points[] = self::encres($encres, $seuilEncre, $encresLe);

        if (null === $profil) {
            $points[] = ['cle' => 'sechage', 'libelle' => 'Séchage', 'detail' => 'Chauffe à régler avec le profil', 'etat' => self::BLOQUANT];
        } else {
            $sechage = implode(' · ', array_filter([null !== $profil['chauffe'] ? 'chauffe '.$profil['chauffe'].' °C' : null, 'ventilation marche', null !== $profil['sechage'] ? 'séchage '.$profil['sechage'].' min' : null]));
            $points[] = ['cle' => 'sechage', 'libelle' => 'Séchage', 'detail' => $sechage, 'etat' => $coche('sechage') ? self::OK : self::A_FAIRE];
        }

        $miroir = null !== $profil ? $profil['miroir'] : $miroirMachine;
        $points[] = ['cle' => 'miroir', 'libelle' => 'Sens et miroir', 'detail' => $miroir ? 'impression en miroir (transfert)' : 'sens normal, pas de miroir', 'etat' => $coche('miroir') ? self::OK : self::A_FAIRE];

        $points[] = ['cle' => 'echelle', 'libelle' => 'Échelle et taille', 'detail' => '1:1 · contrôle au mètre sur la 1re pièce', 'etat' => self::SURVEILLER];

        $points[] = [] === $couleurs
            ? ['cle' => 'couleurs', 'libelle' => 'Couleurs sensibles', 'detail' => 'aucune couleur signalée', 'etat' => self::OK]
            : ['cle' => 'couleurs', 'libelle' => 'Couleurs sensibles', 'detail' => implode(' ; ', $couleurs).' : comparer au BAT imprimé', 'etat' => self::SURVEILLER];

        return $points;
    }

    /**
     * Niveaux d'encre : une couleur sous le seuil bloque (« jaune à 18 % :
     * remplacer avant le travail »).
     *
     * @param array<string, int> $encres
     *
     * @return array{cle: string, libelle: string, detail: string, etat: string}
     */
    public static function encres(array $encres, int $seuil, ?\DateTimeImmutable $le = null): array
    {
        if ([] === $encres) {
            return ['cle' => 'encres', 'libelle' => 'Encres', 'detail' => 'niveaux à relever sur l\'écran de la machine', 'etat' => self::A_FAIRE];
        }
        $basses = [];
        foreach ($encres as $code => $pct) {
            if ($pct < $seuil) {
                $basses[] = (self::NOMS_ENCRES[$code] ?? $code).' à '.$pct.' %';
            }
        }
        if ([] !== $basses) {
            return ['cle' => 'encres', 'libelle' => 'Encres', 'detail' => implode(', ', $basses).' : remplacer avant le travail', 'etat' => self::BLOQUANT];
        }

        return ['cle' => 'encres', 'libelle' => 'Encres', 'detail' => 'toutes au-dessus de '.$seuil.' %'.(null !== $le ? ' · relevées à '.$le->format('H:i') : ''), 'etat' => self::OK];
    }

    /**
     * @param list<array{etat: string}> $points
     *
     * @return array{bloquants: int, afaire: int, surveiller: int, pret: bool}
     */
    public static function resume(array $points): array
    {
        $compte = static fn (string $etat): int => \count(array_filter($points, static fn (array $p): bool => $p['etat'] === $etat));
        $bloquants = $compte(self::BLOQUANT);
        $afaire = $compte(self::A_FAIRE);

        return ['bloquants' => $bloquants, 'afaire' => $afaire, 'surveiller' => $compte(self::SURVEILLER), 'pret' => 0 === $bloquants && 0 === $afaire];
    }

    /**
     * Couleurs à surveiller dans les remarques d'un travail (ligne, BAT,
     * prépresse) : « rouge Awa Mode ». $remarques : textes ; $qui : le nom
     * du client ou de la marque à citer.
     *
     * @param list<?string> $remarques
     */
    public static function couleurSensible(array $remarques, string $qui): ?string
    {
        $trouves = [];
        foreach ($remarques as $texte) {
            $simple = ' '.self::simplifier((string) $texte).' ';
            foreach (self::MOTS_COULEUR as $mot) {
                if (str_contains($simple, ' '.$mot.' ') || ('pantone' === $mot && str_contains($simple, 'pantone'))) {
                    $trouves[$mot] = true;
                }
            }
        }
        if ([] === $trouves) {
            return null;
        }
        // « couleur », « charte », « teinte » seuls : on dit « couleurs ».
        $vraies = array_values(array_diff(array_keys($trouves), ['couleur', 'couleurs', 'charte', 'teinte']));
        $nom = [] !== $vraies ? implode(' et ', \array_slice($vraies, 0, 2)) : 'couleurs';

        return trim($nom.' '.$qui);
    }

    /** Clé d'un point qui peut se cocher (null sinon). */
    public static function cocheValide(string $cle): ?string
    {
        return \array_key_exists($cle, self::A_COCHER) ? $cle : null;
    }

    /**
     * Code lu sur le rouleau (douchette, saisie) comparé à la référence de
     * l'article : majuscules, sans espace ni tiret.
     */
    public static function codeRouleauConforme(string $lu, ?string $reference): bool
    {
        $norme = static fn (string $c): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $c));

        return null !== $reference && '' !== $norme($reference) && $norme($lu) === $norme($reference);
    }

    private static function simplifier(string $texte): string
    {
        $texte = mb_strtolower($texte);
        $sans = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texte);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', false !== $sans ? $sans : $texte));
    }

    private static function nombre(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, ',', ' '), '0'), ',');
    }
}
