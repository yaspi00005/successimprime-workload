<?php

namespace App\Service\Import;

use App\Entity\CategorieProduit;
use App\Entity\ImportDonnees;
use App\Entity\PrixHistorique;
use App\Entity\Produits;
use App\Service\Catalogue\NomCommercial;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import des articles du catalogue de vente (Produits) : nom, catégorie,
 * unité de vente, prix public, prix revendeur (docs/import-donnees.md).
 *
 * Un produit importé est créé « en vente » au comptoir mais pas publié
 * sur le site : il n'a encore ni type d'impression, ni support, ni format,
 * que la fiche produit demande avant de le montrer en ligne. Il part quand
 * même au site en ligne par la synchronisation habituelle (avec publie = 0),
 * comme tout produit.
 *
 * Un produit déjà au catalogue (même code, sinon même nom) n'est jamais
 * recréé ; seuls ses prix et son nom commercial peuvent changer, et chaque
 * changement de prix est tracé dans l'historique des prix. Le nom
 * commercial (docs/noms-commerciaux.md) n'est changé que s'il est donné :
 * une case vide garde celui du catalogue.
 */
final class ImportProduits implements TypeImport
{
    /** Unités de vente (modeCalcul) et les mots qui les désignent. */
    public const UNITES = [
        'unite' => ['unite', 'u', 'piece', 'pieces', 'pce', 'pc', 'article', 'unitaire'],
        'metre_carre' => ['metre carre', 'metres carres', 'm2', 'm 2', 'mc', 'au m2'],
        'metre' => ['metre', 'metres', 'm', 'ml', 'metre lineaire'],
        'forfait' => ['forfait', 'fft', 'global'],
        'heure' => ['heure', 'heures', 'h'],
        'feuille' => ['feuille', 'feuilles', 'page', 'pages'],
        'exemplaire' => ['exemplaire', 'exemplaires', 'ex', 'exp'],
        'point' => ['point', 'points'],
        'face' => ['face', 'faces'],
    ];

    public const UNITES_LIBELLES = [
        'unite' => 'unité', 'metre_carre' => 'm²', 'metre' => 'mètre', 'forfait' => 'forfait', 'heure' => 'heure',
        'feuille' => 'feuille', 'exemplaire' => 'exemplaire', 'point' => 'point', 'face' => 'face',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly References $references,
    ) {
    }

    public function cle(): string
    {
        return 'produits';
    }

    public function libelle(): string
    {
        return 'Articles du catalogue';
    }

    public function description(): string
    {
        return 'Produits vendus : nom, catégorie, unité, prix public et prix revendeur. Ils partent au site en ligne, sans y être publiés.';
    }

    public function droits(): array
    {
        return ['ROLE_ADMIN'];
    }

    public function toutOuRien(): bool
    {
        return false;
    }

    public function avecDateOuverture(): bool
    {
        return false;
    }

    public function avecCompletion(): bool
    {
        return false;
    }

