<?php

namespace App\Service\EspaceClient;

use App\Controller\SiteController;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ProduitConfiguration;
use App\Service\Catalogue\NomCommercial;
use App\Service\Site\CatalogueSite;

/**
 * Ce que montrent les quatre étapes de la nouvelle commande (maquettes
 * CO01 à CO04) : cartes des produits, matières et finitions avec leur
 * supplément, récapitulatif « Votre commande ». Sans $montants (demandeur
 * d'une entreprise, CompteClient::montantsVisibles), aucun prix n'est
 * produit : ni dans le texte, ni dans les données de la page.
 *
 * Tout est statique et pur pour être testé sans base.
 */
final class PageNouvelleCommande
{
    /** Formats courants proposés pour un produit au m², en mètres. */
    public const FORMATS_COURANTS = [[2, 1], [3, 1], [4, 2], [5, 3]];

    /** Unité du prix selon le mode de calcul. */
    private const UNITES = ['metre_carre' => 'm²', 'metre' => 'm', 'unite' => 'pièce', 'exemplaire' => 'pièce', 'feuille' => 'feuille', 'heure' => 'h', 'face' => 'face', 'point' => 'point'];

    /**
     * Étape 1 : un produit par carte (sa configuration la moins chère),
     * rangés par catégorie affichée.
     *
     * @param list<ProduitConfiguration> $configs
     *
     * @return array{categories: list<array{id: int, nom: string, ids: list<int>}>, produits: list<array<string, mixed>>}
     */
    public static function catalogue(array $configs, bool $b2b, bool $montants): array
    {
        $parProduit = [];
        foreach ($configs as $c) {
            $produit = $c->getProduit();
            if (null !== $produit) {
                $parProduit[$produit->getId()][] = $c;
            }
        }
        $categories = [];
        $produits = [];
        foreach ($parProduit as $liste) {
            $depart = self::moinsChere($liste, $b2b);
            $produit = $depart->getProduit();
            $categorie = $produit?->getCategorieProduit();
            $nomCategorie = null === $categorie ? 'Autres produits' : CatalogueSite::nomAffiche((string) $categorie->getNom());
            if (!isset($categories[$nomCategorie])) {
                $categories[$nomCategorie] = ['id' => (int) ($categorie?->getId() ?? 0), 'nom' => $nomCategorie, 'ids' => [], 'famille' => SiteController::famille($nomCategorie, \count($categories))];
            }
            if (null !== $categorie && !\in_array($categorie->getId(), $categories[$nomCategorie]['ids'], true)) {
                $categories[$nomCategorie]['ids'][] = (int) $categorie->getId();
            }
            // Le nom commercial d'abord, la désignation à côté (docs/noms-commerciaux.md).
            $noms = NomCommercial::clientDe($produit);
            $nom = CatalogueSite::nomAffiche($noms['nom']);
            $technique = null === $noms['technique'] ? null : CatalogueSite::nomAffiche($noms['technique']);
            $produits[] = [
                'config' => (int) $depart->getId(),
                'nom' => $nom,
                'technique' => $technique,
                'categorie' => $categories[$nomCategorie]['id'],
                'illustration' => CatalogueSite::illustration(trim($nom.' '.$technique), $categories[$nomCategorie]['famille']),
                'prix' => $montants ? self::prixDepart($depart, $b2b) : null,
                'info' => self::infoSansPrix($depart),
                'matieres' => \count($liste),
            ];
        }

        return ['categories' => array_values(array_map(static fn (array $c): array => ['id' => $c['id'], 'nom' => $c['nom'], 'ids' => $c['ids']], $categories)), 'produits' => $produits];
    }

    /**
     * Configurations du même produit que $config : les « matières » de
     * l'étape 2, la moins chère d'abord.
     *
     * @param list<ProduitConfiguration> $configs
     *
     * @return list<ProduitConfiguration>
     */
    public static function memeProduit(ProduitConfiguration $config, array $configs): array
    {
        $produit = $config->getProduit()?->getId();
        $liste = array_values(array_filter($configs, static fn (ProduitConfiguration $c): bool => $c->getProduit()?->getId() === $produit));

        return [] === $liste ? [$config] : $liste;
    }

