<?php

namespace App\Service\Achats;

/**
 * Calculs du réapprovisionnement et des alertes d'achat (maquettes SU06
 * et SU07, docs/achats.md) : hausse de prix, retard du fournisseur,
 * intervalle habituel entre deux commandes et commande oubliée, quantité
 * suggérée, tendance de la consommation, courbe du stock et textes des
 * alertes.
 *
 * Tout est statique et pur pour être testé sans base.
 */
final class CalculsAlertes
{
    /*
     * Les quatre règles d'alerte (tuile « Achats et fournisseurs »).
     */
    public const STOCK_BAS = 'stock_bas';
    public const PRIX = 'prix';
    public const RETARD = 'retard';
    public const OUBLI = 'oubli';

    public const REGLES = [self::STOCK_BAS, self::PRIX, self::RETARD, self::OUBLI];

    /*
     * Valeurs par défaut des seuils, d'accord avec Yaya.
     */
    public const HAUSSE_PRIX_DEFAUT = 5;
    public const DEPASSEMENT_OUBLI_DEFAUT = 20;

    /*
     * Une hausse de prix reste signalée 30 jours après la commande qui l'a montrée.
     */
    public const JOURS_ALERTE_PRIX = 30;

    /*
     * Il faut au moins trois commandes (deux intervalles) pour parler
     * d'habitude ; l'historique compté est d'un an.
     */
    public const COMMANDES_POUR_HABITUDE = 3;

    /*
     * Couverture visée par une commande quand on ne connaît pas encore
     * l'intervalle habituel : un mois.
     */
    public const COUVERTURE_DEFAUT = 30;

    /*
     * Gravité, pour la couleur : rouge (agir aujourd'hui), orange (cette
     * semaine), jaune (à regarder).
     */
    public const ROUGE = 'rouge';
    public const ORANGE = 'orange';
    public const JAUNE = 'jaune';

    /**
     * Hausse en % entre le prix précédent et le dernier (une décimale) ;
     * null sans prix précédent.
     */
    public static function hausse(?int $precedent, int $dernier): ?float
    {
        if ($precedent === null || $precedent <= 0 || $dernier <= 0) {
            return null;
        }

        return round(($dernier - $precedent) / $precedent * 100, 1);
    }

    /**
     * Le prix a monté d'au moins le seuil (5 % par défaut).
     */
    public static function estHausse(?int $precedent, int $dernier, int $seuil): bool
    {
        $hausse = self::hausse($precedent, $dernier);

        return $hausse !== null && $hausse > 0 && $hausse >= $seuil;
    }

    /**
     * Jours de retard d'une commande non reçue : 0 tant que la date
     * promise n'est pas passée (alerte dès le lendemain).
     */
    public static function joursRetard(?\DateTimeImmutable $promise, \DateTimeImmutable $aujourdhui): int
    {
        return $promise !== null ? max(0, CalculsAchat::retardJours($promise, $aujourdhui)) : 0;
    }

