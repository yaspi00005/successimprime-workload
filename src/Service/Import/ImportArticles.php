<?php

namespace App\Service\Import;

use App\Entity\ArticleFournisseur;
use App\Entity\Articles;
use App\Entity\FamilleArticle;
use App\Entity\Fournisseurs;
use App\Entity\ImportDonnees;
use App\Service\Catalogue\NomCommercial;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Import des articles de stock (matières, encres, consommables, articles
 * revendus) : désignation, famille, unité, prix de vente, et le prix
 * d'achat pour qui a le droit de voir les coûts (docs/import-donnees.md).
 *
 * Mêmes règles que la fiche article saisie à la main : référence unique,
 * fabriquée depuis le préfixe de la famille si elle manque, unité parmi
 * celles du stock, fournisseur principal qui porte le prix d'achat.
 * Le stock de départ ne s'importe pas ici : il se compte avec l'inventaire,
 * qui le valorise.
 *
 * Les articles de stock restent à l'ERP local (ils ne sont pas synchronisés).
 */
final class ImportArticles implements TypeImport
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly References $references,
    ) {
    }

    public function cle(): string
    {
        return 'articles';
    }

    public function libelle(): string
    {
        return 'Articles de stock';
    }

    public function description(): string
    {
        return 'Matières, encres, consommables et articles revendus : désignation, famille, unité, prix de vente et d\'achat.';
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
            ['cle' => 'designation', 'libelle' => 'Désignation', 'alias' => ['nom', 'article', 'libelle', 'produit'], 'obligatoire' => true,
                'exemples' => ['Bâche 510 g laize 3,20 m', 'Encre solvant cyan'], 'aide' => 'Le nom de l\'article.'],
            ['cle' => 'nomCommercial', 'libelle' => 'Nom commercial', 'alias' => ['nom usuel', 'appellation', 'nom courant', 'nom connu'], 'obligatoire' => false,
                'exemples' => ['', 'Cartouche d\'encre cyan'], 'aide' => 'Pour un article vendu tel quel : le nom que tout le monde connaît, montré au client avant la désignation. Vide : aucun.'],
            ['cle' => 'famille', 'libelle' => 'Famille', 'alias' => ['categorie', 'groupe', 'rayon'], 'obligatoire' => false,
                'exemples' => ['Supports', 'Encres'], 'aide' => 'Une famille du stock, par son nom ou son préfixe. Vide : sans famille.'],
            ['cle' => 'unite', 'libelle' => 'Unité', 'alias' => ['unite de stock', 'u'], 'obligatoire' => false,
                'exemples' => ['m²', 'litre'], 'aide' => 'pièce, feuille, paquet, carton, rouleau, m, m², kg ou L. Vide : pièce.'],
            ['cle' => 'reference', 'libelle' => 'Référence', 'alias' => ['ref', 'code', 'code article'], 'obligatoire' => false,
                'exemples' => ['', 'ENC-010'], 'aide' => 'Unique. Vide : fabriquée avec le préfixe de la famille (MAT-007).'],
            ['cle' => 'prixVente', 'libelle' => 'Prix de vente', 'alias' => ['prix', 'pv', 'prix public', 'prix unitaire'], 'obligatoire' => false,
                'exemples' => ['', '35 000'], 'aide' => 'En francs, par unité, pour un article revendu tel quel.'],
            ['cle' => 'prixAchat', 'libelle' => 'Prix d\'achat', 'alias' => ['pa', 'cout', 'cout d achat', 'prix de revient', 'cout unitaire'], 'obligatoire' => false,
                'exemples' => ['650', '28 000'], 'aide' => 'En francs, par unité de stock. Colonne lue seulement si vous avez le droit de voir les coûts.', 'droit' => 'ROLE_COUT_MARGE'],
            ['cle' => 'stockMin', 'libelle' => 'Seuil minimum', 'alias' => ['stock minimum', 'stock min', 'seuil', 'seuil d alerte', 'minimum'], 'obligatoire' => false,
                'exemples' => ['50', '2'], 'aide' => 'En dessous, l\'article passe « à commander ». Vide : 0.'],
            ['cle' => 'fournisseur', 'libelle' => 'Fournisseur', 'alias' => ['fournisseur principal'], 'obligatoire' => false,
                'exemples' => ['Sahel Supplies', 'Encres Pro'], 'aide' => 'Un fournisseur déjà enregistré, par son nom. Inconnu : l\'article est créé sans fournisseur.'],
            ['cle' => 'vendable', 'libelle' => 'Vendable', 'alias' => ['vendu', 'a la vente', 'revendu'], 'obligatoire' => false,
                'exemples' => ['non', 'oui'], 'aide' => 'oui ou non. Vide : oui s\'il a un prix de vente.'],
        ];
    }

    public function notes(): array
    {
        return [
            'Une ligne par article. Seule la désignation est obligatoire.',
            'Un article qui existe déjà (même référence, sinon même désignation) est écarté : rien n\'est écrasé.',
            'Nom commercial : le nom que tout le monde connaît (« Autocollant » pour « Vinyle adhésif »), pour les articles vendus tels quels. La recherche trouve l\'un ou l\'autre.',
            'Le stock de départ ne s\'importe pas : comptez-le avec l\'inventaire, qui le valorise au prix d\'achat.',
            'Les familles et les fournisseurs ne sont pas créés par l\'import : créez-les d\'abord.',
        ];
    }

    public static function unite(string $brut): ?string
    {
        $c = Valeurs::cle(str_replace('²', '2', $brut));
        if ('' === $c) {
            return 'unite';
        }
        foreach (Articles::UNITES_COURTES as $cle => $libelle) {
            if ($c === Valeurs::cle($cle) || $c === Valeurs::cle(str_replace('²', '2', $libelle))) {
                return $cle;
            }
        }

        return match ($c) {
            'pieces', 'pce', 'pc', 'u', 'unites' => 'unite',
            'feuilles', 'f' => 'feuille',
            'paquets', 'pqt' => 'paquet',
            'cartons', 'ctn' => 'carton',
            'rouleaux', 'rlx', 'rl' => 'rouleau',
            'metre', 'metres', 'ml', 'metre lineaire' => 'metre',
            'metre carre', 'metres carres' => 'm2',
            'kilo', 'kilos', 'kilogramme', 'kilogrammes' => 'kg',
            'l', 'litres', 'lt' => 'litre',
            default => null,
        };
    }

    public function analyser(array $lignes, ContexteImport $contexte): void
    {
        $parReference = [];
        $parDesignation = [];
        foreach ($this->em->createQuery('SELECT a.id, a.reference, a.designation FROM '.Articles::class.' a')->getArrayResult() as $a) {
            $parReference[mb_strtoupper((string) $a['reference'])] = $a;
            $parDesignation[Valeurs::cle((string) $a['designation'])] = $a;
        }
        $familles = [];
        foreach ($this->em->getRepository(FamilleArticle::class)->findAll() as $f) {
            $familles[Valeurs::cle($f->getNom())] = $f;
            $familles[Valeurs::cle($f->getPrefixe())] ??= $f;
        }
        $fournisseurs = [];
        foreach ($this->em->createQuery('SELECT f.id, f.nom FROM '.Fournisseurs::class.' f')->getArrayResult() as $f) {
            $fournisseurs[Valeurs::cle((string) $f['nom'])] ??= $f;
        }
        // Prochain numéro par préfixe, comme la fiche article (MAT-007).
        $numeros = [];
        foreach (array_keys($parReference) as $ref) {
            if (preg_match('/^(.+)-(\d+)$/', $ref, $m)) {
                $numeros[$m[1]] = max($numeros[$m[1]] ?? 0, (int) $m[2]);
            }
        }
        $vusDesignation = [];
        $vusReference = [];
        $coutsLus = $contexte->peut('ROLE_COUT_MARGE');

        foreach ($lignes as $ligne) {
            $designation = Valeurs::texte($ligne->valeur('designation'));
            $ligne->libelle = $designation ?? '';
            if (null === $designation) {
                $ligne->erreur('La désignation est obligatoire.');
                continue;
            }
            $famille = null;
            if ('' !== $ligne->valeur('famille')) {
                $famille = $familles[Valeurs::cle($ligne->valeur('famille'))] ?? null;
                if (null === $famille) {
                    $ligne->erreur(sprintf('Famille « %s » inconnue : créez-la d\'abord dans le stock.', $ligne->valeur('famille')));
                    continue;
                }
            }
            $unite = self::unite($ligne->valeur('unite'));
            if (null === $unite) {
                $ligne->erreur(sprintf('Unité « %s » inconnue : %s.', $ligne->valeur('unite'), implode(', ', array_unique(Articles::UNITES_COURTES))));
                continue;
            }
            try {
                $prixVente = '' !== $ligne->valeur('prixVente') ? Valeurs::montant($ligne->valeur('prixVente')) : null;
                $prixAchat = $coutsLus && '' !== $ligne->valeur('prixAchat') ? Valeurs::montant($ligne->valeur('prixAchat')) : null;
            } catch (\InvalidArgumentException $e) {
                $ligne->erreur($e->getMessage());
                continue;
            }
            $stockMin = 0.0;
            if ('' !== $ligne->valeur('stockMin')) {
                $stockMin = Valeurs::nombre($ligne->valeur('stockMin'));
                if (null === $stockMin || $stockMin < 0) {
                    $ligne->erreur(sprintf('Seuil minimum « %s » : un nombre positif.', $ligne->valeur('stockMin')));
                    continue;
                }
            }
            $vendable = Valeurs::booleen($ligne->valeur('vendable'));
            if (null === $vendable) {
                $ligne->erreur(sprintf('Vendable « %s » : oui ou non.', $ligne->valeur('vendable')));
                continue;
            }
            if ('' === $ligne->valeur('vendable')) {
                $vendable = null !== $prixVente;
            }
            $fournisseur = null;
            if ('' !== $ligne->valeur('fournisseur')) {
                $fournisseur = $fournisseurs[Valeurs::cle($ligne->valeur('fournisseur'))] ?? null;
                if (null === $fournisseur) {
                    $ligne->avertir(sprintf('Fournisseur « %s » inconnu : article créé sans fournisseur.', $ligne->valeur('fournisseur')));
                }
            }

            $reference = null !== Valeurs::texte($ligne->valeur('reference')) ? mb_strtoupper(Valeurs::texte($ligne->valeur('reference'), 100) ?? '') : null;
            $cle = Valeurs::cle($designation);
            $existant = (null !== $reference ? ($parReference[$reference] ?? null) : null) ?? $parDesignation[$cle] ?? null;
            if (null !== $existant) {
                $ligne->ignorer(sprintf('Déjà en stock : %s %s.', $existant['reference'], $existant['designation']));
                continue;
            }
            if (isset($vusDesignation[$cle])) {
                $ligne->ignorer(sprintf('Déjà à la ligne %d (même désignation).', $vusDesignation[$cle]));
                continue;
            }
            if (null !== $reference && isset($vusReference[$reference])) {
                $ligne->erreur(sprintf('Référence %s déjà donnée à la ligne %d.', $reference, $vusReference[$reference]));
                continue;
            }
            if (null === $reference) {
                $prefixe = mb_strtoupper(null !== $famille && '' !== $famille->getPrefixe() ? $famille->getPrefixe() : 'ART');
                do {
                    $numeros[$prefixe] = ($numeros[$prefixe] ?? 0) + 1;
                    $reference = sprintf('%s-%03d', $prefixe, $numeros[$prefixe]);
                } while (isset($parReference[$reference]) || isset($vusReference[$reference]));
            }
            $vusDesignation[$cle] = $ligne->numero;
            $vusReference[$reference] = $ligne->numero;

            $ligne->statut = LigneImport::CREER;
            $ligne->detail = implode(' · ', array_filter([
                $reference,
                $famille?->getNom(),
                Articles::UNITES_COURTES[$unite],
                null !== $prixVente ? 'vente '.Valeurs::francs($prixVente) : null,
                null !== $prixAchat ? 'achat '.Valeurs::francs($prixAchat) : null,
                $fournisseur['nom'] ?? null,
            ]));
            $ligne->donnees = [
                'designation' => $designation, 'nomCommercial' => NomCommercial::nettoyer($ligne->valeur('nomCommercial')), 'reference' => $reference, 'famille' => $famille?->getId(), 'unite' => $unite,
                'prixVente' => $prixVente, 'prixAchat' => $prixAchat, 'stockMin' => $stockMin, 'vendable' => $vendable,
                'fournisseur' => $fournisseur['id'] ?? null,
            ];
        }
    }

    public function appliquer(array $lignes, ContexteImport $contexte, ImportDonnees $import): void
    {
        $crees = [];
        foreach ($lignes as $ligne) {
            $d = $ligne->donnees;
            if (LigneImport::CREER !== $ligne->statut) {
                $import->noter(['ligne' => $ligne->numero, 'statut' => $ligne->statut, 'libelle' => $ligne->libelle, 'raison' => $ligne->raison]);
                continue;
            }
            $article = (new Articles())
                ->setReference($d['reference'])
                ->setDesignation($d['designation'])
                ->setNomCommercial($d['nomCommercial'] ?? null)
                ->setFamille(null !== $d['famille'] ? $this->em->getReference(FamilleArticle::class, $d['famille']) : null)
                ->setUnite($d['unite'])
                ->setPrixVente($d['prixVente'])
                ->setStockMin($d['stockMin'])
                ->setActif(true)
                ->setVendable($d['vendable']);
            if (null !== $d['fournisseur']) {
                $fournisseur = $this->em->getReference(Fournisseurs::class, $d['fournisseur']);
                $article->addFournisseur((new ArticleFournisseur())->setFournisseur($fournisseur)->setPrixAchat($d['prixAchat'])->setPrincipal(true));
                $article->setFournisseur($fournisseur);
            }
            if (null !== $d['prixAchat']) {
                $article->setPrixAchat($d['prixAchat']);
            }
            $this->em->persist($article);
            $crees[] = [$ligne, $article];
        }
        $this->em->flush();
        foreach ($crees as [$ligne, $article]) {
            $import->noter(['ligne' => $ligne->numero, 'statut' => LigneImport::CREER, 'id' => $article->getId(), 'code' => $article->getReference(), 'libelle' => $article->getDesignation()]);
        }
    }

    public function blocagesAnnulation(ImportDonnees $import): array
    {
        $references = $this->references->compter(Articles::class, self::crees($import), [ArticleFournisseur::class]);

        return [] !== $references ? [sprintf('Des articles créés par cet import ont servi depuis : %s.', References::phrase($references))] : [];
    }

    public function annuler(ImportDonnees $import, ContexteImport $contexte): void
    {
        foreach (self::crees($import) as $id) {
            $article = $this->em->find(Articles::class, $id);
            if (null !== $article) {
                $this->em->remove($article);
            }
        }
        $this->em->flush();
    }

    /** @return list<int> */
    private static function crees(ImportDonnees $import): array
    {
        $ids = [];
        foreach ($import->getDetails() as $d) {
            if (LigneImport::CREER === ($d['statut'] ?? null)) {
                $ids[] = (int) $d['id'];
            }
        }

        return $ids;
    }
}
