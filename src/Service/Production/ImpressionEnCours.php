<?php

namespace App\Service\Production;

use App\Entity\IncidentMachine;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\PieceTirage;
use App\Entity\TirageRouleau;
use App\Entity\User;
use App\Security\Droits;
use App\Service\Atelier\ActionsOrdre;
use App\Service\Atelier\AtelierProduction;
use App\Service\Atelier\PosteMachiniste;
use App\Service\NotificationService;
use App\Service\TableauProduction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Impression en cours d'un rouleau (PD04, PD07 écran 1,
 * docs/production-rouleau.md). La machine n'a pas d'API : l'avancement
 * vient des « Pièce imprimée » de l'opérateur et s'estime entre deux
 * (ProgressionRouleau). Pause et reprise passent par les ordres
 * (ActionsOrdre) ; « Impression terminée » termine chaque ordre avec ses
 * pièces, note le temps d'impression mesuré et sort la chute et l'encre
 * (SortiesProduction). La machine est libre dès la fin de l'impression, le
 * séchage se fait hors machine. Aucun montant.
 */
final class ImpressionEnCours
{
    /** Travaux montrés au plus dans « Ensuite sur cette machine ». */
    private const ENSUITE_MAX = 4;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TiragesRouleau $tirages,
        private readonly ActionsOrdre $actions,
        private readonly SortiesProduction $sorties,
        private readonly NotificationService $notifications,
        private readonly AtelierProduction $atelier,
    ) {
    }

    /**
     * Rouleaux sur une machine (en impression ou en pause), le plus ancien
     * démarré d'abord ; toutes les machines si $machine est null.
     *
     * @return list<TirageRouleau>
     */
    public function lances(?Machines $machine = null): array
    {
        $qb = $this->em->createQueryBuilder()->select('t', 'm')->from(TirageRouleau::class, 't')->join('t.machine', 'm')
            ->where('t.statut IN (:lances)')->setParameter('lances', [TirageRouleau::STATUT_EN_IMPRESSION, TirageRouleau::STATUT_EN_PAUSE])
            ->orderBy('t.demarreLe', 'ASC');
        if (null !== $machine) {
            $qb->andWhere('t.machine = :machine')->setParameter('machine', $machine);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Vitesse (m²/h), encre (ml/m²) et séchage (min) du suivi : le profil
     * de la machine pour ce support, sinon l'estimation du rouleau.
     *
     * @return array{vitesse: ?float, encreMlM2: ?float, sechageMin: int, mode: ?string}
     */
    public function reperes(TirageRouleau $tirage): array
    {
        $profil = $this->tirages->profil($tirage);
        $encre = $profil['encreMlM2'] ?? null;
        if ((null === $encre || $encre <= 0) && null !== $tirage->getEncreMl() && $tirage->getImprimeM2() > 0) {
            $encre = $tirage->getEncreMl() / $tirage->getImprimeM2();
        }

        return [
            'vitesse' => null !== ($profil['vitesseM2h'] ?? null) && $profil['vitesseM2h'] > 0 ? (float) $profil['vitesseM2h'] : ProgressionRouleau::vitesseEstimee($tirage),
            'encreMlM2' => null !== $encre && $encre > 0 ? (float) $encre : null,
            'sechageMin' => $tirage->getSechageMin() ?? (int) ($profil['sechageMin'] ?? $this->tirages->reglages()['sechage']),
            'mode' => '' !== trim((string) ($profil['mode'] ?? '')) ? (string) $profil['mode'] : null,
        ];
    }

    /**
     * Tout ce que montrent PD04 et PD07 (et l'état lu toutes les 20 s).
     *
     * @return array<string, mixed>
     */
    public function etat(TirageRouleau $tirage, \DateTimeImmutable $maintenant): array
    {
        $reperes = $this->reperes($tirage);
        $progression = ProgressionRouleau::duTirage($tirage, $maintenant, $reperes['vitesse'], $reperes['encreMlM2']);
        $couleurs = [];
        foreach ($this->tirages->travaux($tirage) as $t) {
            $couleurs[(int) $t['ordre']->getId()] = $t['couleur'];
        }
        $pieces = [];
        $courante = null;
        $sechent = [];
        foreach ($tirage->piecesTriees() as $p) {
            $o = $p->getOrdre();
            $secheA = null !== $p->getImprimeeLe() ? $p->getImprimeeLe()->modify('+'.$reperes['sechageMin'].' minutes') : null;
            $piece = [
                'piece' => $p,
                'rang' => $p->getRang(),
                'nom' => $this->nomPiece($p),
                'titre' => $this->titrePiece($p),
                'statut' => $p->getStatut(),
                'secheA' => $secheA,
                'sec' => null !== $secheA && $secheA <= $maintenant,
                'couleur' => $couleurs[(int) $o->getId()] ?? TiragesRouleau::COULEURS[0],
                'client' => $this->client($o),
            ];
            if (PieceTirage::STATUT_IMPRIMEE === $p->getStatut() && !$piece['sec']) {
                $sechent[$piece['client']] = true;
            }
            if (null === $courante && PieceTirage::STATUT_IMPRIMEE !== $p->getStatut() && $tirage->estLance()) {
                $courante = $piece;
            }
            $pieces[] = $piece;
        }
        $atelier = $this->tirages->atelier($tirage->getMachine());
        $seuil = $this->tirages->reglages()['seuilEncre'];
        $encres = $atelier?->getNiveauxEncre() ?? [];
        $basses = array_keys(array_filter($encres, static fn (int $pct): bool => $pct < $seuil));

        return [
            'tirage' => $tirage,
            'machine' => $tirage->getMachine(),
            'progression' => $progression,
            'reperes' => $reperes,
            'pieces' => $pieces,
            'courante' => $courante,
            'sechent' => array_keys($sechent),
            'demarrePar' => AtelierProduction::prenom($tirage->getDemarrePar()),
            'imprimePar' => AtelierProduction::prenom($tirage->getImprimePar()),
            'encres' => $encres,
            'encresLe' => $atelier?->getNiveauxEncreLe(),
            'encresPar' => AtelierProduction::prenom($atelier?->getNiveauxEncrePar()),
            'encresBasses' => $basses,
            'seuilEncre' => $seuil,
            'rappels' => $tirage->estLance() ? $this->rappels($tirage) : [],
            'ensuite' => $tirage->estLance() || TirageRouleau::STATUT_IMPRIME === $tirage->getStatut() ? $this->ensuite($tirage, $progression['fin'] ?? $maintenant, $maintenant) : [],
            'gaches' => $tirage->getGaches(),
            'ordres' => $this->ordres($tirage, $reperes['sechageMin'], $maintenant),
            'sorties' => $tirage->estLance() ? [] : $this->sorties->duRouleau($tirage),
            'dureeMin' => null !== $tirage->getDemarreLe() ? (int) round(ProgressionRouleau::secondesEffectives($tirage->getDemarreLe(), $tirage->getImprimeLe() ?? $maintenant, $tirage->getPauses(), $maintenant) / 60) : null,
            'arret' => $this->arret($tirage->getMachine()),
            'maintenant' => $maintenant,
        ];
    }

    /** « CM-0430 Koita » (une pièce), « Clinique Pasteur 1/2 ». */
    public function nomPiece(PieceTirage $p): string
    {
        $o = $p->getOrdre();
        $quantite = max(1, (int) $o->getQuantite());
        $client = $this->client($o);
        if (1 === $quantite) {
            $numero = NomFichierProduction::numeroCourt((string) $o->getCommandeDetail()?->getCommande()?->getNumero());

            return trim(('' !== $numero ? $numero : $o->getNumero()).' '.$client);
        }

        return \sprintf('%s %d/%d', '' !== $client ? $client : $o->getNumero(), $p->getExemplaire(), $quantite);
    }

    /** « Kakémono 85 × 200 · Clinique Pasteur ». */
    public function titrePiece(PieceTirage $p): string
    {
        $o = $p->getOrdre();
        $ligne = $o->getCommandeDetail();
        $taille = TiragesRouleau::taillePiece($o);
        $produit = TiragesRouleau::produitCourt($ligne);

        return trim($produit.(null !== $taille ? ' '.ImpositionRouleau::nombre($taille[0]).' × '.ImpositionRouleau::nombre($taille[1]) : '').' · '.$this->client($o), ' ·');
    }

    /** Raison sociale, sinon nom du client (court). */
    private function client(OrdreProduction $o): string
    {
        $c = $o->getCommandeDetail()?->getCommande()?->getClients();

        return mb_substr(trim((string) ($c?->getRaisonSociale() ?: $c?->getNomComplet())), 0, 40);
    }

    /**
     * Rappels des couleurs sensibles (point « couleurs » de PD03) pour les
     * travaux pas encore tout imprimés.
     *
     * @return list<string>
     */
    public function rappels(TirageRouleau $tirage): array
    {
        $rappels = [];
        foreach ($this->tirages->couleursParOrdre($tirage) as $id => $couleur) {
            $restantes = 0;
            $total = 0;
            foreach ($tirage->getPieces() as $p) {
                if ((int) $p->getOrdre()->getId() === $id) {
                    ++$total;
                    $restantes += PieceTirage::STATUT_IMPRIMEE === $p->getStatut() ? 0 : 1;
                }
            }
            if (0 === $restantes) {
                continue;
            }
            $quoi = str_starts_with($couleur, 'couleurs') ? 'les '.$couleur : 'le '.$couleur;
            $rappels[] = $total > 1
                ? \sprintf('Comparer %s au BAT imprimé sur la 1re pièce avant de lancer la 2e.', $quoi)
                : \sprintf('Comparer %s au BAT imprimé dès la sortie de la pièce.', $quoi);
        }

        return $rappels;
    }

    /**
     * « Ensuite sur cette machine » : les rouleaux préparés ou envoyés, puis
     * les travaux de la file qui ne sont sur aucun rouleau, avec l'heure
     * prévue et « changer de rouleau » quand le support change.
     *
     * @return list<array{heure: ?\DateTimeImmutable, titre: string, detail: string, memeRouleau: ?bool, lien: ?array{route: string, id: int}}>
     */
    public function ensuite(TirageRouleau $tirage, \DateTimeImmutable $libreA, \DateTimeImmutable $maintenant): array
    {
        $machine = $tirage->getMachine();
        $suite = [];
        $curseur = max($libreA, $maintenant);
        /** @var list<TirageRouleau> $rouleaux */
        $rouleaux = $this->em->createQueryBuilder()->select('t')->from(TirageRouleau::class, 't')
            ->where('t.machine = :m')->andWhere('t.statut IN (:attente)')->andWhere('t <> :ce')
            ->setParameter('m', $machine)->setParameter('attente', [TirageRouleau::STATUT_ENVOYE, TirageRouleau::STATUT_PREPARE])->setParameter('ce', $tirage)
            ->orderBy('t.statut', 'ASC')->addOrderBy('t.creeLe', 'ASC')->setMaxResults(self::ENSUITE_MAX)
            ->getQuery()->getResult();
        // Les envoyés au RIP d'abord : ils sont prêts à démarrer.
        usort($rouleaux, static fn (TirageRouleau $a, TirageRouleau $b): int => [$a->estEnvoye() ? 0 : 1, $a->getCreeLe()] <=> [$b->estEnvoye() ? 0 : 1, $b->getCreeLe()]);
        foreach ($rouleaux as $r) {
            $n = $r->getPieces()->count();
            $suite[] = [
                'heure' => $curseur,
                'titre' => \sprintf('Rouleau %s · %d pièce%s', $r->getNumero(), $n, $n > 1 ? 's' : ''),
                'detail' => $r->getLibelleSupport(),
                'memeRouleau' => $r->getCodeSupport() === $tirage->getCodeSupport(),
                'lien' => ['route' => 'app_atelier_production_fiche', 'id' => (int) $r->getId()],
            ];
            $curseur = $curseur->modify('+'.max(1, (int) ($r->getDureeImpressionMin() ?? 30)).' minutes');
        }
        foreach ($this->atelier->file($machine, $maintenant) as $f) {
            if (\count($suite) >= self::ENSUITE_MAX) {
                break;
            }
            /** @var OrdreProduction $o */
            $o = $f['ordre'];
            if (!$o->estADemarrer() || (null !== $o->getTirage() && !$o->getTirage()->estAnnule())) {
                continue;
            }
            $ligne = $o->getCommandeDetail();
            $code = null !== $ligne ? FichierProduction::codeSupport($ligne) : null;
            $quantite = max(1, (int) $o->getQuantite());
            $taille = null !== $ligne ? PosteMachiniste::taille($ligne) : null;
            $suite[] = [
                'heure' => max($curseur, $f['debut'] ?? $curseur),
                'titre' => trim(NomFichierProduction::numeroCourt((string) ($f['numero'] ?? '')).' · '.$this->client($o), ' ·'),
                'detail' => trim(implode(' ', array_filter([null !== $ligne ? (FichierProduction::libelleSupport($ligne) ?: TableauProduction::titre($ligne)) : $o->getNumero(), $taille, $quantite > 1 ? '× '.$quantite : null]))),
                'memeRouleau' => null !== $code && '' !== $code ? $code === $tirage->getCodeSupport() : null,
                'lien' => ['route' => 'app_ma_file_travail', 'id' => (int) $o->getId()],
            ];
        }

        return $suite;
    }

    /**
     * Les travaux du rouleau après l'impression : séchage et « Commencer la
     * finition ».
     *
     * @return list<array{ordre: OrdreProduction, nom: string, pieces: int, secheA: ?\DateTimeImmutable, sec: bool, finition: ?\DateTimeImmutable, gache: int}>
     */
    private function ordres(TirageRouleau $tirage, int $sechageMin, \DateTimeImmutable $maintenant): array
    {
        $liste = [];
        $gaches = $tirage->getGaches();
        foreach ($tirage->ordres() as $o) {
            $pieces = 0;
            $fin = null;
            foreach ($tirage->getPieces() as $p) {
                if ($p->getOrdre() === $o) {
                    ++$pieces;
                    $fin = null === $fin || ($p->getImprimeeLe() ?? $fin) > $fin ? ($p->getImprimeeLe() ?? $fin) : $fin;
                }
            }
            $fin = $o->getImpressionFinieLe() ?? $fin;
            $secheA = null !== $fin ? $fin->modify('+'.$sechageMin.' minutes') : null;
            $ligne = $o->getCommandeDetail();
            $liste[] = [
                'ordre' => $o,
                'nom' => trim(NomFichierProduction::numeroCourt((string) $ligne?->getCommande()?->getNumero()).' · '.$this->client($o), ' ·'),
                'produit' => null !== $ligne ? PosteMachiniste::titre($ligne) : $o->getNumero(),
                'pieces' => $pieces,
                'secheA' => $secheA,
                'sec' => null !== $secheA && $secheA <= $maintenant,
                'finition' => $o->getFinitionDebuteeLe(),
                'gache' => $gaches[(int) $o->getId()] ?? 0,
            ];
        }

        return $liste;
    }

    /** Un incident « à l'arrêt » ouvert sur la machine, sinon null. */
    private function arret(Machines $machine): ?IncidentMachine
    {
        foreach ($this->atelier->incidentsOuverts([$machine])[(int) $machine->getId()] ?? [] as $incident) {
            if ($incident->estArret()) {
                return $incident;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Gestes de l'opérateur
    // ------------------------------------------------------------------

    /**
     * « Pièce imprimée » : la pièce en cours sort, la suivante commence.
     * Rend la pièce confirmée.
     *
     * @throws \DomainException message à montrer
     */
    public function pieceImprimee(TirageRouleau $tirage, \DateTimeImmutable $maintenant): PieceTirage
    {
        if ($tirage->estEnPause()) {
            throw new \DomainException('Le rouleau est en pause : reprenez-le avant de confirmer une pièce.');
        }
        if (!$tirage->estEnImpression()) {
            throw new \DomainException('Ce rouleau n\'est pas en impression.');
        }
        $restantes = array_values(array_filter($tirage->piecesTriees(), static fn (PieceTirage $p): bool => PieceTirage::STATUT_IMPRIMEE !== $p->getStatut()));
        if ([] === $restantes) {
            throw new \DomainException('Toutes les pièces sont imprimées : appuyez sur « Impression terminée ».');
        }
        $courante = $restantes[0];
        foreach ($restantes as $p) {
            if (PieceTirage::STATUT_EN_COURS === $p->getStatut()) {
                $courante = $p;
                break;
            }
        }
        // Une pièce jamais passée « en cours » a commencé à la fin de la précédente (ou au démarrage).
        if (null === $courante->getDebuteeLe()) {
            $courante->mettreEnCours($this->finPrecedente($tirage, $courante) ?? $tirage->getDemarreLe() ?? $maintenant);
        }
        $courante->marquerImprimee($maintenant);
        foreach ($tirage->piecesTriees() as $p) {
            if (PieceTirage::STATUT_A_IMPRIMER === $p->getStatut()) {
                $p->mettreEnCours($maintenant);
                break;
            }
        }
        $this->em->flush();

        return $courante;
    }

    private function finPrecedente(TirageRouleau $tirage, PieceTirage $piece): ?\DateTimeImmutable
    {
        $fin = null;
        foreach ($tirage->piecesTriees() as $p) {
            if ($p === $piece) {
                break;
            }
            $fin = $p->getImprimeeLe() ?? $fin;
        }

        return $fin;
    }

    /** « Pause » : le rouleau et ses travaux en cours, ensemble. */
    public function pause(TirageRouleau $tirage, \DateTimeImmutable $maintenant): void
    {
        $this->dansUneTransaction(function () use ($tirage, $maintenant): void {
            $tirage->mettreEnPause($maintenant);
            foreach ($tirage->ordres() as $o) {
                if ($o->estEnCours()) {
                    $this->actions->pause($o);
                }
            }
            $this->em->flush();
        }, 'Le rouleau n\'a pas été mis en pause');
    }

    /** « Reprendre » : le rouleau et ses travaux en pause. */
    public function reprendre(TirageRouleau $tirage, User $par, \DateTimeImmutable $maintenant): void
    {
        $this->dansUneTransaction(function () use ($tirage, $par, $maintenant): void {
            $this->reprendreSansFlush($tirage, $par, $maintenant);
            $this->em->flush();
        }, 'Le rouleau n\'a pas repris');
    }

    private function reprendreSansFlush(TirageRouleau $tirage, User $par, \DateTimeImmutable $maintenant): void
    {
        $tirage->reprendre($maintenant);
        foreach ($tirage->ordres() as $o) {
            if ($o->estEnPause()) {
                $this->actions->reprendre($o, $par);
            }
        }
    }

    /**
     * « Signaler un problème » : incident de la machine (le chef d'atelier
     * est prévenu), pièce ratée notée pour son travail, pause si la machine
     * est à l'arrêt.
     */
    public function probleme(TirageRouleau $tirage, User $par, string $titre, ?string $detail, bool $arret, bool $pieceRatee, \DateTimeImmutable $maintenant): IncidentMachine
    {
        $titre = trim($titre);
        if ('' === $titre) {
            throw new \DomainException('Dites en quelques mots ce qui ne va pas.');
        }
        $machine = $tirage->getMachine();
        $courante = null;
        foreach ($tirage->piecesTriees() as $p) {
            if (PieceTirage::STATUT_IMPRIMEE !== $p->getStatut()) {
                $courante = $p;
                break;
            }
        }
        $ordre = ($courante ?? $tirage->piecesTriees()[0] ?? null)?->getOrdre();
        $incident = (new IncidentMachine($machine, $titre, $arret, $maintenant, $par))
            ->setDetail(trim(implode(' · ', array_filter([\sprintf('Rouleau %s', $tirage->getNumero()), null !== $courante ? 'pièce '.$this->nomPiece($courante) : null, trim((string) $detail)]))))
            ->setOrdre($ordre);
        $this->dansUneTransaction(function () use ($tirage, $incident, $ordre, $arret, $pieceRatee, $maintenant): void {
            $this->em->persist($incident);
            if ($pieceRatee && null !== $ordre) {
                $tirage->noterGache((int) $ordre->getId(), 1);
            }
            if ($arret && $tirage->estEnImpression()) {
                $tirage->mettreEnPause($maintenant);
                foreach ($tirage->ordres() as $o) {
                    if ($o->estEnCours()) {
                        $this->actions->pause($o);
                    }
                }
            }
            $this->em->flush();
        }, 'Le problème n\'a pas été noté');
        $this->notifications->notifierRoles([Droits::ATELIER_CHEF], \sprintf('%s %s : %s (rouleau %s)', $machine->getNom(), $arret ? 'en panne' : 'incident', mb_substr($titre, 0, 120), $tirage->getNumero()), 'app_atelier_maintenance', [], $par);
        $this->em->flush();

        return $incident;
    }

    /**
     * « Impression terminée » : les pièces restantes sont confirmées, chaque
     * travail du rouleau se termine avec ses pièces (plus les ratées
     * réimprimées), son temps d'impression mesuré est noté, la chute et
     * l'encre sortent du stock. Rend ce qui n'a pas pu sortir du stock.
     *
     * @param array<int, int> $gaches id de l'ordre => pièces ratées (sinon celles notées pendant l'impression)
     *
     * @return list<string>
     *
     * @throws \DomainException message à montrer
     */
    public function terminer(TirageRouleau $tirage, User $par, array $gaches, \DateTimeImmutable $maintenant): array
    {
        if (!$tirage->estLance()) {
            throw new \DomainException('Ce rouleau n\'est pas en impression.');
        }
        $reperes = $this->reperes($tirage);
        $notes = [];
        $this->dansUneTransaction(function () use ($tirage, $par, $gaches, $maintenant, $reperes, &$notes): void {
            if ($tirage->estEnPause()) {
                $this->reprendreSansFlush($tirage, $par, $maintenant);
            }
            foreach ($tirage->piecesTriees() as $p) {
                if (PieceTirage::STATUT_IMPRIMEE !== $p->getStatut()) {
                    $p->mettreEnCours($this->finPrecedente($tirage, $p) ?? $tirage->getDemarreLe() ?? $maintenant);
                    $p->marquerImprimee($maintenant);
                }
            }
            $notees = $tirage->getGaches();
            $pauses = $tirage->getPauses();
            foreach ($tirage->ordres() as $o) {
                if ($o->estTermine() || $o->estAnnule()) {
                    continue;
                }
                $pieces = array_values(array_filter($tirage->piecesTriees(), static fn (PieceTirage $p): bool => $p->getOrdre() === $o));
                $gache = max(0, min(999, (int) ($gaches[(int) $o->getId()] ?? $notees[(int) $o->getId()] ?? 0)));
                $temps = ProgressionRouleau::tempsOrdre(array_map(static fn (PieceTirage $p): array => ['debut' => $p->getDebuteeLe(), 'fin' => $p->getImprimeeLe()], $pieces), $pauses, $maintenant);
                if (null !== $temps) {
                    $o->noterImpression($temps['debut'], $temps['fin'], $temps['minutes'], $this->impressionPrevue($tirage, $pieces));
                }
                $this->actions->terminer($o, $par, \count($pieces) + $gache, $gache, null, $gache > 0 ? \sprintf('Rouleau %s : pièce%s ratée%s réimprimée%s', $tirage->getNumero(), $gache > 1 ? 's' : '', $gache > 1 ? 's' : '', $gache > 1 ? 's' : '') : null);
                if ($gache !== ($notees[(int) $o->getId()] ?? 0)) {
                    $tirage->noterGache((int) $o->getId(), $gache - ($notees[(int) $o->getId()] ?? 0));
                }
            }
            $tirage->terminerImpression($par, $maintenant);
            $notes = $this->sorties->apresRouleau($tirage, $reperes['encreMlM2']);
            $this->em->flush();
        }, 'L\'impression n\'a pas été terminée');

        return $notes;
    }

    /**
     * Part de l'ordre dans le temps d'impression estimé du rouleau (au
     * prorata des m²), avant correction : ce que FacteursEstimation compare.
     *
     * @param list<PieceTirage> $pieces
     */
    private function impressionPrevue(TirageRouleau $tirage, array $pieces): ?int
    {
        $total = $tirage->getImprimeM2();
        // Le rouleau garde son estimation brute (la correction ne fait que s'afficher, PD02).
        $minutes = $tirage->getDureeImpressionMin();
        if (null === $minutes || $minutes <= 0 || $total <= 0) {
            return null;
        }
        $m2 = array_sum(array_map(static fn (PieceTirage $p): float => SortiesProduction::m2Piece($p), $pieces));

        return max(1, (int) round($minutes * $m2 / $total));
    }

    /** « Commencer la finition » d'un travail imprimé (PD04, PD05). */
    public function commencerFinition(OrdreProduction $ordre, User $par, \DateTimeImmutable $maintenant): void
    {
        if (!$ordre->estTermine()) {
            throw new \DomainException('La finition commence une fois l\'impression terminée.');
        }
        if (null !== $ordre->getFinitionDebuteeLe()) {
            throw new \DomainException(\sprintf('La finition a déjà commencé à %s.', $ordre->getFinitionDebuteeLe()->format('H:i')));
        }
        $ordre->commencerFinition($maintenant, AtelierProduction::prenom($par));
        $this->em->flush();
    }

    private function dansUneTransaction(callable $travail, string $echec): void
    {
        $connexion = $this->em->getConnection();
        $connexion->beginTransaction();
        try {
            $travail();
            $connexion->commit();
        } catch (\Throwable $e) {
            $connexion->rollBack();
            if ($e instanceof \DomainException) {
                throw $e;
            }
            throw new \DomainException($echec.' : '.$e->getMessage(), 0, $e);
        }
    }
}