    public function colonnes(): array
    {
        return [
            ['cle' => 'nom', 'libelle' => 'Nom', 'alias' => ['produit', 'article', 'designation', 'libelle', 'nom du produit'], 'obligatoire' => true,
                'exemples' => ['Flyers A5 quadri', 'Bâche publicitaire'], 'aide' => 'Nom unique au catalogue : un nom déjà pris désigne le produit existant.'],
            ['cle' => 'nomCommercial', 'libelle' => 'Nom commercial', 'alias' => ['nom usuel', 'appellation', 'nom courant', 'nom connu'], 'obligatoire' => false,
                'exemples' => ['Prospectus', 'Banderole'], 'aide' => 'Le nom que tout le monde connaît, montré au client avant le nom. Vide : celui du catalogue ne change pas.'],
            ['cle' => 'categorie', 'libelle' => 'Catégorie', 'alias' => ['famille', 'rayon', 'categorie produit', 'gamme'], 'obligatoire' => true,
                'exemples' => ['Impression numérique', 'Grand format'], 'aide' => 'Une catégorie qui n\'existe pas est créée, non publiée sur le site.'],
            ['cle' => 'unite', 'libelle' => 'Unité', 'alias' => ['unite de vente', 'mode de calcul', 'par'], 'obligatoire' => true,
                'exemples' => ['unité', 'm²'], 'aide' => 'unité, m², mètre, forfait, heure, feuille, exemplaire, point ou face.'],
            ['cle' => 'prix', 'libelle' => 'Prix public', 'alias' => ['prix', 'prix de vente', 'prix unitaire', 'pu', 'tarif', 'prix standard', 'prix b2c'], 'obligatoire' => true,
                'exemples' => ['75', '3 500'], 'aide' => 'Prix de vente en francs, par unité. Plus que 0.'],
            ['cle' => 'prixB2B', 'libelle' => 'Prix revendeur', 'alias' => ['prix b2b', 'prix pro', 'prix grossiste', 'prix revendeur b2b'], 'obligatoire' => false,
                'exemples' => ['60', '3 000'], 'aide' => 'Prix de la grille Revendeur (B2B). Pas plus que le prix public. Vide : pas de prix revendeur.'],
            ['cle' => 'code', 'libelle' => 'Code', 'alias' => ['reference', 'ref', 'code produit'], 'obligatoire' => false,
                'exemples' => ['FLY-A5', ''], 'aide' => 'Unique. Vide : un code est fabriqué à partir du nom.'],
            ['cle' => 'description', 'libelle' => 'Description', 'alias' => ['detail', 'details'], 'obligatoire' => false,
                'exemples' => ['Couché 135 g, recto verso', ''], 'aide' => 'Vide : le nom.'],
        ];
    }

    public function notes(): array
    {
        return [
            'Une ligne par produit. Les colonnes Nom, Catégorie, Unité et Prix public sont obligatoires.',
            'Un produit déjà au catalogue (même code, sinon même nom) n\'est pas recréé : seuls ses prix et son nom commercial sont mis à jour, et l\'historique des prix garde l\'ancien.',
            'Nom commercial : le nom que tout le monde connaît (« Autocollant » pour « Vinyle adhésif »). Le client le voit d\'abord, le nom à côté ; la recherche trouve l\'un ou l\'autre.',
            'Les produits créés sont en vente au comptoir mais ne sont pas publiés sur le site : complétez leurs types d\'impression, supports et formats, puis publiez-les depuis le catalogue.',
            'Pas de prix de revient ici : il se calcule depuis les articles de stock et les configurations.',
        ];
    }

    public static function unite(string $brut): ?string
    {
        $c = Valeurs::cle(str_replace('²', '2', $brut));
        if ('' === $c) {
            return null;
        }
        foreach (self::UNITES as $mode => $mots) {
            if ($c === Valeurs::cle($mode) || \in_array($c, $mots, true)) {
                return $mode;
            }
        }

        return null;
    }

    /** Code fabriqué depuis le nom : « Flyers A5 quadri » → « FLYERS-A5-QUADRI ». */
    public static function codeDepuisNom(string $nom): string
    {
        $code = strtoupper(str_replace(' ', '-', Valeurs::cle($nom)));

        return '' !== $code ? rtrim(substr($code, 0, 40), '-') : 'PRODUIT';
    }

