<?php

namespace App\Service\Bascule;

/**
 * Classe les écarts entre la base de la boutique et la migration de base :
 * ajout sans risque, changement sans perte (vérifié sur les données) ou
 * bloquant. Une seule règle : la correction ne doit jamais perdre une
 * donnée ni inventer une valeur. Dans le doute, c'est bloquant.
 */
final class Classement
{
    /**
     * Valeurs données aux lignes existantes quand la base de la boutique n'a
     * pas encore une colonne obligatoire sans valeur par défaut. Chacune est
     * le sens qu'avaient ces lignes avant que la colonne existe.
     */
    private const REPRISES = [
        'paiements.statut' => ["'valide'", '« validé » : avant le statut, tous les paiements enregistrés comptaient'],
        'commande_detail_fichier.origine' => ["'client'", '« client » : avant cette colonne, les fichiers venaient des clients'],
        'commande_detail_fichier.etat' => ["'original'", '« original » : le fichier tel qu\'il a été reçu'],
    ];

    /** Identifiant public (UUID binaire) : une valeur au hasard par ligne, comme la migration des UUID. */
    private const REPRISE_UUID = "UNHEX(REPLACE(UUID(), '-', ''))";

    /** Rang des entiers : un rang plus grand contient toutes les valeurs d'un plus petit. */
    private const ENTIERS = ['boolean' => 1, 'smallint' => 2, 'integer' => 3, 'bigint' => 4];

    /** Chiffres avant la virgule nécessaires pour garder chaque entier. */
    private const CHIFFRES = ['boolean' => 3, 'smallint' => 5, 'integer' => 10, 'bigint' => 19];

    private const TEXTES = ['string', 'ascii_string', 'text'];

    /**
     * $garderOrphelins : les lignes qui pointent déjà vers une ligne absente
     * ne bloquent plus la création du lien ; elles restent telles quelles
     * (option --garder-orphelins, après en avoir parlé avec nous).
     *
     * $converties : colonnes dont les valeurs sont d'abord converties par
     * l'étape « conversions de données » (ConversionsDonnees) ; leur
     * changement de type suit la conversion, avec ce message.
     *
     * @param list<Ecart>           $ecarts
     * @param array<string, string> $converties « table.colonne » => explication
     *
     * @return list<Constat>
     */
    public static function classer(array $ecarts, SondeDonnees $sonde, bool $garderOrphelins = false, array $converties = []): array
    {
        $tablesNouvelles = [];
        $colonnesNouvelles = [];
        foreach ($ecarts as $e) {
            if (Ecart::TABLE_AJOUTEE === $e->genre) {
                $tablesNouvelles[$e->table] = true;
            } elseif (Ecart::COLONNE_AJOUTEE === $e->genre) {
                $colonnesNouvelles[$e->table][(string) $e->objet] = $e->apres;
            }
        }

        $constats = [];
        foreach ($ecarts as $e) {
            $constats[] = match ($e->genre) {
                Ecart::TABLE_AJOUTEE => new Constat($e, Constat::AJOUT, 'Table à créer.'),
                Ecart::TABLE_EN_TROP => self::tableEnTrop($e, $sonde),
                Ecart::COLONNE_AJOUTEE => self::colonneAjoutee($e, $sonde),
                Ecart::COLONNE_EN_TROP => self::colonneEnTrop($e, $sonde),
                Ecart::COLONNE_MODIFIEE => isset($converties[$e->cible()])
                    ? new Constat($e, Constat::SANS_PERTE, self::decrire($e->avant).' → '.self::decrire($e->apres).' : '.$converties[$e->cible()])
                    : self::colonneModifiee($e, $sonde),
                Ecart::INDEX_AJOUTE => self::indexAjoute($e, $sonde, isset($tablesNouvelles[$e->table]), $colonnesNouvelles[$e->table] ?? []),
                Ecart::INDEX_RETIRE => new Constat($e, Constat::SANS_PERTE, 'Index en trop, retiré : aucune donnée touchée.'),
                Ecart::INDEX_RENOMME => new Constat($e, Constat::SANS_PERTE, \sprintf('Index renommé en %s : aucune donnée touchée.', $e->apres['nom'] ?? '?')),
                Ecart::CLE_AJOUTEE => self::cleAjoutee($e, $sonde, $tablesNouvelles, $colonnesNouvelles, $garderOrphelins),
                Ecart::CLE_RETIREE => new Constat($e, Constat::SANS_PERTE, 'Lien (clé étrangère) en trop, retiré : aucune donnée touchée.'),
                default => new Constat($e, Constat::BLOQUANT, 'Écart non prévu par l\'outil : à voir avec nous.'),
            };
        }

        return $constats;
    }

