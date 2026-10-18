<?php

namespace App\Service\Site;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Photos des produits (envoyées par Yaya le 18/10/2026, docs/site-vitrine.md,
 * « Photos des produits ») : une par sorte de produit, choisie d'après le
 * nom du produit, sur la page « Nos produits » et dans « Commander » de
 * l'espace client quand le produit n'a pas encore de BAT à montrer.
 *
 * Ce sont des visuels de présentation (maquettes aux logos Succès Imprim
 * et Madial Group, sur fond blanc), jamais présentés comme le travail d'un
 * client. Un produit sans mot reconnu, ou dont le fichier manque, garde
 * son dessin (CatalogueSite::ILLUSTRATIONS) ou ses initiales.
 *
 * Fichiers : public/images/site/produits/<nom>-<largeur>.webp, carrés,
 * réencodés sans métadonnée, en 240, 360 et 480 px.
 */
final class PhotosProduits
{
    public const DOSSIER = 'images/site/produits';

    /** Largeurs livrées ; les photos sont carrées. */
    public const LARGEURS = [240, 360, 480];

    /**
     * Photo => mots du nom du produit (en minuscules, cherchés en début de
     * mot) et texte alternatif. Dans l'ordre où elles sont essayées : la
     * première dont un mot est dans le nom l'emporte (« Kakémono » avant
     * « roll-up », « Polo » avant « t-shirt »).
     */
    public const PHOTOS = [
        'vitrine-micro-perforee' => ['mots' => ['micro-perf', 'microperf', 'micro perf', 'vitrophanie'], 'alt' => 'Vitrine de boutique habillée d\'un vinyle micro-perforé au logo Succès Imprim'],
        'kakemono' => ['mots' => ['kakémono', 'kakemono'], 'alt' => 'Kakémono suspendu, vert et gris, au logo Madial Group'],
        'roll-up' => ['mots' => ['roll-up', 'rollup', 'roll up'], 'alt' => 'Roll-up déroulé sur son pied, au logo Succès Imprim sur des vagues de couleurs'],
        'panneau-akylux' => ['mots' => ['akylux', 'panneau', 'forex', 'dibond'], 'alt' => 'Panneau Akylux imprimé au logo Madial Group'],
        'casquette' => ['mots' => ['casquette'], 'alt' => 'Casquette blanche à visière verte, brodée du logo MDG'],
        'polo' => ['mots' => ['polo'], 'alt' => 'Polo blanc à col vert, logo MDG sur la poitrine'],
        't-shirt' => ['mots' => ['t-shirt', 'tee-shirt', 'tshirt', 'dtf'], 'alt' => 'T-shirt blanc imprimé du logo Succès Imprim « Nous imprimons vos succès !! »'],
        'brochure' => ['mots' => ['brochure', 'catalogue', 'livret', 'magazine', 'dépliant', 'depliant'], 'alt' => 'Brochures ouvertes et fermées aux couleurs de Madial Group'],
        'affiches' => ['mots' => ['affiche', 'poster'], 'alt' => 'Deux affiches au logo Succès Imprim sur des vagues bleues, rouges et jaunes'],
        'cartes-de-visite' => ['mots' => ['carte de visite', 'cartes de visite'], 'alt' => 'Cartes de visite au logo Madial Group, recto, verso et en pile'],
        'flyers' => ['mots' => ['flyer', 'prospectus', 'tract'], 'alt' => 'Pile de flyers Succès Imprim « Donnez vie à vos idées »'],
        'vinyle-adhesif' => ['mots' => ['vinyle', 'adhésif', 'adhesif', 'autocollant', 'sticker'], 'alt' => 'Rouleau de vinyle adhésif imprimé au logo Succès Imprim'],
    ];

    /** @var array<string, bool> fichiers déjà cherchés sur le disque */
    private array $presents = [];

    public function __construct(
        #[Autowire('%kernel.project_dir%/public')] private readonly string $dossierPublic,
    ) {
    }

    /** Nom de la photo d'un produit d'après son nom (« Flyers A5 (démo) » → flyers), sinon null. */
    public static function nomPour(?string $produit): ?string
    {
        $texte = mb_strtolower((string) $produit);
        foreach (self::PHOTOS as $photo => $definition) {
            foreach ($definition['mots'] as $mot) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($mot, '/').'/u', $texte)) {
                    return $photo;
                }
            }
        }

        return null;
    }

    /**
     * Photo d'un produit, prête pour site/_photo.html.twig (même forme que
     * PhotosSite::photo), ou null : pas de photo pour ce nom, ou fichiers
     * absents.
     *
     * @return array{nom: string, src: string, fichiers: list<array{chemin: string, largeur: int}>, largeur: int, hauteur: int, alt: string, cadrage: null}|null
     */
    public function photo(?string $produit): ?array
    {
        $nom = self::nomPour($produit);
        if (null === $nom) {
            return null;
        }
        $fichiers = [];
        foreach (self::LARGEURS as $largeur) {
            $chemin = self::chemin($nom, $largeur);
            if ($this->presents[$chemin] ??= is_file($this->dossierPublic.'/'.$chemin)) {
                $fichiers[] = ['chemin' => $chemin, 'largeur' => $largeur];
            }
        }
        if ([] === $fichiers) {
            return null;
        }
        $plusGrande = $fichiers[\count($fichiers) - 1]['largeur'];

        return [
            'nom' => $nom,
            'src' => $fichiers[0]['chemin'],
            'fichiers' => $fichiers,
            'largeur' => $plusGrande,
            'hauteur' => $plusGrande,
            'alt' => self::PHOTOS[$nom]['alt'],
            'cadrage' => null,
        ];
    }

    public static function chemin(string $nom, int $largeur): string
    {
        return self::DOSSIER.'/'.$nom.'-'.$largeur.'.webp';
    }
}
