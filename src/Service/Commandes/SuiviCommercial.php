<?php

namespace App\Service\Commandes;

use App\Entity\BonLivraison;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Conception;
use App\Entity\ControlePrePresse;
use App\Entity\OrdreProduction;
use App\Entity\User;
use App\Service\EspaceClient\DelaisCommande;
use App\Service\SuiviCommandeService;
use App\Service\TableauProduction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Suivi commercial des commandes (maquettes GC01 et GC02, 18/10/2026) :
 * l'étape de chaque commande (acompte attendu, fichier / BAT, impression,
 * prête, en livraison), le retard, la part payée, le responsable du
 * moment et une ligne d'état courte. Lu à partir des données existantes
 * (lignes, contrôles prépresse, conceptions, ordres de production, bons
 * de livraison, paiements), chargées en quelques requêtes pour toute la
 * liste. Ne remplace pas le kanban de l'atelier (/atelier/production),
 * qui suit les travaux ligne par ligne.
 */
final class SuiviCommercial
{
    public const A_CONFIRMER = 'a_confirmer';
    public const ACOMPTE = 'acompte';
    public const FICHIER = 'fichier';
    public const IMPRESSION = 'impression';
    public const PRETE = 'prete';
    public const LIVRAISON = 'livraison';
    public const LIVREE = 'livree';
    public const ANNULEE = 'annulee';

    /** Libellé, libellé court (« BAT en retard ») et couleur de chaque étape. */
    public const ETAPES = [
        self::A_CONFIRMER => ['libelle' => 'À confirmer', 'court' => 'Confirmation', 'pluriel' => 'À confirmer', 'couleur' => 'gris'],
        self::ACOMPTE => ['libelle' => 'Acompte attendu', 'court' => 'Acompte', 'pluriel' => 'Acompte attendu', 'couleur' => 'orange'],
        self::FICHIER => ['libelle' => 'Fichier / BAT', 'court' => 'BAT', 'pluriel' => 'Fichier / BAT', 'couleur' => 'magenta'],
        self::IMPRESSION => ['libelle' => 'En impression', 'court' => 'Impression', 'pluriel' => 'En impression', 'couleur' => 'bleu'],
        self::PRETE => ['libelle' => 'Prête', 'court' => 'Retrait', 'pluriel' => 'Prêtes', 'couleur' => 'vert'],
        self::LIVRAISON => ['libelle' => 'En livraison', 'court' => 'Livraison', 'pluriel' => 'En livraison', 'couleur' => 'bleu'],
        self::LIVREE => ['libelle' => 'Livrée', 'court' => 'Livrée', 'pluriel' => 'Livrées', 'couleur' => 'gris'],
        self::ANNULEE => ['libelle' => 'Annulée', 'court' => 'Annulée', 'pluriel' => 'Annulées', 'couleur' => 'gris'],
    ];

    /** Colonnes du tableau par étape (GC02), dans l'ordre. */
    public const COLONNES = [self::ACOMPTE, self::FICHIER, self::IMPRESSION, self::PRETE, self::LIVRAISON];

    /** Filtres « Pour le » : échéance de la commande. */
    public const POUR = ['aujourdhui' => 'À livrer aujourd\'hui', 'semaine' => 'À livrer cette semaine', 'retard' => 'En retard'];

    /** Au-delà, un BAT sans réponse du client s'affiche en rouge. */
    private const HEURES_BAT_SANS_REPONSE = 24;

    private const RANG_LIGNE = [
        'pao' => 0,
        'bat' => 1,
        'impression' => 2,
        'finition' => 3,
        'prete' => 4,
        'sans_production' => 4,
        'en_livraison' => 5,
        'livree' => 6,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SuiviCommandeService $suivi,
        private readonly DelaisCommande $delais,
    ) {
    }

    /**
     * Une ligne de suivi par commande, dans l'ordre reçu.
     *
     * @param list<Commandes> $commandes
     *
     * @return list<array<string, mixed>>
     */
    public function lignes(array $commandes, \DateTimeImmutable $maintenant): array
    {
        if ([] === $commandes) {
            return [];
        }
        $donnees = $this->charger($commandes);
        $lignes = [];
        foreach ($commandes as $commande) {
            $lignes[] = $this->ligne($commande, $maintenant, $donnees);
        }

        return $lignes;
    }

