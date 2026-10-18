<?php

namespace App\Service\Catalogue;

use App\Entity\Articles;
use App\Entity\Finition;
use App\Entity\PrixHistorique;
use App\Entity\ProduitArticleStock;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Entity\User;
use App\Service\EspaceClient\DelaisCommande;
use App\Service\GrilleTarifaire;
use App\Service\Parametres\Parametres;
use App\Service\Parametres\ReglagesSaisie;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Options partagées (maquette PR04, docs/produits.md) : matières
 * (supports), finitions, délais et services réutilisés par tous les
 * produits, où chacune est utilisée, et les matières liées au stock.
 *
 * Changer le prix d'une finition ici change ce prix sur toutes les
 * configurations qui la proposent (avec une ligne d'historique chacune).
 */
final class OptionsPartagees
{
    public const MODES = [
        'forfait' => 'forfait',
        'unite' => 'pièce',
        'exemplaire' => 'exemplaire',
        'feuille' => 'feuille',
        'metre' => 'm',
        'metre_carre' => 'm²',
        'point' => 'point',
        'face' => 'face',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DelaisCommande $delais,
        private readonly ReglagesSaisie $saisie,
        private readonly Parametres $parametres,
    ) {
    }

    /**
     * Matières : un support par ligne, ses produits et son prix de départ.
     *
     * @return list<array{cle: string, nom: string, commercial: ?string, produits: list<Produits>, depart: ?int, unite: ?string}>
     */
    public function matieres(): array
    {
        $configs = $this->configurationsActives();
        $sortie = [];
        foreach ($this->em->getRepository(Supports::class)->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']) as $s) {
            $produits = [];
            $depart = null;
            $unite = null;
            foreach ($configs as $c) {
                if ($c->getSupport()?->getId() !== $s->getId()) {
                    continue;
                }
                $produits[$c->getProduit()->getId()] = $c->getProduit();
                if (null !== $c->getPrixBase() && (null === $depart || $c->getPrixBase() < $depart)) {
                    $depart = $c->getPrixBase();
                    $unite = CoutProduit::unite($c->getModeCalcul());
                }
            }
            $sortie[] = ['cle' => 'support-'.$s->getId(), 'nom' => (string) $s->getNom(), 'commercial' => NomCommercial::utile($s->getNomCommercial(), $s->getNom()), 'produits' => array_values($produits), 'depart' => $depart, 'unite' => $unite];
        }

        return $sortie;
    }

    /**
     * Finitions : produits qui la proposent et prix pratiqué.
     *
     * @return list<array{cle: string, finition: Finition, nom: string, commercial: ?string, produits: list<Produits>, prix: string, unique: ?int, configurations: int}>
     */
    public function finitions(): array
    {
        $parFinition = [];
        foreach ($this->em->createQueryBuilder()
            ->select('pcf', 'pc', 'p', 'f')
            ->from(ProduitConfigurationFinition::class, 'pcf')
            ->innerJoin('pcf.produitConfiguration', 'pc')->innerJoin('pc.produit', 'p')->innerJoin('pcf.finition', 'f')
            ->where('pcf.active = true')->andWhere('pc.active = true')
            ->getQuery()->getResult() as $pcf) {
            $parFinition[$pcf->getFinition()->getId()][] = $pcf;
        }

        $sortie = [];
        foreach ($this->em->getRepository(Finition::class)->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']) as $f) {
            $liens = $parFinition[$f->getId()] ?? [];
            $produits = [];
            foreach ($liens as $pcf) {
                $produits[$pcf->getProduitConfiguration()->getProduit()->getId()] = $pcf->getProduitConfiguration()->getProduit();
            }
            $resume = self::resumePrix(array_map(static fn (ProduitConfigurationFinition $l): array => ['prix' => $l->getPrix(), 'payante' => $l->isPayante(), 'mode' => $l->getModeCalcul()], $liens));
            $sortie[] = [
                'cle' => 'finition-'.$f->getId(),
                'finition' => $f,
                'nom' => (string) $f->getNom(),
                'commercial' => NomCommercial::utile($f->getNomCommercial(), $f->getNom()),
                'produits' => array_values($produits),
                'prix' => $resume['texte'],
                'unique' => $resume['unique'],
                'configurations' => \count($liens),
            ];
        }

        return $sortie;
    }

    /**
     * Texte du prix d'une finition sur ses configurations : « incluse »,
     * « +1 000 F / pièce », ou « de 500 à 1 000 F » quand il varie.
     *
     * @param list<array{prix: int, payante: bool, mode: string}> $liens
     *
     * @return array{texte: string, unique: ?int}
     */
    public static function resumePrix(array $liens): array
    {
        if ([] === $liens) {
            return ['texte' => 'pas encore proposée', 'unique' => null];
        }
        $prix = array_map(static fn (array $l): int => $l['payante'] ? max(0, $l['prix']) : 0, $liens);
        $min = min($prix);
        $max = max($prix);
        if (0 === $max) {
            return ['texte' => 'incluse', 'unique' => 0];
        }
        if ($min === $max) {
            $modes = array_unique(array_column($liens, 'mode'));
            $mode = 1 === \count($modes) ? (self::MODES[$modes[0]] ?? $modes[0]) : null;

            return ['texte' => '+'.self::francs($max).(null !== $mode && 'forfait' !== $mode ? ' / '.$mode : ''), 'unique' => $max];
        }

        return ['texte' => 'de '.number_format($min, 0, ',', "\u{202F}").' à '.self::francs($max), 'unique' => null];
    }

    /**
     * Délais de la commande (Paramètres › Espace client), proposés à tous les produits.
     *
     * @return list<array{cle: string, nom: string, valeur: string, heures: int}>
     */
    public function delais(): array
    {
        $r = $this->delais->reglages();
        $delais = [['cle' => 'delai-standard', 'nom' => 'Standard', 'valeur' => 'base', 'heures' => $r['standard']]];
        if ($r['express']) {
            $delais[] = ['cle' => 'delai-express', 'nom' => \sprintf('Express %d h', $r['delaiExpress']), 'valeur' => '+'.$r['majoration'].' %', 'heures' => $r['delaiExpress']];
        }

        return $delais;
    }

    /**
     * Services : livraison, conception, et les produits de la famille « Services ».
     *
     * @return list<array{cle: string, nom: string, valeur: string, produit: ?Produits}>
     */
    public function services(): array
    {
        $services = [];
        $finalisation = $this->parametres->valeur('conception', 'finalisation');
        if (null !== $finalisation) {
            $services[] = ['cle' => 'service-conception', 'nom' => 'Création graphique', 'valeur' => 'dès '.self::francs((int) $finalisation), 'produit' => null];
        }
        $services[] = ['cle' => 'service-livraison', 'nom' => 'Livraison à Bamako', 'valeur' => self::francs($this->saisie->fraisEnLigne()), 'produit' => null];
        foreach ($this->em->createQueryBuilder()->select('p', 'cat')->from(Produits::class, 'p')->innerJoin('p.categorieProduit', 'cat')
            ->where('LOWER(cat.nom) LIKE :service')->andWhere('p.actif = true')->setParameter('service', '%service%')
            ->orderBy('p.ordre', 'ASC')->getQuery()->getResult() as $p) {
            $prix = CoutProduit::prixBase($p, CoutProduit::configurationDeBase($p));
            $services[] = ['cle' => 'produit-'.$p->getId(), 'nom' => (string) $p->getNom(), 'valeur' => null === $prix ? 'sur devis' : 'dès '.self::francs($prix), 'produit' => $p];
        }

        return $services;
    }

    /**
     * Produits qui utilisent l'option (clé « finition-3 », « support-2 »,
     * « delai-express »…), pour « Où est utilisée ? ».
     *
     * @param list<array<string, mixed>> $options toutes les options affichées
     *
     * @return array{nom: string, produits: list<Produits>}|null
     */
    public function utilisation(string $cle, array $options): ?array
    {
        foreach ($options as $o) {
            if ($o['cle'] !== $cle) {
                continue;
            }
            if (isset($o['produits'])) {
                return ['nom' => $o['nom'], 'produits' => $o['produits']];
            }
            if (str_starts_with($cle, 'delai-')) {
                return ['nom' => $o['nom'], 'produits' => $this->em->getRepository(Produits::class)->findBy(['actif' => true, 'visibleEnLigne' => true], ['ordre' => 'ASC', 'nom' => 'ASC'])];
            }
            if (null !== ($o['produit'] ?? null)) {
                return ['nom' => $o['nom'], 'produits' => [$o['produit']]];
            }

            return ['nom' => $o['nom'], 'produits' => []];
        }

        return null;
    }

    /**
     * Matières du stock liées aux produits (nomenclature).
     *
     * @return list<array{article: Articles, format: string, produits: list<string>}>
     */
    public function matieresStock(): array
    {
        $parArticle = [];
        foreach ($this->em->createQueryBuilder()
            ->select('l', 'a', 'p')->from(ProduitArticleStock::class, 'l')
            ->innerJoin('l.article', 'a')->innerJoin('l.produit', 'p')
            ->where('l.actif = true')->orderBy('a.designation', 'ASC')
            ->getQuery()->getResult() as $l) {
            $a = $l->getArticle();
            $parArticle[$a->getId()] ??= ['article' => $a, 'format' => self::format($a), 'produits' => []];
            $parArticle[$a->getId()]['produits'][$l->getProduit()->getId()] = (string) $l->getProduit()->getNom();
        }

        return array_values(array_map(static fn (array $m): array => ['produits' => array_values($m['produits'])] + $m, $parArticle));
    }

    /**
     * Nouveau prix d'une finition sur toutes les configurations qui la
     * proposent ; 0 la rend incluse. Chaque changement va à l'historique.
     *
     * @return int nombre de configurations changées
     */
    public function appliquerPrixFinition(Finition $finition, int $prix, ?User $par): int
    {
        $prix = max(0, $prix);
        $changes = 0;
        foreach ($this->em->getRepository(ProduitConfigurationFinition::class)->findBy(['finition' => $finition]) as $pcf) {
            $avant = $pcf->isPayante() ? $pcf->getPrix() : 0;
            if ($avant === $prix && $pcf->isPayante() === ($prix > 0)) {
                continue;
            }
            $libelle = \sprintf('%s · %s', $finition->getNom(), GrilleTarifaire::libelle($pcf->getProduitConfiguration()));
            $this->em->persist(new PrixHistorique('finition', (int) $pcf->getId(), $libelle, 'prix', (string) $avant, (string) $prix, $par));
            $pcf->setPrix($prix)->setPayante($prix > 0);
            ++$changes;
        }
        $this->em->flush();

        return $changes;
    }

    /** « rouleau · laize 3,20 m × 50 m », ou l'unité de stock. */
    public static function format(Articles $a): string
    {
        $parties = [];
        if (null !== $a->getUniteAchat() && '' !== $a->getUniteAchat()) {
            $parties[] = $a->getUniteAchat();
        }
        if (null !== $a->getLaize() && null !== $a->getLongueurUniteAchat()) {
            $parties[] = rtrim(rtrim(number_format($a->getLaize(), 2, ',', ''), '0'), ',').' × '.rtrim(rtrim(number_format($a->getLongueurUniteAchat(), 2, ',', ''), '0'), ',').' m';
        } elseif (null !== $a->getQuantiteParUniteAchat() && $a->getQuantiteParUniteAchat() > 1) {
            $parties[] = rtrim(rtrim(number_format($a->getQuantiteParUniteAchat(), 2, ',', ''), '0'), ',').' '.$a->getUniteCourte();
        }

        return [] === $parties ? 'au '.$a->getUniteCourte() : implode(' · ', $parties);
    }

    /**
     * @return list<ProduitConfiguration>
     */
    private function configurationsActives(): array
    {
        return $this->em->createQueryBuilder()
            ->select('pc', 'p', 's')->from(ProduitConfiguration::class, 'pc')
            ->innerJoin('pc.produit', 'p')->leftJoin('pc.support', 's')
            ->where('pc.active = true')->andWhere('p.actif = true')
            ->orderBy('p.ordre', 'ASC')->addOrderBy('p.nom', 'ASC')
            ->getQuery()->getResult();
    }

    private static function francs(int $montant): string
    {
        return number_format($montant, 0, ',', "\u{202F}")."\u{202F}F";
    }
}
