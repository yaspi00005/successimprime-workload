<?php

namespace App\Service\Production;

use App\Entity\BonLivraison;
use App\Entity\Clients;
use App\Entity\ColisProduction;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\OrdreProduction;
use App\Entity\User;
use App\Repository\ColisProductionRepository;
use App\Repository\ControleFinalRepository;
use App\Service\Atelier\PosteMachiniste;
use App\Service\Livraisons\ProgrammationLivraison;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les colis d'une commande (PD05, PD06, PD07 ; docs/production-colis.md) :
 * travaux terminés sans étiquette, colis conseillés et leur contenu,
 * impression d'une série d'étiquettes (l'ancienne série est remplacée),
 * scan de chaque colis et passage de la commande à « Prête » quand le
 * contrôle final est validé et tous les colis scannés. Aucun montant.
 */
final class ColisCommandes
{
    /** Un travail terminé reste « à étiqueter » pendant ce nombre de jours. */
    public const JOURS_VISIBLES = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PosteMachiniste $poste,
        private readonly ColisProductionRepository $colis,
        private readonly ControleFinalRepository $controles,
    ) {
    }

    /**
     * PD05 « Travaux terminés · sans étiquette » : une carte par commande
     * qui a un travail terminé pas encore dans un colis, la livraison la
     * plus proche d'abord.
     *
     * @return list<array<string, mixed>>
     */
    public function aEtiqueter(\DateTimeImmutable $maintenant): array
    {
        $parCommande = [];
        foreach ($this->ordresTermines(null, $maintenant) as $o) {
            $c = $o->getCommandeDetail()?->getCommande();
            if ($c !== null) {
                $parCommande[(int) $c->getId()][] = $o;
            }
        }
        $actifs = $this->colis->actifsDe(array_keys($parCommande));
        $cartes = [];
        foreach ($parCommande as $id => $ordres) {
            $couverts = self::ordresCouverts($actifs[$id] ?? []);
            if ([] === array_filter($ordres, static fn (OrdreProduction $o): bool => !isset($couverts[(int) $o->getId()]))) {
                continue;
            }
            $commande = $ordres[0]->getCommandeDetail()->getCommande();
            $lignes = self::lignesPourColis($ordres);
            $cartes[] = [
                'commande' => $commande,
                'client' => self::client($commande->getClients()),
                'resume' => self::resume($lignes),
                'colis' => ColisSuggeres::pourCommande($lignes),
                'cartons' => [] === array_filter($lignes, static fn (array $l): bool => NomsProduits::CARTON !== $l['famille']),
                'remplacees' => \count($actifs[$id] ?? []),
                'enProduction' => $this->lignesEnProduction($commande),
                'livraison' => $this->livraison($commande),
            ];
        }
        // Les commandes entièrement terminées d'abord (une commande dont un travail tourne encore sera réétiquetée), puis la livraison la plus proche.
        usort($cartes, static fn (array $a, array $b): int => [$a['enProduction'] > 0, $a['livraison']['jour'] ?? new \DateTimeImmutable('2999-01-01'), $a['commande']->getId()] <=> [$b['enProduction'] > 0, $b['livraison']['jour'] ?? new \DateTimeImmutable('2999-01-01'), $b['commande']->getId()]);

        return $cartes;
    }

    /**
     * Ce qu'on va étiqueter pour une commande : ses travaux terminés pas
     * encore prêts, le nombre de colis (conseillé, ou $n choisi) et le
     * contenu de chacun.
     *
     * @return array<string, mixed>
     */
    public function preparation(Commandes $commande, ?int $n, \DateTimeImmutable $maintenant): array
    {
        $ordres = $this->ordresTermines($commande, $maintenant);
        $lignes = self::lignesPourColis($ordres);
        $suggestion = [] === $lignes ? 1 : ColisSuggeres::pourCommande($lignes);
        $max = [] === $lignes ? 1 : ColisSuggeres::maximum($lignes);
        $n = max(1, min($max, $n ?? $suggestion));

        return [
            'commande' => $commande,
            'client' => self::client($commande->getClients()),
            'ordres' => $ordres,
            'lignes' => $lignes,
            'resume' => self::resume($lignes),
            'suggestion' => $suggestion,
            'max' => $max,
            'n' => $n,
            'colis' => ColisSuggeres::repartir($lignes, $n),
            'actifs' => $this->colis->actifs($commande),
            'enProduction' => $this->lignesEnProduction($commande),
            'livraison' => $this->livraison($commande),
        ];
    }

    /**
     * Imprime une série d'étiquettes : un colis par étiquette, les anciens
     * colis de la commande sont remplacés (leur QR répond « étiquette
     * remplacée »). Chaque travail inclus est noté « étiqueté » (OP05).
     *
     * @return list<ColisProduction>
     */
    public function imprimer(Commandes $commande, int $n, User $par, \DateTimeImmutable $le): array
    {
        $prep = $this->preparation($commande, $n, $le);
        if ([] === $prep['ordres']) {
            throw new \LogicException('Aucun travail terminé à étiqueter pour cette commande.');
        }
        foreach ($prep['actifs'] as $ancien) {
            $ancien->annuler($le);
        }
        $serie = $this->colis->derniereSerie($commande) + 1;
        $nouveaux = [];
        foreach ($prep['colis'] as $i => $p) {
            $colis = new ColisProduction($commande, $serie, $i + 1, \count($prep['colis']), $p['emballage'], $p['contenu'], $par, $le);
            $this->em->persist($colis);
            $nouveaux[] = $colis;
        }
        // Le nombre de colis où se trouve chaque travail : l'ancien suivi par ordre (Ma file) reste juste.
        $parOrdre = [];
        foreach ($nouveaux as $colis) {
            foreach ($colis->getContenu() as $c) {
                $parOrdre[$c['ordre']][$colis->getRang()] = true;
            }
        }
        foreach ($prep['ordres'] as $o) {
            $this->poste->etiqueter($o, max(1, \count($parOrdre[(int) $o->getId()] ?? [])), $par, $le);
        }
        $this->em->flush();

        return $nouveaux;
    }

    /**
     * Scan d'un colis (PD06, téléphone, page de confirmation du QR) ; la
     * commande passe « Prête » si c'était le dernier geste qui manquait.
     *
     * @return array{nouveau: bool, prete: bool, scannes: int, total: int}
     */
    public function scanner(ColisProduction $colis, User $par, \DateTimeImmutable $le): array
    {
        $nouveau = $colis->scanner($par, $le);
        $this->em->flush();
        $prete = $this->passerPrete($colis->getCommande(), $par, $le);
        $etat = $this->etat($colis->getCommande());

        return ['nouveau' => $nouveau, 'prete' => $prete || $etat['prete'], 'scannes' => $etat['scannes'], 'total' => $etat['total']];
    }

    /**
     * Où en est la commande : colis en cours, scannés, contrôle final, prête.
     *
     * @return array{actifs: list<ColisProduction>, scannes: int, total: int, controle: ?\App\Entity\ControleFinal, prete: bool}
     */
    public function etat(Commandes $commande): array
    {
        $actifs = $this->colis->actifs($commande);
        $scannes = \count(array_filter($actifs, static fn (ColisProduction $c): bool => $c->estScanne()));

        return ['actifs' => $actifs, 'scannes' => $scannes, 'total' => \count($actifs), 'controle' => $this->controles->deLaCommande($commande), 'prete' => self::estPrete($commande)];
    }

    /** Toutes les lignes fabriquées de la commande sont prêtes (ou parties, livrées). */
    public static function estPrete(Commandes $commande): bool
    {
        $fabriquees = array_filter($commande->getCommandesDetails()->toArray(), static fn (CommandesDetails $d): bool => $d->isProductionNecessaire() && !\in_array($d->getStatutProduction(), [CommandesDetails::PRODUCTION_ANNULEE, CommandesDetails::PRODUCTION_NON_REQUISE], true));

        return [] !== $fabriquees && [] === array_filter($fabriquees, static fn (CommandesDetails $d): bool => !\in_array($d->getStatutProduction(), [CommandesDetails::PRODUCTION_PRETE_LIVRAISON, CommandesDetails::PRODUCTION_EN_LIVRAISON, CommandesDetails::PRODUCTION_LIVREE], true));
    }

    /**
     * Règle de Yaya (ReglesControleFinal::commandePrete) : contrôle final
     * validé et tous les colis scannés, plus rien en production ni de
     * travail terminé hors des colis. Chaque travail passe « prêt pour la
     * livraison » par le chemin du poste machiniste (statut de la ligne,
     * notification du livreur ou du bureau « À planifier »). Renvoie true
     * si la commande vient de passer « Prête ».
     */
    public function passerPrete(Commandes $commande, User $par, \DateTimeImmutable $le): bool
    {
        $etat = $this->etat($commande);
        if ($etat['prete'] || !ReglesControleFinal::commandePrete($etat['controle']?->estValide() ?? false, $etat['total'], $etat['scannes'])) {
            return false;
        }
        if ($this->lignesEnProduction($commande) > 0) {
            return false;
        }
        $couverts = self::ordresCouverts($etat['actifs']);
        $ordres = $this->ordresTermines($commande, $le, false);
        if ([] === $ordres || [] !== array_filter($ordres, static fn (OrdreProduction $o): bool => !isset($couverts[(int) $o->getId()]))) {
            return false;
        }
        foreach ($ordres as $o) {
            $this->poste->pretPourLivraison($o, $par, $le);
        }
        $this->em->flush();

        return true;
    }

    /**
     * Ce que montre une étiquette (aucun montant, aucun téléphone).
     *
     * @return array<string, mixed>
     */
    public function etiquette(ColisProduction $colis): array
    {
        $commande = $colis->getCommande();

        return [
            'colis' => $colis,
            'numero' => $commande->getNumero(),
            'client' => self::client($commande->getClients()),
            'contenu' => array_map(static fn (array $c): string => $c['libelle'], $colis->getContenu()),
            'livraison' => $this->livraison($commande),
            'nePasPlier' => $colis->estRoule(),
        ];
    }

    /**
     * Jour et quartier de livraison (bon, programmation du client, date
     * promise), ou retrait à l'imprimerie.
     *
     * @return array{retrait: bool, jour: ?\DateTimeImmutable, jourCourt: ?string, creneau: ?string, zone: ?string, bon: ?BonLivraison}
     */
    public function livraison(Commandes $commande): array
    {
        $bon = $this->dernierBon($commande);
        $p = $commande->getProgrammationLivraison() ?? [];
        $jour = $bon?->getPrevuLe();
        if (null === $jour && isset($p['jour'])) {
            $jour = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $p['jour']) ?: null;
        }
        $jour ??= null !== $commande->getDateLivraison() ? \DateTimeImmutable::createFromInterface($commande->getDateLivraison())->setTime(0, 0) : null;
        $client = $commande->getClients();
        $creneau = $bon?->getCreneau() ?? (isset($p['creneau']) ? (ProgrammationLivraison::CRENEAUX[$p['creneau']] ?? null) : null);

        return [
            'retrait' => !$commande->estALivrer(),
            'jour' => $jour,
            'jourCourt' => null !== $jour ? ProgrammationLivraison::jourCourt($jour) : null,
            'creneau' => $creneau,
            'zone' => $client?->getQuartier() ?: ($client?->getCommune() ?: $client?->getVille()),
            'bon' => $bon,
        ];
    }

    public function dernierBon(Commandes $commande): ?BonLivraison
    {
        foreach ($this->em->getRepository(BonLivraison::class)->findBy(['commande' => $commande], ['id' => 'DESC'], 5) as $bon) {
            if (BonLivraison::STATUT_ANNULE !== $bon->getStatut()) {
                return $bon;
            }
        }

        return null;
    }

    /** Lignes fabriquées encore à produire ou en cours (dont une pièce à refaire). */
    public function lignesEnProduction(Commandes $commande): int
    {
        return \count(array_filter($commande->getCommandesDetails()->toArray(), static fn (CommandesDetails $d): bool => $d->isProductionNecessaire() && \in_array($d->getStatutProduction(), [CommandesDetails::PRODUCTION_A_PRODUIRE, CommandesDetails::PRODUCTION_EN_COURS], true)));
    }

    /**
     * Travaux terminés et pas encore prêts (ligne « terminee »), d'une
     * commande ou de toutes ; $recents : seulement des 30 derniers jours.
     *
     * @return list<OrdreProduction>
     */
    public function ordresTermines(?Commandes $commande, \DateTimeImmutable $maintenant, bool $recents = true): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('o', 'd', 'c', 'cli')->from(OrdreProduction::class, 'o')
            ->join('o.commandeDetail', 'd')->join('d.commande', 'c')->leftJoin('c.clients', 'cli')
            ->where('o.statut = :termine AND o.pretLivraisonLe IS NULL AND d.statutProduction = :terminee AND c.deleted = false')
            ->setParameter('termine', OrdreProduction::STATUT_TERMINE)->setParameter('terminee', CommandesDetails::PRODUCTION_TERMINEE)
            ->orderBy('d.id', 'ASC')->addOrderBy('o.id', 'ASC');
        if (null !== $commande) {
            $qb->andWhere('d.commande = :commande')->setParameter('commande', $commande);
        }
        if ($recents && null === $commande) {
            $qb->andWhere('o.termineLe >= :depuis')->setParameter('depuis', $maintenant->setTime(0, 0)->modify('-'.self::JOURS_VISIBLES.' days'));
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Les lignes à mettre en colis, au format de ColisSuggeres.
     *
     * @param list<OrdreProduction> $ordres
     *
     * @return list<array{ordre: int, ligne: int, nom: string, finitions: string, quantite: int, famille: string, produit: ?array}>
     */
    public static function lignesPourColis(array $ordres): array
    {
        $lignes = [];
        foreach ($ordres as $o) {
            $d = $o->getCommandeDetail();
            if (null === $d) {
                continue;
            }
            $produit = NomsProduits::reconnaitre($d->getDesignation(), $d->getProduit()?->getNom(), $d->getProduit()?->getNomCommercial());
            $finitions = array_map(static fn ($f): string => self::finitionCourte((string) $f->getNomFinition()), $d->getFinitions()->toArray());
            $lignes[] = [
                'ordre' => (int) $o->getId(),
                'ligne' => (int) $d->getId(),
                'nom' => self::nomCourt($d, $produit),
                'finitions' => implode(' + ', array_values(array_unique(array_filter($finitions)))),
                'quantite' => max(1, (int) $d->getQuantite()),
                'famille' => $produit['famille'] ?? NomsProduits::AUTRE,
                'produit' => $produit,
            ];
        }

        return $lignes;
    }

    /** « Bâche 300 × 100 cm », « Flyers A5 », sinon la désignation. */
    public static function nomCourt(CommandesDetails $d, ?array $produit): string
    {
        if (null !== $produit) {
            // Le papier se compte au pluriel (« Flyers A5 × 500 »), le reste à l'unité (« Bâche 300 × 100 cm × 1 »).
            $mot = NomsProduits::CARTON === $produit['famille'] && (int) $d->getQuantite() > 1 ? $produit['pluriel'] : $produit['singulier'];
            $nom = mb_strtoupper(mb_substr($mot, 0, 1)).mb_substr($mot, 1);
        } else {
            $nom = trim((string) preg_replace('/\s*\([\d.,\s]+[x×][\d.,\s]+(cm|mm|m)\)/u', '', (string) ($d->getDesignation() ?: $d->getProduit()?->getNom() ?: 'Travail')));
        }
        $l = (float) ($d->getLargeur() ?? 0);
        $h = (float) ($d->getLongueur() ?? 0);
        $f = static fn (float $v): string => rtrim(rtrim(number_format($v * 100, 1, ',', ' '), '0'), ',');
        $taille = $l > 0 && $h > 0 ? $f($l).' × '.$f($h).' cm' : self::sansRedite($nom, (string) $d->getFormat()?->getNom());

        return trim($nom.' '.(string) $taille);
    }

    /** Format sans le mot déjà dit : « Cartes de visite » + « Carte 85 × 55 » → « 85 × 55 ». */
    public static function sansRedite(string $nom, string $format): string
    {
        $premier = static fn (string $t): string => rtrim(mb_strtolower(explode(' ', trim($t))[0]), 's');
        $mots = explode(' ', trim($format), 2);

        return '' !== $nom && \count($mots) > 1 && $premier($nom) === $premier($format) ? $mots[1] : trim($format);
    }

    /** « Œillets tous les 50 cm » → « œillets » ; le reste en minuscules. */
    public static function finitionCourte(string $finition): string
    {
        $f = mb_strtolower(trim($finition));

        return preg_match('/(œ|oe)illet/u', $f) === 1 ? 'œillets' : (string) preg_replace('/\s+/', ' ', $f);
    }

    /**
     * « 2 bâches roulées », « 1 kakémono », « 1 000 flyers » ; plusieurs
     * lignes : séparées par « + ».
     *
     * @param list<array{quantite: int, produit: ?array}> $lignes
     */
    public static function resume(array $lignes): string
    {
        return implode(' + ', array_map(static fn (array $l): string => NomsProduits::resume((int) $l['quantite'], $l['produit']), $lignes));
    }

    /**
     * Nom de la personne et de l'entreprise pour l'étiquette et les cartes :
     * « Awa Diarra » / « Boutique Awa Mode ».
     *
     * @return array{nom: string, societe: ?string, demo: bool, telephone: ?string}
     */
    public static function client(?Clients $client): array
    {
        if (null === $client) {
            return ['nom' => 'Client de passage', 'societe' => null, 'demo' => false, 'telephone' => null];
        }
        $personne = trim(($client->getPrenom() ?? '').' '.($client->getNom() ?? ''));
        $societe = trim((string) $client->getRaisonSociale());
        $nom = '' !== $personne ? $personne : $client->getNomComplet();

        return [
            'nom' => $nom,
            'societe' => '' !== $societe && mb_strtolower($societe) !== mb_strtolower($nom) ? $societe : null,
            'demo' => str_starts_with((string) $client->getCode(), 'DEMO-'),
            'telephone' => $client->getTelephone(),
        ];
    }

    /**
     * @param list<ColisProduction> $colis
     *
     * @return array<int, true> ids des ordres présents dans ces colis
     */
    public static function ordresCouverts(array $colis): array
    {
        $ids = [];
        foreach ($colis as $c) {
            foreach ($c->getContenu() as $p) {
                $ids[(int) $p['ordre']] = true;
            }
        }

        return $ids;
    }
}
