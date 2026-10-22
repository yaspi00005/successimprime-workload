<?php

namespace App\Service\Catalogue;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Entity\ProduitConfiguration;
use App\Service\Parametres\Parametres;
use App\Service\Promotions\PromotionsSaisie;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Prix d'une ligne pour un client (docs/produits.md, « Paliers de prix et
 * grilles ») : le point central où se rejoignent palier de volume, grille
 * du client et prix négocié. Appelé par la commande en ligne (estimation
 * et enregistrement, NouvelleCommande) et par la saisie du comptoir
 * (commande et devis, à l'enregistrement).
 *
 * Ordre : palier de volume (PaliersPrix), puis remise de la grille
 * Partenaire ou Association, puis prix négocié s'il est plus bas. Le
 * revendeur (B2B) garde son prix revendeur : ni palier ni grille, son
 * prix négocié seulement. Le tout devient la remise par unité de la
 * ligne, jamais en dessous de celle déjà posée (geste du comptoir) ;
 * une promotion se compare ensuite à cette remise (pas de cumul).
 */
final class TarifClient
{
    /** Grilles de prix, par valeur de Clients::getGrille(). */
    public const GRILLES = [
        'B2C' => 'Standard',
        'PARTENAIRE' => 'Partenaire',
        'ASSOCIATION' => 'Association',
        'B2B' => 'Revendeur',
    ];

    public const PARTENAIRE_DEFAUT = 5;
    public const ASSOCIATION_DEFAUT = 10;

    /** Remise d'une grille au plus, en %. */
    public const GRILLE_MAX = 50;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
        private readonly PrixSpeciaux $prixSpeciaux,
    ) {
    }

    /**
     * Remise de chaque grille, en % (Paramètres › Grille tarifaire).
     *
     * @return array{B2C: float, PARTENAIRE: float, ASSOCIATION: float, B2B: float}
     */
    public function remises(): array
    {
        $v = fn (string $cle, int $defaut): float => (float) min(self::GRILLE_MAX, max(0, (int) ($this->parametres->valeur('grille', $cle) ?? $defaut)));

        return [
            'B2C' => 0.0,
            'PARTENAIRE' => $v('remise_partenaire_pct', self::PARTENAIRE_DEFAUT),
            'ASSOCIATION' => $v('remise_association_pct', self::ASSOCIATION_DEFAUT),
            'B2B' => 0.0,
        ];
    }

    /** Remise de la grille du client, en % (0 pour Standard et Revendeur). */
    public function remiseGrille(?Clients $client): float
    {
        return null === $client ? 0.0 : ($this->remises()[$client->getGrille()] ?? 0.0);
    }

    /** La plus forte remise de grille (Partenaire ou Association), pour les garde-fous des paliers. */
    public function remiseGrilleMax(): float
    {
        return max($this->remises());
    }

    /**
     * Configuration d'une ligne : celle de la ligne, sinon celle du
     * catalogue qui a ses produit, type d'impression, support et format
     * (saisie du comptoir, qui choisit ces quatre-là).
     */
    public function configurationDe(CommandesDetails|DevisDetails $ligne): ?ProduitConfiguration
    {
        $c = $ligne->getProduitConfiguration();
        if (null !== $c) {
            return $c;
        }
        if (null === $ligne->getProduit() || null === $ligne->getTypeImpression() || null === $ligne->getSupport()) {
            return null;
        }
        $c = $this->em->getRepository(ProduitConfiguration::class)->findOneBy([
            'produit' => $ligne->getProduit(),
            'typeImpression' => $ligne->getTypeImpression(),
            'support' => $ligne->getSupport(),
            'format' => $ligne->getFormat(),
        ]);

        return null !== $c && $c->isActive() ? $c : null;
    }

    /**
     * Remise par unité due au client sur cette ligne (palier, grille, prix
     * négocié), sans rien changer.
     *
     * @return array{remise: int, volume: array{taux: float, source: ?string, seuil: int|float|null, prochain: ?array}, grille: float, negocie: ?int}
     */
    public static function calculer(float $prixLigne, ?ProduitConfiguration $c, int $pieces, float $surfaceTotale, bool $b2b, float $tauxGrille, ?int $prixNegocie): array
    {
        $volume = ['taux' => 0.0, 'source' => null, 'seuil' => null, 'prochain' => null];
        $grille = 0.0;
        if (!$b2b) {
            if (null !== $c) {
                $volume = PaliersPrix::volumeConfiguration($c, $pieces, $surfaceTotale);
            }
            $grille = $tauxGrille;
        }
        $remise = PaliersPrix::remiseParUnite($prixLigne, $volume['taux'], $grille);
        if (null !== $prixNegocie) {
            // Le prix négocié ne s'ajoute pas : on garde le plus bas des deux prix.
            $remise = max($remise, PrixSpeciaux::remiseParUnite((int) round($prixLigne), $prixNegocie));
        }

        return ['remise' => $remise, 'volume' => $volume, 'grille' => $grille, 'negocie' => $prixNegocie];
    }

    /**
     * Pose sur la ligne la remise due au client, si elle dépasse celle déjà
     * posée, et refait ses totaux.
     *
     * @return array{remise: int, volume: array, grille: float, negocie: ?int}
     */
    public function appliquerALigne(CommandesDetails|DevisDetails $ligne, ?Clients $client, \DateTimeInterface $jour): array
    {
        $c = $this->configurationDe($ligne);
        $b2b = null !== $client && $client->isB2B();
        $prixNegocie = $this->prixSpeciaux->pour($client, $c, $jour)?->getPrix();
        [$pieces, $surface] = PaliersPrix::mesures($ligne);
        $calcul = self::calculer($ligne->getPrixUnitaire(), $c, $pieces, $surface, $b2b, $this->remiseGrille($client), $prixNegocie);
        if ($calcul['remise'] > $ligne->getRemise()) {
            $ligne->setRemise($calcul['remise']);
            $ligne->calculerTotaux($b2b);
        }

        return $calcul;
    }

    /**
     * Commande saisie au comptoir, à l'enregistrement : chaque ligne
     * produit reçoit au moins la remise due au client. Une commande
     * facturée ne bouge plus ; une ligne d'avant la nouvelle version qui
     * garde son total d'origine non plus.
     */
    public function appliquerCommande(Commandes $commande, \DateTimeImmutable $jour): void
    {
        if (PromotionsSaisie::estFacturee($commande)) {
            return;
        }
        foreach ($commande->getCommandesDetails() as $ligne) {
            if (self::aTarifer($ligne)) {
                $this->appliquerALigne($ligne, $commande->getClients(), $commande->getDateCommande() ?? $jour);
            }
        }
    }

    /** Devis saisi au comptoir (pas un devis déjà passé en commande). */
    public function appliquerDevis(Devis $devis, \DateTimeImmutable $jour): void
    {
        if (null !== $devis->getCommande()) {
            return;
        }
        foreach ($devis->getDevisDetails() as $ligne) {
            if (self::aTarifer($ligne)) {
                $this->appliquerALigne($ligne, $devis->getClients(), $jour);
            }
        }
    }

    /**
     * Paliers et prix négocié des configurations d'un produit, pour la
     * saisie du comptoir (le navigateur refait le calcul, le serveur le
     * refait à l'enregistrement).
     *
     * @return list<array<string, mixed>>
     */
    public function pourLeComptoir(iterable $configurations, ?Clients $client, \DateTimeInterface $jour): array
    {
        $liste = [];
        foreach ($configurations as $c) {
            if (!$c instanceof ProduitConfiguration || !$c->isActive()) {
                continue;
            }
            $negocie = $this->prixSpeciaux->pour($client, $c, $jour)?->getPrix();
            if (!$c->aDesPaliers() && null === $negocie) {
                continue;
            }
            $liste[] = [
                'type' => $c->getTypeImpression()?->getId(),
                'support' => $c->getSupport()?->getId(),
                'format' => $c->getFormat()?->getId(),
                'prixSpecial' => $negocie,
            ] + PaliersPrix::pourLaPage($c);
        }

        return $liste;
    }

    private static function aTarifer(CommandesDetails|DevisDetails $ligne): bool
    {
        if (CommandesDetails::TYPE_PRODUIT !== $ligne->getTypeLigne() || null === $ligne->getProduit() || $ligne->isTotalFige()) {
            return false;
        }

        return !$ligne instanceof CommandesDetails || CommandesDetails::PRODUCTION_ANNULEE !== $ligne->getStatutProduction();
    }
}