    /**
     * Valeur pour les lignes existantes d'une colonne obligatoire ajoutée,
     * ou null si l'outil ne sait pas laquelle mettre.
     *
     * @param array<string, mixed> $colonne
     *
     * @return array{0: string, 1: string}|null [expression SQL, explication]
     */
    public static function reprise(string $table, string $nom, array $colonne): ?array
    {
        if ('public_id' === $nom && 'binary' === ($colonne['type'] ?? null)) {
            return [self::REPRISE_UUID, 'un identifiant public tiré au hasard, différent pour chaque ligne'];
        }

        return self::REPRISES[$table.'.'.$nom] ?? null;
    }

    /**
     * Toute valeur de $avant tient-elle dans $apres sans changer ?
     *
     * @param array<string, mixed> $avant
     * @param array<string, mixed> $apres
     */
    public static function sansPerte(array $avant, array $apres): bool
    {
        $ta = (string) ($avant['type'] ?? '');
        $tb = (string) ($apres['type'] ?? '');

        if (self::memeType($avant, $apres)) {
            return true;
        }

        if (\in_array($ta, self::TEXTES, true) && \in_array($tb, self::TEXTES, true)) {
            $la = $avant['longueur'] ?? null;
            $lb = $apres['longueur'] ?? null;
            if ('text' === $tb && null === $lb) {
                return true; // LONGTEXT contient tout
            }
            if ('text' === $ta && 'text' !== $tb) {
                return false;
            }

            return null !== $la && null !== $lb && $lb >= $la;
        }

        if (isset(self::ENTIERS[$ta])) {
            $nonSigneAvant = (bool) ($avant['nonSigne'] ?? false);
            if (isset(self::ENTIERS[$tb])) {
                return self::ENTIERS[$tb] > self::ENTIERS[$ta]
                    || (self::ENTIERS[$tb] === self::ENTIERS[$ta] && (!$nonSigneAvant || (bool) ($apres['nonSigne'] ?? false)));
            }
            if ('decimal' === $tb) {
                return (int) ($apres['precision'] ?? 10) - (int) ($apres['echelle'] ?? 0) >= self::CHIFFRES[$ta] + ($nonSigneAvant ? 1 : 0);
            }

            // DOUBLE garde exactement les entiers jusqu'à 2^53
            return 'float' === $tb && 'bigint' !== $ta;
        }

        if ('decimal' === $ta && 'decimal' === $tb) {
            $pa = (int) ($avant['precision'] ?? 10);
            $sa = (int) ($avant['echelle'] ?? 0);
            $pb = (int) ($apres['precision'] ?? 10);
            $sb = (int) ($apres['echelle'] ?? 0);

            return $sb >= $sa && $pb - $sb >= $pa - $sa;
        }

        return match ($ta.'>'.$tb) {
            'smallfloat>float', 'date>datetime', 'json>text' => true,
            default => false,
        };
    }