    /** La ligne de suivi d'une seule commande (fiche). */
    public function pourCommande(Commandes $commande, \DateTimeImmutable $maintenant): array
    {
        return $this->lignes([$commande], $maintenant)[0];
    }

    /**
     * Garde les lignes d'une étape (« retard » : les commandes en retard).
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return list<array<string, mixed>>
     */
    public static function filtrerEtape(array $lignes, string $etape): array
    {
        if ('retard' === $etape) {
            return array_values(array_filter($lignes, static fn (array $l): bool => $l['enRetard']));
        }

        return isset(self::ETAPES[$etape]) ? array_values(array_filter($lignes, static fn (array $l): bool => $l['etape'] === $etape)) : $lignes;
    }

    /**
     * Garde les commandes à livrer aujourd'hui, cette semaine (les 7
     * prochains jours, retards compris : le dimanche, c'est la semaine qui
     * vient) ou en retard.
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return list<array<string, mixed>>
     */
    public static function filtrerPour(array $lignes, string $pour, \DateTimeImmutable $maintenant): array
    {
        $aujourdhui = $maintenant->setTime(0, 0);
        $finSemaine = $aujourdhui->modify('+6 days');

        return match ($pour) {
            'aujourdhui' => array_values(array_filter($lignes, static fn (array $l): bool => $l['ouverte'] && null !== $l['pourLe'] && $l['pourLe']->format('Y-m-d') === $aujourdhui->format('Y-m-d'))),
            'semaine' => array_values(array_filter($lignes, static fn (array $l): bool => $l['ouverte'] && null !== $l['pourLe'] && $l['pourLe']->setTime(0, 0) <= $finSemaine)),
            'retard' => array_values(array_filter($lignes, static fn (array $l): bool => $l['enRetard'])),
            default => $lignes,
        };
    }

    /**
     * Nombre de commandes par étape, plus « toutes » et « retard ».
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return array<string, int>
     */
    public static function compteurs(array $lignes): array
    {
        $compteurs = array_fill_keys(array_keys(self::ETAPES), 0) + ['toutes' => \count($lignes), 'retard' => 0];
        foreach ($lignes as $l) {
            ++$compteurs[$l['etape']];
            $compteurs['retard'] += $l['enRetard'] ? 1 : 0;
        }

        return $compteurs;
    }

    /**
     * Les cinq cartes du haut de la liste (GC01). Le reste à encaisser
     * n'est rendu qu'avec le droit de voir les paiements.
     *
     * @param list<array<string, mixed>> $lignes commandes actives (ouvertes, ou livrées non soldées)
     *
     * @return array<string, mixed>
     */
    public static function cartes(array $lignes, \DateTimeImmutable $maintenant, bool $avecPaiements): array
    {
        $jour = $maintenant->format('Y-m-d');
        $ouvertes = array_values(array_filter($lignes, static fn (array $l): bool => $l['ouverte']));
        $aujourdhui = array_values(array_filter($ouvertes, static fn (array $l): bool => null !== $l['pourLe'] && $l['pourLe']->format('Y-m-d') === $jour));
        $retards = array_values(array_filter($ouvertes, static fn (array $l): bool => $l['enRetard']));
        $retardBat = \count(array_filter($retards, static fn (array $l): bool => \in_array($l['etape'], [self::ACOMPTE, self::FICHIER, self::A_CONFIRMER], true)));

        return [
            'enCours' => \count($ouvertes),
            'aujourdhui' => \count($aujourdhui),
            'aujourdhuiEnLivraison' => \count(array_filter($aujourdhui, static fn (array $l): bool => self::LIVRAISON === $l['etape'])),
            'retard' => \count($retards),
            'retardBat' => $retardBat,
            'retardProduction' => \count($retards) - $retardBat,
            'acomptes' => \count(array_filter($ouvertes, static fn (array $l): bool => self::ACOMPTE === $l['etape'])),
            'reste' => $avecPaiements ? array_sum(array_map(static fn (array $l): int => self::ANNULEE === $l['etape'] ? 0 : $l['reste'], $lignes)) : null,
        ];
    }

