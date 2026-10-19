<?php

namespace App\Service\DossiersClients;

/**
 * « Qui voit quoi » dans les dossiers des clients (maquette DS04) : pour
 * chaque rôle, les sous-dossiers qu'il voit, ceux où il ajoute, ceux où il
 * supprime. « * » veut dire tous (y compris « Autres dossiers ») ; sinon
 * une liste de clés du modèle.
 *
 * « Supprimer » ne veut jamais dire effacer un fichier de l'atelier : c'est
 * retirer quelque chose de l'ERP (le lien avec une commande, un fichier de
 * la liste « à classer », une publication en ligne). L'ERP ne supprime ni
 * ne déplace aucun fichier du disque.
 *
 * Les droits d'une personne sont la réunion de ceux de ses rôles (rôle de
 * base et rôles en base, User::getRoles). La Direction (ROLE_ADMIN) a
 * toujours tout. Le droit par personne PERSONNE (Réglages › Utilisateurs)
 * donne « voir et ajouter partout » à quelqu'un, quel que soit son rôle.
 *
 * Classe pure, testée sans base.
 */
final class DroitsDossiers
{
    public const TOUS = '*';
    public const ACTIONS = ['voir', 'ajouter', 'supprimer'];

    /** Droit par personne (catalogue Droits) : voir et ajouter dans tous les sous-dossiers. */
    public const PERSONNE = 'ROLE_DOSSIERS_CLIENTS';

    /** Matrice de la maquette DS04. */
    public const DEFAUT = [
        'ROLE_ADMIN' => ['voir' => self::TOUS, 'ajouter' => self::TOUS, 'supprimer' => self::TOUS],
        'ROLE_GRAPHISTE' => ['voir' => self::TOUS, 'ajouter' => self::TOUS, 'supprimer' => []],
        'ROLE_CAISSE_COMMANDE' => ['voir' => self::TOUS, 'ajouter' => ['recus', 'documents'], 'supprimer' => []],
        'ROLE_PRODUCTION' => ['voir' => ['prets'], 'ajouter' => [], 'supprimer' => []],
        'ROLE_LIVREUR' => ['voir' => [], 'ajouter' => [], 'supprimer' => []],
    ];

    /** @var array<string, array{voir: string|list<string>, ajouter: string|list<string>, supprimer: string|list<string>}> */
    private array $matrice;

    /**
     * @param array<mixed>|null $matrice réglage enregistré (null : la matrice par défaut)
     * @param list<string>      $cles    clés du modèle de sous-dossiers
     */
    public function __construct(?array $matrice, private readonly array $cles)
    {
        $this->matrice = self::lire($matrice ?? self::DEFAUT, $cles) + self::lire(self::DEFAUT, $cles);
        $this->matrice['ROLE_ADMIN'] = ['voir' => self::TOUS, 'ajouter' => self::TOUS, 'supprimer' => self::TOUS];
    }

    /**
     * Garde ce qui est valide : rôle ROLE_…, « * » ou des clés connues.
     *
     * @param array<mixed> $brut
     * @param list<string> $cles
     *
     * @return array<string, array{voir: string|list<string>, ajouter: string|list<string>, supprimer: string|list<string>}>
     */
    public static function lire(array $brut, array $cles): array
    {
        $matrice = [];
        foreach ($brut as $role => $ligne) {
            if (!\is_string($role) || !preg_match('/^ROLE_[A-Z0-9_]{1,60}$/', $role) || !\is_array($ligne)) {
                continue;
            }
            foreach (self::ACTIONS as $action) {
                $v = $ligne[$action] ?? [];
                $matrice[$role][$action] = self::TOUS === $v ? self::TOUS : array_values(array_intersect($cles, \is_array($v) ? $v : []));
            }
            // Ajouter ou supprimer suppose de voir.
            foreach (['ajouter', 'supprimer'] as $action) {
                if (self::TOUS !== $matrice[$role]['voir']) {
                    $matrice[$role][$action] = array_values(array_intersect(self::TOUS === $matrice[$role][$action] ? $cles : $matrice[$role][$action], $matrice[$role]['voir']));
                }
            }
        }

        return $matrice;
    }

    /** @return array<string, array{voir: string|list<string>, ajouter: string|list<string>, supprimer: string|list<string>}> */
    public function matrice(): array
    {
        return $this->matrice;
    }

    /**
     * Ce que permettent ces rôles (User::getRoles), action par action :
     * true pour tout, sinon la liste des clés.
     *
     * @param list<string> $roles
     *
     * @return array{voir: true|list<string>, ajouter: true|list<string>, supprimer: true|list<string>}
     */
    public function pour(array $roles): array
    {
        $effectifs = ['voir' => [], 'ajouter' => [], 'supprimer' => []];
        if (\in_array('ROLE_ADMIN', $roles, true)) {
            return ['voir' => true, 'ajouter' => true, 'supprimer' => true];
        }
        $lignes = array_values(array_intersect_key($this->matrice, array_flip($roles)));
        if (\in_array(self::PERSONNE, $roles, true)) {
            $lignes[] = ['voir' => self::TOUS, 'ajouter' => self::TOUS, 'supprimer' => []];
        }
        foreach ($lignes as $ligne) {
            foreach (self::ACTIONS as $action) {
                if (true === $effectifs[$action]) {
                    continue;
                }
                $effectifs[$action] = self::TOUS === $ligne[$action] ? true : array_values(array_unique([...$effectifs[$action], ...$ligne[$action]]));
            }
        }

        return $effectifs;
    }

    /**
     * @param list<string> $roles
     */
    public function peut(array $roles, string $action, string $cle): bool
    {
        $droit = $this->pour($roles)[$action] ?? [];
        if (true === $droit) {
            return true;
        }

        // « Autres dossiers » : seulement pour qui voit tout.
        return ModeleSousDossiers::AUTRES !== $cle && \in_array($cle, $droit, true);
    }

    /**
     * Peut-il faire cette action dans au moins un sous-dossier ?
     *
     * @param list<string> $roles
     */
    public function peutQuelquePart(array $roles, string $action): bool
    {
        $droit = $this->pour($roles)[$action] ?? [];

        return true === $droit || [] !== $droit;
    }

    /**
     * Texte d'une case de la matrice : « tout », « 04 Prêts à imprimer
     * seulement », « 02 et 06 », « non ».
     *
     * @param string|list<string> $valeur
     */
    public static function resumer(string|array $valeur, ModeleSousDossiers $modele, string $action): string
    {
        if (self::TOUS === $valeur) {
            return 'voir' === $action ? 'tout' : 'oui';
        }
        if ([] === $valeur) {
            return 'non';
        }
        if (1 === \count($valeur)) {
            return $modele->libelle($valeur[0]).('voir' === $action ? ' seulement' : '');
        }
        $numeros = array_map(static fn (string $c): string => $modele->entree($c)['numero'] ?? $c, $valeur);
        $dernier = array_pop($numeros);

        return implode(', ', $numeros).' et '.$dernier;
    }

    /** @return list<string> */
    public function cles(): array
    {
        return $this->cles;
    }
}