    /**
     * Même type et même taille : seuls changent l'obligation, la valeur par
     * défaut ou l'interclassement.
     *
     * @param array<string, mixed> $avant
     * @param array<string, mixed> $apres
     */
    public static function memeType(array $avant, array $apres): bool
    {
        return ($avant['type'] ?? null) === ($apres['type'] ?? null)
            && ($avant['longueur'] ?? null) === ($apres['longueur'] ?? null)
            && ($avant['precision'] ?? null) === ($apres['precision'] ?? null)
            && ($avant['echelle'] ?? 0) === ($apres['echelle'] ?? 0)
            && (bool) ($avant['nonSigne'] ?? false) === (bool) ($apres['nonSigne'] ?? false);
    }

    /** « string(100) obligatoire » pour les messages. */
    public static function decrire(array $c): string
    {
        $type = (string) ($c['type'] ?? '?');
        $taille = match (true) {
            'decimal' === $type => '('.($c['precision'] ?? 10).','.($c['echelle'] ?? 0).')',
            \in_array($type, ['string', 'ascii_string', 'binary'], true) && null !== ($c['longueur'] ?? null) => '('.$c['longueur'].')',
            default => '',
        };
        $defaut = null !== ($c['defaut'] ?? null) ? ', par défaut « '.$c['defaut'].' »' : '';

        return $type.$taille.(!empty($c['obligatoire']) ? ' obligatoire' : ' facultatif').$defaut;
    }

    private static function tableEnTrop(Ecart $e, SondeDonnees $sonde): Constat
    {
        $n = $sonde->lignes($e->table);

        return 0 === $n
            ? new Constat($e, Constat::SANS_PERTE, 'Table en trop, vide : supprimée.')
            : new Constat($e, Constat::BLOQUANT, \sprintf('Table en trop qui contient %d ligne(s) : la nouvelle version ne la connaît pas. À voir avec nous.', $n));
    }

    private static function colonneAjoutee(Ecart $e, SondeDonnees $sonde): Constat
    {
        $c = $e->apres;
        if (empty($c['obligatoire'])) {
            return new Constat($e, Constat::AJOUT, 'Colonne à ajouter, vide pour les lignes existantes.');
        }
        $n = $sonde->lignes($e->table);
        if (null !== ($c['defaut'] ?? null)) {
            return new Constat($e, Constat::AJOUT, 0 === $n
                ? 'Colonne à ajouter.'
                : \sprintf('Colonne à ajouter : les %d ligne(s) existantes prennent la valeur par défaut « %s ».', $n, $c['defaut']));
        }
        if (0 === $n) {
            return new Constat($e, Constat::AJOUT, 'Colonne obligatoire à ajouter (table vide).');
        }
        $reprise = self::reprise($e->table, (string) $e->objet, $c);
        if (null !== $reprise) {
            return new Constat($e, Constat::AJOUT, \sprintf('Colonne obligatoire à ajouter : les %d ligne(s) existantes reçoivent %s.', $n, $reprise[1]), $reprise[0]);
        }

        return new Constat($e, Constat::BLOQUANT, \sprintf('Colonne obligatoire sans valeur par défaut, alors que la table a %d ligne(s) : il faut décider quelle valeur leur donner. À voir avec nous.', $n));
    }

    private static function colonneEnTrop(Ecart $e, SondeDonnees $sonde): Constat
    {
        $n = $sonde->lignes($e->table);
        if (0 === $n) {
            return new Constat($e, Constat::SANS_PERTE, 'Colonne en trop, table vide : supprimée.');
        }
        $r = $sonde->renseignees($e->table, (string) $e->objet);
        if (0 === $r) {
            return new Constat($e, Constat::SANS_PERTE, \sprintf('Colonne en trop, vide sur les %d ligne(s) : supprimée.', $n));
        }

        return new Constat($e, Constat::BLOQUANT, \sprintf('Colonne en trop, remplie sur %d ligne(s) : la supprimer perdrait ces valeurs. À voir avec nous.', $r));
    }

