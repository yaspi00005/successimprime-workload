<?php

namespace App\Service\Statistiques;

use App\Entity\CategorieProduit;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Factures;
use App\Entity\Machines;
use App\Entity\Objectif;
use App\Entity\Paiements;
use App\Entity\User;
use App\Repository\ObjectifRepository;
use App\Service\Finances\Periode;
use App\Service\Finances\ReglagesFinances;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Objectifs du mois (Statistiques › Objectifs) : ce que la Direction fixe
 * pour chaque indicateur, ce qui est réalisé, la part atteinte et la
 * projection en fin de mois au rythme actuel.
 *
 * Définitions (docs/statistiques.md) :
 *  - CA facturé : factures émises, hors pro formas, TTC, à leur date ;
 *    par agent, celui de la commande ; par catégorie, le montant de la
 *    facture réparti au prorata des lignes de la commande ;
 *  - commandes : commandes du mois, ni supprimées ni annulées ;
 *  - m² imprimés : lignes terminées en production dans le mois ;
 *  - nouveaux clients : première commande dans le mois ;
 *  - taux d'encaissement : paiements validés du mois ÷ CA facturé du mois.
 *
 * Sans objectif enregistré, le CA global reprend le réglage « Objectif de
 * CA facturé par mois », le CA d'un agent son « Objectif de ventes du
 * mois » (fiche utilisateur) et le taux d'encaissement 80 %, le seuil
 * d'alerte de docs/indicateurs.md.
 */
final class Objectifs
{
    /**
     * droit : clé de DroitsStatistiques qui montre la ligne (en plus de
     * « global ») ; cumul : la valeur s'additionne au fil du mois (sinon
     * c'est un taux, sans projection).
     */
    public const INDICATEURS = [
        'ca' => ['libelle' => 'CA facturé', 'unite' => 'F', 'axes' => ['global', 'categorie', 'agent'], 'cumul' => true, 'droit' => 'ventes', 'format' => 'fcfa'],
        'commandes' => ['libelle' => 'Commandes', 'unite' => 'commandes', 'axes' => ['global', 'agent'], 'cumul' => true, 'droit' => 'global', 'format' => 'entier'],
        'surface' => ['libelle' => 'm² imprimés', 'unite' => 'm²', 'axes' => ['global', 'machine'], 'cumul' => true, 'droit' => 'global', 'format' => 'm2'],
        'nouveaux_clients' => ['libelle' => 'Nouveaux clients', 'unite' => 'clients', 'axes' => ['global'], 'cumul' => true, 'droit' => 'global', 'format' => 'entier'],
        'taux_encaissement' => ['libelle' => 'Taux d\'encaissement', 'unite' => '%', 'axes' => ['global'], 'cumul' => false, 'droit' => 'paiements', 'format' => 'pourcent'],
    ];

    public const AXES = [
        'global' => 'Entreprise',
        'categorie' => 'Par catégorie de produits',
        'agent' => 'Par agent',
        'machine' => 'Par machine',
    ];

    /** Taux d'encaissement visé sans objectif fixé (seuil d'alerte de docs/indicateurs.md). */
    public const TAUX_ENCAISSEMENT_PAR_DEFAUT = 80;