    /**
     * Étape 2 : matières (configurations du produit) et leurs finitions.
     * Le supplément d'une matière se compte sur la moins chère, au même
     * mode de calcul ; un demandeur n'en reçoit aucun.
     *
     * @param list<ProduitConfiguration> $matieres
     *
     * @return list<array<string, mixed>>
     */
    public static function matieres(array $matieres, bool $b2b, bool $montants): array
    {
        $base = self::moinsChere($matieres, $b2b);
        $fiches = [];
        foreach ($matieres as $c) {
            $prix = $c->getPrixApplicable($b2b);
            $memeMode = $c->getModeCalcul() === $base->getModeCalcul();
            $ecart = $prix - $base->getPrixApplicable($b2b);
            $supplement = null;
            if ($montants && $c !== $base) {
                $supplement = $memeMode
                    ? ($ecart > 0 ? '+'.self::f($ecart).' F'.self::parUnite($c) : ($ecart < 0 ? '−'.self::f(-$ecart).' F'.self::parUnite($c) : 'même prix'))
                    : self::f($prix).' F'.self::parUnite($c);
            }
            $fiche = NouvelleCommande::fiche($c, $b2b);
            $finitions = [];
            foreach ($c->getConfigurationFinitions() as $cf) {
                if (!$cf->isActive() || null === $cf->getId()) {
                    continue;
                }
                $gratuite = !$cf->isPayante() || $cf->getPrix() <= 0;
                $finitions[] = [
                    'id' => (int) $cf->getId(),
                    // Nom commercial de la finition d'abord (Yaya, 18/10/2026).
                    'nom' => NomCommercial::texteClient($cf->getFinition()?->getNomCommercial(), $cf->getFinition()?->getNom()),
                    'obligatoire' => $cf->isObligatoire(),
                    'parDefaut' => $cf->isSelectionneeParDefaut() || $cf->isObligatoire(),
                    'detail' => $gratuite ? 'inclus' : ($montants ? '+'.self::f($cf->getPrix()).' F'.self::parUnite($cf->getModeCalcul(), true) : 'en option'),
                    'oeillets' => self::estOeillets((string) $cf->getFinition()?->getNom()),
                ];
            }
            $fiches[] = [
                'id' => (int) $c->getId(),
                'titre' => self::titreMatiere($c),
                'sousTitre' => self::sousTitreMatiere($c),
                'supplement' => $supplement,
                'taille' => $fiche['taille'],
                'mode' => $c->getModeCalcul(),
                'largeur' => $fiche['largeur'],
                'hauteur' => $fiche['hauteur'],
                'min' => $fiche['min'],
                'max' => $fiche['max'],
                'm2' => $fiche['m2'],
                'finitions' => $finitions,
            ];
        }

        return $fiches;
    }