    /**
     * Intervalle moyen en jours entre les commandes d'un article (une
     * décimale) ; plusieurs commandes le même jour comptent pour une.
     * Null avec moins de $minimum commandes.
     *
     * @param list<\DateTimeImmutable> $dates
     */
    public static function intervalleHabituel(array $dates, int $minimum = self::COMMANDES_POUR_HABITUDE): ?float
    {
        $jours = array_values(array_unique(array_map(static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d'), $dates)));
        sort($jours);

        if (\count($jours) < max(2, $minimum)) {
            return null;
        }

        $premier = new \DateTimeImmutable($jours[0]);
        $dernier = new \DateTimeImmutable($jours[\count($jours) - 1]);

        return round(CalculsAchat::retardJours($premier, $dernier) / (\count($jours) - 1), 1);
    }

    /**
     * Commande oubliée : depuis la dernière commande, l'intervalle
     * habituel est dépassé de plus du pourcentage (20 % par défaut).
     */
    public static function estOubliee(?float $intervalle, int $joursDepuis, int $depassement): bool
    {
        return $intervalle !== null && $intervalle > 0 && $joursDepuis > $intervalle * (1 + max(0, $depassement) / 100);
    }

    /**
     * Unités d'achat à commander, d'après la consommation moyenne : de
     * quoi tenir pendant le délai, la marge et une couverture (l'intervalle
     * habituel, sinon un mois), moins ce qui est disponible, et au moins
     * de quoi revenir au minimum. Sans dépasser le stock maximum à
     * l'arrivée quand il est connu. Toujours au moins une unité.
     */
    public static function quantiteSuggeree(float $consoHebdo, float $disponible, float $stockMin, ?float $stockMax, int $delai, int $marge, ?float $couverture, float $facteur): int
    {
        if ($facteur <= 0) {
            return 1;
        }

        $parJour = max(0.0, $consoHebdo) / 7;
        $jours = $delai + $marge + ($couverture !== null && $couverture > 0 ? $couverture : self::COUVERTURE_DEFAUT);
        $besoin = max($parJour * $jours - $disponible, $stockMin - $disponible + $parJour * $delai, 0.0);

        // À l'arrivée, le stock ne dépasse pas le maximum (s'il en reste une unité au moins).
        if ($stockMax !== null && $stockMax > 0) {
            $plafond = $stockMax - max(0.0, $disponible - $parJour * $delai);
            if ($plafond >= $facteur) {
                $besoin = min($besoin, $plafond);
            }
        }

        return max(1, (int) ceil(round($besoin / $facteur, 6)));
    }

    /**
     * Tendance de la consommation : les 4 dernières semaines comparées à
     * la moyenne des 12, en % (arrondi) ; null sans consommation.
     */
    public static function tendance(float $sorties4Semaines, float $sorties12Semaines): ?int
    {
        if ($sorties12Semaines <= 0) {
            return null;
        }

        return (int) round(($sorties4Semaines / 4) / ($sorties12Semaines / 12) * 100 - 100);
    }

    /**
     * « consommation en hausse de 12 % sur 4 semaines », « stable ».
     */
    public static function texteTendance(?int $tendance): ?string
    {
        return match (true) {
            $tendance === null => null,
            $tendance >= 5 => sprintf('consommation en hausse de %d %% sur 4 semaines', $tendance),
            $tendance <= -5 => sprintf('consommation en baisse de %d %% sur 4 semaines', -$tendance),
            default => 'consommation stable',
        };
    }

    /**
     * Gravité d'une alerte de stock : rouge sous le minimum ou à commander
     * aujourd'hui, orange sinon.
     */
    public static function graviteStock(?string $urgence): string
    {
        return \in_array($urgence, [CalculsAchat::URGENCE_SOUS_MINIMUM, CalculsAchat::URGENCE_MAINTENANT], true) ? self::ROUGE : self::ORANGE;
    }

    /**
     * Titre d'une alerte de stock : « Bâche 510 g sous le minimum »,
     * « Micro-perforé : commander avant le 24/10 ».
     */
    public static function titreStock(string $article, ?string $urgence, ?\DateTimeImmutable $date): string
    {
        return match ($urgence) {
            CalculsAchat::URGENCE_SOUS_MINIMUM => $article.' sous le minimum',
            CalculsAchat::URGENCE_MAINTENANT, null => $article.' : commander maintenant',
            default => $article.' : commander avant le '.$date?->format('d/m'),
        };
    }

    /**
     * « Stock 24,7 m² · 19 jours restants · Dakar Signs livre en 9 j ».
     */
    public static function detailStock(float $disponible, string $unite, ?int $joursRestants, string $fournisseur, int $delai): string
    {
        // « 3 900 feuilles », « 89 pièces » ; les symboles (m², L) restent tels quels.
        $unite = preg_match('/^\p{Ll}{3,}$/u', $unite) ? CalculsAchat::pluriel($unite, $disponible) : $unite;
        $morceaux = [sprintf('Stock %s %s', self::nombre($disponible), $unite)];
        if ($joursRestants !== null) {
            $morceaux[] = sprintf('%d jour%s restant%s', $joursRestants, $joursRestants > 1 ? 's' : '', $joursRestants > 1 ? 's' : '');
        }
        $morceaux[] = sprintf('%s livre en %d j', $fournisseur, $delai);

        return implode(' · ', $morceaux);
    }

    /**
     * « Prix en hausse : poudre DTF +7 % ».
     */
    public static function titrePrix(string $article, float $hausse): string
    {
        return sprintf('Prix en hausse : %s +%s %%', self::minuscule($article), self::nombre(round($hausse)));
    }

    /**
     * « 182 400 F le rouleau au lieu de 170 400 F · Dakar Signs ».
     */
    public static function detailPrix(int $dernier, int $precedent, ?string $uniteAchat, string $fournisseur): string
    {
        return sprintf('%s F %s au lieu de %s F · %s', self::francs($dernier), self::parUnite($uniteAchat), self::francs($precedent), $fournisseur);
    }

    /**
     * « Dakar Signs en retard : CA-2026-0041 ».
     */
    public static function titreRetard(string $fournisseur, ?string $numero): string
    {
        return sprintf('%s en retard%s', $fournisseur, $numero !== null ? ' : '.$numero : '');
    }

    /**
     * « Promise le 18/10 · 2 jours de retard ».
     */
    public static function detailRetard(\DateTimeImmutable $promise, int $jours): string
    {
        return sprintf('Promise le %s · %d jour%s de retard', $promise->format('d/m'), $jours, $jours > 1 ? 's' : '');
    }

    /**
     * Gravité d'un retard : rouge au-delà d'une semaine.
     */
    public static function graviteRetard(int $jours): string
    {
        return $jours > 7 ? self::ROUGE : self::ORANGE;
    }

    /**
     * « Commande oubliée ? Structures roll-up ».
     */
    public static function titreOubli(string $article): string
    {
        return 'Commande oubliée ? '.$article;
    }

    /**
     * « Commandé tous les 61 jours chez Kanté · rien depuis 75 jours (06/08) ».
     */
    public static function detailOubli(float $intervalle, int $joursDepuis, \DateTimeImmutable $derniere, string $fournisseur): string
    {
        return sprintf('Commandé tous les %d jours chez %s · rien depuis %d jours (%s)', (int) round($intervalle), $fournisseur, $joursDepuis, $derniere->format('d/m'));
    }

    /**
     * Message WhatsApp pour prévenir un collègue : « Bonjour Kadiatou,
     * alerte achats : Bâche 510 g sous le minimum. Stock 38,4 m² ·
     * 12 jours restants · Sahel Supplies livre en 3 j. À voir : <lien> ».
     * Le détail passé ne contient pas de montant : le message quitte
     * l'application.
     */
    public static function messageCollegue(?string $prenom, string $titre, ?string $detail, string $lien): string
    {
        return trim(sprintf('Bonjour%s, alerte achats : %s.%s À voir : %s', $prenom !== null && $prenom !== '' ? ' '.$prenom : '', $titre, $detail !== null && trim($detail, ' ·') !== '' ? ' '.trim($detail, ' ·').'.' : '', $lien));
    }

    /**
     * Relance du fournisseur en retard, pour son contact.
     */
    public static function messageRelance(?string $contact, ?string $numero, \DateTimeImmutable $promise): string
    {
        return sprintf(
            'Bonjour%s, notre commande %s devait arriver le %s et nous ne l\'avons pas encore reçue. Pouvez-vous nous donner la date de livraison ? Merci.',
            $contact !== null && $contact !== '' ? ' '.$contact : '',
            $numero ?? '',
            $promise->format('d/m'),
        );
    }

    /**
     * Points de la courbe du stock (maquette SU06) : le stock des jours
     * passés, puis la prévision au rythme de consommation, la ligne du
     * minimum, le jour de commande, la fenêtre du transport et l'arrivée.
     * Les jours sont comptés depuis aujourd'hui (négatifs : passé).
     *
     * @param array<int, float> $historique stock en fin de journée, par jour (−30 … 0)
     *
     * @return array{largeur: int, hauteur: int, passe: string, prevision: string, minimum: ?float, aujourdhui: float, commande: ?array{x: float, y: float}, arrivee: ?array{x: float, y: float}, bande: ?array{x: float, largeur: float}, max: float, x: array<int, float>}
     */
    public static function courbe(array $historique, float $disponible, float $consoHebdo, float $stockMin, ?int $jourCommande, int $delai, int $avant = 30, int $apres = 30, int $largeur = 600, int $hauteur = 190): array
    {
        $parJour = max(0.0, $consoHebdo) / 7;
        $prevision = [];
        for ($j = 0; $j <= $apres; ++$j) {
            $prevision[$j] = max(0.0, $disponible - $parJour * $j);
        }

        $max = max([...array_values($historique), ...$prevision, $stockMin, 1.0]) * 1.12;
        $haut = 14;
        $bas = $hauteur - 22;
        $x = static fn (int $jour): float => round(($jour + $avant) / ($avant + $apres) * $largeur, 1);
        $y = static fn (float $v): float => round($bas - $v / $max * ($bas - $haut), 1);
        $points = static function (array $serie) use ($x, $y): string {
            $p = [];
            foreach ($serie as $jour => $v) {
                $p[] = $x((int) $jour).','.$y((float) $v);
            }

            return implode(' ', $p);
        };

        $passe = [];
        foreach ($historique as $jour => $v) {
            if ($jour >= -$avant && $jour <= 0) {
                $passe[$jour] = $v;
            }
        }
        ksort($passe);

        $commande = $arrivee = $bande = null;
        if ($jourCommande !== null && $jourCommande <= $apres) {
            $commande = ['x' => $x($jourCommande), 'y' => $y($prevision[$jourCommande] ?? 0.0)];
            $fin = min($apres, $jourCommande + $delai);
            $bande = ['x' => $commande['x'], 'largeur' => round($x($fin) - $commande['x'], 1)];
            if ($jourCommande + $delai <= $apres) {
                $arrivee = ['x' => $x($jourCommande + $delai), 'y' => $y($prevision[$jourCommande + $delai] ?? 0.0)];
            }
        }

        $graduations = [];
        foreach ([-$avant, (int) round(-$avant / 2), 0, (int) round($apres / 2), $apres] as $jour) {
            $graduations[$jour] = $x($jour);
        }

        return [
            'largeur' => $largeur,
            'hauteur' => $hauteur,
            'passe' => $points($passe),
            'prevision' => $points($prevision),
            'minimum' => $stockMin > 0 ? $y($stockMin) : null,
            'aujourdhui' => $x(0),
            'commande' => $commande,
            'arrivee' => $arrivee,
            'bande' => $bande,
            'max' => $max,
            'x' => $graduations,
        ];
    }

    /**
     * Stock de chaque jour (fin de journée), de −$avant à aujourd'hui,
     * reconstitué depuis le stock d'aujourd'hui et les mouvements ; avant
     * un jour où il serait négatif, les jours manquent.
     *
     * @param array<int, float> $mouvements entrées moins sorties de chaque jour (−$avant … 0)
     *
     * @return array<int, float>
     */
    public static function historique(float $stockAujourdhui, array $mouvements, int $avant = 30): array
    {
        $serie = [0 => max(0.0, $stockAujourdhui)];
        $stock = $stockAujourdhui;
        for ($j = 0; $j > -$avant; --$j) {
            $stock -= $mouvements[$j] ?? 0.0;
            // Un stock négatif veut dire des mouvements manquants (inventaire non saisi) : la courbe s'arrête là.
            if ($stock < -0.001) {
                break;
            }
            $serie[$j - 1] = round($stock, 3);
        }
        ksort($serie);

        return $serie;
    }

    private static function nombre(float $n): string
    {
        return abs($n - round($n)) < 0.001 ? number_format($n, 0, ',', ' ') : number_format($n, 1, ',', ' ');
    }

    private static function francs(int $montant): string
    {
        return number_format($montant, 0, ',', ' ');
    }

    /**
     * « le rouleau », « le litre », « l'unité ».
     */
    private static function parUnite(?string $unite): string
    {
        $unite = trim((string) $unite);
        if ($unite === '') {
            return 'l\'unité';
        }

        return preg_match('/^[aeiouyhéè]/iu', $unite) ? 'l\''.$unite : 'le '.$unite;
    }

    private static function minuscule(string $texte): string
    {
        return mb_strtolower(mb_substr($texte, 0, 1)).mb_substr($texte, 1);
    }
}
