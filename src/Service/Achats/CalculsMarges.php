<?php

namespace App\Service\Achats;

/**
 * Calculs de l'écran « Coûts et marges » (maquette SU04, docs/achats.md),
 * statiques et testés sans base (tests/Unit/Service/Achats/CalculsMargesTest.php).
 *
 * Le bénéfice est **brut** : prix de vente moins le coût de revient du
 * produit, c'est-à-dire la matière rendue Bamako (prix payé, transport et
 * transit réels des réceptions), sa gâche, l'encre, la machine et la
 * main-d'œuvre au m², les autres matières et les finitions. Loyer,
 * salaires fixes et électricité restent dans les Finances.
 *
 * Les montants par unité vendue sont des moyennes pondérées : chaque
 * ligne de vente est chiffrée, puis les totaux du produit sont divisés
 * par la quantité vendue (m², mètres ou pièces).
 */
final class CalculsMarges
{
    /** Gâche comptée tant qu'elle n'est pas mesurée en production (réglage). */
    public const GACHE_DEFAUT = 8.0;

    /** Jours de réceptions retenus pour le coût de la matière ; sans réception dans la période, la dernière. */
    public const JOURS_RECEPTIONS = 90;

    /** Taux de marge sous lequel un produit est signalé (le même que la rentabilité des devis). */
    public const SEUIL_MARGE_FAIBLE = 25.0;

    /** Les postes du coût de revient, dans l'ordre de la cascade. */
    public const POSTES = [
        'achat' => 'Achat',
        'transport' => 'Transport',
        'transit' => 'Transit',
        'encre' => 'Encre',
        'gache' => 'Gâche',
        'machine' => 'Machine et main-d\'œuvre',
        'autres' => 'Finitions et accessoires',
    ];

    /**
     * Coût d'une unité de stock (m², mètre, pièce…) rendue Bamako, d'après
     * les lignes reçues : prix d'achat, part de transport et part de
     * transit, pondérés par les unités entrées en stock (en bon état).
     *
     * Chaque ligne donne : quantité et prix en unité d'achat, quantité en
     * unité de stock commandée, coût de revient réel par unité de stock,
     * unités en bon état entrées en stock, transport et transit de la
     * commande. Le prix d'une unité de stock est le prix d'une unité
     * d'achat divisé par ce qu'elle contient ; les frais sont ce que le
     * coût de revient ajoute au prix, partagés entre transport et transit
     * comme sur la commande.
     *
     * @param list<array{quantiteAchat: int, prixUnitaire: int, quantiteStock: ?float, coutRevient: ?int, bonStock: ?float, fraisTransport: int, fraisTransit: int}> $lignes
     *
     * @return array{achat: float, transport: float, transit: float, quantite: float}|null
     */
    public static function unitairesReceptions(array $lignes): ?array
    {
        $somme = ['achat' => 0.0, 'transport' => 0.0, 'transit' => 0.0];
        $poids = 0.0;

        foreach ($lignes as $l) {
            $quantiteAchat = max(0, $l['quantiteAchat']);
            $quantiteStock = (float) ($l['quantiteStock'] ?? 0);
            if ($quantiteAchat <= 0 || $quantiteStock <= 0) {
                continue;
            }
            $achat = $l['prixUnitaire'] * $quantiteAchat / $quantiteStock;
            $frais = null !== $l['coutRevient'] ? max(0.0, $l['coutRevient'] - $achat) : 0.0;
            $fraisCommande = max(0, $l['fraisTransport']) + max(0, $l['fraisTransit']);
            $partTransport = $fraisCommande > 0 ? max(0, $l['fraisTransport']) / $fraisCommande : 1.0;
            $bon = (float) ($l['bonStock'] ?? $quantiteStock);
            if ($bon <= 0) {
                continue;
            }

            $somme['achat'] += $achat * $bon;
            $somme['transport'] += $frais * $partTransport * $bon;
            $somme['transit'] += $frais * (1 - $partTransport) * $bon;
            $poids += $bon;
        }

        if ($poids <= 0) {
            return null;
        }

        return [
            'achat' => $somme['achat'] / $poids,
            'transport' => $somme['transport'] / $poids,
            'transit' => $somme['transit'] / $poids,
            'quantite' => $poids,
        ];
    }

