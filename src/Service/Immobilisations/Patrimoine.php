<?php

namespace App\Service\Immobilisations;

use App\Entity\DotationAmortissement;
use App\Entity\Immobilisation;
use App\Entity\Machines;
use App\Entity\ReleveCompteur;
use App\Entity\User;
use App\Repository\DotationAmortissementRepository;
use App\Repository\ImmobilisationRepository;
use App\Repository\MachinesRepository;
use App\Service\AmortissementMachine;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Patrimoine et amortissements (maquettes M01 à M04,
 * docs/immobilisations-primes.md) : machines amorties à l'usage, autres
 * biens en linéaire, registre, synthèse par catégorie et par lieu,
 * relevés de compteurs et dotations mensuelles.
 *
 * Les montants (valeur d'achat, cumul, valeur nette) sont réservés à
 * l'admin et à la comptabilité : les contrôleurs filtrent, ce service
 * calcule tout.
 */
final class Patrimoine
{
    /** Mois d'historique chargés pour l'usage des machines (graphique, dotations). */
    private const HISTORIQUE_MOIS = 14;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImmobilisationRepository $immobilisations,
        private readonly DotationAmortissementRepository $dotations,
        private readonly MachinesRepository $machines,
        private readonly UsageMachines $usages,
        private readonly Parametres $parametres,
    ) {
    }

    /** Méthode choisie pour les machines (tuile Machines) : à l'usage ou linéaire. */
    public function methodeMachines(): string
    {
        return (string) ($this->parametres->valeur('machines', 'amortissement') ?? AmortissementMachine::LINEAIRE);
    }

    /**
     * Calcul d'un bien ou d'une machine pour un mois : cumul à la fin du
     * mois, dotation du mois, valeur nette, usage. Un bien sorti garde le
     * cumul de son mois de sortie.
     *
     * @param array{releves: array<string, ReleveCompteur>, production: array<string, float>, compteur: float}|null $suivi
     *
     * @return array{methode: string, valeurAchat: int, base: int, cumul: int, cumulDebut: int, dotation: int, valeurNette: int, usageMois: ?float, usageCumule: ?float, coutUnitaire: ?float}
     */
    public static function calculer(int $valeurAchat, int $base, string $methode, ?float $coutUnitaire, int $dureeMois, ?\DateTimeInterface $miseEnService, ?\DateTimeInterface $sortie, ?array $suivi, \DateTimeImmutable $mois): array
    {
        $mois = CalculAmortissement::premierDuMois($mois);
        $precedent = $mois->modify('-1 month');
        if (null !== $sortie) {
            $moisSortie = CalculAmortissement::premierDuMois($sortie);
            $mois = min($mois, $moisSortie);
            $precedent = min($precedent, $moisSortie);
        }
        $usageMois = null;
        $usageCumule = null;
        if (CalculAmortissement::USAGE === $methode && null !== $suivi && null !== $coutUnitaire) {
            $usageCumule = UsageMachines::cumulFin($suivi, $mois->format('Y-m'));
            $usageDebut = UsageMachines::cumulFin($suivi, $precedent->format('Y-m'));
            $usageMois = $mois == $precedent ? 0.0 : max(0.0, $usageCumule - $usageDebut);
            $cumul = CalculAmortissement::cumulUsage($usageCumule, $coutUnitaire, $base);
            $cumulDebut = CalculAmortissement::cumulUsage($usageDebut, $coutUnitaire, $base);
        } else {
            $methode = CalculAmortissement::LINEAIRE;
            $cumul = CalculAmortissement::cumulLineaire($base, $dureeMois, $miseEnService, $mois);
            $cumulDebut = CalculAmortissement::cumulLineaire($base, $dureeMois, $miseEnService, $precedent);
        }

        return [
            'methode' => $methode,
            'valeurAchat' => $valeurAchat,
            'base' => $base,
            'cumul' => $cumul,
            'cumulDebut' => $cumulDebut,
            'dotation' => CalculAmortissement::dotation($cumul, $cumulDebut),
            'valeurNette' => CalculAmortissement::valeurNette($valeurAchat, $cumul),
            'usageMois' => $usageMois,
            'usageCumule' => $usageCumule,
            'coutUnitaire' => CalculAmortissement::USAGE === $methode ? $coutUnitaire : null,
        ];
    }

    /** Calcul d'une machine seule (M01, M02), qu'elle soit au registre ou non. */
    public function calculMachine(Machines $m, ?array $suivi, \DateTimeImmutable $mois, ?Immobilisation $bien = null): array
    {
        $methode = AmortissementMachine::aLUsage($m, $this->methodeMachines()) ? CalculAmortissement::USAGE : CalculAmortissement::LINEAIRE;
        $achat = (int) ($m->getPrixAchat() ?? $bien?->getValeurAchat() ?? 0);
        $base = (int) ($m->baseAmortissable() ?? 0);
        $duree = $bien?->dureeMois() ?? max(1, (int) ($m->getDureeAmortissementMois() ?: 60));

        return self::calculer($achat, $base, $methode, $m->coutUsageUnitaire(), $duree, $m->getDateMiseService() ?? $m->getDateAchat(), $bien?->getSortieLe(), $suivi, $mois);
    }

    /**
     * Calcul de chaque bien de la liste, par id de bien.
     *
     * @param list<Immobilisation> $biens
     *
     * @return array<int, array<string, mixed>>
     */
    public function calculs(array $biens, \DateTimeImmutable $mois): array
    {
        $machines = array_values(array_filter(array_map(static fn (Immobilisation $i): ?Machines => $i->getMachine(), $biens)));
        $suivi = $this->usages->suivi($machines, CalculAmortissement::premierDuMois($mois)->modify('-'.self::HISTORIQUE_MOIS.' months'));
        $calculs = [];
        foreach ($biens as $i) {
            $m = $i->getMachine();
            $calculs[(int) $i->getId()] = null !== $m
                ? $this->calculMachine($m, $suivi[(int) $m->getId()] ?? null, $mois, $i)
                : self::calculer($i->getValeurAchat(), $i->baseAmortissable(), CalculAmortissement::LINEAIRE, null, $i->dureeMois(), $i->getDateMiseService(), $i->getSortieLe(), null, $mois);
            $calculs[(int) $i->getId()]['libelleMethode'] = CalculAmortissement::libelleMethode($calculs[(int) $i->getId()]['methode'], $m?->uniteUsage() === 'page A4' ? 'pages' : ($m !== null ? self::uniteCourte($m) : null), $i->dureeMois());
        }

        return $calculs;
    }

    /** « m² », « pages », « cartes »… pour la colonne Méthode du registre. */
    public static function uniteCourte(Machines $m): string
    {
        return match ($m->getUnite()) {
            Machines::UNITE_PAGE => 'pages',
            Machines::UNITE_METRE => 'm de film',
            Machines::UNITE_CARTE => 'cartes',
            Machines::UNITE_TRANSFERT => 'transferts',
            default => 'm²',
        };
    }

    /**
     * Registre (M04) : biens et calcul à date. Sans les valeurs, la ligne
     * garde ce qu'un agent peut voir (code, bien, lieu, état).
     *
     * @return list<array{bien: Immobilisation, calcul: array<string, mixed>}>
     */
    public function registre(?string $categorie, bool $avecSortis, ?User $confieA, \DateTimeImmutable $maintenant): array
    {
        $biens = $this->immobilisations->registre($categorie, $avecSortis, $confieA);
        $calculs = $this->calculs($biens, $maintenant);

        return array_map(static fn (Immobilisation $i): array => ['bien' => $i, 'calcul' => $calculs[(int) $i->getId()]], $biens);
    }

    /**
     * Synthèse du patrimoine (M03) : chiffres, catégories, lieux, biens à
     * contrôler.
     *
     * @return array<string, mixed>
     */
    public function synthese(\DateTimeImmutable $maintenant): array
    {
        $lignes = $this->registre(null, false, null, $maintenant);
        $categories = [];
        foreach (Immobilisation::CATEGORIES as $cle => $c) {
            $categories[$cle] = $c + ['cle' => $cle, 'biens' => 0, 'unites' => 0, 'achat' => 0, 'nette' => 0];
        }
        $lieux = [];
        $achat = 0;
        $nette = 0;
        $aControler = [];
        $limite = $maintenant->modify('-'.max(1, (int) ($this->parametres->valeur('patrimoine', 'inventaire_mois') ?? 12)).' months');
        foreach ($lignes as ['bien' => $i, 'calcul' => $c]) {
            $cat = &$categories[$i->getCategorie()];
            ++$cat['biens'];
            $cat['unites'] += $i->getQuantite();
            $cat['achat'] += $c['valeurAchat'];
            $cat['nette'] += $c['valeurNette'];
            unset($cat);
            if (!$i->estIncorporelle()) {
                $achat += $c['valeurAchat'];
                $nette += $c['valeurNette'];
                $lieu = $i->getLieu() ?? 'Non précisé';
                $lieux[$lieu] ??= ['lieu' => $lieu, 'biens' => 0, 'nette' => 0];
                $lieux[$lieu]['biens'] += $i->getQuantite();
                $lieux[$lieu]['nette'] += $c['valeurNette'];
            }
            if (null === $i->getControleLe() || $i->getControleLe() < $limite) {
                $aControler[] = $i;
            }
        }
        usort($lieux, static fn (array $a, array $b): int => $b['nette'] <=> $a['nette']);
        $avecBiens = array_filter($categories, static fn (array $c): bool => $c['biens'] > 0);
        $corporelles = array_filter($avecBiens, static fn (array $c): bool => !\in_array($c['cle'], Immobilisation::INCORPORELLES, true));

        return [
            'biens' => array_sum(array_map(static fn (array $l): int => $l['bien']->getQuantite(), $lignes)),
            'categories' => $categories,
            'nombreCategories' => \count($avecBiens),
            'corporelles' => $corporelles,
            'maxNette' => max(1, ...array_column($corporelles ?: [['nette' => 1]], 'nette')),
            'achat' => $achat,
            'nette' => $nette,
            'pourcentNette' => $achat > 0 ? (int) round($nette * 100 / $achat) : null,
            'lieux' => array_values($lieux),
            'aControler' => $aControler,
            'inventaireMois' => (int) ($this->parametres->valeur('patrimoine', 'inventaire_mois') ?? 12),
            'incorporelles' => array_values(array_filter($lignes, static fn (array $l): bool => $l['bien']->estIncorporelle())),
        ];
    }

    /**
     * Écran Machines et amortissement (M01) : une ligne par machine, avec
     * l'usage et la dotation du mois.
     *
     * @return array<string, mixed>
     */
    public function machines(\DateTimeImmutable $mois, \DateTimeImmutable $maintenant): array
    {
        $mois = CalculAmortissement::premierDuMois($mois);
        $machines = $this->machines->findBy([], ['id' => 'ASC']);
        $suivi = $this->usages->suivi($machines, $mois->modify('-'.self::HISTORIQUE_MOIS.' months'));
        $biens = [];
        foreach ($this->immobilisations->findBy(['categorie' => 'machine']) as $i) {
            if (null !== $i->getMachine()) {
                $biens[(int) $i->getMachine()->getId()] = $i;
            }
        }
        $lignes = [];
        foreach ($machines as $m) {
            $bien = $biens[(int) $m->getId()] ?? null;
            if (null !== $bien && $bien->estSorti() && $bien->getSortieLe() < $mois) {
                continue;
            }
            $s = $suivi[(int) $m->getId()];
            $lignes[] = [
                'machine' => $m,
                'bien' => $bien,
                'calcul' => $this->calculMachine($m, $s, $mois, $bien),
                'usageCumule' => UsageMachines::cumulFin($s, $mois->format('Y-m')),
                'releve' => UsageMachines::suitParReleve($m),
            ];
        }
        // Machines du registre d'abord (par code IMM), puis celles à y ajouter.
        usort($lignes, static fn (array $a, array $b): int => [null === $a['bien'], $a['bien']?->getCode(), $a['machine']->getId()] <=> [null === $b['bien'], $b['bien']?->getCode(), $b['machine']->getId()]);
        $releves = $this->relevesAttendus($machines, $suivi, $maintenant);
        $achat = array_sum(array_map(static fn (array $l): int => $l['calcul']['valeurAchat'], $lignes));
        $nette = array_sum(array_map(static fn (array $l): int => $l['calcul']['valeurNette'], $lignes));

        return [
            'mois' => $mois,
            'lignes' => $lignes,
            'achat' => $achat,
            'dotation' => array_sum(array_map(static fn (array $l): int => $l['calcul']['dotation'], $lignes)),
            'aLUsage' => \count(array_filter($lignes, static fn (array $l): bool => CalculAmortissement::USAGE === $l['calcul']['methode'])),
            'nette' => $nette,
            'pourcentNette' => $achat > 0 ? (int) round($nette * 100 / $achat) : null,
            'releves' => $releves,
        ];
    }

    /**
     * Relevés attendus pour le dernier mois clos : machines suivies par
     * relevé, celles dont le relevé est saisi et celles qui manquent.
     *
     * @param list<Machines> $machines
     * @param array<int, array<string, mixed>> $suivi
     *
     * @return array{mois: \DateTimeImmutable, attendus: int, saisis: int, manquants: list<Machines>}
     */
    public function relevesAttendus(array $machines, array $suivi, \DateTimeImmutable $maintenant): array
    {
        $mois = $maintenant->modify('first day of this month')->modify('-1 month')->setTime(0, 0);
        $attendus = array_values(array_filter($machines, static fn (Machines $m): bool => UsageMachines::suitParReleve($m)));
        $manquants = array_values(array_filter($attendus, static fn (Machines $m): bool => !isset($suivi[(int) $m->getId()]['releves'][$mois->format('Y-m')])));

        return ['mois' => $mois, 'attendus' => \count($attendus), 'saisis' => \count($attendus) - \count($manquants), 'manquants' => $manquants];
    }

    /**
     * Fiche machine (M02) : relevé du mois, pages par mois, valeur et coût
     * complet par page (amortissement + toner + entretien).
     *
     * @return array<string, mixed>
     */
    public function ficheMachine(Machines $m, \DateTimeImmutable $mois, \DateTimeImmutable $maintenant): array
    {
        $mois = CalculAmortissement::premierDuMois($mois);
        $suivi = $this->usages->suivi([$m], $mois->modify('-'.self::HISTORIQUE_MOIS.' months'))[(int) $m->getId()];
        $bien = $this->immobilisations->findOneBy(['machine' => $m]);
        $calcul = $this->calculMachine($m, $suivi, $mois, $bien);

        $parMois = [];
        for ($k = 5; $k >= 0; --$k) {
            $d = $mois->modify(sprintf('-%d months', $k));
            $parMois[] = ['mois' => $d, 'usage' => UsageMachines::duMois($suivi, $d), 'releve' => isset($suivi['releves'][$d->format('Y-m')])];
        }

        // Entretien par unité : interventions des 12 derniers mois ÷ usage de la même période.
        $debut = $mois->modify('-11 months');
        $entretien = 0;
        foreach ($m->getMaintenances() as $intervention) {
            $date = $intervention->getDate();
            if (null !== $date && $date >= $debut && $date < $mois->modify('+1 month')) {
                $entretien += (int) $intervention->getCout();
            }
        }
        // Somme des usages mensuels connus (un mois sans relevé ni production compte pour 0).
        $usageAnnee = 0.0;
        for ($k = 0; $k < 12; ++$k) {
            $usageAnnee += UsageMachines::duMois($suivi, $mois->modify(sprintf('-%d months', $k)));
        }
        $entretienUnite = $usageAnnee > 0 ? $entretien / $usageAnnee : null;
        // En linéaire, la part d'amortissement d'une unité est la dotation du mois ÷ l'usage du mois.
        $usageDuMois = UsageMachines::duMois($suivi, $mois);
        $amort = $calcul['coutUnitaire'] ?? ($usageDuMois > 0 ? $calcul['dotation'] / $usageDuMois : null);

        $couts = [];
        if ($m->releveA4A3() || Machines::UNITE_PAGE === $m->getUnite()) {
            foreach (['A4 couleur' => [1, $m->getCoutPageCouleur()], 'A4 noir et blanc' => [1, $m->getCoutPageNoir()], 'A3 couleur' => [2, $m->getCoutPageCouleur()], 'A3 noir et blanc' => [2, $m->getCoutPageNoir()]] as $format => [$coef, $toner]) {
                $couts[] = self::coutComplet($format, $coef, $amort, $toner, $entretienUnite);
            }
        } else {
            $couts[] = self::coutComplet('Par '.$m->uniteUsage(), 1, $amort, $m->getCoutEncreM2() !== null ? (float) $m->getCoutEncreM2() : null, $entretienUnite);
        }

        return [
            'machine' => $m,
            'bien' => $bien,
            'mois' => $mois,
            'calcul' => $calcul,
            'usageCumule' => UsageMachines::cumulFin($suivi, $mois->format('Y-m')),
            'releve' => $suivi['releves'][$mois->format('Y-m')] ?? null,
            'releves' => $suivi['releves'],
            'pages' => UsageMachines::pagesDuMois($suivi['releves'], $mois),
            'parMois' => $parMois,
            'maxParMois' => max(1.0, ...array_column($parMois, 'usage')),
            'entretien' => $entretien,
            'couts' => $couts,
            'cumulTotal' => $calcul['cumul'],
        ];
    }

    /**
     * Coût complet d'une unité : amortissement + toner (ou encre) +
     * entretien, chaque part multipliée par le coefficient (A3 = 2 A4).
     *
     * @return array{format: string, amort: ?float, toner: ?float, entretien: ?float, total: ?float}
     */
    public static function coutComplet(string $format, int $coefficient, ?float $amort, ?float $toner, ?float $entretien): array
    {
        $parts = ['amort' => $amort, 'toner' => $toner, 'entretien' => $entretien];
        foreach ($parts as $cle => $valeur) {
            $parts[$cle] = null !== $valeur ? round($valeur * $coefficient, 1) : null;
        }
        $connues = array_filter($parts, static fn (?float $v): bool => null !== $v);

        return ['format' => $format] + $parts + ['total' => [] !== $connues ? round(array_sum($connues), 1) : null];
    }

    /**
     * Passe les dotations du mois (commande app:immobilisations:dotations) :
     * une ligne par bien en service non encore doté ce mois-ci. Le montant
     * est le cumul de fin de mois moins le cumul déjà doté (ou celui du mois
     * précédent pour la première dotation), ce qui rattrape un relevé
     * tardif. Une machine suivie par relevé attend son relevé jusqu'au jour
     * limite du mois suivant.
     *
     * @return array{creees: int, montant: int, attente: list<string>}
     */
    public function doter(\DateTimeImmutable $mois, ?User $par, \DateTimeImmutable $maintenant): array
    {
        $mois = CalculAmortissement::premierDuMois($mois);
        if ($mois >= CalculAmortissement::premierDuMois($maintenant)) {
            throw new \DomainException('Seul un mois terminé se dote.');
        }
        $biens = $this->immobilisations->registre(null, true, null);
        $calculs = $this->calculs($biens, $mois);
        $faites = [];
        $dernier = [];
        foreach ($this->dotations->findBy([], ['mois' => 'ASC']) as $d) {
            $id = (int) $d->getImmobilisation()->getId();
            if ($d->getMois() == $mois) {
                $faites[$id] = true;
            } elseif ($d->getMois() < $mois) {
                $dernier[$id] = $d->getCumul();
            }
        }
        $limite = (int) ($this->parametres->valeur('patrimoine', 'releve_jour') ?? 10);
        $attenteAutorisee = $maintenant < $mois->modify('+1 month')->modify(sprintf('+%d days', max(0, $limite - 1)));
        $creees = 0;
        $montant = 0;
        $attente = [];
        foreach ($biens as $i) {
            $id = (int) $i->getId();
            $c = $calculs[$id];
            $mise = $i->getDateMiseService();
            if (isset($faites[$id]) || null === $mise || CalculAmortissement::premierDuMois($mise) > $mois || ($i->estSorti() && CalculAmortissement::premierDuMois($i->getSortieLe()) < $mois)) {
                continue;
            }
            $m = $i->getMachine();
            if (null !== $m && CalculAmortissement::USAGE === $c['methode'] && UsageMachines::suitParReleve($m) && $attenteAutorisee
                && null === $this->em->getRepository(ReleveCompteur::class)->findOneBy(['machine' => $m, 'mois' => $mois])) {
                $attente[] = $i->getCode().' '.$i->getDesignation();
                continue;
            }
            $dotation = CalculAmortissement::dotation($c['cumul'], $dernier[$id] ?? $c['cumulDebut']);
            $this->em->persist(new DotationAmortissement($i, $mois, $dotation, max($c['cumul'], $dernier[$id] ?? 0), $c['methode'], $c['usageMois'], $maintenant, $par));
            ++$creees;
            $montant += $dotation;
        }
        $this->em->flush();

        return ['creees' => $creees, 'montant' => $montant, 'attente' => $attente];
    }

    /**
     * Dotations passées d'une période, avec leur bien.
     *
     * @return list<DotationAmortissement>
     */
    public function dotationsEntre(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->dotations->createQueryBuilder('d')
            ->addSelect('i')->innerJoin('d.immobilisation', 'i')
            ->andWhere('d.mois >= :debut')->andWhere('d.mois <= :fin')
            ->setParameter('debut', CalculAmortissement::premierDuMois($debut))
            ->setParameter('fin', $fin)
            ->orderBy('d.mois', 'ASC')->addOrderBy('i.code', 'ASC')
            ->getQuery()->getResult();
    }
}
