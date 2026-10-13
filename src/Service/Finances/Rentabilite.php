<?php

namespace App\Service\Finances;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Conception;
use App\Entity\OrdreProduction;
use App\Service\CalculateurCoutRevient;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Rentabilité par commande, par produit et par machine (maquette F06).
 *
 * Le coût d'une ligne vient du calcul automatique (nomenclature des
 * articles, m² imprimés, encre, amortissement des machines à l'usage) ;
 * le coût enregistré à la saisie fait foi et la ventilation est ramenée
 * à ce montant. La main d'œuvre s'y ajoute : temps pointé en PAO et à
 * l'atelier, au coût horaire des Paramètres.
 *
 * Seules les lignes au coût complet comptent dans les marges : la part
 * du chiffre d'affaires chiffrée est rendue pour juger de la fiabilité.
 */
final class Rentabilite
{
    public const POSTES = [
        'matieres' => 'Matières',
        'encre' => 'Encre (selon m² et passes)',
        'machine' => 'Amortissement des machines à l\'usage',
        'finitions' => 'Finitions',
        'main_oeuvre' => 'Main d\'œuvre (temps pointé PAO et atelier)',
    ];

    /** Taux de marge au-dessus duquel la barre est verte, puis bleue. */
    public const SEUIL_BON = 55;
    public const SEUIL_MOYEN = 45;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CalculateurCoutRevient $calculateur,
        private readonly ReglagesFinances $reglages,
    ) {
    }

    /**
     * Marges du mois : globale, par produit et par machine.
     *
     * @return array{taux: ?int, ht: int, cout: int, marge: int, part_chiffree: ?int, produits: list<array<string, mixed>>, machines: list<array<string, mixed>>, plus_faible: ?array<string, mixed>, faibles: list<array<string, mixed>>}
     */
    public function synthese(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $chiffrees = $this->chiffrer($this->lignes($debut, $fin));
        $complets = array_values(array_filter($chiffrees, static fn (array $l): bool => $l['complet']));
        $ht = array_sum(array_column($complets, 'ht'));
        $cout = array_sum(array_column($complets, 'total'));
        $htTotal = array_sum(array_column($chiffrees, 'ht'));
        $machines = self::regrouper($complets, 'machine_nom', 8);

        return [
            'taux' => self::taux($ht, $cout),
            'ht' => $ht,
            'cout' => $cout,
            'marge' => $ht - $cout,
            'part_chiffree' => $htTotal > 0 ? (int) round($ht * 100 / $htTotal) : null,
            'produits' => self::regrouper($complets, 'produit', 6),
            'machines' => $machines,
            'plus_faible' => self::plusFaible($machines),
            'faibles' => self::commandesFaibles($complets, 5),
        ];
    }

    /** Taux de marge brute du mois, ou null sans ligne chiffrée. */
    public function tauxDuMois(\DateTimeImmutable $debut, \DateTimeImmutable $fin): ?int
    {
        $complets = array_filter($this->chiffrer($this->lignes($debut, $fin)), static fn (array $l): bool => $l['complet']);

        return self::taux(array_sum(array_column($complets, 'ht')), array_sum(array_column($complets, 'total')));
    }

    /**
     * Détail d'une commande : coût de chaque poste, marge et barre empilée.
     *
     * @return array{commande: Commandes, client: string, resume: string, ht: int, postes: list<array{cle: string, libelle: string, montant: int, part: float}>, cout: int, marge: int, taux: ?int, part_marge: float, manquants: list<string>}
     */
    public function commande(Commandes $commande): array
    {
        $lignes = array_values(array_filter(
            $commande->getCommandesDetails()->toArray(),
            static fn (CommandesDetails $l): bool => CommandesDetails::PRODUCTION_ANNULEE !== $l->getStatutProduction(),
        ));
        $chiffrees = $this->chiffrer($lignes);
        $ht = array_sum(array_column($chiffrees, 'ht'));
        $totaux = array_fill_keys(array_keys(self::POSTES), 0);
        $manquants = [];
        $produits = [];
        $matieres = [];
        foreach ($chiffrees as $l) {
            if ('Autres' !== $l['produit']) {
                $matieres[self::nomCourt($l['produit'])] = true;
            }
            foreach (array_keys(self::POSTES) as $poste) {
                $totaux[$poste] += $l[$poste];
            }
            $manquants = [...$manquants, ...$l['manquants']];
            $produits[] = $l['resume'];
        }
        $cout = array_sum($totaux);
        $base = max(1, $ht, $cout);
        $postes = [];
        foreach (self::POSTES as $cle => $libelle) {
            if ($totaux[$cle] > 0 || \in_array($cle, ['matieres', 'encre', 'machine', 'main_oeuvre'], true)) {
                if ('matieres' === $cle && [] !== $matieres) {
                    $libelle .= ' ('.self::liste(array_keys($matieres), 3).')';
                }
                $postes[] = ['cle' => $cle, 'libelle' => $libelle, 'montant' => $totaux[$cle], 'part' => round($totaux[$cle] * 100 / $base, 1)];
            }
        }

        return [
            'commande' => $commande,
            'client' => (string) ($commande->getClients()?->getNomComplet() ?? ''),
            'resume' => implode(', ', array_filter($produits)),
            'ht' => $ht,
            'postes' => $postes,
            'cout' => $cout,
            'marge' => $ht - $cout,
            'taux' => self::taux($ht, $cout),
            'part_marge' => round(max(0, $ht - $cout) * 100 / $base, 1),
            'manquants' => array_values(array_unique($manquants)),
        ];
    }

    /**
     * Commandes confirmées du mois, les plus grosses d'abord, pour choisir
     * celle qu'on détaille.
     *
     * @return list<Commandes>
     */
    public function commandesDuMois(\DateTimeImmutable $debut, \DateTimeImmutable $fin, int $limite = 60): array
    {
        return $this->em->createQueryBuilder()
            ->select('c', 'client')
            ->from(Commandes::class, 'c')
            ->leftJoin('c.clients', 'client')
            ->andWhere('c.deleted = false')
            ->andWhere('c.statut = true')
            ->andWhere('c.totalHt > 0')
            ->andWhere('c.dateCommande >= :debut AND c.dateCommande < :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->orderBy('c.totalHt', 'DESC')
            ->setMaxResults($limite)
            ->getQuery()
            ->getResult();
    }

    /**
     * Ramène la ventilation du calcul du jour au coût enregistré à la
     * saisie, qui fait foi (les prix d'achat ont pu changer depuis).
     *
     * @param array{matieres: int, encre: int, machine: int, finitions: int, total: int} $calcul
     *
     * @return array{matieres: int, encre: int, machine: int, finitions: int}
     */
    public static function ventiler(array $calcul, int $coutEnregistre): array
    {
        $postes = ['matieres' => $calcul['matieres'], 'encre' => $calcul['encre'], 'machine' => $calcul['machine'], 'finitions' => $calcul['finitions']];
        if ($coutEnregistre <= 0 || $coutEnregistre === $calcul['total']) {
            return $postes;
        }
        if ($calcul['total'] <= 0) {
            return ['matieres' => $coutEnregistre, 'encre' => 0, 'machine' => 0, 'finitions' => 0];
        }
        $facteur = $coutEnregistre / $calcul['total'];
        $ventile = array_map(static fn (int $m): int => (int) round($m * $facteur), $postes);
        // L'arrondi ne doit pas changer le total : l'écart va aux matières.
        $ventile['matieres'] += $coutEnregistre - array_sum($ventile);

        return $ventile;
    }

    /** Minutes réelles d'un ordre de production, pauses déduites. */
    public static function minutesOrdre(?\DateTimeImmutable $demarre, ?\DateTimeImmutable $termine, int $pauseMinutes): int
    {
        if (null === $demarre || null === $termine || $termine <= $demarre) {
            return 0;
        }

        return max(0, intdiv($termine->getTimestamp() - $demarre->getTimestamp(), 60) - $pauseMinutes);
    }

    public static function taux(int $ht, int $cout): ?int
    {
        return $ht > 0 ? (int) round(($ht - $cout) * 100 / $ht) : null;
    }

    /** Couleur de la barre de marge : vert, bleu, orange, rouge sous 35 %. */
    public static function couleur(?int $taux): string
    {
        return match (true) {
            null === $taux => 'gris',
            $taux >= self::SEUIL_BON => 'vert',
            $taux >= self::SEUIL_MOYEN => 'bleu',
            $taux >= 35 => 'orange',
            default => 'rouge',
        };
    }

    /**
     * Regroupe les lignes chiffrées par produit ou par machine (clé
     * 'produit' ou 'machine_nom'), du plus
     * gros chiffre d'affaires au plus petit.
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return list<array{nom: string, ht: int, cout: int, taux: ?int, couleur: string}>
     */
    public static function regrouper(array $lignes, string $cle, int $limite): array
    {
        $groupes = [];
        foreach ($lignes as $l) {
            $nom = (string) ($l[$cle] ?? '');
            if ('' === $nom) {
                continue;
            }
            $groupes[$nom] ??= ['nom' => $nom, 'ht' => 0, 'cout' => 0];
            $groupes[$nom]['ht'] += (int) $l['ht'];
            $groupes[$nom]['cout'] += (int) $l['total'];
        }
        usort($groupes, static fn (array $a, array $b): int => $b['ht'] <=> $a['ht']);
        $resultat = [];
        foreach (\array_slice($groupes, 0, $limite) as $g) {
            $taux = self::taux($g['ht'], $g['cout']);
            $resultat[] = $g + ['taux' => $taux, 'couleur' => self::couleur($taux)];
        }

        return $resultat;
    }

    /**
     * Les commandes du mois qui marginent le moins (sous le seuil « moyen »),
     * la pire d'abord : prix à revoir ou coûts à vérifier.
     *
     * @param list<array<string, mixed>> $lignes
     *
     * @return list<array{id: int, numero: string, client: string, ht: int, cout: int, taux: int, couleur: string}>
     */
    public static function commandesFaibles(array $lignes, int $limite): array
    {
        $commandes = [];
        foreach ($lignes as $l) {
            $id = (int) ($l['commande_id'] ?? 0);
            if (0 === $id) {
                continue;
            }
            $commandes[$id] ??= ['id' => $id, 'numero' => (string) $l['commande_numero'], 'client' => (string) $l['client'], 'ht' => 0, 'cout' => 0];
            $commandes[$id]['ht'] += (int) $l['ht'];
            $commandes[$id]['cout'] += (int) $l['total'];
        }
        $faibles = [];
        foreach ($commandes as $c) {
            $taux = self::taux($c['ht'], $c['cout']);
            if (null !== $taux && $taux < self::SEUIL_MOYEN) {
                $faibles[] = $c + ['taux' => $taux, 'couleur' => self::couleur($taux)];
            }
        }
        usort($faibles, static fn (array $a, array $b): int => [$a['taux'], -$a['ht']] <=> [$b['taux'], -$b['ht']]);

        return \array_slice($faibles, 0, $limite);
    }

    /**
     * La machine qui marge le moins, si l'écart avec la meilleure vaut
     * d'être signalé (10 points ou plus).
     *
     * @param list<array{nom: string, taux: ?int}> $machines
     *
     * @return array{nom: string, taux: int}|null
     */
    public static function plusFaible(array $machines): ?array
    {
        $avecTaux = array_values(array_filter($machines, static fn (array $m): bool => null !== $m['taux']));
        if (\count($avecTaux) < 2) {
            return null;
        }
        usort($avecTaux, static fn (array $a, array $b): int => $a['taux'] <=> $b['taux']);
        $pire = $avecTaux[0];
        $meilleure = end($avecTaux);

        return $meilleure['taux'] - $pire['taux'] >= 10 ? ['nom' => $pire['nom'], 'taux' => (int) $pire['taux']] : null;
    }

    /**
     * « bâche, roll-up, t-shirts » ; « bâche, roll-up… » au-delà de la limite.
     *
     * @param list<string> $noms
     */
    public static function liste(array $noms, int $limite): string
    {
        return implode(', ', \array_slice($noms, 0, $limite)).(\count($noms) > $limite ? '…' : '');
    }

    /** Nom de produit pour une liste : « Roll-up 85 × 200 cm (démo) » → « roll-up 85 × 200 cm ». */
    public static function nomCourt(string $nom): string
    {
        $nom = trim((string) preg_replace('/\s*\([^()]*\)\s*$/u', '', trim($nom)));

        return mb_strtolower(mb_substr($nom, 0, 1)).mb_substr($nom, 1);
    }

    /** Résumé d'une ligne pour l'en-tête de la commande : « 2 bâche ». */
    public static function resumeLigne(int $quantite, string $nom): string
    {
        $nom = trim($nom);

        return '' === $nom ? '' : \sprintf('%d %s', max(1, $quantite), mb_strtolower(mb_substr($nom, 0, 1)).mb_substr($nom, 1));
    }

    /**
     * @return list<CommandesDetails>
     */
    private function lignes(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        return $this->em->createQueryBuilder()
            ->select('l', 'c', 'client', 'p', 'm')
            ->from(CommandesDetails::class, 'l')
            ->innerJoin('l.commande', 'c')
            ->leftJoin('c.clients', 'client')
            ->leftJoin('l.produit', 'p')
            ->leftJoin('l.machine', 'm')
            ->andWhere('c.deleted = false')
            ->andWhere('c.statut = true')
            ->andWhere('l.statutProduction != :annulee')
            ->andWhere('c.dateCommande >= :debut AND c.dateCommande < :fin')
            ->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE)
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->getQuery()
            ->getResult();
    }

    /**
     * Coût de chaque ligne, poste par poste, main d'œuvre comprise.
     *
     * @param list<CommandesDetails> $lignes
     *
     * @return list<array{ht: int, matieres: int, encre: int, machine: int, finitions: int, main_oeuvre: int, total: int, complet: bool, manquants: list<string>, produit: string, machine_nom: string, resume: string}>
     */
    private function chiffrer(array $lignes): array
    {
        $minutes = $this->minutesPointees($lignes);
        $coutHoraire = $this->reglages->coutHoraire();
        $resultat = [];
        foreach ($lignes as $l) {
            $calcul = $this->calculateur->pourLigne($l);
            $postes = self::ventiler($calcul, $l->getCoutRevient());
            $mainOeuvre = (int) round(($minutes[(int) $l->getId()] ?? 0) / 60 * $coutHoraire);
            $complet = $l->getCoutRevient() > 0 || ([] === $calcul['manquants'] && $calcul['total'] > 0);
            $nomProduit = $l->getProduit()?->getNom() ?? '';
            $resultat[] = $postes + [
                'ht' => $l->getTotalHt(),
                'main_oeuvre' => $mainOeuvre,
                'total' => array_sum($postes) + $mainOeuvre,
                'complet' => $complet,
                'manquants' => $l->getCoutRevient() > 0 ? [] : $calcul['manquants'],
                'produit' => '' !== $nomProduit ? $nomProduit : 'Autres',
                'machine_nom' => $l->getMachine()?->getNom() ?? '',
                'commande_id' => (int) $l->getCommande()?->getId(),
                'commande_numero' => (string) $l->getCommande()?->getNumero(),
                'client' => (string) ($l->getCommande()?->getClients()?->getNomComplet() ?? ''),
                'resume' => self::resumeLigne($l->getQuantite(), '' !== $nomProduit ? $nomProduit : (string) $l->getDesignation()),
            ];
        }
        return $resultat;
    }

    /**
     * Minutes pointées par ligne : conception PAO et ordres de production.
     *
     * @param list<CommandesDetails> $lignes
     *
     * @return array<int, int>
     */
    private function minutesPointees(array $lignes): array
    {
        if ([] === $lignes) {
            return [];
        }
        $minutes = [];
        foreach ($this->em->createQueryBuilder()
            ->select('IDENTITY(controle.commandeDetail) AS ligne', 'SUM(conception.secondes) AS secondes')
            ->from(Conception::class, 'conception')
            ->innerJoin('conception.controle', 'controle')
            ->andWhere('controle.commandeDetail IN (:lignes)')
            ->setParameter('lignes', $lignes)
            ->groupBy('controle.commandeDetail')
            ->getQuery()
            ->getArrayResult() as $r) {
            $minutes[(int) $r['ligne']] = intdiv((int) $r['secondes'], 60);
        }
        foreach ($this->em->createQueryBuilder()
            ->select('IDENTITY(o.commandeDetail) AS ligne', 'o.demarreLe', 'o.termineLe', 'o.dureePauseMinutes')
            ->from(OrdreProduction::class, 'o')
            ->andWhere('o.commandeDetail IN (:lignes)')
            ->andWhere('o.termineLe IS NOT NULL')
            ->setParameter('lignes', $lignes)
            ->getQuery()
            ->getArrayResult() as $r) {
            $cle = (int) $r['ligne'];
            $minutes[$cle] = ($minutes[$cle] ?? 0) + self::minutesOrdre($r['demarreLe'], $r['termineLe'], (int) $r['dureePauseMinutes']);
        }

        return $minutes;
    }
}