    public function analyser(array $lignes, ContexteImport $contexte): void
    {
        $parCode = [];
        $parNom = [];
        foreach ($this->em->createQuery('SELECT p.id, p.code, p.nom, p.nomCommercial, p.prixBase, p.prixB2B, p.modeCalcul, IDENTITY(p.categorieProduit) AS categorie FROM '.Produits::class.' p')->getArrayResult() as $p) {
            $parCode[mb_strtoupper((string) $p['code'])] = $p;
            $parNom[Valeurs::cle((string) $p['nom'])] = $p;
        }
        $categories = [];
        foreach ($this->em->createQuery('SELECT c.id, c.nom FROM '.CategorieProduit::class.' c')->getArrayResult() as $c) {
            $categories[Valeurs::cle((string) $c['nom'])] = $c;
        }
        $codesPris = array_fill_keys(array_keys($parCode), true);
        $vusNom = [];
        $vusCode = [];
        $nouvellesCategories = [];

        foreach ($lignes as $ligne) {
            $nom = Valeurs::texte($ligne->valeur('nom'));
            $ligne->libelle = $nom ?? '';
            if (null === $nom) {
                $ligne->erreur('Le nom est obligatoire.');
                continue;
            }
            $nomCategorie = Valeurs::texte($ligne->valeur('categorie'));
            if (null === $nomCategorie) {
                $ligne->erreur('La catégorie est obligatoire.');
                continue;
            }
            $mode = self::unite($ligne->valeur('unite'));
            if (null === $mode) {
                $ligne->erreur('' === $ligne->valeur('unite')
                    ? 'L\'unité est obligatoire.'
                    : sprintf('Unité « %s » inconnue : %s.', $ligne->valeur('unite'), implode(', ', self::UNITES_LIBELLES)));
                continue;
            }
            if ('' === $ligne->valeur('prix')) {
                $ligne->erreur('Le prix public est obligatoire.');
                continue;
            }
            try {
                $prix = Valeurs::montant($ligne->valeur('prix'));
                $prixB2B = '' !== $ligne->valeur('prixB2B') ? Valeurs::montant($ligne->valeur('prixB2B')) : null;
            } catch (\InvalidArgumentException $e) {
                $ligne->erreur($e->getMessage());
                continue;
            }
            if ($prix <= 0) {
                $ligne->erreur('Le prix public doit être plus que 0.');
                continue;
            }
            if (null !== $prixB2B && $prixB2B > $prix) {
                $ligne->erreur(sprintf('Le prix revendeur (%s) dépasse le prix public (%s).', Valeurs::francs($prixB2B), Valeurs::francs($prix)));
                continue;
            }
            $code = null !== Valeurs::texte($ligne->valeur('code')) ? mb_strtoupper(Valeurs::texte($ligne->valeur('code'), 50) ?? '') : null;
            $cleNom = Valeurs::cle($nom);
            $nomCommercial = NomCommercial::nettoyer($ligne->valeur('nomCommercial'));

            // Déjà au catalogue : seuls les prix et le nom commercial changent.
            $existant = (null !== $code ? ($parCode[$code] ?? null) : null) ?? ($parNom[$cleNom] ?? null);
            if (null !== $existant) {
                $avant = ['prixBase' => self::entier($existant['prixBase']), 'prixB2B' => self::entier($existant['prixB2B']), 'nomCommercial' => $existant['nomCommercial']];
                $apres = ['prixBase' => $prix, 'prixB2B' => $prixB2B ?? $avant['prixB2B'], 'nomCommercial' => $nomCommercial ?? $avant['nomCommercial']];
                if (null !== $apres['prixB2B'] && $apres['prixB2B'] > $prix) {
                    $ligne->erreur(sprintf('Le prix revendeur actuel (%s) dépasse le nouveau prix public : donnez aussi le prix revendeur.', Valeurs::francs($apres['prixB2B'])));
                    continue;
                }
                if (isset($vusNom[Valeurs::cle((string) $existant['nom'])])) {
                    $ligne->ignorer(sprintf('Déjà à la ligne %d.', $vusNom[Valeurs::cle((string) $existant['nom'])]));
                    continue;
                }
                $vusNom[Valeurs::cle((string) $existant['nom'])] = $ligne->numero;
                $ligne->libelle = (string) $existant['nom'];
                if ($mode !== $existant['modeCalcul']) {
                    $ligne->avertir(sprintf('Unité différente au catalogue (%s) : non modifiée.', self::UNITES_LIBELLES[$existant['modeCalcul']] ?? $existant['modeCalcul']));
                }
                if ($avant === $apres) {
                    $ligne->ignorer(sprintf('Déjà au catalogue (%s), mêmes prix%s.', $existant['code'], null !== $nomCommercial ? ' et même nom commercial' : ''));
                    continue;
                }
                $changes = [];
                foreach (['prixBase' => 'prix public', 'prixB2B' => 'prix revendeur'] as $champ => $libelle) {
                    if ($avant[$champ] !== $apres[$champ]) {
                        $changes[] = sprintf('%s %s → %s', $libelle, null !== $avant[$champ] ? Valeurs::francs($avant[$champ]) : '—', null !== $apres[$champ] ? Valeurs::francs($apres[$champ]) : '—');
                    }
                }
                if ($avant['nomCommercial'] !== $apres['nomCommercial']) {
                    $changes[] = sprintf('nom commercial %s → %s', $avant['nomCommercial'] ?? '—', $apres['nomCommercial']);
                }
                $ligne->statut = LigneImport::MAJ;
                $ligne->detail = $existant['code'].' · '.implode(' ; ', $changes);
                $ligne->donnees = ['id' => (int) $existant['id'], 'avant' => $avant, 'apres' => $apres];
                continue;
            }

            if (isset($vusNom[$cleNom])) {
                $ligne->ignorer(sprintf('Déjà à la ligne %d (même nom).', $vusNom[$cleNom]));
                continue;
            }
            if (null !== $code && isset($vusCode[$code])) {
                $ligne->erreur(sprintf('Code %s déjà donné à la ligne %d.', $code, $vusCode[$code]));
                continue;
            }
            if (null === $code) {
                $base = self::codeDepuisNom($nom);
                $code = $base;
                for ($i = 2; isset($codesPris[$code]); ++$i) {
                    $code = $base.'-'.$i;
                }
            }
            $codesPris[$code] = true;
            $vusNom[$cleNom] = $ligne->numero;
            $vusCode[$code] = $ligne->numero;

            $cleCategorie = Valeurs::cle($nomCategorie);
            $categorie = $categories[$cleCategorie] ?? null;
            if (null === $categorie && !isset($nouvellesCategories[$cleCategorie])) {
                $nouvellesCategories[$cleCategorie] = $nomCategorie;
                $ligne->avertir(sprintf('Catégorie « %s » créée (non publiée sur le site).', $nomCategorie));
            }
            $ligne->statut = LigneImport::CREER;
            $ligne->detail = sprintf('%s%s · %s · %s par %s%s', $code, null !== $nomCommercial ? ' · « '.$nomCommercial.' »' : '', $categorie['nom'] ?? $nouvellesCategories[$cleCategorie], Valeurs::francs($prix), self::UNITES_LIBELLES[$mode], null !== $prixB2B ? ' · revendeur '.Valeurs::francs($prixB2B) : '');
            $ligne->donnees = [
                'nom' => $nom, 'nomCommercial' => $nomCommercial, 'code' => $code, 'categorie' => $categorie['id'] ?? null, 'nouvelleCategorie' => null === $categorie ? $nouvellesCategories[$cleCategorie] : null,
                'mode' => $mode, 'prix' => $prix, 'prixB2B' => $prixB2B, 'description' => Valeurs::texte($ligne->valeur('description'), 1000) ?? $nom,
            ];
        }
    }

