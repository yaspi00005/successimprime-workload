<?php

namespace App\Service\Corbeille;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Copie, juste avant leur suppression, les lignes que la base va perdre
 * (docs/corbeille.md) :
 *  - les fiches supprimées par l'application (Doctrine) ;
 *  - ce que la base supprime avec elles (clés étrangères ON DELETE
 *    CASCADE) et les lignes des tables de liaison, de proche en proche ;
 *  - pour les fiches qui restent mais pointaient vers une ligne supprimée
 *    (lien vidé par SET NULL ou par l'application), l'adresse du lien, pour
 *    le rebrancher à la restauration.
 *
 * Les lignes liées entre elles forment un groupe : une entrée de la
 * corbeille. Sa racine est la ligne dont les autres dépendent. Un groupe
 * fait seulement d'éléments rattachés (une finition retirée en modifiant
 * un devis) n'est pas gardé : c'est une modification, pas une suppression.
 * Une ligne de commande retirée avec ses fichiers ou son contrôle de BAT
 * est gardée, sous le nom de la ligne.
 *
 * Les tables exclues (TypesCorbeille::EXCLUES) ne sont jamais copiées.
 */
final class Instantane
{
    /** Valeur binaire (identifiant public sur 16 octets, image) : rangée en base64. */
    public const BINAIRE = '__base64';

    /** Valeur binaire trop lourde pour la base : rangée dans un fichier à part. */
    public const FICHIER_BINAIRE = '__fichier';

    public const TAILLE_MAX_EN_LIGNE = 262144;

    private const PAQUET = 500;

    /**
     * @param array<string, true> $tablesEntites tables qui portent une entité (les autres sont des tables de liaison)
     */
    public function __construct(
        private readonly Connection $connexion,
        private readonly SchemaCorbeille $schema,
        private readonly array $tablesEntites,
    ) {
    }

    /**
     * @param list<array{table: string, cle: array<string, mixed>}> $supprimees fiches supprimées par l'application, dans l'ordre
     *
     * @return list<list<array{table: string, cle: array<string, mixed>, donnees: array<string, mixed>, liens: list<array<string, mixed>>, racine: bool}>> groupes, lignes dans l'ordre de remise en place
     */
    public function capturer(array $supprimees): array
    {
        /** @var array<string, array{table: string, cle: array<string, mixed>, donnees: array<string, mixed>, liens: list<array<string, mixed>>, rang: int}> $lignes */
        $lignes = [];
        $niveau = [];

        // 1. Les fiches supprimées par l'application.
        $parTable = [];
        foreach ($supprimees as $s) {
            if (TypesCorbeille::exclue($s['table']) || !$this->schema->existe($s['table'])) {
                continue;
            }
            $parTable[$s['table']][] = $s['cle'];
        }
        foreach ($parTable as $table => $cles) {
            foreach ($this->lire($table, $cles) as $donnees) {
                $cle = $this->cleDe($table, $donnees);
                if (null === $cle || isset($lignes[$cle])) {
                    continue;
                }
                $lignes[$cle] = ['table' => $table, 'cle' => $this->valeursCle($table, $donnees), 'donnees' => $donnees, 'liens' => [], 'rang' => \count($lignes)];
                $niveau[] = $cle;
            }
        }
        // Ordre de l'application d'abord (lire() regroupe par table).
        uasort($lignes, static fn (array $a, array $b): int => $a['rang'] <=> $b['rang']);

        // 2. De proche en proche : ce que la base supprime avec elles, et les liens à rebrancher.
        $liens = [];
        while ([] !== $niveau) {
            $suivant = [];
            $parTable = [];
            foreach ($niveau as $cle) {
                $parTable[$lignes[$cle]['table']][] = $cle;
            }
            foreach ($parTable as $table => $clesParents) {
                foreach ($this->schema->referencesVers($table) as $fk) {
                    $enfant = $fk['table'];
                    if (TypesCorbeille::exclue($enfant)) {
                        continue;
                    }
                    $supprimeAvec = 'CASCADE' === $fk['regle'] || !isset($this->tablesEntites[$enfant]);
                    foreach ($this->enfants($fk, array_map(static fn (string $c): array => $lignes[$c]['donnees'], $clesParents)) as [$donneesParent, $donnees]) {
                        $cle = $this->cleDe($enfant, $donnees);
                        if (null === $cle) {
                            continue;
                        }
                        if ($supprimeAvec) {
                            if (!isset($lignes[$cle])) {
                                $lignes[$cle] = ['table' => $enfant, 'cle' => $this->valeursCle($enfant, $donnees), 'donnees' => $donnees, 'liens' => [], 'rang' => \count($lignes)];
                                $suivant[] = $cle;
                            }
                            continue;
                        }
                        $parent = $this->cleDe($table, $donneesParent);
                        if (null !== $parent) {
                            $valeurs = [];
                            foreach ($fk['colonnes'] as $i => $colonne) {
                                $valeurs[$colonne] = $donneesParent[$fk['colonnesCible'][$i]] ?? null;
                            }
                            $liens[] = [$parent, $cle, ['table' => $enfant, 'valeurs' => $valeurs, 'cle' => $this->valeursCle($enfant, $donnees)]];
                        }
                    }
                }
            }
            $niveau = $suivant;
        }

        // Liens des fiches qui restent en place seulement.
        foreach ($liens as [$parent, $enfant, $lien]) {
            if (isset($lignes[$parent]) && !isset($lignes[$enfant])) {
                $lignes[$parent]['liens'][] = $lien;
            }
        }

        return $this->grouper($lignes);
    }

