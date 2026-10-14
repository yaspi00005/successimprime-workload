<?php

namespace App\Service\Sondages;

/**
 * Sondage envoyé à la livraison d'une commande : un modèle fixe et court
 * (docs/sondages.md). Note globale, une note par article, respect du
 * délai, accueil, recommandation et proposition libre.
 */
final class SondageCommande
{
    public const GLOBALE = 'globale';
    public const DELAI = 'delai';
    public const ACCUEIL = 'accueil';
    public const NPS = 'nps';
    public const PROPOSITION = 'proposition';

    /** Après ce délai, le lien de l'invitation n'ouvre plus le sondage. */
    public const JOURS_POUR_REPONDRE = 60;

    /**
     * Les questions du modèle, sous la même forme que celles d'un sondage
     * construit par l'admin (les notes par article sont à part).
     *
     * @return list<array{cle: string, type: string, libelle: string, choix: list<string>, obligatoire: bool}>
     */
    public static function questions(): array
    {
        return [
            ['cle' => self::GLOBALE, 'type' => Questions::ETOILES, 'libelle' => 'Votre note pour cette commande', 'choix' => [], 'obligatoire' => true],
            ['cle' => self::DELAI, 'type' => Questions::ETOILES, 'libelle' => 'Respect du délai', 'choix' => [], 'obligatoire' => false],
            ['cle' => self::ACCUEIL, 'type' => Questions::ETOILES, 'libelle' => 'Accueil', 'choix' => [], 'obligatoire' => false],
            ['cle' => self::NPS, 'type' => Questions::NPS, 'libelle' => 'Recommanderiez-vous Succès Imprim à un proche ?', 'choix' => [], 'obligatoire' => false],
            ['cle' => self::PROPOSITION, 'type' => Questions::TEXTE, 'libelle' => Questions::PROPOSITION, 'choix' => [], 'obligatoire' => false],
        ];
    }

    /**
     * Contrôle les réponses : la note globale est obligatoire, une note
     * d'article ne vaut que pour un article de la commande.
     *
     * @param list<string>         $articles identifiants publics des lignes de la commande
     * @param array<string, mixed> $saisie   [cle => valeur], et 'articles' => [identifiant => note]
     *
     * @return array{reponses: array<string, int|string>, articles: array<string, int>}
     *
     * @throws \DomainException message à montrer
     */
    public static function valider(array $articles, array $saisie): array
    {
        $reponses = Questions::valider(self::questions(), $saisie);
        $notes = [];
        $brut = \is_array($saisie['articles'] ?? null) ? $saisie['articles'] : [];
        foreach ($articles as $id) {
            $note = Questions::entier($brut[$id] ?? null, 1, 5);
            if (null !== $note) {
                $notes[$id] = $note;
            }
        }

        return ['reponses' => $reponses, 'articles' => $notes];
    }
}