    public function appliquer(array $lignes, ContexteImport $contexte, ImportDonnees $import): void
    {
        $ordre = (int) $this->em->createQuery('SELECT COALESCE(MAX(p.ordre), 0) FROM '.Produits::class.' p')->getSingleScalarResult();
        $ordreCategorie = (int) $this->em->createQuery('SELECT COALESCE(MAX(c.ordre), 0) FROM '.CategorieProduit::class.' c')->getSingleScalarResult();
        /** @var array<string, CategorieProduit> $creees */
        $creees = [];
        $crees = [];
        foreach ($lignes as $ligne) {
            $d = $ligne->donnees;
            if (LigneImport::CREER === $ligne->statut) {
                if (null !== $d['categorie']) {
                    $categorie = $this->em->getReference(CategorieProduit::class, $d['categorie']);
                } else {
                    $cle = Valeurs::cle($d['nouvelleCategorie']);
                    if (!isset($creees[$cle])) {
                        $ordreCategorie += 10;
                        $creees[$cle] = (new CategorieProduit())->setNom($d['nouvelleCategorie'])->setDescription($d['nouvelleCategorie'])->setPublie(false)->setOrdre($ordreCategorie);
                        $this->em->persist($creees[$cle]);
                    }
                    $categorie = $creees[$cle];
                }
                $ordre += 10;
                $produit = (new Produits())
                    ->setNom($d['nom'])->setNomCommercial($d['nomCommercial'] ?? null)->setCode($d['code'])->setDescription($d['description'])
                    ->setCategorieProduit($categorie)->setModeCalcul($d['mode'])
                    ->setPrixBase((float) $d['prix'])->setPrixB2B(null !== $d['prixB2B'] ? (float) $d['prixB2B'] : null)
                    ->setPublie(false)->setActif(true)->setPersonnalisable(true)->setOrdre($ordre);
                $this->em->persist($produit);
                $crees[] = [$ligne, $produit];
            } elseif (LigneImport::MAJ === $ligne->statut) {
                $produit = $this->em->find(Produits::class, $d['id']);
                if (null === $produit) {
                    throw new \RuntimeException(sprintf('Ligne %d : le produit n\'existe plus.', $ligne->numero));
                }
                // Le prix revendeur ne peut pas dépasser le prix public : on l'ôte d'abord.
                $produit->setPrixB2B(null)->setPrixBase((float) $d['apres']['prixBase'])->setPrixB2B(null !== $d['apres']['prixB2B'] ? (float) $d['apres']['prixB2B'] : null);
                if (($d['avant']['nomCommercial'] ?? null) !== ($d['apres']['nomCommercial'] ?? null)) {
                    $produit->setNomCommercial($d['apres']['nomCommercial']);
                }
                foreach (['prixBase', 'prixB2B'] as $champ) {
                    if ($d['avant'][$champ] !== $d['apres'][$champ]) {
                        $this->em->persist(new PrixHistorique('produit', (int) $produit->getId(), (string) $produit->getNom(), $champ, self::texte($d['avant'][$champ]), self::texte($d['apres'][$champ]), $contexte->par));
                    }
                }
                $import->noter(['ligne' => $ligne->numero, 'statut' => LigneImport::MAJ, 'id' => $produit->getId(), 'code' => $produit->getCode(), 'libelle' => $produit->getNom(), 'avant' => $d['avant'], 'apres' => $d['apres'], 'detail' => $ligne->detail]);
            } else {
                $import->noter(['ligne' => $ligne->numero, 'statut' => $ligne->statut, 'libelle' => $ligne->libelle, 'raison' => $ligne->raison]);
            }
        }
        $this->em->flush();
        foreach ($crees as [$ligne, $produit]) {
            $import->noter(['ligne' => $ligne->numero, 'statut' => LigneImport::CREER, 'id' => $produit->getId(), 'code' => $produit->getCode(), 'libelle' => $produit->getNom()]);
        }
        foreach ($creees as $categorie) {
            $import->noter(['ligne' => 0, 'statut' => 'categorie', 'id' => $categorie->getId(), 'libelle' => $categorie->getNom()]);
        }
    }

