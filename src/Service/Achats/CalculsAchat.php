<?php

namespace App\Service\Achats;

use App\Entity\Achats;

/**
 * Calculs des écrans Achats (maquettes SU01 à SU03, docs/achats.md) :
 * répartition des frais au m², coût de revient, ponctualité et délai
 * réel des fournisseurs, date de la prochaine commande, suggestion de
 * quantité et seuil de validation.
 *
 * Tout est statique et pur pour être testé sans base.
 */
final class CalculsAchat
{
    /*
     * Valeurs par défaut des réglages (tuile « Achats et fournisseurs »).
     */
    public const SEUIL_VALIDATION_DEFAUT = 500000;
    public const MARGE_SECURITE_DEFAUT = 5;
    public const JOURS_REGROUPEMENT_DEFAUT = 14;

    /*
     * La consommation se mesure sur les 12 dernières semaines.
     */
    public const JOURS_CONSOMMATION = 84;

    /*
     * Délai pris quand on ne sait rien du fournisseur.
     */
    public const DELAI_INCONNU = 7;

    /*
     * Au-delà de 7 jours avant la date de commande, l'article n'est pas pressé.
     */
    public const JOURS_BIENTOT = 7;

    public const URGENCE_SOUS_MINIMUM = 'sous_minimum';
    public const URGENCE_MAINTENANT = 'maintenant';
    public const URGENCE_BIENTOT = 'bientot';
    public const URGENCE_PLUS_TARD = 'plus_tard';

    /**
     * Répartit des frais (transport, transit) entre les lignes d'une
     * commande. Si toutes les lignes sont dans la même unité de stock
     * (des m²), chaque m² porte la même part ; sinon on répartit au
     * prorata du montant de marchandise. Les parts sont arrondies au
     * franc et leur somme fait exactement les frais.
     *
     * @param list<array{quantite: float, unite: string, montant: int}> $lignes
     *
     * @return list<int>
     */
    public static function repartirFrais(array $lignes, int $frais): array
    {
        if ($lignes === []) {
            return [];
        }

        $parQuantite = self::memeUnite($lignes);
        $poids = array_map(static fn (array $l): float => max(0.0, $parQuantite ? (float) $l['quantite'] : (float) $l['montant']), $lignes);
        $total = array_sum($poids);

        if ($frais <= 0 || $total <= 0) {
            return array_fill(0, \count($lignes), 0);
        }

        $parts = [];
        $reparti = 0;
        $derniere = \count($lignes) - 1;

        foreach ($poids as $i => $p) {
            $part = $i === $derniere ? $frais - $reparti : (int) round($frais * $p / $total);
            $parts[] = $part;
            $reparti += $part;
        }

        return $parts;
    }

    /**
     * @param list<array{unite: string}> $lignes
     */
    public static function memeUnite(array $lignes): bool
    {
        return \count(array_unique(array_map(static fn (array $l): string => mb_strtolower((string) $l['unite']), $lignes))) <= 1;
    }

    /**
     * Frais par m² (ou par unité) : ce que chaque m² acheté porte.
     */
    public static function fraisParUnite(int $frais, float $quantiteTotale): ?int
    {
        return $quantiteTotale > 0 ? (int) round($frais / $quantiteTotale) : null;
    }

    /**
     * Coût de revient d'une unité de stock rendue à Bamako : (montant de
     * la ligne + sa part de transport et de transit) ÷ quantité en stock.
     */
    public static function coutRevientUnitaire(int $montantLigne, int $partFrais, float $quantiteStock): ?int
    {
        return $quantiteStock > 0 ? (int) round(($montantLigne + $partFrais) / $quantiteStock) : null;
    }

    /**
     * Retard en jours (négatif : en avance) entre la date promise et l'arrivée.
     */
    public static function retardJours(\DateTimeImmutable $promise, \DateTimeImmutable $recue): int
    {
        return (int) $promise->setTime(0, 0)->diff($recue->setTime(0, 0))->format('%r%a');
    }

    /**
     * Part des livraisons arrivées au plus tard le jour promis, en %.
     *
     * @param list<array{promise: ?\DateTimeImmutable, recue: ?\DateTimeImmutable}> $livraisons
     */
    public static function pourcentageALHeure(array $livraisons): ?int
    {
        $comptees = array_values(array_filter($livraisons, static fn (array $l): bool => $l['promise'] !== null && $l['recue'] !== null));

        if ($comptees === []) {
            return null;
        }

        $aLHeure = \count(array_filter($comptees, static fn (array $l): bool => self::retardJours($l['promise'], $l['recue']) <= 0));

        return (int) round($aLHeure / \count($comptees) * 100);
    }