    /**
     * Les réceptions retenues : celles des JOURS_RECEPTIONS derniers jours
     * avant la fin du mois, sinon la plus récente. Les lignes arrivent
     * triées de la plus récente à la plus ancienne, avec leur date.
     *
     * @template T of array{recueLe: \DateTimeImmutable}
     *
     * @param list<T> $lignes
     *
     * @return list<T>
     */
    public static function receptionsRetenues(array $lignes, \DateTimeImmutable $fin): array
    {
        $depuis = $fin->modify(sprintf('-%d days', self::JOURS_RECEPTIONS));
        $retenues = array_values(array_filter($lignes, static fn (array $l): bool => $l['recueLe'] < $fin && $l['recueLe'] >= $depuis));
        if ([] !== $retenues) {
            return $retenues;
        }
        $avant = array_values(array_filter($lignes, static fn (array $l): bool => $l['recueLe'] < $fin));
        if ([] === $avant) {
            return [];
        }
        // La plus récente, avec les autres lignes du même jour (même commande).
        $jour = $avant[0]['recueLe']->format('Y-m-d');

        return array_values(array_filter($avant, static fn (array $l): bool => $l['recueLe']->format('Y-m-d') === $jour));
    }

    /**
     * Coût machine et main-d'œuvre au m² d'une machine : celui du réglage,
     * sinon son coût horaire (Réglages › Coûts de revient) divisé par sa
     * vitesse ; sinon inconnu.
     */
    public static function machineM2(?int $saisi, ?int $coutHoraire, ?float $vitesseM2Heure): ?int
    {
        if (null !== $saisi && $saisi >= 0) {
            return $saisi;
        }
        if (null !== $coutHoraire && null !== $vitesseM2Heure && $vitesseM2Heure > 0) {
            return (int) round($coutHoraire / $vitesseM2Heure);
        }

        return null;
    }

    /**
     * Gâche d'un article : celle mesurée et saisie pour lui, sinon celle
     * par défaut.
     *
     * @return array{pourcent: float, mesuree: bool}
     */
    public static function gache(?float $saisie, float $defaut = self::GACHE_DEFAUT): array
    {
        if (null !== $saisie && $saisie >= 0 && $saisie <= 100) {
            return ['pourcent' => $saisie, 'mesuree' => true];
        }

        return ['pourcent' => max(0.0, min(100.0, $defaut)), 'mesuree' => false];
    }

    /**
     * Quantité vendue d'une ligne dans l'unité du produit : m² (surface
     * vendue), mètres (longueur × quantité) ou pièces.
     */
    public static function quantiteVendue(string $unite, float $surfaceTotale, float $longueur, int $quantite): float
    {
        return match ($unite) {
            'm²' => max(0.0, $surfaceTotale),
            'm' => max(0.0, $longueur) * max(0, $quantite),
            default => (float) max(0, $quantite),
        };
    }

    /**
     * Coût d'une ligne vendue, poste par poste, en francs.
     *
     * - matière : la quantité de l'article principal consommée par la
     *   ligne × son coût rendu Bamako (achat, transport, transit) ;
     * - gâche : un pourcentage de cette matière (chutes, ratés, essais) ;
     * - encre et machine : la surface imprimée (faces comprises) × le coût
     *   de l'encre au m² et le coût machine et main-d'œuvre au m² ;
     * - autres : autres matières du produit et finitions, déjà chiffrées.
     *
     * @param array{achat: float, transport: float, transit: float}|null $unitaires
     *
     * @return array{achat: float, transport: float, transit: float, encre: float, gache: float, machine: float, autres: float}
     */
    public static function coutsLigne(float $besoin, ?array $unitaires, float $gachePourcent, float $surfaceImprimee, ?int $encreM2, ?int $machineM2, float $autres = 0.0): array
    {
        $besoin = max(0.0, $besoin);
        $achat = $besoin * ($unitaires['achat'] ?? 0.0);
        $transport = $besoin * ($unitaires['transport'] ?? 0.0);
        $transit = $besoin * ($unitaires['transit'] ?? 0.0);
        $surfaceImprimee = max(0.0, $surfaceImprimee);

        return [
            'achat' => $achat,
            'transport' => $transport,
            'transit' => $transit,
            'encre' => $surfaceImprimee * max(0, $encreM2 ?? 0),
            'gache' => ($achat + $transport + $transit) * max(0.0, $gachePourcent) / 100,
            'machine' => $surfaceImprimee * max(0, $machineM2 ?? 0),
            'autres' => max(0.0, $autres),
        ];
    }

