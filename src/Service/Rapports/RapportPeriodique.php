<?php

namespace App\Service\Rapports;

use App\Entity\BonLivraison;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\DemandeEmploye;
use App\Entity\Devis;
use App\Entity\Echeance;
use App\Entity\Paiements;
use App\Entity\ReclamationClient;
use App\Entity\Tache;
use App\Entity\User;
use App\Repository\PointageRepository;
use App\Repository\TacheRepository;
use App\Service\ActionsCaisse\HeuresTravail;
use App\Service\Creances;
use App\Service\Finances\Budgets;
use App\Service\Finances\ReglagesFinances;
use App\Service\Finances\Rentabilite;
use App\Service\Finances\VueEnsemble;
use App\Service\Parametres\Parametres;
use App\Service\Pointage\CalculPointage;
use App\Service\Pointage\Pointages;
use App\Service\Statistiques\Objectifs;
use App\Service\TableauDeBordService;
use App\Service\VentesService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Contenu d'un rapport par e-mail (docs/rapports-mail.md) pour un
 * destinataire et une période : l'activité, puis l'argent, comparés à la
 * période d'avant et aux objectifs du mois, et ce qui demande une
 * attention. Les services des écrans existants donnent les chiffres
 * (Objectifs, Vue d'ensemble, Budgets, Créances, Pointage, Planning) :
 * un rapport dit la même chose que les écrans.
 *
 * Chaque partie n'est calculée que si le destinataire a son droit
 * (DroitsRapport) : une partie fermée vaut null et n'existe nulle part.
 * Deux destinataires aux mêmes droits partagent les chiffres de
 * l'entreprise (calculés une fois) ; les tâches en retard sont celles que
 * chacun voit dans son planning.
 */
final class RapportPeriodique
{
    /** Devis émis (envoyés au client, quelle que soit la suite), comme le rapport de la semaine. */
    private const DEVIS_EMIS = [Devis::STATUT_ENVOYE, Devis::STATUT_ACCEPTE, Devis::STATUT_REFUSE, Devis::STATUT_EXPIRE, Devis::STATUT_CONVERTI];

    /** Lignes encore à faire ou à livrer : la commande n'est pas livrée. */
    private const NON_LIVREES = [
        CommandesDetails::PRODUCTION_A_PRODUIRE,
        CommandesDetails::PRODUCTION_EN_COURS,
        CommandesDetails::PRODUCTION_TERMINEE,
        CommandesDetails::PRODUCTION_PRETE_LIVRAISON,
        CommandesDetails::PRODUCTION_EN_LIVRAISON,
    ];

    /** Au plus, dans les listes de noms. */
    public const NOMS_MAX = 6;

    /** Réclamation ouverte depuis plus de N jours : alerte (docs/indicateurs.md). */
    public const JOURS_RECLAMATION = 3;

    /** BAT sans réponse du client depuis plus de N heures : alerte (tableau de bord). */
    public const HEURES_BAT = 48;

    /** Taux de marge sous lequel le rapport du mois alerte (docs/indicateurs.md). */
    public const MARGE_MINIMUM = 35;

    /** @var array<string, array<string, mixed>> chiffres d'entreprise par droits et période */
    private array $memoire = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Objectifs $objectifs,
        private readonly VueEnsemble $vue,
        private readonly Budgets $budgets,
        private readonly Creances $creances,
        private readonly Rentabilite $rentabilite,
        private readonly TableauDeBordService $tableauDeBord,
        private readonly ReglagesFinances $reglagesFinances,
        private readonly Pointages $pointages,
        private readonly PointageRepository $depotPointages,
        private readonly TacheRepository $taches,
        private readonly Parametres $parametres,
    ) {
    }

    /**
     * Le rapport d'une personne : ses droits disent ce qui est calculé.
     *
     * @param array<string, bool> $droits DroitsRapport
     * @param list<string>        $roles  rôles atteignables (planning : ce qu'elle voit)
     *
     * @return array<string, mixed>
     */
    public function donnees(User $pour, array $droits, array $roles, PeriodeRapport $p, \DateTimeImmutable $maintenant): array
    {
        if (!($droits['rapport'] ?? false)) {
            throw new \LogicException('Pas de rapport sans le droit « Chiffre d’affaires global ».');
        }
        $cle = DroitsRapport::signature($droits).'|'.$p->type.':'.$p->code().'|'.$maintenant->format('Y-m-d H:i');
        $r = $this->memoire[$cle] ??= $this->entreprise($droits, $p, $maintenant);
        $r['taches'] = $this->tachesEnRetard($pour, $roles, $maintenant);
        $r['alertes'] = self::alertes($r, $droits);

        return $r;
    }

    /** Oublie les chiffres déjà calculés (après un envoi). */
    public function oublier(): void
    {
        $this->memoire = [];
    }

    /**
     * Ce qui demande une attention, chaque ligne seulement avec le droit
     * de voir son chiffre. Règle pure, pour être testée.
     *
     * @param array<string, mixed> $r
     * @param array<string, bool>  $droits
     *
     * @return list<array{niveau: string, texte: string}>
     */
    public static function alertes(array $r, array $droits): array
    {
        $alertes = [];
        $n = static fn (int $nombre, string $singulier, string $pluriel): string => $nombre.' '.($nombre > 1 ? $pluriel : $singulier);
        $f = static fn (int $montant): string => number_format($montant, 0, ',', ' ').' F';
        $a = $r['activite'] ?? null;

        if (($droits['rapport'] ?? false) && null !== $a) {
            if ($a['en_retard'] > 0) {
                $alertes[] = ['niveau' => 'danger', 'texte' => $n($a['en_retard'], 'commande en retard', 'commandes en retard').' (date promise dépassée)'];
            }
            if ($a['bat']['sans_reponse'] > 0) {
                $alertes[] = ['niveau' => 'warning', 'texte' => $n($a['bat']['sans_reponse'], 'BAT', 'BAT').' sans réponse du client depuis plus de '.self::HEURES_BAT.' h'];
            }
            if ($a['reclamations']['anciennes'] > 0) {
                $alertes[] = ['niveau' => 'warning', 'texte' => $n($a['reclamations']['anciennes'], 'réclamation ouverte', 'réclamations ouvertes').' depuis plus de '.self::JOURS_RECLAMATION.' jours'];
            }
            if ($a['devis']['a_relancer'] > 0) {
                $alertes[] = ['niveau' => 'warning', 'texte' => $n($a['devis']['a_relancer'], 'devis envoyé', 'devis envoyés').' sans réponse depuis '.VentesService::JOURS_AVANT_RELANCE.' jours'];
            }
        }
        if (null !== ($r['taches'] ?? null) && $r['taches']['nombre'] > 0) {
            $alertes[] = ['niveau' => 'danger', 'texte' => $n($r['taches']['nombre'], 'tâche du planning en retard', 'tâches du planning en retard')];
        }

        $v = $r['ventes'] ?? null;
        if (($droits['ventes'] ?? false) && null !== $v) {
            if (null !== $v['objectif'] && $v['objectif'] > 0 && $v['facture'] < $v['objectif'] * 0.9) {
                $alertes[] = ['niveau' => 'warning', 'texte' => \sprintf('CA facturé à %d %% de l’objectif de la période', (int) round($v['facture'] * 100 / $v['objectif']))];
            }
            if (null !== $v['mois'] && \in_array($v['mois']['etat'], ['en_retard', 'manque'], true) && null !== $v['mois']['part_projetee']) {
                $alertes[] = ['niveau' => 'danger', 'texte' => \sprintf('Au rythme actuel, le CA du mois finirait à %d %% de l’objectif', $v['mois']['part_projetee'])];
            }
        }

        $e = $r['encaissements'] ?? null;
        if (($droits['paiements'] ?? false) && null !== $e) {
            if (null !== $e['taux'] && $e['taux'] < $e['taux_objectif']) {
                $alertes[] = ['niveau' => 'warning', 'texte' => \sprintf('Taux d’encaissement à %d %% (objectif %d %%)', (int) round($e['taux']), $e['taux_objectif'])];
            }
            if ($e['attente']['nombre'] > 0) {
                $alertes[] = ['niveau' => 'warning', 'texte' => $n($e['attente']['nombre'], 'paiement', 'paiements').' à valider (saisis en ligne ou au comptoir)'];
            }
        }
        $c = $r['creances'] ?? null;
        if (($droits['paiements'] ?? false) && null !== $c && $c['echues'] > 0) {
            $alertes[] = ['niveau' => 'danger', 'texte' => 'Créances échues : '.$f($c['echues']).' ('.$n($c['nb_echues'], 'document', 'documents').')'];
        }

        $t = $r['tresorerie'] ?? null;
        if (($droits['tresorerie'] ?? false) && null !== $t) {
            if ($t['seuil'] > 0 && $t['total'] < $t['seuil']) {
                $alertes[] = ['niveau' => 'danger', 'texte' => 'Trésorerie disponible sous le seuil d’alerte de '.$f($t['seuil'])];
            }
            $d = $t['depenses'];
            if (null !== $d['budget'] && $d['budget'] > 0 && $d['hors_salaires'] > $d['budget']) {
                $alertes[] = ['niveau' => 'warning', 'texte' => \sprintf('Dépenses hors salaires à %d %% du budget du mois', (int) round($d['hors_salaires'] * 100 / $d['budget']))];
            }
        }

        if (($droits['marges'] ?? false) && null !== ($r['marge'] ?? null) && null !== $r['marge']['taux'] && $r['marge']['taux'] < self::MARGE_MINIMUM) {
            $alertes[] = ['niveau' => 'warning', 'texte' => \sprintf('Marge brute du mois à %d %% (seuil %d %%)', $r['marge']['taux'], self::MARGE_MINIMUM)];
        }
        if (($droits['stock'] ?? false) && [] !== ($r['stock'] ?? [])) {
            $alertes[] = ['niveau' => 'warning', 'texte' => $n(\count($r['stock']), 'article de stock sous le seuil', 'articles de stock sous le seuil')];
        }
        if (($droits['rh'] ?? false) && null !== ($r['equipe'] ?? null) && $r['equipe']['absences_non_justifiees'] > 0) {
            $alertes[] = ['niveau' => 'warning', 'texte' => $n($r['equipe']['absences_non_justifiees'], 'absence non justifiée', 'absences non justifiées')];
        }

        return $alertes;
    }

    /**
     * Écart en % avec la période de comparaison, null sans base.
     */
    public static function ecart(float|int|null $valeur, float|int|null $avant): ?int
    {
        if (null === $valeur || null === $avant || $avant <= 0) {
            return null;
        }

        return (int) round(($valeur - $avant) * 100 / $avant);
    }

    /* ---------- Chiffres de l'entreprise ---------- */

    /**
     * @param array<string, bool> $droits
     *
     * @return array<string, mixed>
     */
    private function entreprise(array $droits, PeriodeRapport $p, \DateTimeImmutable $maintenant): array
    {
        $c = $p->comparaison();
        $arret = min($p->fin, $maintenant);
        $objectifs = $this->objectifsDuMois($p);
        $mois = PeriodeRapport::contenant(PeriodeRapport::MOIS, $p->dernierJour());

        $r = [
            'periode' => $p,
            'comparaison' => $c,
            'arrete_le' => $arret,
            'en_cours' => !$p->terminee($maintenant),
            'droits' => $droits,
            'activite' => $this->activite($droits, $p, $c, $objectifs, $maintenant),
            'ventes' => null,
            'encaissements' => null,
            'creances' => null,
            'tresorerie' => null,
            'marge' => null,
            'equipe' => null,
            'stock' => null,
        ];

        if ($droits['ventes']) {
            $facture = (int) ($this->objectifs->realises('ca', 'global', $p->debut, $p->fin)[0] ?? 0);
            $objectif = $objectifs['ca:global:0'];
            $r['ventes'] = [
                'facture' => $facture,
                'avant' => (int) ($this->objectifs->realises('ca', 'global', $c->debut, $c->fin)[0] ?? 0),
                'objectif' => $objectif,
                'part' => null !== $objectif && $objectif > 0 ? (int) round($facture * 100 / $objectif) : null,
                // Le mois en cours, arrêté à la fin de la période (rapports du jour et de la semaine).
                'mois' => PeriodeRapport::MOIS === $p->type ? null : $this->avancementDuMois('ca', $mois, $arret),
                'nom_mois' => $mois->libelle(),
            ];
        }

        if ($droits['paiements']) {
            $r['encaissements'] = $this->encaissements($p, $c, $objectifs);
            $r['creances'] = $this->creancesClients($droits['clients'], $maintenant);
        }

        if ($droits['tresorerie']) {
            $comptes = $this->vue->comptes();
            $r['tresorerie'] = [
                'comptes' => array_map(static fn (array $c): array => ['nom' => $c['nom'], 'type' => $c['type'], 'solde' => $c['solde']], $comptes),
                'total' => array_sum(array_column($comptes, 'solde')),
                'seuil' => $this->reglagesFinances->seuilTresorerie(),
                'depenses' => $this->depenses($p, $c),
            ];
        }

        if ($droits['marges'] && PeriodeRapport::MOIS === $p->type) {
            $r['marge'] = ['taux' => $this->rentabilite->tauxDuMois($p->debut, $p->fin), 'avant' => $this->rentabilite->tauxDuMois($c->debut, $c->fin)];
        }

        if ($droits['rh']) {
            $r['equipe'] = $this->equipe($p, $maintenant);
        }

        if ($droits['stock']) {
            $r['stock'] = $this->tableauDeBord->articlesSousSeuil();
        }

        return $r;
    }

    /**
     * @param array<string, bool>      $droits
     * @param array<string, ?int>      $objectifs
     *
     * @return array<string, mixed>
     */
    private function activite(array $droits, PeriodeRapport $p, PeriodeRapport $c, array $objectifs, \DateTimeImmutable $maintenant): array
    {
        $realise = fn (string $indicateur, PeriodeRapport $periode): float => (float) ($this->objectifs->realises($indicateur, 'global', $periode->debut, $periode->fin)[0] ?? 0);
        $devis = $this->devis($p);
        $commandes = (int) $realise('commandes', $p);

        return [
            'commandes' => [
                'nombre' => $commandes,
                'avant' => (int) $realise('commandes', $c),
                'objectif' => $objectifs['commandes:global:0'],
                'mois' => PeriodeRapport::MOIS === $p->type ? null : $this->avancementDuMois('commandes', PeriodeRapport::contenant(PeriodeRapport::MOIS, $p->dernierJour()), min($p->fin, $maintenant)),
            ],
            'devis' => $devis + ['emis_avant' => $this->devis($c)['emis'], 'a_relancer' => $this->devisARelancer($maintenant)],
            'surface' => ['valeur' => round($realise('surface', $p), 1), 'avant' => round($realise('surface', $c), 1), 'objectif' => $objectifs['surface:global:0']],
            'clients' => $this->nouveauxClients($p, $c, $objectifs['nouveaux_clients:global:0'], $droits['clients']),
            'livraisons' => $this->livraisons($p),
            'bat' => $this->bat($p, $maintenant),
            'reclamations' => $this->reclamations($p, $droits['clients'], $maintenant),
            'en_retard' => $this->commandesEnRetard($maintenant),
        ];
    }

    /**
     * Objectif de chaque indicateur de l'entreprise pour la période : celui
     * du mois au prorata des jours (Objectifs::prorata), null sans objectif.
     *
     * @return array<string, ?int>
     */
    private function objectifsDuMois(PeriodeRapport $p): array
    {
        $parMois = [];
        for ($mois = $p->debut->modify('first day of this month')->setTime(0, 0); $mois < $p->fin; $mois = $mois->modify('+1 month')) {
            $parMois[$mois->format('Y-m')] = $this->objectifs->valeurs($mois);
        }
        $objectifs = [];
        foreach (['ca:global:0', 'commandes:global:0', 'surface:global:0', 'nouveaux_clients:global:0'] as $cle) {
            $objectifs[$cle] = Objectifs::prorata(array_map(static fn (array $v): ?int => $v[$cle] ?? null, $parMois), $p->debut, $p->fin);
        }
        $premier = reset($parMois) ?: [];
        $objectifs['taux_encaissement:global:0'] = $premier['taux_encaissement:global:0'] ?? Objectifs::TAUX_ENCAISSEMENT_PAR_DEFAUT;

        return $objectifs;
    }

    /** Avancement du mois arrêté à la fin de la période : réalisé depuis le 1er, objectif, projection. */
    private function avancementDuMois(string $indicateur, PeriodeRapport $mois, \DateTimeImmutable $arret): array
    {
        $realise = (float) ($this->objectifs->realises($indicateur, 'global', $mois->debut, $arret)[0] ?? 0);

        return Objectifs::avancement($realise, $this->objectifs->valeurs($mois->debut)[$indicateur.':global:0'] ?? null, $mois->debut, $mois->fin, $arret);
    }

    /**
     * Devis émis dans la période, et devis signés : transformés en commande
     * dans la période, ou acceptés et datés de la période (même règle que
     * le rapport de la semaine).
     *
     * @return array{emis: int, signes: int}
     */
    private function devis(PeriodeRapport $p): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('d.statut', 'd.dateDevis AS date', 'c.dateCommande AS commande')
            ->from(Devis::class, 'd')
            ->leftJoin('d.commande', 'c')
            ->andWhere('d.deleted = false')
            ->andWhere('(d.dateDevis >= :debut AND d.dateDevis < :fin) OR (c.dateCommande >= :debut AND c.dateCommande < :fin)')
            ->setParameter('debut', $p->debut)
            ->setParameter('fin', $p->fin)
            ->getQuery()
            ->getArrayResult();

        $emis = $signes = 0;
        foreach ($lignes as $d) {
            $dedans = null !== $d['date'] && $d['date'] >= $p->debut && $d['date'] < $p->fin;
            if ($dedans && \in_array($d['statut'], self::DEVIS_EMIS, true)) {
                ++$emis;
            }
            $signes += match ($d['statut']) {
                Devis::STATUT_CONVERTI => null !== $d['commande'] && $d['commande'] >= $p->debut && $d['commande'] < $p->fin ? 1 : 0,
                Devis::STATUT_ACCEPTE => $dedans ? 1 : 0,
                default => 0,
            };
        }

        return ['emis' => $emis, 'signes' => $signes];
    }

    /** Devis envoyés sans réponse depuis 3 jours (écran Ventes), aujourd'hui. */
    private function devisARelancer(\DateTimeImmutable $maintenant): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(Devis::class, 'd')
            ->andWhere('d.deleted = false')
            ->andWhere('d.statut = :envoye')
            ->andWhere('d.dateDevis <= :limite')
            ->setParameter('envoye', Devis::STATUT_ENVOYE)
            ->setParameter('limite', $maintenant->setTime(23, 59, 59)->modify(\sprintf('-%d days', VentesService::JOURS_AVANT_RELANCE)))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Clients dont la première commande tombe dans la période ; leurs noms
     * seulement avec le droit Clients.
     *
     * @return array{nombre: int, avant: int, objectif: ?int, noms: ?list<string>}
     */
    private function nouveauxClients(PeriodeRapport $p, PeriodeRapport $c, ?int $objectif, bool $noms): array
    {
        $resultat = [
            'nombre' => $this->objectifs->nouveauxClients($p->debut, $p->fin),
            'avant' => $this->objectifs->nouveauxClients($c->debut, $c->fin),
            'objectif' => $objectif,
            'noms' => null,
        ];
        if ($noms && $resultat['nombre'] > 0) {
            $ids = array_column($this->em->createQueryBuilder()
                ->select('IDENTITY(c.clients) AS client', 'MIN(c.dateCommande) AS premiere')
                ->from(Commandes::class, 'c')
                ->andWhere('c.deleted = false AND c.soldeOuverture = false')
                ->groupBy('c.clients')
                ->having('MIN(c.dateCommande) >= :debut AND MIN(c.dateCommande) < :fin')
                ->setParameter('debut', $p->debut)
                ->setParameter('fin', $p->fin)
                ->orderBy('premiere', 'ASC')
                ->setMaxResults(self::NOMS_MAX)
                ->getQuery()
                ->getArrayResult(), 'client');
            $clients = [] === $ids ? [] : $this->em->getRepository(Clients::class)->findBy(['id' => $ids]);
            $resultat['noms'] = array_values(array_map(static fn (Clients $cl): string => $cl->getNomComplet(), $clients));
        }

        return $resultat;
    }

    /**
     * Livraisons remises et échecs notés dans la période.
     *
     * @return array{livrees: int, echecs: int}
     */
    private function livraisons(PeriodeRapport $p): array
    {
        $livrees = (int) $this->em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(BonLivraison::class, 'b')
            ->andWhere('b.statut = :livre')
            ->andWhere('b.livreLe >= :debut AND b.livreLe < :fin')
            ->setParameter('livre', BonLivraison::STATUT_LIVRE)
            ->setParameter('debut', $p->debut)
            ->setParameter('fin', $p->fin)
            ->getQuery()
            ->getSingleScalarResult();

        $echecs = (int) $this->em->createQueryBuilder()
            ->select('COUNT(b.id)')
            ->from(BonLivraison::class, 'b')
            ->andWhere('b.statut != :annule')
            ->andWhere('b.echecLe >= :debut AND b.echecLe < :fin')
            ->setParameter('annule', BonLivraison::STATUT_ANNULE)
            ->setParameter('debut', $p->debut)
            ->setParameter('fin', $p->fin)
            ->getQuery()
            ->getSingleScalarResult();

        return ['livrees' => $livrees, 'echecs' => $echecs];
    }

    /**
     * BAT validés dans la période ; en attente du client aujourd'hui, dont
     * ceux sans réponse depuis plus de 48 h.
     *
     * @return array{valides: int, en_attente: int, sans_reponse: int}
     */
    private function bat(PeriodeRapport $p, \DateTimeImmutable $maintenant): array
    {
        $valides = (int) $this->em->createQueryBuilder()
            ->select('COUNT(cp.id)')
            ->from(ControlePrePresse::class, 'cp')
            ->andWhere('cp.valideLe >= :debut AND cp.valideLe < :fin')
            ->andWhere('cp.statut IN (:valides)')
            ->setParameter('debut', $p->debut)
            ->setParameter('fin', $p->fin)
            ->setParameter('valides', [ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION])
            ->getQuery()
            ->getSingleScalarResult();

        $attente = $this->em->createQueryBuilder()
            ->select('COUNT(cp.id) AS nombre', 'SUM(CASE WHEN COALESCE(cp.traiteLe, cp.creeLe) < :limite THEN 1 ELSE 0 END) AS anciens')
            ->from(ControlePrePresse::class, 'cp')
            ->andWhere('cp.statut = :attente')
            ->setParameter('attente', ControlePrePresse::STATUT_EN_ATTENTE_BAT)
            ->setParameter('limite', $maintenant->modify(\sprintf('-%d hours', self::HEURES_BAT)))
            ->getQuery()
            ->getSingleResult();

        return ['valides' => $valides, 'en_attente' => (int) $attente['nombre'], 'sans_reponse' => (int) $attente['anciens']];
    }

    /**
     * Réclamations des clients : reçues dans la période, ouvertes
     * aujourd'hui (dont les anciennes). Le détail (numéro, objet, client)
     * seulement avec le droit Clients.
     *
     * @return array{nouvelles: int, ouvertes: int, anciennes: int, liste: ?list<array{numero: string, objet: string, client: string}>}
     */
    private function reclamations(PeriodeRapport $p, bool $detail, \DateTimeImmutable $maintenant): array
    {
        $nouvelles = (int) $this->em->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(ReclamationClient::class, 'r')
            ->andWhere('r.creeLe >= :debut AND r.creeLe < :fin')
            ->setParameter('debut', $p->debut)
            ->setParameter('fin', $p->fin)
            ->getQuery()
            ->getSingleScalarResult();

        /** @var list<ReclamationClient> $ouvertes */
        $ouvertes = $this->em->getRepository(ReclamationClient::class)->findBy(['statut' => [ReclamationClient::OUVERTE, ReclamationClient::EN_COURS]], ['creeLe' => 'ASC']);
        $limite = $maintenant->modify(\sprintf('-%d days', self::JOURS_RECLAMATION));

        return [
            'nouvelles' => $nouvelles,
            'ouvertes' => \count($ouvertes),
            'anciennes' => \count(array_filter($ouvertes, static fn (ReclamationClient $r): bool => $r->getCreeLe() < $limite)),
            'liste' => $detail ? array_map(static fn (ReclamationClient $r): array => [
                'numero' => (string) ($r->getNumero() ?? 'R-'.$r->getId()),
                'objet' => $r->getObjet(),
                'client' => (string) $r->getClient()?->getNomComplet(),
            ], \array_slice($ouvertes, 0, self::NOMS_MAX)) : null,
        ];
    }

    /** Date promise dépassée et commande pas encore livrée (docs/indicateurs.md), aujourd'hui. */
    private function commandesEnRetard(\DateTimeImmutable $maintenant): int
    {
        return (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT c.id)')
            ->from(Commandes::class, 'c')
            ->join('c.commandesDetails', 'cd')
            ->andWhere('c.deleted = false AND c.soldeOuverture = false')
            ->andWhere('c.dateLivraison < :a')
            ->andWhere('cd.statutProduction IN (:statuts)')
            ->setParameter('a', $maintenant)
            ->setParameter('statuts', self::NON_LIVREES)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Paiements validés de la période par moyen de paiement (le paiement
     * par le solde du client ou par points ne fait pas entrer d'argent),
     * taux d'encaissement (Objectifs) et paiements encore à valider.
     *
     * @param array<string, ?int> $objectifs
     *
     * @return array<string, mixed>
     */
    private function encaissements(PeriodeRapport $p, PeriodeRapport $c, array $objectifs): array
    {
        $parMode = function (PeriodeRapport $periode): array {
            $lignes = $this->em->createQueryBuilder()
                ->select('p.mode AS mode', 'SUM(p.montant) AS montant', 'COUNT(p.id) AS nombre')
                ->from(Paiements::class, 'p')
                ->andWhere('p.statut = :valide')
                ->andWhere('p.mode NOT IN (:sans)')
                ->andWhere('p.date >= :debut AND p.date < :fin')
                ->setParameter('valide', Paiements::STATUT_VALIDE)
                ->setParameter('sans', Paiements::MODES_SANS_ENCAISSEMENT)
                ->setParameter('debut', $periode->debut)
                ->setParameter('fin', $periode->fin)
                ->groupBy('p.mode')
                ->orderBy('montant', 'DESC')
                ->getQuery()
                ->getArrayResult();

            return array_map(static fn (array $l): array => ['libelle' => Paiements::MODES_LABELS[$l['mode']] ?? (string) $l['mode'], 'montant' => (int) $l['montant'], 'nombre' => (int) $l['nombre']], $lignes);
        };
        $modes = $parMode($p);
        $facture = (int) ($this->objectifs->realises('ca', 'global', $p->debut, $p->fin)[0] ?? 0);

        $attente = $this->em->createQueryBuilder()
            ->select('COUNT(p.id) AS nombre', 'COALESCE(SUM(p.montant), 0) AS montant')
            ->from(Paiements::class, 'p')
            ->andWhere('p.statut = :attente')
            ->setParameter('attente', Paiements::STATUT_EN_ATTENTE)
            ->getQuery()
            ->getSingleResult();

        return [
            'total' => array_sum(array_column($modes, 'montant')),
            'avant' => array_sum(array_column($parMode($c), 'montant')),
            'modes' => $modes,
            'taux' => $facture > 0 ? $this->objectifs->tauxEncaissement($p->debut, $p->fin) : null,
            'taux_objectif' => (int) ($objectifs['taux_encaissement:global:0'] ?? Objectifs::TAUX_ENCAISSEMENT_PAR_DEFAUT),
            'attente' => ['nombre' => (int) $attente['nombre'], 'montant' => (int) $attente['montant']],
        ];
    }

    /**
     * Créances clients aujourd'hui (écran Créances) : total, échues, et
     * les plus gros débiteurs avec le droit Clients.
     *
     * @return array<string, mixed>
     */
    private function creancesClients(bool $noms, \DateTimeImmutable $maintenant): array
    {
        $d = $this->creances->donnees($maintenant);
        $echues = array_filter($d['lignes'], static fn (array $l): bool => $l['jours'] > 0);
        $debiteurs = null;
        if ($noms) {
            $parClient = [];
            foreach ($d['lignes'] as $l) {
                $nom = '' !== trim((string) $l['client']) ? (string) $l['client'] : 'Client sans nom';
                $parClient[$nom] = ($parClient[$nom] ?? 0) + (int) $l['reste'];
            }
            arsort($parClient);
            $debiteurs = [];
            foreach (\array_slice($parClient, 0, 5, true) as $nom => $reste) {
                $debiteurs[] = ['nom' => (string) $nom, 'reste' => $reste];
            }
        }

        return [
            'total' => (int) $d['total'],
            'echues' => array_sum(array_map(static fn (array $l): int => (int) $l['reste'], $echues)),
            'nb_echues' => \count($echues),
            'plus_60' => (int) (($d['balance']['61_90'] ?? 0) + ($d['balance']['plus_90'] ?? 0)),
            'debiteurs' => $debiteurs,
        ];
    }

    /**
     * Dépenses de la période (toutes les charges, salaires compris, comme
     * le graphique de la vue d'ensemble) et par catégorie de budget ; le
     * budget seulement pour le rapport du mois.
     *
     * @return array<string, mixed>
     */
    private function depenses(PeriodeRapport $p, PeriodeRapport $c): array
    {
        $total = $this->budgets->chargesTotales($p->debut, $p->fin);
        $consommes = array_filter($this->budgets->consommes($p->debut, $p->fin), static fn (int $m): bool => $m > 0);
        arsort($consommes);
        $categories = [];
        foreach ($consommes as $cle => $montant) {
            $categories[] = ['libelle' => Budgets::CATEGORIES[$cle]['libelle'] ?? $cle, 'montant' => $montant];
        }
        $horsSalaires = array_sum($consommes);
        if ($total - $horsSalaires > 0) {
            $categories[] = ['libelle' => 'Salaires et autres charges', 'montant' => $total - $horsSalaires];
        }

        return [
            'total' => $total,
            'avant' => $this->budgets->chargesTotales($c->debut, $c->fin),
            'categories' => $categories,
            'hors_salaires' => $horsSalaires,
            'budget' => PeriodeRapport::MOIS === $p->type ? array_sum($this->budgets->montants()) : null,
        ];
    }

    /**
     * Présences de l'équipe (écran Pointage) : pour chaque jour travaillé
     * de la période déjà commencé, qui était là, en retard, absent ; une
     * absence couverte par une demande acceptée (absence, congé) est
     * justifiée. Jours fériés de la tuile Horaires écartés.
     *
     * @return array<string, mixed>
     */
    private function equipe(PeriodeRapport $p, \DateTimeImmutable $maintenant): array
    {
        $regles = $this->pointages->regles();
        $feries = new HeuresTravail('lun-dim', feries: (string) $this->parametres->valeur('horaires', 'feries'));
        $personnel = $this->pointages->personnel();
        $fin = min($p->fin, $maintenant->setTime(0, 0)->modify('+1 day'));

        $presences = [];
        $retards = [];
        foreach ($this->depotPointages->entre($p->debut, $fin) as $pointage) {
            $u = (int) $pointage->getUtilisateur()->getId();
            $jour = $pointage->getArriveeLe()->format('Y-m-d');
            $presences[$u][$jour] = true;
            $calcul = $this->pointages->calcul($pointage, $regles, $maintenant);
            if (($calcul['retard'] ?? 0) > 0) {
                $retards[$u] ??= ['jours' => 0, 'minutes' => 0];
                ++$retards[$u]['jours'];
                $retards[$u]['minutes'] += (int) $calcul['retard'];
            }
        }
        $justifiees = $this->joursJustifies($p->debut, $fin);

        $lignes = [];
        $presentsDernierJour = 0;
        $attendusDernierJour = 0;
        $absNonJustifiees = 0;
        foreach ($personnel as $u) {
            $id = (int) $u->getId();
            $ouvres = CalculPointage::joursOuvres((string) Pointages::reglesDe($u, $regles)['jours']);
            $absences = 0;
            $justifies = 0;
            for ($jour = $p->debut; $jour < $fin; $jour = $jour->modify('+1 day')) {
                if (!\in_array((int) $jour->format('N'), $ouvres, true) || !$feries->estOuvre($jour)) {
                    continue;
                }
                $cle = $jour->format('Y-m-d');
                $dernier = $jour->modify('+1 day') >= $fin;
                if ($dernier) {
                    ++$attendusDernierJour;
                }
                if (isset($presences[$id][$cle])) {
                    $presentsDernierJour += $dernier ? 1 : 0;
                    continue;
                }
                // Le jour même, avant l'heure d'ouverture, personne n'est encore absent.
                if ($cle === $maintenant->format('Y-m-d') && $maintenant->format('H:i') < (string) Pointages::reglesDe($u, $regles)['ouverture']) {
                    continue;
                }
                isset($justifiees[$id][$cle]) ? ++$justifies : ++$absences;
            }
            $absNonJustifiees += $absences;
            if ($absences > 0 || $justifies > 0 || isset($retards[$id])) {
                $lignes[] = [
                    'nom' => Pointages::nom($u),
                    'retards' => $retards[$id]['jours'] ?? 0,
                    'minutes' => $retards[$id]['minutes'] ?? 0,
                    'absences' => $absences,
                    'justifiees' => $justifies,
                ];
            }
        }
        usort($lignes, static fn (array $a, array $b): int => [$b['absences'], $b['retards'], $a['nom']] <=> [$a['absences'], $a['retards'], $b['nom']]);

        return [
            'effectif' => \count($personnel),
            'presents' => $presentsDernierJour,
            'attendus' => $attendusDernierJour,
            'retards' => array_sum(array_column($lignes, 'retards')),
            'absences_non_justifiees' => $absNonJustifiees,
            'lignes' => $lignes,
        ];
    }

    /**
     * Jours couverts par une absence ou un congé accepté, par personne.
     *
     * @return array<int, array<string, true>>
     */
    private function joursJustifies(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        /** @var list<DemandeEmploye> $demandes */
        $demandes = $this->em->getRepository(DemandeEmploye::class)->createQueryBuilder('d')
            ->andWhere('d.type IN (:types)')
            ->andWhere('d.etat IN (:etats)')
            ->andWhere('d.du < :fin')
            ->andWhere('COALESCE(d.au, d.du) >= :debut')
            ->setParameter('types', [DemandeEmploye::ABSENCE, DemandeEmploye::CONGE])
            ->setParameter('etats', [DemandeEmploye::ACCEPTEE, DemandeEmploye::PAYEE])
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();

        $jours = [];
        foreach ($demandes as $d) {
            $u = (int) $d->getUtilisateur()->getId();
            for ($jour = max($d->getDu()->setTime(0, 0), $debut); $jour <= min(($d->getAu() ?? $d->getDu())->setTime(0, 0), $fin); $jour = $jour->modify('+1 day')) {
                $jours[$u][$jour->format('Y-m-d')] = true;
            }
        }

        return $jours;
    }

    /**
     * Tâches du planning en retard que la personne voit (Planning) : la
     * direction toutes, les autres les leurs, plus Fiscal et Paie avec le
     * droit trésorerie. Rappel « En retard » éteint : pas comptée.
     *
     * @param list<string> $roles
     *
     * @return array{nombre: int, liste: list<array{titre: string, prevue: \DateTimeImmutable}>}
     */
    private function tachesEnRetard(User $pour, array $roles, \DateTimeImmutable $maintenant): array
    {
        $direction = \in_array('ROLE_ADMIN', $roles, true);
        $tresorerie = \in_array('ROLE_TRESORERIE_VOIR', $roles, true);
        $visibles = array_values(array_filter(
            $this->taches->enRetard($maintenant),
            static fn (Tache $t): bool => ($t->getEcheance()?->rappel('retard') ?? true)
                && ($direction || $t->concerne($pour) || (\in_array($t->getCategorie(), Echeance::CONFIDENTIELLES, true) && $tresorerie)),
        ));

        return [
            'nombre' => \count($visibles),
            'liste' => array_map(static fn (Tache $t): array => ['titre' => $t->getTitre(), 'prevue' => $t->getPrevueLe()], \array_slice($visibles, 0, self::NOMS_MAX)),
        ];
    }
}
