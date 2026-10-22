<?php

namespace App\Service\Finances;

use App\Entity\Achats;
use App\Entity\BulletinPaie;
use App\Entity\DecaissementRecurrent;
use App\Entity\Depense;
use App\Repository\BulletinPaieRepository;
use App\Repository\TacheRepository;
use App\Service\EspaceEmploye\Paie;
use App\Service\Pointage\Pointages;
use App\Service\TableauTresorerie;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ce que l'entreprise doit payer (maquette F04), réutilisé par la
 * prévision de trésorerie (F05) :
 * - les dépenses « à payer » de Finances › Dépenses (dettes fournisseurs),
 *   à leur échéance, ou tout de suite sans échéance ;
 * - les charges récurrentes (loyer, électricité, abonnements…) ;
 * - les tâches du planning qui ont un montant (impôts, achats prévus) et
 *   les échéances fiscales sans montant (« selon déclaration »). Une tâche
 *   du même montant qu'une charge récurrente à 7 jours près est le même
 *   paiement : elle n'est pas comptée deux fois ;
 * - les salaires nets du mois, au jour de paie des Réglages : bulletins
 *   pas encore payés, sinon les salaires des fiches (estimation).
 */
final class Echeancier
{
    /** Type d'échéance => libellé. */
    public const TYPES = [
        'fournisseur' => 'Fournisseur',
        'charge' => 'Charge récurrente',
        'fiscal' => 'Impôts et taxes',
        'planning' => 'Planning',
        'salaires' => 'Salaires',
    ];

