<?php

namespace App\Service\Production;

use App\Entity\Articles;
use App\Entity\AtelierMachine;
use App\Entity\CommandesDetails;
use App\Entity\JournalMachine;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\PieceTirage;
use App\Entity\TirageRouleau;
use App\Entity\User;
use App\Service\Atelier\ActionsOrdre;
use App\Service\Atelier\AvancementOrdre;
use App\Service\Atelier\PosteMachiniste;
use App\Service\Atelier\TempsEstime;
use App\Service\Parametres\Parametres;
use App\Service\TableauProduction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tirages au rouleau (maquettes PD02 et PD03, docs/production-rouleau.md) :
 * les travaux à regrouper, le placement automatique ou manuel, le bilan,
 * l'estimation, les contrôles avant impression et le démarrage. Les règles
 * de calcul sont dans ImpositionRouleau et ControlesTirage (classes pures).
 */
final class TiragesRouleau
{
    /** Au-delà, un ordre ne part pas sur un rouleau (montage PAO à faire). */
    public const PIECES_MAX = 200;
    /** Ordres proposés au plus dans « Nouveau rouleau ». */
    private const LIBRES_MAX = 400;

    /** Couleurs des commandes sur le placement (une par ordre, dans l'ordre d'impression). */
    public const COULEURS = ['#1E4FA3', '#13787A', '#14213D', '#B4232F', '#7C3AED', '#0E7490', '#9A6700', '#475569'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
        private readonly ActionsOrdre $actions,
    ) {
    }

    /**
     * Réglages de la tuile Production (marges en cm, longueur maximale en cm).
     *
     * @return array{marge: float, espace: float, longueurMax: float, seuilEncre: int, sechage: int}
     */
    public function reglages(): array
    {
        $v = fn (string $cle, float $defaut): float => is_numeric($this->parametres->valeur('production', $cle)) ? (float) $this->parametres->valeur('production', $cle) : $defaut;

        return [
            'marge' => max(0.0, $v('marge_cm', 10)),
            'espace' => max(0.0, $v('espace_cm', 4)),
            'longueurMax' => max(100.0, $v('longueur_max_m', 30) * 100),
            'seuilEncre' => (int) $v('seuil_encre_pourcent', 20),
            'sechage' => (int) $v('sechage_min', 45),
        ];
    }

    public function atelier(Machines $machine): ?AtelierMachine
    {
        return $this->em->getRepository(AtelierMachine::class)->findOneBy(['machine' => $machine]);
    }

    /**
     * Machines actives, avec leur réglage d'atelier (dossier, profils),
     * celles qui ont un dossier d'abord.
     *
     * @return list<array{machine: Machines, atelier: ?AtelierMachine}>
     */
    public function machines(): array
    {
        $machines = $this->em->getRepository(Machines::class)->findBy([], ['nom' => 'ASC']);
        $ateliers = [];
        foreach ($this->em->getRepository(AtelierMachine::class)->findAll() as $a) {
            $ateliers[$a->getMachine()->getId()] = $a;
        }
        $liste = [];
        foreach ($machines as $m) {
            if ('actif' !== mb_strtolower((string) $m->getEtat()) && null === ($ateliers[$m->getId()] ?? null)?->getCodeDossier()) {
                continue;
            }
            $liste[] = ['machine' => $m, 'atelier' => $ateliers[$m->getId()] ?? null];
        }
        usort($liste, static fn (array $a, array $b): int => [null === $a['atelier']?->getCodeDossier(), (string) $a['machine']->getNom()] <=> [null === $b['atelier']?->getCodeDossier(), (string) $b['machine']->getNom()]);

        return $liste;
    }

    /** Machine à rouleau : une laize, facturée au m², pas le traceur de découpe. */
    public function estMachineRouleau(Machines $m, ?AtelierMachine $atelier = null): bool
    {
        $atelier ??= $this->atelier($m);

        return null !== $m->getLaizeMetres() && $m->getLaizeMetres() >= 0.3 && !$m->utiliseFeuille()
            && FichierProduction::CODE_DECOUPE !== $atelier?->getCodeDossier()
            && 'actif' === mb_strtolower((string) $m->getEtat());
    }

    /** @return list<Machines> */
    public function machinesRouleau(): array
    {
        $liste = [];
        foreach ($this->machines() as $l) {
            if ($this->estMachineRouleau($l['machine'], $l['atelier'])) {
                $liste[] = $l['machine'];
            }
        }
        usort($liste, static fn (Machines $a, Machines $b): int => [-($a->getLaizeMetres() ?? 0), (string) $a->getNom()] <=> [-($b->getLaizeMetres() ?? 0), (string) $b->getNom()]);

        return $liste;
    }

