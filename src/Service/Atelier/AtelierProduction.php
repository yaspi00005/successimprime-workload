<?php

namespace App\Service\Atelier;

use App\Entity\Articles;
use App\Entity\AtelierMachine;
use App\Entity\CommandeDetailFichier;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\EntretienMachine;
use App\Entity\IncidentMachine;
use App\Entity\JournalMachine;
use App\Entity\Machines;
use App\Entity\Maintenance;
use App\Entity\OrdreProduction;
use App\Entity\Pointage;
use App\Entity\ProduitArticleStock;
use App\Entity\User;
use App\Service\Production\ProgressionRouleau;
use App\Service\TableauProduction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Données de l'atelier de production (docs/atelier-production.md) : vue en
 * direct des machines (AT01), file d'attente (AT02), planning de la semaine
 * (AT03), maintenance et incidents (AT04), écran de l'opérateur (AT05).
 * Les calculs sont dans AvancementOrdre ; ici, la lecture de la base et
 * l'assemblage. Aucun montant n'en sort, sauf le devis d'un incident,
 * que les écrans ne montrent qu'avec le droit Marges et coûts.
 */
final class AtelierProduction
{
    /** Niveau d'encre sous lequel une alerte s'affiche (%). */
    public const SEUIL_ENCRE = 25;
    /** Charge de la semaine à partir de laquelle le planning propose de décaler (%). */
    public const SEUIL_CHARGE = 85;

    private const ACTIFS = [OrdreProduction::STATUT_A_PRODUIRE, OrdreProduction::STATUT_EN_COURS, OrdreProduction::STATUT_EN_PAUSE];
    private const MOTS_ENCRE = ['C' => 'cyan', 'M' => 'magenta', 'J' => 'jaune', 'N' => 'noir', 'B' => 'blanc'];
    private const SUITE_LIGNE = [
        CommandesDetails::PRODUCTION_TERMINEE => 'terminé',
        CommandesDetails::PRODUCTION_PRETE_LIVRAISON => 'prêt · retrait ou livraison',
        CommandesDetails::PRODUCTION_EN_LIVRAISON => 'en livraison',
        CommandesDetails::PRODUCTION_LIVREE => 'livré',
    ];

    /** @var array<int, list<array<string, mixed>>> */
    private array $files = [];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    // ------------------------------------------------------------------
    // Machines visibles et réglages de l'atelier
    // ------------------------------------------------------------------

    /**
     * Le chef d'atelier et la direction voient toutes les machines ; un
     * opérateur, celles qui lui sont affectées.
     *
     * @return list<Machines>
     */
    public function machinesVisibles(User $user, bool $chef): array
    {
        $qb = $this->em->createQueryBuilder()->select('m')->from(Machines::class, 'm')->orderBy('m.nom', 'ASC');
        if (!$chef) {
            $qb->join(AtelierMachine::class, 'a', 'WITH', 'a.machine = m')->join('a.operateurs', 'u')->where('u = :u')->setParameter('u', $user);
        }

        return $qb->getQuery()->getResult();
    }

