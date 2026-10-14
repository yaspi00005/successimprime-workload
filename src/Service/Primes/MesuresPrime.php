<?php

namespace App\Service\Primes;

use App\Entity\ClotureCaisse;
use App\Entity\Devis;
use App\Entity\Factures;
use App\Entity\JournalMachine;
use App\Entity\OrdreProduction;
use App\Entity\Paiements;
use App\Entity\ReponseSondage;
use App\Entity\User;
use App\Service\ActionsCaisse\ActionsCaisse;
use App\Service\MesStatistiques;
use App\Service\Pointage\Pointages;
use App\Service\Statistiques\Objectifs;
use App\Service\SuiviMetiers\Bareme;
use App\Service\SuiviMetiers\SuiviEquipes;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Mesures du mois d'un agent pour chaque critère de prime, prises dans
 * l'ERP (N02 « calcul auto ») : factures et objectifs, devis, avis
 * clients, factures payées à l'échéance, écarts de caisse et
 * réimpressions, pointage, points du métier, actions de caisse.
 *
 * Une note null veut dire « pas de données ce mois-ci » : CalculPrime
 * répartit alors son poids sur les autres critères.
 */
final class MesuresPrime
{
    /** Métier du barème des points pour le critère « Points du métier ». */
    private const METIERS = [
        'graphiste' => Bareme::GRAPHISTE,
        'production' => Bareme::OPERATEUR,
        'livreur' => Bareme::LIVREUR,
        'entretien' => Bareme::ENTRETIEN,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Objectifs $objectifs,
        private readonly Pointages $pointages,
        private readonly ActionsCaisse $actions,
        private readonly SuiviEquipes $suivi,
    ) {
    }

    /**
     * @param list<array{cle: string, poids: int, objectif: int|float|null}> $criteres
     *
     * @return array<string, array{note: ?int, mesure: string, reste?: int}>
     */
    public function mesurer(User $agent, string $role, array $criteres, \DateTimeImmutable $mois, \DateTimeImmutable $maintenant): array
    {
        $debut = $mois->modify('first day of this month')->setTime(0, 0);
        $fin = min($debut->modify('+1 month'), $maintenant);
        $mesures = [];
        foreach ($criteres as $c) {
            $objectif = $c['objectif'] ?? CalculPrime::CRITERES[$c['cle']]['objectif'];
            $mesures[$c['cle']] = match ($c['cle']) {
                'ventes' => $this->ventes($agent, $debut, $fin, (int) $objectif),
                'transformation' => $this->transformation($agent, $debut, $fin, (float) $objectif),
                'satisfaction' => $this->satisfaction($agent, $debut, $fin),
                'encaissement' => $this->encaissement($agent, $role, $debut, $fin),
                'erreurs' => $this->erreurs($agent, $debut, $fin, (int) $objectif),
                'ponctualite' => $this->ponctualite($agent, $debut, $maintenant),
                'productivite' => $this->productivite($agent, $role, $debut, $maintenant),
                'actions' => $this->actionsCaisse($agent, $debut, $fin),
                default => ['note' => null, 'mesure' => 'saisie du responsable'],
            };
        }

        return $mesures;
    }

    /** Factures émises de l'agent rapportées à son objectif du mois (Statistiques › Objectifs), sinon celui du barème. */
    private function ventes(User $agent, \DateTimeImmutable $debut, \DateTimeImmutable $fin, int $objectifBareme): array
    {
        // L'objectif de chiffre d'affaires fixé à la personne (Statistiques › Objectifs) remplace celui du barème.
        $personnel = $this->objectifs->valeurs($debut)['ca:agent:'.$agent->getId()] ?? null;
        $objectif = $personnel ?? $objectifBareme;
        if ($objectif <= 0) {
            return ['note' => null, 'mesure' => 'pas d\'objectif de ventes'];
        }
        // Un mois sans vente compte 0 pour qui a un objectif de ventes : pour un vendeur, c'est un résultat.
        $realise = max(0, (int) round($this->objectifs->realises('ca', 'agent', $debut, $fin, $agent)[(int) $agent->getId()] ?? 0));
        $note = CalculPrime::noteObjectif($realise, $objectif);

        return [
            'note' => $note,
            'mesure' => sprintf('%s / %s F (%d %%)%s', self::f($realise), self::f($objectif), (int) round($realise * 100 / $objectif), null !== $personnel ? ' · objectif personnel' : ''),
            'reste' => max(0, $objectif - $realise),
        ];
    }