    /**
     * Ordres qui attendent leur rouleau : à produire, transmis par le
     * prépresse, sur aucun tirage en cours.
     *
     * @return list<OrdreProduction>
     */
    public function ordresLibres(): array
    {
        /** @var list<OrdreProduction> $ordres */
        $ordres = $this->em->createQueryBuilder()
            ->select('o', 'd', 'c')
            ->from(OrdreProduction::class, 'o')
            ->join('o.commandeDetail', 'd')
            ->join('d.commande', 'c')
            ->leftJoin('o.tirage', 't')
            ->where('o.statut = :statut')
            ->andWhere('o.transmisLe IS NOT NULL')
            ->andWhere('t.id IS NULL OR t.statut = :annule')
            ->setParameter('statut', OrdreProduction::STATUT_A_PRODUIRE)
            ->setParameter('annule', TirageRouleau::STATUT_ANNULE)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults(self::LIBRES_MAX)
            ->getQuery()->getResult();

        return array_values(array_filter($ordres, static fn (OrdreProduction $o): bool => null !== self::taillePiece($o)));
    }

    /**
     * Supports des travaux en attente qui passent sur cette machine.
     *
     * @return list<array{code: string, libelle: string, nombre: int}>
     */
    public function supportsProposes(Machines $machine): array
    {
        $supports = [];
        foreach ($this->ordresLibres() as $o) {
            $ligne = $o->getCommandeDetail();
            $code = null !== $ligne ? FichierProduction::codeSupport($ligne) : null;
            if (null === $code || null !== $this->raisonHorsMachine($o, $machine)) {
                continue;
            }
            $supports[$code] ??= ['code' => $code, 'libelle' => FichierProduction::libelleSupport($ligne), 'nombre' => 0];
            ++$supports[$code]['nombre'];
        }
        usort($supports, static fn (array $a, array $b): int => [-$a['nombre'], $a['code']] <=> [-$b['nombre'], $b['code']]);

        return array_values($supports);
    }

    /**
     * Les travaux proposés pour un nouveau rouleau, par urgence, et ceux
     * pré-cochés (jusqu'à la longueur maximale).
     *
     * @return array{ordres: list<OrdreProduction>, preselection: list<int>, laize: float, article: ?Articles}
     */
    public function candidats(Machines $machine, string $code, \DateTimeImmutable $maintenant): array
    {
        $ordres = [];
        foreach ($this->ordresLibres() as $o) {
            $ligne = $o->getCommandeDetail();
            if (null !== $ligne && FichierProduction::codeSupport($ligne) === $code && null === $this->raisonHorsMachine($o, $machine)) {
                $ordres[] = $o;
            }
        }
        $ordres = $this->trierParUrgence($ordres, $maintenant);
        $article = $this->articleRouleau($machine, $code);
        $laize = $this->laize($machine, $article);
        $r = $this->reglages();
        $placement = ImpositionRouleau::placer($laize * 100, $r['marge'], $r['espace'], $this->piecesAPlacer($ordres), $r['longueurMax']);
        $refuses = [];
        foreach ($placement['refusees'] as $ref) {
            $refuses[(int) explode('-', $ref['cle'])[0]] = true;
        }
        $preselection = [];
        foreach ($ordres as $o) {
            if (!isset($refuses[(int) $o->getId()])) {
                $preselection[] = (int) $o->getId();
            }
        }

        return ['ordres' => $ordres, 'preselection' => $preselection, 'laize' => $laize, 'article' => $article];
    }

    /** Pourquoi un ordre ne passe pas sur cette machine (null : il passe). */
    public function raisonHorsMachine(OrdreProduction $o, Machines $machine): ?string
    {
        $taille = self::taillePiece($o);
        if (null === $taille) {
            return 'dimensions inconnues';
        }
        $utile = ($machine->getLaizeMetres() ?? 0) * 100 - 2 * $this->reglages()['marge'];
        if (min($taille) > $utile + 0.001) {
            return \sprintf('trop large pour la laize (%s cm utiles)', ImpositionRouleau::nombre($utile));
        }
        if (max(1, (int) $o->getQuantite()) > self::PIECES_MAX) {
            return 'trop de pièces pour un rouleau (montage PAO)';
        }

        return null;
    }

    /**
     * Largeur × hauteur commandées en cm (largeur en travers), ou null.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function taillePiece(OrdreProduction $o): ?array
    {
        $ligne = $o->getCommandeDetail();
        $l = (float) ($ligne?->getLargeur() ?? 0);
        $h = (float) ($ligne?->getLongueur() ?? 0);

        return $l > 0 && $h > 0 ? [round($l * 100, 1), round($h * 100, 1)] : null;
    }

    /**
     * Urgence : priorité urgente ou haute d'abord, puis l'ordre automatique
     * de l'atelier (retards, date promise).
     *
     * @param list<OrdreProduction> $ordres
     *
     * @return list<OrdreProduction>
     */
    public function trierParUrgence(array $ordres, \DateTimeImmutable $maintenant): array
    {
        $groupes = ['urgent' => [], 'autres' => []];
        foreach ($ordres as $o) {
            $urgent = \in_array($o->getPriorite(), [OrdreProduction::PRIORITE_URGENTE, OrdreProduction::PRIORITE_HAUTE], true);
            $groupes[$urgent ? 'urgent' : 'autres'][] = $o;
        }
        $tries = [];
        foreach ($groupes as $groupe) {
            $parId = [];
            $travaux = [];
            foreach ($groupe as $o) {
                $parId[(int) $o->getId()] = $o;
                $promis = AvancementOrdre::datePromise($o);
                $travaux[] = ['id' => (int) $o->getId(), 'retard' => AvancementOrdre::retardMinutes($promis, null, $maintenant) > 0, 'promis' => $promis];
            }
            foreach (AvancementOrdre::ordreAutomatique($travaux) as $id) {
                $tries[] = $parId[$id];
            }
        }

        return $tries;
    }