    /**
     * Colonnes du tableau par étape (GC02) ; « À confirmer » seulement
     * s'il y a des commandes passées en ligne qui attendent l'atelier.
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return array<string, array{libelle: string, couleur: string, cartes: list<array<string, mixed>>}>
     */
    public static function colonnes(array $lignes): array
    {
        $cles = self::COLONNES;
        if ([] !== array_filter($lignes, static fn (array $l): bool => self::A_CONFIRMER === $l['etape'])) {
            array_unshift($cles, self::A_CONFIRMER);
        }
        $colonnes = [];
        foreach ($cles as $cle) {
            $colonnes[$cle] = ['libelle' => self::ETAPES[$cle]['libelle'], 'couleur' => self::ETAPES[$cle]['couleur'], 'cartes' => []];
        }
        foreach ($lignes as $l) {
            if (isset($colonnes[$l['etape']])) {
                $colonnes[$l['etape']]['cartes'][] = $l;
            }
        }
        // Les plus pressées d'abord : retards, puis échéance la plus proche.
        foreach ($colonnes as &$colonne) {
            usort($colonne['cartes'], static fn (array $a, array $b): int => [$b['enRetard'], null === $a['pourLe'], $a['pourLe']] <=> [$a['enRetard'], null === $b['pourLe'], $b['pourLe']]);
        }
        unset($colonne);

        return $colonnes;
    }

    /**
     * Étape d'une commande d'après le rang le plus bas de ses lignes
     * (la commande avance au pas de sa ligne la plus en retard).
     */
    public static function etape(Commandes $commande, array $rangs, bool $acompteManquant): string
    {
        $statutTravaux = $commande->getStatutTravaux();
        if ('annulee' === $statutTravaux) {
            return self::ANNULEE;
        }
        if (!$commande->isStatut()) {
            return self::A_CONFIRMER;
        }
        $rangMin = [] === $rangs ? 0 : min($rangs);

        return match (true) {
            [] !== $rangs && $rangMin >= 6 => self::LIVREE,
            $rangMin >= 5 => self::LIVRAISON,
            $rangMin >= 4 => self::PRETE,
            // Rien n'est encore imprimé et l'acompte manque : la production attend.
            $acompteManquant && $rangMin <= 1 => self::ACOMPTE,
            $rangMin >= 2 => self::IMPRESSION,
            default => self::FICHIER,
        };
    }

    /**
     * Rang de chaque ligne. Une ligne sans production (frais, conception,
     * crédit) suit les autres : elle ne retient pas la commande à « Prête ».
     *
     * @param array<int|string, string> $etapesLignes
     *
     * @return list<int>
     */
    public static function rangs(array $etapesLignes): array
    {
        $avecProduction = array_filter($etapesLignes, static fn (string $e): bool => 'sans_production' !== $e);
        $retenues = [] !== $avecProduction ? $avecProduction : $etapesLignes;

        return array_values(array_map(static fn (string $e): int => self::RANG_LIGNE[$e] ?? 0, $retenues));
    }