    private function transformation(User $agent, \DateTimeImmutable $debut, \DateTimeImmutable $fin, float $objectif): array
    {
        $statuts = $this->em->createQueryBuilder()
            ->select('d.statut')->from(Devis::class, 'd')
            ->where('d.agents = :agent')->andWhere('d.deleted = false')->andWhere('d.statut IN (:emis)')
            ->andWhere('d.dateDevis >= :debut AND d.dateDevis < :fin')
            ->setParameter('agent', $agent)->setParameter('emis', MesStatistiques::DEVIS_EMIS)
            ->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->getQuery()->getSingleColumnResult();
        if ([] === $statuts) {
            return ['note' => null, 'mesure' => 'aucun devis envoyé'];
        }
        $taux = (int) round(\count(array_intersect($statuts, MesStatistiques::DEVIS_GAGNES)) * 100 / \count($statuts));

        return ['note' => CalculPrime::noteObjectif($taux, $objectif), 'mesure' => sprintf('%d %% (objectif %s %%) sur %d devis', $taux, self::n($objectif), \count($statuts))];
    }

    /** Avis du mois sur les commandes de l'agent (ou qu'il a livrées / produites). */
    private function satisfaction(User $agent, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $notes = $this->em->createQueryBuilder()
            ->select('r.noteGlobale')->from(ReponseSondage::class, 'r')
            ->innerJoin('r.commande', 'c')
            ->where('c.agents = :agent')->andWhere('r.noteGlobale IS NOT NULL')
            ->andWhere('r.creeLe >= :debut AND r.creeLe < :fin')
            ->setParameter('agent', $agent)->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->getQuery()->getSingleColumnResult();
        if ([] === $notes) {
            return ['note' => null, 'mesure' => 'aucun avis client'];
        }
        $moyenne = array_sum(array_map('intval', $notes)) / \count($notes);

        return ['note' => CalculPrime::noteSatisfaction($moyenne), 'mesure' => sprintf('%s / 5 sur %d avis', self::n(round($moyenne, 1)), \count($notes))];
    }

    /**
     * Factures dont l'échéance tombe dans le mois (et est passée) : part
     * payée en entier à l'échéance. Celles de l'agent ; toutes pour le
     * rôle comptable, qui suit les relances.
     */
    private function encaissement(User $agent, string $role, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('f.id', 'f.totalTtc', 'f.dateEcheance', 'IDENTITY(f.commande) AS commande')
            ->from(Factures::class, 'f')->leftJoin('f.commande', 'c')
            ->andWhere('f.etat = :emise')->andWhere('f.typeDocument = :facture')
            ->andWhere('f.dateEcheance >= :debut AND f.dateEcheance < :fin')
            ->setParameter('emise', Factures::ETAT_EMISE)->setParameter('facture', Factures::TYPE_FACTURE)
            ->setParameter('debut', $debut)->setParameter('fin', $fin);
        if ('comptable' !== $role) {
            $qb->andWhere('c.agents = :agent')->setParameter('agent', $agent);
        }
        $factures = $qb->getQuery()->getArrayResult();
        if ([] === $factures) {
            return ['note' => null, 'mesure' => 'aucune facture à échéance'];
        }
        $aTemps = 0;
        foreach ($factures as $f) {
            $paye = (int) $this->em->createQueryBuilder()
                ->select('COALESCE(SUM(p.montant), 0)')->from(Paiements::class, 'p')
                ->where('p.commande = :c')->andWhere('p.statut = :valide')->andWhere('p.date < :limite')
                ->setParameter('c', $f['commande'])->setParameter('valide', Paiements::STATUT_VALIDE)
                ->setParameter('limite', $f['dateEcheance']->modify('+1 day')->setTime(0, 0))
                ->getQuery()->getSingleScalarResult();
            $aTemps += $paye >= (int) $f['totalTtc'] ? 1 : 0;
        }
        $taux = (int) round($aTemps * 100 / \count($factures));

        return ['note' => $taux, 'mesure' => sprintf('%d %% des factures payées à temps (%d / %d)', $taux, $aTemps, \count($factures))];
    }