    /** L'article rouleau du support (même code), le plus large qui tient sur la machine. */
    public function articleRouleau(Machines $machine, string $code): ?Articles
    {
        $laizeMachine = $machine->getLaizeMetres() ?? 0;
        $meilleur = null;
        /** @var list<Articles> $articles */
        $articles = $this->em->createQueryBuilder()->select('a')->from(Articles::class, 'a')
            ->where('a.laize IS NOT NULL')->andWhere('a.laize > 0')->andWhere('a.actif = true')
            ->getQuery()->getResult();
        foreach ($articles as $a) {
            if (NomFichierProduction::code((string) $a->getDesignation()) !== $code || (float) $a->getLaize() > $laizeMachine + 0.001) {
                continue;
            }
            if (null === $meilleur || (float) $a->getLaize() > (float) $meilleur->getLaize()) {
                $meilleur = $a;
            }
        }

        return $meilleur;
    }

    /** Laize du rouleau : celle de l'article (au plus celle de la machine), sinon celle de la machine. */
    public function laize(Machines $machine, ?Articles $article): float
    {
        $machineM = (float) ($machine->getLaizeMetres() ?? 0);
        $articleM = (float) ($article?->getLaize() ?? 0);

        return $articleM > 0 ? min($articleM, $machineM > 0 ? $machineM : $articleM) : $machineM;
    }

    /**
     * Crée le tirage avec ces ordres et place les pièces. Ne flushe pas.
     *
     * @param list<OrdreProduction> $ordres
     */
    public function creer(Machines $machine, string $code, array $ordres, ?User $par, \DateTimeImmutable $maintenant): TirageRouleau
    {
        if ([] === $ordres) {
            throw new \DomainException('Cochez au moins un travail.');
        }
        foreach ($ordres as $o) {
            if (null !== $o->getTirage() && !$o->getTirage()->estAnnule()) {
                throw new \DomainException(\sprintf('%s est déjà sur le rouleau %s.', $o->getNumero(), $o->getTirage()->getNumero()));
            }
            if (!$o->estADemarrer() || null === $o->getTransmisLe()) {
                throw new \DomainException(\sprintf('%s n\'attend plus son impression.', $o->getNumero()));
            }
            $ligne = $o->getCommandeDetail();
            if (null === $ligne || FichierProduction::codeSupport($ligne) !== $code) {
                throw new \DomainException(\sprintf('%s n\'est pas sur ce support.', $o->getNumero()));
            }
            $raison = $this->raisonHorsMachine($o, $machine);
            if (null !== $raison) {
                throw new \DomainException(\sprintf('%s : %s.', $o->getNumero(), $raison));
            }
        }
        $article = $this->articleRouleau($machine, $code);
        $r = $this->reglages();
        $libelle = null !== $article ? (string) $article->getDesignation() : FichierProduction::libelleSupport($ordres[0]->getCommandeDetail());
        $tirage = new TirageRouleau($this->numeroSuivant($maintenant), $machine, '' !== $libelle ? $libelle : $code, $code, $this->laize($machine, $article), $r['marge'], $r['espace'], $par, $maintenant);
        $tirage->setSupport($article);
        foreach ($ordres as $o) {
            for ($i = 1; $i <= max(1, (int) $o->getQuantite()); ++$i) {
                new PieceTirage($tirage, $o, $i);
            }
            $o->setTirage($tirage);
            if (null === $o->getNomFichierProduction()) {
                FichierProduction::preparer($o);
            }
        }
        $this->em->persist($tirage);
        $this->placerAuto($tirage, $maintenant);

        return $tirage;
    }

    /** R-MMJJ-NN : le suivant pour ce jour (les numéros restent uniques d'une année à l'autre). */
    public function numeroSuivant(\DateTimeImmutable $jour): string
    {
        $prefixe = 'R-'.$jour->format('md').'-';
        $numeros = $this->em->createQueryBuilder()->select('t.numero')->from(TirageRouleau::class, 't')
            ->where('t.numero LIKE :p')->setParameter('p', $prefixe.'%')
            ->getQuery()->getSingleColumnResult();

        return TirageRouleau::numeroSuivant($jour, array_map('strval', $numeros));
    }

