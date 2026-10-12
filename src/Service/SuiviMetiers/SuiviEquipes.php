<?php

namespace App\Service\SuiviMetiers;

use App\Entity\BonLivraison;
use App\Entity\Conception;
use App\Entity\OrdreProduction;
use App\Entity\Tache;
use App\Entity\User;
use App\Service\Livraisons\Tournee;
use App\Service\Parametres\Parametres;
use App\Service\Pointage\Pointages;
use App\Service\TableauProduction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suivi des équipes en direct (maquette W06) : ce que fait chacun en ce
 * moment, d'après le travail déjà suivi par métier (conception PAO, file
 * machine, tournée, entretien), les délais du jour, les alertes et le
 * classement du mois.
 *
 * Le classement se fait sur la note de chacun (points gagnés sur points
 * possibles de son métier) : les points bruts d'un livreur et d'un
 * graphiste ne se comparent pas.
 */
final class SuiviEquipes
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Pointages $pointages,
        private readonly Parametres $parametres,
        private readonly TableauConception $conception,
        private readonly PointsLivreur $livreurs,
        private readonly Entretien $entretien,
        private readonly FileMachine $machines,
    ) {
    }

    /** Métier suivi d'une personne, d'après son rôle. */
    public static function metier(User $u): ?string
    {
        if ($u->isAdmin()) {
            return null;
        }
        $roles = $u->getRoles();
        $code = $u->getProfil()?->getCode();

        return match (true) {
            \in_array('ROLE_ENTRETIEN', $roles, true) && !\in_array('ROLE_PRODUCTION_GERER', $roles, true) => Bareme::ENTRETIEN,
            $code === 'ROLE_LIVREUR' => Bareme::LIVREUR,
            $code === 'ROLE_GRAPHISTE' => Bareme::GRAPHISTE,
            $code === 'ROLE_PRODUCTION' => Bareme::OPERATEUR,
            default => null,
        };
    }

    /**
     * @return array{cartes: list<array<string, mixed>>, chiffres: array<string, mixed>, classement: list<array<string, mixed>>}
     */
    public function direct(\DateTimeImmutable $maintenant): array
    {
        $cartes = [];
        foreach ($this->pointages->personnel() as $u) {
            $metier = self::metier($u);
            if ($metier === null) {
                continue;
            }
            $carte = match ($metier) {
                Bareme::GRAPHISTE => $this->graphiste($u, $maintenant),
                Bareme::OPERATEUR => $this->operateur($u, $maintenant),
                Bareme::LIVREUR => $this->livreur($u, $maintenant),
                default => $this->agentEntretien($u, $maintenant),
            };
            $cartes[] = $carte + ['utilisateur' => $u, 'nom' => Pointages::nom($u), 'poste' => $u->getEmploye()?->getFonction() ?? Bareme::METIERS[$metier]['libelle'], 'metier' => $metier];
        }

        $ordre = [Bareme::GRAPHISTE => 0, Bareme::OPERATEUR => 1, Bareme::LIVREUR => 2, Bareme::ENTRETIEN => 3];
        usort($cartes, static fn (array $a, array $b): int => [$ordre[$a['metier']], $a['nom']] <=> [$ordre[$b['metier']], $b['nom']]);

        $enCours = array_fill_keys(array_keys($ordre), 0);
        $delais = ['ok' => 0, 'sur' => 0, 'retards' => []];
        foreach ($cartes as $c) {
            $enCours[$c['metier']] += $c['actif'] ? 1 : 0;
            $delais['ok'] += $c['delais'][0];
            $delais['sur'] += $c['delais'][1];
            foreach ($c['retards'] as $r) {
                $delais['retards'][] = $r;
            }
        }

        $classement = array_map(static fn (array $c): array => [
            'nom' => $c['nom'],
            'court' => self::court($c['nom']),
            'metier' => $c['metier'],
            'gagnes' => $c['mois']['gagnes'],
            'possibles' => $c['mois']['possibles'],
            'note' => $c['mois']['possibles'] > 0 ? Bareme::note($c['mois']['gagnes'], $c['mois']['possibles']) : null,
        ], $cartes);
        usort($classement, static fn (array $a, array $b): int => [$b['note'] ?? -1, $b['gagnes']] <=> [$a['note'] ?? -1, $a['gagnes']]);

        return [
            'cartes' => $cartes,
            'chiffres' => [
                'enCours' => $enCours,
                'delais' => $delais,
                'alertes' => \count(array_filter($cartes, static fn (array $c): bool => $c['alerte'] !== null)),
                'pointsJour' => array_sum(array_column($cartes, 'jour')),
                'pointsMois' => array_sum(array_map(static fn (array $c): int => $c['mois']['gagnes'], $cartes)),
            ],
            'classement' => $classement,
        ];
    }

    /** « Bakary C. » */
    public static function court(string $nom): string
    {
        $mots = preg_split('/\s+/', trim($nom)) ?: [];

        return \count($mots) > 1 ? $mots[0].' '.mb_substr($mots[\count($mots) - 1], 0, 1).'.' : $nom;
    }

    /** @return array<string, mixed> */
    private function graphiste(User $u, \DateTimeImmutable $maintenant): array
    {
        $colonnes = $this->conception->colonnes($u, false, $maintenant);
        $chiffres = $this->conception->chiffres($u, $colonnes, $maintenant);
        $courant = $this->conception->enCours($u);
        $maintenantTexte = 'aucun travail démarré';
        $progression = 0;
        $alerte = null;
        if ($courant !== null) {
            $carte = $this->conception->carte($courant, $maintenant);
            $secondes = $courant->secondesA($maintenant);
            $maintenantTexte = $carte['titre'].($carte['client'] ? ' · '.$carte['client'] : '').' · '.Conception::duree($secondes);
            $progression = (int) min(100, round($secondes * 100 / max(60, $courant->getTempsPrevu() * 60)));
            if (\in_array($carte['delai']['couleur'], ['orange', 'rouge'], true) && $courant->getEcheanceBat() !== null) {
                $alerte = 'BAT dû à '.$courant->getEcheanceBat()->format('H:i').' · '.$carte['delai']['texte'];
            }
        } elseif (($suivant = $this->conception->suivant($u, $maintenant)) !== null && $suivant->getGraphiste() === $u) {
            // Un travail encore libre n'est à personne : il ne figure pas sur la carte de chacun.
            $carte = $this->conception->carte($suivant, $maintenant);
            $maintenantTexte = 'À démarrer : '.$carte['titre'].($carte['client'] ? ' · '.$carte['client'] : '');
            if ($carte['delai']['couleur'] === 'rouge') {
                $alerte = 'BAT en retard : '.$carte['delai']['texte'];
            }
        }

        $jour = $maintenant->setTime(0, 0);
        $debutMois = $maintenant->modify('first day of this month')->setTime(0, 0);
        $valides = array_filter(
            $this->em->getRepository(Conception::class)->createQueryBuilder('c')
                ->where('c.graphiste = :g')->andWhere('c.valideLe >= :d')->andWhere('c.valideLe <= :m')
                ->setParameter('g', $u)->setParameter('d', $debutMois)->setParameter('m', $maintenant)
                ->getQuery()->getResult(),
            static fn (Conception $c): bool => $c->getPoints() !== null,
        );
        $duJour = array_filter($valides, static fn (Conception $c): bool => $c->getValideLe() >= $jour && $c->dansLesDelais() !== null);
        $retards = array_values(array_map(static fn (Conception $c): string => 'BAT en retard', array_filter($duJour, static fn (Conception $c): bool => !$c->dansLesDelais())));
        $pourcent = static fn (array $x): string => $x['sur'] > 0 ? round($x['ok'] * 100 / $x['sur']).' %' : '—';

        return [
            'actif' => $courant !== null,
            'maintenant' => $maintenantTexte,
            'progression' => $progression,
            'stats' => [$chiffres['travaux'].' travaux', 'Délais '.$pourcent($chiffres['delais']), '1er envoi '.$pourcent($chiffres['premierEnvoi'])],
            'alerte' => $alerte,
            'jour' => (int) $chiffres['pointsJour'],
            'mois' => [
                'gagnes' => array_sum(array_map(static fn (Conception $c): int => (int) $c->getPoints(), $valides)),
                'possibles' => array_sum(array_map(static fn (Conception $c): int => (int) $c->getPointsPossibles(), $valides)),
            ],
            'delais' => [\count($duJour) - \count($retards), \count($duJour)],
            'retards' => $retards,
        ];
    }

    /** @return array<string, mixed> */
    private function operateur(User $u, \DateTimeImmutable $maintenant): array
    {
        $bareme = $this->parametres->bareme();
        $debutMois = $maintenant->modify('first day of this month')->setTime(0, 0);
        $jour = $maintenant->setTime(0, 0);
        /** @var list<OrdreProduction> $ordres */
        $ordres = $this->em->getRepository(OrdreProduction::class)->createQueryBuilder('o')
            ->where('o.operateur = :u')
            ->andWhere('o.statut IN (:actifs) OR (o.statut = :termine AND o.termineLe >= :debut)')
            ->setParameter('u', $u)->setParameter('actifs', [OrdreProduction::STATUT_EN_COURS, OrdreProduction::STATUT_EN_PAUSE])
            ->setParameter('termine', OrdreProduction::STATUT_TERMINE)->setParameter('debut', $debutMois)
            ->getQuery()->getResult();

        $courant = null;
        $gagnes = 0;
        $possibles = 0;
        $pointsJour = 0;
        $m2Jour = 0.0;
        $duJour = [];
        foreach ($ordres as $o) {
            if (!$o->estTermine()) {
                $courant ??= $o;
                continue;
            }
            $faits = FileMachine::faitsOrdre($o);
            $parfaits = ['p_avant_heure' => 1] + array_intersect_key($faits, ['p_10m2' => 1]);
            $gagnes += $bareme->points($faits);
            $possibles += $bareme->possibles($parfaits);
            if ($o->getTermineLe() >= $jour) {
                $pointsJour += $bareme->points($faits);
                $m2Jour += FileMachine::m2($o) ?? 0.0;
                if (($o->getFinPrevueLe() ?? $o->getDateLimite()) !== null) {
                    $duJour[] = isset($faits['p_avant_heure']);
                }
            }
        }

        $maintenantTexte = 'aucun travail en cours';
        $progression = 0;
        $alerte = null;
        $fin = '—';
        $gache = null;
        if ($courant !== null) {
            $p = FileMachine::progression($courant, $maintenant);
            $titre = TableauProduction::titre($courant->getCommandeDetail());
            $progression = $p['m2'] && $p['fait'] !== null ? (int) round($p['fait'] * 100 / $p['m2']) : 0;
            $maintenantTexte = $titre.($p['fait'] !== null ? ' · '.self::nombre($p['fait']).' m² · '.$progression.' %' : '').($courant->getStatut() === OrdreProduction::STATUT_EN_PAUSE ? ' · en pause' : '');
            if ($p['finEstimee'] !== null) {
                $prevue = $courant->getFinPrevueLe();
                $enRetard = $prevue !== null && $p['finEstimee'] > $prevue;
                $fin = 'Fin '.$p['finEstimee']->format('H:i').($enRetard ? '' : ' ✓');
                if ($enRetard) {
                    $alerte = 'Sortie prévue à '.$prevue->format('H:i').', fin estimée '.$p['finEstimee']->format('H:i');
                }
            }
            if ($courant->getMachine() !== null) {
                $gache = $this->machines->journee($courant->getMachine(), $maintenant)['tauxGache'];
            }
        }

        $retards = array_values(array_map(static fn (): string => 'sortie machine en retard', array_filter($duJour, static fn (bool $ok): bool => !$ok)));

        return [
            'actif' => $courant !== null,
            'maintenant' => $maintenantTexte,
            'progression' => $progression,
            'stats' => [self::nombre($m2Jour).' m² sortis', 'Gâche '.($gache !== null ? self::nombre($gache).' %' : '—'), $fin],
            'alerte' => $alerte,
            'jour' => $pointsJour,
            'mois' => ['gagnes' => $gagnes, 'possibles' => $possibles],
            'delais' => [\count($duJour) - \count($retards), \count($duJour)],
            'retards' => $retards,
        ];
    }

    /** @return array<string, mixed> */
    private function livreur(User $u, \DateTimeImmutable $maintenant): array
    {
        $j = $this->livreurs->journee($u, $maintenant);
        $bons = array_values(array_filter($j['bons'], static fn (BonLivraison $b): bool => $b->getStatut() !== BonLivraison::STATUT_ANNULE));
        $livres = array_values(array_filter($bons, static fn (BonLivraison $b): bool => $b->getStatut() === BonLivraison::STATUT_LIVRE));
        $client = static fn (BonLivraison $b): string => (string) ($b->getCommande()?->getClients()?->getRaisonSociale() ?: $b->getCommande()?->getClients()?->getNomComplet() ?: 'client');
        $enRoute = null;
        $suivant = null;
        foreach ($bons as $b) {
            if ($b->getStatut() !== BonLivraison::STATUT_LIVRE) {
                if ($b->getEnRouteLe() !== null) {
                    $enRoute ??= $b;
                }
                $suivant ??= $b;
            }
        }
        $enRetard = array_values(array_filter($livres, static fn (BonLivraison $b): bool => !Tournee::aLHeure($b)));

        $maintenantTexte = match (true) {
            $enRoute !== null => 'En route vers '.$client($enRoute).($enRoute->getCreneau() ? ' · créneau '.$enRoute->getCreneau() : ''),
            $suivant !== null => 'Prochaine livraison : '.$client($suivant).($suivant->getCreneau() ? ' · '.$suivant->getCreneau() : ''),
            $bons !== [] => 'Tournée terminée',
            default => 'aucune livraison aujourd\'hui',
        };

        return [
            'actif' => $enRoute !== null,
            'maintenant' => $maintenantTexte,
            'progression' => $bons !== [] ? (int) round(\count($livres) * 100 / \count($bons)) : 0,
            'stats' => [
                \count($livres).' / '.\count($bons).' livraisons',
                \count($enRetard).' retard'.(\count($enRetard) > 1 ? 's' : ''),
                'Encaissé '.number_format(array_sum(array_map(static fn (BonLivraison $b): int => (int) $b->getEncaisseMontant(), $livres)), 0, ',', ' ').' F',
            ],
            'alerte' => $enRetard !== [] ? 'Retard à '.$client($enRetard[\count($enRetard) - 1]).' (livré à '.$enRetard[\count($enRetard) - 1]->getLivreLe()->format('H:i').')' : null,
            'jour' => (int) $j['jour'],
            'mois' => ['gagnes' => $j['mois']['gagnes'], 'possibles' => $j['mois']['possibles']],
            'delais' => [\count($livres) - \count($enRetard), \count($livres)],
            'retards' => array_map(static fn (): string => 'retard livraison', $enRetard),
        ];
    }

    /** @return array<string, mixed> */
    private function agentEntretien(User $u, \DateTimeImmutable $maintenant): array
    {
        $j = $this->entretien->journee($u, $maintenant);
        /** @var list<Tache> $taches */
        $taches = $j['taches'];
        $suivante = null;
        $enRetard = [];
        $faitesAlHeure = 0;
        foreach ($taches as $t) {
            $retard = Entretien::retard($t, $maintenant);
            if ($t->estFaite()) {
                $faitesAlHeure += $retard === 0 ? 1 : 0;
                continue;
            }
            $suivante ??= $t;
            if ($retard > 0) {
                $enRetard[] = $t;
            }
        }
        $controles = $j['mois']['controles'];

        return [
            'actif' => $suivante !== null && $suivante->getCommenceLe() !== null,
            'maintenant' => $suivante !== null ? 'Prochaine tâche '.($suivante->getHeure() ? $suivante->getHeure().' · ' : '').$suivante->getTitre() : ($taches !== [] ? 'Tâches du jour terminées' : 'aucune tâche aujourd\'hui'),
            'progression' => $taches !== [] ? (int) round($j['faites'] * 100 / \count($taches)) : 0,
            'stats' => [
                $j['faites'].' / '.\count($taches).' tâches',
                \count($enRetard).' en retard',
                'Contrôles '.($controles > 0 ? $j['mois']['conformes'].' / '.$controles : '—'),
            ],
            'alerte' => array_values(array_filter($enRetard, static fn (Tache $t): bool => Entretien::retard($t, $maintenant) > Entretien::RETARD_MAX)) !== []
                ? 'En retard : '.$enRetard[0]->getTitre().' (+'.Entretien::retard($enRetard[0], $maintenant).' min)' : null,
            'jour' => (int) $j['jour'],
            'mois' => ['gagnes' => $j['mois']['gagnes'], 'possibles' => $j['mois']['possibles']],
            'delais' => [$faitesAlHeure, $j['faites']],
            'retards' => array_fill(0, $j['faites'] - $faitesAlHeure, 'tâche d\'entretien en retard'),
        ];
    }

    private static function nombre(float $n): string
    {
        return number_format($n, $n == round($n) ? 0 : 1, ',', ' ');
    }
}