    /**
     * @param array<string, array{table: string, cle: array<string, mixed>, donnees: array<string, mixed>, liens: list<array<string, mixed>>, rang: int}> $lignes
     *
     * @return list<list<array{table: string, cle: array<string, mixed>, donnees: array<string, mixed>, liens: list<array<string, mixed>>, racine: bool}>>
     */
    private function grouper(array $lignes): array
    {
        // Qui pointe vers qui, parmi les lignes copiées.
        $parents = [];
        // Parents dont la ligne dépend en cascade (elle leur appartient : un fichier à sa ligne de commande).
        $proprietaires = [];
        $chef = [];
        foreach (array_keys($lignes) as $cle) {
            $chef[$cle] = $cle;
            $parents[$cle] = [];
            $proprietaires[$cle] = [];
        }
        $trouver = static function (string $c) use (&$chef): string {
            while ($chef[$c] !== $c) {
                $chef[$c] = $chef[$chef[$c]];
                $c = $chef[$c];
            }

            return $c;
        };
        foreach ($lignes as $cle => $ligne) {
            foreach ($this->schema->referencesDe($ligne['table']) as $fk) {
                if ($fk['colonnesCible'] !== $this->schema->clePrimaire($fk['cible'])) {
                    continue;
                }
                $valeurs = [];
                foreach ($fk['colonnes'] as $colonne) {
                    $valeurs[] = $ligne['donnees'][$colonne] ?? null;
                }
                if (\in_array(null, $valeurs, true)) {
                    continue;
                }
                $cible = self::cle($fk['cible'], $valeurs);
                if ($cible === $cle || !isset($lignes[$cible])) {
                    continue;
                }
                $parents[$cle][] = $cible;
                if ('CASCADE' === $fk['regle']) {
                    $proprietaires[$cle][] = $cible;
                }
                $chef[$trouver($cle)] = $trouver($cible);
            }
        }

        $composantes = [];
        foreach (array_keys($lignes) as $cle) {
            $composantes[$trouver($cle)][] = $cle;
        }

        $groupes = [];
        foreach ($composantes as $membres) {
            // Racine : la fiche (non rattachée) qui n'appartient à aucune autre ligne du groupe : le
            // client avec ses adresses, la page du site avec ses images. À défaut, le sommet : la ligne
            // de commande retirée avec les fichiers du client. Un groupe dont la racine est un élément
            // rattaché sans fichier n'est pas gardé : c'est une modification (ligne retirée d'un devis,
            // tâches refaites par le planning avec leurs rappels).
            $racine = null;
            $sommet = null;
            $fichiers = false;
            foreach ($membres as $cle) {
                $table = $lignes[$cle]['table'];
                if ([] === $parents[$cle]) {
                    $sommet ??= $cle;
                }
                if (!TypesCorbeille::rattachee($table, isset($this->tablesEntites[$table])) && [] === $proprietaires[$cle]) {
                    $racine ??= $cle;
                }
                $fichiers = $fichiers || [] !== TypesCorbeille::fichiers($table, $lignes[$cle]['donnees']);
            }
            $racine ??= $sommet ?? $membres[0];
            $tableRacine = $lignes[$racine]['table'];
            if (TypesCorbeille::rattachee($tableRacine, isset($this->tablesEntites[$tableRacine])) && !$fichiers) {
                continue;
            }
            $groupe = [];
            foreach ($this->ordonner($membres, $parents) as $cle) {
                $groupe[] = [
                    'table' => $lignes[$cle]['table'],
                    'cle' => $lignes[$cle]['cle'],
                    'donnees' => $lignes[$cle]['donnees'],
                    'liens' => $lignes[$cle]['liens'],
                    'racine' => $cle === $racine,
                ];
            }
            $groupes[] = $groupe;
        }

        return $groupes;
    }