    /**
     * Placement automatique, par urgence ou dans l'ordre donné (ids des
     * ordres). Un ordre dont une pièce ne tient pas sort du tirage. Rend
     * les messages des ordres retirés. Ne flushe pas.
     *
     * @param list<int>|null $ordreIds
     *
     * @return list<string>
     */
    public function placerAuto(TirageRouleau $tirage, \DateTimeImmutable $maintenant, ?array $ordreIds = null): array
    {
        if (!$tirage->estModifiable()) {
            throw new \DomainException('Ce rouleau est en impression : le placement ne change plus.');
        }
        $ordres = $tirage->ordres();
        if (null !== $ordreIds) {
            $rang = array_flip(array_values($ordreIds));
            usort($ordres, static fn (OrdreProduction $a, OrdreProduction $b): int => ($rang[(int) $a->getId()] ?? \PHP_INT_MAX) <=> ($rang[(int) $b->getId()] ?? \PHP_INT_MAX));
        } else {
            $ordres = $this->trierParUrgence($ordres, $maintenant);
        }
        $r = $this->reglages();
        $retires = [];
        do {
            $placement = ImpositionRouleau::placer($tirage->getLaizeCm(), $tirage->getMargeCm(), $tirage->getEspaceCm(), $this->piecesAPlacer($ordres), $r['longueurMax']);
            $refuses = [];
            foreach ($placement['refusees'] as $ref) {
                $refuses[(int) explode('-', $ref['cle'])[0]] ??= $ref['raison'];
            }
            if ([] !== $refuses) {
                $ordres = array_values(array_filter($ordres, function (OrdreProduction $o) use ($refuses, $tirage, &$retires): bool {
                    if (!isset($refuses[(int) $o->getId()])) {
                        return true;
                    }
                    $retires[] = \sprintf('%s retiré du rouleau : %s.', $o->getNumero(), $refuses[(int) $o->getId()]);
                    $this->retirerOrdre($tirage, $o);

                    return false;
                }));
            }
        } while ([] !== $refuses && [] !== $ordres);

        $parCle = [];
        foreach ($tirage->getPieces() as $p) {
            $parCle[$p->cle()] = $p;
        }
        $ordreImpression = array_flip(ImpositionRouleau::ordreImpression($placement['pieces']));
        foreach ($placement['pieces'] as $pl) {
            ($parCle[$pl['cle']] ?? null)?->placer($ordreImpression[$pl['cle']] + 1, $pl['x'], $pl['y'], $pl['largeur'], $pl['hauteur'], $pl['tourne']);
        }
        $tirage->setMode(TirageRouleau::MODE_AUTO);
        $this->enregistrerBilan($tirage, $maintenant);

        return $retires;
    }

    /**
     * Placement manuel : positions des pièces (cle => [x, y, tourne]),
     * contrôlées (largeur utile, chevauchements, longueur maximale). Rien
     * n'est changé s'il y a un problème. Ne flushe pas.
     *
     * @param array<string, array{x: mixed, y: mixed, tourne?: mixed}> $positions
     *
     * @return list<string> problèmes (vide : enregistré)
     */
    public function placerManuel(TirageRouleau $tirage, array $positions, \DateTimeImmutable $maintenant): array
    {
        if (!$tirage->estModifiable()) {
            return ['Ce rouleau est en impression : le placement ne change plus.'];
        }
        $pieces = [];
        $parCle = [];
        foreach ($tirage->getPieces() as $p) {
            $cle = $p->cle();
            $parCle[$cle] = $p;
            $pos = $positions[$cle] ?? null;
            $taille = self::taillePiece($p->getOrdre()) ?? [$p->getLargeurCm(), $p->getHauteurCm()];
            $tourne = \is_array($pos) ? filter_var($pos['tourne'] ?? $p->isTourne(), \FILTER_VALIDATE_BOOL) : $p->isTourne();
            [$l, $h] = $tourne ? [$taille[1], $taille[0]] : $taille;
            $x = \is_array($pos) && is_numeric($pos['x'] ?? null) ? round((float) $pos['x']) : $p->getXCm();
            $y = \is_array($pos) && is_numeric($pos['y'] ?? null) ? round((float) $pos['y']) : $p->getYCm();
            $pieces[] = ['cle' => $cle, 'x' => (float) $x, 'y' => (float) $y, 'largeur' => (float) $l, 'hauteur' => (float) $h, 'tourne' => $tourne];
        }
        $problemes = ImpositionRouleau::problemes($tirage->getLaizeCm(), $tirage->getMargeCm(), $pieces, $this->reglages()['longueurMax']);
        if ([] !== $problemes) {
            $libelles = $this->libellesPieces($tirage);

            return array_map(static fn (string $texte): string => strtr($texte, $libelles), $problemes);
        }
        // Le rouleau commence à la première pièce.
        $haut = [] !== $pieces ? min(array_column($pieces, 'y')) : 0.0;
        $rang = array_flip(ImpositionRouleau::ordreImpression($pieces));
        foreach ($pieces as $pl) {
            $parCle[$pl['cle']]->placer($rang[$pl['cle']] + 1, $pl['x'], $pl['y'] - $haut, $pl['largeur'], $pl['hauteur'], $pl['tourne']);
        }
        $tirage->setMode(TirageRouleau::MODE_MANUEL);
        $this->enregistrerBilan($tirage, $maintenant);

        return [];
    }

    /** « 412-1 » → « CMD-000430 1/1 » dans les messages. */
    private function libellesPieces(TirageRouleau $tirage): array
    {
        $libelles = [];
        foreach ($tirage->getPieces() as $p) {
            $libelles[$p->cle()] = $this->libellePiece($p);
        }
        uksort($libelles, static fn (string $a, string $b): int => \strlen($b) <=> \strlen($a));

        return $libelles;
    }

