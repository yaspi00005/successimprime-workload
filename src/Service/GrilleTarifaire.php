<?php

namespace App\Service;

use App\Entity\CategorieProduit;
use App\Entity\PrixHistorique;
use App\Entity\ProduitArticleStock;
use App\Entity\ProduitClient;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Grille tarifaire (maquette 37) : les prix de chaque configuration de
 * produit, rangés par catégorie, les finitions et les prix négociés.
 * C'est la même source de prix que la saisie de devis et de commande.
 */
final class GrilleTarifaire
{
    public const UNITES = [
        'metre_carre' => 'm²',
        'metre' => 'm',
        'unite' => 'unité',
        'forfait' => 'forfait',
        'heure' => 'heure',
    ];

    /** Champs modifiables d'une configuration, avec leur libellé. */
    public const CHAMPS_CONFIGURATION = [
        'prixBase' => 'Prix public',
        'prixB2B' => 'Prix revendeur',
        'quantiteMinimale' => 'Minimum',
        'active' => 'En vente',
    ];

    public const CHAMPS_FINITION = [
        'prix' => 'Prix',
        'active' => 'Proposée',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return list<array{cle: string, libelle: string, nombre: int}>
     */
    public function onglets(): array
    {
        $onglets = [];
        foreach ($this->em->getRepository(CategorieProduit::class)->findBy([], ['ordre' => 'ASC', 'nom' => 'ASC']) as $categorie) {
            $onglets[] = ['cle' => 'c'.$categorie->getId(), 'libelle' => (string) $categorie->getNom(), 'nombre' => \count($this->configurations($categorie))];
        }
        $sans = \count($this->configurations(null));
        if ($sans > 0) {
            $onglets[] = ['cle' => 'autres', 'libelle' => 'Sans catégorie', 'nombre' => $sans];
        }
        $onglets[] = ['cle' => 'finitions', 'libelle' => 'Finitions', 'nombre' => $this->em->getRepository(ProduitConfigurationFinition::class)->count([])];
        $onglets[] = ['cle' => 'negocies', 'libelle' => 'Prix négociés clients', 'nombre' => $this->em->getRepository(ProduitClient::class)->count([])];

        return $onglets;
    }

    public function categorie(string $cle): ?CategorieProduit
    {
        return str_starts_with($cle, 'c') ? $this->em->getRepository(CategorieProduit::class)->find((int) substr($cle, 1)) : null;
    }

    /**
     * Configurations d'une catégorie (null : produits sans catégorie).
     *
     * @return list<ProduitConfiguration>
     */
    public function configurations(?CategorieProduit $categorie): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('pc', 'p', 's', 'f', 't')
            ->from(ProduitConfiguration::class, 'pc')
            ->innerJoin('pc.produit', 'p')
            ->leftJoin('pc.support', 's')
            ->leftJoin('pc.format', 'f')
            ->leftJoin('pc.typeImpression', 't')
            ->orderBy('p.ordre', 'ASC')
            ->addOrderBy('p.nom', 'ASC')
            ->addOrderBy('pc.ordre', 'ASC');
        null === $categorie
            ? $qb->andWhere('p.categorieProduit IS NULL')
            : $qb->andWhere('p.categorieProduit = :c')->setParameter('c', $categorie);

        return $qb->getQuery()->getResult();
    }