    /**
     * Retard moyen en jours sur les livraisons : une livraison en avance
     * compte pour zéro, pas pour un retard négatif.
     *
     * @param list<array{promise: ?\DateTimeImmutable, recue: ?\DateTimeImmutable}> $livraisons
     */
    public static function retardMoyen(array $livraisons): ?float
    {
        $retards = [];

        foreach ($livraisons as $l) {
            if ($l['promise'] !== null && $l['recue'] !== null) {
                $retards[] = max(0, self::retardJours($l['promise'], $l['recue']));
            }
        }

        return $retards !== [] ? round(array_sum($retards) / \count($retards), 1) : null;
    }

    /**
     * Délai réel moyen en jours entre la commande et l'arrivée.
     *
     * @param list<array{commande: ?\DateTimeImmutable, recue: ?\DateTimeImmutable}> $livraisons
     */
    public static function delaiReelMoyen(array $livraisons): ?float
    {
        $delais = [];

        foreach ($livraisons as $l) {
            if ($l['commande'] !== null && $l['recue'] !== null) {
                $delais[] = max(0, self::retardJours($l['commande'], $l['recue']));
            }
        }

        return $delais !== [] ? round(array_sum($delais) / \count($delais), 1) : null;
    }

    /**
     * Part de la marchandise reçue abîmée, en % (une décimale).
     */
    public static function pourcentageAbime(float $recue, float $abimee): ?float
    {
        return $recue > 0 ? round($abimee / $recue * 100, 1) : null;
    }

    /**
     * Consommation moyenne par semaine sur la période mesurée.
     */
    public static function consommationHebdo(float $sorties, int $jours = self::JOURS_CONSOMMATION): float
    {
        return $jours > 0 ? round(max(0.0, $sorties) / $jours * 7, 3) : 0.0;
    }

    /**
     * Jours que le disponible couvre à ce rythme (null sans consommation).
     */
    public static function joursRestants(float $disponible, float $consoHebdo): ?int
    {
        if ($consoHebdo <= 0) {
            return null;
        }

        return (int) floor(max(0.0, $disponible) / ($consoHebdo / 7));
    }

    /**
     * Délai à compter pour réapprovisionner : le délai réel mesuré, sinon
     * celui noté sur la fiche article, sinon celui promis par le fournisseur.
     */
    public static function delaiReappro(?float $delaiReel, ?int $delaiArticle, ?int $delaiPromis): int
    {
        if ($delaiReel !== null) {
            return (int) ceil($delaiReel);
        }

        return $delaiArticle ?? $delaiPromis ?? self::DELAI_INCONNU;
    }

    /**
     * Date à laquelle commander pour recevoir avant la rupture, en
     * gardant la marge de sécurité : aujourd'hui + jours restants −
     * (délai + marge). Jamais avant aujourd'hui ; null sans consommation.
     */
    public static function dateProchaineCommande(\DateTimeImmutable $aujourdhui, ?int $joursRestants, int $delai, int $marge): ?\DateTimeImmutable
    {
        if ($joursRestants === null) {
            return null;
        }

        $jours = max(0, $joursRestants - $delai - $marge);

        return $aujourdhui->setTime(0, 0)->modify(sprintf('+%d days', $jours));
    }

    /**
     * Règle simple d'alerte : le disponible est au minimum, ou ce qui
     * reste ne couvre plus le délai du fournisseur plus la marge.
     */
    public static function aCommanderBientot(float $disponible, float $stockMin, ?int $joursRestants, int $delai, int $marge): bool
    {
        if ($disponible <= 0 || ($stockMin > 0 && $disponible <= $stockMin)) {
            return true;
        }

        return $joursRestants !== null && $joursRestants <= $delai + $marge;
    }

    /**
     * Urgence de la prochaine commande, pour la couleur : sous le minimum
     * ou maintenant (rouge), dans la semaine (orange), plus tard (vert).
     */
    public static function urgence(float $disponible, float $stockMin, ?\DateTimeImmutable $date, \DateTimeImmutable $aujourdhui): ?string
    {
        if ($disponible <= 0 || ($stockMin > 0 && $disponible <= $stockMin)) {
            return self::URGENCE_SOUS_MINIMUM;
        }

        if ($date === null) {
            return null;
        }

        $jours = self::retardJours($aujourdhui, $date);

        return match (true) {
            $jours <= 0 => self::URGENCE_MAINTENANT,
            $jours <= self::JOURS_BIENTOT => self::URGENCE_BIENTOT,
            default => self::URGENCE_PLUS_TARD,
        };
    }