    public function libellePiece(PieceTirage $p): string
    {
        $numero = NomFichierProduction::numeroCourt((string) $p->getOrdre()->getCommandeDetail()?->getCommande()?->getNumero());

        return \sprintf('%s %d/%d', '' !== $numero ? $numero : $p->getOrdre()->getNumero(), $p->getExemplaire(), max(1, (int) $p->getOrdre()->getQuantite()));
    }

    /** Retire un ordre (toutes ses pièces) d'un tirage modifiable. Ne flushe pas. */
    public function retirerOrdre(TirageRouleau $tirage, OrdreProduction $o): void
    {
        foreach ($tirage->getPieces()->toArray() as $p) {
            if ($p->getOrdre() === $o) {
                $tirage->retirerPiece($p);
            }
        }
        if ($o->getTirage() === $tirage) {
            $o->setTirage(null);
        }
    }

    /** Annule un tirage pas encore imprimé : ses ordres redeviennent libres. Ne flushe pas. */
    public function annuler(TirageRouleau $tirage, \DateTimeImmutable $maintenant): void
    {
        $tirage->annuler($maintenant);
        foreach ($tirage->ordres() as $o) {
            if ($o->getTirage() === $tirage) {
                $o->setTirage(null);
            }
        }
    }

    public function enregistrerBilan(TirageRouleau $tirage, \DateTimeImmutable $maintenant): void
    {
        $pieces = array_map(static fn (PieceTirage $p): array => ['y' => $p->getYCm(), 'largeur' => $p->getLargeurCm(), 'hauteur' => $p->getHauteurCm()], $tirage->getPieces()->toArray());
        $bilan = ImpositionRouleau::bilan($tirage->getLaizeCm(), $pieces);
        $profil = $this->profil($tirage);
        $e = ImpositionRouleau::estimation($bilan['imprime'], $profil['vitesseM2h'] ?? $tirage->getMachine()->getVitesseM2Heure(), $profil['sechageMin'] ?? $this->reglages()['sechage'], $profil['encreMlM2'] ?? null);
        $tirage->enregistrerBilan($bilan, $e['impressionMin'], $e['sechageMin'], $e['encreMl'], $maintenant);
    }

    /**
     * Pièces à donner au placement, ordre par ordre (exemplaires à la suite).
     *
     * @param list<OrdreProduction> $ordres
     *
     * @return list<array{cle: string, largeur: float, hauteur: float}>
     */
    private function piecesAPlacer(array $ordres): array
    {
        $pieces = [];
        foreach ($ordres as $o) {
            $taille = self::taillePiece($o);
            for ($i = 1; $i <= max(1, min(self::PIECES_MAX, (int) $o->getQuantite())); ++$i) {
                $pieces[] = ['cle' => $o->getId().'-'.$i, 'largeur' => $taille[0] ?? 0.0, 'hauteur' => $taille[1] ?? 0.0];
            }
        }

        return $pieces;
    }

    /**
     * Profil d'impression du tirage (support de l'article, son code, un mot
     * du nom ; sinon le premier de la machine ; sinon aucun).
     *
     * @return array{support: string, profilIcc: string, mode: string, vitesseM2h: ?float, encreMlM2: ?float, chauffeC: ?int, sechageMin: ?int, miroir: bool}|null
     */
    public function profil(TirageRouleau $tirage): ?array
    {
        $atelier = $this->atelier($tirage->getMachine());

        return null !== $atelier ? ProfilsImpression::choisir($atelier->getProfils(), $tirage->getSupport()?->getId(), $tirage->getLibelleSupport(), $tirage->getCodeSupport()) : null;
    }

    /** Coût matière + encre (pour ROLE_COUT_MARGE seulement), ou null. */
    public function cout(TirageRouleau $tirage): ?int
    {
        return ImpositionRouleau::cout($tirage->getConsommeM2(), $tirage->getImprimeM2(), $tirage->getSupport()?->getCoutUnitaireStock(), $tirage->getMachine()->getCoutEncreM2());
    }