    /**
     * @param array<string, float> $a
     * @param array<string, float> $b
     *
     * @return array<string, float>
     */
    public static function additionner(array $a, array $b): array
    {
        foreach ($b as $cle => $valeur) {
            $a[$cle] = ($a[$cle] ?? 0.0) + $valeur;
        }

        return $a;
    }

    /**
     * Bilan d'un produit sur le mois : prix de vente moyen, coût de revient
     * par unité (poste par poste), marge unitaire, taux de marge (sur le
     * prix de vente) et bénéfice brut.
     *
     * @param array<string, float> $couts totaux du mois par poste
     *
     * @return array{ca: int, quantite: float, cout: int, benefice: int, prixMoyen: ?int, coutUnitaire: ?int, margeUnitaire: ?int, taux: ?float, unitaires: array<string, int>}
     */
    public static function bilan(int $ca, float $quantite, array $couts): array
    {
        $cout = (int) round(array_sum($couts));
        $unitaires = [];
        foreach (array_keys(self::POSTES) as $poste) {
            $unitaires[$poste] = $quantite > 0 ? (int) round(($couts[$poste] ?? 0.0) / $quantite) : 0;
        }
        $prixMoyen = $quantite > 0 ? (int) round($ca / $quantite) : null;
        $coutUnitaire = $quantite > 0 ? array_sum($unitaires) : null;

        return [
            'ca' => $ca,
            'quantite' => $quantite,
            'cout' => $cout,
            'benefice' => $ca - $cout,
            'prixMoyen' => $prixMoyen,
            'coutUnitaire' => $coutUnitaire,
            'margeUnitaire' => null !== $prixMoyen && null !== $coutUnitaire ? $prixMoyen - $coutUnitaire : null,
            'taux' => $ca > 0 ? round(($ca - $cout) / $ca * 100, 1) : null,
            'unitaires' => $unitaires,
        ];
    }

    /**
     * Évolution en % d'un montant sur celui du mois d'avant (null sans base).
     */
    public static function evolution(int $maintenant, int $avant): ?float
    {
        if ($avant <= 0) {
            return null;
        }

        return round(($maintenant - $avant) / $avant * 100, 1);
    }

    /**
     * La cascade « où part l'argent d'une unité vendue » : le prix de
     * vente, chaque poste du coût qui le fait descendre, puis la marge.
     * Les hauteurs sont des fractions du prix de vente (0 en bas,
     * 1 en haut). Un poste nul n'a pas de barre, sauf achat et marge.
     *
     * @param array{prixMoyen: ?int, margeUnitaire: ?int, unitaires: array<string, int>} $bilan
     *
     * @return list<array{cle: string, libelle: string, valeur: int, bas: float, haut: float}>
     */
    public static function cascade(array $bilan): array
    {
        $prix = (int) ($bilan['prixMoyen'] ?? 0);
        $marge = (int) ($bilan['margeUnitaire'] ?? 0);
        $echelle = (float) max(1, $prix);
        $barres = [['cle' => 'prix', 'libelle' => 'Prix de vente', 'valeur' => $prix, 'bas' => 0.0, 'haut' => $prix / $echelle]];
        $niveau = $prix;

        foreach (self::POSTES as $poste => $libelle) {
            $valeur = (int) ($bilan['unitaires'][$poste] ?? 0);
            if (0 === $valeur && 'achat' !== $poste) {
                continue;
            }
            $barres[] = ['cle' => $poste, 'libelle' => $libelle, 'valeur' => -$valeur, 'bas' => max(0, $niveau - $valeur) / $echelle, 'haut' => max(0, $niveau) / $echelle];
            $niveau -= $valeur;
        }
        // Marge négative : une barre rouge sous zéro n'a pas de sens sur cette échelle ; elle est posée à zéro, son montant dit la perte.
        $barres[] = ['cle' => 'marge', 'libelle' => 'Marge', 'valeur' => $marge, 'bas' => 0.0, 'haut' => max(0, $marge) / $echelle];

        return $barres;
    }

