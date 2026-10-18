<?php

namespace App\Service\Atelier;

use App\Entity\BonLivraison;
use App\Entity\CommandeDetailFichier;
use App\Entity\CommandesDetails;
use App\Entity\AtelierMachine;
use App\Entity\Conception;
use App\Entity\ControlePrePresse;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\RetourPrepresse;
use App\Entity\User;
use App\Repository\RetourPrepresseRepository;
use App\Service\AnalyseFichierImpression;
use App\Service\FichiersPrives;
use App\Service\NotificationService;
use App\Service\Pointage\Pointages;
use App\Service\SuiviMetiers\FileMachine;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Poste machiniste (maquettes OP01 à OP06, docs/poste-machiniste.md) : la
 * file du machiniste en colonnes, l'état d'un travail, la machine libre,
 * les estimations récentes, le retour au prépresse, les colis.
 *
 * Réutilisable par l'atelier (vue en direct, files par machine) : etat(),
 * machineLibreA(), etatMachine() et carte() ne dépendent pas de l'écran.
 */
final class PosteMachiniste
{
    public const A_VERIFIER = 'a_verifier';
    public const PRET = 'pret';
    public const EN_COURS = 'en_cours';
    public const A_ETIQUETER = 'a_etiqueter';
    public const PRET_LIVRAISON = 'pret_livraison';
    public const RETOURNE = 'retourne';

    public const COLONNES = [
        self::A_VERIFIER => 'À vérifier',
        self::PRET => 'Prêt à démarrer',
        self::EN_COURS => 'En cours',
        self::A_ETIQUETER => 'Terminé · à étiqueter',
        self::PRET_LIVRAISON => 'Prêt pour livraison',
    ];

    /** Points du contrôle avant impression (OP02) ; « requis » bloque « Fichier conforme ». */
    public const CONTROLES = [
        'dimensions' => ['libelle' => 'Dimensions = commande', 'requis' => true],
        'securite' => ['libelle' => 'Textes dans la zone de sécurité', 'requis' => true],
        'resolution' => ['libelle' => 'Résolution suffisante', 'requis' => true],
        'matiere' => ['libelle' => 'Matière en stock', 'requis' => true],
        'observation' => ['libelle' => 'Consigne du client lue', 'requis' => false],
    ];

