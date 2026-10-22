<?php

namespace App\Service\Sondages;

/**
 * Questions d'un sondage (docs/sondages.md) : les six types possibles, la
 * mise en forme de ce que l'admin a saisi, et le contrôle des réponses.
 *
 * Une question est un tableau {cle, type, libelle, choix, obligatoire},
 * gardé tel quel dans Sondage::questions. Une réponse est rangée sous la
 * clé de sa question.
 */
final class Questions
{
    public const ETOILES = 'etoiles';
    public const NPS = 'nps';
    public const CHOIX_UNIQUE = 'choix_unique';
    public const CHOIX_MULTIPLE = 'choix_multiple';
    public const OUI_NON = 'oui_non';
    public const TEXTE = 'texte';

    public const TYPES = [
        self::ETOILES => 'Note de 1 à 5 étoiles',
        self::NPS => 'Recommandation de 0 à 10',
        self::CHOIX_UNIQUE => 'Choix unique',
        self::CHOIX_MULTIPLE => 'Choix multiple',
        self::OUI_NON => 'Oui ou non',
        self::TEXTE => 'Texte libre',
    ];

    public const QUESTIONS_MAX = 20;
    public const CHOIX_MAX = 10;
    public const LIBELLE_MAX = 200;
    public const CHOIX_LONGUEUR_MAX = 80;
    public const TEXTE_MAX = 1000;

    /** Libellé de la question libre, repris dans tous les sondages. */
    public const PROPOSITION = 'Votre proposition pour nous améliorer';

    /**
     * Questions proposées pour un nouveau sondage : note globale, NPS et
     * proposition libre (ce que l'analyse lit en premier).
     *
     * @return list<array{cle: string, type: string, libelle: string, choix: list<string>, obligatoire: bool}>
     */
    public static function modeleParDefaut(): array
    {
        return [
            ['cle' => 'q1', 'type' => self::ETOILES, 'libelle' => 'Dans l\'ensemble, êtes-vous satisfait de Succès Imprim ?', 'choix' => [], 'obligatoire' => true],
            ['cle' => 'q2', 'type' => self::NPS, 'libelle' => 'Recommanderiez-vous Succès Imprim à un proche ?', 'choix' => [], 'obligatoire' => false],
            ['cle' => 'q3', 'type' => self::TEXTE, 'libelle' => self::PROPOSITION, 'choix' => [], 'obligatoire' => false],
        ];
    }

    /**
     * Met en forme les questions saisies par l'admin : lignes vides
     * écartées, choix un par ligne, clés q1, q2… dans l'ordre.
     *
     * @param list<array<string, mixed>> $saisie [{type, libelle, choix (texte, un par ligne), obligatoire}]
     *
     * @return list<array{cle: string, type: string, libelle: string, choix: list<string>, obligatoire: bool}>
     *
     * @throws \DomainException message à montrer
     */
    public static function normaliser(array $saisie): array
    {
        $questions = [];
        foreach ($saisie as $q) {
            if (!\is_array($q)) {
                continue;
            }
            $libelle = self::ligne((string) ($q['libelle'] ?? ''), self::LIBELLE_MAX);
            if ('' === $libelle) {
                continue;
            }
            $type = (string) ($q['type'] ?? '');
            if (!isset(self::TYPES[$type])) {
                throw new \DomainException(\sprintf('Type de question inconnu pour « %s ».', $libelle));
            }
            $choix = [];
            if (\in_array($type, [self::CHOIX_UNIQUE, self::CHOIX_MULTIPLE], true)) {
                $brut = \is_array($q['choix'] ?? null) ? $q['choix'] : preg_split('/\R/u', (string) ($q['choix'] ?? ''));
                foreach ($brut as $c) {
                    $c = self::ligne((string) $c, self::CHOIX_LONGUEUR_MAX);
                    if ('' !== $c && !\in_array($c, $choix, true)) {
                        $choix[] = $c;
                    }
                }
                if (\count($choix) < 2) {
                    throw new \DomainException(\sprintf('« %s » : donnez au moins deux choix, un par ligne.', $libelle));
                }
                if (\count($choix) > self::CHOIX_MAX) {
                    throw new \DomainException(\sprintf('« %s » : %d choix au plus.', $libelle, self::CHOIX_MAX));
                }
            }
            $questions[] = [
                'cle' => 'q'.(\count($questions) + 1),
                'type' => $type,
                'libelle' => $libelle,
                'choix' => $choix,
                'obligatoire' => filter_var($q['obligatoire'] ?? false, \FILTER_VALIDATE_BOOLEAN),
            ];
        }
        if ([] === $questions) {
            throw new \DomainException('Ajoutez au moins une question.');
        }
        if (\count($questions) > self::QUESTIONS_MAX) {
            throw new \DomainException(\sprintf('%d questions au plus : un sondage court a plus de réponses.', self::QUESTIONS_MAX));
        }

        return $questions;
    }

