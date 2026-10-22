<?php

namespace App\Service\Documentation;

/**
 * Lecture simple du code source pour le guide technique : résumé d'un
 * docblock, corps d'une méthode, jeton CSRF, refus d'accès, données
 * sensibles. Ce n'est pas un analyseur PHP : des expressions régulières sur
 * le texte, assez pour signaler ce qu'un auditeur doit aller relire.
 *
 * Tout est statique et pur (sauf la lecture du fichier d'une méthode).
 */
final class AnalyseCode
{
    /** Ce qui, dans le corps d'une action, vérifie un jeton CSRF. */
    private const MOTIFS_CSRF = [
        '/->isCsrfTokenValid\s*\(/',
        '/JetonJson::lire\s*\(/',
        // Un formulaire Symfony porte son jeton (framework.form.csrf_protection).
        '/->createForm(Builder)?\s*\(/',
        '/->createNamedBuilder\s*\(/',
    ];

    /** Ce qui, dans le corps d'une action, écrit en base ou sur le disque. */
    private const MOTIFS_ECRITURE = [
        '/->flush\s*\(/', '/->persist\s*\(/', '/->remove\s*\(/', '/->executeStatement\s*\(/',
        '/->handleRequest\s*\(/', '/->isMethod\s*\(\s*[\'"]POST[\'"]/i', '/->move\s*\(/',
    ];

    /**
     * Début d'un mot du nom ou de l'adresse d'une route => sorte de donnée
     * sensible (« paie » seul : un mot entier, pour ne pas prendre « paiements »).
     *
     * @var array<string, string>
     */
    private const MOTS_SENSIBLES = [
        'paiement' => 'montants', 'facture' => 'montants', 'avoir' => 'montants', 'tresorerie' => 'montants',
        'caisse' => 'montants', 'solde' => 'montants', 'creance' => 'montants', 'comptabilite' => 'montants',
        'depense' => 'montants', 'rentabilite' => 'montants', 'marge' => 'montants', 'cout' => 'montants',
        'statistique' => 'montants', 'encaissement' => 'montants', 'orange' => 'montants', 'immobilisation' => 'montants',
        'rapprochement' => 'montants', 'mouvement' => 'montants', 'plafond' => 'montants', 'finances' => 'montants',
        'bulletin' => 'salaires', 'prime' => 'salaires', 'salaire' => 'salaires',
        'employe' => 'donnees_personnelles', 'pointage' => 'donnees_personnelles', 'conge' => 'donnees_personnelles',
        'utilisateur' => 'donnees_personnelles', 'client' => 'donnees_personnelles', 'prospect' => 'donnees_personnelles',
        'contact' => 'donnees_personnelles', 'message' => 'donnees_personnelles', 'conversation' => 'donnees_personnelles',
        'compte' => 'donnees_personnelles', 'appareil' => 'donnees_personnelles', 'journal' => 'donnees_personnelles',
        'fichier' => 'fichiers', 'telecharger' => 'fichiers', 'pdf' => 'fichiers', 'export' => 'fichiers',
        'photo' => 'fichiers', 'justificatif' => 'fichiers', 'image' => 'fichiers', 'piece' => 'fichiers',
        'sauvegarde' => 'fichiers', 'dossier' => 'fichiers', 'capture' => 'fichiers', 'vocale' => 'fichiers',
    ];

    /** Mots qui ne comptent qu'entiers. */
    private const MOTS_ENTIERS = ['paie' => 'salaires', 'om' => 'montants'];

    /**
     * Première phrase d'un docblock (sans les lignes « @… »), sur une ligne.
     */
    public static function resume(string|false|null $docblock, int $longueur = 400): string
    {
        $texte = self::texteDocblock($docblock);
        if ('' === $texte) {
            return '';
        }
        // Fin de la première phrase : un point suivi d'un espace (pas « docs/x.md » ni « 1.5 »).
        if (preg_match('/^(.+?[.!?])(\s|$)/u', $texte, $m)) {
            $texte = $m[1];
        }

        return self::couper($texte, $longueur);
    }

