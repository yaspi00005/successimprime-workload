<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\CommandeDetailFichier;
use App\Entity\CommandeDetailFinition;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Service\GrilleTarifaire;
use App\Service\Promotions\PromotionsSaisie;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Commande passée par le client dans son espace (maquettes CO01 à CO04) :
 * produit du catalogue publié, taille, matière, finitions, quantité, délai
 * (standard ou express) et réception (retrait ou livraison). Le prix vient
 * des mêmes prix que la saisie au comptoir, calculé ici ; la commande
 * arrive en brouillon « à confirmer » : l'atelier vérifie le prix et le
 * fichier, puis la confirme.
 */
final class NouvelleCommande
{
    /** Commandes en attente de confirmation au plus, par client. */
    public const EN_ATTENTE_MAX = 10;

    /** Taille saisie par le client, en cm. */
    public const COTE_MAX_CM = 5000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        /** Code promo tapé par le client (docs/promotions.md) ; absent dans les tests qui n'en ont pas besoin. */
        private readonly ?PromotionsSaisie $promotionsSaisie = null,
    ) {
    }

    /**
     * Produits que le client peut commander seul : configurations actives,
     * avec un prix, d'un produit publié (et d'une catégorie publiée).
     *
     * @return list<ProduitConfiguration>
     */
    public function produits(): array
    {
        $configs = $this->em->createQueryBuilder()
            ->select('pc', 'p', 's', 'f', 'cf', 'fi')
            ->from(ProduitConfiguration::class, 'pc')
            ->innerJoin('pc.produit', 'p')
            ->leftJoin('p.categorieProduit', 'cat')
            ->leftJoin('pc.support', 's')
            ->leftJoin('pc.format', 'f')
            ->leftJoin('pc.configurationFinitions', 'cf')
            ->leftJoin('cf.finition', 'fi')
            ->where('pc.active = true')->andWhere('p.actif = true')->andWhere('p.publie = true')
            ->andWhere('cat.id IS NULL OR cat.publie = true')
            ->andWhere('pc.prixBase > 0')
            ->orderBy('cat.ordre', 'ASC')->addOrderBy('p.ordre', 'ASC')->addOrderBy('p.nom', 'ASC')->addOrderBy('pc.ordre', 'ASC')
            ->getQuery()->getResult();

        return array_values($configs);
    }

    public function produit(int $id): ?ProduitConfiguration
    {
        foreach ($this->produits() as $c) {
            if ($c->getId() === $id) {
                return $c;
            }
        }

        return null;
    }

    /**
     * Fiches de tous les produits : le nom court du produit sur la puce,
     * complété du support quand deux produits portent le même nom.
     *
     * @param list<ProduitConfiguration> $configs
     *
     * @return list<array<string, mixed>>
     */
    public static function fiches(array $configs, bool $b2b): array
    {
        $fiches = array_map(static fn (ProduitConfiguration $c): array => self::fiche($c, $b2b), $configs);
        $noms = array_count_values(array_column($fiches, 'court'));
        foreach ($fiches as $i => $f) {
            if ($noms[$f['court']] > 1) {
                $fiches[$i]['court'] = $f['nom'];
            }
        }

        return $fiches;
    }

    /** La taille est demandée au client (bâche, vinyle...) ou imposée par le format. */
    public static function demandeTaille(ProduitConfiguration $c): bool
    {
        return $c->utiliseDimensions() || \in_array($c->getModeCalcul(), ['metre', 'metre_carre'], true);
    }

    /**
     * Ce que la vue montre d'un produit (prix compris : c'est le tarif public).
     *
     * @return array<string, mixed>
     */
    public static function fiche(ProduitConfiguration $c, bool $b2b): array
    {
        $finitions = [];
        foreach ($c->getConfigurationFinitions() as $cf) {
            if ($cf->isActive() && null !== $cf->getId()) {
                $finitions[] = ['id' => $cf->getId(), 'nom' => (string) $cf->getFinition()?->getNom(), 'obligatoire' => $cf->isObligatoire(), 'parDefaut' => $cf->isSelectionneeParDefaut() || $cf->isObligatoire()];
            }
        }

        return [
            'id' => $c->getId(),
            'nom' => GrilleTarifaire::libelle($c),
            'court' => (string) $c->getProduit()?->getNom(),
            'taille' => self::demandeTaille($c),
            'largeur' => null !== $c->getLargeurDefaut() ? (float) $c->getLargeurDefaut() * 100 : null,
            'hauteur' => null !== $c->getLongueurDefaut() ? (float) $c->getLongueurDefaut() * 100 : null,
            'min' => max(1, $c->getQuantiteMinimale()),
            'max' => $c->getQuantiteMaximale(),
            'finitions' => $finitions,
        ];
    }

    /**
     * Ligne de commande calculée comme au comptoir (prix du catalogue,
     * finitions obligatoires ajoutées). N'enregistre rien.
     *
     * @param list<int> $finitionsChoisies identifiants de ProduitConfigurationFinition
     *
     * @throws \DomainException message à montrer au client
     */
    public static function construireLigne(ProduitConfiguration $config, int $quantite, ?float $largeurCm, ?float $hauteurCm, array $finitionsChoisies, bool $b2b): CommandesDetails
    {
        if ($quantite < max(1, $config->getQuantiteMinimale())) {
            throw new \DomainException(\sprintf('Minimum de commande : %d.', max(1, $config->getQuantiteMinimale())));
        }
        if (null !== $config->getQuantiteMaximale() && $quantite > $config->getQuantiteMaximale()) {
            throw new \DomainException(\sprintf('Au-delà de %d, demandez un devis.', $config->getQuantiteMaximale()));
        }

        $ligne = (new CommandesDetails())
            ->setModeConfiguration('manuel')
            ->setProduitConfiguration($config)
            ->setProduit($config->getProduit())
            ->setTypeImpression($config->getTypeImpression())
            ->setSupport($config->getSupport())
            ->setFormat($config->getFormat())
            ->setDesignation(GrilleTarifaire::libelle($config))
            ->setModeCalcul($config->getModeCalcul())
            ->setQuantite($quantite)
            ->setPrixUnitaire($config->getPrixApplicable($b2b))
            ->setTva(0);

        if (self::demandeTaille($config)) {
            $largeurCm ??= null !== $config->getLargeurDefaut() ? (float) $config->getLargeurDefaut() * 100 : null;
            $hauteurCm ??= null !== $config->getLongueurDefaut() ? (float) $config->getLongueurDefaut() * 100 : null;
            if (null === $largeurCm || null === $hauteurCm || $largeurCm <= 0 || $hauteurCm <= 0) {
                throw new \DomainException('Indiquez la largeur et la hauteur en cm.');
            }
            if ($largeurCm > self::COTE_MAX_CM || $hauteurCm > self::COTE_MAX_CM) {
                throw new \DomainException('Pour plus de 50 m, demandez un devis.');
            }
            $ligne->setLargeur($largeurCm / 100)->setLongueur($hauteurCm / 100)->calculerSurface();
        }

        $surface = null !== $ligne->getSurface() ? (float) $ligne->getSurface() : null;
        $longueur = null !== $ligne->getLongueur() ? (float) $ligne->getLongueur() : null;
        foreach ($config->getConfigurationFinitions() as $cf) {
            if (!$cf->isActive() || (!$cf->isObligatoire() && !\in_array($cf->getId(), $finitionsChoisies, true))) {
                continue;
            }
            if (!$cf->accepteQuantite($quantite)) {
                throw new \DomainException(\sprintf('%s : pas possible pour %d exemplaire%s.', $cf->getFinition()?->getNom(), $quantite, $quantite > 1 ? 's' : ''));
            }
            $ligne->addFinition((new CommandeDetailFinition())
                ->setConfigurationFinition($cf)
                ->setQuantite($quantite)
                ->setMontant($cf->calculerMontant($quantite, $surface, $longueur, max(1, $ligne->getNombreFaces()))));
        }

        return $ligne->calculerTotaux($b2b);
    }

    /**
     * Ligne « Express » : la majoration du délai express, en % du prix des
     * produits. Une ligne libre de la commande, visible sur les documents,
     * sans rien à fabriquer ni à contrôler (jamais un prix de ligne gonflé).
     */
    public static function ligneExpress(int $montantProduits, int $pourcentage, int $heures): CommandesDetails
    {
        $ligne = (new CommandesDetails())
            ->setTypeLigne(CommandesDetails::TYPE_LIBRE)
            ->setDesignation(\sprintf('Express %d h (+%d %%)', $heures, $pourcentage))
            ->setModeCalcul('forfait')
            ->setQuantite(1)
            ->setPrixUnitaire((int) round(max(0, $montantProduits) * max(0, $pourcentage) / 100))
            ->setTva(0)
            ->setPrePresseNecessaire(false)
            ->setProductionNecessaire(false);

        return $ligne->calculerTotaux();
    }

    /**
     * Commande en ligne non enregistrée : la ligne du produit, la ligne
     * express si demandée, la réception et les totaux (frais de livraison
     * compris). Sert à l'estimation comme à l'enregistrement.
     */
    public static function assembler(CommandesDetails $ligne, ?OptionsCommande $options = null, int $heuresExpress = 24): Commandes
    {
        $commande = (new Commandes())
            ->setCanal(Commandes::CANAL_EN_LIGNE)
            ->setStatut(false) // à confirmer par l'atelier
            ->setEtat(true);
        $commande->addCommandesDetail($ligne);
        if (null !== $options) {
            $commande->setDelaiProduction($options->delai);
            if ($options->estExpress() && $options->majorationExpress > 0) {
                $commande->addCommandesDetail(self::ligneExpress($ligne->getTotalTtc(), $options->majorationExpress, $heuresExpress));
            }
            $commande->setModeLivraison($options->livraison ? Commandes::LIVRAISON_DOMICILE : Commandes::LIVRAISON_RETRAIT)
                ->setFraisLivraison($options->livraison ? $options->fraisLivraison : 0)
                ->setAdresseLivraison($options->livraison ? $options->adresse : null)
                ->setCreneauLivraison($options->livraison ? $options->creneau : null)
                ->setDateLivraison($options->dateLivraison);
        }

        return $commande->recalculerTotaux();
    }

    /**
     * Prix, sans rien enregistrer : la ligne du produit, puis l'express et
     * la livraison quand ils sont choisis.
     *
     * @param list<int> $finitions
     *
     * @return array{total: int, detail: list<array{libelle: string, montant: int}>}
     */
    public static function estimer(ProduitConfiguration $config, int $quantite, ?float $largeurCm, ?float $hauteurCm, array $finitions, bool $b2b, ?OptionsCommande $options = null, int $heuresExpress = 24): array
    {
        $ligne = self::construireLigne($config, $quantite, $largeurCm, $hauteurCm, $finitions, $b2b);
        $commande = self::assembler($ligne, $options, $heuresExpress);
        $detail = [['libelle' => 'Impression', 'montant' => $ligne->calculerMontantImpression()]];
        foreach ($ligne->getFinitions() as $f) {
            $detail[] = ['libelle' => (string) $f->getNomFinition(), 'montant' => (int) $f->getMontant()];
        }
        foreach ($commande->getCommandesDetails() as $l) {
            if ($l !== $ligne) {
                $detail[] = ['libelle' => (string) $l->getDesignation(), 'montant' => $l->getTotalTtc()];
            }
        }
        if ($commande->getFraisLivraison() > 0) {
            $detail[] = ['libelle' => 'Livraison', 'montant' => $commande->getFraisLivraison()];
        }

        return ['total' => $commande->getTotalTtc(), 'detail' => $detail];
    }

    /**
     * Ce que la page reçoit de l'estimation : le total et son détail pour qui
     * voit les montants (CompteClient::montantsVisibles) ; pour un demandeur,
     * seulement « pas de montant » (sa quantité a été contrôlée, le prix est
     * vu par son valideur).
     *
     * @param array{total: int, detail: list<array{libelle: string, montant: int}>} $estimation
     *
     * @return array<string, mixed>
     */
    public static function estimationPourLaPage(array $estimation, bool $montants): array
    {
        return $montants ? ['montants' => true] + $estimation : ['montants' => false];
    }

    /**
     * Enregistre la commande, « à confirmer » par l'atelier, avec les
     * fichiers du client choisis.
     *
     * @param list<int>                   $finitions
     * @param list<CommandeDetailFichier> $fichiers  fichiers du client, déjà vérifiés
     * @param \Closure(CommandesDetails): void|null $ajuster prix propre à la page (Atelier 3D : seconde face, palier), recalculé ici
     * @param string|null $codePromo code promo tapé : vérifié avant d'enregistrer (PromotionsSaisie::appliquerCodeEnLigne)
     * @param OptionsCommande|null $options délai, réception et créneau (nouvelle commande en quatre étapes)
     *
     * @throws \DomainException
     * @throws \App\Service\Promotions\CodePromoRefuse code promo refusé : rien n'est enregistré
     */
    public function commander(Clients $client, ProduitConfiguration $config, int $quantite, ?float $largeurCm, ?float $hauteurCm, array $finitions, array $fichiers, ?string $note, \DateTimeImmutable $maintenant, ?\Closure $ajuster = null, ?string $codePromo = null, ?OptionsCommande $options = null, int $heuresExpress = 24): Commandes
    {
        $this->verifierEnAttente($client);

        $ligne = self::construireLigne($config, $quantite, $largeurCm, $hauteurCm, $finitions, $client->isB2B());
        $ligne->setDemandeClient($note);
        if (null !== $ajuster) {
            $ajuster($ligne);
        }

        $commande = self::assembler($ligne, $options, $heuresExpress)
            ->setClients($client)
            ->setDateCommande($maintenant);
        if (null !== $this->promotionsSaisie && null !== $codePromo && '' !== trim($codePromo)) {
            $this->promotionsSaisie->appliquerCodeEnLigne($commande, $codePromo, $maintenant);
        }

        $this->em->wrapInTransaction(function () use ($commande, $ligne, $fichiers, $maintenant): void {
            $this->em->persist($commande);
            $this->em->flush();
            // Même numéro qu'au comptoir ; le site y ajoute W-.
            $commande->setNumero(\sprintf('CMD-%06d-%s', $commande->getId(), $maintenant->format('m-Y')));
            foreach ($fichiers as $f) {
                $f->setCommandeDetail($ligne);
            }
            $this->em->flush();
        });

        return $commande;
    }

    /**
     * Pas plus de EN_ATTENTE_MAX commandes en ligne non confirmées par client.
     *
     * @throws \DomainException
     */
    public function verifierEnAttente(Clients $client): void
    {
        $enAttente = $this->em->getRepository(Commandes::class)->count(['clients' => $client, 'statut' => false, 'deleted' => false, 'canal' => Commandes::CANAL_EN_LIGNE]);
        if ($enAttente >= self::EN_ATTENTE_MAX) {
            throw new \DomainException('Vous avez déjà plusieurs commandes en attente de confirmation. L\'atelier vous recontacte.');
        }
    }

    public function enregistrer(): void
    {
        $this->em->flush();
    }
}