    /**
     * « Pourquoi la 3,20 m ? » : la pièce la plus large, les machines qui la
     * prennent d'un seul morceau dans le sens commandé, et tournée.
     */
    public function pourquoi(TirageRouleau $tirage): ?string
    {
        $large = null;
        foreach ($tirage->ordres() as $o) {
            $t = self::taillePiece($o);
            if (null !== $t && (null === $large || $t[0] > $large['taille'][0])) {
                $large = ['ordre' => $o, 'taille' => $t];
            }
        }
        if (null === $large) {
            return null;
        }
        $marge = 2 * $tirage->getMargeCm() / 100;
        $machines = array_map(static fn (Machines $m): array => ['id' => (int) $m->getId(), 'nom' => (string) $m->getNom(), 'largeur' => null !== $m->getLaizeMetres() ? $m->getLaizeMetres() - $marge : null], $this->machinesRouleau());
        [$l, $h] = [$large['taille'][0] / 100, $large['taille'][1] / 100];
        $droit = array_column(array_filter(TempsEstime::machinesPossibles($l, $l, $machines), static fn (array $m): bool => $m['possible']), 'nom');
        $tournee = array_values(array_diff(array_column(array_filter(TempsEstime::machinesPossibles($l, $h, $machines), static fn (array $m): bool => $m['possible']), 'nom'), $droit));
        $titre = mb_strtolower(self::produitCourt($large['ordre']->getCommandeDetail()));
        $texte = \sprintf('La pièce la plus large (%s %s × %s) fait %s m dans le sens commandé', $titre, ImpositionRouleau::nombre($large['taille'][0]), ImpositionRouleau::nombre($large['taille'][1]), ImpositionRouleau::nombre($l));
        $texte .= [] !== $droit ? \sprintf(' : en un seul morceau, sans raccord, elle passe sur %s.', implode(', ', $droit)) : ' : aucune machine ne la prend dans ce sens.';
        if ([] !== $tournee) {
            $texte .= \sprintf(' Tournée, elle passerait aussi sur %s.', implode(', ', $tournee));
        }
        if (\count($tirage->ordres()) > 1) {
            $texte .= ' Les pièces plus étroites complètent la largeur au lieu de partir en chute.';
        }

        return $texte;
    }

    /** « Kakémono », « Bâche » : le premier mot du travail. */
    public static function produitCourt(?CommandesDetails $ligne): string
    {
        $titre = null !== $ligne ? TableauProduction::titre($ligne) : 'Travail';
        $mot = preg_split('/[\s(×·,]+/u', trim($titre))[0] ?? 'Travail';

        return '' !== $mot ? $mot : 'Travail';
    }

    /**
     * Quand le premier travail du rouleau sort sec : début (démarrage ou
     * maintenant) + son temps d'impression + le séchage.
     */
    public function sortSecA(TirageRouleau $tirage, \DateTimeImmutable $maintenant): ?\DateTimeImmutable
    {
        $premier = $tirage->ordres()[0] ?? null;
        $profil = $this->profil($tirage);
        $vitesse = $profil['vitesseM2h'] ?? $tirage->getMachine()->getVitesseM2Heure();
        if (null === $premier || null === $vitesse || $vitesse <= 0) {
            return null;
        }
        $m2 = 0.0;
        foreach ($tirage->getPieces() as $p) {
            if ($p->getOrdre() === $premier) {
                $m2 += $p->getLargeurCm() * $p->getHauteurCm() / 10000;
            }
        }
        $minutes = (int) ceil(60 * $m2 / $vitesse) + ($tirage->getSechageMin() ?? $this->reglages()['sechage']);
        $debut = $tirage->getDemarreLe() ?? $maintenant;

        return $debut->modify('+'.$minutes.' minutes');
    }

    /**
     * Le test de buses du jour sur cette machine (journal, entretien des
     * têtes), ou null.
     *
     * @return array{le: \DateTimeImmutable, bouchees: int, par: User}|null
     */
    public function busesDuJour(Machines $machine, \DateTimeImmutable $maintenant): ?array
    {
        /** @var JournalMachine|null $j */
        $j = $this->em->createQueryBuilder()->select('j')->from(JournalMachine::class, 'j')
            ->where('j.machine = :m')->andWhere('j.type = :t')->andWhere('j.le >= :debut')->andWhere('j.le < :fin')
            ->setParameter('m', $machine)->setParameter('t', JournalMachine::TETES)
            ->setParameter('debut', $maintenant->setTime(0, 0))->setParameter('fin', $maintenant->setTime(0, 0)->modify('+1 day'))
            ->orderBy('j.le', 'DESC')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        if (null === $j) {
            return null;
        }
        $bouchees = preg_match('/(\d+) buses? bouch/u', (string) $j->getNote(), $m) ? (int) $m[1] : 0;

        return ['le' => $j->getLe(), 'bouchees' => $bouchees, 'par' => $j->getOperateur()];
    }

    /** Le test de buses fait, noté au journal de la machine. Ne flushe pas. */
    public function noterTestBuses(Machines $machine, User $par, int $bouchees, \DateTimeImmutable $le): void
    {
        $bouchees = max(0, min(999, $bouchees));
        $this->em->persist(new JournalMachine($machine, $par, JournalMachine::TETES, $le, null, null, \sprintf('Test de buses : %d buse%s bouchée%s', $bouchees, $bouchees > 1 ? 's' : '', $bouchees > 1 ? 's' : '')));
    }

    /**
     * Couleurs sensibles des travaux (remarques de la ligne, du prépresse et
     * du BAT) : « rouge Awa Mode ».
     *
     * @return list<string>
     */
    public function couleurs(TirageRouleau $tirage): array
    {
        $couleurs = [];
        foreach ($tirage->ordres() as $o) {
            $ligne = $o->getCommandeDetail();
            $client = $ligne?->getCommande()?->getClients();
            $qui = trim((string) ($client?->getRaisonSociale() ?: $client?->getNomComplet()));
            $controle = $ligne?->getDernierControlePrePresse();
            $remarques = [$ligne?->getObservation(), $controle?->getObservation(), $o->getInstructions()];
            foreach ($o->getFichiers() as $f) {
                $remarques[] = $f->getRemarqueBat();
            }
            $c = ControlesTirage::couleurSensible($remarques, '' !== $qui ? $qui : $o->getNumero());
            if (null !== $c) {
                $couleurs[] = $c;
            }
        }

        return array_values(array_unique($couleurs));
    }