    public const VALEUR_MAX = 2000000000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ObjectifRepository $depot,
        private readonly ReglagesFinances $reglages,
    ) {
    }

    /* ---------- Règles pures ---------- */

    /**
     * La personne voit-elle cette ligne d'objectif ? Ses propres objectifs
     * d'agent (Mes statistiques) demandent ROLE_STATS_PERSO ; tout le reste
     * les chiffres globaux, et les montants ou l'encaissement leur droit.
     *
     * @param array<string, bool> $droits DroitsStatistiques
     */
    public static function peutVoir(string $indicateur, string $axe, array $droits, bool $soiMeme = false): bool
    {
        if (!isset(self::INDICATEURS[$indicateur]) || !\in_array($axe, self::INDICATEURS[$indicateur]['axes'], true)) {
            return false;
        }
        if ($soiMeme) {
            return 'agent' === $axe && ($droits['perso'] ?? false);
        }

        return ($droits['global'] ?? false) && ($droits[self::INDICATEURS[$indicateur]['droit']] ?? false);
    }

    /**
     * Où en est l'objectif : part atteinte, projection en fin de période au
     * rythme actuel (sauf pour un taux) et état.
     *
     * États : sans_objectif, a_venir (rien d'écoulé, ou moins d'un jour),
     * atteint, en_bonne_voie (projection ≥ 100 %), a_surveiller (≥ 90 %),
     * en_retard, manque (période finie sans l'atteindre).
     *
     * @return array{realise: float, objectif: ?int, part: ?int, projection: ?float, part_projetee: ?int, ecoule: int, attendu: ?float, etat: string}
     */
    public static function avancement(float $realise, ?int $objectif, \DateTimeImmutable $debut, \DateTimeImmutable $fin, \DateTimeImmutable $maintenant, bool $cumul = true): array
    {
        $duree = max(1, $fin->getTimestamp() - $debut->getTimestamp());
        $passe = $maintenant->getTimestamp() - $debut->getTimestamp();
        $ecoule = max(0.0, min(1.0, $passe / $duree));
        $finie = $ecoule >= 1.0;

        $projection = match (true) {
            !$cumul => $realise,
            $finie => $realise,
            $passe < 86400 => null,
            default => $realise / $ecoule,
        };
        $objectif = null !== $objectif && $objectif > 0 ? $objectif : null;
        $part = null !== $objectif ? (int) round($realise * 100 / $objectif) : null;
        $partProjetee = null !== $objectif && null !== $projection ? (int) round($projection * 100 / $objectif) : null;

        $etat = match (true) {
            null === $objectif => 'sans_objectif',
            $realise >= $objectif => 'atteint',
            $finie => 'manque',
            null === $partProjetee => 'a_venir',
            $partProjetee >= 100 => 'en_bonne_voie',
            $partProjetee >= 90 => 'a_surveiller',
            default => 'en_retard',
        };

        return [
            'realise' => $realise,
            'objectif' => $objectif,
            'part' => $part,
            'projection' => null === $projection ? null : round($projection, 1),
            'part_projetee' => $partProjetee,
            'ecoule' => (int) round($ecoule * 100),
            'attendu' => null === $objectif ? null : round($cumul ? $objectif * $ecoule : $objectif, 1),
            'etat' => $etat,
        ];
    }

    /**
     * Objectif d'une période qui ne fait pas un mois entier (une semaine,
     * le mois jusqu'à aujourd'hui) : chaque jour vaut l'objectif de son
     * mois divisé par le nombre de jours de ce mois. Null si aucun des
     * mois concernés n'a d'objectif.
     *
     * @param array<string, ?int> $parMois objectif par mois « AAAA-MM »
     */
    public static function prorata(array $parMois, \DateTimeImmutable $debut, \DateTimeImmutable $fin): ?int
    {
        $total = 0.0;
        $trouve = false;
        for ($jour = $debut->setTime(0, 0); $jour < $fin; $jour = $jour->modify('+1 day')) {
            $objectif = $parMois[$jour->format('Y-m')] ?? null;
            if (null !== $objectif && $objectif > 0) {
                $total += $objectif / (int) $jour->format('t');
                $trouve = true;
            }
        }

        return $trouve ? (int) round($total) : null;
    }

    /**
     * Répartit le montant d'une facture entre les catégories des lignes
     * de sa commande, au prorata de leur montant TTC. Le reste des arrondis
     * va à la plus grosse part, pour que la somme reste exacte.
     *
     * @param list<array{categorie: string, ttc: int}> $lignes
     *
     * @return array<string, int>
     */
    public static function repartir(int $montant, array $lignes): array
    {
        $parCategorie = [];
        foreach ($lignes as $l) {
            $parCategorie[$l['categorie']] = ($parCategorie[$l['categorie']] ?? 0) + max(0, $l['ttc']);
        }
        $total = array_sum($parCategorie);
        if ($total <= 0) {
            return [] === $parCategorie ? ['Autre' => $montant] : [array_key_first($parCategorie) => $montant];
        }

        $parts = array_map(static fn (int $ttc): int => intdiv($montant * $ttc, $total), $parCategorie);
        arsort($parCategorie);
        $parts[array_key_first($parCategorie)] += $montant - array_sum($parts);

        return $parts;
    }

    /** Lit une saisie d'objectif (« 9 000 000 », « 85 % », vide = pas d'objectif). */
    public static function lireValeur(string $saisie): ?int
    {
        $texte = str_replace([' ', "\u{a0}", "\u{202f}", 'F', 'f', '%', 'm²'], '', trim($saisie));
        $texte = str_replace(',', '.', $texte);
        if ('' === $texte || !is_numeric($texte)) {
            return null;
        }

        return max(0, min(self::VALEUR_MAX, (int) round((float) $texte)));
    }

    /* ---------- Objectifs enregistrés ---------- */

    /**
     * Objectifs du mois par clé « indicateur:axe:cible », valeurs par défaut
     * comprises (voir la classe).
     *
     * @return array<string, int>
     */
    public function valeurs(\DateTimeImmutable $mois): array
    {
        $valeurs = [];
        $defautCa = $this->reglages->objectifCa();
        if ($defautCa > 0) {
            $valeurs['ca:global:0'] = $defautCa;
        }
        $valeurs['taux_encaissement:global:0'] = self::TAUX_ENCAISSEMENT_PAR_DEFAUT;
        foreach ($this->agentsAvecObjectifFiche() as $agent) {
            $valeurs['ca:agent:'.$agent->getId()] = (int) $agent->getObjectifVentesMois();
        }

        foreach ($this->depot->duMois($mois) as $cle => $objectif) {
            $valeurs[$cle] = $objectif->getValeur();
        }

        // Un objectif mis à 0 retire l'objectif par défaut.
        return array_filter($valeurs, static fn (int $v): bool => $v > 0);
    }

    /**
     * Objectif global de CA des mois touchés par la période (prorata d'une
     * semaine), par mois « AAAA-MM ».
     *
     * @return array<string, ?int>
     */
    public function parMois(string $cle, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $parMois = [];
        for ($mois = $debut->modify('first day of this month')->setTime(0, 0); $mois < $fin; $mois = $mois->modify('+1 month')) {
            $parMois[$mois->format('Y-m')] = $this->valeurs($mois)[$cle] ?? null;
        }

        return $parMois;
    }

    /**
     * Enregistre les objectifs saisis pour le mois (null : retour à la
     * valeur par défaut, 0 : aucun objectif).
     *
     * @param array<string, ?int> $saisies par clé « indicateur:axe:cible »
     *
     * @return int nombre d'objectifs changés
     */
    public function enregistrer(\DateTimeImmutable $mois, array $saisies, ?User $par): int
    {
        $existants = $this->depot->duMois($mois);
        $maintenant = new \DateTimeImmutable();
        $changes = 0;

        foreach ($saisies as $cle => $valeur) {
            [$indicateur, $axe, $cible] = explode(':', $cle) + [null, null, null];
            if (null === $cible || !isset(self::INDICATEURS[$indicateur]) || !\in_array($axe, self::INDICATEURS[$indicateur]['axes'], true)) {
                continue;
            }
            $existant = $existants[$cle] ?? null;
            if (null === $valeur) {
                if (null !== $existant) {
                    $this->em->remove($existant);
                    ++$changes;
                }
                continue;
            }
            if (null !== $existant && $existant->getValeur() === $valeur) {
                continue;
            }
            $objectif = $existant ?? new Objectif($mois, $indicateur, $axe, (int) $cible);
            $objectif->modifier('taux_encaissement' === $indicateur ? min(100, $valeur) : $valeur, $par, $maintenant);
            $this->em->persist($objectif);
            ++$changes;
        }
        $this->em->flush();

        return $changes;
    }

    /**
     * Reprend les objectifs enregistrés du mois précédent pour ce mois
     * (ceux déjà fixés ce mois restent).
     */
    public function copierMoisPrecedent(\DateTimeImmutable $mois, ?User $par): int
    {
        $existants = $this->depot->duMois($mois);
        $saisies = [];
        foreach ($this->depot->duMois($mois->modify('first day of this month')->modify('-1 month')) as $cle => $objectif) {
            if (!isset($existants[$cle])) {
                $saisies[$cle] = $objectif->getValeur();
            }
        }

        return [] === $saisies ? 0 : $this->enregistrer($mois, $saisies, $par);
    }

    /* ---------- Tableau des objectifs ---------- */

    /**
     * Lignes d'objectifs du mois que la personne a le droit de voir, avec
     * réalisé, part et projection. Les chiffres qu'elle ne peut pas voir ne
     * sont pas calculés.
     *
     * @param array<string, bool> $droits DroitsStatistiques
     * @param User|null           $agent  seulement les objectifs de cet agent (Mes statistiques)
     *
     * @return list<array{cle: string, indicateur: string, axe: string, cible: int, libelle: string, groupe: string, unite: string, format: string, cumul: bool, realise: float, objectif: ?int, part: ?int, projection: ?float, part_projetee: ?int, ecoule: int, attendu: ?float, etat: string}>
     */
    public function tableau(\DateTimeImmutable $mois, array $droits, \DateTimeImmutable $maintenant, ?User $agent = null): array
    {
        [$debut, $fin] = Periode::bornes($mois);
        $finCalcul = min($fin, $maintenant);
        $objectifs = $this->valeurs($debut);
        $lignes = [];

        foreach (self::INDICATEURS as $indicateur => $definition) {
            foreach ($definition['axes'] as $axe) {
                $soiMeme = null !== $agent;
                if (!self::peutVoir($indicateur, $axe, $droits, $soiMeme)) {
                    continue;
                }
                $realises = $debut > $maintenant ? [] : $this->realises($indicateur, $axe, $debut, $finCalcul, $agent);
                foreach ($this->cibles($axe, $indicateur, $debut, $objectifs, $realises, $agent) as $cible => $nom) {
                    $cle = $indicateur.':'.$axe.':'.$cible;
                    $lignes[] = [
                        'cle' => $cle,
                        'indicateur' => $indicateur,
                        'axe' => $axe,
                        'cible' => $cible,
                        'libelle' => 'global' === $axe ? $definition['libelle'] : $nom,
                        'groupe' => 'global' === $axe ? self::AXES['global'] : $definition['libelle'].' · '.mb_strtolower(self::AXES[$axe]),
                        'unite' => $definition['unite'],
                        'format' => $definition['format'],
                        'cumul' => $definition['cumul'],
                    ] + self::avancement((float) ($realises[$cible] ?? 0), $objectifs[$cle] ?? null, $debut, $fin, $maintenant, $definition['cumul']);
                }
            }
        }

        return $lignes;
    }

    /**
     * Saisie de la Direction : toutes les cibles possibles du mois avec
     * l'objectif enregistré et la valeur par défaut, par axe.
     *
     * @return list<array{cle: string, indicateur: string, axe: string, libelle: string, groupe: string, unite: string, enregistre: ?int, defaut: ?int}>
     */
    public function saisie(\DateTimeImmutable $mois): array
    {
        $debut = $mois->modify('first day of this month')->setTime(0, 0);
        $enregistres = array_map(static fn (Objectif $o): int => $o->getValeur(), $this->depot->duMois($debut));
        $defauts = ['ca:global:0' => $this->reglages->objectifCa() ?: null, 'taux_encaissement:global:0' => self::TAUX_ENCAISSEMENT_PAR_DEFAUT];
        foreach ($this->agentsAvecObjectifFiche() as $a) {
            $defauts['ca:agent:'.$a->getId()] = $a->getObjectifVentesMois();
        }
        $valeurs = $this->valeurs($debut);
        $lignes = [];

        foreach (self::INDICATEURS as $indicateur => $definition) {
            foreach ($definition['axes'] as $axe) {
                foreach ($this->cibles($axe, $indicateur, $debut, $valeurs + $enregistres, [], null, true) as $cible => $nom) {
                    $cle = $indicateur.':'.$axe.':'.$cible;
                    $lignes[] = [
                        'cle' => $cle,
                        'indicateur' => $indicateur,
                        'axe' => $axe,
                        'libelle' => 'global' === $axe ? $definition['libelle'] : $nom,
                        'groupe' => 'global' === $axe ? self::AXES['global'] : $definition['libelle'].' · '.mb_strtolower(self::AXES[$axe]),
                        'unite' => $definition['unite'],
                        'enregistre' => $enregistres[$cle] ?? null,
                        'defaut' => $defauts[$cle] ?? null,
                    ];
                }
            }
        }

        return $lignes;
    }

    /* ---------- Réalisé ---------- */

    /**
     * Réalisé d'un indicateur sur la période, par cible (0 pour global).
     *
     * @return array<int, float>
     */
    public function realises(string $indicateur, string $axe, \DateTimeImmutable $debut, \DateTimeImmutable $fin, ?User $agent = null): array
    {
        return match ($indicateur) {
            'ca' => $this->ca($axe, $debut, $fin, $agent),
            'commandes' => $this->commandes($axe, $debut, $fin, $agent),
            'surface' => $this->surface($axe, $debut, $fin),
            'nouveaux_clients' => [0 => (float) $this->nouveauxClients($debut, $fin)],
            'taux_encaissement' => [0 => $this->tauxEncaissement($debut, $fin)],
            default => [],
        };
    }

    /**
     * CA facturé : factures émises (hors pro formas) de la période.
     *
     * @return array<int, float>
     */
    private function ca(string $axe, \DateTimeImmutable $debut, \DateTimeImmutable $fin, ?User $agent): array
    {
        if ('categorie' === $axe) {
            return $this->caParCategorie($debut, $fin);
        }

        $qb = $this->em->createQueryBuilder()
            ->from(Factures::class, 'f')
            ->leftJoin('f.commande', 'c')
            ->andWhere('f.etat = :emise')
            ->andWhere('f.typeDocument = :facture')
            ->andWhere('f.dateFacture >= :debut AND f.dateFacture < :fin')
            ->setParameter('emise', Factures::ETAT_EMISE)
            ->setParameter('facture', Factures::TYPE_FACTURE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin);

        if ('global' === $axe) {
            return [0 => (float) $qb->select('COALESCE(SUM(f.totalTtc), 0)')->getQuery()->getSingleScalarResult()];
        }

        $qb->select('IDENTITY(c.agents) AS cible', 'SUM(f.totalTtc) AS valeur')
            ->andWhere('c.agents IS NOT NULL')
            ->groupBy('c.agents');
        if (null !== $agent) {
            $qb->andWhere('c.agents = :agent')->setParameter('agent', $agent);
        }

        return self::parCible($qb->getQuery()->getArrayResult());
    }

    /**
     * @return array<int, float>
     */
    private function caParCategorie(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('f.id AS facture', 'f.totalTtc AS montant', 'cat.id AS categorie', 'l.totalTtc AS ttc', 'l.statutProduction AS statut')
            ->from(Factures::class, 'f')
            ->leftJoin('f.commande', 'c')
            ->leftJoin('c.commandesDetails', 'l')
            ->leftJoin('l.produit', 'p')
            ->leftJoin('p.categorieProduit', 'cat')
            ->andWhere('f.etat = :emise')
            ->andWhere('f.typeDocument = :facture')
            ->andWhere('f.dateFacture >= :debut AND f.dateFacture < :fin')
            ->setParameter('emise', Factures::ETAT_EMISE)
            ->setParameter('facture', Factures::TYPE_FACTURE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getArrayResult();

        $parFacture = [];
        foreach ($lignes as $l) {
            $parFacture[$l['facture']] ??= ['montant' => (int) $l['montant'], 'lignes' => []];
            if (null !== $l['statut'] && CommandesDetails::PRODUCTION_ANNULEE !== $l['statut']) {
                $parFacture[$l['facture']]['lignes'][] = ['categorie' => (string) ($l['categorie'] ?? 0), 'ttc' => (int) $l['ttc']];
            }
        }

        $totaux = [];
        foreach ($parFacture as $f) {
            foreach (self::repartir($f['montant'], $f['lignes']) as $categorie => $montant) {
                $id = 'Autre' === $categorie ? 0 : (int) $categorie;
                $totaux[$id] = ($totaux[$id] ?? 0) + $montant;
            }
        }

        return array_map('floatval', $totaux);
    }

    /**
     * Commandes ni supprimées ni annulées (toutes leurs lignes annulées).
     *
     * @return array<int, float>
     */
    private function commandes(string $axe, \DateTimeImmutable $debut, \DateTimeImmutable $fin, ?User $agent): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('c.id', 'IDENTITY(c.agents) AS agent', 'COUNT(l.id) AS lignes', 'SUM(CASE WHEN l.statutProduction = :annulee THEN 1 ELSE 0 END) AS annulees')
            ->from(Commandes::class, 'c')
            ->leftJoin('c.commandesDetails', 'l')
            ->andWhere('c.deleted = false AND c.soldeOuverture = false')
            ->andWhere('c.dateCommande >= :debut AND c.dateCommande < :fin')
            ->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->groupBy('c.id');
        if (null !== $agent) {
            $qb->andWhere('c.agents = :agent')->setParameter('agent', $agent);
        }

        $totaux = [];
        foreach ($qb->getQuery()->getArrayResult() as $c) {
            if ((int) $c['lignes'] > 0 && (int) $c['annulees'] === (int) $c['lignes']) {
                continue;
            }
            $cible = 'global' === $axe ? 0 : (int) $c['agent'];
            if ('agent' === $axe && 0 === $cible) {
                continue;
            }
            $totaux[$cible] = ($totaux[$cible] ?? 0) + 1;
        }

        return array_map('floatval', $totaux);
    }

    /**
     * m² des lignes terminées en production dans la période.
     *
     * @return array<int, float>
     */
    private function surface(string $axe, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(l.surface * l.quantite), 0) AS valeur')
            ->from(CommandesDetails::class, 'l')
            ->andWhere('l.productionTermineeLe >= :debut AND l.productionTermineeLe < :fin')
            ->andWhere('l.statutProduction != :annulee')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE);

        if ('global' === $axe) {
            return [0 => round((float) $qb->getQuery()->getSingleScalarResult(), 1)];
        }

        $qb->addSelect('IDENTITY(l.machine) AS cible')->andWhere('l.machine IS NOT NULL')->groupBy('l.machine');

        return array_map(static fn (float $v): float => round($v, 1), self::parCible($qb->getQuery()->getArrayResult()));
    }

    /** Clients dont la première commande tombe dans la période. */
    public function nouveauxClients(\DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        return \count($this->em->createQueryBuilder()
            ->select('IDENTITY(c.clients) AS client', 'MIN(c.dateCommande) AS premiere')
            ->from(Commandes::class, 'c')
            ->andWhere('c.deleted = false AND c.soldeOuverture = false')
            ->groupBy('c.clients')
            ->having('MIN(c.dateCommande) >= :debut AND MIN(c.dateCommande) < :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getArrayResult());
    }

    /** Paiements validés (hors paiement par le solde du client) ÷ CA facturé, en %. */
    public function tauxEncaissement(\DateTimeImmutable $debut, \DateTimeImmutable $fin): float
    {
        $facture = $this->ca('global', $debut, $fin, null)[0];
        if ($facture <= 0) {
            return 0.0;
        }

        return round($this->encaisse($debut, $fin) * 100 / $facture, 1);
    }

    public function encaisse(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?User $encaissePar = null): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(p.montant), 0)')
            ->from(Paiements::class, 'p')
            // Le règlement d'un solde d'ouverture n'est pas une vente de la période.
            ->leftJoin('p.commande', 'pc')
            ->andWhere('pc.id IS NULL OR pc.soldeOuverture = false')
            ->andWhere('p.statut = :valide')
            ->andWhere('p.mode != :solde')
            ->andWhere('p.date >= :debut AND p.date < :fin')
            ->setParameter('valide', Paiements::STATUT_VALIDE)
            ->setParameter('solde', Paiements::MODE_SOLDE_CLIENT)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin);
        if (null !== $encaissePar) {
            $qb->andWhere('p.encaissePar = :par')->setParameter('par', $encaissePar);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /* ---------- Cibles ---------- */

    /**
     * Cibles affichées pour un axe : celles qui ont un objectif ou un
     * réalisé (toutes pour la saisie), id => nom.
     *
     * @param array<string, int>  $objectifs
     * @param array<int, float>   $realises
     *
     * @return array<int, string>
     */
    private function cibles(string $axe, string $indicateur, \DateTimeImmutable $mois, array $objectifs, array $realises, ?User $agent, bool $toutes = false): array
    {
        if ('global' === $axe) {
            return [0 => ''];
        }
        if (null !== $agent) {
            return [(int) $agent->getId() => self::nomAgent($agent)];
        }

        $avecValeur = static function (int $id) use ($indicateur, $axe, $objectifs, $realises): bool {
            return isset($objectifs[$indicateur.':'.$axe.':'.$id]) || ($realises[$id] ?? 0) > 0;
        };

        $noms = match ($axe) {
            'categorie' => $this->nomsCategories(),
            'machine' => $this->nomsMachines(),
            'agent' => $this->nomsAgents($mois),
            default => [],
        };
        if ('categorie' === $axe && (($realises[0] ?? 0) > 0)) {
            $noms[0] = 'Sans catégorie';
        }

        return array_filter($noms, static fn (string $nom, int $id): bool => $toutes ? 0 !== $id : $avecValeur($id), \ARRAY_FILTER_USE_BOTH);
    }

    /** @return array<int, string> */
    private function nomsCategories(): array
    {
        $noms = [];
        foreach ($this->em->getRepository(CategorieProduit::class)->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']) as $c) {
            $noms[(int) $c->getId()] = (string) $c->getNom();
        }

        return $noms;
    }

    /** Machines facturées au m² (celles à la feuille n'ont pas d'objectif de m²). */
    private function nomsMachines(): array
    {
        $noms = [];
        foreach ($this->em->getRepository(Machines::class)->findBy(['modeFacturation' => Machines::MODE_FACTURATION_METRE_CARRE], ['nom' => 'ASC']) as $m) {
            $noms[(int) $m->getId()] = (string) $m->getNom();
        }

        return $noms;
    }

    /**
     * Agents commerciaux : comptes actifs qui ont pris une commande dans les
     * 6 derniers mois ou qui ont un objectif.
     *
     * @return array<int, string>
     */
    private function nomsAgents(\DateTimeImmutable $mois): array
    {
        $ids = $this->em->createQueryBuilder()
            ->select('DISTINCT IDENTITY(c.agents)')
            ->from(Commandes::class, 'c')
            ->andWhere('c.agents IS NOT NULL')
            ->andWhere('c.deleted = false AND c.soldeOuverture = false')
            ->andWhere('c.dateCommande >= :depuis')
            ->setParameter('depuis', $mois->modify('-6 months'))
            ->getQuery()
            ->getSingleColumnResult();
        $ids = array_merge(array_map('intval', $ids), array_map(static fn (User $u): int => (int) $u->getId(), $this->agentsAvecObjectifFiche()));
        foreach ($this->depot->duMois($mois) as $o) {
            if ('agent' === $o->getAxe()) {
                $ids[] = $o->getCible();
            }
        }

        $noms = [];
        foreach ($this->em->getRepository(User::class)->findBy(['id' => array_values(array_unique($ids)), 'actif' => true]) as $u) {
            $noms[(int) $u->getId()] = self::nomAgent($u);
        }
        asort($noms);

        return $noms;
    }

    /** @return list<User> */
    private function agentsAvecObjectifFiche(): array
    {
        return $this->em->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->andWhere('u.actif = true')
            ->andWhere('u.objectifVentesMois > 0')
            ->getQuery()
            ->getResult();
    }

    public static function nomAgent(User $u): string
    {
        $employe = $u->getEmploye();
        $nom = trim(($employe?->getPrenom() ?? '').' '.($employe?->getNom() ?? ''));

        return '' !== $nom ? $nom : (string) $u->getUsername();
    }

    /**
     * @param list<array{cible: int|string|null, valeur: int|string|float|null}> $lignes
     *
     * @return array<int, float>
     */
    private static function parCible(array $lignes): array
    {
        $resultat = [];
        foreach ($lignes as $l) {
            $resultat[(int) $l['cible']] = ($resultat[(int) $l['cible']] ?? 0) + (float) $l['valeur'];
        }

        return $resultat;
    }
}