    /**
     * @param array{ordres: array<int, OrdreProduction>, conceptions: array<int, Conception>, livraisons: array<int, BonLivraison>} $donnees
     *
     * @return array<string, mixed>
     */
    private function ligne(Commandes $commande, \DateTimeImmutable $maintenant, array $donnees): array
    {
        $actives = array_values(array_filter(
            $commande->getCommandesDetails()->toArray(),
            static fn (CommandesDetails $d): bool => CommandesDetails::PRODUCTION_ANNULEE !== $d->getStatutProduction()
        ));
        $etapesLignes = [];
        foreach ($actives as $detail) {
            $etapesLignes[$detail->getId() ?? spl_object_id($detail)] = $this->suivi->ligne($detail)['etape'];
        }
        $rangs = self::rangs($etapesLignes);

        $total = max(0, (int) $commande->getTotalTtc());
        $paye = 0;
        foreach ($commande->getPaiements() as $paiement) {
            if ($paiement->estValide()) {
                $paye += (int) $paiement->getMontant();
            }
        }
        $client = $commande->getClients();
        $pourcentageAcompte = null !== $client ? $this->delais->pourcentageAcompte($client) : 0;
        $acompte = DelaisCommande::acompte($total, $pourcentageAcompte);
        $acompteManquant = $acompte > 0 && $paye < $acompte && $paye < $total;

        $etape = self::etape($commande, $rangs, $acompteManquant);
        $ouverte = !\in_array($etape, [self::LIVREE, self::ANNULEE], true);
        $pourLe = $commande->getDateLivraison();
        $enRetard = $ouverte && null !== $pourLe && $pourLe->format('Y-m-d') < $maintenant->format('Y-m-d');

        // La ligne qui fixe l'étape : la moins avancée.
        $ligneCle = null;
        foreach ($actives as $detail) {
            $etapeLigne = $etapesLignes[$detail->getId() ?? spl_object_id($detail)];
            $rang = 'sans_production' === $etapeLigne && \count($rangs) < \count($actives) ? 99 : (self::RANG_LIGNE[$etapeLigne] ?? 0);
            if (null === $ligneCle || $rang < $ligneCle[1]) {
                $ligneCle = [$detail, $rang];
            }
        }
        $ligneCle = $ligneCle[0] ?? null;
        $premiere = $actives[0] ?? null;

        [$etat, $alerte, $responsable] = $this->etat($etape, $commande, $ligneCle, $acompte, $maintenant, $donnees);

        return [
            'commande' => $commande,
            'etape' => $etape,
            'libelle' => $enRetard ? self::ETAPES[$etape]['court'].' en retard' : self::ETAPES[$etape]['libelle'],
            'couleur' => $enRetard ? 'rouge' : self::ETAPES[$etape]['couleur'],
            'ouverte' => $ouverte,
            // Comme sur la fiche : une fois une ligne en production, la commande ne se modifie plus (sauf Direction, depuis la fiche).
            'verrouillee' => [] !== array_filter($actives, static fn (CommandesDetails $d): bool => \in_array($d->getStatutProduction(), [CommandesDetails::PRODUCTION_EN_COURS, CommandesDetails::PRODUCTION_TERMINEE, CommandesDetails::PRODUCTION_PRETE_LIVRAISON, CommandesDetails::PRODUCTION_EN_LIVRAISON, CommandesDetails::PRODUCTION_LIVREE], true)),
            'enRetard' => $enRetard,
            'pourLe' => $pourLe,
            'retrait' => !$commande->estALivrer(),
            'total' => $total,
            'paye' => $paye,
            'reste' => max(0, $total - $paye),
            'partPayee' => $total > 0 ? (int) min(100, floor($paye * 100 / $total)) : ($paye > 0 ? 100 : 0),
            'acompte' => $acompte,
            'produit' => null !== $premiere ? TableauProduction::titre($premiere) : 'Aucun travail',
            'autres' => max(0, \count($actives) - 1),
            'etat' => $etat,
            'alerte' => $alerte,
            'responsable' => self::personne($responsable ?? $commande->getAgents()),
            'client' => self::client($commande),
        ];
    }