    /**
     * Les points « Avant d'appuyer sur Imprimer » du tirage.
     *
     * @return list<array{cle: string, libelle: string, detail: string, etat: string}>
     */
    public function controles(TirageRouleau $tirage, \DateTimeImmutable $maintenant): array
    {
        $atelier = $this->atelier($tirage->getMachine());
        $p = $this->profil($tirage);
        $profil = null !== $p ? ['fichier' => $p['profilIcc'], 'mode' => $p['mode'], 'vitesse' => $p['vitesseM2h'], 'chauffe' => $p['chauffeC'], 'sechage' => $p['sechageMin'] ?? $this->reglages()['sechage'], 'miroir' => $p['miroir'] || (bool) $atelier?->isMiroir()] : null;
        $buses = $this->busesDuJour($tirage->getMachine(), $maintenant);
        $support = $tirage->getLibelleSupport().(str_contains(mb_strtolower($tirage->getLibelleSupport()), 'laize') ? '' : ', laize '.ImpositionRouleau::nombre($tirage->getLaizeM()).' m');

        return ControlesTirage::evaluer($support, $tirage->getControles(), $profil, $buses, $atelier?->getNiveauxEncre() ?? [], $this->reglages()['seuilEncre'], $this->couleurs($tirage), $atelier?->getNiveauxEncreLe(), (bool) $atelier?->isMiroir());
    }

    /**
     * « Après l'impression » : séchage, coupe, finitions par type de
     * travail, roulage.
     *
     * @return list<array{titre: string, texte: string}>
     */
    public function apresImpression(TirageRouleau $tirage): array
    {
        $sechage = $tirage->getSechageMin() ?? $this->reglages()['sechage'];
        $consignes = [['titre' => 'Séchage', 'texte' => $sechage.' min minimum avant de rouler ou de poser les œillets']];
        $consignes[] = ['titre' => 'Coupe', 'texte' => 'suivre les repères imprimés dans la marge'];
        $finitions = [];
        foreach ($tirage->ordres() as $o) {
            $noms = $o->getCommandeDetail()?->nomsFinitions() ?? [];
            if ([] !== $noms) {
                $finitions[mb_strtolower(self::produitCourt($o->getCommandeDetail()))][] = implode(' + ', array_map('mb_strtolower', $noms));
            }
        }
        if ([] !== $finitions) {
            $parties = [];
            foreach ($finitions as $produit => $liste) {
                $parties[] = $produit.' : '.implode(' ; ', array_unique($liste));
            }
            $consignes[] = ['titre' => 'Finition', 'texte' => implode(' · ', $parties)];
        }
        $consignes[] = ['titre' => 'Roulage', 'texte' => 'face imprimée vers l\'extérieur, jamais pliée'];

        return $consignes;
    }

    /**
     * Ce qui empêche un ordre de démarrer (null : il peut démarrer). Mêmes
     * règles que ActionsOrdre::demarrer et CommandesDetails::demarrerProduction.
     */
    public static function raisonNePeutDemarrer(OrdreProduction $o): ?string
    {
        $ligne = $o->getCommandeDetail();
        $nom = $o->getNumero();

        return match (true) {
            !$o->estADemarrer() => $nom.' n\'attend plus son impression.',
            null === $ligne => $nom.' n\'a pas de ligne de commande.',
            null === $o->getControlePrePresse() => $nom.' : le contrôle prépresse manque.',
            !$ligne->isProductionNecessaire() => $nom.' : cette ligne ne demande pas de production.',
            CommandesDetails::PRODUCTION_A_PRODUIRE !== $ligne->getStatutProduction() => $nom.' : la ligne n\'est plus « à produire ».',
            null !== $ligne->getDernierControlePrePresse() && $ligne->getDernierControlePrePresse()->isBatNecessaire() && !$ligne->getDernierControlePrePresse()->isBatValide() => $nom.' : le BAT doit être validé avant de démarrer.',
            default => null,
        };
    }