    /**
     * Texte de la prochaine commande : « maintenant », « sous le
     * minimum », « avant le 24/10 » ou « le 06/11 ».
     */
    public static function texteProchaineCommande(?string $urgence, ?\DateTimeImmutable $date): string
    {
        return match ($urgence) {
            self::URGENCE_SOUS_MINIMUM => 'sous le minimum',
            self::URGENCE_MAINTENANT => 'maintenant',
            self::URGENCE_BIENTOT => 'avant le '.$date?->format('d/m'),
            self::URGENCE_PLUS_TARD => 'le '.$date?->format('d/m'),
            default => '—',
        };
    }

    /**
     * « 4 rouleaux ≈ 21 semaines » : ce que la quantité commandée couvre
     * au rythme de consommation actuel.
     */
    public static function suggestion(float $quantiteAchat, ?string $uniteAchat, float $facteur, float $consoHebdo): string
    {
        $quantite = self::nombre($quantiteAchat);
        $unite = self::pluriel($uniteAchat ?: 'unité', $quantiteAchat);

        if ($consoHebdo <= 0) {
            return sprintf('%s %s · pas de consommation récente', $quantite, $unite);
        }

        $semaines = $quantiteAchat * max($facteur, 0.0) / $consoHebdo;

        if ($semaines < 1) {
            return sprintf('%s %s ≈ %d jours', $quantite, $unite, max(1, (int) round($semaines * 7)));
        }

        $semaines = (int) round($semaines);

        return sprintf('%s %s ≈ %d semaine%s', $quantite, $unite, $semaines, $semaines > 1 ? 's' : '');
    }

    /**
     * Unités d'achat entières pour couvrir un nombre de semaines.
     */
    public static function unitesPourSemaines(float $consoHebdo, float $facteur, int $semaines): int
    {
        if ($consoHebdo <= 0 || $facteur <= 0) {
            return 1;
        }

        return max(1, (int) ceil($consoHebdo * $semaines / $facteur));
    }

    public static function pluriel(string $mot, float $nombre): string
    {
        if (abs($nombre) < 2) {
            return $mot;
        }

        return match (true) {
            str_ends_with($mot, 'eau') => $mot.'x',
            str_ends_with($mot, 's'), str_ends_with($mot, 'x') => $mot,
            default => $mot.'s',
        };
    }

    /**
     * Statut d'une commande envoyée : au-dessus du seuil, elle attend la
     * validation de la direction ; en dessous, elle part tout de suite.
     */
    public static function statutApresEnvoi(int $totalRendu, int $seuil): string
    {
        return $totalRendu > $seuil ? Achats::STATUT_A_VALIDER : Achats::STATUT_COMMANDEE;
    }

    public static function doitEtreValidee(int $totalRendu, int $seuil): bool
    {
        return self::statutApresEnvoi($totalRendu, $seuil) === Achats::STATUT_A_VALIDER;
    }

    /**
     * Date promise : la commande plus le délai annoncé par le fournisseur.
     */
    public static function datePromise(\DateTimeImmutable $commande, ?int $delaiPromis): \DateTimeImmutable
    {
        return $commande->setTime(0, 0)->modify(sprintf('+%d days', $delaiPromis ?? self::DELAI_INCONNU));
    }

    /**
     * Livraison prévue : la date promise plus le retard habituel du
     * fournisseur (arrondi au jour supérieur).
     */
    public static function livraisonPrevue(\DateTimeImmutable $promise, ?float $retardMoyen): \DateTimeImmutable
    {
        $retard = $retardMoyen !== null && $retardMoyen > 0 ? (int) ceil($retardMoyen) : 0;

        return $promise->setTime(0, 0)->modify(sprintf('+%d days', $retard));
    }

    /**
     * Badge « Écart » d'une commande sur la fiche fournisseur : en route,
     * à l'heure, +N j, et la casse s'il y en a.
     *
     * @return array{libelle: string, classe: string}
     */
    public static function ecart(string $statut, ?\DateTimeImmutable $promise, ?\DateTimeImmutable $recue, int $abimees = 0, ?string $uniteAchat = null, ?\DateTimeImmutable $prevue = null): array
    {
        if (\in_array($statut, [Achats::STATUT_BROUILLON, Achats::STATUT_A_VALIDER, Achats::STATUT_ANNULE], true)) {
            return ['libelle' => mb_strtolower(Achats::LIBELLES_STATUTS[$statut]), 'classe' => $statut === Achats::STATUT_A_VALIDER ? 'attente' : 'brouillon'];
        }

        if ($recue === null) {
            $libelle = $prevue !== null ? 'en route · prévu '.$prevue->format('d/m') : 'en route';

            return ['libelle' => $libelle, 'classe' => 'attente'];
        }

        $casse = $abimees > 0 ? sprintf('%d %s abîmé%s', $abimees, self::pluriel($uniteAchat ?: 'unité', $abimees), $abimees > 1 ? 's' : '') : null;
        $retard = $promise !== null ? self::retardJours($promise, $recue) : 0;

        if ($retard <= 0) {
            return $casse !== null ? ['libelle' => 'à l\'heure · '.$casse, 'classe' => 'retard'] : ['libelle' => 'à l\'heure', 'classe' => 'ok'];
        }

        $texte = sprintf('+%d j', $retard);

        return $casse !== null ? ['libelle' => $texte.' · '.$casse, 'classe' => 'retard'] : ['libelle' => $texte, 'classe' => $retard > 3 ? 'retard' : 'jaune'];
    }