    /**
     * Contrôle les réponses envoyées. Une question facultative sans
     * réponse est absente du résultat.
     *
     * @param list<array{cle: string, type: string, libelle: string, choix: list<string>, obligatoire: bool}> $questions
     * @param array<string, mixed>                                                                          $saisie    [cle => valeur]
     *
     * @return array<string, int|string|list<string>> [cle => valeur propre]
     *
     * @throws \DomainException message à montrer
     */
    public static function valider(array $questions, array $saisie): array
    {
        $reponses = [];
        foreach ($questions as $q) {
            $valeur = self::valeur($q, $saisie[$q['cle']] ?? null);
            if (null === $valeur) {
                if ($q['obligatoire']) {
                    throw new \DomainException(\sprintf('Répondez à « %s ».', $q['libelle']));
                }
                continue;
            }
            $reponses[$q['cle']] = $valeur;
        }
        if ([] === $reponses) {
            throw new \DomainException('Répondez au moins à une question.');
        }

        return $reponses;
    }

    /**
     * Valeur propre d'une réponse, ou null si elle est vide ou hors des
     * valeurs permises (une valeur bricolée ne passe pas).
     *
     * @param array{type: string, choix: list<string>} $question
     *
     * @return int|string|list<string>|null
     */
    public static function valeur(array $question, mixed $brut): int|string|array|null
    {
        return match ($question['type']) {
            self::ETOILES => self::entier($brut, 1, 5),
            self::NPS => self::entier($brut, 0, 10),
            self::OUI_NON => \in_array($brut, ['oui', 'non'], true) ? $brut : null,
            self::CHOIX_UNIQUE => \is_string($brut) && \in_array($brut, $question['choix'], true) ? $brut : null,
            self::CHOIX_MULTIPLE => self::plusieurs($brut, $question['choix']),
            self::TEXTE => \is_string($brut) && '' !== trim($brut) ? mb_substr(trim($brut), 0, self::TEXTE_MAX) : null,
            default => null,
        };
    }

    /** Entier entre $min et $max, ou null (« 4 » du formulaire compris). */
    public static function entier(mixed $brut, int $min, int $max): ?int
    {
        if (\is_string($brut)) {
            $brut = trim($brut);
        }
        if (\is_int($brut) || (\is_string($brut) && '' !== $brut && ctype_digit($brut))) {
            $n = (int) $brut;

            return $n >= $min && $n <= $max ? $n : null;
        }

        return null;
    }

    /**
     * Première question d'un type (note globale, NPS, proposition) : c'est
     * elle que l'analyse retient.
     *
     * @param list<array{cle: string, type: string}> $questions
     */
    public static function premiere(array $questions, string $type): ?string
    {
        foreach ($questions as $q) {
            if ($q['type'] === $type) {
                return $q['cle'];
            }
        }

        return null;
    }

    /**
     * @param list<string> $choix
     *
     * @return list<string>|null
     */
    private static function plusieurs(mixed $brut, array $choix): ?array
    {
        if (!\is_array($brut)) {
            return null;
        }
        // Dans l'ordre des choix, sans doublon ni valeur inventée.
        $retenus = array_values(array_filter($choix, static fn (string $c): bool => \in_array($c, $brut, true)));

        return [] === $retenus ? null : $retenus;
    }

    private static function ligne(string $texte, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $texte)), 0, $max);
    }
}