    /**
     * @return list<ProduitConfigurationFinition>
     */
    public function finitions(): array
    {
        return $this->em->createQueryBuilder()
            ->select('pcf', 'pc', 'p', 'f', 's')
            ->from(ProduitConfigurationFinition::class, 'pcf')
            ->innerJoin('pcf.produitConfiguration', 'pc')
            ->innerJoin('pc.produit', 'p')
            ->leftJoin('pc.support', 's')
            ->innerJoin('pcf.finition', 'f')
            ->orderBy('f.ordre', 'ASC')
            ->addOrderBy('p.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ProduitClient>
     */
    public function prixNegocies(): array
    {
        return $this->em->getRepository(ProduitClient::class)->findBy([], ['id' => 'DESC'], 200);
    }

    /**
     * Coût des matières pour une unité de vente (1 m², 1 m ou 1 pièce),
     * d'après les articles liés au produit et leur prix d'achat. Null si
     * un prix d'achat manque ou si rien n'est lié.
     */
    public static function coutMatiere(Produits $produit, string $modeCalcul): ?int
    {
        $total = 0.0;
        $lie = false;
        foreach ($produit->getArticlesStock() as $liaison) {
            $article = $liaison->getArticle();
            if (!$liaison->isActif() || null === $article) {
                continue;
            }
            if (null === $article->getPrixAchat()) {
                return null;
            }
            $lie = true;
            $surface = 'metre_carre' === $modeCalcul ? 1.0 : 0.0;
            $metres = 'metre' === $modeCalcul ? 1.0 : 0.0;
            $total += CalculateurCoutRevient::besoinArticle($liaison->getModeCalcul(), (float) $liaison->getCoefficient(), 1, $surface, $metres) * $article->getPrixAchat();
        }

        return $lie ? (int) round($total) : null;
    }

    /**
     * Applique les valeurs postées et trace chaque changement.
     *
     * @param array<int|string, array<string, mixed>> $saisies par id de configuration
     *
     * @return array{changements: int, erreurs: list<string>}
     */
    public function enregistrerConfigurations(array $saisies, ?User $par): array
    {
        $changements = 0;
        $erreurs = [];
        foreach ($saisies as $id => $valeurs) {
            $config = $this->em->getRepository(ProduitConfiguration::class)->find((int) $id);
            if (!$config instanceof ProduitConfiguration || !\is_array($valeurs)) {
                continue;
            }
            $libelle = self::libelle($config);
            $nouveau = [
                'prixBase' => self::entier($valeurs['prixBase'] ?? null),
                'prixB2B' => self::entier($valeurs['prixB2B'] ?? null),
                'quantiteMinimale' => self::entier($valeurs['quantiteMinimale'] ?? null) ?? 1,
                'active' => !empty($valeurs['active']),
            ];
            if (false === $nouveau['prixBase'] || false === $nouveau['prixB2B'] || false === $nouveau['quantiteMinimale']) {
                $erreurs[] = sprintf('%s : les prix et le minimum sont des nombres entiers.', $libelle);
                continue;
            }
            if (null !== $nouveau['prixB2B'] && null !== $nouveau['prixBase'] && $nouveau['prixB2B'] > $nouveau['prixBase']) {
                $erreurs[] = sprintf('%s : le prix revendeur dépasse le prix public.', $libelle);
                continue;
            }
            $ancien = [
                'prixBase' => $config->getPrixBase(),
                'prixB2B' => $config->getPrixB2B(),
                'quantiteMinimale' => $config->getQuantiteMinimale(),
                'active' => $config->isActive(),
            ];
            foreach ($nouveau as $champ => $valeur) {
                if ($ancien[$champ] === $valeur) {
                    continue;
                }
                match ($champ) {
                    'prixBase' => $config->setPrixBase($valeur),
                    'prixB2B' => $config->setPrixB2B($valeur),
                    'quantiteMinimale' => $config->setQuantiteMinimale(max(1, (int) $valeur)),
                    'active' => $config->setActive((bool) $valeur),
                };
                $this->em->persist(new PrixHistorique('configuration', (int) $config->getId(), $libelle, $champ, self::texte($ancien[$champ]), self::texte($valeur), $par));
                ++$changements;
            }
        }
        $this->em->flush();

        return ['changements' => $changements, 'erreurs' => $erreurs];
    }

    /**
     * @param array<int|string, array<string, mixed>> $saisies par id de finition de configuration
     *
     * @return array{changements: int, erreurs: list<string>}
     */
    public function enregistrerFinitions(array $saisies, ?User $par): array
    {
        $changements = 0;
        $erreurs = [];
        foreach ($saisies as $id => $valeurs) {
            $pcf = $this->em->getRepository(ProduitConfigurationFinition::class)->find((int) $id);
            if (!$pcf instanceof ProduitConfigurationFinition || !\is_array($valeurs)) {
                continue;
            }
            $libelle = sprintf('%s · %s', $pcf->getFinition()?->getNom(), self::libelle($pcf->getProduitConfiguration()));
            $prix = self::entier($valeurs['prix'] ?? null) ?? 0;
            if (false === $prix) {
                $erreurs[] = sprintf('%s : le prix est un nombre entier.', $libelle);
                continue;
            }
            $active = !empty($valeurs['active']);
            if ($pcf->getPrix() !== $prix) {
                $this->em->persist(new PrixHistorique('finition', (int) $pcf->getId(), $libelle, 'prix', (string) $pcf->getPrix(), (string) $prix, $par));
                $pcf->setPrix($prix);
                ++$changements;
            }
            if ($pcf->isActive() !== $active) {
                $this->em->persist(new PrixHistorique('finition', (int) $pcf->getId(), $libelle, 'active', self::texte($pcf->isActive()), self::texte($active), $par));
                $pcf->setActive($active);
                ++$changements;
            }
        }
        $this->em->flush();

        return ['changements' => $changements, 'erreurs' => $erreurs];
    }

    /**
     * @return list<PrixHistorique>
     */
    public function historique(int $limite = 150): array
    {
        return $this->em->getRepository(PrixHistorique::class)->findBy([], ['changeLe' => 'DESC', 'id' => 'DESC'], $limite);
    }

    public static function libelle(?ProduitConfiguration $config): string
    {
        if (null === $config) {
            return '—';
        }
        $parties = array_filter([$config->getSupport()?->getNom(), $config->getFormat()?->getNom()]);
        $nom = (string) $config->getProduit()?->getNom();

        return [] === $parties ? $nom : $nom.' · '.implode(' · ', $parties);
    }

    /**
     * Champ vide : null ; nombre entier positif (espaces tolérés) ; sinon false.
     */
    public static function entier(mixed $valeur): int|false|null
    {
        $texte = str_replace([' ', "\u{202F}", "\u{A0}"], '', trim((string) $valeur));
        if ('' === $texte) {
            return null;
        }

        return ctype_digit($texte) ? (int) $texte : false;
    }

    private static function texte(int|bool|null $valeur): ?string
    {
        return \is_bool($valeur) ? ($valeur ? 'oui' : 'non') : (null === $valeur ? null : (string) $valeur);
    }
}
