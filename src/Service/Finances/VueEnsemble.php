<?php

namespace App\Service\Finances;

use App\Entity\CompteTresorerie;
use App\Entity\Depense;
use App\Entity\Factures;
use App\Entity\Paiements;
use App\Service\Creances;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Vue d'ensemble financière (maquette F01) : soldes des comptes, facturé
 * et encaissé du mois, dépenses hors salaires face au budget, marge brute
 * estimée, chiffre d'affaires et dépenses sur 6 mois, et ce qui demande
 * une attention (« À surveiller »).
 *
 * Chaque chiffre n'est calculé que si la personne a le droit de le voir
 * (voir $droits) : un chiffre caché n'est jamais envoyé à la page.
 * Le facturé est celui de la comptabilité : factures comptabilisées et
 * émises, jamais les pro formas.
 */
final class VueEnsemble
{
    /** Ordre des comptes à l'écran : banque, mobile money, caisses. */
    private const ORDRE_TYPES = [CompteTresorerie::TYPE_BANQUE => 0, CompteTresorerie::TYPE_ORANGE_MONEY => 1, CompteTresorerie::TYPE_WAVE => 2, CompteTresorerie::TYPE_CAISSE => 3];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Creances $creances,
        private readonly Budgets $budgets,
        private readonly Rentabilite $rentabilite,
        private readonly ReglagesFinances $reglages,
        private readonly Echeancier $echeancier,
        private readonly PrevisionTresorerie $prevision,
    ) {
    }

    /**
     * @param array{tresorerie: bool, ca: bool, paiements: bool, marges: bool, admin: bool, saisie?: bool} $droits
     *
     * @return array<string, mixed>
     */
    public function donnees(\DateTimeImmutable $maintenant, \DateTimeImmutable $mois, array $droits): array
    {
        [$debut, $fin] = Periode::bornes($mois);
        $enCours = $maintenant >= $debut && $maintenant < $fin;
        $donnees = [
            'mois' => $debut,
            'libelle_mois' => Periode::libelle($debut),
            'nom_mois' => Periode::MOIS[(int) $debut->format('n')],
            'en_cours' => $enCours,
            'jour' => $enCours ? (int) $maintenant->format('j') : (int) $debut->format('t'),
            'jours_mois' => (int) $debut->format('t'),
            'comptes' => null,
            'facture' => null,
            'encaisse' => null,
            'depenses' => null,
            'marge' => null,
            'graphique' => null,
            'surveiller' => [],
        ];

        if ($droits['tresorerie']) {
            $donnees['comptes'] = $this->comptes();
            $budgets = $this->budgets->montants();
            $consomme = array_sum($this->budgets->consommes($debut, $fin));
            $budget = array_sum($budgets);
            $donnees['depenses'] = ['montant' => $consomme, 'budget' => $budget, 'part' => $budget > 0 ? (int) round($consomme * 100 / $budget) : null];
        }

        $facture = null;
        if ($droits['ca']) {
            $facture = $this->facture($debut, $fin);
            $objectif = $this->reglages->objectifCa();
            $donnees['facture'] = ['montant' => $facture, 'objectif' => $objectif, 'part' => $objectif > 0 ? (int) round($facture * 100 / $objectif) : null];
        }

        if ($droits['paiements']) {
            $encaisse = $this->encaisse($debut, $fin);
            $donnees['encaisse'] = ['montant' => $encaisse, 'part' => null !== $facture && $facture > 0 ? (int) round($encaisse * 100 / $facture) : null];
        }

        if ($droits['marges']) {
            $taux = $this->rentabilite->tauxDuMois($debut, $fin);
            [$debutAvant, $finAvant] = Periode::bornes($debut->modify('-1 month'));
            $avant = $this->rentabilite->tauxDuMois($debutAvant, $finAvant);
            $donnees['marge'] = ['taux' => $taux, 'ecart' => null !== $taux && null !== $avant ? $taux - $avant : null, 'mois_avant' => Periode::MOIS[(int) $debutAvant->format('n')]];
        }

        if ($droits['ca'] || $droits['tresorerie']) {
            $donnees['graphique'] = $this->graphique($debut, $droits['ca'], $droits['tresorerie']);
        }

        $donnees['surveiller'] = $this->surveiller($maintenant, $droits);

        return $donnees;
    }

    /**
     * Barres du graphique en millions de F, hauteur relative à la plus haute.
     *
     * @param list<array{libelle: string, ca: ?int, depenses: ?int}> $mois
     *
     * @return list<array{libelle: string, ca: ?int, depenses: ?int, ca_m: ?string, depenses_m: ?string, h_ca: int, h_depenses: int}>
     */
    public static function barres(array $mois): array
    {
        $max = max([1, ...array_map(static fn (array $m): int => max((int) $m['ca'], (int) $m['depenses']), $mois)]);
        $millions = static fn (?int $v): ?string => null === $v ? null : rtrim(rtrim(number_format($v / 1000000, $v >= 10000000 ? 1 : 2, ',', ''), '0'), ',');

        return array_map(static fn (array $m): array => $m + [
            'ca_m' => $millions($m['ca']),
            'depenses_m' => $millions($m['depenses']),
            'h_ca' => null === $m['ca'] ? 0 : (int) round($m['ca'] * 100 / $max),
            'h_depenses' => null === $m['depenses'] ? 0 : (int) round($m['depenses'] * 100 / $max),
        ], $mois);
    }

    /**
     * Comptes actifs avec leur solde : banque, mobile money, caisses.
     *
     * @return list<array{nom: string, type: string, solde: int, rang: int}>
     */
    private function comptes(): array
    {
        /** @var list<CompteTresorerie> $comptes */
        $comptes = $this->em->getRepository(CompteTresorerie::class)->findBy(['actif' => true]);
        usort($comptes, static fn (CompteTresorerie $a, CompteTresorerie $b): int => [self::ORDRE_TYPES[$a->getType()] ?? 9, -$a->getSoldeActuel()] <=> [self::ORDRE_TYPES[$b->getType()] ?? 9, -$b->getSoldeActuel()]);

        $rangs = [];

        return array_map(static function (CompteTresorerie $c) use (&$rangs): array {
            $rangs[$c->getType()] = ($rangs[$c->getType()] ?? -1) + 1;

            return ['nom' => $c->getNom(), 'type' => $c->getType(), 'solde' => $c->getSoldeActuel(), 'rang' => $rangs[$c->getType()]];
        }, $comptes);
    }

    /** Factures comptabilisées et émises du mois, TTC (montants de la comptabilité). */
    private function facture(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(f.totalTtc), 0)')
            ->from(Factures::class, 'f')
            ->andWhere('f.typeDocument = :facture')
            ->andWhere('f.comptabilisee = true')
            ->andWhere('f.etat = :emise')
            ->andWhere('f.dateFacture >= :debut AND f.dateFacture < :fin')
            ->setParameter('facture', Factures::TYPE_FACTURE)
            ->setParameter('emise', Factures::ETAT_EMISE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Paiements validés du mois. Le paiement par le solde du client ne
     * fait pas entrer d'argent : il n'est pas compté.
     */
    private function encaisse(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(p.montant), 0)')
            ->from(Paiements::class, 'p')
            ->andWhere('p.statut = :valide')
            ->andWhere('p.mode != :solde')
            ->andWhere('p.date >= :debut AND p.date < :fin')
            ->setParameter('valide', Paiements::STATUT_VALIDE)
            ->setParameter('solde', Paiements::MODE_SOLDE_CLIENT)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Les 6 mois jusqu'au mois affiché.
     *
     * @return array{mois: list<array<string, mixed>>, partiel: ?string}
     */
    private function graphique(\DateTimeImmutable $debut, bool $ca, bool $depenses): array
    {
        $mois = [];
        for ($i = 5; $i >= 0; --$i) {
            [$d, $f] = Periode::bornes($debut->modify(\sprintf('-%d months', $i)));
            $mois[] = [
                'libelle' => Periode::MOIS_COURTS[(int) $d->format('n')],
                'ca' => $ca ? $this->facture($d, $f) : null,
                'depenses' => $depenses ? $this->budgets->chargesTotales($d, $f) : null,
            ];
        }

        return ['mois' => self::barres($mois), 'partiel' => null];
    }

    /**
     * Ce qui demande une attention, chaque ligne selon les droits.
     *
     * @param array{tresorerie: bool, ca: bool, paiements: bool, marges: bool, admin: bool, saisie?: bool} $droits
     *
     * @return list<array{cle: string, titre: string, detail: string, valeur: string, couleur: string, route: ?string, parametres?: array<string, mixed>}>
     */
    private function surveiller(\DateTimeImmutable $maintenant, array $droits): array
    {
        $lignes = [];
        $f = static fn (int $n): string => number_format($n, 0, ',', ' ').' F';

        if ($droits['paiements']) {
            $creances = $this->creances->donnees($maintenant)['lignes'];
            $total = array_sum(array_column($creances, 'reste'));
            $echues = array_values(array_filter($creances, static fn (array $c): bool => $c['jours'] > 0));
            $clients = \count(array_unique(array_map(static fn (array $c): string => (string) $c['client'], $echues)));
            if ($total > 0) {
                $lignes[] = [
                    'cle' => 'creances', 'titre' => 'Créances clients', 'couleur' => 'rouge', 'valeur' => $f($total), 'route' => 'app_creances_index',
                    'detail' => [] === $echues ? 'aucune créance échue' : \sprintf('dont %s échus · %d client%s', $f(array_sum(array_column($echues, 'reste'))), $clients, $clients > 1 ? 's' : ''),
                ];
            }

            $attente = $this->em->createQueryBuilder()
                ->select('COUNT(p.id) AS nombre', 'COALESCE(SUM(p.montant), 0) AS montant', 'SUM(CASE WHEN p.encaissePar IS NULL THEN 1 ELSE 0 END) AS en_ligne')
                ->from(Paiements::class, 'p')
                ->andWhere('p.statut = :attente')
                ->setParameter('attente', Paiements::STATUT_EN_ATTENTE)
                ->getQuery()
                ->getSingleResult();
            if ((int) $attente['nombre'] > 0) {
                $enLigne = (int) $attente['en_ligne'];
                $lignes[] = [
                    'cle' => 'en_attente', 'titre' => 'Encaissements à valider', 'couleur' => 'bleu', 'valeur' => (string) (int) $attente['nombre'],
                    'route' => $droits['admin'] ? 'app_a_valider_index' : null,
                    'detail' => $f((int) $attente['montant']).($enLigne > 0 ? \sprintf(' · %d saisi%s en ligne', $enLigne, $enLigne > 1 ? 's' : '') : ' · virements et chèques'),
                ];
            }
        }

        if ($droits['tresorerie'] || $droits['admin']) {
            /** @var list<Depense> $aApprouver */
            $aApprouver = $this->em->getRepository(Depense::class)->findBy(['statut' => Depense::A_APPROUVER], ['creeLe' => 'ASC']);
            if ([] !== $aApprouver) {
                $premiere = $aApprouver[0];
                $nombre = \count($aApprouver);
                $lignes[] = [
                    'cle' => 'depense_a_approuver', 'titre' => $nombre > 1 ? 'Dépenses à approuver' : 'Dépense à approuver', 'couleur' => 'rose', 'valeur' => (string) $nombre,
                    'route' => 1 === $nombre ? 'app_finances_depenses_voir' : 'app_finances_depenses',
                    'parametres' => 1 === $nombre ? ['id' => $premiere->getId()] : [],
                    'detail' => 1 === $nombre
                        ? \sprintf('%s · %s', $premiere->getObjet(), $f($premiere->getMontant()))
                        : \sprintf('%s au total · la plus ancienne : %s', $f(array_sum(array_map(static fn (Depense $d): int => $d->getMontant(), $aApprouver))), $premiere->getObjet()),
                ];
            }
        }

        if ($droits['tresorerie']) {
            $aujourdhui = $maintenant->setTime(0, 0);
            $du = array_sum(array_column($this->echeancier->dettes($aujourdhui), 'du'));
            if ($du > 0) {
                $fournisseurs = array_values(array_filter($this->echeancier->sorties($aujourdhui, $aujourdhui->modify('+60 days')), static fn (array $s): bool => 'fournisseur' === $s['type']));
                $retard = array_sum(array_map(static fn (array $s): int => $s['en_retard'] ? (int) $s['montant'] : 0, $fournisseurs));
                $prochain = Echeancier::parJour(array_values(array_filter($fournisseurs, static fn (array $s): bool => $s['date'] > $aujourdhui)))[0] ?? null;
                $lignes[] = [
                    'cle' => 'dettes', 'titre' => 'Dettes fournisseurs', 'couleur' => 'orange', 'valeur' => $f($du), 'route' => 'app_finances_fournisseurs',
                    'detail' => $retard > 0
                        ? \sprintf('dont %s en retard', $f($retard))
                        : (null !== $prochain ? \sprintf('dont %s le %s', $f($prochain['total']), $prochain['date']->format('d/m')) : 'à payer dès maintenant'),
                ];
            }
        }

        if ($droits['tresorerie']) {
            // Prévision F05, scénario prudent : toujours depuis aujourd'hui.
            $p = $this->prevision->donnees($maintenant, 'prudent');
            $bas = $p['point_bas'];
            $sous = $p['sous_seuil'];
            $lignes[] = [
                'cle' => 'point_bas', 'titre' => 'Point bas de trésorerie', 'couleur' => null !== $sous ? 'rouge' : 'canard', 'valeur' => $f($bas['solde']), 'route' => 'app_finances_tresorerie',
                'detail' => match (true) {
                    null !== $sous && $sous['date'] <= $p['aujourdhui'] => \sprintf('déjà sous le seuil de %s', $f($p['seuil'])),
                    null !== $sous => \sprintf('sous le seuil de %s le %s', $f($p['seuil']), $sous['date']->format('d/m')),
                    0 === $bas['index'] => 'aujourd’hui : le solde prévu ne descend pas plus bas sur 90 jours',
                    default => \sprintf('prévu le %s%s', $bas['date']->format('d/m'), null !== $p['cause_point_bas'] ? ', '.$p['cause_point_bas'] : ''),
                },
            ];
        }

        // Ordre de la maquette : créances, dettes, encaissements, dépenses, trésorerie.
        $ordre = array_flip(['creances', 'dettes', 'en_attente', 'depense_a_approuver', 'point_bas']);
        usort($lignes, static fn (array $a, array $b): int => ($ordre[$a['cle']] ?? 9) <=> ($ordre[$b['cle']] ?? 9));

        return $lignes;
    }
}