    public function blocagesAnnulation(ImportDonnees $import): array
    {
        [$crees, $majs] = self::fiches($import);
        $blocages = [];
        $references = $this->references->compter(Produits::class, $crees);
        if ([] !== $references) {
            $blocages[] = sprintf('Des produits créés par cet import ont servi depuis : %s.', References::phrase($references));
        }
        $changes = [];
        foreach ($majs as $id => $d) {
            $p = $this->em->find(Produits::class, $id);
            if (null !== $p && (self::entier($p->getPrixBase()) !== $d['apres']['prixBase'] || self::entier($p->getPrixB2B()) !== $d['apres']['prixB2B']
                || (self::nomCommercialChange($d) && $p->getNomCommercial() !== $d['apres']['nomCommercial']))) {
                $changes[] = (string) $p->getNom();
            }
        }
        if ([] !== $changes) {
            $blocages[] = sprintf('Les prix ou le nom commercial de %s ont changé depuis l\'import.', implode(', ', \array_slice($changes, 0, 5)).(\count($changes) > 5 ? '…' : ''));
        }

        return $blocages;
    }

    public function annuler(ImportDonnees $import, ContexteImport $contexte): void
    {
        [$crees, $majs, $categories] = self::fiches($import);
        foreach ($crees as $id) {
            $p = $this->em->find(Produits::class, $id);
            if (null !== $p) {
                $this->em->remove($p);
            }
        }
        foreach ($majs as $id => $d) {
            $p = $this->em->find(Produits::class, $id);
            if (null === $p) {
                continue;
            }
            $p->setPrixB2B(null)->setPrixBase((float) $d['avant']['prixBase'])->setPrixB2B(null !== $d['avant']['prixB2B'] ? (float) $d['avant']['prixB2B'] : null);
            // Le nom commercial seulement si l'import l'a changé : une case vide l'avait gardé.
            if (self::nomCommercialChange($d)) {
                $p->setNomCommercial($d['avant']['nomCommercial']);
            }
            foreach (['prixBase', 'prixB2B'] as $champ) {
                if ($d['avant'][$champ] !== $d['apres'][$champ]) {
                    $this->em->persist(new PrixHistorique('produit', $id, (string) $p->getNom(), $champ, self::texte($d['apres'][$champ]), self::texte($d['avant'][$champ]), $contexte->par));
                }
            }
        }
        $this->em->flush();
        // Les catégories créées par l'import, si plus rien ne s'y trouve.
        foreach ($categories as $id) {
            $c = $this->em->find(CategorieProduit::class, $id);
            if (null !== $c && 0 === (int) $this->em->createQuery('SELECT COUNT(p) FROM '.Produits::class.' p WHERE p.categorieProduit = :c')->setParameter('c', $c)->getSingleScalarResult()) {
                $this->em->remove($c);
            }
        }
        $this->em->flush();
    }