    /**
     * « À retenir » : trois constats tirés du tableau.
     *
     * - le poids du transport et du transit (le produit où ils mangent la
     *   plus grande part de la marge) ;
     * - la marge la plus faible, en rouge sous SEUIL_MARGE_FAIBLE ;
     * - le produit le plus rentable (meilleur taux).
     *
     * @param list<array{nom: string, unite: string, origine: ?string, bilan: array{ca: int, prixMoyen: ?int, margeUnitaire: ?int, taux: ?float, unitaires: array<string, int>}}> $produits
     *
     * @return list<array{sorte: string, titre: string, detail: string}>
     */
    public static function aRetenir(array $produits): array
    {
        $avecVentes = array_values(array_filter($produits, static fn (array $p): bool => $p['bilan']['ca'] > 0 && null !== $p['bilan']['taux']));
        if ([] === $avecVentes) {
            return [];
        }
        $constats = [];

        // Transport : la plus grande part de la marge unitaire.
        $transport = null;
        $part = 0.0;
        foreach ($avecVentes as $p) {
            $frais = $p['bilan']['unitaires']['transport'] + $p['bilan']['unitaires']['transit'];
            $marge = (int) $p['bilan']['margeUnitaire'];
            if ($frais <= 0 || $marge <= 0) {
                continue;
            }
            if ($frais / $marge > $part) {
                [$transport, $part] = [$p, $frais / $marge];
            }
        }
        if (null !== $transport) {
            $frais = $transport['bilan']['unitaires']['transport'] + $transport['bilan']['unitaires']['transit'];
            $constats[] = [
                'sorte' => 'transport',
                'titre' => sprintf('Le transport%s coûte %s F/%s', null !== $transport['origine'] ? ' depuis '.$transport['origine'] : '', self::nombre($frais), $transport['unite']),
                'detail' => sprintf('soit %s %% de la marge %s', self::nombre((int) round($part * 100)), self::du($transport['nom'])),
            ];
        }

        usort($avecVentes, static fn (array $a, array $b): int => $a['bilan']['taux'] <=> $b['bilan']['taux']);
        $faible = $avecVentes[0];
        $meilleur = $avecVentes[\count($avecVentes) - 1];

        if (\count($avecVentes) > 1 || $faible['bilan']['taux'] < self::SEUIL_MARGE_FAIBLE) {
            $constats[] = [
                'sorte' => $faible['bilan']['taux'] < self::SEUIL_MARGE_FAIBLE ? 'alerte' : 'faible',
                'titre' => sprintf('%s : la marge la plus faible (%s %%)', $faible['nom'], self::nombre($faible['bilan']['taux'])),
                'detail' => sprintf('prix moyen %s F/%s pour un coût de %s F', self::nombre((int) $faible['bilan']['prixMoyen']), $faible['unite'], self::nombre((int) $faible['bilan']['prixMoyen'] - (int) $faible['bilan']['margeUnitaire'])),
            ];
        }
        if ($meilleur !== $faible && $meilleur['bilan']['taux'] > 0) {
            $constats[] = [
                'sorte' => 'meilleur',
                'titre' => sprintf('%s : %s %% de marge', $meilleur['nom'], self::nombre($meilleur['bilan']['taux'])),
                'detail' => sprintf('le produit le plus rentable, %s F gagnés par %s', self::nombre((int) $meilleur['bilan']['margeUnitaire']), $meilleur['unite']),
            ];
        }

        return $constats;
    }

    /**
     * Le mois demandé (« 2026-10 »), sinon le mois en cours ; jamais dans le futur.
     */
    public static function mois(?string $saisie, \DateTimeImmutable $aujourdhui): \DateTimeImmutable
    {
        $courant = $aujourdhui->modify('first day of this month')->setTime(0, 0);
        if (null === $saisie || 1 !== preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $saisie, $m)) {
            return $courant;
        }
        $mois = $courant->setDate((int) $m[1], (int) $m[2], 1);

        return $mois > $courant ? $courant : $mois;
    }

    /** « octobre 2026 ». */
    public static function libelleMois(\DateTimeImmutable $mois): string
    {
        $noms = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

        return $noms[(int) $mois->format('n') - 1].' '.$mois->format('Y');
    }

    /** « du vinyle adhésif », « de l'affiche » : le nom en minuscules après « de ». */
    private static function du(string $nom): string
    {
        $nom = mb_strtolower(mb_substr($nom, 0, 1)).mb_substr($nom, 1);

        return preg_match('/^[aeéèêiîoôuh]/u', $nom) ? 'de l\''.$nom : 'de '.$nom;
    }

    private static function nombre(int|float $valeur): string
    {
        return \is_float($valeur) && $valeur != round($valeur)
            ? number_format($valeur, 1, ',', ' ')
            : number_format($valeur, 0, ',', ' ');
    }
}
