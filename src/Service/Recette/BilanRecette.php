<?php

namespace App\Service\Recette;

/**
 * Résultat d'une recette des accès : comptes essayés, pages demandées,
 * matrice compte × page et constats (fuites, pages à examiner, erreurs).
 */
final class BilanRecette
{
    /** Fuite certaine : une donnée ou une page ouverte sans le droit. */
    public const FUITE = 'fuite';
    /** Écart avec la matrice des menus ou montant repéré : à relire. */
    public const A_EXAMINER = 'a_examiner';
    /** Erreur 500 : la page ne s'ouvre pas. */
    public const ERREUR = 'erreur';
    /** Remarque sans risque (écart connu et voulu, page qui écrit en lecture). */
    public const INFO = 'info';

    public const GRAVITES = [
        self::FUITE => 'Fuite',
        self::ERREUR => 'Erreur',
        self::A_EXAMINER => 'À examiner',
        self::INFO => 'Remarque',
    ];

    /** @var array<string, array{libelle: string, droits: list<string>}> par identifiant */
    public array $comptes = [];

    /** @var array<string, array{route: string, onglet: ?string}> par adresse */
    public array $pages = [];

    /** @var array<string, array<string, string>> adresse => compte => case (Reponse::OUVERT…) */
    public array $matrice = [];

    /** @var array<string, string> route => raison */
    public array $ignorees = [];

    /** @var list<array{gravite: string, type: string, route: string, chemin: string, compte: string, detail: string}> */
    public array $constats = [];

    /** @var list<array{controle: string, resultat: string, ok: bool}> */
    public array $controles = [];

    public function constater(string $gravite, string $type, string $route, string $chemin, string $compte, string $detail): void
    {
        $this->constats[] = ['gravite' => $gravite, 'type' => $type, 'route' => $route, 'chemin' => $chemin, 'compte' => $compte, 'detail' => $detail];
    }

    public function controler(string $controle, bool $ok, string $resultat = ''): void
    {
        $this->controles[] = ['controle' => $controle, 'resultat' => $resultat, 'ok' => $ok];
    }

    /**
     * @return array<string, int> gravité => nombre
     */
    public function compterConstats(): array
    {
        $nombres = array_fill_keys(array_keys(self::GRAVITES), 0);
        foreach ($this->constats as $c) {
            ++$nombres[$c['gravite']];
        }

        return $nombres;
    }

    /**
     * Pour chaque compte : nombre de pages par case de la matrice.
     *
     * @return array<string, array<string, int>>
     */
    public function resumeParCompte(): array
    {
        $resume = [];
        foreach (array_keys($this->comptes) as $compte) {
            $resume[$compte] = array_fill_keys(array_keys(Reponse::LETTRES), 0);
        }
        foreach ($this->matrice as $cases) {
            foreach ($cases as $compte => $etat) {
                if (isset($resume[$compte][$etat])) {
                    ++$resume[$compte][$etat];
                }
            }
        }

        return $resume;
    }

    public function sansFuiteNiErreur(): bool
    {
        $n = $this->compterConstats();

        return 0 === $n[self::FUITE] && 0 === $n[self::ERREUR] && [] === array_filter($this->controles, static fn (array $c): bool => !$c['ok']);
    }
}