    /**
     * L'import a-t-il changé le nom commercial de ce produit ? Une case vide
     * le gardait : son annulation ne le remet pas, et un nom changé depuis
     * (page Noms commerciaux) ne bloque pas l'annulation des prix.
     *
     * @param array{avant: array<string, int|string|null>, apres: array<string, int|string|null>} $d
     */
    private static function nomCommercialChange(array $d): bool
    {
        return \array_key_exists('nomCommercial', $d['avant']) && $d['avant']['nomCommercial'] !== ($d['apres']['nomCommercial'] ?? null);
    }

    private static function entier(float|int|null $v): ?int
    {
        return null !== $v ? (int) round($v) : null;
    }

    private static function texte(?int $v): ?string
    {
        return null !== $v ? (string) $v : null;
    }

    /**
     * @return array{0: list<int>, 1: array<int, array{avant: array<string, int|string|null>, apres: array<string, int|string|null>}>, 2: list<int>}
     */
    private static function fiches(ImportDonnees $import): array
    {
        $crees = [];
        $majs = [];
        $categories = [];
        foreach ($import->getDetails() as $d) {
            match ($d['statut'] ?? null) {
                LigneImport::CREER => $crees[] = (int) $d['id'],
                LigneImport::MAJ => $majs[(int) $d['id']] = ['avant' => $d['avant'], 'apres' => $d['apres']],
                'categorie' => $categories[] = (int) $d['id'],
                default => null,
            };
        }

        return [$crees, $majs, $categories];
    }
}