    public function atelier(Machines $machine): AtelierMachine
    {
        $atelier = $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine]);
        if (null === $atelier) {
            $atelier = new AtelierMachine($machine);
            $this->em->persist($atelier);
        }

        return $atelier;
    }

    /**
     * @param list<Machines> $machines
     *
     * @return array<int, AtelierMachine>
     */
    private function ateliers(array $machines): array
    {
        if ([] === $machines) {
            return [];
        }
        $parMachine = [];
        foreach ($this->em->getRepository(AtelierMachine::class)->findBy(['machine' => $machines]) as $a) {
            $parMachine[(int) $a->getMachine()->getId()] = $a;
        }

        return $parMachine;
    }

    /** Prénom d'un membre du personnel (sinon son identifiant). */
    public static function prenom(?User $user): ?string
    {
        if (null === $user) {
            return null;
        }

        return trim((string) $user->getEmploye()?->getPrenom()) ?: $user->getUserIdentifier();
    }

    /** « Solvant grand format · 3,20 m ». */
    public static function sousTitre(Machines $machine): string
    {
        $largeur = (float) ($machine->getLargeurImpression() ?? 0);

        return trim(implode(' · ', array_filter([$machine->getTypeMachine(), $largeur >= 0.5 ? number_format($largeur, 2, ',', ' ').' m' : null])));
    }

    // ------------------------------------------------------------------
    // File d'attente d'une machine (AT02), base de tous les écrans
    // ------------------------------------------------------------------

    /**
     * Les travaux à faire sur une machine, dans l'ordre de passage, avec
     * leur créneau estimé, leur retard et leur état.
     *
     * @return list<array<string, mixed>>
     */
    public function file(Machines $machine, \DateTimeImmutable $maintenant): array
    {
        $id = (int) $machine->getId();
        if (isset($this->files[$id])) {
            return $this->files[$id];
        }
        /** @var list<OrdreProduction> $ordres */
        $ordres = $this->em->createQueryBuilder()
            ->select('o', 'd', 'c', 'cli', 'cp', 'p')->from(OrdreProduction::class, 'o')
            ->join('o.commandeDetail', 'd')->join('d.commande', 'c')->leftJoin('c.clients', 'cli')
            ->leftJoin('o.controlePrePresse', 'cp')->leftJoin('d.produit', 'p')
            ->where('o.machine = :m')->andWhere('o.statut IN (:actifs)')
            ->setParameter('m', $machine)->setParameter('actifs', self::ACTIFS)
            ->orderBy('o.creeLe', 'ASC')
            ->getQuery()->getResult();

        $matieres = $this->matieres(array_map(static fn (OrdreProduction $o): ?CommandesDetails => $o->getCommandeDetail(), $ordres));
        $travaux = [];
        foreach ($ordres as $o) {
            $promis = AvancementOrdre::datePromise($o);
            $bloque = self::bloque($o);
            $travaux[(int) $o->getId()] = [
                'ordre' => $o,
                'enCours' => !$o->estADemarrer(),
                'bloque' => null !== $bloque,
                'raisonBlocage' => $bloque,
                'promis' => $promis,
                'retard' => $o->estADemarrer() && AvancementOrdre::retardMinutes($promis, null, $maintenant) > 0,
                'matiere' => $matieres[(int) $o->getCommandeDetail()?->getId()] ?? null,
            ];
        }

        // Ordre choisi par le chef (glisser ou « Réordonner automatiquement »), puis les nouveaux dans l'ordre automatique.
        $choisi = $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine])?->getOrdreFile() ?? [];
        $automatique = AvancementOrdre::ordreAutomatique(array_values(array_map(static fn (array $t): array => ['id' => (int) $t['ordre']->getId()] + $t, $travaux)));
        $enCours = array_values(array_filter($automatique, static fn (int $i): bool => $travaux[$i]['enCours']));
        $rangs = array_values(array_unique([...$enCours, ...array_values(array_filter($choisi, static fn (int $i): bool => isset($travaux[$i]))), ...$automatique]));

        // Le travail en cours finit à sa fin estimée ; les suivants s'enchaînent après, sur les heures d'ouverture.
        $depart = $maintenant;
        $place = [];
        $creneaux = [];
        foreach ($rangs as $n => $i) {
            $o = $travaux[$i]['ordre'];
            $avancement = AvancementOrdre::avancement($o, $maintenant);
            if ($o->estADemarrer()) {
                $place[$n] = ['duree' => $avancement['duree'], 'fixe' => $o->getDebutPrevuLe()];
            } else {
                // Sur un rouleau en impression : la machine se libère à la fin de l'impression (PD04), pas après le séchage.
                $fin = self::finSurRouleau($o, $maintenant) ?? $avancement['finEstimee'] ?? $maintenant;
                $creneaux[$n] = ['debut' => $o->getDemarreLe() ?? $maintenant, 'fin' => $fin];
                $depart = max($depart, $fin);
            }
        }
        foreach (array_combine(array_keys($place), AvancementOrdre::placer(array_values($place), $depart)) ?: [] as $n => $creneau) {
            $creneaux[$n] = $creneau;
        }

        $file = [];
        foreach ($rangs as $n => $i) {
            $t = $travaux[$i];
            $o = $t['ordre'];
            $ligne = $o->getCommandeDetail();
            $avancement = AvancementOrdre::avancement($o, $maintenant);
            $debut = $o->estADemarrer() ? $creneaux[$n]['debut'] : $o->getDemarreLe();
            $fin = $creneaux[$n]['fin'];
            $retard = AvancementOrdre::retardMinutes($t['promis'], $fin, $maintenant);
            $file[] = $t + [
                'id' => $i,
                'numero' => $ligne?->getCommande()?->getNumero() ?? $o->getNumero(),
                'client' => $ligne?->getCommande()?->getClients()?->getNomComplet(),
                'travail' => null !== $ligne ? TableauProduction::titre($ligne) : $o->getNumero(),
                'surface' => AvancementOrdre::surface($o),
                'minutes' => $avancement['duree'],
                'avancement' => $avancement,
                'debut' => $debut,
                'fin' => $fin,
                'retardMinutes' => $retard,
                'statut' => match (true) {
                    $o->estEnCours() => 'en_cours',
                    $o->estEnPause() => 'pause',
                    $t['bloque'] => 'bloque',
                    $retard > 0 => 'retard',
                    default => 'a_suivre',
                },
                'note' => self::noteFichier($o),
                'operateur' => self::prenom($o->getOperateur()),
            ];
        }

        return $this->files[$id] = $file;
    }

    /** Fin d'impression du rouleau en cours qui porte l'ordre (ProgressionRouleau), sinon null. */
    public static function finSurRouleau(OrdreProduction $ordre, \DateTimeImmutable $maintenant): ?\DateTimeImmutable
    {
        $tirage = $ordre->getTirage();
        if (null === $tirage || !$tirage->estLance()) {
            return null;
        }
        $fin = ProgressionRouleau::duTirage($tirage, $maintenant)['fin'] ?? null;

        return null !== $fin ? max($fin, $maintenant) : null;
    }

    /** Raison du blocage : fichier ou BAT pas prêt (contrôle prépresse ni validé ni envoyé). */
    public static function bloque(OrdreProduction $ordre): ?string
    {
        if (!$ordre->estADemarrer()) {
            return null;
        }
        $controle = $ordre->getControlePrePresse();
        if (null === $controle) {
            return 'contrôle du fichier à faire';
        }

        return match ($controle->getStatut()) {
            ControlePrePresse::STATUT_VALIDE, ControlePrePresse::STATUT_ENVOYE_PRODUCTION => null,
            ControlePrePresse::STATUT_CORRECTION_REQUISE => 'fichier à corriger · attente client',
            ControlePrePresse::STATUT_EN_ATTENTE_BAT => 'BAT chez le client',
            ControlePrePresse::STATUT_REJETE => 'fichier refusé',
            default => 'fichier en contrôle',
        };
    }

    /** « BAT validé 17/10 », « fichiers liés ». */
    private static function noteFichier(OrdreProduction $ordre): ?string
    {
        if (null !== ($raison = self::bloque($ordre))) {
            return $raison;
        }
        $valide = $ordre->getControlePrePresse()?->getValideLe();

        return null !== $valide ? 'BAT validé '.$valide->format('d/m') : 'fichiers prêts';
    }

    /**
     * Matière de chaque ligne : le support de la nomenclature du produit
     * (famille Supports), sinon le support choisi sur la ligne.
     *
     * @param list<?CommandesDetails> $lignes
     *
     * @return array<int, string>
     */
    private function matieres(array $lignes): array
    {
        $produits = [];
        foreach ($lignes as $l) {
            if (null !== $l?->getProduit()) {
                $produits[(int) $l->getProduit()->getId()] = $l->getProduit();
            }
        }
        $parProduit = [];
        if ([] !== $produits) {
            /** @var list<ProduitArticleStock> $nomenclature */
            $nomenclature = $this->em->createQueryBuilder()->select('n', 'a', 'f')->from(ProduitArticleStock::class, 'n')
                ->join('n.article', 'a')->leftJoin('a.famille', 'f')
                ->where('n.produit IN (:p)')->andWhere('n.actif = true')->setParameter('p', array_values($produits))
                ->orderBy('n.ordre', 'ASC')->getQuery()->getResult();
            foreach ($nomenclature as $n) {
                $article = $n->getArticle();
                if (null !== $article && 'supports' === mb_strtolower((string) $article->getFamille()?->getNom())) {
                    $parProduit[(int) $n->getProduit()?->getId()] ??= self::nomCourt((string) $article->getDesignation());
                }
            }
        }
        $matieres = [];
        foreach ($lignes as $l) {
            if (null === $l) {
                continue;
            }
            $m = $parProduit[(int) $l->getProduit()?->getId()] ?? $l->getSupport()?->getNom();
            if (null !== $m && '' !== $m) {
                $matieres[(int) $l->getId()] = $m;
            }
        }

        return $matieres;
    }

    /** « Vinyle blanc brillant · 1,52 m » reste tel quel ; on retire seulement les blancs en trop. */
    private static function nomCourt(string $designation): string
    {
        return trim(preg_replace('/\s+/u', ' ', $designation) ?? $designation);
    }

    /**
     * Totaux d'une file : surface, temps, fin estimée, changements de rouleau.
     *
     * @param list<array<string, mixed>> $file
     *
     * @return array{surface: float, minutes: int, fin: ?\DateTimeImmutable, rouleaux: int, retards: int, bloques: int}
     */
    public static function totaux(array $file): array
    {
        $surface = 0.0;
        $minutes = 0;
        $fin = null;
        foreach ($file as $t) {
            $surface += (float) ($t['surface'] ?? 0);
            $minutes += $t['statut'] === 'bloque' ? 0 : ($t['enCours'] ? $t['avancement']['restantes'] : $t['minutes']);
            if ('bloque' !== $t['statut'] && (null === $fin || $t['fin'] > $fin)) {
                $fin = $t['fin'];
            }
        }

        return [
            'surface' => round($surface, 1),
            'minutes' => $minutes,
            'fin' => $fin,
            'rouleaux' => AvancementOrdre::changementsRouleau(array_column(array_filter($file, static fn (array $t): bool => 'bloque' !== $t['statut']), 'matiere')),
            'retards' => \count(array_filter($file, static fn (array $t): bool => $t['retardMinutes'] > 0 && 'bloque' !== $t['statut'])),
            'bloques' => \count(array_filter($file, static fn (array $t): bool => 'bloque' === $t['statut'])),
        ];
    }

    /**
     * Consommables d'une machine : ses encres (niveau saisi et stock) et les
     * matières de sa file (stock restant), avec l'état du seuil.
     *
     * @param list<array<string, mixed>> $file
     *
     * @return list<array{libelle: string, valeur: string, etat: string}>
     */
    public function consommables(Machines $machine, array $file): array
    {
        $lignes = [];
        $noms = array_values(array_unique(array_filter(array_column($file, 'matiere'))));
        $articles = $this->em->getRepository(Articles::class)->findBy(['actif' => true], ['designation' => 'ASC']);
        foreach ($articles as $a) {
            $designation = self::nomCourt((string) $a->getDesignation());
            if (\in_array($designation, $noms, true)) {
                $lignes[] = ['libelle' => $designation, 'valeur' => self::quantite($a), 'etat' => self::etatStock($a)];
            }
        }
        $niveaux = $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine])?->getNiveauxEncre() ?? [];
        foreach ($niveaux as $code => $pct) {
            $lignes[] = ['libelle' => 'Encre '.mb_strtolower(AtelierMachine::ENCRES[$code] ?? $code), 'valeur' => $pct.' %', 'etat' => $pct < self::SEUIL_ENCRE ? 'bas' : ($pct < 40 ? 'bientot' : 'ok')];
        }
        foreach ($articles as $a) {
            if ($a->getMachine() === $machine && 'encres' === mb_strtolower((string) $a->getFamille()?->getNom()) && [] === $niveaux) {
                $lignes[] = ['libelle' => self::nomCourt((string) $a->getDesignation()), 'valeur' => self::quantite($a).' en stock', 'etat' => self::etatStock($a)];
            }
        }

        return $lignes;
    }

    private static function quantite(Articles $a): string
    {
        $stock = $a->getStock();

        return number_format($stock, $stock < 10 && floor($stock) != $stock ? 1 : 0, ',', ' ').' '.$a->getUniteCourte();
    }

    private static function etatStock(Articles $a): string
    {
        return match (true) {
            $a->getStockMin() > 0 && $a->getStock() <= $a->getStockMin() => 'bas',
            $a->getStockMin() > 0 && $a->getStock() <= 1.5 * $a->getStockMin() => 'bientot',
            default => 'ok',
        };
    }

    /**
     * L'opérateur de la machine : le premier affecté, son arrivée pointée
     * aujourd'hui et le nombre de machines qu'il tient.
     *
     * @return array{user: User, prenom: string, nom: string, pointe: ?\DateTimeImmutable, machines: int}|null
     */
    public function operateur(Machines $machine, \DateTimeImmutable $maintenant): ?array
    {
        $atelier = $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine]);
        $user = $atelier?->getOperateurs()->first() ?: null;
        if (!$user instanceof User) {
            return null;
        }
        $pointage = $this->em->createQueryBuilder()->select('p')->from(Pointage::class, 'p')
            ->where('p.utilisateur = :u AND p.arriveeLe >= :jour')->setParameter('u', $user)->setParameter('jour', $maintenant->setTime(0, 0))
            ->orderBy('p.arriveeLe', 'ASC')->setMaxResults(1)->getQuery()->getOneOrNullResult();
        $machines = (int) $this->em->createQueryBuilder()->select('COUNT(a.id)')->from(AtelierMachine::class, 'a')->join('a.operateurs', 'u')->where('u = :u')->setParameter('u', $user)->getQuery()->getSingleScalarResult();

        return [
            'user' => $user,
            'prenom' => (string) self::prenom($user),
            'nom' => trim((string) $user->getEmploye()?->getNomComplet()) ?: $user->getUserIdentifier(),
            'pointe' => $pointage?->getArriveeLe(),
            'machines' => $machines,
        ];
    }

    // ------------------------------------------------------------------
    // Vue en direct (AT01)
    // ------------------------------------------------------------------

    /**
     * @param list<Machines> $machines
     *
     * @return array<string, mixed>
     */
    public function vueDirecte(array $machines, \DateTimeImmutable $maintenant): array
    {
        $ateliers = $this->ateliers($machines);
        $incidents = $this->incidentsOuverts($machines);
        $maintenances = $this->maintenancesEnCours($machines, $maintenant);
        $finJour = $maintenant->setTime(AvancementOrdre::HEURE_FERMETURE, 0);

        $cartes = [];
        $retards = [];
        $restants = 0;
        foreach ($machines as $machine) {
            $id = (int) $machine->getId();
            $file = $this->file($machine, $maintenant);
            $courant = null;
            foreach ($file as $t) {
                if ($t['enCours']) {
                    $courant = $t;
                    break;
                }
            }
            $prochain = null;
            foreach ($file as $t) {
                if (!$t['enCours'] && 'bloque' !== $t['statut']) {
                    $prochain = $t;
                    break;
                }
            }
            $panne = null;
            $surveille = null;
            foreach ($incidents[$id] ?? [] as $incident) {
                if ($incident->estArret()) {
                    $panne ??= $incident;
                } else {
                    $surveille ??= $incident;
                }
            }
            $etat = match (true) {
                null !== $panne => 'panne',
                isset($maintenances[$id]) => 'maintenance',
                'en_cours' === ($courant['statut'] ?? null) => 'production',
                'pause' === ($courant['statut'] ?? null) => 'pause',
                null !== $prochain => 'attente',
                default => 'libre',
            };
            foreach ($file as $t) {
                if ('bloque' !== $t['statut'] && $t['fin'] <= $finJour) {
                    ++$restants;
                }
                if ($t['retardMinutes'] > 0 && 'bloque' !== $t['statut']) {
                    $retards[] = $t + ['machine' => $machine];
                }
            }
            $cartes[] = [
                'machine' => $machine,
                'sousTitre' => self::sousTitre($machine),
                'etat' => $etat,
                'courant' => $courant,
                'prochain' => $prochain,
                'panne' => $panne,
                'surveille' => $surveille,
                'maintenance' => $maintenances[$id] ?? null,
                'encres' => ($ateliers[$id] ?? null)?->getNiveauxEncre() ?? [],
                'operateurs' => array_map(self::prenom(...), ($ateliers[$id] ?? null)?->getOperateurs()->toArray() ?? []),
                'aFaire' => \count($file),
            ];
        }

        $termines = $this->terminesEntre($machines, $maintenant->setTime(0, 0), $maintenant);
        $imprime = 0.0;
        $pieces = 0;
        foreach ($termines as $o) {
            $surface = ActionsOrdre::surfacePiece($o);
            $nb = max(0, $o->getQuantiteProduite() - $o->getQuantiteRebut());
            if ((float) ($o->getCommandeDetail()?->getLargeur() ?? 0) > 0) {
                $imprime += $surface * $nb;
            } else {
                $pieces += $nb;
            }
        }
        usort($retards, static fn (array $a, array $b): int => $b['retardMinutes'] <=> $a['retardMinutes']);
        // Comme la maquette : les machines qui produisent d'abord, la panne à la fin (elle est aussi dans les alertes).
        $ordreEtat = ['production' => 0, 'pause' => 1, 'maintenance' => 2, 'attente' => 3, 'libre' => 4, 'panne' => 5];
        usort($cartes, static fn (array $a, array $b): int => [$ordreEtat[$a['etat']], $a['machine']->getNom()] <=> [$ordreEtat[$b['etat']], $b['machine']->getNom()]);
        $compte = array_count_values(array_column($cartes, 'etat'));

        return [
            'cartes' => $cartes,
            'indicateurs' => [
                'actives' => $compte['production'] ?? 0,
                'machines' => \count($machines),
                'pannes' => $compte['panne'] ?? 0,
                'pauses' => ($compte['pause'] ?? 0) + ($compte['maintenance'] ?? 0),
                'termines' => \count($termines),
                'restants' => $restants,
                'imprime' => round($imprime, 1),
                'pieces' => $pieces,
                'retards' => \count($retards),
                'premierRetard' => $retards[0] ?? null,
                'gache' => $this->gacheEntre($machines, $maintenant->modify('monday this week')->setTime(0, 0), $maintenant),
            ],
            'alertes' => $this->alertes($machines, $cartes, $retards, $maintenant),
            'termines' => array_map(fn (OrdreProduction $o): array => [
                'ordre' => $o,
                'numero' => $o->getCommandeDetail()?->getCommande()?->getNumero() ?? $o->getNumero(),
                'travail' => null !== $o->getCommandeDetail() ? TableauProduction::titre($o->getCommandeDetail()) : $o->getNumero(),
                'suite' => self::SUITE_LIGNE[$o->getCommandeDetail()?->getStatutProduction()] ?? 'terminé',
                'operateur' => self::prenom($o->getTerminePar() ?? $o->getOperateur()),
            ], array_reverse($termines)),
        ];
    }

    /**
     * @param list<Machines> $machines
     *
     * @return list<OrdreProduction>
     */
    private function terminesEntre(array $machines, \DateTimeImmutable $du, \DateTimeImmutable $au): array
    {
        if ([] === $machines) {
            return [];
        }

        return $this->em->createQueryBuilder()->select('o', 'd', 'c')->from(OrdreProduction::class, 'o')
            ->join('o.commandeDetail', 'd')->join('d.commande', 'c')
            ->where('o.machine IN (:m)')->andWhere('o.statut = :t')->andWhere('o.termineLe >= :du AND o.termineLe <= :au')
            ->setParameter('m', $machines)->setParameter('t', OrdreProduction::STATUT_TERMINE)->setParameter('du', $du)->setParameter('au', $au)
            ->orderBy('o.termineLe', 'ASC')->getQuery()->getResult();
    }

    /**
     * Gâche d'une période en % (pièces jetées en m², plus la gâche notée au
     * journal des machines sans pièces jetées sur l'ordre).
     *
     * @param list<Machines> $machines
     */
    public function gacheEntre(array $machines, \DateTimeImmutable $du, \DateTimeImmutable $au): ?float
    {
        $bon = 0.0;
        $gache = 0.0;
        $avecRebut = [];
        foreach ($this->terminesEntre($machines, $du, $au) as $o) {
            $surface = ActionsOrdre::surfacePiece($o);
            if ($surface <= 0) {
                continue;
            }
            $bon += $surface * max(0, $o->getQuantiteProduite() - $o->getQuantiteRebut());
            $gache += $surface * $o->getQuantiteRebut();
            if ($o->getQuantiteRebut() > 0) {
                $avecRebut[(int) $o->getId()] = true;
            }
        }
        if ([] !== $machines) {
            /** @var list<JournalMachine> $journal */
            $journal = $this->em->createQueryBuilder()->select('j')->from(JournalMachine::class, 'j')
                ->where('j.machine IN (:m) AND j.type = :g AND j.le >= :du AND j.le <= :au')
                ->setParameter('m', $machines)->setParameter('g', JournalMachine::GACHE)->setParameter('du', $du)->setParameter('au', $au)
                ->getQuery()->getResult();
            foreach ($journal as $j) {
                if (null === $j->getOrdre() || !isset($avecRebut[(int) $j->getOrdre()->getId()])) {
                    $gache += (float) $j->getQuantite();
                }
            }
        }

        return AvancementOrdre::tauxGache($bon, $gache);
    }

    /**
     * @param list<Machines>             $machines
     * @param list<array<string, mixed>> $cartes
     * @param list<array<string, mixed>> $retards
     *
     * @return list<array{niveau: string, texte: string}>
     */
    private function alertes(array $machines, array $cartes, array $retards, \DateTimeImmutable $maintenant): array
    {
        $alertes = [];
        $articles = [] === $machines ? [] : $this->em->getRepository(Articles::class)->findBy(['actif' => true]);
        foreach ($cartes as $c) {
            $m = $c['machine'];
            if (null !== $c['panne']) {
                $decales = $c['aFaire'];
                $alertes[] = ['niveau' => 'rouge', 'texte' => sprintf('%s en panne : %s%s', $m->getNom(), $decales > 0 ? $decales.' travail'.($decales > 1 ? 'aux' : '').' en attente' : 'aucun travail en attente', null !== $c['panne']->getSuite() ? ' ('.$c['panne']->getSuite().')' : '')];
            } elseif (null !== $c['surveille']) {
                $alertes[] = ['niveau' => 'orange', 'texte' => $m->getNom().' : '.$c['surveille']->getTitre()];
            }
            foreach ($c['encres'] as $code => $pct) {
                if ($pct < self::SEUIL_ENCRE) {
                    $stock = null;
                    foreach ($articles as $a) {
                        if ($a->getMachine() === $m && str_contains(mb_strtolower((string) $a->getDesignation()), self::MOTS_ENCRE[$code] ?? '#')) {
                            $stock = self::quantite($a);
                        }
                    }
                    $alertes[] = ['niveau' => 'orange', 'texte' => sprintf('Encre %s %s à %d %%%s', mb_strtolower(AtelierMachine::ENCRES[$code] ?? $code), $m->getNom(), $pct, null !== $stock ? ' · stock : '.$stock : '')];
                }
            }
        }
        foreach (\array_slice($retards, 0, 3) as $r) {
            $alertes[] = ['niveau' => 'orange', 'texte' => sprintf('%s %s : promis le %s, %s', $r['numero'], mb_strtolower(explode(' × ', (string) $r['travail'])[0]), $r['promis']?->format('d/m') ?? '—', $r['enCours'] ? 'fin estimée '.$r['fin']->format('H:i') : 'impression prévue '.self::moment($r['debut'], $maintenant))];
        }
        $matieres = [];
        foreach ($machines as $m) {
            foreach ($this->file($m, $maintenant) as $t) {
                if (null !== $t['matiere']) {
                    $matieres[$t['matiere']] = true;
                }
            }
        }
        foreach ($articles as $a) {
            $lie = (null !== $a->getMachine() && \in_array($a->getMachine(), $machines, true)) || isset($matieres[self::nomCourt((string) $a->getDesignation())]);
            if ($lie && 'bas' === self::etatStock($a)) {
                $alertes[] = ['niveau' => 'bleu', 'texte' => sprintf('%s : %s restants', self::nomCourt((string) $a->getDesignation()), self::quantite($a))];
            }
        }

        return $alertes;
    }

    /** « 09:50 », « demain 08:00 », « mer. 21/10 08:00 ». */
    public static function moment(?\DateTimeImmutable $date, \DateTimeImmutable $maintenant): string
    {
        if (null === $date) {
            return '—';
        }
        $jours = (int) $maintenant->setTime(0, 0)->diff($date->setTime(0, 0))->format('%r%a');
        $jour = ['', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'][(int) $date->format('N')];

        return match (true) {
            0 === $jours => $date->format('H:i'),
            1 === $jours => 'demain '.$date->format('H:i'),
            default => $jour.' '.$date->format('d/m H:i'),
        };
    }

    /**
     * @param list<Machines> $machines
     *
     * @return array<int, list<IncidentMachine>>
     */
    public function incidentsOuverts(array $machines): array
    {
        if ([] === $machines) {
            return [];
        }
        $parMachine = [];
        /** @var list<IncidentMachine> $liste */
        $liste = $this->em->createQueryBuilder()->select('i')->from(IncidentMachine::class, 'i')
            ->where('i.machine IN (:m)')->andWhere('i.etat != :r')->setParameter('m', $machines)->setParameter('r', IncidentMachine::RESOLU)
            ->orderBy('i.signaleLe', 'DESC')->getQuery()->getResult();
        foreach ($liste as $i) {
            $parMachine[(int) $i->getMachine()->getId()][] = $i;
        }

        return $parMachine;
    }

    /**
     * @param list<Machines> $machines
     *
     * @return array<int, Maintenance>
     */
    private function maintenancesEnCours(array $machines, \DateTimeImmutable $maintenant): array
    {
        if ([] === $machines) {
            return [];
        }
        $en = [];
        /** @var list<Maintenance> $liste */
        $liste = $this->em->createQueryBuilder()->select('m')->from(Maintenance::class, 'm')
            ->where('m.machine IN (:m) AND m.date >= :veille AND m.date <= :maintenant')
            ->setParameter('m', $machines)->setParameter('veille', \DateTime::createFromImmutable($maintenant->modify('-1 day')))->setParameter('maintenant', \DateTime::createFromImmutable($maintenant))
            ->getQuery()->getResult();
        foreach ($liste as $m) {
            $debut = \DateTimeImmutable::createFromInterface($m->getDate());
            $duree = $m->getDureeArretMinutes();
            if (null !== $duree && $duree > 0 && $debut->modify('+'.$duree.' minutes') > $maintenant && null !== $m->getMachine()) {
                $en[(int) $m->getMachine()->getId()] = $m;
            }
        }

        return $en;
    }

    // ------------------------------------------------------------------
    // Planning de la semaine (AT03)
    // ------------------------------------------------------------------

    /**
     * @param list<Machines> $machines
     *
     * @return array<string, mixed>
     */
    public function planning(array $machines, \DateTimeImmutable $lundi, \DateTimeImmutable $maintenant): array
    {
        $lundi = $lundi->modify('monday this week')->setTime(0, 0);
        $jours = [];
        for ($i = 0; $i < 6; ++$i) {
            $jours[] = $lundi->modify('+'.$i.' days');
        }
        $finSemaine = $lundi->modify('+6 days');
        $minutesSemaine = array_sum(array_map(AvancementOrdre::minutesOuvertes(...), $jours));
        $incidents = $this->incidentsOuverts($machines);

        $lignes = [];
        $alertes = [];
        foreach ($machines as $machine) {
            $id = (int) $machine->getId();
            $blocs = array_fill(0, 6, []);
            $minutes = 0;
            $dernier = null;
            $ajouter = static function (array $bloc, \DateTimeImmutable $debut, \DateTimeImmutable $fin) use (&$blocs, &$minutes, $jours): void {
                foreach ($jours as $n => $jour) {
                    $ouverture = $jour->setTime(AvancementOrdre::HEURE_OUVERTURE, 0);
                    $fermeture = $jour->setTime(AvancementOrdre::HEURE_FERMETURE, 0);
                    $d = max($debut, $ouverture);
                    $f = min($fin, $fermeture);
                    if ($f <= $d) {
                        continue;
                    }
                    $m = intdiv($f->getTimestamp() - $d->getTimestamp(), 60);
                    $total = (AvancementOrdre::HEURE_FERMETURE - AvancementOrdre::HEURE_OUVERTURE) * 60;
                    $blocs[$n][] = $bloc + [
                        'debut' => $d, 'fin' => $f, 'minutes' => $m,
                        'gauche' => round(100 * ($d->getTimestamp() - $ouverture->getTimestamp()) / 60 / $total, 2),
                        'largeur' => max(6, round(100 * $m / $total, 2)),
                    ];
                    if (!\in_array($bloc['sorte'], ['panne', 'entretien'], true)) {
                        $minutes += $m;
                    }
                }
            };

            foreach ($this->terminesEntre([$machine], $lundi, $finSemaine) as $o) {
                if (null !== $o->getDemarreLe()) {
                    $ajouter(['sorte' => 'termine', 'ordre' => $o, 'libelle' => $o->getCommandeDetail()?->getCommande()?->getNumero() ?? $o->getNumero(), 'titre' => null !== $o->getCommandeDetail() ? TableauProduction::titre($o->getCommandeDetail()) : '', 'mobile' => false], $o->getDemarreLe(), $o->getTermineLe() ?? $o->getDemarreLe());
                }
            }
            foreach ($this->file($machine, $maintenant) as $t) {
                if ('bloque' === $t['statut'] || $t['fin'] < $lundi || $t['debut'] > $finSemaine) {
                    continue;
                }
                $sorte = match ($t['statut']) {
                    'en_cours', 'pause' => 'en_cours',
                    'retard' => 'retard',
                    default => 'prevu',
                };
                $ajouter(['sorte' => $sorte, 'ordre' => $t['ordre'], 'libelle' => $t['numero'], 'titre' => $t['travail'], 'promis' => $t['promis'], 'mobile' => $t['ordre']->estADemarrer(), 'couleur' => TableauProduction::couleur($machine, $t['ordre']->getCommandeDetail())], $t['debut'] ?? $maintenant, $t['fin']);
                if ($t['ordre']->estADemarrer() && $t['fin'] <= $finSemaine) {
                    $dernier = $t;
                }
            }
            foreach ($incidents[$id] ?? [] as $incident) {
                if ($incident->estArret()) {
                    $ajouter(['sorte' => 'panne', 'ordre' => null, 'libelle' => 'En panne', 'titre' => $incident->getTitre(), 'mobile' => false], max($incident->getSignaleLe(), $lundi), max($maintenant->setTime(AvancementOrdre::HEURE_FERMETURE, 0), $incident->getSignaleLe()));
                }
            }
            /** @var list<Maintenance> $entretiens */
            $entretiens = $this->em->createQueryBuilder()->select('m')->from(Maintenance::class, 'm')
                ->where('m.machine = :m AND m.date >= :du AND m.date < :au')->setParameter('m', $machine)
                ->setParameter('du', \DateTime::createFromImmutable($lundi))->setParameter('au', \DateTime::createFromImmutable($finSemaine))
                ->getQuery()->getResult();
            foreach ($entretiens as $e) {
                $debut = \DateTimeImmutable::createFromInterface($e->getDate());
                if ('00:00' === $debut->format('H:i')) {
                    $debut = $debut->setTime(AvancementOrdre::HEURE_OUVERTURE, 0);
                }
                $ajouter(['sorte' => 'entretien', 'ordre' => null, 'libelle' => 'Entretien', 'titre' => (string) ($e->getDescription() ?: $e->getType()), 'mobile' => false], $debut, $debut->modify('+'.max(30, (int) $e->getDureeArretMinutes()).' minutes'));
            }
            foreach ($blocs as &$jour) {
                usort($jour, static fn (array $a, array $b): int => $a['debut'] <=> $b['debut']);
            }
            unset($jour);

            $charge = AvancementOrdre::charge($minutes, $minutesSemaine);
            $lignes[] = ['machine' => $machine, 'blocs' => $blocs, 'charge' => $charge, 'panne' => array_filter($incidents[$id] ?? [], static fn (IncidentMachine $i): bool => $i->estArret()) !== []];
            if ($charge >= self::SEUIL_CHARGE) {
                $alertes[] = sprintf('%s chargé à %d %% : sous-traiter ou décaler %s ?', $machine->getNom(), $charge, null !== $dernier ? $dernier['numero'] : 'un travail');
            }
        }

        $aujourdhui = $maintenant->setTime(0, 0);
        $indexJour = null;
        foreach ($jours as $n => $j) {
            if ($j == $aujourdhui) {
                $indexJour = $n;
            }
        }
        $ouverture = $maintenant->setTime(AvancementOrdre::HEURE_OUVERTURE, 0);
        $total = (AvancementOrdre::HEURE_FERMETURE - AvancementOrdre::HEURE_OUVERTURE) * 3600;

        return [
            'lundi' => $lundi,
            'jours' => $jours,
            'lignes' => $lignes,
            'alertes' => $alertes,
            'maintenant' => null !== $indexJour ? ['jour' => $indexJour, 'gauche' => max(0, min(100, round(100 * ($maintenant->getTimestamp() - $ouverture->getTimestamp()) / $total, 2)))] : null,
        ];
    }

    // ------------------------------------------------------------------
    // Maintenance et incidents (AT04)
    // ------------------------------------------------------------------

    /**
     * @param list<Machines> $machines
     *
     * @return array<string, mixed>
     */
    public function maintenance(array $machines, \DateTimeImmutable $maintenant): array
    {
        $incidents = [];
        foreach ($this->incidentsOuverts($machines) as $liste) {
            array_push($incidents, ...$liste);
        }
        usort($incidents, static fn (IncidentMachine $a, IncidentMachine $b): int => [$b->estArret(), $b->getSignaleLe()] <=> [$a->estArret(), $a->getSignaleLe()]);

        $debutMois = $maintenant->modify('first day of this month')->setTime(0, 0);
        $arrets = [];
        if ([] !== $machines) {
            /** @var list<IncidentMachine> $duMois */
            $duMois = $this->em->createQueryBuilder()->select('i')->from(IncidentMachine::class, 'i')
                ->where('i.machine IN (:m) AND i.arret = true AND (i.resoluLe IS NULL OR i.resoluLe >= :du)')
                ->setParameter('m', $machines)->setParameter('du', $debutMois)->getQuery()->getResult();
            foreach ($duMois as $i) {
                $fin = $i->getResoluLe() ?? ($i->estArret() ? $maintenant : null);
                if (null === $fin) {
                    continue;
                }
                $minutes = intdiv(max(0, $fin->getTimestamp() - max($i->getSignaleLe(), $debutMois)->getTimestamp()), 60);
                $cle = (int) $i->getMachine()->getId();
                $arrets[$cle] ??= ['machine' => $i->getMachine(), 'minutes' => 0, 'notes' => []];
                $arrets[$cle]['minutes'] += $minutes;
                $arrets[$cle]['notes'][] = $i->estArret() ? (($n = \count($this->file($i->getMachine(), $maintenant))) > 0 ? $n.' travail'.($n > 1 ? 'aux' : '').' en attente' : mb_strtolower($i->getTitre())) : mb_strtolower($i->getTitre());
            }
            /** @var list<Maintenance> $interventions */
            $interventions = $this->em->createQueryBuilder()->select('m')->from(Maintenance::class, 'm')
                ->where('m.machine IN (:m) AND m.date >= :du AND m.date <= :au AND m.dureeArretMinutes > 0')
                ->setParameter('m', $machines)->setParameter('du', \DateTime::createFromImmutable($debutMois))->setParameter('au', \DateTime::createFromImmutable($maintenant))
                ->getQuery()->getResult();
            foreach ($interventions as $m) {
                $cle = (int) $m->getMachine()?->getId();
                $arrets[$cle] ??= ['machine' => $m->getMachine(), 'minutes' => 0, 'notes' => []];
                $arrets[$cle]['minutes'] += (int) $m->getDureeArretMinutes();
                $arrets[$cle]['notes'][] = mb_strtolower((string) ($m->getDescription() ?: $m->getType()));
            }
        }
        foreach ($arrets as &$a) {
            $a['notes'] = implode(', ', array_slice(array_values(array_unique($a['notes'])), 0, 2));
        }
        unset($a);
        usort($arrets, static fn (array $a, array $b): int => $b['minutes'] <=> $a['minutes']);

        /** @var list<EntretienMachine> $taches */
        $taches = [] === $machines ? [] : $this->em->createQueryBuilder()->select('e', 'm')->from(EntretienMachine::class, 'e')->join('e.machine', 'm')
            ->where('e.machine IN (:m) AND e.actif = true')->setParameter('m', $machines)
            ->orderBy('m.nom', 'ASC')->addOrderBy('e.id', 'ASC')->getQuery()->getResult();
        $entretien = [];
        foreach ($taches as $e) {
            $usage = $this->usageDepuis($e, $maintenant);
            $entretien[] = [
                'tache' => $e,
                'usage' => $usage,
                'etat' => AvancementOrdre::etatEntretien($e->getFrequence(), $e->getIntervalle(), $e->getDerniereLe(), $usage, $maintenant),
                'derniere' => self::derniereFois($e, $usage, $maintenant),
            ];
        }

        $ateliers = $this->ateliers($machines);
        $compteurs = [];
        foreach ($machines as $m) {
            $usage = $m->usage();
            if ($usage <= 0) {
                continue;
            }
            $tetes = ($ateliers[(int) $m->getId()] ?? null)?->getTetesPoseesLe();
            $compteurs[] = [
                'machine' => $m,
                'valeur' => number_format($usage, 0, ',', ' ').' '.match ($m->getUnite()) {
                    Machines::UNITE_PAGE => 'pages A4',
                    Machines::UNITE_METRE => 'm imprimés',
                    Machines::UNITE_CARTE => 'cartes',
                    Machines::UNITE_TRANSFERT => 'transferts',
                    default => 'm² imprimés',
                },
                'tetes' => null !== $tetes ? self::age($tetes, $maintenant) : null,
            ];
        }

        return ['incidents' => $incidents, 'arrets' => array_values($arrets), 'entretien' => $entretien, 'compteurs' => $compteurs];
    }

    /** Heures de production ou m² imprimés sur la machine depuis le dernier passage. */
    private function usageDepuis(EntretienMachine $e, \DateTimeImmutable $maintenant): float
    {
        if (!\in_array($e->getFrequence(), [EntretienMachine::HEURES, EntretienMachine::M2], true)) {
            return 0.0;
        }
        $depuis = $e->getDerniereLe() ?? $maintenant->modify('-1 year');
        $total = 0.0;
        foreach ($this->terminesEntre([$e->getMachine()], $depuis, $maintenant) as $o) {
            $total += EntretienMachine::HEURES === $e->getFrequence()
                ? AvancementOrdre::minutesEffectives($o, $maintenant) / 60
                : ActionsOrdre::surfacePiece($o) * $o->getQuantiteProduite();
        }

        return round($total, 1);
    }

    private static function derniereFois(EntretienMachine $e, float $usage, \DateTimeImmutable $maintenant): string
    {
        $le = $e->getDerniereLe();
        if (null === $le) {
            return 'jamais';
        }
        if (EntretienMachine::HEURES === $e->getFrequence()) {
            return number_format($usage, 0, ',', ' ').' h';
        }
        if (EntretienMachine::M2 === $e->getFrequence()) {
            return number_format($usage, 0, ',', ' ').' m²';
        }
        $jours = (int) $le->setTime(0, 0)->diff($maintenant->setTime(0, 0))->format('%a');
        $mois = ['', 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

        return match (true) {
            0 === $jours => ((int) $le->format('G') < 12 ? 'ce matin ' : 'aujourd’hui ').$le->format('H:i'),
            1 === $jours => 'hier',
            $jours < 31 => $le->format('d/m'),
            default => $mois[(int) $le->format('n')],
        };
    }

    /** « 14 mois », « 2 ans ». */
    public static function age(\DateTimeImmutable $depuis, \DateTimeImmutable $maintenant): string
    {
        $diff = $depuis->diff($maintenant);
        $mois = $diff->y * 12 + $diff->m;

        return $mois < 1 ? $diff->days.' jours' : $mois.' mois';
    }

    // ------------------------------------------------------------------
    // Écran de l'opérateur (AT05)
    // ------------------------------------------------------------------

    /**
     * Le travail en cours (ou le suivant) d'une machine, les travaux qui
     * restent aujourd'hui et l'étape suivante de la commande.
     *
     * @return array<string, mixed>
     */
    public function operateurMachine(Machines $machine, \DateTimeImmutable $maintenant): array
    {
        $file = $this->file($machine, $maintenant);
        $travail = null;
        foreach ($file as $t) {
            if ($t['enCours']) {
                $travail = $t;
                break;
            }
        }
        $suivant = null;
        foreach ($file as $t) {
            if (!$t['enCours'] && 'bloque' !== $t['statut']) {
                $suivant = $t;
                break;
            }
        }
        $finJour = $maintenant->setTime(AvancementOrdre::HEURE_FERMETURE, 0);

        return [
            'travail' => $travail,
            'suivant' => $suivant,
            'restants' => \count(array_filter($file, static fn (array $t): bool => 'bloque' !== $t['statut'] && $t['fin'] <= $finJour)),
            'file' => $file,
        ];
    }

    /**
     * Fichiers de production de la ligne (sinon ceux du client), les plus récents.
     *
     * @return list<CommandeDetailFichier>
     */
    public static function fichiers(OrdreProduction $ordre): array
    {
        $ligne = $ordre->getCommandeDetail();
        if (null === $ligne) {
            return [];
        }
        $tous = array_values(array_filter($ligne->getFichiers()->toArray(), static fn (CommandeDetailFichier $f): bool => $f->isActif() && null !== $f->getNomOriginal()));
        $production = array_values(array_filter($tous, static fn (CommandeDetailFichier $f): bool => $f->isFichierProduction()));

        return \array_slice([] !== $production ? $production : $tous, 0, 4);
    }

    /**
     * Étape suivante de la commande : un autre travail de la même commande,
     * pas encore fait, sur une autre machine, avec son passage estimé.
     *
     * @return array{ordre: OrdreProduction, machine: ?Machines, debut: ?\DateTimeImmutable, attente: ?string}|null
     */
    public function etapeSuivante(OrdreProduction $ordre, \DateTimeImmutable $maintenant): ?array
    {
        $commande = $ordre->getCommandeDetail()?->getCommande();
        if (null === $commande) {
            return null;
        }
        /** @var list<OrdreProduction> $autres */
        $autres = $this->em->createQueryBuilder()->select('o')->from(OrdreProduction::class, 'o')->join('o.commandeDetail', 'd')
            ->where('d.commande = :c AND o.id != :id AND o.statut = :a')->setParameter('c', $commande)->setParameter('id', $ordre->getId())->setParameter('a', OrdreProduction::STATUT_A_PRODUIRE)
            ->orderBy('o.debutPrevuLe', 'ASC')->getQuery()->getResult();
        foreach ($autres as $autre) {
            if (null !== $autre->getMachine() && $autre->getMachine() !== $ordre->getMachine()) {
                $debut = null;
                foreach ($this->file($autre->getMachine(), $maintenant) as $t) {
                    if ($t['ordre'] === $autre) {
                        $debut = $t['debut'];
                    }
                }

                return ['ordre' => $autre, 'machine' => $autre->getMachine(), 'debut' => $debut, 'attente' => $autre->getInstructions()];
            }
        }

        return null;
    }

    /**
     * Masque en ligne les produits qui n'ont que cette machine (sans machine
     * de secours en état), quand le catalogue sait à quelle machine un
     * produit est lié (Produits::machine, Produits::visibleEnLigne). Sans ces
     * champs, rien n'est fait. Rend le nombre de produits changés.
     */
    public function masquerProduits(Machines $machine, bool $masquer, string $motif): int
    {
        $meta = $this->em->getClassMetadata(\App\Entity\Produits::class);
        if (!$meta->hasAssociation('machine') || !$meta->hasField('visibleEnLigne')) {
            return 0;
        }
        // Un autre arrêt encore ouvert sur la machine : ses produits restent masqués.
        if (!$masquer && [] !== array_filter($this->incidentsOuverts([$machine])[(int) $machine->getId()] ?? [], static fn (IncidentMachine $i): bool => $i->estArret())) {
            return 0;
        }
        $n = 0;
        foreach ($this->em->getRepository(\App\Entity\Produits::class)->findBy(['machine' => $machine]) as $produit) {
            if ($masquer) {
                $secours = $meta->hasAssociation('machineSecours') ? $meta->getFieldValue($produit, 'machineSecours') : null;
                $secoursEnPanne = $secours instanceof Machines && [] !== array_filter($this->incidentsOuverts([$secours])[(int) $secours->getId()] ?? [], static fn (IncidentMachine $i): bool => $i->estArret());
                if ((null === $secours || $secoursEnPanne) && true === $meta->getFieldValue($produit, 'visibleEnLigne')) {
                    $meta->setFieldValue($produit, 'visibleEnLigne', false);
                    if ($meta->hasField('motifHorsLigne')) {
                        $meta->setFieldValue($produit, 'motifHorsLigne', $motif);
                    }
                    ++$n;
                }
            } elseif (false === $meta->getFieldValue($produit, 'visibleEnLigne') && (!$meta->hasField('motifHorsLigne') || $motif === $meta->getFieldValue($produit, 'motifHorsLigne'))) {
                $meta->setFieldValue($produit, 'visibleEnLigne', true);
                if ($meta->hasField('motifHorsLigne')) {
                    $meta->setFieldValue($produit, 'motifHorsLigne', null);
                }
                ++$n;
            }
        }
        return $n;
    }

    public static function masquageDisponible(EntityManagerInterface $em): bool
    {
        $meta = $em->getClassMetadata(\App\Entity\Produits::class);

        return $meta->hasAssociation('machine') && $meta->hasField('visibleEnLigne');
    }
}