    private static function colonneModifiee(Ecart $e, SondeDonnees $sonde): Constat
    {
        $avant = $e->avant;
        $apres = $e->apres;
        $change = self::decrire($avant).' → '.self::decrire($apres);
        $n = $sonde->lignes($e->table);
        if (0 === $n) {
            return new Constat($e, Constat::SANS_PERTE, $change.' (table vide).');
        }

        $r = $sonde->renseignees($e->table, (string) $e->objet);
        if (!empty($apres['obligatoire']) && empty($avant['obligatoire']) && $r < $n && self::memeType($avant, $apres)
            && null !== ($reprise = self::reprise($e->table, (string) $e->objet, $apres)) && self::REPRISE_UUID === $reprise[0]) {
            return new Constat($e, Constat::SANS_PERTE, \sprintf('%s : les %d ligne(s) sans valeur reçoivent %s.', $change, $n - $r, $reprise[1]), $reprise[0], Constat::IDENTIFIANT_VIDE);
        }
        if (!empty($apres['obligatoire']) && empty($avant['obligatoire']) && $r < $n) {
            return new Constat($e, Constat::BLOQUANT, \sprintf('%s : %d ligne(s) sans valeur alors que la colonne devient obligatoire. À voir avec nous.', $change, $n - $r));
        }
        if (0 === $r) {
            return new Constat($e, Constat::SANS_PERTE, $change.' (colonne vide).');
        }
        if (self::memeType($avant, $apres)) {
            return new Constat($e, Constat::SANS_PERTE, $change.' (même type : aucune valeur ne change).');
        }
        if (self::sansPerte($avant, $apres)) {
            return new Constat($e, Constat::SANS_PERTE, $change.' (plus large : toutes les valeurs y tiennent).');
        }
        if (\in_array($avant['type'] ?? null, self::TEXTES, true) && \in_array($apres['type'] ?? null, self::TEXTES, true) && null !== ($apres['longueur'] ?? null)) {
            $max = $sonde->longueurMax($e->table, (string) $e->objet);

            return $max <= (int) $apres['longueur']
                ? new Constat($e, Constat::SANS_PERTE, \sprintf('%s (plus courte, mais la plus longue valeur fait %d caractère(s)).', $change, $max))
                : new Constat($e, Constat::BLOQUANT, \sprintf('%s : la plus longue valeur fait %d caractère(s), elle serait coupée. À voir avec nous.', $change, $max));
        }

        return new Constat($e, Constat::BLOQUANT, \sprintf('%s : %d ligne(s) ont une valeur que ce changement de type pourrait modifier. À voir avec nous.', $change, $r));
    }

    /**
     * @param array<string, array<string, mixed>> $colonnesNouvelles
     */
    private static function indexAjoute(Ecart $e, SondeDonnees $sonde, bool $tableNouvelle, array $colonnesNouvelles): Constat
    {
        $colonnes = $e->apres['colonnes'] ?? [];
        if (empty($e->apres['unique'])) {
            return new Constat($e, Constat::AJOUT, 'Index à créer.');
        }
        if ($tableNouvelle || $sonde->lignes($e->table) <= 1) {
            return new Constat($e, Constat::AJOUT, 'Index unique à créer.');
        }
        foreach ($colonnes as $colonne) {
            if (!isset($colonnesNouvelles[$colonne])) {
                continue;
            }
            $c = $colonnesNouvelles[$colonne];
            $reprise = self::reprise($e->table, $colonne, $c);
            if (self::REPRISE_UUID === ($reprise[0] ?? null) || (empty($c['obligatoire']) && null === ($c['defaut'] ?? null))) {
                return new Constat($e, Constat::AJOUT, 'Index unique à créer sur une nouvelle colonne (valeurs toutes différentes ou vides).');
            }

            return new Constat($e, Constat::BLOQUANT, \sprintf('Index unique sur la nouvelle colonne %s, qui aurait la même valeur sur toutes les lignes. À voir avec nous.', $colonne));
        }
        $doublons = $sonde->doublons($e->table, $colonnes);
        if ($doublons > 0 && ['public_id'] === $colonnes && 0 === $sonde->doublons($e->table, $colonnes, true)) {
            // Identifiants publics jamais tirés (colonne ajoutée sans être remplie) : un tirage
            // pour chacun, comme la migration des UUID. Aucun n'a pu servir, ils sont tous à zéro.
            $vides = $sonde->identifiantsVides($e->table, 'public_id');

            return new Constat($e, Constat::AJOUT, \sprintf('Index unique à créer : les %d ligne(s) sans identifiant public (à zéro) en reçoivent un tiré au hasard.', $vides), self::REPRISE_UUID, Constat::IDENTIFIANT_VIDE);
        }

        return 0 === $doublons
            ? new Constat($e, Constat::AJOUT, 'Index unique à créer : aucune valeur en double.')
            : new Constat($e, Constat::BLOQUANT, \sprintf('Index unique impossible : %d valeur(s) en double sur %s. À voir avec nous.', $doublons, implode(', ', $colonnes)));
    }

