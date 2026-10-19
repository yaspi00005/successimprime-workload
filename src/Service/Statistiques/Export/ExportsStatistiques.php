<?php

namespace App\Service\Statistiques\Export;

use App\Service\Statistiques\Objectifs;
use App\Twig\StatistiquesExtension;

/**
 * Transforme les données d'un écran de statistiques en tableaux à
 * exporter. Les contrôleurs passent ici exactement ce qu'ils passent à la
 * page, déjà filtré selon les droits : ce qui n'est pas dans les données
 * n'est pas exporté, et une colonne de montant n'apparaît qu'avec son
 * droit (DroitsStatistiques). Tout est pur, pour être testé sans base.
 */
final class ExportsStatistiques
{
    /**
     * Statistiques et rapports (maquette 36).
     *
     * @param array<string, mixed> $d      données de StatistiquesRapportsController
     * @param array<string, bool>  $droits DroitsStatistiques
     */
    public static function rapports(array $d, array $droits, string $periode): Export
    {
        $kpi = $d['kpi'];
        $evol = static fn (?int $v, string $suffixe): string => null === $v ? 'pas de comparaison' : ($v >= 0 ? '+' : '−').abs($v).$suffixe;
        $indicateurs = [];
        if ($droits['ventes'] && isset($kpi['ca'])) {
            $indicateurs[] = ['CA facturé', $kpi['ca'], 'F', $evol($kpi['ca_evolution'], ' % vs N−1')];
        }
        if ($droits['marges'] && null !== ($kpi['marge'] ?? null) && null !== $kpi['marge']['taux']) {
            $indicateurs[] = ['Marge brute', $kpi['marge']['taux'], '%', $evol($kpi['marge_evolution'], ' pts')];
        }
        $indicateurs[] = ['m² imprimés', (int) round($kpi['surface']), 'm²', $evol($kpi['surface_evolution'], ' %')];
        $indicateurs[] = ['Nouveaux clients', $kpi['nouveaux'], 'clients', $evol($kpi['nouveaux_evolution'], ' %')];
        $indicateurs[] = ['Commandes', $kpi['commandes'], 'commandes', ''];
        if ($droits['ventes'] && isset($kpi['panier'])) {
            $indicateurs[] = ['Panier moyen', $kpi['panier'], 'F', $kpi['factures'].' facture'.($kpi['factures'] > 1 ? 's' : '')];
        }

        $tableaux = [new Tableau('Indicateurs', ['Indicateur' => 'texte', 'Valeur' => 'entier', 'Unité' => 'texte', 'Évolution' => 'texte'], $indicateurs)];
        if (isset($d['repartition'])) {
            $tableaux[] = self::tableauRepartition($d['repartition']);
        }

        if ($droits['ventes'] && isset($d['mois'])) {
            $avecObjectif = isset($d['objectifs_mois']);
            $colonnes = ['Mois' => 'texte', 'CA facturé' => 'fcfa', 'Même mois N−1' => 'fcfa'] + ($avecObjectif ? ['Objectif' => 'fcfa', 'Atteint' => 'pourcent'] : []);
            $lignes = [];
            foreach ($d['mois'] as $m) {
                $ligne = [$m['mois']->format('m/Y'), $m['montant'], $m['avant']];
                if ($avecObjectif) {
                    $objectif = $d['objectifs_mois'][$m['mois']->format('Y-m')] ?? null;
                    $ligne[] = $objectif;
                    $ligne[] = $objectif ? (int) round($m['montant'] * 100 / $objectif) : null;
                }
                $lignes[] = $ligne;
            }
            $total = ['Total', array_sum(array_column($d['mois'], 'montant')), array_sum(array_column($d['mois'], 'avant'))];
            if ($avecObjectif) {
                $total[] = array_sum(array_filter($d['objectifs_mois'])) ?: null;
                $total[] = null;
            }
            $tableaux[] = new Tableau('CA facturé par mois', $colonnes, $lignes, $total, 'Factures émises, hors pro formas, TTC.');
        }

        if ($droits['marges'] && [] !== ($d['categories'] ?? [])) {
            $tableaux[] = new Tableau('Marge par produit', ['Catégorie' => 'texte', 'Marge brute' => 'pourcent', 'CA HT chiffré' => 'fcfa'], array_map(static fn (array $c): array => [$c['categorie'], $c['taux'], $c['ht']], $d['categories']), null, 'Lignes dont le coût de revient est complet.');
        }

        if ($droits['ventes'] && \array_key_exists('top_client', $d)) {
            $tableaux[] = new Tableau('Meilleurs', ['Rang' => 'texte', 'Nom' => 'texte', 'CA facturé' => 'fcfa', 'Part des ventes' => 'pourcent'], array_values(array_filter([
                null !== $d['top_client'] ? ['Top client', $d['top_client']['nom'], $d['top_client']['montant'], $d['top_client']['part']] : null,
                null !== $d['top_agent'] ? ['Top agent', $d['top_agent']['nom'], $d['top_agent']['montant'], $d['top_agent']['part']] : null,
            ])));
        }

        if ([] !== ($d['objectifs'] ?? [])) {
            array_push($tableaux, ...self::tableauxObjectifs($d['objectifs']));
        }

        return new Export('Statistiques et rapports', $periode, $tableaux, 'statistiques-'.$periode);
    }