    /**
     * Ordre de remise en place : une ligne après celles vers qui elle pointe.
     *
     * @param list<string>                $membres
     * @param array<string, list<string>> $parents
     *
     * @return list<string>
     */
    private function ordonner(array $membres, array $parents): array
    {
        $restants = array_fill_keys($membres, true);
        $ordre = [];
        while ([] !== $restants) {
            $avance = false;
            foreach (array_keys($restants) as $cle) {
                $attend = array_filter($parents[$cle], static fn (string $p): bool => isset($restants[$p]));
                if ([] === $attend) {
                    $ordre[] = $cle;
                    unset($restants[$cle]);
                    $avance = true;
                }
            }
            // Boucle (fiche qui pointe vers elle-même par un détour) : dans l'ordre restant.
            if (!$avance) {
                $cle = array_key_first($restants);
                $ordre[] = $cle;
                unset($restants[$cle]);
            }
        }

        return $ordre;
    }

    /**
     * Lignes d'une table par leur clé primaire.
     *
     * @param list<array<string, mixed>> $cles
     *
     * @return list<array<string, mixed>>
     */
    private function lire(string $table, array $cles): array
    {
        $primaire = $this->schema->clePrimaire($table);
        if ([] === $primaire) {
            return [];
        }
        $q = $this->connexion->quoteSingleIdentifier(...);
        $lignes = [];
        if (1 === \count($primaire)) {
            $valeurs = array_values(array_unique(array_map(static fn (array $c): mixed => $c[$primaire[0]] ?? null, $cles), \SORT_REGULAR));
            foreach (array_chunk(array_filter($valeurs, static fn (mixed $v): bool => null !== $v), self::PAQUET) as $paquet) {
                array_push($lignes, ...$this->connexion->fetchAllAssociative(
                    \sprintf('SELECT * FROM %s WHERE %s IN (?)', $q($table), $q($primaire[0])),
                    [$paquet],
                    [\is_int($paquet[0]) ? ArrayParameterType::INTEGER : ArrayParameterType::STRING],
                ));
            }

            return $lignes;
        }
        foreach ($cles as $cle) {
            $conditions = [];
            $parametres = [];
            foreach ($primaire as $colonne) {
                $conditions[] = $q($colonne).' = ?';
                $parametres[] = $cle[$colonne] ?? null;
            }
            $ligne = $this->connexion->fetchAssociative(\sprintf('SELECT * FROM %s WHERE %s', $q($table), implode(' AND ', $conditions)), $parametres);
            if (false !== $ligne) {
                $lignes[] = $ligne;
            }
        }

        return $lignes;
    }

    /**
     * Lignes de la table enfant qui pointent vers ces lignes parentes.
     *
     * @param array{table: string, colonnes: list<string>, cible: string, colonnesCible: list<string>, regle: string} $fk
     * @param list<array<string, mixed>>                                                                            $parents
     *
     * @return list<array{0: array<string, mixed>, 1: array<string, mixed>}> [ligne parente, ligne enfant]
     */
    private function enfants(array $fk, array $parents): array
    {
        $q = $this->connexion->quoteSingleIdentifier(...);
        $resultat = [];
        if (1 === \count($fk['colonnes'])) {
            $parValeur = [];
            foreach ($parents as $p) {
                $v = $p[$fk['colonnesCible'][0]] ?? null;
                if (null !== $v) {
                    $parValeur[(string) $v] = $p;
                }
            }
            foreach (array_chunk(array_keys($parValeur), self::PAQUET) as $paquet) {
                $valeurs = array_map(static fn (int|string $v): string => (string) $v, $paquet);
                foreach ($this->connexion->fetchAllAssociative(
                    \sprintf('SELECT * FROM %s WHERE %s IN (?)', $q($fk['table']), $q($fk['colonnes'][0])),
                    [$valeurs],
                    [ArrayParameterType::STRING],
                ) as $enfant) {
                    $resultat[] = [$parValeur[(string) $enfant[$fk['colonnes'][0]]] ?? $parents[0], $enfant];
                }
            }

            return $resultat;
        }
        foreach ($parents as $p) {
            $conditions = [];
            $parametres = [];
            foreach ($fk['colonnes'] as $i => $colonne) {
                $conditions[] = $q($colonne).' = ?';
                $parametres[] = $p[$fk['colonnesCible'][$i]] ?? null;
            }
            if (\in_array(null, $parametres, true)) {
                continue;
            }
            foreach ($this->connexion->fetchAllAssociative(\sprintf('SELECT * FROM %s WHERE %s', $q($fk['table']), implode(' AND ', $conditions)), $parametres) as $enfant) {
                $resultat[] = [$p, $enfant];
            }
        }

        return $resultat;
    }