    /**
     * Récapitulatif « Votre commande » d'une commande assemblée
     * (NouvelleCommande::assembler), non enregistrée. Sans $montants, les
     * lignes ne portent que des quantités et des délais.
     *
     * @param list<CommandesDetails> $autres produits déjà mis dans la même commande
     *
     * @return array<string, mixed>
     */
    public static function recapitulatif(Commandes $commande, CommandesDetails $ligne, ProduitConfiguration $config, bool $montants, int $heures, ?int $pourcentageAcompte = null, array $autres = []): array
    {
        $taille = null !== $ligne->getLargeur() && null !== $ligne->getLongueur();
        $quantite = $ligne->getQuantite();
        $mode = $config->getModeCalcul();
        $titre = self::titreMatiere($config);
        if ($taille) {
            $titre .= ' · '.self::m((float) $ligne->getLargeur()).' × '.self::m((float) $ligne->getLongueur()).' m';
        }
        $choisies = array_map(static fn ($f): string => mb_strtolower((string) $f->getNomFinition()), $ligne->getFinitions()->toArray());
        $lignes = [];
        $surface = null;
        if ('metre_carre' === $mode && $taille) {
            // En pièces au m² (quantité donnée en m²) : la surface facturée, espacement et chutes compris.
            $surface = $ligne->getSurfaceTotale();
            $lignes[] = ['libelle' => 'Surface', 'valeur' => self::nombre($surface).' m²'.($montants ? ' × '.self::f((int) $ligne->getPrixUnitaire()).' F' : '')];
            $pieces = $ligne->getPiecesAuM2();
            if (null !== $pieces) {
                // Un nombre de pièces, pas un montant : montré à tous ceux qui commandent (l'espacement est dit sous la quantité).
                $lignes[] = ['libelle' => 'Pièces au m²', 'valeur' => \sprintf('%s (%d × %d)', self::f($pieces['parM2']), $pieces['colonnes'], $pieces['rangees'])];
            }
        } elseif ('metre' === $mode && $taille) {
            $lignes[] = ['libelle' => 'Longueur', 'valeur' => self::nombre((float) $ligne->getLongueur() * $quantite).' m'.($montants ? ' × '.self::f((int) $ligne->getPrixUnitaire()).' F' : '')];
        } elseif ('forfait' === $mode) {
            $lignes[] = ['libelle' => 'Impression', 'valeur' => $montants ? self::f($ligne->calculerMontantImpression()).' F' : 'forfait'];
        } else {
            $lignes[] = ['libelle' => 'Quantité', 'valeur' => self::f($quantite).($montants ? ' × '.self::f((int) $ligne->getPrixUnitaire()).' F' : '')];
        }
        foreach ($ligne->getFinitions() as $f) {
            // Sans montants (demandeur), une finition payante choisie est seulement « choisie ».
            $lignes[] = ['libelle' => (string) $f->getNomFinition(), 'valeur' => (int) $f->getMontant() <= 0 ? 'inclus' : ($montants ? self::f((int) $f->getMontant()).' F' : 'choisie')];
        }
        // Palier, grille ou prix négocié (docs/produits.md) : la remise déjà comptée dans le total.
        $remise = $ligne->getMontantRemise() - $ligne->getRemiseCodePromo();
        if ($montants && $remise > 0) {
            [$pieces, $surfaceLigne] = \App\Service\Catalogue\PaliersPrix::mesures($ligne);
            $volume = \App\Service\Catalogue\PaliersPrix::volumeConfiguration($config, $pieces, $surfaceLigne);
            // Le libellé du palier seulement quand c'est bien lui qui fait la remise (pas une grille, un prix négocié, un revendeur).
            $palier = $ligne->getRemise() === \App\Service\Catalogue\PaliersPrix::remiseParUnite($ligne->getPrixUnitaire(), $volume['taux'], 0.0) ? \App\Service\Catalogue\PaliersPrix::libelle($volume) : null;
            $lignes[] = ['libelle' => $palier ?? 'Votre remise', 'valeur' => '−'.self::f($remise).' F'];
        }
        // Les autres produits de la même commande : leur somme, détaillée à part (« Déjà dans la commande »).
        if ([] !== $autres) {
            $somme = array_sum(array_map(static fn (CommandesDetails $l): int => $l->getTotalTtc(), $autres));
            $lignes[] = ['libelle' => \count($autres) > 1 ? \sprintf('%d autres produits', \count($autres)) : '1 autre produit', 'valeur' => $montants ? '+'.self::f($somme).' F' : 'déjà choisi'.(\count($autres) > 1 ? 's' : '')];
        }
        $express = null;
        foreach ($commande->getCommandesDetails() as $l) {
            if ($l !== $ligne && !\in_array($l, $autres, true)) {
                $express = $l;
            }
        }
        $lignes[] = ['libelle' => 'Délai', 'valeur' => ($commande->estExpress() ? 'express ' : 'standard ').$heures.' h'.(null !== $express && $montants ? ' · +'.self::f($express->getTotalTtc()).' F' : '')];
        // Réception : montrée à l'étape 4 (« reception »).
        if ($commande->estALivrer()) {
            $lignes[] = ['libelle' => 'Livraison à Bamako', 'valeur' => $montants ? ($commande->getFraisLivraison() > 0 ? self::f($commande->getFraisLivraison()).' F' : 'offerte') : 'oui', 'reception' => true];
        } elseif (null !== $commande->getDelaiProduction()) {
            $lignes[] = ['libelle' => 'Retrait à l\'imprimerie', 'valeur' => 'gratuit', 'reception' => true];
        }

        $recap = [
            'titre' => $titre,
            'sousTitre' => '× '.self::f($quantite).([] !== $choisies ? ' · '.implode(', ', $choisies) : ''),
            'lignes' => $lignes,
            'quantite' => self::quantiteCourte($ligne, $mode, $surface),
            'montants' => $montants,
        ];
        if ($montants) {
            $recap['total'] = $commande->getTotalTtc();
            $recap['totalTexte'] = self::f($commande->getTotalTtc()).' F';
            if (null !== $pourcentageAcompte && $pourcentageAcompte > 0 && $pourcentageAcompte < 100) {
                $acompte = DelaisCommande::acompte($commande->getTotalTtc(), $pourcentageAcompte);
                $recap['acompte'] = ['pourcentage' => $pourcentageAcompte, 'maintenant' => $acompte, 'ensuite' => $commande->getTotalTtc() - $acompte];
            }
        }

        return $recap;
    }

    /** « dès 3 500 F / m² », « 500 ex. dès 25 000 F », « dès 15 000 F » */
    public static function prixDepart(ProduitConfiguration $c, bool $b2b): string
    {
        $prix = $c->getPrixApplicable($b2b);
        $min = max(1, $c->getQuantiteMinimale());
        if ($min > 1 && \in_array($c->getModeCalcul(), ['unite', 'exemplaire', 'feuille'], true)) {
            return \sprintf('%s ex. dès %s F', self::f($min), self::f($prix * $min));
        }

        return 'dès '.self::f($prix).' F'.self::parUnite($c);
    }