    /**
     * Ligne d'état courte de la carte (« BAT n°2 sans réponse depuis 26 h »,
     * « 40/60 faits », « départ 10:30 »), si elle est une alerte, et la
     * personne qui a la main à cette étape.
     *
     * @return array{0: ?string, 1: bool, 2: ?User}
     */
    private function etat(string $etape, Commandes $commande, ?CommandesDetails $ligne, int $acompte, \DateTimeImmutable $maintenant, array $donnees): array
    {
        $ctl = null !== $ligne ? self::dernierControle($ligne) : null;
        $conception = null !== $ctl ? ($donnees['conceptions'][$ctl->getId()] ?? null) : null;
        $ordre = null !== $ligne ? ($donnees['ordres'][$ligne->getId()] ?? null) : null;
        $bl = $donnees['livraisons'][$commande->getId()] ?? null;

        switch ($etape) {
            case self::A_CONFIRMER:
                return [$commande->attendValidationEntreprise() ? 'attend le valideur du client' : 'passée en ligne, à vérifier', false, null];
            case self::ACOMPTE:
                return [\sprintf('acompte %s F attendu', number_format($acompte, 0, ',', ' ')), false, null];
            case self::FICHIER:
                $graphiste = $conception?->getGraphiste() ?? $ctl?->getTraitePar() ?? $ctl?->getControlePar();
                if (ControlePrePresse::STATUT_EN_ATTENTE_BAT === $ctl?->getStatut()) {
                    $envoye = $conception?->getDernierBatLe() ?? $ctl->getTraiteLe();
                    $version = max(1, $conception?->getVersions() ?? 1);
                    if (null !== $envoye) {
                        $heures = intdiv(max(0, $maintenant->getTimestamp() - $envoye->getTimestamp()), 3600);
                        if ($heures >= 1) {
                            return [\sprintf('BAT n°%d sans réponse depuis %s', $version, self::duree($heures)), $heures >= self::HEURES_BAT_SANS_REPONSE, $graphiste];
                        }
                    }

                    return [\sprintf('BAT n°%d envoyé', $version), false, $graphiste];
                }
                if (ControlePrePresse::STATUT_CORRECTION_REQUISE === $ctl?->getStatut() || $ctl?->isCorrectionNecessaire()) {
                    return ['correction demandée par le client', true, $graphiste];
                }
                if (null !== $conception && $conception->enCours()) {
                    return ['PAO en cours', false, $graphiste];
                }

                return [null === $ctl ? 'fichier à contrôler' : 'BAT à préparer', false, $graphiste];
            case self::IMPRESSION:
                $operateur = $ordre?->getOperateur() ?? $ligne?->getProductionDebuteePar();
                $machine = $ordre?->getMachine()?->getNom() ?? $ligne?->getMachine()?->getNom() ?? $ligne?->getTypeImpression()?->getNom();
                if (null !== $ordre && ($ordre->getQuantite() ?? 0) > 0 && $ordre->getQuantiteProduite() > 0) {
                    return [trim(($machine ? $machine.' · ' : '').$ordre->getQuantiteProduite().'/'.$ordre->getQuantite().' faits'), false, $operateur];
                }
                if (null !== $ordre && $ordre->estEnCours()) {
                    return [trim(($machine ? $machine.' · ' : '').'en cours'), false, $operateur];
                }
                if (null !== $ordre?->getDebutPrevuLe()) {
                    return [trim(($machine ? $machine.' · ' : '').'prévue '.$ordre->getDebutPrevuLe()->format('d/m H:i')), false, $operateur];
                }

                return [trim(($machine ? $machine.' · ' : '').'à imprimer'), false, $operateur];
            case self::PRETE:
                if (!$commande->estALivrer()) {
                    return ['à retirer à l\'imprimerie', false, null];
                }
                if (null !== $bl?->getPrevuLe()) {
                    return ['livraison prévue '.$bl->getPrevuLe()->format('d/m').($bl->getCreneau() ? ' · '.$bl->getCreneau() : ''), false, $bl->getLivreur()];
                }

                return ['à livrer, livraison à planifier', false, null];
            case self::LIVRAISON:
                $depart = $bl?->getEnRouteLe() ?? $bl?->getChargeLe();

                return [null !== $depart ? 'départ '.$depart->format('H:i') : 'en tournée', false, $bl?->getLivreur() ?? $bl?->getLivrePar()];
            default:
                return [null, false, null];
        }
    }

