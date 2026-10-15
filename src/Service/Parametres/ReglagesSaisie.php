<?php

namespace App\Service\Parametres;

/**
 * Tuile Réglages › Ventes › « Livraison et objet » : les paliers des frais
 * de livraison proposés à la saisie des devis et des commandes, et les
 * objets suggérés (docs/documents-livraisons.md).
 *
 * Les lectures sont statiques et pures pour être testées sans base.
 */
final class ReglagesSaisie
{
    public const TUILE = 'saisie';

    public const PALIERS_DEFAUT = '1000, 1500, 2000, 2500';
    public const OBJETS_DEFAUT = 'Impressions, Prestations informatiques, Conception graphique, Signalétique, Textile personnalisé';

    /** Garde-fous de la saisie des paliers. */
    public const PALIER_MAX = 100000;
    public const PALIERS_NOMBRE_MAX = 8;
    public const OBJETS_NOMBRE_MAX = 20;

    public function __construct(private readonly Parametres $parametres)
    {
    }

    /**
     * Paliers en vigueur, du plus petit au plus grand (le plus grand est le
     * maximum). Un réglage illisible redonne les paliers par défaut.
     *
     * @return list<int>
     */
    public function paliers(): array
    {
        return self::lirePaliers((string) ($this->parametres->valeur(self::TUILE, 'paliers_livraison') ?? self::PALIERS_DEFAUT))
            ?? self::lirePaliers(self::PALIERS_DEFAUT) ?? [];
    }

    /** @return list<string> */
    public function objets(): array
    {
        return self::lireObjets((string) ($this->parametres->valeur(self::TUILE, 'objets') ?? self::OBJETS_DEFAUT));
    }

    /**
     * « 1000, 1 500 ; 2000 » => [1000, 1500, 2000]. Vide => aucun palier.
     * null si un morceau n'est pas un montant entier de 1 à PALIER_MAX F,
     * ou s'il y a trop de paliers.
     *
     * @return list<int>|null
     */
    public static function lirePaliers(string $texte): ?array
    {
        $paliers = [];
        foreach (preg_split('/[,;\n]+/', $texte) ?: [] as $morceau) {
            $morceau = str_replace([' ', "\u{a0}", "\u{202f}", 'F', 'f'], '', trim($morceau));
            if ('' === $morceau) {
                continue;
            }
            // « 1.500 » : point des milliers ; « 1500.5 » reste refusé.
            if (1 === preg_match('/^\d{1,3}(\.\d{3})+$/', $morceau)) {
                $morceau = str_replace('.', '', $morceau);
            }
            if (!ctype_digit($morceau) || (int) $morceau < 1 || (int) $morceau > self::PALIER_MAX) {
                return null;
            }
            $paliers[(int) $morceau] = (int) $morceau;
        }
        if (\count($paliers) > self::PALIERS_NOMBRE_MAX) {
            return null;
        }
        sort($paliers);

        return array_values($paliers);
    }

    /** Forme rangée du réglage : « 1000, 1500, 2000, 2500 ». */
    public static function ecrirePaliers(array $paliers): string
    {
        return implode(', ', $paliers);
    }

    /**
     * Choix du formulaire : « Sans frais » puis les paliers. Un montant
     * déjà enregistré qui n'est plus un palier (réglage changé depuis)
     * reste proposé, pour relire et réenregistrer le document tel quel.
     *
     * @param list<int> $paliers
     *
     * @return array<string, int>
     */
    public static function choixFrais(array $paliers, int $actuel = 0): array
    {
        $montants = $paliers;
        if ($actuel > 0 && !\in_array($actuel, $montants, true)) {
            $montants[] = $actuel;
            sort($montants);
        }

        $choix = ['Sans frais' => 0];
        foreach ($montants as $montant) {
            $choix[self::montant($montant)] = $montant;
        }

        return $choix;
    }

    /**
     * Seuls 0 et les paliers sont permis (et le montant déjà enregistré
     * sur le document, voir choixFrais).
     *
     * @param list<int> $paliers
     */
    public static function fraisPermis(int $montant, array $paliers, int $actuel = 0): bool
    {
        return 0 === $montant || \in_array($montant, $paliers, true) || ($actuel > 0 && $montant === $actuel);
    }

    /**
     * Objets suggérés, séparés par des virgules ou des retours à la ligne,
     * sans doublon, 150 caractères au plus chacun.
     *
     * @return list<string>
     */
    public static function lireObjets(string $texte): array
    {
        $objets = [];
        foreach (preg_split('/[,;\n]+/', $texte) ?: [] as $morceau) {
            $objet = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $morceau)), 0, 150);
            if ('' !== $objet) {
                $objets[mb_strtolower($objet)] ??= $objet;
            }
        }

        return \array_slice(array_values($objets), 0, self::OBJETS_NOMBRE_MAX);
    }

    /** « 1 500 F » */
    public static function montant(int $montant): string
    {
        return number_format($montant, 0, ',', "\u{a0}")."\u{a0}F";
    }
}