    /** Écarts de caisse à ses clôtures et réimpressions dues à l'agent. */
    private function erreurs(User $agent, \DateTimeImmutable $debut, \DateTimeImmutable $fin, int $tolerees): array
    {
        /** @var list<ClotureCaisse> $clotures */
        $clotures = $this->em->getRepository(ClotureCaisse::class)->createQueryBuilder('k')
            ->where('k.cloturePar = :a')->andWhere('k.clotureLe >= :debut AND k.clotureLe < :fin')
            ->setParameter('a', $agent)->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->getQuery()->getResult();
        $reimpressions = (int) $this->em->createQueryBuilder()
            ->select('COUNT(j.id)')->from(JournalMachine::class, 'j')
            ->where('j.operateur = :a')->andWhere('j.type = :r')->andWhere('j.le >= :debut AND j.le < :fin')
            ->setParameter('a', $agent)->setParameter('r', JournalMachine::REIMPRESSION)
            ->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->getQuery()->getSingleScalarResult();
        $ordres = (int) $this->em->createQueryBuilder()
            ->select('COUNT(o.id)')->from(OrdreProduction::class, 'o')
            ->where('o.operateur = :a')->andWhere('o.termineLe >= :debut AND o.termineLe < :fin')
            ->setParameter('a', $agent)->setParameter('debut', $debut)->setParameter('fin', $fin)
            ->getQuery()->getSingleScalarResult();
        if ([] === $clotures && 0 === $reimpressions && 0 === $ordres) {
            return ['note' => null, 'mesure' => 'ni clôture de caisse ni production'];
        }
        $ecarts = array_filter($clotures, static fn (ClotureCaisse $k): bool => 0 !== $k->getEcart());
        $montant = array_sum(array_map(static fn (ClotureCaisse $k): int => abs($k->getEcart()), $ecarts));
        $parties = [];
        if ([] !== $clotures) {
            $parties[] = self::f($montant).' F d\'écart'.(\count($ecarts) > 1 ? ' ('.\count($ecarts).' clôtures)' : '');
        }
        $parties[] = $reimpressions.' réimpression'.($reimpressions > 1 ? 's' : '');

        return ['note' => CalculPrime::noteErreurs(\count($ecarts) + $reimpressions, $tolerees), 'mesure' => implode(' · ', $parties)];
    }

    private function ponctualite(User $agent, \DateTimeImmutable $debut, \DateTimeImmutable $maintenant): array
    {
        $fiche = $this->pointages->mois($agent, $debut, $maintenant);
        if ($fiche['presents'] <= 0 || null === $fiche['note']) {
            return ['note' => null, 'mesure' => 'pas de pointage ce mois-ci'];
        }

        return [
            'note' => max(0, min(100, (int) $fiche['note'])),
            'mesure' => sprintf('%d / %d jours · %d retard%s', $fiche['presents'], max($fiche['ouvres'], $fiche['presents']), $fiche['retards'], $fiche['retards'] > 1 ? 's' : ''),
        ];
    }

    private function productivite(User $agent, string $role, \DateTimeImmutable $debut, \DateTimeImmutable $maintenant): array
    {
        $metier = self::METIERS[$role] ?? SuiviEquipes::metier($agent);
        if (null === $metier) {
            return ['note' => null, 'mesure' => 'métier sans barème de points'];
        }
        $points = $this->suivi->pointsDuMois($agent, $metier, $debut, $maintenant);
        if ($points['possibles'] <= 0) {
            return ['note' => null, 'mesure' => 'aucun travail compté'];
        }

        return ['note' => Bareme::note($points['gagnes'], $points['possibles']), 'mesure' => sprintf('%s / %s points', self::f($points['gagnes']), self::f($points['possibles']))];
    }

    private function actionsCaisse(User $agent, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $bilan = $this->actions->bilan($agent, $debut, $fin);
        if (null === $bilan['pourcentage']) {
            return ['note' => null, 'mesure' => 'aucune action assignée'];
        }

        return ['note' => $bilan['pourcentage'], 'mesure' => sprintf('%d / %d à l\'heure', $bilan['aLHeure'], $bilan['lignes'])];
    }

    private static function f(int|float $montant): string
    {
        return number_format((float) $montant, 0, ',', ' ');
    }

    private static function n(float $nombre): string
    {
        return rtrim(rtrim(number_format($nombre, 1, ',', ' '), '0'), ',');
    }
}