    /**
     * Objectifs du mois : un tableau par indicateur (un seul format par
     * feuille), lignes déjà filtrées par Objectifs::tableau().
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return list<Tableau>
     */
    public static function tableauxObjectifs(array $lignes): array
    {
        $parIndicateur = [];
        foreach ($lignes as $l) {
            $parIndicateur[$l['indicateur']][] = $l;
        }

        $tableaux = [];
        foreach ($parIndicateur as $indicateur => $groupe) {
            $definition = Objectifs::INDICATEURS[$indicateur];
            $format = $definition['format'];
            $colonnes = ['Périmètre' => 'texte', 'Nom' => 'texte', 'Réalisé' => $format, 'Objectif' => $format, 'Atteint' => 'pourcent'];
            if ($definition['cumul']) {
                $colonnes += ['Projection fin de mois' => $format, 'Projection / objectif' => 'pourcent'];
            }
            $colonnes['État'] = 'texte';

            $rangees = [];
            foreach ($groupe as $l) {
                $rangee = [Objectifs::AXES[$l['axe']], 'global' === $l['axe'] ? 'Toute l\'entreprise' : $l['libelle'], $l['realise'], $l['objectif'], $l['part']];
                if ($definition['cumul']) {
                    $rangee[] = $l['projection'];
                    $rangee[] = $l['part_projetee'];
                }
                $rangee[] = StatistiquesExtension::ETATS[$l['etat']]['libelle'] ?? $l['etat'];
                $rangees[] = $rangee;
            }
            $tableaux[] = new Tableau('Objectif · '.$definition['libelle'], $colonnes, $rangees);
        }

        return $tableaux;
    }

    /**
     * @param list<array<string, mixed>> $lignes Objectifs::tableau()
     */
    public static function objectifs(array $lignes, string $periode): Export
    {
        return new Export('Objectifs du mois', $periode, self::tableauxObjectifs($lignes), 'objectifs-'.$periode, 'Projection : réalisé divisé par la part du mois écoulée.');
    }

    /**
     * « Imprimerie / Hors imprimerie / Total » (Activite::repartition) :
     * le CA et la marge seulement s'ils sont dans les données (leur droit).
     *
     * @param array{imprimerie: array<string, mixed>, hors: array<string, mixed>, total: array<string, mixed>} $rep
     */
    public static function tableauRepartition(array $rep): Tableau
    {
        $avecCa = \array_key_exists('ca', $rep['total']);
        $avecMarge = \array_key_exists('marge', $rep['total']);
        $colonnes = ['Activité' => 'texte'] + ($avecCa ? ['CA facturé' => 'fcfa', 'Part' => 'pourcent'] : []) + ($avecMarge ? ['Marge brute' => 'pourcent'] : []) + ['Commandes' => 'entier'];
        $ligne = static fn (string $libelle, array $l, ?int $part): array => array_merge(
            [$libelle],
            $avecCa ? [$l['ca'], $part] : [],
            $avecMarge ? [$l['marge']] : [],
            [$l['commandes']],
        );

        return new Tableau(
            'Imprimerie et hors imprimerie',
            $colonnes,
            [$ligne('Imprimerie', $rep['imprimerie'], $rep['imprimerie']['part'] ?? null), $ligne('Hors imprimerie', $rep['hors'], $rep['hors']['part'] ?? null)],
            $ligne('Total', $rep['total'], $avecCa ? 100 : null),
            'Hors imprimerie : ventes de matériel, informatique… cochées sur le devis ou la commande.',
        );
    }