    /** @param array<string, mixed> $donnees */
    private function cleDe(string $table, array $donnees): ?string
    {
        $valeurs = [];
        foreach ($this->schema->clePrimaire($table) as $colonne) {
            $valeurs[] = $donnees[$colonne] ?? null;
        }

        return [] === $valeurs || \in_array(null, $valeurs, true) ? null : self::cle($table, $valeurs);
    }

    /**
     * @param array<string, mixed> $donnees
     *
     * @return array<string, mixed>
     */
    private function valeursCle(string $table, array $donnees): array
    {
        $cle = [];
        foreach ($this->schema->clePrimaire($table) as $colonne) {
            $cle[$colonne] = $donnees[$colonne] ?? null;
        }

        return $cle;
    }

    /** @param list<mixed> $valeurs */
    public static function cle(string $table, array $valeurs): string
    {
        return $table.'|'.implode("\x1f", array_map(static fn (mixed $v): string => bin2hex((string) $v), $valeurs));
    }

    /**
     * Clé primaire lisible : « 42 », ou en JSON quand elle a plusieurs colonnes.
     *
     * @param array<string, mixed> $cle
     */
    public static function cleLisible(array $cle): string
    {
        if (1 === \count($cle)) {
            $v = reset($cle);

            return \is_string($v) && !mb_check_encoding($v, 'UTF-8') ? bin2hex($v) : (string) $v;
        }

        return (string) json_encode(array_map(static fn (mixed $v): mixed => \is_string($v) && !mb_check_encoding($v, 'UTF-8') ? bin2hex($v) : $v, $cle), \JSON_UNESCAPED_UNICODE);
    }

    /**
     * Colonnes à ranger : sans les colonnes sensibles, valeurs binaires en
     * base64 (ou confiées à $fichier au-delà de TAILLE_MAX_EN_LIGNE, qui
     * rend le nom du fichier).
     *
     * @param array<string, mixed>           $donnees
     * @param callable(string): string|null $fichier
     *
     * @return array<string, mixed>
     */
    public static function encoder(string $table, array $donnees, ?callable $fichier = null): array
    {
        $resultat = [];
        foreach ($donnees as $colonne => $valeur) {
            if (TypesCorbeille::sensible($table, (string) $colonne)[0]) {
                continue;
            }
            if (\is_string($valeur) && !mb_check_encoding($valeur, 'UTF-8')) {
                $valeur = null !== $fichier && \strlen($valeur) > self::TAILLE_MAX_EN_LIGNE
                    ? [self::FICHIER_BINAIRE => $fichier($valeur)]
                    : [self::BINAIRE => base64_encode($valeur)];
            }
            $resultat[$colonne] = $valeur;
        }

        return $resultat;
    }

    /**
     * Valeurs prêtes à remettre en base : binaires décodés, colonnes
     * sensibles remplies (vide ou valeur neuve).
     *
     * @param array<string, mixed>          $donnees
     * @param callable(string): string|null $lireFichier
     *
     * @return array<string, mixed>
     */
    public static function decoder(string $table, array $donnees, ?callable $lireFichier = null): array
    {
        $resultat = [];
        foreach ($donnees as $colonne => $valeur) {
            if (\is_array($valeur) && isset($valeur[self::BINAIRE])) {
                $valeur = (string) base64_decode((string) $valeur[self::BINAIRE], true);
            } elseif (\is_array($valeur) && isset($valeur[self::FICHIER_BINAIRE])) {
                if (null === $lireFichier) {
                    throw new \RuntimeException('Contenu rangé à part introuvable.');
                }
                $valeur = $lireFichier((string) $valeur[self::FICHIER_BINAIRE]);
            }
            $resultat[$colonne] = $valeur;
        }
        foreach (TypesCorbeille::SENSIBLES[$table] ?? [] as $colonne => $remplacement) {
            if (!\array_key_exists($colonne, $resultat)) {
                $resultat[$colonne] = 'aleatoire' === $remplacement ? bin2hex(random_bytes(12)) : null;
            }
        }

        return $resultat;
    }
}
