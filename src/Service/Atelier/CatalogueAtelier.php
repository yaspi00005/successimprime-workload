<?php

namespace App\Service\Atelier;

use App\Entity\Articles;
use App\Entity\Clients;
use App\Entity\CompteClient;
use App\Entity\ModeleAtelier;
use App\Entity\ProduitArticleStock;
use App\Entity\ProduitConfiguration;
use App\Service\EspaceClient\NouvelleCommande;
use App\Service\Promotions\CalculPromotion;
use App\Service\Promotions\ContexteLigne;
use App\Service\Promotions\Promotions;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ce que propose l'Atelier 3D (docs/atelier-3d.md) : les articles réglés
 * (ModeleAtelier) dont le produit est commandable en ligne, et leur prix.
 *
 * Le prix se calcule ici à chaque changement de la page, avec la ligne de
 * commande qui serait enregistrée : prix du catalogue (B2B pour un
 * revendeur connecté), seconde face, palier, finitions obligatoires. La
 * promotion du client est indiquée, jamais posée : l'atelier l'applique en
 * confirmant la commande (docs/promotions.md).
 */
final class CatalogueAtelier
{
    /** Modèle GLB acheté plus tard : public/modeles-3d/<forme>.glb remplace le modèle dessiné. */
    public const DOSSIER_GLB = 'modeles-3d';

    /**
     * Fiche des modèles GLB (public/modeles-3d/modeles.json) : repères pour
     * placer le marquage et crédits à afficher sous les vues.
     */
    public const FICHE_GLB = 'modeles.json';

    /**
     * Repères lus dans la fiche : direction de l'avant, puis points (unités
     * du GLB). « poitrine » est aussi le centre du devant d'une casquette.
     */
    private const REPERES = [
        'devant' => ['devant', 'front'],
        'poitrine' => ['poitrine', 'chest', 'face'],
        'dos' => ['dos', 'back'],
        'cote' => ['cote', 'côté', 'side'],
        'arriere' => ['arriere', 'arrière', 'rear'],
    ];

    /** Crédits lus dans la fiche, avec leurs autres noms. */
    private const CREDITS = [
        'titre' => ['titre', 'title', 'nom', 'name'],
        'auteur' => ['auteur', 'author'],
        'licence' => ['licence', 'license'],
        'licenceUrl' => ['licenceUrl', 'licenseUrl', 'licence_url', 'license_url'],
        'sourceUrl' => ['sourceUrl', 'source_url', 'source', 'url'],
    ];