    /**
     * @param array<string, true>                                $tablesNouvelles
     * @param array<string, array<string, array<string, mixed>>> $colonnesNouvelles
     */
    private static function cleAjoutee(Ecart $e, SondeDonnees $sonde, array $tablesNouvelles, array $colonnesNouvelles, bool $garderOrphelins): Constat
    {
        $colonnes = $e->apres['colonnes'] ?? [];
        $parente = (string) ($e->apres['parente'] ?? '');
        $colonnesParentes = $e->apres['colonnesParentes'] ?? [];
        $lien = \sprintf('Lien (clé étrangère) vers %s', $parente);
        if (isset($tablesNouvelles[$e->table]) || 0 === $sonde->lignes($e->table)) {
            return new Constat($e, Constat::AJOUT, $lien.' à créer.');
        }

        foreach ($colonnes as $i => $colonne) {
            if (!isset($colonnesNouvelles[$e->table][$colonne])) {
                continue;
            }
            $c = $colonnesNouvelles[$e->table][$colonne];
            $defaut = $c['defaut'] ?? null;
            if (null === $defaut && empty($c['obligatoire'])) {
                return new Constat($e, Constat::AJOUT, $lien.' à créer sur une nouvelle colonne, vide pour les lignes existantes.');
            }
            if (null !== $defaut && !isset($tablesNouvelles[$parente]) && $sonde->valeurExiste($parente, (string) ($colonnesParentes[$i] ?? 'id'), (string) $defaut)) {
                return new Constat($e, Constat::AJOUT, \sprintf('%s à créer : la valeur par défaut « %s » existe dans %s.', $lien, $defaut, $parente));
            }

            return new Constat($e, Constat::BLOQUANT, \sprintf('%s : les lignes existantes pointeraient vers une ligne absente de %s. À voir avec nous.', $lien, $parente));
        }

        if (isset($tablesNouvelles[$parente])) {
            $r = $sonde->renseignees($e->table, (string) ($colonnes[0] ?? ''));

            return 0 === $r
                ? new Constat($e, Constat::AJOUT, $lien.' à créer.')
                : new Constat($e, Constat::BLOQUANT, \sprintf('%s : %d ligne(s) pointent vers %s, une table encore vide. À voir avec nous.', $lien, $r, $parente));
        }

        $orphelins = $sonde->orphelins($e->table, $colonnes, $parente, $colonnesParentes);
        if (0 === $orphelins) {
            return new Constat($e, Constat::AJOUT, $lien.' à créer : toutes les lignes pointent vers une ligne existante.');
        }
        if ($garderOrphelins) {
            return new Constat($e, Constat::SANS_PERTE, \sprintf('%s créé sans toucher les %d ligne(s) de %s qui pointent vers une ligne absente : elles restent telles quelles (--garder-orphelins).', $lien, $orphelins, $e->table), null, Constat::SANS_VALEUR, true);
        }

        return new Constat($e, Constat::BLOQUANT, \sprintf('%s impossible : %d ligne(s) de %s pointent vers une ligne absente de %s. À voir avec nous ; pour les garder telles quelles et continuer : --garder-orphelins.', $lien, $orphelins, $e->table, $parente));
    }
}