    /**
     * Agents, caisse et production (statistiques globales).
     *
     * @param array<string, mixed> $d
     * @param array<string, bool>  $droits
     */
    public static function globales(array $d, array $droits, string $periode): Export
    {
        $montant = $droits['ventes'];
        $tableaux = [];
        if (isset($d['repartition'])) {
            $tableaux[] = self::tableauRepartition($d['repartition']);
        }

        $tableaux[] = new Tableau(
            'Ventes par agent',
            ['Agent' => 'texte', 'Commandes' => 'entier'] + ($montant ? ['Montant TTC' => 'fcfa'] : []),
            array_map(static fn (array $l): array => $montant ? [$l['nom'], $l['nombre'], $l['montant']] : [$l['nom'], $l['nombre']], $d['parAgent']),
            $montant ? ['Total', array_sum(array_column($d['parAgent'], 'nombre')), array_sum(array_column($d['parAgent'], 'montant'))] : ['Total', array_sum(array_column($d['parAgent'], 'nombre'))],
        );
        if ($droits['paiements'] && null !== ($d['parCaissiere'] ?? null)) {
            $tableaux[] = new Tableau('Encaissements par caissière', ['Caissière' => 'texte', 'Paiements' => 'entier', 'Montant' => 'fcfa'], array_map(static fn (array $l): array => [$l['nom'], $l['nombre'], $l['montant']], $d['parCaissiere']), ['Total', array_sum(array_column($d['parCaissiere'], 'nombre')), array_sum(array_column($d['parCaissiere'], 'montant'))]);
        }
        $tableaux[] = new Tableau('Production par agent', ['Agent' => 'texte', 'Travaux terminés' => 'entier'], array_map(static fn (array $l): array => [$l['nom'], $l['nombre']], $d['parProduction']));
        $tableaux[] = new Tableau('Rendement par machine', ['Machine' => 'texte', 'Travaux' => 'entier', 'Surface' => 'm2'], array_map(static fn (array $l): array => [$l['nom'], $l['nombre'], $l['quantite']], $d['parMachine']), ['Total', array_sum(array_column($d['parMachine'], 'nombre')), array_sum(array_column($d['parMachine'], 'quantite'))]);
        $tableaux[] = new Tableau('Top des produits', ['Produit' => 'texte', 'Commandes' => 'entier', 'Quantité' => 'entier'], array_map(static fn (array $l): array => [$l['nom'], $l['nombre'], $l['quantite']], $d['topProduits']));
        $tableaux[] = new Tableau(
            'Ventes d\'articles',
            ['Article' => 'texte', 'Sorties' => 'entier', 'Quantité' => 'entier'] + ($montant ? ['Montant' => 'fcfa'] : []),
            array_map(static fn (array $l): array => $montant ? [$l['nom'], $l['nombre'], $l['quantite'], $l['montant']] : [$l['nom'], $l['nombre'], $l['quantite']], $d['ventesArticles']),
        );
        $stock = $d['evolutionStock'];
        $tableaux[] = new Tableau('Variation du stock', ['Période' => 'texte', 'Entrées' => 'entier', 'Sorties' => 'entier'], array_map(null, $stock['labels'], $stock['entrees'], $stock['sorties']));

        return new Export('Agents, caisse et production', $periode, $tableaux, 'statistiques-globales-'.$periode);
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, bool>  $droits
     */
    public static function commandes(array $d, array $droits, string $periode): Export
    {
        $evolution = $d['evolution'];
        $avecCa = $droits['ventes'] && isset($evolution['ca']);
        $tableaux = [new Tableau(
            'Évolution des commandes',
            ['Période' => 'texte', 'Commandes' => 'entier'] + ($avecCa ? ['Chiffre d\'affaires' => 'fcfa'] : []),
            array_map(null, ...array_values(array_filter([$evolution['labels'], $evolution['commandes'], $avecCa ? $evolution['ca'] : null], static fn (?array $c): bool => null !== $c))),
        )];

        if ($droits['paiements'] && null !== ($d['commandesNonSoldees'] ?? null)) {
            $tableaux[] = new Tableau('Impayés par ancienneté', ['Ancienneté' => 'texte', 'Commandes' => 'entier', 'Reste à payer' => 'fcfa'], array_map(static fn (array $t): array => [$t['libelle'], $t['nombre'], $t['montant']], $d['parTranche']), ['Total', array_sum(array_column($d['parTranche'], 'nombre')), array_sum(array_column($d['parTranche'], 'montant'))]);
            $tableaux[] = new Tableau('Commandes non soldées', ['N° commande' => 'texte', 'Client' => 'texte', 'Date' => 'date', 'Ancienneté (jours)' => 'entier', 'Reste à payer' => 'fcfa'], array_map(static fn (array $l): array => [
                $l['numero'], $l['client'], $l['date'], $l['jours'], $l['reste'],
            ], $d['commandesNonSoldees']), ['Total', null, null, null, $d['totalReste']]);
        }

        return new Export('Commandes', $periode, $tableaux, 'statistiques-commandes-'.$periode);
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, bool>  $droits
     */
    public static function clients(array $d, array $droits, string $periode, string $granularite): Export
    {
        $montant = $droits['ventes'];

        return new Export('Clients', $periode, [
            new Tableau(
                'Chiffre d\'affaires par client',
                ['Client' => 'texte', 'Commandes' => 'entier'] + ($montant ? ['Chiffre d\'affaires' => 'fcfa'] : []),
                array_map(static fn (array $l): array => $montant ? [$l['nom'], $l['nombre'], $l['montant']] : [$l['nom'], $l['nombre']], $d['caParClient']),
            ),
            new Tableau('Nouveaux clients par '.$granularite, ['Période' => 'texte', 'Nouveaux clients' => 'entier'], array_map(null, $d['nouveauxClients']['labels'], $d['nouveauxClients']['valeurs'])),
        ], 'statistiques-clients-'.$periode);
    }

    /**
     * @param array<string, mixed> $d
     */
    public static function tresorerie(array $d, string $periode): Export
    {
        $evolution = $d['evolution'];

        return new Export('Trésorerie', $periode, [
            new Tableau('Totaux de la période', ['Indicateur' => 'texte', 'Montant' => 'fcfa'], [
                ['Encaissé', $d['totalEncaisse']],
                ['Décaissé', $d['totalDecaisse']],
                ['Solde de la période', $d['totalEncaisse'] - $d['totalDecaisse']],
            ]),
            new Tableau('Encaissements et décaissements', ['Période' => 'texte', 'Encaissements' => 'fcfa', 'Décaissements' => 'fcfa'], array_map(null, $evolution['labels'], $evolution['encaissements'], $evolution['decaissements'])),
            new Tableau('Charges par catégorie', ['Catégorie' => 'texte', 'Mouvements' => 'entier', 'Montant' => 'fcfa'], array_map(static fn (array $l): array => [$l['categorie'], $l['nombre'], $l['montant']], $d['chargesParCategorie']), ['Total', array_sum(array_column($d['chargesParCategorie'], 'nombre')), array_sum(array_column($d['chargesParCategorie'], 'montant'))]),
        ], 'statistiques-tresorerie-'.$periode);
    }

    /**
     * Mes statistiques (maquette U05) : les chiffres de la personne, ses
     * objectifs, et ceux de l'entreprise que ses droits laissent voir.
     *
     * @param array<string, mixed> $d
     */
    public static function mesStatistiques(array $d, string $nom, string $periode): Export
    {
        $s = $d['s'];
        $tableaux = [new Tableau('Indicateurs', ['Indicateur' => 'texte', 'Valeur' => 'entier', 'Unité' => 'texte'], [
            ['Ventes du mois (commandes TTC)', $s['ventes'], 'F'],
            ['Commandes traitées', $s['commandes'], 'commandes'],
            ['Panier moyen', $s['panierMoyen'], 'F'],
            ['Devis envoyés', $s['devisEmis'], 'devis'],
            ['Taux de transformation', $s['tauxTransformation'], '%'],
            ['Encaissements', $s['encaissements'], 'F'],
            ['Devis à relancer', $s['devisARelancer'], 'devis'],
            ['Remises accordées', null === $s['tauxRemise'] ? null : (int) round($s['tauxRemise']), '% du CA'],
        ])];
        $tableaux[] = new Tableau('Ventes par mois', ['Mois' => 'texte', 'Ventes' => 'fcfa'], array_map(static fn (array $l): array => [$l['mois']->format('m/Y'), $l['montant']], $s['parMois']));

        if ([] !== ($d['objectifs'] ?? [])) {
            array_push($tableaux, ...self::tableauxObjectifs($d['objectifs']));
        }

        $entreprise = array_values(array_filter([
            null !== ($d['chiffreAffaires'] ?? null) ? ['CA global de l\'entreprise (12 mois)', $d['chiffreAffaires']] : null,
            null !== ($d['marge'] ?? null) ? ['Marge brute du mois', $d['marge']['marge']] : null,
            null !== ($d['tresorerie'] ?? null) ? ['Trésorerie (soldes des comptes)', $d['tresorerie']] : null,
        ]));
        if ([] !== $entreprise) {
            $tableaux[] = new Tableau('Chiffres de l\'entreprise', ['Indicateur' => 'texte', 'Montant' => 'fcfa'], $entreprise);
        }

        return new Export('Statistiques · '.$nom, $periode, $tableaux, 'mes-statistiques-'.$periode);
    }
}