    /** @var array<string, array<string, mixed>>|null fiche lue une fois par requête */
    private ?array $modeles3d = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NouvelleCommande $nouvelleCommande,
        private readonly TarifAtelier $tarif,
        private readonly Promotions $promotions,
        #[Autowire('%kernel.project_dir%')] private readonly string $projet,
    ) {
    }

    /**
     * Articles proposés, dans l'ordre : actifs, avec des couleurs, et dont
     * le produit est commandable en ligne (publié, avec un prix).
     *
     * @return list<ModeleAtelier>
     */
    public function modeles(): array
    {
        $commandables = [];
        foreach ($this->nouvelleCommande->produits() as $c) {
            $commandables[(int) $c->getId()] = true;
        }

        return array_values(array_filter(
            $this->em->getRepository(ModeleAtelier::class)->findBy(['actif' => true], ['ordre' => 'ASC', 'id' => 'ASC']),
            static fn (ModeleAtelier $m): bool => isset($commandables[(int) $m->getConfiguration()?->getId()]) && [] !== $m->getCouleurs(),
        ));
    }

    public function modele(mixed $publicId): ?ModeleAtelier
    {
        foreach ($this->modeles() as $m) {
            if (\is_string($publicId) && $m->getPublicId()->toRfc4122() === $publicId) {
                return $m;
            }
        }

        return null;
    }

    /**
     * Ce que la page reçoit d'un article (aucun prix : il vient de estimer()).
     *
     * @return array<string, mixed>
     */
    public function fiche(ModeleAtelier $m): array
    {
        $c = $m->getConfiguration();

        return [
            'id' => $m->getPublicId()->toRfc4122(),
            'nom' => (string) $c?->getProduit()?->getNom(),
            'forme' => $m->getForme(),
            'libelleForme' => $m->getLibelleForme(),
            'couleurs' => $m->getCouleurs(),
            'tailles' => $m->getTailles(),
            'zone' => ['largeur' => $m->getZoneLargeurCm(), 'hauteur' => $m->getZoneHauteurCm()],
            'min' => max(1, (int) $c?->getQuantiteMinimale()),
            'max' => $c?->getQuantiteMaximale(),
            'glb' => $this->glb($m->getForme()),
            'modele3d' => null !== $this->glb($m->getForme()) ? ($this->modeles3d()[$m->getForme()] ?? null) : null,
        ];
    }

    /**
     * Repères et crédits des modèles GLB déposés, par forme (fiche absente
     * ou illisible : aucun, la page se repère sur la boîte du modèle).
     *
     * @return array<string, array{reperes: array<string, list<float>>, tailleCm: ?float, credits: ?array<string, string>}>
     */
    public function modeles3d(): array
    {
        if (null === $this->modeles3d) {
            $chemin = $this->projet.'/public/'.self::DOSSIER_GLB.'/'.self::FICHE_GLB;
            $this->modeles3d = is_file($chemin) && filesize($chemin) <= 256 * 1024 ? self::lireModeles3d((string) file_get_contents($chemin)) : [];
        }

        return $this->modeles3d;
    }

    /**
     * Lit la fiche des modèles GLB sans la croire : seules les formes
     * connues, des nombres pour les repères, du texte court et des
     * adresses http(s) pour les crédits. Clés en français ou en anglais,
     * par forme (« tshirt ») ou par fichier (« tshirt.glb »).
     *
     * @return array<string, array{reperes: array<string, list<float>>, tailleCm: ?float, credits: ?array<string, string>}>
     */
    public static function lireModeles3d(string $json): array
    {
        $brut = json_decode($json, true);
        if (!\is_array($brut)) {
            return [];
        }
        $entrees = [];
        foreach ($brut as $cle => $valeur) {
            if (!\is_array($valeur)) {
                continue;
            }
            $nom = \is_string($cle) ? $cle : (string) ($valeur['fichier'] ?? $valeur['file'] ?? $valeur['forme'] ?? '');
            $forme = strtolower((string) preg_replace('/\.glb$/i', '', basename($nom)));
            if (isset(ModeleAtelier::FORMES[$forme])) {
                $entrees[$forme] = $valeur;
            }
        }

        $modeles = [];
        foreach ($entrees as $forme => $v) {
            $reperes = [];
            foreach (self::REPERES as $repere => $noms) {
                $point = self::premier($v, $noms);
                if (\is_array($point) && 3 === \count($point) && array_is_list($point) && [] === array_filter($point, static fn ($n): bool => !\is_int($n) && !\is_float($n))) {
                    $point = array_map(static fn ($n): float => max(-1000.0, min(1000.0, (float) $n)), $point);
                    if ('devant' !== $repere || abs($point[0]) + abs($point[1]) + abs($point[2]) > 0) {
                        $reperes[$repere] = $point;
                    }
                }
            }
            // Taille réelle : hauteur d'un vêtement, largeur d'une casquette (sinon le GLB est en mètres).
            $cm = self::premier($v, ['hauteurCm', 'heightCm', 'largeurCm', 'widthCm']);
            $credits = null;
            $c = self::premier($v, ['credits', 'credit', 'attribution']);
            if (\is_array($c)) {
                $credits = [];
                foreach (self::CREDITS as $champ => $noms) {
                    $texte = self::premier($c, $noms);
                    if (!\is_string($texte) || '' === trim($texte)) {
                        continue;
                    }
                    $texte = trim(mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $texte)), 0, str_ends_with($champ, 'Url') ? 500 : 160));
                    if (str_ends_with($champ, 'Url') && (!preg_match('#^https?://[^\s<>"]+$#i', $texte) || false === filter_var($texte, \FILTER_VALIDATE_URL))) {
                        continue;
                    }
                    $credits[$champ] = $texte;
                }
                if (!isset($credits['titre']) && !isset($credits['auteur'])) {
                    $credits = null;
                }
            }
            $modeles[$forme] = [
                'reperes' => $reperes,
                'tailleCm' => (\is_int($cm) || \is_float($cm)) && $cm > 0 && $cm < 1000 ? (float) $cm : null,
                'credits' => $credits,
            ];
        }

        return $modeles;
    }

    /**
     * Première valeur présente parmi ces noms.
     *
     * @param array<mixed> $tableau
     * @param list<string> $noms
     */
    private static function premier(array $tableau, array $noms): mixed
    {
        foreach ($noms as $nom) {
            if (\array_key_exists($nom, $tableau)) {
                return $tableau[$nom];
            }
        }

        return null;
    }

    /** Adresse du modèle GLB de cette forme s'il a été déposé, sinon null (modèle dessiné). */
    public function glb(string $forme): ?string
    {
        if (!isset(ModeleAtelier::FORMES[$forme])) {
            return null;
        }
        $chemin = $this->projet.'/public/'.self::DOSSIER_GLB.'/'.$forme.'.glb';

        return is_file($chemin) ? '/'.self::DOSSIER_GLB.'/'.$forme.'.glb?v='.filemtime($chemin) : null;
    }

    /**
     * Le compte voit-il les prix de l'atelier ? La règle de tout l'espace
     * client (CompteClient::montantsVisibles) : un particulier, et dans une
     * entreprise qui paie ou qui valide ; pas un demandeur ni la lecture seule.
     */
    public static function montantsVisibles(CompteClient $compte): bool
    {
        return $compte->montantsVisibles();
    }

    /**
     * Prix pour la page. Sans $montants (demandeur d'une entreprise), pas
     * un montant : seulement les paliers de quantité.
     *
     * @return array<string, mixed>
     */
    public function estimer(ModeleAtelier $m, int $quantite, int $faces, ?Clients $client, bool $montants, \DateTimeImmutable $jour): array
    {
        $config = $m->getConfiguration() ?? throw new \DomainException('Article indisponible.');
        $faces = max(1, min(2, $faces));
        $b2b = null !== $client && $client->isB2B();
        $r = ['quantite' => $quantite, 'faces' => $faces, 'montants' => $montants];
        if ($quantite < 1) {
            return $r + ['erreur' => 'Indiquez au moins une pièce.'];
        }
        try {
            $ligne = NouvelleCommande::construireLigne($config, $quantite, null, null, [], $b2b);
        } catch (\DomainException $e) {
            return $r + ['erreur' => $e->getMessage()];
        }
        $calcul = TarifAtelier::appliquer($ligne, $faces, $this->tarif->reglages(), $b2b);
        $r += ['pourcentage' => $calcul['pourcentage'], 'prochain' => $calcul['prochain']];
        if (!$montants) {
            return $r;
        }

        $finitions = 0;
        foreach ($ligne->getFinitions() as $f) {
            $finitions += max(0, (int) $f->getMontant());
        }
        $brut = $ligne->calculerMontantImpression() + $finitions;
        $r += [
            'b2b' => $b2b,
            'prixPiece' => $calcul['net'],
            'prixAvantRemise' => $calcul['prixUnitaire'],
            'remise' => $brut - $ligne->getTotalHt(),
            'finitions' => $finitions,
            'total' => $ligne->getTotalTtc(),
            'promotion' => null,
        ];

        // Promotion du client (jamais pour un visiteur ni un revendeur) : indiquée, posée par l'atelier.
        if (null !== $client && !$b2b && null !== $config->getProduit()) {
            $promos = $this->promotions->pourLeClient($client, $jour);
            if ([] !== $promos) {
                $ctx = new ContexteLigne($client, $config->getProduit(), $calcul['prixUnitaire'], $calcul['remiseUnitaire'], $quantite, $brut, $jour, $this->promotions->segmentsDe($client, null, $jour), null, [], [], (float) $config->getProduit()->getPrixBase());
                $e = CalculPromotion::evaluer($promos, $ctx);
                $montant = null !== $e['promotion'] ? CalculPromotion::montantLigne($e['remise'], $calcul['remiseUnitaire'], (float) $quantite, $brut) : 0;
                if ($montant > 0) {
                    $r['promotion'] = ['libelle' => $e['promotion']->libelleLigne(), 'montant' => $montant];
                }
            }
        }

        return $r;
    }

    /**
     * Produits textile du catalogue sans article dans l'atelier : ceux dont
     * un article de stock est de la famille « Textile ».
     *
     * @return list<ProduitConfiguration>
     */
    public function textilesSansModele(): array
    {
        $dejaLa = [];
        foreach ($this->em->getRepository(ModeleAtelier::class)->findAll() as $m) {
            $dejaLa[(int) $m->getConfiguration()?->getId()] = true;
        }
        $textiles = [];
        foreach ($this->nouvelleCommande->produits() as $c) {
            if (!isset($dejaLa[(int) $c->getId()]) && $this->estTextile($c)) {
                $textiles[] = $c;
            }
        }

        return $textiles;
    }

    private function estTextile(ProduitConfiguration $c): bool
    {
        $produit = $c->getProduit();
        if (null === $produit) {
            return false;
        }
        $articles = [$produit->getArticleStock()];
        foreach ($this->em->getRepository(ProduitArticleStock::class)->findBy(['produit' => $produit]) as $lien) {
            $articles[] = $lien->getArticle();
        }
        foreach ($articles as $a) {
            if ($a instanceof Articles && 'textile' === mb_strtolower(trim((string) $a->getFamille()?->getNom()))) {
                return true;
            }
        }

        return false;
    }
}
