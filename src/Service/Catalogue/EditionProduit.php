<?php

namespace App\Service\Catalogue;

use App\Entity\Articles;
use App\Entity\CategorieProduit;
use App\Entity\Finition;
use App\Entity\Format;
use App\Entity\Machines;
use App\Entity\PrixHistorique;
use App\Entity\ProduitArticleStock;
use App\Entity\ProduitConfiguration;
use App\Entity\ProduitConfigurationFinition;
use App\Entity\Produits;
use App\Entity\Supports;
use App\Entity\TypesImpression;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Enregistrement de la fiche produit (maquette PR02, docs/produits.md) :
 * général, prix, délai, machines, visibilité, compatibilités du comptoir
 * et nomenclature (matières consommées). Les prix changés vont dans
 * l'historique des prix (PrixHistorique, cible « produit »).
 */
final class EditionProduit
{
    public const MODES = ['metre_carre' => 'm² (au mètre carré)', 'metre' => 'mètre linéaire', 'unite' => 'pièce', 'exemplaire' => 'exemplaire', 'feuille' => 'feuille', 'forfait' => 'forfait', 'heure' => 'heure', 'face' => 'face', 'point' => 'point'];

    public const MODES_NOMENCLATURE = [
        ProduitArticleStock::MODE_SURFACE => 'par m²',
        ProduitArticleStock::MODE_METRE => 'par mètre',
        ProduitArticleStock::MODE_QUANTITE => 'par pièce vendue',
        ProduitArticleStock::MODE_FORFAIT => 'forfait par ligne',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Applique les valeurs postées. Rien n'est enregistré s'il y a une erreur.
     *
     * @param array<string, mixed> $d
     *
     * @return list<string> erreurs à montrer
     */
    public function appliquer(Produits $p, array $d, bool $avecCouts, ?User $par): array
    {
        $erreurs = [];
        $nom = trim((string) ($d['nom'] ?? ''));
        $code = strtoupper(trim((string) ($d['code'] ?? '')));
        $description = trim((string) ($d['description'] ?? ''));
        $categorie = $this->em->find(CategorieProduit::class, (int) ($d['categorie'] ?? 0));

        if ('' === $nom) {
            $erreurs[] = 'Le nom du produit est obligatoire.';
        }
        if ('' === $code) {
            $erreurs[] = 'Le code du produit est obligatoire.';
        }
        if (null === $categorie) {
            $erreurs[] = 'Choisissez une catégorie.';
        }
        foreach (['nom' => $nom, 'code' => $code] as $champ => $valeur) {
            $autre = '' === $valeur ? null : $this->em->getRepository(Produits::class)->findOneBy([$champ => $valeur]);
            if (null !== $autre && $autre->getId() !== $p->getId()) {
                $erreurs[] = 'nom' === $champ ? 'Un autre produit porte déjà ce nom.' : 'Un autre produit porte déjà ce code.';
            }
        }
        $prixBase = self::entier($d['prix_base'] ?? null);
        $prixB2B = self::entier($d['prix_b2b'] ?? null);
        $delai = self::entier($d['delai'] ?? null);
        if (false === $prixBase || false === $prixB2B || false === $delai) {
            $erreurs[] = 'Les prix et le délai sont des nombres entiers.';
        } elseif (null !== $prixBase && null !== $prixB2B && $prixB2B > $prixBase) {
            $erreurs[] = 'Le prix revendeur ne peut pas dépasser le prix de base.';
        }
        $cout = $avecCouts ? self::entier($d['cout_saisi'] ?? null) : null;
        if (false === $cout) {
            $erreurs[] = 'Le coût saisi est un nombre entier.';
        }
        if ([] !== $erreurs) {
            return $erreurs;
        }

        $this->tracer($p, 'prixBase', null === $p->getPrixBase() ? null : (int) round($p->getPrixBase()), $prixBase, $par);
        $this->tracer($p, 'prixB2B', null === $p->getPrixB2B() ? null : (int) round($p->getPrixB2B()), $prixB2B, $par);

        $p->setNom($nom)->setCode($code)->setDescription('' === $description ? $nom : mb_substr($description, 0, 1000))
            ->setCategorieProduit($categorie)
            ->setModeCalcul((string) ($d['mode_calcul'] ?? 'unite'))
            ->setPrixB2B(null)->setPrixBase(null === $prixBase ? null : (float) $prixBase)->setPrixB2B(null === $prixB2B ? null : (float) $prixB2B)
            ->setDelaiHeures($delai ?: null)
            ->setMachine($this->machine($d['machine'] ?? null))
            ->setMachineSecours($this->machine($d['machine_secours'] ?? null))
            ->setActif(!empty($d['actif']))
            ->setVisibleEnLigne(!empty($d['en_ligne']));
        $p->setNomCommercial(NomCommercial::nettoyer((string) ($d['nom_commercial'] ?? '')));
        if (!$p->isVisibleEnLigne()) {
            $p->setMotifHorsLigne((string) ($d['motif'] ?? ''));
        }
        if ($avecCouts) {
            $p->setCoutRevientSaisi($cout);
        }

        if (!empty($d['compatibilites'])) {
            $this->remplacer($p->getTypesImpressions(), TypesImpression::class, $d['types'] ?? [], $p->addTypeImpression(...), $p->removeTypeImpression(...));
            $this->remplacer($p->getSupports(), Supports::class, $d['supports'] ?? [], $p->addSupport(...), $p->removeSupport(...));
            $this->remplacer($p->getFormats(), Format::class, $d['formats'] ?? [], $p->addFormat(...), $p->removeFormat(...));
            $this->remplacer($p->getFinitions(), Finition::class, $d['finitions'] ?? [], $p->addFinition(...), $p->removeFinition(...));
        }
        if (!empty($d['nomenclature_envoyee'])) {
            $this->nomenclature($p, \is_array($d['nomenclature'] ?? null) ? $d['nomenclature'] : []);
        }

        $this->em->persist($p);
        $this->em->flush();

        return [];
    }

    /**
     * Copie du produit (sans ses ventes) : « (copie) » dans le nom, code
     * suffixé, masquée en ligne tant qu'elle n'est pas relue.
     */
    public function dupliquer(Produits $source): Produits
    {
        $copie = (new Produits())
            ->setNom(self::libre(Produits::class, 'nom', $source->getNom().' (copie)'))
            ->setCode(self::libre(Produits::class, 'code', $source->getCode().'-COPIE', 50))
            ->setDescription((string) $source->getDescription())
            ->setCategorieProduit($source->getCategorieProduit())
            ->setModeCalcul($source->getModeCalcul())
            ->setPrixBase($source->getPrixBase())
            ->setPrixB2B($source->getPrixB2B())
            ->setPersonnalisable($source->isPersonnalisable())
            ->setOrdre($source->getOrdre() + 1)
            ->setDelaiHeures($source->getDelaiHeures())
            ->setMachine($source->getMachine())
            ->setMachineSecours($source->getMachineSecours())
            ->setCoutRevientSaisi($source->getCoutRevientSaisi())
            ->setGestionStock($source->isGestionStock())
            ->setVisibleEnLigne(false)
            ->setMotifHorsLigne('copie à relire');
        $copie->setNomCommercial($source->getNomCommercial());
        foreach ($source->getTypesImpressions() as $t) {
            $copie->addTypeImpression($t);
        }
        foreach ($source->getSupports() as $s) {
            $copie->addSupport($s);
        }
        foreach ($source->getFormats() as $f) {
            $copie->addFormat($f);
        }
        foreach ($source->getFinitions() as $f) {
            $copie->addFinition($f);
        }
        foreach ($source->getArticlesStock() as $l) {
            $copie->addArticlesStock((new ProduitArticleStock())->setArticle($l->getArticle())->setCoefficient($l->getCoefficient())->setModeCalcul($l->getModeCalcul())->setActif($l->isActif())->setObligatoire($l->isObligatoire()));
        }
        foreach ($source->getConfigurations() as $c) {
            $nc = (new ProduitConfiguration())
                ->setTypeImpression($c->getTypeImpression())->setSupport($c->getSupport())->setFormat($c->getFormat())
                ->setActive($c->isActive())->setOrdre($c->getOrdre())->setDescription($c->getDescription())
                ->setPrixBase($c->getPrixBase())->setPrixB2B($c->getPrixB2B())->setModeCalcul($c->getModeCalcul())
                ->setModeDimension($c->getModeDimension())->setLargeurDefaut($c->getLargeurDefaut())->setLongueurDefaut($c->getLongueurDefaut())
                ->setQuantiteMinimale($c->getQuantiteMinimale())->setQuantiteMaximale($c->getQuantiteMaximale());
            foreach ($c->getConfigurationFinitions() as $cf) {
                $nc->addConfigurationFinition((new ProduitConfigurationFinition())
                    ->setFinition($cf->getFinition())->setObligatoire($cf->isObligatoire())->setSelectionneeParDefaut($cf->isSelectionneeParDefaut())
                    ->setPayante($cf->isPayante())->setActive($cf->isActive())->setPrix($cf->getPrix())->setModeCalcul($cf->getModeCalcul())
                    ->setQuantiteMinimale($cf->getQuantiteMinimale())->setQuantiteMaximale($cf->getQuantiteMaximale())->setOrdre($cf->getOrdre())->setDescription($cf->getDescription()));
            }
            $copie->addConfiguration($nc);
        }
        $this->em->persist($copie);
        $this->em->flush();

        return $copie;
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

    private function tracer(Produits $p, string $champ, ?int $avant, ?int $apres, ?User $par): void
    {
        if (null === $p->getId() || $avant === $apres) {
            return;
        }
        $this->em->persist(new PrixHistorique('produit', (int) $p->getId(), (string) $p->getNom(), $champ, null === $avant ? null : (string) $avant, null === $apres ? null : (string) $apres, $par));
    }

    private function machine(mixed $id): ?Machines
    {
        $id = (int) $id;

        return $id > 0 ? $this->em->find(Machines::class, $id) : null;
    }

    /**
     * @param \Doctrine\Common\Collections\Collection<int, object> $actuels
     * @param class-string $classe
     */
    private function remplacer(\Doctrine\Common\Collections\Collection $actuels, string $classe, mixed $ids, callable $ajouter, callable $retirer): void
    {
        $ids = \is_array($ids) ? array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0))) : [];
        foreach ($actuels->toArray() as $e) {
            if (!\in_array($e->getId(), $ids, true)) {
                $retirer($e);
            }
        }
        foreach ([] === $ids ? [] : $this->em->getRepository($classe)->findBy(['id' => $ids]) as $e) {
            $ajouter($e);
        }
    }

    /**
     * @param array<int|string, mixed> $lignes
     */
    private function nomenclature(Produits $p, array $lignes): void
    {
        $voulues = [];
        foreach ($lignes as $l) {
            if (!\is_array($l) || (int) ($l['article'] ?? 0) <= 0) {
                continue;
            }
            $article = $this->em->find(Articles::class, (int) $l['article']);
            $coefficient = str_replace(',', '.', trim((string) ($l['coefficient'] ?? '1')));
            $mode = (string) ($l['mode'] ?? ProduitArticleStock::MODE_QUANTITE);
            if (null === $article || !is_numeric($coefficient) || (float) $coefficient <= 0 || !\array_key_exists($mode, self::MODES_NOMENCLATURE)) {
                continue;
            }
            $voulues[$article->getId()] = [$article, $coefficient, $mode];
        }
        foreach ($p->getArticlesStock()->toArray() as $liaison) {
            $id = $liaison->getArticle()?->getId();
            if (!isset($voulues[$id])) {
                $p->removeArticlesStock($liaison);
                continue;
            }
            $liaison->setCoefficient($voulues[$id][1])->setModeCalcul($voulues[$id][2])->setActif(true);
            unset($voulues[$id]);
        }
        foreach ($voulues as [$article, $coefficient, $mode]) {
            $p->addArticlesStock((new ProduitArticleStock())->setArticle($article)->setCoefficient($coefficient)->setModeCalcul($mode));
        }
        $p->setGestionStock(!$p->getArticlesStock()->isEmpty());
    }

    /**
     * @param class-string $classe
     */
    private function libre(string $classe, string $champ, string $voulu, int $longueur = 255): string
    {
        $voulu = mb_substr($voulu, 0, $longueur - 3);
        $essai = $voulu;
        for ($i = 2; null !== $this->em->getRepository($classe)->findOneBy([$champ => $essai]); ++$i) {
            $essai = $voulu.' '.$i;
        }

        return $essai;
    }
}
