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
        ];
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
     * Le compte voit-il les prix de l'atelier ? Un particulier, et dans une
     * entreprise qui paie ou qui valide les commandes ; pas un demandeur
     * (sa demande part chez son valideur), ni un compte en lecture seule.
     */
    public static function montantsVisibles(CompteClient $compte): bool
    {
        return $compte->peut(CompteClient::DROIT_PAYEUR) || $compte->peut(CompteClient::DROIT_VALIDEUR);
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
