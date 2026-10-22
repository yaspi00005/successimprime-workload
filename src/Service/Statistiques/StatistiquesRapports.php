<?php

namespace App\Service\Statistiques;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Factures;
use App\Service\CalculateurCoutRevient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Statistiques et rapports (maquette 36) : CA facturé (factures émises,
 * hors proformas), marge brute, m² imprimés et nouveaux clients sur les
 * commandes confirmées, chacun comparé à la période d'avant de même durée.
 *
 * La marge ne compte que les lignes dont le coût de revient est complet
 * (matières, encre, machine renseignées) : la part du CA chiffrée est
 * rendue avec, pour juger de la fiabilité.
 *
 * Tout compte l'imprimerie seule par défaut ; $activite (Activite) prend
 * les ventes hors imprimerie, ou tout. La carte « repartition » montre
 * toujours les deux côtés et le total (docs/hors-imprimerie.md).
 */
final class StatistiquesRapports
{
    public const PERIODES = [3 => '3 mois', 6 => '6 mois', 12 => '12 mois'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalculateurCoutRevient $couts,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function donnees(\DateTimeImmutable $maintenant, int $mois, bool $avecMarge, string $activite = Activite::IMPRIMERIE): array
    {
        $debut = $maintenant->modify('first day of this month')->setTime(0, 0)->modify(sprintf('-%d months', $mois - 1));
        $debutAvant = $debut->modify(sprintf('-%d months', $mois));
        $finAvant = $maintenant->modify(sprintf('-%d months', $mois));

        /** @var list<Commandes> $commandes */
        $commandes = $this->em->createQueryBuilder()
            ->select('c', 'client', 'agent')
            ->from(Commandes::class, 'c')
            ->leftJoin('c.clients', 'client')
            ->leftJoin('c.agents', 'agent')
            ->andWhere('c.deleted = false AND c.soldeOuverture = false')
            ->andWhere('c.dateCommande >= :depuis')
            ->setParameter('depuis', $debutAvant)
            ->getQuery()
            ->getResult();
        // Le statut des travaux se déduit des lignes : filtré ici.
        $commandes = array_values(array_filter($commandes, static fn (Commandes $c): bool => 'annulee' !== $c->getStatutTravaux()));
        $periodeTout = array_values(array_filter($commandes, static fn (Commandes $c): bool => $c->getDateCommande() >= $debut));
        $lignes = $this->lignes($debutAvant);
        // CA = factures émises, hors proformas (docs/indicateurs.md).
        $ventes = $this->ventes($debutAvant);
        $ventesPeriodeTout = array_values(array_filter($ventes, static fn (array $v): bool => $v['date'] >= $debut));
        $lignesPeriodeTout = array_values(array_filter($lignes, static fn (CommandesDetails $l): bool => $l->getCommande()?->getDateCommande() >= $debut));
        $chiffreesTout = $avecMarge ? $this->chiffrer($lignesPeriodeTout) : [];
        $repartition = self::repartition($periodeTout, $ventesPeriodeTout, $avecMarge ? $chiffreesTout : null);

        // L'activité choisie : imprimerie seule par défaut.
        $retenue = static fn (?Commandes $c): bool => Activite::retient((bool) $c?->isHorsImprimerie(), $activite);
        $commandes = array_values(array_filter($commandes, $retenue));
        $lignes = array_values(array_filter($lignes, static fn (CommandesDetails $l): bool => $retenue($l->getCommande())));
        $ventes = array_values(array_filter($ventes, static fn (array $v): bool => Activite::retient($v['hors'], $activite)));

        $periode = array_values(array_filter($commandes, static fn (Commandes $c): bool => $c->getDateCommande() >= $debut));
        $avant = array_values(array_filter($commandes, static fn (Commandes $c): bool => $c->getDateCommande() < $debut && $c->getDateCommande() <= $finAvant));
        $lignesPeriode = array_values(array_filter($lignes, static fn (CommandesDetails $l): bool => $l->getCommande()?->getDateCommande() >= $debut));
        $lignesAvant = array_values(array_filter($lignes, static fn (CommandesDetails $l): bool => $l->getCommande()?->getDateCommande() < $debut && $l->getCommande()?->getDateCommande() <= $finAvant));

        $ventesPeriode = array_values(array_filter($ventes, static fn (array $v): bool => $v['date'] >= $debut));
        $ventesAvant = array_values(array_filter($ventes, static fn (array $v): bool => $v['date'] < $debut && $v['date'] <= $finAvant));
        $ca = self::ca($ventesPeriode);
        $caAvant = self::ca($ventesAvant);
        $surface = self::surface($lignesPeriode);
        $surfaceAvant = self::surface($lignesAvant);
        $nouveaux = $this->nouveauxClients($debut, $maintenant);
        $nouveauxAvant = $this->nouveauxClients($debutAvant, $finAvant);

        $marge = $margeAvant = null;
        $parCategorie = [];
        if ($avecMarge) {
            $chiffrees = array_values(array_filter($chiffreesTout, static fn (array $l): bool => Activite::retient($l['hors'], $activite)));
            $marge = self::marge($chiffrees);
            $margeAvant = self::marge($this->chiffrer($lignesAvant));
            $parCategorie = self::margeParCategorie($chiffrees);
        }

        return [
            'kpi' => [
                'ca' => $ca,
                'ca_evolution' => self::evolution($ca, $caAvant),
                'marge' => $marge,
                'marge_evolution' => null !== $marge && null !== $margeAvant && null !== $marge['taux'] && null !== $margeAvant['taux'] ? $marge['taux'] - $margeAvant['taux'] : null,
                'surface' => $surface,
                'surface_evolution' => self::evolution((int) round($surface), (int) round($surfaceAvant)),
                'nouveaux' => $nouveaux,
                'nouveaux_evolution' => self::evolution($nouveaux, $nouveauxAvant),
                'commandes' => \count($periode),
                'factures' => \count($ventesPeriode),
                'panier' => [] === $ventesPeriode ? 0 : intdiv($ca, \count($ventesPeriode)),
            ],
            'mois' => self::parMois($ventesPeriode, $ventesAvant, $debut, $mois),
            'categories' => $parCategorie,
            'top_client' => self::top($ventesPeriode, 'client'),
            'top_agent' => self::top($ventesPeriode, 'agent'),
            'debut' => $debut,
            'repartition' => $repartition,
            'activite' => $activite,
        ];
    }

    /**
     * Carte « Imprimerie / Hors imprimerie / Total » de la période :
     * commandes, CA facturé et, avec $chiffrees, taux de marge brute.
     *
     * @param list<Commandes>                                                   $commandes
     * @param list<array{montant: int, hors: bool}>                             $ventes
     * @param list<array{categorie: string, ht: int, cout: ?int, hors: bool}>|null $chiffrees
     *
     * @return array{imprimerie: array<string, mixed>, hors: array<string, mixed>, total: array<string, mixed>}
     */
    public static function repartition(array $commandes, array $ventes, ?array $chiffrees): array
    {
        $cote = static function (string $activite) use ($commandes, $ventes, $chiffrees): array {
            $c = [
                'ca' => self::ca(array_values(array_filter($ventes, static fn (array $v): bool => Activite::retient($v['hors'], $activite)))),
                'commandes' => \count(array_filter($commandes, static fn (Commandes $c): bool => Activite::retient($c->isHorsImprimerie(), $activite))),
            ];
            if (null !== $chiffrees) {
                $c['marge'] = self::marge(array_values(array_filter($chiffrees, static fn (array $l): bool => Activite::retient($l['hors'], $activite))))['taux'];
            }

            return $c;
        };
        $total = $cote(Activite::TOUT);

        return Activite::repartition($cote(Activite::IMPRIMERIE), $cote(Activite::HORS), null !== $chiffrees ? ['marge' => $total['marge']] : []);
    }

    /**
     * @param list<array{date: \DateTimeImmutable, montant: int, client: ?string, agent: ?string}> $ventes
     */
    public static function ca(array $ventes): int
    {
        return array_sum(array_column($ventes, 'montant'));
    }

    /**
     * @param list<CommandesDetails> $lignes
     */
    public static function surface(array $lignes): float
    {
        return round(array_sum(array_map(static fn (CommandesDetails $l): float => $l->getSurfaceTotale(), $lignes)), 1);
    }

    public static function evolution(int $valeur, int $avant): ?int
    {
        return $avant > 0 ? (int) round(($valeur - $avant) * 100 / $avant) : null;
    }

    /**
     * @param list<array{categorie: string, ht: int, cout: ?int}> $chiffrees
     *
     * @return array{taux: ?int, montant: int, part_chiffree: ?int}
     */
    public static function marge(array $chiffrees): array
    {
        $ht = $htChiffre = $cout = 0;
        foreach ($chiffrees as $l) {
            $ht += $l['ht'];
            if (null !== $l['cout']) {
                $htChiffre += $l['ht'];
                $cout += $l['cout'];
            }
        }

        return [
            'taux' => $htChiffre > 0 ? (int) round(($htChiffre - $cout) * 100 / $htChiffre) : null,
            'montant' => $htChiffre - $cout,
            'part_chiffree' => $ht > 0 ? (int) round($htChiffre * 100 / $ht) : null,
        ];
    }

    /**
     * @param list<array{categorie: string, ht: int, cout: ?int}> $chiffrees
     *
     * @return list<array{categorie: string, taux: int, ht: int}>
     */
    public static function margeParCategorie(array $chiffrees, int $limite = 6): array
    {
        $groupes = [];
        foreach ($chiffrees as $l) {
            if (null !== $l['cout']) {
                $groupes[$l['categorie']] ??= ['ht' => 0, 'cout' => 0];
                $groupes[$l['categorie']]['ht'] += $l['ht'];
                $groupes[$l['categorie']]['cout'] += $l['cout'];
            }
        }
        $resultat = [];
        foreach ($groupes as $categorie => $g) {
            if ($g['ht'] > 0) {
                $resultat[] = ['categorie' => $categorie, 'taux' => (int) round(($g['ht'] - $g['cout']) * 100 / $g['ht']), 'ht' => $g['ht']];
            }
        }
        usort($resultat, static fn (array $a, array $b): int => $b['taux'] <=> $a['taux']);

        return \array_slice($resultat, 0, $limite);
    }

    /**
     * @param list<array{date: \DateTimeImmutable, montant: int}> $periode
     * @param list<array{date: \DateTimeImmutable, montant: int}> $avant
     *
     * @return list<array{libelle: string, mois: \DateTimeImmutable, montant: int, avant: int, courant: bool}>
     */
    public static function parMois(array $periode, array $avant, \DateTimeImmutable $debut, int $mois): array
    {
        $initiales = ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D'];
        $serie = [];
        for ($i = 0; $i < $mois; ++$i) {
            $m = $debut->modify(sprintf('+%d months', $i));
            $serie[$m->format('Y-m')] = ['libelle' => $initiales[(int) $m->format('n') - 1], 'mois' => $m, 'montant' => 0, 'avant' => 0, 'courant' => $i === $mois - 1];
        }
        foreach ($periode as $v) {
            $cle = $v['date']->format('Y-m');
            if (isset($serie[$cle])) {
                $serie[$cle]['montant'] += $v['montant'];
            }
        }
        foreach ($avant as $v) {
            $cle = $v['date']->modify(sprintf('+%d months', $mois))->format('Y-m');
            if (isset($serie[$cle])) {
                $serie[$cle]['avant'] += $v['montant'];
            }
        }

        return array_values($serie);
    }

    /**
     * Le plus gros contributeur au CA ('client' ou 'agent'), avec sa part.
     *
     * @param list<array{montant: int, client: ?string, agent: ?string}> $ventes
     *
     * @return array{nom: string, montant: int, part: int}|null
     */
    public static function top(array $ventes, string $cle): ?array
    {
        $totaux = [];
        $total = 0;
        foreach ($ventes as $v) {
            $total += $v['montant'];
            $nom = trim((string) ($v[$cle] ?? ''));
            if ('' !== $nom) {
                $totaux[$nom] = ($totaux[$nom] ?? 0) + $v['montant'];
            }
        }
        if ([] === $totaux || $total <= 0) {
            return null;
        }
        arsort($totaux);
        $nom = (string) array_key_first($totaux);

        return ['nom' => $nom, 'montant' => $totaux[$nom], 'part' => (int) round($totaux[$nom] * 100 / $total)];
    }

    /**
     * @return list<array{date: \DateTimeImmutable, montant: int, client: ?string, agent: ?string, hors: bool}>
     */
    private function ventes(\DateTimeImmutable $depuis): array
    {
        /** @var list<Factures> $factures */
        $factures = $this->em->createQueryBuilder()
            ->select('f', 'c', 'client', 'agent')
            ->from(Factures::class, 'f')
            ->leftJoin('f.commande', 'c')
            ->leftJoin('c.clients', 'client')
            ->leftJoin('c.agents', 'agent')
            ->andWhere('f.etat = :emise')
            ->andWhere('f.typeDocument = :facture')
            ->andWhere('f.dateFacture >= :depuis')
            ->setParameter('emise', Factures::ETAT_EMISE)
            ->setParameter('facture', Factures::TYPE_FACTURE)
            ->setParameter('depuis', $depuis)
            ->getQuery()
            ->getResult();

        return array_map(static fn (Factures $f): array => [
            'date' => \DateTimeImmutable::createFromInterface($f->getDateFacture()),
            'montant' => $f->getTotalTtc(),
            'client' => $f->getCommande()?->getClients()?->getNomComplet(),
            'agent' => $f->getCommande()?->getAgents()?->getUsername(),
            'hors' => (bool) $f->getCommande()?->isHorsImprimerie(),
        ], $factures);
    }

    /**
     * @return list<CommandesDetails>
     */
    private function lignes(\DateTimeImmutable $depuis): array
    {
        return $this->em->createQueryBuilder()
            ->select('l', 'c', 'p', 'cat', 'm')
            ->from(CommandesDetails::class, 'l')
            ->innerJoin('l.commande', 'c')
            ->leftJoin('l.produit', 'p')
            ->leftJoin('p.categorieProduit', 'cat')
            ->leftJoin('l.machine', 'm')
            ->andWhere('c.deleted = false')
            ->andWhere('l.statutProduction != :annulee')
            ->andWhere('c.dateCommande >= :depuis')
            ->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE)
            ->setParameter('depuis', $depuis)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<CommandesDetails> $lignes
     *
     * @return list<array{categorie: string, ht: int, cout: ?int, hors: bool}>
     */
    private function chiffrer(array $lignes): array
    {
        $resultat = [];
        foreach ($lignes as $l) {
            $cout = $this->couts->pourLigne($l);
            $resultat[] = [
                'categorie' => $l->getProduit()?->getCategorieProduit()?->getNom() ?? $l->getProduit()?->getNom() ?? 'Autre',
                'ht' => $l->getTotalHt(),
                'cout' => [] === $cout['manquants'] && $cout['total'] > 0 ? $cout['total'] : null,
                'hors' => (bool) $l->getCommande()?->isHorsImprimerie(),
            ];
        }

        return $resultat;
    }

    private function nouveauxClients(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        $premieres = $this->em->createQueryBuilder()
            ->select('MIN(c.dateCommande) AS premiere')
            ->from(Commandes::class, 'c')
            ->andWhere('c.deleted = false AND c.soldeOuverture = false')
            ->andWhere('c.clients IS NOT NULL')
            ->groupBy('c.clients')
            ->getQuery()
            ->getSingleColumnResult();
        $debut = $debut->format('Y-m-d H:i:s');
        $fin = $fin->format('Y-m-d H:i:s');

        return \count(array_filter($premieres, static fn ($d): bool => (string) $d >= $debut && (string) $d <= $fin));
    }
}