    /**
     * Prix moyen payé chaque mois sur les $mois derniers mois (le mois en
     * cours compris), null pour un mois sans achat.
     *
     * @param list<array{date: \DateTimeImmutable, prix: int}> $achats
     *
     * @return list<?int>
     */
    public static function prixParMois(array $achats, \DateTimeImmutable $aujourdhui, int $mois = 12): array
    {
        $debut = $aujourdhui->modify('first day of this month')->setTime(0, 0)->modify(sprintf('-%d months', $mois - 1));
        $sommes = [];

        foreach ($achats as $a) {
            if ($a['date'] < $debut) {
                continue;
            }
            $cle = $a['date']->format('Y-m');
            $sommes[$cle][] = $a['prix'];
        }

        $serie = [];

        for ($i = 0; $i < $mois; ++$i) {
            $cle = $debut->modify(sprintf('+%d months', $i))->format('Y-m');
            $serie[] = isset($sommes[$cle]) ? (int) round(array_sum($sommes[$cle]) / \count($sommes[$cle])) : null;
        }

        return $serie;
    }

    /**
     * Variation en % entre le premier et le dernier prix connus de la série.
     *
     * @param list<?int> $serie
     */
    public static function variation(array $serie): ?int
    {
        $prix = array_values(array_filter($serie, static fn (?int $p): bool => $p !== null && $p > 0));

        if (\count($prix) < 2) {
            return \count($prix) === 1 ? 0 : null;
        }

        return (int) round(($prix[\count($prix) - 1] - $prix[0]) / $prix[0] * 100);
    }

    /**
     * Points d'une petite courbe SVG (« x,y x,y … ») : les mois sans
     * achat reprennent le dernier prix connu.
     *
     * @param list<?int> $serie
     */
    public static function pointsCourbe(array $serie, int $largeur = 90, int $hauteur = 24): string
    {
        $connus = array_values(array_filter($serie, static fn (?int $p): bool => $p !== null));

        if ($connus === []) {
            return '';
        }

        $min = min($connus);
        $max = max($connus);
        $ecart = $max - $min;
        $n = \count($serie);
        $precedent = $connus[0];
        $points = [];

        foreach ($serie as $i => $prix) {
            $prix ??= $precedent;
            $precedent = $prix;
            $x = $n > 1 ? $i / ($n - 1) * $largeur : $largeur / 2;
            $y = $ecart > 0 ? $hauteur - 2 - ($prix - $min) / $ecart * ($hauteur - 4) : $hauteur / 2;
            $points[] = sprintf('%s,%s', round($x, 1), round($y, 1));
        }

        return implode(' ', $points);
    }

    /**
     * Économie en groupant un second article dans le même envoi : à peu
     * près ce qu'aurait coûté son propre transport, soit le transport
     * moyen d'un envoi de ce fournisseur. Sans historique, la part de
     * transport que l'article ajouté porte dans cette commande.
     *
     * @param list<int> $transportsPasses frais de transport des derniers envois
     */
    public static function economieRegroupement(array $transportsPasses, int $partDansLaCommande): int
    {
        $passes = array_values(array_filter($transportsPasses, static fn (int $f): bool => $f > 0));

        if ($passes === []) {
            return max(0, $partDansLaCommande);
        }

        return (int) (round(array_sum($passes) / \count($passes) / 1000) * 1000);
    }

    /**
     * Numéro suivant au format CA-2026-0042, d'après le plus grand de l'année.
     */
    public static function numeroSuivant(int $annee, ?string $dernier): string
    {
        $rang = 0;

        if ($dernier !== null && preg_match('/^CA-'.$annee.'-(\d+)$/', $dernier, $m)) {
            $rang = (int) $m[1];
        }

        return sprintf('CA-%d-%04d', $annee, $rang + 1);
    }

    private static function nombre(float $n): string
    {
        return abs($n - round($n)) < 0.001 ? (string) (int) round($n) : str_replace('.', ',', (string) round($n, 2));
    }
}
