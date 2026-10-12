<?php

namespace App\Service\Planning;

use App\Entity\Echeance;

/**
 * Planning annuel (T03) : une ligne par échéance, ses mois sur l'année,
 * et ce qu'elle coûte dans l'année. Calculé depuis la règle de chaque
 * échéance (pas depuis les tâches, qui ne couvrent que 13 mois). Les
 * tâches de chaque jour restent dans le calendrier : elles rempliraient
 * toutes les cases.
 */
final class PlanningAnnuel
{
    public const MOIS = [1 => 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sept', 'Oct', 'Nov', 'Déc'];
    public const MOIS_LONGS = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    /** Catégories comptées comme achats dans le budget. */
    public const ACHATS = ['stock', 'fournisseurs'];

    /**
     * @param list<Echeance> $echeances
     *
     * @return array{lignes: list<array<string, mixed>>, budget: int, achats: int, reperes: array<int, list<string>>}
     */
    public static function annee(array $echeances, Calendrier $calendrier, int $annee): array
    {
        $du = new \DateTimeImmutable($annee.'-01-01');
        $au = new \DateTimeImmutable($annee.'-12-31');
        $ordre = array_flip(array_keys(Echeance::CATEGORIES));
        $lignes = [];
        foreach ($echeances as $e) {
            if (!$e->isActif() || $e->getFrequence() === Calendrier::JOUR) {
                continue;
            }
            $mois = array_fill(1, 12, 0);
            foreach ($calendrier->occurrences($e->regle(), $du, $au) as $o) {
                ++$mois[(int) $o['date']->format('n')];
            }
            $nombre = array_sum($mois);
            if ($nombre === 0) {
                continue;
            }
            $montant = (int) $e->getMontantEstime();
            $lignes[] = [
                'echeance' => $e,
                'titre' => $e->getTitre(),
                'categorie' => $e->getCategorie(),
                'mois' => $mois,
                'nombre' => $nombre,
                // Présente presque tous les mois : une seule barre continue.
                'barre' => \count(array_filter($mois)) >= 10,
                'montant' => $montant,
                'total' => $montant * $nombre,
                'budget' => self::budget($e->getFrequence(), $mois, $montant),
            ];
        }
        usort($lignes, static fn (array $a, array $b): int => [$ordre[$a['categorie']] ?? 99, $a['titre']] <=> [$ordre[$b['categorie']] ?? 99, $b['titre']]);

        return [
            'lignes' => $lignes,
            'budget' => array_sum(array_column($lignes, 'total')),
            'achats' => array_sum(array_map(static fn (array $l): int => \in_array($l['categorie'], self::ACHATS, true) ? $l['total'] : 0, $lignes)),
            'reperes' => self::reperes($calendrier, $annee),
        ];
    }

    /**
     * Texte de la colonne Budget : « 4 × 1,4 M F », « mensuel », « mars »,
     * « 2 fois ».
     *
     * @param array<int, int> $mois
     */
    public static function budget(string $frequence, array $mois, int $montant): string
    {
        $nombre = array_sum($mois);
        if ($montant > 0) {
            return $nombre === 1 ? self::compact($montant) : $nombre.' × '.self::compact($montant);
        }
        if (\count(array_filter($mois)) >= 10) {
            return match ($frequence) {
                Calendrier::SEMAINE => 'chaque semaine',
                default => 'mensuel',
            };
        }
        $presents = array_keys(array_filter($mois));
        if (\count($presents) === 1) {
            return self::MOIS_LONGS[$presents[0]];
        }
        if (\count($presents) === 2 && $presents[1] === $presents[0] + 1) {
            return self::MOIS_LONGS[$presents[0]].' – '.self::MOIS_LONGS[$presents[1]];
        }

        return $nombre.' fois';
    }

    /** 1 400 000 → « 1,4 M F », 300 000 → « 300 000 F ». */
    public static function compact(int $montant): string
    {
        if ($montant >= 1_000_000) {
            return rtrim(rtrim(number_format($montant / 1_000_000, 1, ',', ' '), '0'), ',').' M F';
        }

        return number_format($montant, 0, ',', ' ').' F';
    }

    /**
     * Repères pour placer les achats : les fêtes mobiles saisies (Ramadan,
     * Tabaski, Maouloud…), la rentrée de septembre et la fin d'année. Les
     * fériés fixes (fête du travail…) ne changent rien aux achats.
     *
     * @return array<int, list<string>>
     */
    public static function reperes(Calendrier $calendrier, int $annee): array
    {
        $reperes = array_fill(1, 12, []);
        $reperes[9][] = 'Rentrée';
        $reperes[12][] = 'Fin d\'année';
        for ($jour = new \DateTimeImmutable($annee.'-01-01'); (int) $jour->format('Y') === $annee; $jour = $jour->modify('+1 day')) {
            $nom = $calendrier->ferie($jour);
            $fixe = Calendrier::FETES_FIXES[$jour->format('m-d')] ?? null;
            if ($nom !== null && $nom !== $fixe && !\in_array($nom, $reperes[(int) $jour->format('n')], true)) {
                $reperes[(int) $jour->format('n')][] = $nom;
            }
        }

        return $reperes;
    }
}