    /** Ce que la carte montre sans prix (demandeur) : l'unité de vente. */
    public static function infoSansPrix(ProduitConfiguration $c): string
    {
        $min = max(1, $c->getQuantiteMinimale());

        return match (true) {
            'metre_carre' === $c->getModeCalcul() => 'sur mesure, au m²',
            'metre' === $c->getModeCalcul() => 'au mètre',
            $min > 1 => \sprintf('à partir de %s ex.', self::f($min)),
            'forfait' === $c->getModeCalcul() => 'au forfait',
            default => 'à la pièce',
        };
    }

    /** Œillets : le nom de la finition les annonce (aperçu à l'échelle, contrôle du bord). */
    public static function estOeillets(string $nom): bool
    {
        return 1 === preg_match('/[œo]e?illet/iu', $nom);
    }

    /** Espacement des œillets lu dans « Œillets tous les 50 cm », 50 cm sinon. */
    public static function espacementOeillets(string $nom): int
    {
        return preg_match('/(\d{2,3})\s*cm/u', $nom, $m) ? max(10, (int) $m[1]) : 50;
    }

    /** Nombre d'œillets d'une pièce, posés sur le pourtour tous les $espacement cm. */
    public static function nombreOeillets(float $largeurCm, float $hauteurCm, int $espacement = 50): int
    {
        if ($largeurCm <= 0 || $hauteurCm <= 0) {
            return 0;
        }

        return 2 * (int) ceil($largeurCm / $espacement) + 2 * (int) ceil($hauteurCm / $espacement);
    }

    private static function titreMatiere(ProduitConfiguration $c): string
    {
        $support = trim((string) $c->getSupport()?->getNom());
        $format = null !== $c->getFormat() ? NomCommercial::texteClient($c->getFormat()->getNomCommercial(), $c->getFormat()->getNom()) : null;
        $nom = CatalogueSite::nomAffiche(NomCommercial::clientDe($c->getProduit())['nom']);
        if ('' !== $support) {
            return CatalogueSite::nomAffiche(NomCommercial::texteClient($c->getSupport()?->getNomCommercial(), $support));
        }

        return null !== $format && !\in_array(mb_strtolower((string) $c->getFormat()?->getNom()), ['sur mesure', 'taille unique'], true) ? $nom.' · '.$format : $nom;
    }

    private static function sousTitreMatiere(ProduitConfiguration $c): ?string
    {
        $description = trim((string) $c->getDescription());
        if ('' !== $description) {
            return mb_strimwidth($description, 0, 70, '…');
        }
        $format = null !== $c->getFormat() ? NomCommercial::texteClient($c->getFormat()->getNomCommercial(), $c->getFormat()->getNom()) : null;

        return null !== $format && '' !== trim($format) ? $format : null;
    }

    /** « / m² », « / pièce », rien pour un forfait. */
    private static function parUnite(ProduitConfiguration|string $mode, bool $finition = false): string
    {
        $mode = $mode instanceof ProduitConfiguration ? $mode->getModeCalcul() : $mode;
        $unite = self::UNITES[$mode] ?? null;

        return null === $unite ? '' : ($finition && 'pièce' !== $unite ? '/'.$unite : ' / '.$unite);
    }

    /** @param list<ProduitConfiguration> $configs */
    private static function moinsChere(array $configs, bool $b2b): ProduitConfiguration
    {
        $choisie = $configs[0];
        foreach ($configs as $c) {
            if ($c->getPrixApplicable($b2b) < $choisie->getPrixApplicable($b2b)) {
                $choisie = $c;
            }
        }

        return $choisie;
    }

    private static function quantiteCourte(CommandesDetails $ligne, string $mode, ?float $surface): string
    {
        return match (true) {
            null !== $surface => self::nombre($surface).' m²',
            'metre' === $mode && null !== $ligne->getLongueur() => self::nombre((float) $ligne->getLongueur() * $ligne->getQuantite()).' m',
            default => self::f($ligne->getQuantite()).' ex.',
        };
    }

    public static function f(int|float $n): string
    {
        return number_format((float) $n, 0, ',', ' ');
    }

    /** « 6 », « 2,5 », « 0,25 » */
    private static function nombre(float $n): string
    {
        return str_replace('.', ',', (string) round($n, 2));
    }

    /** Côté en mètres, au mm près : « 3 », « 1,5 », « 0,055 » (étiquette de 5,5 cm) */
    private static function m(float $metres): string
    {
        return str_replace('.', ',', (string) round($metres, 3));
    }
}
