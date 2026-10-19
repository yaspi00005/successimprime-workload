<?php

namespace App\Service\Achats;

use App\Entity\AchatDetail;
use App\Entity\Achats;
use App\Entity\ArticleFournisseur;
use App\Entity\Articles;
use App\Entity\Fournisseurs;
use App\Entity\StockSorties;
use App\Service\CatalogueStock;
use App\Service\Finances\Echeancier;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Chiffres des écrans Achats (maquettes SU01 à SU03, docs/achats.md) :
 * ponctualité et délai réel de chaque fournisseur sur 12 mois, achats
 * de l'année, ce qui lui est dû, prochaine commande calculée sur la
 * consommation, prix d'achat sur 12 mois et coût rendu Bamako.
 *
 * Les calculs eux-mêmes sont dans CalculsAchat (testés sans base).
 */
final class SuiviAchats
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $stats = null;

    /** @var list<Achats>|null */
    private ?array $achats = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogueStock $catalogue,
        private readonly Echeancier $echeancier,
        private readonly Parametres $parametres,
    ) {
    }

    public function seuilValidation(): int
    {
        return (int) ($this->parametres->valeur('achats', 'seuil_validation') ?? CalculsAchat::SEUIL_VALIDATION_DEFAUT);
    }

    public function margeSecurite(): int
    {
        return (int) ($this->parametres->valeur('achats', 'marge_securite_jours') ?? CalculsAchat::MARGE_SECURITE_DEFAUT);
    }

    public function joursRegroupement(): int
    {
        return (int) ($this->parametres->valeur('achats', 'jours_regroupement') ?? CalculsAchat::JOURS_REGROUPEMENT_DEFAUT);
    }

    /**
     * Toutes les commandes d'achat non annulées, avec leurs lignes.
     *
     * @return list<Achats>
     */
    public function achats(): array
    {
        return $this->achats ??= $this->em->createQueryBuilder()
            ->select('a', 'l', 'art', 'f')
            ->from(Achats::class, 'a')
            ->join('a.fournisseur', 'f')
            ->leftJoin('a.lignes', 'l')
            ->leftJoin('l.article', 'art')
            ->where('a.statut != :annule')
            ->setParameter('annule', Achats::STATUT_ANNULE)
            ->orderBy('a.dateDemande', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Date de commande d'un bon : l'envoi, à défaut la création.
     */
    public static function dateCommande(Achats $achat): \DateTimeImmutable
    {
        return $achat->getDateCommande() ?? $achat->getDateDemande() ?? new \DateTimeImmutable();
    }

    /**
     * Une commande est « passée » quand elle est partie chez le fournisseur.
     */
    public static function estPassee(Achats $achat): bool
    {
        return $achat->estEnCours() || $achat->estRecu();
    }

    /**
     * Chiffres de chaque fournisseur, par id.
     *
     * @return array<int, array{delaiReel: ?float, aLHeure: ?int, retardMoyen: ?float, abime: ?float, commandes: int, achatsAnnee: int, transports: list<int>, enCours: list<Achats>, livraisons: int}>
     */
    public function statistiques(\DateTimeImmutable $aujourdhui): array
    {
        if ($this->stats !== null) {
            return $this->stats;
        }

        $depuis = $aujourdhui->setTime(0, 0)->modify('-12 months');
        $annee = $aujourdhui->format('Y');
        $brut = [];

        foreach ($this->achats() as $achat) {
            $id = (int) $achat->getFournisseur()?->getId();
            $brut[$id] ??= ['livraisons' => [], 'recue' => 0.0, 'abimee' => 0.0, 'commandes' => 0, 'achatsAnnee' => 0, 'transports' => [], 'enCours' => []];

            if (!self::estPassee($achat)) {
                continue;
            }

            $commande = self::dateCommande($achat);

            if ($commande >= $depuis) {
                ++$brut[$id]['commandes'];
                $brut[$id]['transports'][] = $achat->getFraisTransport();
            }

            if ($commande->format('Y') === $annee) {
                $brut[$id]['achatsAnnee'] += $achat->getTotalRendu();
            }

            if ($achat->estEnCours()) {
                $brut[$id]['enCours'][] = $achat;
                continue;
            }

            if ($achat->getDateReception() !== null && $achat->getDateReception() >= $depuis) {
                $brut[$id]['livraisons'][] = ['promise' => $achat->getDatePromise(), 'recue' => $achat->getDateReception(), 'commande' => $commande];

                foreach ($achat->getLignes() as $ligne) {
                    $brut[$id]['recue'] += (float) ($ligne->getQuantiteRecue() ?? $ligne->getQuantite());
                    $brut[$id]['abimee'] += (float) $ligne->getQuantiteAbimee();
                }
            }
        }

        $stats = [];

        foreach ($brut as $id => $b) {
            $stats[$id] = [
                'delaiReel' => CalculsAchat::delaiReelMoyen($b['livraisons']),
                'aLHeure' => CalculsAchat::pourcentageALHeure($b['livraisons']),
                'retardMoyen' => CalculsAchat::retardMoyen($b['livraisons']),
                'abime' => CalculsAchat::pourcentageAbime($b['recue'], $b['abimee']),
                'commandes' => $b['commandes'],
                'achatsAnnee' => $b['achatsAnnee'],
                'transports' => $b['transports'],
                'enCours' => $b['enCours'],
                'livraisons' => \count($b['livraisons']),
            ];
        }

        return $this->stats = $stats;
    }

    /**
     * @return array{delaiReel: ?float, aLHeure: ?int, retardMoyen: ?float, abime: ?float, commandes: int, achatsAnnee: int, transports: list<int>, enCours: list<Achats>, livraisons: int}
     */
    public function statistiquesDe(Fournisseurs $fournisseur, \DateTimeImmutable $aujourdhui): array
    {
        return $this->statistiques($aujourdhui)[(int) $fournisseur->getId()]
            ?? ['delaiReel' => null, 'aLHeure' => null, 'retardMoyen' => null, 'abime' => null, 'commandes' => 0, 'achatsAnnee' => 0, 'transports' => [], 'enCours' => [], 'livraisons' => 0];
    }

    /**
     * Livraison attendue d'une commande en cours : la date promise plus
     * le retard habituel du fournisseur.
     */
    public function livraisonPrevue(Achats $achat, \DateTimeImmutable $aujourdhui): ?\DateTimeImmutable
    {
        if ($achat->getDatePromise() === null || $achat->getFournisseur() === null) {
            return $achat->getDatePromise();
        }

        return CalculsAchat::livraisonPrevue($achat->getDatePromise(), $this->statistiquesDe($achat->getFournisseur(), $aujourdhui)['retardMoyen']);
    }

    /**
     * Ce qui est dû à chaque fournisseur (factures fournisseurs à payer,
     * écran Finances › Fournisseurs), par id, avec la prochaine échéance.
     *
     * @return array<int, array{du: int, echeance: ?\DateTimeImmutable}>
     */
    public function dettes(\DateTimeImmutable $aujourdhui): array
    {
        $parFournisseur = [];

        foreach ($this->echeancier->dettes($aujourdhui) as $dette) {
            $fournisseur = ($dette['depenses'][0] ?? null)?->getFournisseur();

            if ($fournisseur === null || $dette['du'] <= 0) {
                continue;
            }

            $parFournisseur[(int) $fournisseur->getId()] = ['du' => $dette['du'], 'echeance' => $dette['echeance']];
        }

        return $parFournisseur;
    }

    /**
     * Le fournisseur habituel d'un article et le lien qui porte son prix
     * et son délai.
     *
     * @return array{0: ?Fournisseurs, 1: ?ArticleFournisseur}
     */
    public static function fournisseurDe(Articles $article): array
    {
        $lien = $article->getFournisseurPrincipal();

        return [$lien?->getFournisseur() ?? $article->getFournisseur(), $lien];
    }

    /**
     * Réapprovisionnement de chaque article actif qui a un fournisseur :
     * disponible, consommation par semaine, jours restants, délai à
     * compter, date de commande et urgence.
     *
     * @return array<int, array{article: Articles, fournisseur: Fournisseurs, lien: ?ArticleFournisseur, disponible: float, consoHebdo: float, joursRestants: ?int, delai: int, marge: int, date: ?\DateTimeImmutable, urgence: ?string, bientot: bool, unites: int}>
     */
    public function reappro(\DateTimeImmutable $aujourdhui): array
    {
        $articles = $this->em->getRepository(Articles::class)->findBy(['actif' => true], ['designation' => 'ASC']);

        if ($articles === []) {
            return [];
        }

        $stats = $this->statistiques($aujourdhui);
        $sorties = $this->sortiesRecentes($articles, $aujourdhui);
        $marge = $this->margeSecurite();
        $lignes = [];

        foreach ($this->catalogue->lignes($articles, $aujourdhui) as $ligneStock) {
            /** @var Articles $article */
            $article = $ligneStock['article'];
            [$fournisseur, $lien] = self::fournisseurDe($article);

            if ($fournisseur === null) {
                continue;
            }

            $disponible = (float) $ligneStock['disponible'];
            $conso = CalculsAchat::consommationHebdo($sorties[(int) $article->getId()] ?? 0.0);
            $jours = CalculsAchat::joursRestants($disponible, $conso);
            $delai = CalculsAchat::delaiReappro($stats[(int) $fournisseur->getId()]['delaiReel'] ?? null, $lien?->getDelaiJours(), $fournisseur->getDelaiPromisJours());
            $date = CalculsAchat::dateProchaineCommande($aujourdhui, $jours, $delai, $marge);
            $aCommander = CatalogueStock::aCommander($article, $disponible);

            $lignes[(int) $article->getId()] = [
                'article' => $article,
                'fournisseur' => $fournisseur,
                'lien' => $lien,
                'disponible' => $disponible,
                'consoHebdo' => $conso,
                'joursRestants' => $jours,
                'delai' => $delai,
                'marge' => $marge,
                'date' => $date,
                'urgence' => CalculsAchat::urgence($disponible, $article->getStockMin(), $date, $aujourdhui),
                'bientot' => CalculsAchat::aCommanderBientot($disponible, $article->getStockMin(), $jours, $delai, $marge),
                'unites' => $aCommander['unitesAchat'] ?? (int) max(1, ceil($aCommander['quantite'] / $article->getFacteurConversion())),
            ];
        }

        return $lignes;
    }

    /**
     * Prochaine commande de chaque fournisseur : son article le plus
     * pressé (sous le minimum, puis la date la plus proche).
     *
     * @param array<int, array<string, mixed>> $reappro
     *
     * @return array<int, array<string, mixed>> par id de fournisseur
     */
    public static function prochainesCommandes(array $reappro): array
    {
        $rang = [CalculsAchat::URGENCE_SOUS_MINIMUM => 0, CalculsAchat::URGENCE_MAINTENANT => 1, CalculsAchat::URGENCE_BIENTOT => 2, CalculsAchat::URGENCE_PLUS_TARD => 3];
        $prochaines = [];

        foreach ($reappro as $ligne) {
            if ($ligne['urgence'] === null) {
                continue;
            }

            $id = (int) $ligne['fournisseur']->getId();
            $actuelle = $prochaines[$id] ?? null;
            $cle = [$rang[$ligne['urgence']], $ligne['date']?->getTimestamp() ?? 0];

            if ($actuelle === null || $cle < [$rang[$actuelle['urgence']], $actuelle['date']?->getTimestamp() ?? 0]) {
                $prochaines[$id] = $ligne;
            }
        }

        return $prochaines;
    }

    /**
     * Nom court d'un article : « Bâche 510 g frontlit · laize 3,20 m »
     * donne « Bâche 510 g frontlit ».
     */
    public static function nomCourt(Articles $article): string
    {
        return trim(explode(' · ', (string) $article->getDesignation())[0]);
    }

    /**
     * Lignes d'une commande avec leur quantité en stock, leur part de
     * transport et de transit, et leur coût de revient par unité de stock.
     *
     * @return array{lignes: list<array{ligne: AchatDetail, article: Articles, quantiteStock: float, unite: string, partTransport: int, partTransit: int, coutRevient: ?int}>, quantiteTotale: float, memeUnite: bool, unite: ?string, transportParUnite: ?int, transitParUnite: ?int}
     */
    public static function detailCommande(Achats $achat): array
    {
        $lignes = [];
        $pourRepartir = [];

        foreach ($achat->getLignes() as $ligne) {
            $article = $ligne->getArticle();

            if ($article === null) {
                continue;
            }

            $quantiteStock = $ligne->getQuantiteStock() ?? $article->convertirAchatEnStock((float) $ligne->getQuantite());
            $lignes[] = ['ligne' => $ligne, 'article' => $article, 'quantiteStock' => $quantiteStock, 'unite' => $article->getUniteCourte()];
            $pourRepartir[] = ['quantite' => $quantiteStock, 'unite' => $article->getUniteCourte(), 'montant' => $ligne->getTotalHt()];
        }

        $transport = CalculsAchat::repartirFrais($pourRepartir, $achat->getFraisTransport());
        $transit = CalculsAchat::repartirFrais($pourRepartir, $achat->getFraisTransit());
        $memeUnite = CalculsAchat::memeUnite($pourRepartir);
        $total = array_sum(array_column($pourRepartir, 'quantite'));

        foreach ($lignes as $i => $l) {
            $lignes[$i]['partTransport'] = $transport[$i];
            $lignes[$i]['partTransit'] = $transit[$i];
            $lignes[$i]['coutRevient'] = CalculsAchat::coutRevientUnitaire($l['ligne']->getTotalHt(), $transport[$i] + $transit[$i], $l['quantiteStock']);
        }

        return [
            'lignes' => $lignes,
            'quantiteTotale' => $total,
            'memeUnite' => $memeUnite,
            'unite' => $memeUnite && $lignes !== [] ? $lignes[0]['unite'] : null,
            'transportParUnite' => $memeUnite ? CalculsAchat::fraisParUnite($achat->getFraisTransport(), $total) : null,
            'transitParUnite' => $memeUnite ? CalculsAchat::fraisParUnite($achat->getFraisTransit(), $total) : null,
        ];
    }

    /**
     * Enregistre sur chaque ligne sa quantité en stock et son coût de revient.
     */
    public static function figerCouts(Achats $achat): void
    {
        foreach (self::detailCommande($achat)['lignes'] as $l) {
            $l['ligne']->setQuantiteStock($l['quantiteStock']);
            $l['ligne']->setCoutRevientUnitaire($l['coutRevient']);
        }
    }

    /**
     * Produits achetés chez ce fournisseur (maquette SU02) : dernier prix
     * par unité d'achat, courbe sur 12 mois, variation, coût rendu Bamako
     * par unité de stock.
     *
     * @return list<array{article: Articles, dernierPrix: ?int, serie: list<?int>, points: string, variation: ?int, coutRendu: ?int}>
     */
    public function produits(Fournisseurs $fournisseur, \DateTimeImmutable $aujourdhui): array
    {
        $articles = [];

        foreach ($this->em->getRepository(ArticleFournisseur::class)->findBy(['fournisseur' => $fournisseur]) as $lien) {
            $articles[(int) $lien->getArticle()?->getId()] = ['article' => $lien->getArticle(), 'lien' => $lien, 'achats' => [], 'dernier' => null];
        }

        foreach ($this->achats() as $achat) {
            if ($achat->getFournisseur() !== $fournisseur || !self::estPassee($achat)) {
                continue;
            }

            $detail = null;

            foreach ($achat->getLignes() as $ligne) {
                $article = $ligne->getArticle();
                if ($article === null || $ligne->getPrixUnitaire() <= 0) {
                    continue;
                }

                $id = (int) $article->getId();
                $articles[$id] ??= ['article' => $article, 'lien' => null, 'achats' => [], 'dernier' => null];
                $articles[$id]['achats'][] = ['date' => self::dateCommande($achat), 'prix' => $ligne->getPrixUnitaire()];

                // Les commandes sont lues de la plus récente à la plus ancienne.
                if ($articles[$id]['dernier'] === null) {
                    $detail ??= self::detailCommande($achat);
                    $cout = $ligne->getCoutRevientUnitaire();
                    foreach ($detail['lignes'] as $l) {
                        if ($l['ligne'] === $ligne) {
                            $cout ??= $l['coutRevient'];
                        }
                    }
                    $articles[$id]['dernier'] = ['prix' => $ligne->getPrixUnitaire(), 'cout' => $cout];
                }
            }
        }

        $produits = [];

        foreach ($articles as $a) {
            if (!$a['article'] instanceof Articles) {
                continue;
            }

            $serie = CalculsAchat::prixParMois($a['achats'], $aujourdhui);
            $prixLien = $a['lien']?->getPrixAchat();
            $produits[] = [
                'article' => $a['article'],
                'dernierPrix' => $a['dernier']['prix'] ?? $prixLien,
                'serie' => $serie,
                'points' => CalculsAchat::pointsCourbe($serie),
                'variation' => CalculsAchat::variation($serie),
                'coutRendu' => $a['dernier']['cout'] ?? ($prixLien !== null ? (int) round($prixLien / $a['article']->getFacteurConversion()) : null),
            ];
        }

        usort($produits, static fn (array $x, array $y): int => strcmp((string) $x['article']->getDesignation(), (string) $y['article']->getDesignation()));

        return $produits;
    }

    /**
     * Prochain numéro de commande d'achat de l'année (CA-2026-0042).
     */
    public function prochainNumero(\DateTimeImmutable $date): string
    {
        $annee = (int) $date->format('Y');
        $dernier = $this->em->createQueryBuilder()
            ->select('MAX(a.numero)')
            ->from(Achats::class, 'a')
            ->where('a.numero LIKE :prefixe')
            ->setParameter('prefixe', sprintf('CA-%d-%%', $annee))
            ->getQuery()
            ->getSingleScalarResult();

        return CalculsAchat::numeroSuivant($annee, $dernier !== null ? (string) $dernier : null);
    }

    /**
     * Quantités sorties du stock sur la période de mesure, par article.
     *
     * @param list<Articles> $articles
     *
     * @return array<int, float>
     */
    private function sortiesRecentes(array $articles, \DateTimeImmutable $aujourdhui): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(s.article) AS article, COALESCE(SUM(s.quantite), 0) AS total')
            ->from(StockSorties::class, 's')
            ->where('s.article IN (:articles)')
            ->andWhere('s.date >= :depuis')
            ->groupBy('s.article')
            ->setParameter('articles', $articles)
            ->setParameter('depuis', $aujourdhui->setTime(0, 0)->modify(sprintf('-%d days', CalculsAchat::JOURS_CONSOMMATION)))
            ->getQuery()
            ->getArrayResult();

        $totaux = [];

        foreach ($lignes as $ligne) {
            $totaux[(int) $ligne['article']] = (float) $ligne['total'];
        }

        return $totaux;
    }
}
