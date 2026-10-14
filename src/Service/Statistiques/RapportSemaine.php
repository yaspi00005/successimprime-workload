<?php

namespace App\Service\Statistiques;

use App\Entity\CategorieProduit;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Devis;
use App\Entity\User;
use App\Service\TableauDeBordService;
use App\Service\VentesService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rapport de la semaine (lundi → dimanche), version de chaque personne
 * selon ses droits (docs/statistiques.md) :
 *  - « Entreprise » avec les chiffres globaux (ROLE_STATS_GLOBAL) :
 *    commandes, m², nouveaux clients, devis signés, commandes en retard ;
 *    les montants (CA face à l'objectif, catégories, agents) avec les
 *    indicateurs commerciaux ; l'encaissé, le taux d'encaissement et les
 *    créances avec le droit Paiements ;
 *  - « Mes chiffres » avec ROLE_STATS_PERSO : ses ventes facturées face à
 *    son objectif, ses commandes, devis, encaissements et travaux.
 * Ce qu'une section ne montre pas n'est pas calculé. Les alertes suivent
 * les mêmes droits.
 */
final class RapportSemaine
{
    /** Devis émis (envoyés au client, quelle que soit la suite). */
    private const DEVIS_EMIS = [Devis::STATUT_ENVOYE, Devis::STATUT_ACCEPTE, Devis::STATUT_REFUSE, Devis::STATUT_EXPIRE, Devis::STATUT_CONVERTI];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Objectifs $objectifs,
        private readonly TableauDeBordService $tableauDeBord,
    ) {
    }

    /**
     * Sections que les droits ouvrent.
     *
     * @param array<string, bool> $droits DroitsStatistiques
     *
     * @return array{entreprise: bool, montants: bool, encaissement: bool, perso: bool}
     */
    public static function sections(array $droits): array
    {
        $global = $droits['global'] ?? false;

        return [
            'entreprise' => $global,
            'montants' => $global && ($droits['ventes'] ?? false),
            'encaissement' => $global && ($droits['paiements'] ?? false),
            'perso' => $droits['perso'] ?? false,
        ];
    }

    /**
     * Le rapport de la personne pour la semaine. « perso » reste null quand
     * elle n'a ni activité ni objectif personnel.
     *
     * @param array<string, bool> $droits
     *
     * @return array{semaine: SemaineIso, sections: array<string, bool>, entreprise: ?array<string, mixed>, perso: ?array<string, mixed>, alertes: list<array{niveau: string, texte: string, route: ?string}>}
     */
    public function donnees(User $pour, array $droits, SemaineIso $semaine, \DateTimeImmutable $maintenant): array
    {
        $sections = self::sections($droits);
        $entreprise = $sections['entreprise'] ? $this->entreprise($semaine, $sections, $maintenant) : null;
        $perso = $sections['perso'] ? $this->perso($pour, $semaine, $maintenant) : null;
        if (null !== $perso && !self::aActivite($perso)) {
            $perso = null;
        }

        return [
            'semaine' => $semaine,
            'sections' => $sections,
            'entreprise' => $entreprise,
            'perso' => $perso,
            'alertes' => self::alertes($entreprise, $perso, $sections),
        ];
    }

    /**
     * La personne reçoit-elle le rapport ? Direction (chiffres globaux) si
     * l'envoi à la direction est coché ; agent si l'envoi aux agents est
     * coché et qu'il a eu une activité ou a un objectif.
     *
     * @param array<string, bool> $droits
     */
    public function estDestinataire(User $u, array $droits, SemaineIso $semaine, bool $pourDirection, bool $pourAgents, \DateTimeImmutable $maintenant): bool
    {
        $sections = self::sections($droits);
        if ($sections['entreprise'] && $pourDirection) {
            return true;
        }

        return $sections['perso'] && $pourAgents && self::aActivite($this->perso($u, $semaine, $maintenant));
    }

    /** @param array<string, mixed> $perso */
    public static function aActivite(array $perso): bool
    {
        return $perso['ca'] > 0 || $perso['commandes'] > 0 || $perso['devis_emis'] > 0 || $perso['encaisse'] > 0
            || $perso['travaux'] > 0 || null !== $perso['objectif_semaine'];
    }

    /**
     * Alertes du rapport, chacune seulement avec le droit de voir son chiffre.
     *
     * @param array<string, mixed>|null $e
     * @param array<string, mixed>|null $p
     * @param array<string, bool>       $sections
     *
     * @return list<array{niveau: string, texte: string, route: ?string}>
     */
    public static function alertes(?array $e, ?array $p, array $sections): array
    {
        $alertes = [];
        $pluriel = static fn (int $n, string $mot): string => $n.' '.$mot.($n > 1 && !str_ends_with($mot, 's') ? 's' : '');

        if (null !== $e && $sections['entreprise']) {
            if ($e['en_retard'] > 0) {
                $alertes[] = ['niveau' => 'danger', 'texte' => $pluriel($e['en_retard'], 'commande').' en retard (date promise dépassée)', 'route' => 'app_ventes_index'];
            }
            if ($sections['montants'] && null !== $e['objectif_semaine'] && $e['ca'] < $e['objectif_semaine'] * 0.9) {
                $alertes[] = ['niveau' => 'warning', 'texte' => \sprintf('CA facturé de la semaine à %d %% de l’objectif de la semaine', (int) round($e['ca'] * 100 / $e['objectif_semaine'])), 'route' => 'app_statistiques_objectifs'];
            }
            if ($sections['montants'] && \in_array($e['mois']['etat'] ?? null, ['en_retard', 'manque'], true)) {
                $alertes[] = ['niveau' => 'danger', 'texte' => \sprintf('Au rythme actuel, le CA du mois finirait à %d %% de l’objectif', (int) $e['mois']['part_projetee']), 'route' => 'app_statistiques_objectifs'];
            }
            if ($sections['encaissement'] && null !== $e['taux_encaissement'] && $e['taux_encaissement'] < 80) {
                $alertes[] = ['niveau' => 'warning', 'texte' => \sprintf('Taux d’encaissement de la semaine à %d %% (objectif 80 %%)', (int) round($e['taux_encaissement'])), 'route' => 'app_finances_encaissements'];
            }
            if ($sections['encaissement'] && $sections['montants'] && $e['ca_30_jours'] > 0 && $e['creances'] > $e['ca_30_jours'] * 0.2) {
                $alertes[] = ['niveau' => 'warning', 'texte' => 'Créances au-dessus de 20 % du CA des 30 derniers jours', 'route' => 'app_creances_index'];
            }
            if ($sections['encaissement'] && $e['factures_echues'] > 0) {
                $alertes[] = ['niveau' => 'danger', 'texte' => $pluriel($e['factures_echues'], 'facture').' échue'.($e['factures_echues'] > 1 ? 's' : '').' à relancer', 'route' => 'app_creances_index'];
            }
            if ($e['devis_a_relancer'] > 0) {
                $alertes[] = ['niveau' => 'warning', 'texte' => $pluriel($e['devis_a_relancer'], 'devis').' envoyé'.($e['devis_a_relancer'] > 1 ? 's' : '').' sans réponse depuis 3 jours', 'route' => 'app_ventes_index'];
            }
        }

        if (null !== $p && $sections['perso']) {
            if (null !== $p['objectif_semaine'] && $p['ca'] < $p['objectif_semaine'] * 0.9) {
                $alertes[] = ['niveau' => 'warning', 'texte' => \sprintf('Vos ventes facturées à %d %% de votre objectif de la semaine', (int) round($p['ca'] * 100 / $p['objectif_semaine'])), 'route' => 'app_mes_statistiques'];
            }
            if ($p['devis_a_relancer'] > 0) {
                $alertes[] = ['niveau' => 'warning', 'texte' => 'Vous avez '.$pluriel($p['devis_a_relancer'], 'devis').' à relancer', 'route' => 'app_ventes_index'];
            }
        }

        return $alertes;
    }

    /**
     * @param array{entreprise: bool, montants: bool, encaissement: bool, perso: bool} $sections
     *
     * @return array<string, mixed>
     */
    private function entreprise(SemaineIso $semaine, array $sections, \DateTimeImmutable $maintenant): array
    {
        $debut = $semaine->debut;
        $fin = $semaine->fin();
        $avant = $semaine->avant();
        $commandes = $this->objectifs->realises('commandes', 'global', $debut, $fin)[0] ?? 0;
        $surface = $this->objectifs->realises('surface', 'global', $debut, $fin)[0] ?? 0;
        $devis = $this->devis($debut, $fin, null);

        $e = [
            'commandes' => (int) $commandes,
            'commandes_avant' => (int) ($this->objectifs->realises('commandes', 'global', $avant->debut, $avant->fin())[0] ?? 0),
            'surface' => $surface,
            'surface_avant' => $this->objectifs->realises('surface', 'global', $avant->debut, $avant->fin())[0] ?? 0,
            'nouveaux_clients' => $this->objectifs->nouveauxClients($debut, $fin),
            'devis_emis' => $devis['emis'],
            'devis_signes' => $devis['signes'],
            'en_retard' => $this->commandesEnRetard(min($fin, $maintenant)),
            'devis_a_relancer' => $this->devisARelancer(null, min($fin, $maintenant)),
            'agents' => [],
            'ca' => null,
            'categories' => [],
            'mois' => null,
            'objectif_semaine' => null,
            'encaisse' => null,
            'taux_encaissement' => null,
            'creances' => null,
            'factures_echues' => 0,
            'ca_30_jours' => 0,
        ];

        // Par agent : commandes (chiffres globaux), et CA avec les montants.
        $parAgentCommandes = $this->objectifs->realises('commandes', 'agent', $debut, $fin);
        $parAgentCa = $sections['montants'] ? $this->objectifs->realises('ca', 'agent', $debut, $fin) : [];
        $ids = array_unique(array_merge(array_keys($parAgentCommandes), array_keys($parAgentCa)));
        $noms = $this->nomsUtilisateurs($ids);
        foreach ($ids as $id) {
            $e['agents'][] = ['nom' => $noms[$id] ?? 'Agent #'.$id, 'commandes' => (int) ($parAgentCommandes[$id] ?? 0), 'ca' => $sections['montants'] ? (int) ($parAgentCa[$id] ?? 0) : null];
        }
        usort($e['agents'], static fn (array $a, array $b): int => [$b['ca'], $b['commandes']] <=> [$a['ca'], $a['commandes']]);

        if ($sections['montants']) {
            $e['ca'] = (int) ($this->objectifs->realises('ca', 'global', $debut, $fin)[0] ?? 0);
            $e['ca_avant'] = (int) ($this->objectifs->realises('ca', 'global', $avant->debut, $avant->fin())[0] ?? 0);
            $e['objectif_semaine'] = Objectifs::prorata($this->objectifs->parMois('ca:global:0', $debut, $fin), $debut, $fin);
            $e['mois'] = $this->moisAuDimanche($semaine, 'ca:global:0', null);
            $e['ca_30_jours'] = (int) ($this->objectifs->realises('ca', 'global', $fin->modify('-30 days'), $fin)[0] ?? 0);

            $categories = $this->objectifs->realises('ca', 'categorie', $debut, $fin);
            arsort($categories);
            $nomsCategories = $this->nomsCategories(array_keys($categories));
            $total = max(1, array_sum($categories));
            foreach (\array_slice($categories, 0, 5, true) as $id => $montant) {
                $e['categories'][] = ['nom' => $nomsCategories[$id] ?? 'Sans catégorie', 'montant' => (int) $montant, 'part' => (int) round($montant * 100 / $total)];
            }
        }

        if ($sections['encaissement']) {
            $e['encaisse'] = $this->objectifs->encaisse($debut, $fin);
            $e['taux_encaissement'] = $this->objectifs->tauxEncaissement($debut, $fin);
            $facture = (int) ($this->objectifs->realises('ca', 'global', $debut, $fin)[0] ?? 0);
            if (0 === $facture) {
                $e['taux_encaissement'] = null;
            }
            $creances = $this->tableauDeBord->creances(min($fin, $maintenant));
            $e['creances'] = $creances['total'];
            $e['factures_echues'] = $creances['facturesEchues'];
        }

        return $e;
    }

    /**
     * @return array<string, mixed>
     */
    private function perso(User $u, SemaineIso $semaine, \DateTimeImmutable $maintenant): array
    {
        $debut = $semaine->debut;
        $fin = $semaine->fin();
        $avant = $semaine->avant();
        $cle = 'ca:agent:'.$u->getId();
        $devis = $this->devis($debut, $fin, $u);
        $travaux = $this->travaux($u, $debut, $fin);
        $objectifSemaine = Objectifs::prorata($this->objectifs->parMois($cle, $debut, $fin), $debut, $fin);

        return [
            'ca' => (int) ($this->objectifs->realises('ca', 'agent', $debut, $fin, $u)[$u->getId()] ?? 0),
            'ca_avant' => (int) ($this->objectifs->realises('ca', 'agent', $avant->debut, $avant->fin(), $u)[$u->getId()] ?? 0),
            'objectif_semaine' => $objectifSemaine,
            'mois' => null !== $objectifSemaine || null !== ($this->objectifs->valeurs($semaine->dimanche())[$cle] ?? null) ? $this->moisAuDimanche($semaine, $cle, $u) : null,
            'commandes' => (int) ($this->objectifs->realises('commandes', 'agent', $debut, $fin, $u)[$u->getId()] ?? 0),
            'devis_emis' => $devis['emis'],
            'devis_signes' => $devis['signes'],
            'encaisse' => $this->objectifs->encaisse($debut, $fin, $u),
            'devis_a_relancer' => $this->devisARelancer($u, min($fin, $maintenant)),
            'travaux' => $travaux['nombre'],
            'surface' => $travaux['surface'],
        ];
    }

    /**
     * Avancement du mois du dimanche de la semaine, arrêté à la fin de la
     * semaine : réalisé depuis le 1er, objectif du mois, projection.
     *
     * @return array<string, mixed>
     */
    private function moisAuDimanche(SemaineIso $semaine, string $cle, ?User $agent): array
    {
        $dimanche = $semaine->dimanche();
        $debutMois = $dimanche->modify('first day of this month')->setTime(0, 0);
        $finMois = $debutMois->modify('+1 month');
        $arret = $semaine->fin();
        [$indicateur, $axe] = explode(':', $cle);
        $realises = $this->objectifs->realises($indicateur, $axe, $debutMois, $arret, $agent);
        $realise = (float) ($realises[null !== $agent ? $agent->getId() : 0] ?? 0);

        return Objectifs::avancement($realise, $this->objectifs->valeurs($debutMois)[$cle] ?? null, $debutMois, $finMois, min($arret, $finMois)) + [
            'mois' => $debutMois,
        ];
    }

    /**
     * Devis émis dans la semaine, et devis signés : transformés en commande
     * dans la semaine (date de la commande), ou acceptés et datés de la
     * semaine (la date d'acceptation n'est pas gardée).
     *
     * @return array{emis: int, signes: int}
     */
    private function devis(\DateTimeImmutable $debut, \DateTimeImmutable $fin, ?User $agent): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('d.statut', 'd.dateDevis AS date', 'c.dateCommande AS commande')
            ->from(Devis::class, 'd')
            ->leftJoin('d.commande', 'c')
            ->andWhere('d.deleted = false')
            ->andWhere('(d.dateDevis >= :debut AND d.dateDevis < :fin) OR (c.dateCommande >= :debut AND c.dateCommande < :fin)')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin);
        if (null !== $agent) {
            $qb->andWhere('d.agents = :agent')->setParameter('agent', $agent);
        }

        $emis = $signes = 0;
        foreach ($qb->getQuery()->getArrayResult() as $d) {
            $dansLaSemaine = $d['date'] >= $debut && $d['date'] < $fin;
            if ($dansLaSemaine && \in_array($d['statut'], self::DEVIS_EMIS, true)) {
                ++$emis;
            }
            $signe = match ($d['statut']) {
                Devis::STATUT_CONVERTI => null !== $d['commande'] && $d['commande'] >= $debut && $d['commande'] < $fin,
                Devis::STATUT_ACCEPTE => $dansLaSemaine,
                default => false,
            };
            $signes += $signe ? 1 : 0;
        }

        return ['emis' => $emis, 'signes' => $signes];
    }

    /** Date promise dépassée et commande pas encore livrée (docs/indicateurs.md). */
    private function commandesEnRetard(\DateTimeImmutable $a): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT c.id)')
            ->from(Commandes::class, 'c')
            ->join('c.commandesDetails', 'cd')
            ->andWhere('c.deleted = false AND c.soldeOuverture = false')
            ->andWhere('c.dateLivraison < :a')
            ->andWhere('cd.statutProduction IN (:statuts)')
            ->setParameter('a', $a)
            ->setParameter('statuts', [
                CommandesDetails::PRODUCTION_A_PRODUIRE,
                CommandesDetails::PRODUCTION_EN_COURS,
                CommandesDetails::PRODUCTION_TERMINEE,
                CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
                CommandesDetails::PRODUCTION_EN_LIVRAISON,
            ])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Devis envoyés sans réponse depuis 3 jours (Ventes). */
    private function devisARelancer(?User $agent, \DateTimeImmutable $a): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Devis::class, 'd')
            ->andWhere('d.deleted = false')
            ->andWhere('d.statut = :envoye')
            ->andWhere('d.dateDevis <= :limite')
            ->setParameter('envoye', Devis::STATUT_ENVOYE)
            ->setParameter('limite', $a->setTime(23, 59, 59)->modify(\sprintf('-%d days', VentesService::JOURS_AVANT_RELANCE)));
        if (null !== $agent) {
            $qb->andWhere('d.agents = :agent')->setParameter('agent', $agent);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Travaux terminés par la personne en production (lignes et m²).
     *
     * @return array{nombre: int, surface: float}
     */
    private function travaux(User $u, \DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $ligne = $this->em->createQueryBuilder()
            ->select('COUNT(l.id) AS nombre', 'COALESCE(SUM(l.surface * l.quantite), 0) AS surface')
            ->from(CommandesDetails::class, 'l')
            ->andWhere('l.productionTermineePar = :u')
            ->andWhere('l.productionTermineeLe >= :debut AND l.productionTermineeLe < :fin')
            ->andWhere('l.statutProduction != :annulee')
            ->setParameter('u', $u)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE)
            ->getQuery()
            ->getSingleResult();

        return ['nombre' => (int) $ligne['nombre'], 'surface' => round((float) $ligne['surface'], 1)];
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function nomsUtilisateurs(array $ids): array
    {
        $noms = [];
        foreach ([] === $ids ? [] : $this->em->getRepository(User::class)->findBy(['id' => $ids]) as $u) {
            $noms[(int) $u->getId()] = Objectifs::nomAgent($u);
        }

        return $noms;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function nomsCategories(array $ids): array
    {
        $noms = [];
        foreach ([] === $ids ? [] : $this->em->getRepository(CategorieProduit::class)->findBy(['id' => $ids]) as $c) {
            $noms[(int) $c->getId()] = (string) $c->getNom();
        }

        return $noms;
    }
}