    /**
     * Paiements, contrôles prépresse, conceptions, ordres de production et
     * bons de livraison de toutes les commandes en une requête chacun.
     *
     * @param list<Commandes> $commandes
     *
     * @return array{ordres: array<int, OrdreProduction>, conceptions: array<int, Conception>, livraisons: array<int, BonLivraison>}
     */
    private function charger(array $commandes): array
    {
        $ids = array_values(array_filter(array_map(static fn (Commandes $c): ?int => $c->getId(), $commandes)));
        if ([] === $ids) {
            return ['ordres' => [], 'conceptions' => [], 'livraisons' => []];
        }
        // Remplit les collections déjà chargées (paiements, contrôles des lignes) sans une requête par commande.
        $this->em->createQueryBuilder()->select('c', 'p')->from(Commandes::class, 'c')->leftJoin('c.paiements', 'p')
            ->andWhere('c.id IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        $this->em->createQueryBuilder()->select('d', 'ctl')->from(CommandesDetails::class, 'd')->leftJoin('d.controlesPrePresse', 'ctl')
            ->andWhere('d.commande IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();

        $ordres = [];
        /** @var list<OrdreProduction> $liste */
        $liste = $this->em->createQueryBuilder()->select('o', 'm', 'op')->from(OrdreProduction::class, 'o')
            ->join('o.commandeDetail', 'd')->leftJoin('o.machine', 'm')->leftJoin('o.operateur', 'op')
            ->andWhere('d.commande IN (:ids)')->andWhere('o.statut != :annule')
            ->setParameter('ids', $ids)->setParameter('annule', OrdreProduction::STATUT_ANNULE)
            ->orderBy('o.id', 'ASC')->getQuery()->getResult();
        foreach ($liste as $ordre) {
            $ordres[$ordre->getCommandeDetail()->getId()] = $ordre;
        }

        $conceptions = [];
        /** @var list<Conception> $liste */
        $liste = $this->em->createQueryBuilder()->select('cp', 'ctl')->from(Conception::class, 'cp')
            ->join('cp.controle', 'ctl')->join('ctl.commandeDetail', 'd')
            ->andWhere('d.commande IN (:ids)')->setParameter('ids', $ids)->getQuery()->getResult();
        foreach ($liste as $conception) {
            $conceptions[$conception->getControle()->getId()] = $conception;
        }

        $livraisons = [];
        /** @var list<BonLivraison> $liste */
        $liste = $this->em->createQueryBuilder()->select('bl')->from(BonLivraison::class, 'bl')
            ->andWhere('bl.commande IN (:ids)')->andWhere('bl.statut != :annule')
            ->setParameter('ids', $ids)->setParameter('annule', BonLivraison::STATUT_ANNULE)
            ->orderBy('bl.id', 'ASC')->getQuery()->getResult();
        foreach ($liste as $bl) {
            $livraisons[$bl->getCommande()->getId()] = $bl;
        }

        return ['ordres' => $ordres, 'conceptions' => $conceptions, 'livraisons' => $livraisons];
    }

    /** « 26 h », « 3 j ». */
    public static function duree(int $heures): string
    {
        return $heures < 48 ? $heures.' h' : intdiv($heures, 24).' j';
    }

    /**
     * Prénom, initiales et teinte d'une personne de l'équipe (disque coloré).
     *
     * @return array{nom: string, complet: string, initiales: string, teinte: int}|null
     */
    public static function personne(?User $utilisateur): ?array
    {
        if (null === $utilisateur) {
            return null;
        }
        $employe = $utilisateur->getEmploye();
        $prenom = trim((string) $employe?->getPrenom());
        $nom = trim((string) $employe?->getNom());
        $affiche = '' !== $prenom ? $prenom : (string) $utilisateur->getUsername();

        return [
            'nom' => $affiche,
            'complet' => trim($prenom.' '.$nom) ?: $affiche,
            'initiales' => self::initiales('' !== $prenom ? $prenom.' '.$nom : $affiche),
            'teinte' => ($utilisateur->getId() ?? 0) % 6,
        ];
    }

    /**
     * Nom du client, initiales et teinte de sa pastille.
     *
     * @return array{nom: string, initiales: string, teinte: int}
     */
    public static function client(Commandes $commande): array
    {
        $client = $commande->getClients();
        $nom = null !== $client ? $client->getNomComplet() : 'Client comptoir';

        return ['nom' => $nom, 'initiales' => self::initiales($nom), 'teinte' => ($client?->getId() ?? 0) % 6];
    }

    /** « Hôtel Niger » → « HN », « Pharmacie du Fleuve » → « PF », « École Les Étoiles » → « ÉL ». */
    public static function initiales(string $nom): string
    {
        $petits = ['de', 'du', 'des', 'd', 'la', 'le', 'l', 'et', '&', 'sa', 'sarl', 'ets'];
        $mots = array_values(array_filter(
            preg_split('/[\s\-\'’.]+/u', trim($nom)) ?: [],
            static fn (string $m): bool => '' !== $m && !\in_array(mb_strtolower($m), $petits, true)
        ));
        if ([] === $mots) {
            return mb_strtoupper(mb_substr(trim($nom), 0, 2)) ?: '?';
        }
        $second = \count($mots) > 1 ? mb_substr($mots[1], 0, 1) : mb_substr($mots[0], 1, 1);

        return mb_strtoupper(mb_substr($mots[0], 0, 1).$second);
    }

    private static function dernierControle(CommandesDetails $ligne): ?ControlePrePresse
    {
        $dernier = null;
        foreach ($ligne->getControlesPrePresse() as $controle) {
            if (null === $dernier || $controle->getId() > $dernier->getId()) {
                $dernier = $controle;
            }
        }

        return $dernier;
    }
}