    /** Première ligne utile d'un docblock. */
    public static function premiereLigne(string|false|null $docblock, int $longueur = 240): string
    {
        foreach (self::lignesDocblock($docblock) as $ligne) {
            if ('' !== $ligne) {
                return self::couper($ligne, $longueur);
            }
        }

        return '';
    }

    /** Texte d'un docblock sur une ligne, sans les étiquettes « @… ». */
    public static function texteDocblock(string|false|null $docblock): string
    {
        $lignes = [];
        foreach (self::lignesDocblock($docblock) as $ligne) {
            if ('' === $ligne) {
                if ([] !== $lignes) {
                    break; // le premier paragraphe seulement
                }
                continue;
            }
            $lignes[] = $ligne;
        }

        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $lignes)));
    }

    /**
     * @return list<string>
     */
    private static function lignesDocblock(string|false|null $docblock): array
    {
        if (!\is_string($docblock) || '' === trim($docblock)) {
            return [];
        }
        $texte = preg_replace(['#^\s*/\*\*#', '#\*/\s*$#'], '', $docblock) ?? '';
        $lignes = [];
        foreach (preg_split('/\R/u', $texte) ?: [] as $ligne) {
            $ligne = trim((string) preg_replace('/^\s*\*\s?/u', '', $ligne));
            if (str_starts_with($ligne, '@')) {
                break;
            }
            $lignes[] = $ligne;
        }

        return $lignes;
    }

    /** Corps d'une méthode (entre ses accolades), lu dans son fichier. */
    public static function corps(\ReflectionMethod $methode): string
    {
        $fichier = $methode->getFileName();
        $debut = $methode->getStartLine();
        $fin = $methode->getEndLine();
        if (false === $fichier || false === $debut || false === $fin || !is_readable($fichier)) {
            return '';
        }
        $lignes = \array_slice(file($fichier) ?: [], $debut - 1, $fin - $debut + 1);

        return self::corpsDepuisSource(implode('', $lignes));
    }

    /** Ce qui suit la première accolade après « function » (la signature est retirée). */
    public static function corpsDepuisSource(string $code): string
    {
        $fonction = strpos($code, 'function');
        $parenthese = false === $fonction ? false : strpos($code, '(', $fonction);
        if (false === $parenthese) {
            return $code;
        }
        // Sauter la liste des arguments (qui peut contenir des attributs avec des accolades).
        $niveau = 0;
        $longueur = \strlen($code);
        for ($i = $parenthese; $i < $longueur; ++$i) {
            if ('(' === $code[$i]) {
                ++$niveau;
            } elseif (')' === $code[$i] && 0 === --$niveau) {
                break;
            }
        }
        $accolade = strpos($code, '{', $i);

        return false === $accolade ? '' : substr($code, $accolade + 1);
    }

    /** Le corps vérifie-t-il un jeton CSRF (directement) ? */
    public static function verifieCsrf(string $corps): bool
    {
        foreach (self::MOTIFS_CSRF as $motif) {
            if (preg_match($motif, $corps)) {
                return true;
            }
        }

        return false;
    }

    /** Le corps écrit-il quelque chose (base, fichier, formulaire traité) ? */
    public static function ecrit(string $corps): bool
    {
        foreach (self::MOTIFS_ECRITURE as $motif) {
            if (preg_match($motif, $corps)) {
                return true;
            }
        }

        return false;
    }

    /** Appels qui ne font que répondre ou lire. */
    private const APPELS_INERTES = ['redirectToRoute', 'redirect', 'json', 'render', 'renderView', 'createNotFoundException', 'createAccessDeniedException', 'generateUrl'];

    /**
     * Le corps ne fait-il que lire et répondre (renvoi vers une autre page,
     * JSON, refus) ? false s'il écrit ou appelle autre chose qu'un accesseur
     * (get…, is…, has…) ; sinon la liste des méthodes de la classe qu'il
     * appelle, à regarder à leur tour ([] : rien d'autre).
     *
     * @return false|list<string>
     */
    public static function inerte(string $corps): false|array
    {
        $corps = (string) preg_replace(['#//[^\n]*#', '#/\*.*?\*/#s'], '', $corps);
        if (self::ecrit($corps) || preg_match('/\bnew\s+(?!(Json|Redirect)?Response\b)[A-Z]/', $corps)) {
            return false;
        }
        $internes = [];
        preg_match_all('/(\$this)?->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $corps, $m, \PREG_SET_ORDER);
        foreach ($m as $appel) {
            $nom = $appel[2];
            if (\in_array($nom, self::APPELS_INERTES, true) || preg_match('/^(get|is|has)[A-Z_]/', $nom)) {
                continue;
            }
            if ('$this' !== ($appel[1] ?? '')) {
                return false; // un service appelé : il peut écrire
            }
            $internes[] = $nom;
        }

        return array_values(array_unique($internes));
    }

    /** Le corps compare-t-il l'en-tête Origin à l'adresse du site (formulaire public sans session) ? */
    public static function verifieOrigine(string $corps): bool
    {
        return 1 === preg_match('/headers->get\(\s*[\'"]Origin[\'"]/', $corps);
    }

    /**
     * Méthodes de la même classe appelées par le corps ($this->nom(...)).
     *
     * @return list<string>
     */
    public static function appelsInternes(string $corps): array
    {
        preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $corps, $m);

        return array_values(array_unique($m[1]));
    }

    /**
     * Arguments de chaque denyAccessUnlessGranted(...) du corps, tels qu'écrits
     * (« 'ROLE_X' » devient ROLE_X ; une constante reste « Classe::NOM »).
     *
     * @return list<string>
     */
    public static function refusAcces(string $corps): array
    {
        return self::premiersArguments('denyAccessUnlessGranted', $corps);
    }

    /**
     * Arguments des isGranted(...) du corps (vérifications au cas par cas).
     *
     * @return list<string>
     */
    public static function verificationsDroits(string $corps): array
    {
        return self::premiersArguments('isGranted', $corps);
    }

    /**
     * @return list<string>
     */
    private static function premiersArguments(string $fonction, string $corps): array
    {
        preg_match_all('/->'.$fonction.'\(\s*([^,)]+)/', $corps, $m);
        $valeurs = [];
        foreach ($m[1] as $brut) {
            $brut = trim($brut);
            if (preg_match('/^[\'"]([^\'"]+)[\'"]$/', $brut, $t)) {
                $valeurs[] = $t[1];
            } elseif (preg_match('/^[A-Za-z_\\\\]+::[A-Z_0-9]+$/', $brut)) {
                $valeurs[] = $brut;
            }
        }

        return array_values(array_unique($valeurs));
    }

    /**
     * La route modifie-t-elle des données ? Une méthode d'écriture déclarée,
     * ou toutes les méthodes permises et un corps qui écrit.
     *
     * @param list<string> $methodes méthodes HTTP de la route ([] : toutes)
     */
    public static function modifieDonnees(array $methodes, string $corps): bool
    {
        if ([] !== array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $methodes)) {
            return true;
        }

        return [] === $methodes && self::ecrit($corps);
    }

    /**
     * Sortes de données sensibles qu'une route peut montrer, d'après son
     * nom et son adresse : montants, salaires, données personnelles, fichiers.
     *
     * @return list<string>
     */
    public static function sensibles(string $nom, string $chemin): array
    {
        $mots = preg_split('/[^a-z0-9]+/', strtolower($nom.' '.$chemin), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $sortes = [];
        foreach ($mots as $mot) {
            if (isset(self::MOTS_ENTIERS[$mot])) {
                $sortes[self::MOTS_ENTIERS[$mot]] = true;
            }
            foreach (self::MOTS_SENSIBLES as $debut => $sorte) {
                if (str_starts_with($mot, $debut)) {
                    $sortes[$sorte] = true;
                }
            }
        }

        return array_keys($sortes);
    }

    public static function couper(string $texte, int $longueur): string
    {
        return mb_strlen($texte) > $longueur ? rtrim(mb_substr($texte, 0, $longueur - 1)).'…' : $texte;
    }
}