    /** Écart toléré entre une tâche du planning et la charge récurrente qu'elle répète. */
    public const JOURS_DOUBLON = 7;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TacheRepository $taches,
        private readonly BulletinPaieRepository $bulletins,
        private readonly Paie $paie,
        private readonly Pointages $pointages,
    ) {
    }

    /**
     * Échéances entre aujourd'hui et l'horizon, dans l'ordre des dates.
     *
     * @return list<array{date: \DateTimeImmutable, titre: string, detail: string, montant: ?int, type: string, en_retard: bool, route: ?string, parametres: array<string, int>}>
     */
    public function sorties(\DateTimeImmutable $aujourdhui, \DateTimeImmutable $jusqua): array
    {
        $aujourdhui = $aujourdhui->setTime(0, 0);
        $sorties = [];

        foreach ($this->depensesAPayer() as $d) {
            $echeance = $d->getEcheance();
            $date = null === $echeance || $echeance < $aujourdhui ? $aujourdhui : $echeance;
            if ($date > $jusqua) {
                continue;
            }
            $enRetard = null !== $echeance && $echeance < $aujourdhui;
            $sorties[] = self::ligne($date, '' !== $d->getPayeA() ? $d->getPayeA() : $d->getObjet(), $enRetard
                ? \sprintf('%s · en retard depuis le %s', $d->getObjet(), $echeance->format('d/m'))
                : $d->getObjet().(null === $echeance ? ' · sans échéance' : ''), $d->getMontant(), 'fournisseur', $enRetard, 'app_finances_depenses_voir', ['id' => (int) $d->getId()]);
        }

        $charges = [];
        foreach ($this->em->getRepository(DecaissementRecurrent::class)->findBy(['actif' => true]) as $r) {
            foreach (TableauTresorerie::echeancesRecurrentes($r->getProchaineDateExecution(), $r->getFrequence(), $aujourdhui, $jusqua) as $date) {
                $charges[] = self::ligne($date, $r->getLibelle(), DecaissementRecurrent::FREQUENCE_ANNUELLE === $r->getFrequence() ? 'charge annuelle' : 'charge mensuelle', $r->getMontant(), 'charge');
            }
        }
        $sorties = [...$sorties, ...$charges];

        foreach ($this->taches->entre($aujourdhui, $jusqua) as $t) {
            $montant = $t->getMontantEstime();
            $fiscal = 'fiscal' === $t->getCategorie();
            if ($t->estFaite() || null !== $t->getMouvement() || 'paie' === $t->getCategorie() || ((null === $montant || $montant <= 0) && !$fiscal)) {
                continue;
            }
            if (null !== $montant && $montant > 0 && self::doublon($montant, $t->getPrevueLe(), $charges)) {
                continue;
            }
            $sorties[] = self::ligne($t->getPrevueLe(), $t->getTitre(), $fiscal ? 'impôts et taxes' : 'planning', null !== $montant && $montant > 0 ? $montant : null, $fiscal ? 'fiscal' : 'planning', false, 'app_planning_tache', ['id' => (int) $t->getId()]);
        }

        // Salaires du mois M versés en M+1 : on part du mois dernier
        // jusqu'au dernier versement avant l'horizon (90 jours pour F05).
        $m = $aujourdhui->modify('first day of this month')->modify('-1 month');
        for ($i = 0; $i < 6; ++$i, $m = $m->modify('+1 month')) {
            $date = $this->paie->dateVersement($m);
            if ($date > $jusqua) {
                break;
            }
            if ($date < $aujourdhui) {
                continue;
            }
            $salaires = $this->salairesNets($m);
            if ($salaires['montant'] > 0) {
                $sorties[] = self::ligne($date, \sprintf('Salaires %s (net)', Periode::deMois($m)), $salaires['estime'] ? 'estimés sur les fiches du personnel' : \sprintf('%d bulletin%s à payer', $salaires['nombre'], $salaires['nombre'] > 1 ? 's' : ''), $salaires['montant'], 'salaires', false, 'app_paie_index');
            }
        }

        usort($sorties, static fn (array $a, array $b): int => [$a['date'], array_search($a['type'], array_keys(self::TYPES), true)] <=> [$b['date'], array_search($b['type'], array_keys(self::TYPES), true)]);

        return $sorties;
    }

    /**
     * Regroupe les échéances d'un même jour : « paiement groupé ».
     *
     * @param list<array{date: \DateTimeImmutable, titre: string, montant: ?int, en_retard: bool}> $sorties
     *
     * @return list<array{date: \DateTimeImmutable, lignes: list<array<string, mixed>>, total: int, selon_declaration: bool, en_retard: bool}>
     */
    public static function parJour(array $sorties): array
    {
        $jours = [];
        foreach ($sorties as $s) {
            $cle = $s['date']->format('Y-m-d');
            $jours[$cle] ??= ['date' => $s['date'], 'lignes' => [], 'total' => 0, 'selon_declaration' => false, 'en_retard' => false];
            $jours[$cle]['lignes'][] = $s;
            $jours[$cle]['total'] += (int) $s['montant'];
            $jours[$cle]['selon_declaration'] = $jours[$cle]['selon_declaration'] || null === $s['montant'];
            $jours[$cle]['en_retard'] = $jours[$cle]['en_retard'] || $s['en_retard'];
        }

        return array_values($jours);
    }

    /**
     * Le jour qui pèse le plus parmi ceux qui regroupent plusieurs
     * paiements ; à défaut, la prochaine échéance.
     *
     * @param list<array{date: \DateTimeImmutable, lignes: list<array<string, mixed>>, total: int}> $jours
     *
     * @return array{date: \DateTimeImmutable, lignes: list<array<string, mixed>>, total: int}|null
     */
    public static function grosJour(array $jours): ?array
    {
        $groupes = array_values(array_filter($jours, static fn (array $j): bool => \count($j['lignes']) > 1));
        if ([] === $groupes) {
            return $jours[0] ?? null;
        }
        usort($groupes, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return $groupes[0];
    }

    /**
     * Une tâche du planning répète-t-elle une charge récurrente (même
     * montant, à 7 jours près) ?
     *
     * @param list<array{date: \DateTimeImmutable, montant: ?int}> $charges
     */
    public static function doublon(int $montant, \DateTimeImmutable $date, array $charges): bool
    {
        foreach ($charges as $c) {
            if ($c['montant'] === $montant && abs((int) $c['date']->diff($date)->format('%r%a')) <= self::JOURS_DOUBLON) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dettes fournisseurs : dépenses à payer (et à approuver) regroupées
     * par fournisseur ou bénéficiaire, la plus grosse dette d'abord.
     *
     * @return list<array{nom: string, achats: list<string>, du: int, a_approuver: int, echeance: ?\DateTimeImmutable, en_retard: bool, depenses: list<Depense>, bloque: int}>
     */
    public function dettes(\DateTimeImmutable $aujourdhui): array
    {
        $aujourdhui = $aujourdhui->setTime(0, 0);
        $dettes = [];
        foreach ([...$this->depensesAPayer(), ...$this->depensesParStatut(Depense::A_APPROUVER)] as $d) {
            $cle = null !== $d->getFournisseur() ? 'f'.$d->getFournisseur()->getId() : ('' !== $d->getPayeA() ? 'b'.mb_strtolower($d->getPayeA()) : 'd'.$d->getId());
            $dettes[$cle] ??= ['nom' => '' !== $d->getPayeA() ? $d->getPayeA() : $d->getObjet(), 'achats' => [], 'du' => 0, 'a_approuver' => 0, 'echeance' => null, 'en_retard' => false, 'depenses' => [], 'bloque' => 0];
            $dettes[$cle]['achats'][] = $d->getObjet();
            // Solde d'une commande d'achat bloqué à la réception (réclamation ouverte, docs/achats.md SU05).
            $dettes[$cle]['bloque'] += true === $d->getAchat()?->isPaiementBloque() ? $d->getMontant() : 0;
            $dettes[$cle]['depenses'][] = $d;
            if (Depense::A_APPROUVER === $d->getStatut()) {
                $dettes[$cle]['a_approuver'] += $d->getMontant();
                continue;
            }
            $dettes[$cle]['du'] += $d->getMontant();
            $echeance = $d->getEcheance();
            if (null === $echeance) {
                continue;
            }
            if (null === $dettes[$cle]['echeance'] || $echeance < $dettes[$cle]['echeance']) {
                $dettes[$cle]['echeance'] = $echeance;
            }
            $dettes[$cle]['en_retard'] = $dettes[$cle]['en_retard'] || $echeance < $aujourdhui;
        }
        $dettes = array_values($dettes);
        usort($dettes, static fn (array $a, array $b): int => [$b['du'], $b['a_approuver']] <=> [$a['du'], $a['a_approuver']]);

        return $dettes;
    }

    /**
     * Achats reçus en stock dans le mois (bons de commande).
     *
     * @return array{montant: int, nombre: int}
     */
    public function achatsRecus(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $r = $this->em->createQueryBuilder()
            ->select('COUNT(a.id) AS nombre', 'COALESCE(SUM(a.totalTtc), 0) AS montant')
            ->from(Achats::class, 'a')
            ->andWhere('a.statut = :recu')
            ->andWhere('a.dateReception >= :debut AND a.dateReception < :fin')
            ->setParameter('recu', Achats::STATUT_RECU)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getSingleResult();

        return ['montant' => (int) $r['montant'], 'nombre' => (int) $r['nombre']];
    }

    /**
     * Salaires nets d'un mois encore à verser.
     *
     * @return array{montant: int, nombre: int, estime: bool}
     */
    public function salairesNets(\DateTimeImmutable $mois): array
    {
        $bulletins = $this->bulletins->duMois($mois);
        if ([] !== $bulletins) {
            $aPayer = array_values(array_filter($bulletins, static fn (BulletinPaie $b): bool => BulletinPaie::PAYE !== $b->getEtat()));

            return ['montant' => array_sum(array_map(static fn (BulletinPaie $b): int => $b->net(), $aPayer)), 'nombre' => \count($aPayer), 'estime' => false];
        }
        $salaires = array_map(static fn ($u): int => (int) $u->getEmploye()?->getSalaires(), $this->pointages->personnel());
        $salaires = array_filter($salaires, static fn (int $s): bool => $s > 0);

        return ['montant' => array_sum($salaires), 'nombre' => \count($salaires), 'estime' => true];
    }

    /** @return list<Depense> */
    private function depensesAPayer(): array
    {
        return $this->depensesParStatut(Depense::A_PAYER);
    }

    /** @return list<Depense> */
    private function depensesParStatut(string $statut): array
    {
        return $this->em->createQueryBuilder()
            ->select('d', 'f')
            ->from(Depense::class, 'd')
            ->leftJoin('d.fournisseur', 'f')
            ->andWhere('d.statut = :statut')
            ->setParameter('statut', $statut)
            ->orderBy('d.echeance', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array<string, int> $parametres
     *
     * @return array{date: \DateTimeImmutable, titre: string, detail: string, montant: ?int, type: string, en_retard: bool, route: ?string, parametres: array<string, int>}
     */
    private static function ligne(\DateTimeImmutable $date, string $titre, string $detail, ?int $montant, string $type, bool $enRetard = false, ?string $route = null, array $parametres = []): array
    {
        return ['date' => $date->setTime(0, 0), 'titre' => $titre, 'detail' => $detail, 'montant' => $montant, 'type' => $type, 'en_retard' => $enRetard, 'route' => $route, 'parametres' => $parametres];
    }
}
