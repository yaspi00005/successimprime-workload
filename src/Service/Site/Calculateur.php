<?php

namespace App\Service\Site;

use App\Entity\ProduitConfiguration;
use App\Service\EspaceClient\NouvelleCommande;
use App\Twig\SiteExtension;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Calculateur de prix du site vitrine (maquette 03). La saisie du visiteur
 * est lue et bornée ici, puis chiffrée comme la commande en ligne de
 * l'espace client (NouvelleCommande::estimer), toujours au prix normal :
 * jamais le prix B2B, le coût ni la marge. N'enregistre rien et n'ouvre
 * pas de session.
 */
final class Calculateur
{
    /** Au-delà, le visiteur demande un devis. */
    public const QUANTITE_MAX = 100000;

    /** Finitions lues au plus dans une requête. */
    public const FINITIONS_MAX = 30;

    /** Taille proposée quand le produit n'en impose pas : 1 m × 1 m. */
    public const COTE_PROPOSEE_CM = 100;

    /** Aucun délai de fabrication n'est saisi par produit : l'atelier l'annonce avec le BAT. */
    public const DELAI = 'Annoncé à la validation du BAT';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly SiteExtension $site,
    ) {
    }

    /**
     * Saisie du visiteur (paramètres de l'adresse), nettoyée : un nombre
     * illisible ou hors bornes devient null, les finitions une liste
     * d'identifiants. Une quantité envoyée mais illisible vaut 0 (refusée
     * au calcul) ; absente, c'est l'arrivée sur la page.
     *
     * @param array<string, mixed> $parametres
     *
     * @return array{produit: ?int, categorie: ?int, precedent: ?int, largeur: ?float, hauteur: ?float, quantite: ?int, finitions: list<int>}
     */
    public static function lire(array $parametres): array
    {
        return [
            'produit' => self::entier($parametres['produit'] ?? null),
            'categorie' => self::entier($parametres['categorie'] ?? null),
            'precedent' => self::entier($parametres['precedent'] ?? null),
            'largeur' => self::cote($parametres['largeur'] ?? null),
            'hauteur' => self::cote($parametres['hauteur'] ?? null),
            'quantite' => \array_key_exists('quantite', $parametres) ? (self::entier($parametres['quantite']) ?? 0) : null,
            'finitions' => self::identifiants($parametres['finitions'] ?? []),
        ];
    }

    /** Entier positif ou nul de 9 chiffres au plus (espaces tolérés), sinon null. */
    public static function entier(mixed $valeur): ?int
    {
        if (\is_int($valeur)) {
            return $valeur >= 0 && $valeur <= 999_999_999 ? $valeur : null;
        }
        if (!\is_string($valeur)) {
            return null;
        }
        $texte = str_replace([' ', "\u{A0}", "\u{202F}"], '', trim($valeur));

        return 1 === preg_match('/^\d{1,9}$/', $texte) ? (int) $texte : null;
    }

    /** Côté en cm : nombre positif à deux décimales au plus (virgule ou point), sinon null. */
    public static function cote(mixed $valeur): ?float
    {
        if (!\is_string($valeur) && !\is_int($valeur)) {
            return null;
        }
        $texte = str_replace([' ', "\u{A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $valeur));
        if (1 !== preg_match('/^\d{1,6}(\.\d{1,2})?$/', $texte)) {
            return null;
        }

        return (float) $texte > 0 ? (float) $texte : null;
    }

    /** @return list<int> */
    public static function identifiants(mixed $valeurs): array
    {
        $ids = [];
        foreach (\is_array($valeurs) ? \array_slice($valeurs, 0, self::FINITIONS_MAX) : [] as $valeur) {
            $id = self::entier($valeur);
            if (null !== $id && $id > 0 && !\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Configuration à chiffrer : celle demandée (?produit=), sinon la
     * première de la catégorie (?categorie=), sinon la première du catalogue.
     *
     * @param list<ProduitConfiguration>                                     $configurations
     * @param list<array{ids: list<int>, options: list<array{id: int}>}>     $groupes        CatalogueSite::groupes()
     * @param array{produit: ?int, categorie: ?int}                          $saisie
     */
    public static function configuration(array $configurations, array $groupes, array $saisie): ?ProduitConfiguration
    {
        $parId = [];
        foreach ($configurations as $c) {
            $parId[$c->getId()] = $c;
        }
        if (null !== $saisie['produit'] && isset($parId[$saisie['produit']])) {
            return $parId[$saisie['produit']];
        }
        foreach (null === $saisie['categorie'] ? [] : $groupes as $groupe) {
            if (\in_array($saisie['categorie'], $groupe['ids'], true) && [] !== $groupe['options']) {
                return $parId[$groupe['options'][0]['id']] ?? null;
            }
        }

        return $configurations[0] ?? null;
    }

    /**
     * Valeurs du formulaire pour cette configuration. À l'arrivée sur la
     * page, et quand le visiteur vient de changer de produit sans
     * JavaScript (?precedent= différent), on propose la taille du produit
     * (sinon 1 m × 1 m), son minimum et ses finitions par défaut ;
     * ensuite on garde ce qu'il a saisi.
     *
     * @param array{precedent: ?int, largeur: ?float, hauteur: ?float, quantite: ?int, finitions: list<int>} $saisie
     *
     * @return array{produit: int, largeur: ?float, hauteur: ?float, quantite: int, finitions: list<int>}
     */
    public static function valeurs(ProduitConfiguration $c, array $saisie): array
    {
        $fiche = NouvelleCommande::fiche($c, false);
        $change = null !== $saisie['precedent'] && $saisie['precedent'] !== $c->getId();
        $arrivee = null === $saisie['quantite'] || $change;

        [$largeur, $hauteur] = [null, null];
        if ($fiche['taille']) {
            $imposee = null !== $fiche['largeur'] && null !== $fiche['hauteur'];
            if ($change && $imposee) {
                [$largeur, $hauteur] = [(float) $fiche['largeur'], (float) $fiche['hauteur']];
            } else {
                // Côté effacé : la taille du produit s'il en a une (comme NouvelleCommande), sinon le calcul la demande.
                $largeur = $saisie['largeur'] ?? (null !== $fiche['largeur'] ? (float) $fiche['largeur'] : ($arrivee ? (float) self::COTE_PROPOSEE_CM : null));
                $hauteur = $saisie['hauteur'] ?? (null !== $fiche['hauteur'] ? (float) $fiche['hauteur'] : ($arrivee ? (float) self::COTE_PROPOSEE_CM : null));
            }
        }

        $possibles = array_column($fiche['finitions'], 'id');
        $finitions = array_values(array_intersect($saisie['finitions'], $possibles));
        if ($arrivee && [] === $finitions) {
            $finitions = array_column(array_filter($fiche['finitions'], static fn (array $f): bool => $f['parDefaut']), 'id');
        }

        return [
            'produit' => (int) $c->getId(),
            'largeur' => $largeur,
            'hauteur' => $hauteur,
            'quantite' => $arrivee ? max($fiche['min'], $saisie['quantite'] ?? 1) : (int) $saisie['quantite'],
            'finitions' => array_map('intval', $finitions),
        ];
    }

    /**
     * Ce que la page et le JSON montrent : total, détail, liens pour
     * commander ou demander un devis. Prix normal seulement.
     *
     * @param array{produit: int, largeur: ?float, hauteur: ?float, quantite: int, finitions: list<int>} $valeurs
     *
     * @return array{produit: int, nom: string, total: ?int, totalTexte: ?string, lignes: list<array{libelle: string, valeur: string}>, detail: list<array{libelle: string, montant: int, texte: string}>, erreur: ?string, liens: array{commander: ?string, devis: ?string, whatsapp: ?string}}
     */
    public function resultat(ProduitConfiguration $c, array $valeurs): array
    {
        $resultat = ['produit' => (int) $c->getId(), 'nom' => CatalogueSite::libelle($c), 'total' => null, 'totalTexte' => null, 'lignes' => [], 'detail' => [], 'erreur' => null];
        try {
            if ($valeurs['quantite'] < 1) {
                throw new \DomainException('Indiquez une quantité.');
            }
            if ($valeurs['quantite'] > self::QUANTITE_MAX) {
                throw new \DomainException(\sprintf('Au-delà de %s exemplaires, demandez un devis.', self::nombre(self::QUANTITE_MAX)));
            }
            $estimation = NouvelleCommande::estimer($c, $valeurs['quantite'], $valeurs['largeur'], $valeurs['hauteur'], $valeurs['finitions'], false);
            $resultat['total'] = $estimation['total'];
            $resultat['totalTexte'] = self::fcfa($estimation['total']);
            foreach ($estimation['detail'] as $ligne) {
                $resultat['detail'][] = ['libelle' => $ligne['libelle'], 'montant' => $ligne['montant'], 'texte' => $ligne['montant'] > 0 ? self::fcfa($ligne['montant']) : 'Inclus'];
            }
            $resultat['lignes'] = self::lignes($c, $valeurs, \array_slice(array_column($estimation['detail'], 'libelle'), 1));
        } catch (\DomainException $e) {
            $resultat['erreur'] = $e->getMessage();
        }

        $parametres = array_filter([
            'produit' => $c->getId(),
            'largeur' => null !== $valeurs['largeur'] ? self::cm($valeurs['largeur']) : null,
            'hauteur' => null !== $valeurs['hauteur'] ? self::cm($valeurs['hauteur']) : null,
            'quantite' => $valeurs['quantite'],
            'finitions' => $valeurs['finitions'] ?: null,
        ], static fn (mixed $v): bool => null !== $v);
        $resultat['liens'] = [
            // L'espace client reprend ces paramètres (connexion d'abord si besoin) ; pas de commande sur une saisie refusée.
            'commander' => null === $resultat['erreur'] ? $this->urls->generate('app_espace_nouvelle_commande', $parametres) : null,
            'devis' => $this->site->lien('app_site_devis', $parametres),
            'whatsapp' => $this->site->whatsapp(self::message($resultat, $valeurs)),
        ];

        return $resultat;
    }

    /**
     * Lignes de l'estimation : surface (ou longueur, ou quantité), prix
     * unitaire, machine publiée, finitions, délai.
     *
     * @param list<string> $finitions noms des finitions comptées
     *
     * @return list<array{libelle: string, valeur: string}>
     */
    private static function lignes(ProduitConfiguration $c, array $valeurs, array $finitions): array
    {
        $q = $valeurs['quantite'];
        if (null !== $valeurs['largeur'] && null !== $valeurs['hauteur']) {
            [$libelle, $mesure, $unite] = 'metre' === $c->getModeCalcul()
                ? ['Longueur', $valeurs['hauteur'] / 100, 'm']
                : ['Surface', $valeurs['largeur'] / 100 * ($valeurs['hauteur'] / 100), 'm²'];
            $texte = self::decimal($mesure)."\u{A0}".$unite;
            $lignes = [['libelle' => $libelle, 'valeur' => $q > 1 ? \sprintf('%s × %s = %s', $texte, self::nombre($q), self::decimal($mesure * $q)."\u{A0}".$unite) : $texte]];
        } else {
            $lignes = [['libelle' => 'Quantité', 'valeur' => self::nombre($q)]];
        }
        $lignes[] = ['libelle' => 'Prix unitaire', 'valeur' => self::fcfa($c->getPrixApplicable(false)).' '.CatalogueSite::unite($c->getModeCalcul())];
        $machine = $c->getTypeImpression();
        if (null !== $machine && $machine->isPublie() && '' !== trim((string) $machine->getNom())) {
            $lignes[] = ['libelle' => 'Machine', 'valeur' => CatalogueSite::nomAffiche((string) $machine->getNom())];
        }
        $lignes[] = ['libelle' => 'Finitions', 'valeur' => [] === $finitions ? 'Aucune' : implode(', ', $finitions)];
        $lignes[] = ['libelle' => 'Délai', 'valeur' => self::DELAI];

        return $lignes;
    }

    /** Premier message WhatsApp : ce que le visiteur a choisi, et l'estimation s'il y en a une. */
    private static function message(array $resultat, array $valeurs): string
    {
        $choix = [$resultat['nom']];
        if (null !== $valeurs['largeur'] && null !== $valeurs['hauteur']) {
            $choix[] = str_replace('.', ',', self::cm($valeurs['largeur'])).' × '.str_replace('.', ',', self::cm($valeurs['hauteur'])).' cm';
        }
        $choix[] = 'quantité '.$valeurs['quantite'];
        $finitions = array_column(\array_slice($resultat['detail'], 1), 'libelle');
        if ([] !== $finitions) {
            $choix[] = 'finitions : '.implode(', ', $finitions);
        }
        $texte = 'Bonjour Succès Imprim, je souhaite un devis : '.implode(', ', $choix).'.';

        return null === $resultat['totalTexte'] ? $texte : $texte.' Estimation du site : '.$resultat['totalTexte'].'.';
    }

    /** Montant comme dans le reste de l'application (54 000 FCFA), sans retour à la ligne. */
    public static function fcfa(int $montant): string
    {
        return self::nombre($montant)."\u{A0}FCFA";
    }

    private static function nombre(int $n): string
    {
        return number_format($n, 0, ',', "\u{A0}");
    }

    private static function decimal(float $n): string
    {
        return number_format($n, 2, ',', "\u{A0}");
    }

    /** Côté pour une adresse : 300, 120.5. */
    public static function cm(float $cote): string
    {
        $texte = number_format($cote, 2, '.', '');

        return rtrim(rtrim($texte, '0'), '.');
    }
}