    /**
     * « Démarrer » : tout doit être vert et le rouleau envoyé au RIP. Chaque
     * ordre démarre (ActionsOrdre) ; si l'un refuse, rien ne démarre.
     *
     * @throws \DomainException message à montrer
     */
    public function demarrer(TirageRouleau $tirage, User $par, \DateTimeImmutable $maintenant): void
    {
        if (!$tirage->estEnvoye()) {
            throw new \DomainException(TirageRouleau::STATUT_PREPARE === $tirage->getStatut() ? 'Envoyez d\'abord le rouleau au RIP.' : 'Ce rouleau n\'est plus à démarrer.');
        }
        $resume = ControlesTirage::resume($this->controles($tirage, $maintenant));
        if (!$resume['pret']) {
            throw new \DomainException(\sprintf('« Démarrer » attend que tout soit vert : %d point%s bloquant%s, %d à faire.', $resume['bloquants'], $resume['bloquants'] > 1 ? 's' : '', $resume['bloquants'] > 1 ? 's' : '', $resume['afaire']));
        }
        $ordres = $tirage->ordres();
        if ([] === $ordres) {
            throw new \DomainException('Le rouleau n\'a plus de travail.');
        }
        foreach ($ordres as $o) {
            $raison = self::raisonNePeutDemarrer($o);
            if (null !== $raison) {
                throw new \DomainException($raison.' Rien n\'a démarré.');
            }
        }
        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            foreach ($ordres as $o) {
                $this->actions->demarrer($o, $par, $tirage->getMachine());
            }
            foreach ($tirage->getPieces() as $p) {
                if (1 === $p->getRang()) {
                    $p->mettreEnCours();
                }
            }
            $tirage->demarrer($par, $maintenant);
            $this->em->flush();
            $connexion->commit();
        } catch (\Throwable $e) {
            $connexion->rollBack();
            throw new \DomainException('Le rouleau n\'a pas démarré : '.$e->getMessage().' Rien n\'a démarré.', 0, $e);
        }
    }

    /** R-1020-03_SOLVANT-320_BACHE440_5pieces.pdf */
    public function nomRip(TirageRouleau $tirage, string $extension = 'pdf'): string
    {
        $code = $this->atelier($tirage->getMachine())?->getCodeDossier() ?? AtelierMachine::nettoyerCodeDossier((string) $tirage->getMachine()->getNom()) ?? 'MACHINE';
        $n = $tirage->getPieces()->count();

        return \sprintf('%s_%s_%s_%dpiece%s.%s', $tirage->getNumero(), $code, NomFichierProduction::nettoyerCode($tirage->getCodeSupport()) ?: 'SUPPORT', $n, $n > 1 ? 's' : '', $extension);
    }

    /**
     * Les travaux du rouleau pour les écrans (rang d'impression, numéro,
     * client, produit et taille, finitions, livraison, couleur).
     *
     * @return list<array{ordre: OrdreProduction, rang: int, numero: string, client: string, produit: string, taille: ?string, quantite: int, finitions: list<string>, promis: ?\DateTimeImmutable, couleur: string, pieces: int, nomFichier: string}>
     */
    public function travaux(TirageRouleau $tirage): array
    {
        $travaux = [];
        foreach ($tirage->ordres() as $i => $o) {
            $ligne = $o->getCommandeDetail();
            $pieces = 0;
            foreach ($tirage->getPieces() as $p) {
                if ($p->getOrdre() === $o) {
                    ++$pieces;
                }
            }
            $taille = self::taillePiece($o);
            $travaux[] = [
                'ordre' => $o,
                'rang' => $i + 1,
                'numero' => NomFichierProduction::numeroCourt((string) $ligne?->getCommande()?->getNumero()) ?: $o->getNumero(),
                'client' => (string) $ligne?->getCommande()?->getClients()?->getNomComplet(),
                'produit' => self::produitCourt($ligne),
                'taille' => null !== $taille ? ImpositionRouleau::nombre($taille[0]).' × '.ImpositionRouleau::nombre($taille[1]) : (null !== $ligne ? PosteMachiniste::taille($ligne) : null),
                'quantite' => max(1, (int) $o->getQuantite()),
                'finitions' => $ligne?->nomsFinitions() ?? [],
                'promis' => AvancementOrdre::datePromise($o),
                'couleur' => self::COULEURS[$i % \count(self::COULEURS)],
                'pieces' => $pieces,
                'nomFichier' => FichierProduction::nomProduction($o),
            ];
        }

        return $travaux;
    }

    /**
     * Tirages par état pour la liste (préparés, envoyés, en impression,
     * terminés aujourd'hui), les plus récents d'abord.
     *
     * @return array<string, list<TirageRouleau>>
     */
    public function parEtat(\DateTimeImmutable $maintenant): array
    {
        /** @var list<TirageRouleau> $tirages */
        $tirages = $this->em->createQueryBuilder()->select('t', 'm')->from(TirageRouleau::class, 't')->join('t.machine', 'm')
            ->where('t.statut IN (:actifs) OR t.modifieLe >= :jour OR t.creeLe >= :jour')
            ->setParameter('actifs', [TirageRouleau::STATUT_PREPARE, TirageRouleau::STATUT_ENVOYE, TirageRouleau::STATUT_EN_IMPRESSION, TirageRouleau::STATUT_EN_PAUSE])
            ->setParameter('jour', $maintenant->setTime(0, 0))
            ->orderBy('t.creeLe', 'DESC')->setMaxResults(200)
            ->getQuery()->getResult();
        $groupes = ['prepare' => [], 'envoye' => [], 'impression' => [], 'faits' => []];
        foreach ($tirages as $t) {
            $groupe = match ($t->getStatut()) {
                TirageRouleau::STATUT_PREPARE => 'prepare',
                TirageRouleau::STATUT_ENVOYE => 'envoye',
                TirageRouleau::STATUT_EN_IMPRESSION, TirageRouleau::STATUT_EN_PAUSE => 'impression',
                default => 'faits',
            };
            $groupes[$groupe][] = $t;
        }

        return $groupes;
    }
}