    /** Un travail terminé reste « à étiqueter » ou « prêt » pendant ce nombre de jours. */
    public const JOURS_VISIBLES = 7;
    /** Machines « à soi » : celles où la personne a démarré un travail depuis ce nombre de jours. */
    public const JOURS_MACHINES = 60;
    /** Marge de sécurité autour d'une bâche (ourlet, œillets), en cm. */
    public const ZONE_SECURITE_CM = 5;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RetourPrepresseRepository $retours,
        private readonly NotificationService $notifications,
        private readonly FichiersPrives $fichiersPrives,
        private readonly AnalyseFichierImpression $analyse,
    ) {
    }

    /** Colonne d'un travail pour le machiniste. */
    public static function etat(OrdreProduction $o, ?RetourPrepresse $retourOuvert = null): ?string
    {
        return match (true) {
            $o->estAnnule() => null,
            $retourOuvert !== null && $o->estADemarrer() => self::RETOURNE,
            $o->estADemarrer() => $o->estFichierVerifie() ? self::PRET : self::A_VERIFIER,
            $o->estEnCours(), $o->estEnPause() => self::EN_COURS,
            $o->estPretLivraison() => self::PRET_LIVRAISON,
            default => self::A_ETIQUETER,
        };
    }

    /**
     * Machines suivies par défaut : celles où la personne est déclarée
     * opératrice (atelier_machine), puis celles où elle a démarré un
     * travail ces 60 derniers jours (la plus utilisée d'abord).
     *
     * @return list<Machines>
     */
    public function machinesDe(User $user, \DateTimeImmutable $maintenant): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(o.machine) AS id', 'COUNT(o.id) AS n')->from(OrdreProduction::class, 'o')
            ->where('o.operateur = :u')->andWhere('o.machine IS NOT NULL')->andWhere('o.demarreLe >= :depuis')
            ->setParameter('u', $user)->setParameter('depuis', $maintenant->modify('-'.self::JOURS_MACHINES.' days'))
            ->groupBy('o.machine')->orderBy('n', 'DESC')
            ->getQuery()->getArrayResult();
        // Les machines où la personne est déclarée opératrice (Atelier › machines) d'abord, puis celles de ses travaux récents.
        $declarees = $this->em->createQueryBuilder()
            ->select('IDENTITY(a.machine) AS id')->from(AtelierMachine::class, 'a')->join('a.operateurs', 'u')
            ->where('u = :u')->setParameter('u', $user)
            ->getQuery()->getArrayResult();
        $ids = array_values(array_unique([...array_map(static fn (array $l): int => (int) $l['id'], $declarees), ...array_map(static fn (array $l): int => (int) $l['id'], $lignes)]));
        if ($ids === []) {
            return [];
        }
        $machines = $this->em->getRepository(Machines::class)->findBy(['id' => $ids]);
        usort($machines, static fn (Machines $a, Machines $b): int => array_search($a->getId(), $ids, true) <=> array_search($b->getId(), $ids, true));

        return $machines;
    }

    /**
     * La file : une liste de cartes par colonne et les travaux retournés
     * au prépresse (bandeau). Sans machine précisée, toutes les machines.
     * Les travaux sans machine (à choisir à l'estimation) sont toujours là.
     *
     * @param list<Machines> $machines
     *
     * @return array{colonnes: array<string, list<array<string, mixed>>>, retours: list<array<string, mixed>>}
     */
    public function file(array $machines, \DateTimeImmutable $maintenant): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('o', 'd', 'c', 'cli', 'm')->from(OrdreProduction::class, 'o')
            ->join('o.commandeDetail', 'd')->join('d.commande', 'c')->leftJoin('c.clients', 'cli')->leftJoin('o.machine', 'm')
            ->where('o.statut IN (:actifs) OR (o.statut = :termine AND o.termineLe >= :depuis AND d.statutProduction IN (:apres))')
            ->setParameter('actifs', [OrdreProduction::STATUT_A_PRODUIRE, OrdreProduction::STATUT_EN_COURS, OrdreProduction::STATUT_EN_PAUSE])
            ->setParameter('termine', OrdreProduction::STATUT_TERMINE)
            ->setParameter('depuis', $maintenant->setTime(0, 0)->modify('-'.self::JOURS_VISIBLES.' days'))
            ->setParameter('apres', [CommandesDetails::PRODUCTION_TERMINEE, CommandesDetails::PRODUCTION_PRETE_LIVRAISON]);
        if ($machines !== []) {
            $qb->andWhere('o.machine IN (:machines) OR o.machine IS NULL')->setParameter('machines', $machines);
        }
        /** @var list<OrdreProduction> $ordres */
        $ordres = $qb->getQuery()->getResult();

        $ouverts = [];
        foreach ($this->retours->ouverts() as $r) {
            $ouverts[$r->getOrdre()->getId()] = $r;
        }

        $colonnes = array_fill_keys(array_keys(self::COLONNES), []);
        $retours = [];
        foreach ($ordres as $o) {
            $retour = $ouverts[$o->getId()] ?? null;
            $etat = self::etat($o, $retour);
            if ($etat === null) {
                continue;
            }
            if ($etat === self::RETOURNE) {
                $retours[] = ['retour' => $retour, 'carte' => $this->carte($o, $maintenant), 'minutes' => $retour->minutesEnCorrection($maintenant)];
                continue;
            }
            // Un travail « prêt pour livraison » sort de la file une fois parti en livraison.
            if ($etat === self::PRET_LIVRAISON && $o->getCommandeDetail()?->getStatutProduction() !== CommandesDetails::PRODUCTION_PRETE_LIVRAISON) {
                continue;
            }
            $colonnes[$etat][] = $this->carte($o, $maintenant);
        }
        // Le plus urgent d'abord ; terminés et prêts : le plus ancien d'abord.
        foreach ($colonnes as $etat => $cartes) {
            usort($cartes, static fn (array $a, array $b): int => [$a['rang'], $a['promis'] ?? new \DateTimeImmutable('2999-01-01'), $a['id']] <=> [$b['rang'], $b['promis'] ?? new \DateTimeImmutable('2999-01-01'), $b['id']]);
            $colonnes[$etat] = $cartes;
        }

        return ['colonnes' => $colonnes, 'retours' => $retours];
    }

    /**
     * Ce qu'une carte affiche (OP01). Aucun montant.
     *
     * @return array<string, mixed>
     */
    public function carte(OrdreProduction $o, \DateTimeImmutable $maintenant): array
    {
        $ligne = $o->getCommandeDetail();
        $commande = $ligne?->getCommande();
        $client = $commande?->getClients()?->getNomComplet();
        $promis = $o->getDateLimite() ?? $commande?->getDateLivraison();
        $progression = FileMachine::progression($o, $maintenant);
        $duree = $o->getDureeEstimeeMinutes();

        return [
            'id' => $o->getId(),
            'ordre' => $o,
            'numero' => $commande?->getNumero() ?? $o->getNumero(),
            'client' => $client,
            'initiales' => self::initiales($client),
            'couleur' => self::couleur($client),
            'titre' => $ligne !== null ? self::titre($ligne) : $o->getNumero(),
            'taille' => $ligne !== null ? self::taille($ligne) : null,
            'm2' => FileMachine::m2($o),
            'promis' => $promis,
            'enRetard' => $promis !== null && !$o->estTermine() && $promis->format('Ymd') < $maintenant->format('Ymd'),
            'rang' => ['urgente' => 0, 'haute' => 1, 'normale' => 2, 'basse' => 3][$o->getPriorite()] ?? 2,
            'machine' => $o->getMachine()?->getNom(),
            'estime' => $duree !== null ? TempsEstime::duree($duree) : null,
            'debutPrevu' => $o->getDebutPrevuLe(),
            'pourcent' => $duree !== null && $duree > 0 && $o->getDemarreLe() !== null ? min(99, (int) floor($progression['secondes'] * 100 / ($duree * 60))) : null,
            'finEstimee' => $progression['finEstimee'],
            'enPause' => $o->estEnPause(),
            'colis' => $o->getNombreColis(),
            'livraison' => $commande?->estALivrer() ?? false,
            'livreur' => $commande !== null ? $this->livreur($commande->getId()) : null,
        ];
    }

    /**
     * Quand la machine se libère : fin estimée du travail en cours, puis
     * des travaux déjà prévus dessus. Null si elle est libre.
     */
    public function machineLibreA(Machines $machine, \DateTimeImmutable $maintenant, ?OrdreProduction $sauf = null): ?\DateTimeImmutable
    {
        /** @var list<OrdreProduction> $ordres */
        $ordres = $this->em->createQueryBuilder()->select('o')->from(OrdreProduction::class, 'o')
            ->where('o.machine = :m')->andWhere('o.statut IN (:s)')
            ->setParameter('m', $machine)->setParameter('s', [OrdreProduction::STATUT_EN_COURS, OrdreProduction::STATUT_EN_PAUSE, OrdreProduction::STATUT_A_PRODUIRE])
            ->getQuery()->getResult();
        $libre = null;
        foreach ($ordres as $o) {
            if ($sauf !== null && $o->getId() === $sauf->getId()) {
                continue;
            }
            $fin = $o->estADemarrer()
                ? ($o->getFinPrevueLe() !== null && $o->getFinPrevueLe() > $maintenant ? $o->getFinPrevueLe() : null)
                : (FileMachine::progression($o, $maintenant)['finEstimee'] ?? $maintenant->modify('+30 minutes'));
            if ($fin !== null && $fin > $maintenant && ($libre === null || $fin > $libre)) {
                $libre = $fin;
            }
        }

        return $libre;
    }

    /**
     * État d'une machine pour l'atelier : « en_marche » (avec le travail),
     * « en_pause », « libre » ou « arret » (machine pas en service).
     *
     * @return array{etat: string, ordre: ?OrdreProduction, libreA: ?\DateTimeImmutable}
     */
    public function etatMachine(Machines $machine, \DateTimeImmutable $maintenant): array
    {
        if ($machine->getEtat() !== null && $machine->getEtat() !== 'actif') {
            return ['etat' => 'arret', 'ordre' => null, 'libreA' => null];
        }
        $courant = $this->em->getRepository(OrdreProduction::class)->findOneBy(['machine' => $machine, 'statut' => [OrdreProduction::STATUT_EN_COURS, OrdreProduction::STATUT_EN_PAUSE]], ['demarreLe' => 'DESC']);

        return [
            'etat' => $courant === null ? 'libre' : ($courant->estEnPause() ? 'en_pause' : 'en_marche'),
            'ordre' => $courant,
            'libreA' => $this->machineLibreA($machine, $maintenant),
        ];
    }

    /**
     * Machines proposées à l'estimation (OP03) : possibles ou non pour la
     * taille de la pièce, libres à quelle heure, la recommandée en premier.
     *
     * @return list<array<string, mixed>>
     */
    public function choixMachines(OrdreProduction $o, \DateTimeImmutable $maintenant): array
    {
        $ligne = $o->getCommandeDetail();
        $largeur = $ligne?->getLargeur() !== null ? (float) $ligne->getLargeur() : null;
        $hauteur = $ligne?->getLongueur() !== null ? (float) $ligne->getLongueur() : null;
        $type = $ligne?->getTypeImpression();
        $toutes = $this->em->getRepository(Machines::class)->findBy([], ['nom' => 'ASC']);
        $machines = [];
        foreach ($toutes as $m) {
            if ($m->getEtat() !== null && $m->getEtat() !== 'actif') {
                continue;
            }
            // Les machines du même procédé que la ligne (ou celle déjà choisie) ; sans procédé connu, toutes.
            $memeProcede = $type === null || $m === $o->getMachine() || $m === $ligne?->getMachine() || self::procedeCompatible($type->getNom(), $m->getTypeMachine());
            if (!$memeProcede) {
                continue;
            }
            $machines[] = ['id' => (int) $m->getId(), 'nom' => (string) $m->getNom(), 'largeur' => $m->getLargeurImpression() !== null ? (float) $m->getLargeurImpression() : null, 'machine' => $m];
        }
        $choix = [];
        foreach (TempsEstime::machinesPossibles($largeur, $hauteur, $machines) as $m) {
            $libre = $this->machineLibreA($m['machine'], $maintenant, $o);
            $choix[] = $m + ['libreA' => $libre, 'tetes' => $m['machine']->getNbTetes(), 'vitesse' => $m['machine']->getVitesseM2Heure() !== null ? (float) $m['machine']->getVitesseM2Heure() : null, 'recommandee' => false];
        }
        // Recommandée : possible, libre le plus tôt, puis la plus étroite qui suffit (moins de chute).
        $possibles = array_filter($choix, static fn (array $c): bool => $c['possible']);
        uasort($possibles, static fn (array $a, array $b): int => [$a['libreA'] ?? $maintenant, $a['largeur'] ?? 99] <=> [$b['libreA'] ?? $maintenant, $b['largeur'] ?? 99]);
        if ($possibles !== []) {
            $choix[array_key_first($possibles)]['recommandee'] = true;
        }
        usort($choix, static fn (array $a, array $b): int => [!$a['recommandee'], !$a['possible'], $a['nom']] <=> [!$b['recommandee'], !$b['possible'], $b['nom']]);

        return $choix;
    }

    /** « Solvant grand format » et « Solvant 3,20 m » : même famille d'encre. */
    public static function procedeCompatible(?string $typeImpression, ?string $typeMachine): bool
    {
        $famille = static function (?string $t): ?string {
            $t = mb_strtolower((string) $t);
            foreach (['solvant', 'dtf', 'laser', 'sublimation', 'pvc', 'traceur', 'copieur'] as $f) {
                if (str_contains($t, $f)) {
                    return $f;
                }
            }

            return null;
        };
        $a = $famille($typeImpression);
        $b = $famille($typeMachine);

        return $a === null || $b === null || $a === $b;
    }

    /**
     * Étapes proposées pour l'ordre sur cette machine (OP03).
     *
     * @return list<array{cle: string, libelle: string, propose: ?int}>
     */
    public static function etapesProposees(OrdreProduction $o, ?Machines $machine): array
    {
        $ligne = $o->getCommandeDetail();
        $finitions = [];
        foreach ($ligne?->getFinitions() ?? [] as $f) {
            $finitions[] = (string) $f->getNomFinition();
        }
        $largeur = (float) ($ligne?->getLargeur() ?? 0);
        $hauteur = (float) ($ligne?->getLongueur() ?? 0);
        $solvant = str_contains(mb_strtolower((string) $machine?->getTypeMachine().' '.$ligne?->getTypeImpression()?->getNom()), 'solvant');

        return TempsEstime::proposer(
            FileMachine::m2($o),
            $machine?->getVitesseM2Heure() !== null ? (float) $machine->getVitesseM2Heure() : null,
            (int) ($o->getQuantite() ?? $ligne?->getQuantite() ?? 1),
            $finitions,
            $solvant,
            $largeur > 0 && $hauteur > 0 ? $largeur * $hauteur : null,
        );
    }

    /**
     * Les 3 dernières estimations de la personne comparées au réel, et
     * l'écart moyen du mois.
     *
     * @return array{lignes: list<array<string, mixed>>, ecartMois: ?float}
     */
    public function estimationsRecentes(User $user, \DateTimeImmutable $maintenant): array
    {
        /** @var list<OrdreProduction> $ordres */
        $ordres = $this->em->createQueryBuilder()->select('o', 'd', 'c')->from(OrdreProduction::class, 'o')
            ->join('o.commandeDetail', 'd')->join('d.commande', 'c')
            ->where('o.operateur = :u')->andWhere('o.statut = :t')->andWhere('o.dureeEstimeeMinutes IS NOT NULL')->andWhere('o.termineLe >= :mois')
            ->setParameter('u', $user)->setParameter('t', OrdreProduction::STATUT_TERMINE)->setParameter('mois', $maintenant->modify('first day of this month')->setTime(0, 0))
            ->orderBy('o.termineLe', 'DESC')->getQuery()->getResult();
        $series = [];
        $lignes = [];
        foreach ($ordres as $i => $o) {
            $s = ['estime' => $o->getDureeEstimeeMinutes(), 'reel' => $o->getDureeReelleMinutes()];
            $series[] = $s;
            if ($i < 3) {
                $lignes[] = $s + ['numero' => $o->getCommandeDetail()?->getCommande()?->getNumero(), 'titre' => $o->getCommandeDetail() !== null ? self::titre($o->getCommandeDetail()) : $o->getNumero()];
            }
        }

        return ['lignes' => $lignes, 'ecartMois' => TempsEstime::ecartMoyen($series)];
    }

    /** Graphiste qui a préparé le fichier : celui de la conception, sinon qui a traité ou validé le contrôle. */
    public function graphiste(OrdreProduction $o): ?User
    {
        $controle = $this->dernierControle($o);
        if ($controle === null) {
            return null;
        }
        $conception = $this->em->getRepository(Conception::class)->findOneBy(['controle' => $controle], ['id' => 'DESC']);

        return $conception?->getGraphiste() ?? $controle->getTraitePar() ?? $controle->getValidePar() ?? $controle->getEnvoyeProductionPar() ?? $controle->getControlePar();
    }

    /**
     * Fichier à montrer et à imprimer : le plus récent de ceux transmis à
     * l'ordre, sinon le plus récent de la ligne.
     */
    public static function fichier(OrdreProduction $o): ?CommandeDetailFichier
    {
        $liste = array_values(array_filter($o->getFichiers()->toArray(), static fn (CommandeDetailFichier $f): bool => $f->isActif()));
        if ($liste === []) {
            $liste = array_values(array_filter($o->getCommandeDetail()?->getFichiers()->toArray() ?? [], static fn (CommandeDetailFichier $f): bool => $f->isActif()));
        }
        usort($liste, static fn (CommandeDetailFichier $a, CommandeDetailFichier $b): int => [$b->getCreeLe(), $b->getId()] <=> [$a->getCreeLe(), $a->getId()]);

        return $liste[0] ?? null;
    }

    /** « image », « pdf » ou null (fichier de travail à ouvrir dans le logiciel). */
    public static function typeApercu(?CommandeDetailFichier $f): ?string
    {
        $type = (string) $f?->getTypeMime();
        $nom = mb_strtolower((string) $f?->getNomOriginal());

        return match (true) {
            str_starts_with($type, 'image/') && !str_contains($type, 'tiff') && !str_contains($type, 'photoshop'), (bool) preg_match('/\.(jpe?g|png|webp|gif)$/', $nom) => 'image',
            $type === 'application/pdf', str_ends_with($nom, '.pdf') => 'pdf',
            default => null,
        };
    }

    /**
     * Contrôle automatique du fichier (dimensions, résolution, couleurs…),
     * quand il est lisible sur ce serveur.
     *
     * @return list<array{libelle: string, valeur: string, etat: string}>
     */
    public function analyseFichier(OrdreProduction $o): array
    {
        $f = self::fichier($o);
        $chemin = $f !== null ? $this->fichiersPrives->chemin($f->getChemin()) : null;
        if ($chemin === null || !is_readable($chemin)) {
            return [];
        }
        $ligne = $o->getCommandeDetail();
        $l = (float) ($ligne?->getLargeur() ?? 0);
        $h = (float) ($ligne?->getLongueur() ?? 0);

        return $this->analyse->analyser($chemin, $l > 0 ? $l * 100 : null, $h > 0 ? $h * 100 : null);
    }

    /**
     * Cases pré-cochées : ce que le prépresse a déjà déclaré conforme.
     *
     * @return array<string, bool>
     */
    public function controlesProposes(OrdreProduction $o): array
    {
        if ($o->getControleMachiniste() !== []) {
            return $o->getControleMachiniste();
        }
        $c = $this->dernierControle($o);
        // Ce que l'analyse du fichier trouve en erreur n'est jamais pré-coché.
        $analyse = $this->analyseFichier($o);
        $erreur = static fn (string $mot): bool => array_filter($analyse, static fn (array $a): bool => str_contains($a['libelle'], $mot) && $a['etat'] === AnalyseFichierImpression::ERREUR) !== [];

        return [
            'dimensions' => (bool) $c?->isDimensionsConformes() && !$erreur('Ratio'),
            'securite' => (bool) $c?->isMargesSecuriteConformes(),
            'resolution' => (bool) $c?->isResolutionConforme() && !$erreur('solution'),
            'matiere' => false,
            'observation' => false,
        ];
    }

    /**
     * Cases cochées par le machiniste, et les requises qui manquent.
     *
     * @param array<string, mixed> $saisie
     *
     * @return array{0: array<string, bool>, 1: list<string>}
     */
    public static function lireControles(array $saisie, bool $avecObservation): array
    {
        $controles = [];
        $manquent = [];
        foreach (self::CONTROLES as $cle => $c) {
            if ($cle === 'observation' && !$avecObservation) {
                continue;
            }
            $controles[$cle] = !empty($saisie[$cle]);
            if ($c['requis'] && !$controles[$cle]) {
                $manquent[] = $c['libelle'];
            }
        }

        return [$controles, $manquent];
    }

    public function verifierFichier(OrdreProduction $o, User $par, array $controles, \DateTimeImmutable $le): void
    {
        $o->verifierFichier($par, $controles, $le);
        $this->em->flush();
    }

    /**
     * Renvoie le travail au prépresse (OP04) : il sort de la file, le
     * contrôle repasse « correction demandée », le graphiste est prévenu.
     *
     * @param list<string>               $motifs
     * @param list<array<string, mixed>> $annotation
     */
    public function retournerAuPrepresse(OrdreProduction $o, User $par, array $motifs, ?string $commentaire, array $annotation, \DateTimeImmutable $le): RetourPrepresse
    {
        if (!$o->estADemarrer()) {
            throw new \LogicException('Un travail démarré ne repart plus au prépresse : signalez un problème.');
        }
        if ($this->retours->ouvertPour($o) !== null) {
            throw new \LogicException('Ce travail est déjà au prépresse.');
        }
        $graphiste = $this->graphiste($o);
        $retour = new RetourPrepresse($o, $par, $graphiste, $motifs, $commentaire, $annotation, $le);
        $this->em->persist($retour);
        $o->annulerVerification();

        $texte = $retour->getLibelleMotifs().($retour->getCommentaire() !== null ? ' : '.$retour->getCommentaire() : '');
        $controle = $this->dernierControle($o);
        $ligne = $o->getCommandeDetail();
        if ($controle !== null) {
            $controle->setCorrectionNecessaire(true)->setAnomalies('Retour du machiniste ('.Pointages::nom($par).') : '.$texte)->setStatut(ControlePrePresse::STATUT_CORRECTION_REQUISE);
            $conception = $this->em->getRepository(Conception::class)->findOneBy(['controle' => $controle], ['id' => 'DESC']);
            $conception?->retourMachiniste($le, $texte);
        }
        // Impression directe (« Mes produits ») : la ligne réapparaît au prépresse.
        $ligne?->setPrePresseNecessaire(true);

        $numero = $ligne?->getCommande()?->getNumero() ?? $o->getNumero();
        $message = \sprintf('%s renvoyé au prépresse par %s : %s', $numero, Pointages::nom($par), $texte);
        $parametres = $ligne !== null ? ['id' => $ligne->getId()] : [];
        if ($graphiste !== null) {
            $this->notifications->notifierUtilisateur($graphiste, $message, $ligne !== null ? 'app_controle_pre_presse_controler' : null, $parametres);
        } else {
            $this->notifications->notifierRoles(['ROLE_PREPRESSE'], $message, $ligne !== null ? 'app_controle_pre_presse_controler' : null, $parametres, $par);
        }
        $this->em->flush();

        return $retour;
    }

    /**
     * Le prépresse renvoie le fichier corrigé (nouveau contrôle envoyé en
     * production) : le retour se ferme, l'ordre reprend les nouveaux
     * fichiers et revient « À vérifier », le machiniste est prévenu.
     * Ne flushe pas.
     */
    public function fichierCorrige(OrdreProduction $o, ControlePrePresse $controle, \DateTimeImmutable $le): bool
    {
        $retour = $this->retours->ouvertPour($o);
        if ($retour === null) {
            return false;
        }
        $retour->marquerCorrige($le);
        if ($o->getControlePrePresse() !== $controle) {
            $o->setControlePrePresse($controle);
        }
        foreach ($o->getFichiers()->toArray() as $ancien) {
            $o->removeFichier($ancien);
        }
        foreach ($controle->getFichiers() as $f) {
            $o->addFichier($f);
        }
        $o->getCommandeDetail()?->setStatutProduction(CommandesDetails::PRODUCTION_A_PRODUIRE);
        if ($retour->getPar() !== null) {
            $this->notifications->notifierUtilisateur($retour->getPar(), \sprintf('%s : le fichier corrigé est revenu du prépresse, à vérifier.', $o->getCommandeDetail()?->getCommande()?->getNumero() ?? $o->getNumero()), 'app_ma_file_travail', ['id' => $o->getId()]);
        }

        return true;
    }

    public function dernierControle(OrdreProduction $o): ?ControlePrePresse
    {
        $dernier = $o->getControlePrePresse();
        foreach ($o->getCommandeDetail()?->getControlesPrePresse() ?? [] as $c) {
            if ($dernier === null || $c->getId() > $dernier->getId()) {
                $dernier = $c;
            }
        }

        return $dernier;
    }

    /**
     * Suivi de production d'un travail (OP05) : étapes datées.
     *
     * @return list<array{libelle: string, detail: ?string, le: ?\DateTimeImmutable, fait: bool}>
     */
    public function suivi(OrdreProduction $o): array
    {
        $etapes = [];
        $etapes[] = ['libelle' => 'Fichier vérifié', 'detail' => $o->getFichierVerifieLe() !== null ? 'conforme'.($o->getFichierVerifiePar() !== null ? ' · '.Pointages::nom($o->getFichierVerifiePar()) : '') : null, 'le' => $o->getFichierVerifieLe(), 'fait' => $o->estFichierVerifie()];
        foreach ($this->em->getRepository(RetourPrepresse::class)->findBy(['ordre' => $o], ['creeLe' => 'ASC']) as $r) {
            $etapes[] = ['libelle' => 'Retourné au prépresse', 'detail' => $r->getLibelleMotifs().($r->getCorrigeLe() !== null ? ' · corrigé en '.TempsEstime::duree($r->minutesEnCorrection($r->getCorrigeLe())) : ''), 'le' => $r->getCreeLe(), 'fait' => true];
        }
        $etapes[] = ['libelle' => 'Démarré', 'detail' => $o->getMachine()?->getNom(), 'le' => $o->getDemarreLe(), 'fait' => $o->getDemarreLe() !== null];
        $estime = $o->getDureeEstimeeMinutes();
        $reel = $o->getDureeReelleMinutes();
        $etapes[] = ['libelle' => 'Terminé', 'detail' => $o->estTermine() ? implode(' · ', array_filter([$estime !== null ? 'estimé '.TempsEstime::duree($estime) : null, $reel !== null ? 'réel '.TempsEstime::duree($reel) : null])) : null, 'le' => $o->getTermineLe(), 'fait' => $o->estTermine()];
        $etapes[] = ['libelle' => 'Étiquettes imprimées', 'detail' => $o->getNombreColis() !== null ? $o->getNombreColis().' colis' : null, 'le' => $o->getEtiquettesImprimeesLe(), 'fait' => $o->getEtiquettesImprimeesLe() !== null];
        $etapes[] = ['libelle' => 'Prêt pour livraison', 'detail' => $o->estPretLivraison() ? null : 'après les étiquettes', 'le' => $o->getPretLivraisonLe(), 'fait' => $o->estPretLivraison()];

        return $etapes;
    }

    /**
     * Ce qui va sur l'étiquette d'un colis et le bloc Livraison (OP05).
     * Le reste à encaisser n'est rendu qu'avec $montants (droit Paiements).
     *
     * @return array<string, mixed>
     */
    public function livraison(OrdreProduction $o, bool $montants): array
    {
        $ligne = $o->getCommandeDetail();
        $commande = $ligne?->getCommande();
        $client = $commande?->getClients();
        $finitions = [];
        foreach ($ligne?->getFinitions() ?? [] as $f) {
            $finitions[] = mb_strtolower((string) $f->getNomFinition());
        }
        $bon = $commande !== null ? $this->dernierBon($commande->getId()) : null;
        $adresse = $commande?->estALivrer()
            ? ($commande->getAdresseLivraison() ?? implode(', ', array_filter([$client?->getAdresse(), $client?->getQuartier(), $client?->getVille()])))
            : 'Retrait à l\'imprimerie';

        return [
            'client' => $client?->getNomComplet(),
            'adresse' => $adresse !== '' ? $adresse : null,
            'telephone' => $client?->getTelephone(),
            'contenu' => $ligne !== null ? trim(self::titre($ligne).($ligne->getLargeur() ? ' · '.self::taille($ligne) : '')) : null,
            'finitions' => implode(' + ', $finitions),
            'numero' => $commande?->getNumero() ?? $o->getNumero(),
            'aLivrer' => $commande?->estALivrer() ?? false,
            'date' => $commande?->getDateLivraison() ?? $o->getDateLimite(),
            'creneau' => $bon?->getCreneau() ?? $commande?->getCreneauLivraison(),
            'livreur' => $bon?->getLivreur() !== null ? Pointages::nom($bon->getLivreur()) : null,
            'preparePar' => ($o->getTerminePar() ?? $o->getOperateur()) !== null ? Pointages::nom($o->getTerminePar() ?? $o->getOperateur()) : null,
            'observation' => $o->getObservationFin(),
            'reste' => $montants ? $commande?->getResteAPayer() : null,
            'resteAEncaisser' => ($commande?->getResteAPayer() ?? 0) > 0,
            'conformes' => $o->getQuantiteConforme(),
            'rebut' => $o->getQuantiteRebut(),
        ];
    }

    /** Étiquettes des colis imprimées : numéro d'étiquette affecté si besoin. */
    public function etiqueter(OrdreProduction $o, int $colis, User $par, \DateTimeImmutable $le): void
    {
        $o->etiqueterColis($colis, $le);
        if (!$o->aUneEtiquette()) {
            $o->affecterEtiquette(\sprintf('ETQ-%s-%06d', $le->format('Y'), $o->getId()), $par);
        }
        $this->em->flush();
    }

    /**
     * « Prêt pour livraison » : la ligne passe « prête », le client le voit
     * dans son espace ; le livreur du bon (ou l'équipe livraison) est prévenu.
     */
    public function pretPourLivraison(OrdreProduction $o, User $par, \DateTimeImmutable $le): void
    {
        $ligne = $o->getCommandeDetail() ?? throw new \LogicException('Aucune ligne de commande.');
        $o->marquerPretLivraison($par, $le);
        if ($ligne->getStatutProduction() === CommandesDetails::PRODUCTION_TERMINEE) {
            $ligne->marquerPreteLivraison(true);
        }
        $commande = $ligne->getCommande();
        if ($commande !== null && $commande->estALivrer()) {
            $message = \sprintf('%s prête pour la livraison : %d colis étiqueté%s.', $commande->getNumero(), $o->getNombreColis() ?? 1, ($o->getNombreColis() ?? 1) > 1 ? 's' : '');
            $livreur = $this->dernierBon($commande->getId())?->getLivreur();
            if ($livreur !== null) {
                $this->notifications->notifierUtilisateur($livreur, $message, 'app_livraisons_tournee');
            } else {
                $this->notifications->notifierRoles(['ROLE_COMMANDE'], $message.' À planifier.', 'app_livraisons_index');
            }
        }
        $this->em->flush();
    }

    /** « Bâche 440 g × 2 » : la désignation sans les dimensions ajoutées entre parenthèses. */
    public static function titre(CommandesDetails $ligne): string
    {
        $nom = trim((string) preg_replace('/\s*\([\d.,\s]+[x×][\d.,\s]+(cm|mm|m)\)/u', '', (string) ($ligne->getDesignation() ?: $ligne->getProduit()?->getNom()))) ?: 'Travail';

        return $nom.' × '.number_format((float) $ligne->getQuantite(), 0, ',', ' ');
    }

    /** « 4 × 2 m », « 85 × 200 cm ». */
    public static function taille(CommandesDetails $ligne): ?string
    {
        $l = (float) ($ligne->getLargeur() ?? 0);
        $h = (float) ($ligne->getLongueur() ?? 0);
        if ($l <= 0 || $h <= 0) {
            return $ligne->getFormat()?->getNom();
        }
        $f = static fn (float $v, int $mult): string => rtrim(rtrim(number_format($v * $mult, 2, ',', ' '), '0'), ',');

        return $l < 1 || $h < 1 ? $f($l, 100).' × '.$f($h, 100).' cm' : $f($l, 1).' × '.$f($h, 1).' m';
    }

    /** Couleur de la pastille d'un client : toujours la même pour un nom. */
    public static function couleur(?string $nom): string
    {
        $couleurs = ['#2F6BC0', '#E8398A', '#E95B35', '#13787A', '#7C3AED', '#0891B2', '#CA8A04'];

        return $couleurs[crc32(mb_strtolower(trim((string) $nom))) % \count($couleurs)];
    }

    public static function initiales(?string $nom): string
    {
        $mots = array_values(array_filter(preg_split('/[\s\-]+/u', trim((string) $nom)) ?: [], static fn (string $m): bool => mb_strlen($m) > 1 || ctype_upper($m)));
        if ($mots === []) {
            return '?';
        }

        return mb_strtoupper(mb_substr($mots[0], 0, 1).(isset($mots[1]) ? mb_substr($mots[1], 0, 1) : ''));
    }

    private function livreur(?int $commande): ?string
    {
        $livreur = $commande !== null ? $this->dernierBon($commande)?->getLivreur() : null;

        return $livreur !== null ? ($livreur->getEmploye()?->getPrenom() ?? $livreur->getUsername()) : null;
    }

    /** @var array<int, ?BonLivraison> */
    private array $bons = [];

    private function dernierBon(?int $commande): ?BonLivraison
    {
        if ($commande === null) {
            return null;
        }
        if (!\array_key_exists($commande, $this->bons)) {
            $this->bons[$commande] = $this->em->getRepository(BonLivraison::class)->findOneBy(['commande' => $commande], ['id' => 'DESC']);
        }

        return $this->bons[$commande];
    }
}
