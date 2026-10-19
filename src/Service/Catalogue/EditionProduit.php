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

    /** Modes de calcul d'une configuration (ProduitConfiguration::setModeCalcul). */
    public const MODES_CONFIGURATION = ['unite' => 'pièce', 'metre_carre' => 'm²', 'metre' => 'mètre linéaire', 'forfait' => 'forfait', 'heure' => 'heure'];

    public const MODES_NOMENCLATURE = [
        ProduitArticleStock::MODE_SURFACE => 'par m²',
        ProduitArticleStock::MODE_METRE => 'par mètre',
        ProduitArticleStock::MODE_QUANTITE => 'par pièce vendue',
        ProduitArticleStock::MODE_FORFAIT => 'forfait par ligne',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        /** Garde-fous des paliers de prix ; absents des tests : pas de contrôle de marge. */
        private readonly ?GardeFousPrix $gardeFous = null,
        private readonly ?TarifClient $tarif = null,
    ) {
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
        // Configurations (prix par type d'impression, support et format) : saisies dans la fiche.
        $configs = !empty($d['configurations_envoyees']) && null !== $p->getId()
            ? $this->lireConfigurations($p, \is_array($d['configurations'] ?? null) ? $d['configurations'] : [], \is_array($d['configuration_nouvelle'] ?? null) ? $d['configuration_nouvelle'] : [], $d)
            : ['erreurs' => [], 'plan' => [], 'nouvelle' => null];
        array_push($erreurs, ...$configs['erreurs']);
        $prixNouveaux = [];
        foreach ($configs['plan'] as $id => $l) {
            $prixNouveaux[$id] = (int) $l['valeurs']['prixBase'];
        }
        // Paliers de prix des configurations (docs/produits.md) : contrôlés avant tout changement,
        // avec le prix de configuration saisi dans le même envoi.
        $paliers = !empty($d['paliers_envoyes']) && null !== $p->getId()
            ? $this->lirePaliers($p, \is_array($d['paliers'] ?? null) ? $d['paliers'] : [], !empty($d['paliers_accord']), $avecCouts, $prixNouveaux)
            : ['erreurs' => [], 'plan' => [], 'alertes' => []];
        array_push($erreurs, ...$paliers['erreurs']);
        if ([] !== $erreurs) {
            return $erreurs;
        }
        $this->appliquerConfigurations($p, $configs['plan'], $configs['nouvelle'], $par);
        $this->appliquerPaliers($p, $paliers['plan'], $paliers['alertes'], $par);

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

        $nouvelle = $configs['nouvelle'];
        if (null !== $nouvelle && null !== $nouvelle->getId()) {
            // La création compte dans l'historique des prix, comme un changement de la grille.
            foreach (['prixBase' => $nouvelle->getPrixBase(), 'prixB2B' => $nouvelle->getPrixB2B()] as $champ => $valeur) {
                if (null !== $valeur) {
                    $this->em->persist(new PrixHistorique('configuration', (int) $nouvelle->getId(), \App\Service\GrilleTarifaire::libelle($nouvelle), $champ, null, (string) $valeur, $par));
                }
            }
            $this->em->flush();
        }

        return [];
    }

    /**
     * Configurations postées depuis la carte « Prix par configuration » :
     * prix, prix revendeur, minimum et « en vente » des configurations du
     * produit, et une nouvelle configuration (type d'impression, support,
     * format compatibles avec le produit et le support, mêmes règles que
     * l'ancienne page /produit-configurations). Rien n'est changé ici.
     *
     * @param array<mixed> $postes   configurations[id][prixBase|prixB2B|quantiteMinimale|active]
     * @param array<mixed> $nouvelle configuration_nouvelle[type|support|format|mode|prixBase|prixB2B|quantiteMinimale]
     * @param array<mixed> $d        tout l'envoi (compatibilités cochées dans le même envoi)
     *
     * @return array{erreurs: list<string>, plan: array<int, array{config: ProduitConfiguration, valeurs: array{prixBase: ?int, prixB2B: ?int, quantiteMinimale: int, active: bool}}>, nouvelle: ?ProduitConfiguration}
     */
    private function lireConfigurations(Produits $p, array $postes, array $nouvelle, array $d): array
    {
        $erreurs = [];
        $plan = [];
        foreach ($p->getConfigurations() as $c) {
            $v = $postes[(int) $c->getId()] ?? null;
            if (!\is_array($v)) {
                continue;
            }
            $libelle = \App\Service\GrilleTarifaire::libelle($c);
            $valeurs = [
                'prixBase' => self::entier($v['prixBase'] ?? null),
                'prixB2B' => self::entier($v['prixB2B'] ?? null),
                'quantiteMinimale' => self::entier($v['quantiteMinimale'] ?? null) ?? 1,
                'active' => !empty($v['active']),
            ];
            if (false === $valeurs['prixBase'] || false === $valeurs['prixB2B'] || false === $valeurs['quantiteMinimale']) {
                $erreurs[] = \sprintf('%s : les prix et le minimum sont des nombres entiers.', $libelle);
                continue;
            }
            if (null !== $valeurs['prixB2B'] && null !== $valeurs['prixBase'] && $valeurs['prixB2B'] > $valeurs['prixBase']) {
                $erreurs[] = \sprintf('%s : le prix revendeur dépasse le prix public.', $libelle);
                continue;
            }
            $valeurs['quantiteMinimale'] = max(1, $valeurs['quantiteMinimale']);
            $plan[(int) $c->getId()] = ['config' => $c, 'valeurs' => $valeurs];
        }

        $remplie = false;
        foreach (['type', 'support', 'format', 'prixBase', 'prixB2B'] as $champ) {
            if (\is_scalar($nouvelle[$champ] ?? null) && '' !== trim((string) $nouvelle[$champ])) {
                $remplie = true;
            }
        }
        if (!$remplie) {
            return ['erreurs' => $erreurs, 'plan' => $plan, 'nouvelle' => null];
        }

        $type = $this->em->find(TypesImpression::class, (int) ($nouvelle['type'] ?? 0));
        $support = $this->em->find(Supports::class, (int) ($nouvelle['support'] ?? 0));
        $format = (int) ($nouvelle['format'] ?? 0) > 0 ? $this->em->find(Format::class, (int) $nouvelle['format']) : null;
        $prixBase = self::entier($nouvelle['prixBase'] ?? null);
        $prixB2B = self::entier($nouvelle['prixB2B'] ?? null);
        $minimum = self::entier($nouvelle['quantiteMinimale'] ?? null) ?? 1;
        $mode = (string) ($nouvelle['mode'] ?? '');

        // Compatibilités du produit : celles cochées dans le même envoi, sinon celles enregistrées.
        $ids = static fn (iterable $liste): array => array_map(static fn (object $e): int => (int) $e->getId(), [...$liste]);
        $compatibles = static fn (string $cle, iterable $actuels): array => !empty($d['compatibilites'])
            ? array_map('intval', \is_array($d[$cle] ?? null) ? $d[$cle] : [])
            : $ids($actuels);

        $avant = \count($erreurs);
        if (null === $type || null === $support) {
            $erreurs[] = 'Nouvelle configuration : choisissez le type d\'impression et le support.';
        } else {
            if (!\in_array((int) $type->getId(), $compatibles('types', $p->getTypesImpressions()), true)) {
                $erreurs[] = 'Nouvelle configuration : ce type d\'impression n\'est pas compatible avec le produit (cochez-le dans « Compatibilités »).';
            }
            if (!\in_array((int) $support->getId(), $compatibles('supports', $p->getSupports()), true)) {
                $erreurs[] = 'Nouvelle configuration : ce support n\'est pas compatible avec le produit (cochez-le dans « Compatibilités »).';
            }
            // Le support affine : s'il liste ses types d'impression ou ses formats, le choix doit y être.
            $typesSupport = $ids($support->getTypesImpressions());
            if ([] !== $typesSupport && !\in_array((int) $type->getId(), $typesSupport, true)) {
                $erreurs[] = 'Nouvelle configuration : ce support n\'est pas compatible avec le type d\'impression.';
            }
            if (null !== $format) {
                if (!\in_array((int) $format->getId(), $compatibles('formats', $p->getFormats()), true)) {
                    $erreurs[] = 'Nouvelle configuration : ce format n\'est pas compatible avec le produit (cochez-le dans « Compatibilités »).';
                }
                $formatsSupport = $ids($support->getFormats());
                if ([] !== $formatsSupport && !\in_array((int) $format->getId(), $formatsSupport, true)) {
                    $erreurs[] = 'Nouvelle configuration : ce format n\'est pas compatible avec le support.';
                }
            }
            foreach ($p->getConfigurations() as $c) {
                if ($c->getTypeImpression()?->getId() === $type->getId() && $c->getSupport()?->getId() === $support->getId() && $c->getFormat()?->getId() === $format?->getId()) {
                    $erreurs[] = \sprintf('Nouvelle configuration : « %s » existe déjà%s.', \App\Service\GrilleTarifaire::libelle($c), $c->isActive() ? '' : ' (désactivée : réactivez-la)');
                }
            }
        }
        if (false === $prixBase || false === $prixB2B || false === $minimum) {
            $erreurs[] = 'Nouvelle configuration : les prix et le minimum sont des nombres entiers.';
        } elseif (null === $prixBase) {
            $erreurs[] = 'Nouvelle configuration : donnez son prix.';
        } elseif (null !== $prixB2B && $prixB2B > $prixBase) {
            $erreurs[] = 'Nouvelle configuration : le prix revendeur dépasse le prix public.';
        }
        if (!\array_key_exists($mode, self::MODES_CONFIGURATION)) {
            $mode = self::modeConfiguration((string) $p->getModeCalcul());
        }
        if (\count($erreurs) > $avant) {
            return ['erreurs' => $erreurs, 'plan' => $plan, 'nouvelle' => null];
        }

        $config = (new ProduitConfiguration())
            ->setTypeImpression($type)->setSupport($support)->setFormat($format)
            ->setModeCalcul($mode)
            ->setModeDimension(\in_array($mode, ['metre', 'metre_carre'], true) ? 'mesure' : (null !== $format ? 'format' : 'aucune'))
            ->setPrixBase($prixBase)->setPrixB2B($prixB2B)
            ->setQuantiteMinimale(max(1, (int) $minimum))
            ->setActive(true);

        return ['erreurs' => $erreurs, 'plan' => $plan, 'nouvelle' => $config];
    }

    /**
     * @param array<int, array{config: ProduitConfiguration, valeurs: array<string, int|bool|null>}> $plan
     */
    private function appliquerConfigurations(Produits $p, array $plan, ?ProduitConfiguration $nouvelle, ?User $par): void
    {
        foreach ($plan as $l) {
            $c = $l['config'];
            $libelle = \App\Service\GrilleTarifaire::libelle($c);
            $ancien = ['prixBase' => $c->getPrixBase(), 'prixB2B' => $c->getPrixB2B(), 'quantiteMinimale' => $c->getQuantiteMinimale(), 'active' => $c->isActive()];
            foreach ($l['valeurs'] as $champ => $valeur) {
                if ($ancien[$champ] === $valeur) {
                    continue;
                }
                match ($champ) {
                    'prixBase' => $c->setPrixBase($valeur),
                    'prixB2B' => $c->setPrixB2B($valeur),
                    'quantiteMinimale' => $c->setQuantiteMinimale((int) $valeur),
                    'active' => $c->setActive((bool) $valeur),
                };
                // Même historique que Réglages › Grille tarifaire.
                $this->em->persist(new PrixHistorique('configuration', (int) $c->getId(), $libelle, $champ, self::texte($ancien[$champ]), self::texte($valeur), $par));
            }
        }
        if (null !== $nouvelle) {
            $ordre = 0;
            foreach ($p->getConfigurations() as $c) {
                $ordre = max($ordre, $c->getOrdre());
            }
            $p->addConfiguration($nouvelle->setOrdre($ordre + 10));
            $this->em->persist($nouvelle);
        }
    }

    /** Unité de vente du produit ramenée à un mode de calcul de configuration. */
    public static function modeConfiguration(string $modeProduit): string
    {
        return \array_key_exists($modeProduit, self::MODES_CONFIGURATION) ? $modeProduit : 'unite';
    }

    /**
     * Lignes de commande et de devis, prix négociés, demandes de devis,
     * Atelier 3D et supports de conception qui utilisent la configuration :
     * une configuration utilisée se désactive, elle ne se supprime pas.
     */
    public function utilisations(ProduitConfiguration $c): int
    {
        if (null === $c->getId()) {
            return 0;
        }
        $total = 0;
        foreach ([
            [\App\Entity\CommandesDetails::class, 'produitConfiguration'],
            [\App\Entity\DevisDetails::class, 'produitConfiguration'],
            [\App\Entity\PrixSpecial::class, 'configuration'],
            [\App\Entity\DemandeDevis::class, 'configuration'],
            [\App\Entity\DemandeDevisLigne::class, 'configuration'],
            [\App\Entity\CreationAtelier::class, 'configuration'],
            [\App\Entity\ModeleAtelier::class, 'configuration'],
        ] as [$classe, $champ]) {
            $total += (int) $this->em->createQueryBuilder()->select('COUNT(x.id)')->from($classe, 'x')
                ->where(\sprintf('x.%s = :c', $champ))->setParameter('c', $c)->getQuery()->getSingleScalarResult();
        }
        $total += (int) $this->em->createQueryBuilder()->select('COUNT(s.id)')->from(\App\Entity\SupportConception::class, 's')
            ->innerJoin('s.configurations', 'c')->where('c = :c')->setParameter('c', $c)->getQuery()->getSingleScalarResult();

        return $total;
    }

    /**
     * Supprime une configuration jamais utilisée (elle part à la corbeille
     * et la suppression part au journal de synchronisation).
     *
     * @return bool false si elle est utilisée : la désactiver à la place
     */
    public function supprimerConfiguration(ProduitConfiguration $c, ?User $par): bool
    {
        if ($this->utilisations($c) > 0) {
            return false;
        }
        $this->em->persist(new PrixHistorique('configuration', (int) $c->getId(), \App\Service\GrilleTarifaire::libelle($c), 'active', self::texte($c->isActive()), 'supprimée', $par));
        $c->getProduit()?->removeConfiguration($c);
        $this->em->remove($c);
        $this->em->flush();

        return true;
    }

    private static function texte(int|bool|null $valeur): ?string
    {
        return \is_bool($valeur) ? ($valeur ? 'oui' : 'non') : (null === $valeur ? null : (string) $valeur);
    }

    /**
     * Paliers postés pour chaque configuration du produit, normalisés et
     * contrôlés : un prix de palier sous le prix de la configuration, des
     * paliers de plus en plus avantageux, et les garde-fous de
     * GardeFousPrix (marge minimale) au pire cas : palier puis la plus
     * forte grille à remise (Partenaire ou Association). Un palier sous la
     * marge minimale, ou sous le coût de revient, n'est gardé qu'avec
     * l'accord de la direction (case cochée).
     *
     * @param array<mixed> $postes paliers[id de configuration][quantite|surface][]
     * @param array<int, int> $prixNouveaux prix de configuration saisis dans le même envoi
     *
     * @return array{erreurs: list<string>, plan: array<int, array{config: ProduitConfiguration, quantite: list<array>, surface: list<array>}>, alertes: list<string>}
     */
    private function lirePaliers(Produits $p, array $postes, bool $accord, bool $avecCouts, array $prixNouveaux = []): array
    {
        $erreurs = [];
        $plan = [];
        $alertes = [];
        $g = $this->gardeFous?->valeurs();
        $grille = $this->tarif?->remiseGrilleMax() ?? 0.0;
        foreach ($p->getConfigurations() as $c) {
            $brut = $postes[(int) $c->getId()] ?? null;
            if (!\is_array($brut)) {
                continue;
            }
            $libelle = \App\Service\GrilleTarifaire::libelle($c);
            $prixBase = $prixNouveaux[(int) $c->getId()] ?? (int) $c->getPrixBase();
            $lignesQ = \is_array($brut['quantite'] ?? null) ? $brut['quantite'] : [];
            $lignesS = 'metre_carre' === $c->getModeCalcul() && \is_array($brut['surface'] ?? null) ? $brut['surface'] : [];
            foreach ($lignesQ as $l) {
                if (\is_array($l) && self::rempli($l) && [] === PaliersPrix::normaliserQuantite([$l])) {
                    $erreurs[] = \sprintf('%s : chaque palier donne un nombre de pièces (2 ou plus) et un prix ou une remise.', $libelle);
                    break;
                }
            }
            foreach ($lignesS as $l) {
                if (\is_array($l) && self::rempli($l) && [] === PaliersPrix::normaliserSurface([$l])) {
                    $erreurs[] = \sprintf('%s : chaque remise de surface donne une surface en m² et une remise en %%.', $libelle);
                    break;
                }
            }
            $quantite = PaliersPrix::normaliserQuantite($lignesQ);
            $surface = PaliersPrix::normaliserSurface($lignesS);
            $avant = -1.0;
            foreach ($quantite as $q) {
                if (null !== $q['prix'] && $q['prix'] >= $prixBase) {
                    $erreurs[] = \sprintf('%s : le prix du palier dès %d pièces (%s F) doit être sous le prix de la configuration (%s F).', $libelle, $q['de'], number_format($q['prix'], 0, ',', ' '), number_format($prixBase, 0, ',', ' '));
                }
                $taux = PaliersPrix::tauxPalier($q, $prixBase);
                if ($taux <= $avant) {
                    $erreurs[] = \sprintf('%s : le palier dès %d pièces doit être plus avantageux que le précédent.', $libelle, $q['de']);
                }
                $avant = $taux;
            }
            $avant = -1.0;
            foreach ($surface as $r) {
                if ($r['remise'] <= $avant) {
                    $erreurs[] = \sprintf('%s : la remise dès %s m² doit être plus forte que la précédente.', $libelle, self::nombre($r['m2']));
                }
                $avant = $r['remise'];
            }
            $plan[(int) $c->getId()] = ['config' => $c, 'quantite' => $quantite, 'surface' => $surface];

            // Garde-fous au pire cas : le palier le plus fort, puis la plus forte grille à remise.
            // Seulement pour des paliers changés : une fiche réenregistrée ne redemande pas l'accord.
            // Un prix de configuration changé recompte les paliers à prix fixe.
            $inchanges = $quantite === $c->getPaliersQuantite() && $surface === $c->getRemisesSurface() && $prixBase === (int) $c->getPrixBase();
            if (null === $g || $prixBase <= 0 || $inchanges) {
                continue;
            }
            $detailCout = CoutProduit::detail($p, $c->getModeCalcul());
            $cout = $detailCout['total'];
            // Coût inconnu ou incomplet : la marge ne se vérifie pas, la remise maximale sans accord sert de garde-fou.
            $remiseLimite = null === $cout || !$detailCout['complet'] ? $g['remise'] : 100;
            $taux = [];
            foreach ($quantite as $q) {
                $taux[] = ['taux' => PaliersPrix::tauxPalier($q, $prixBase), 'quoi' => \sprintf('dès %d pièces', $q['de'])];
            }
            foreach ($surface as $r) {
                $taux[] = ['taux' => $r['remise'], 'quoi' => \sprintf('dès %s m²', self::nombre($r['m2']))];
            }
            foreach ($taux as $t) {
                $prix = PaliersPrix::net($prixBase, $t['taux'], $grille);
                // Coût connu : seule la marge compte (le palier est voulu plus bas que la grille).
                $raisons = GardeFousPrix::controler($prixBase, $prix, $cout, $remiseLimite, $g['minimum']);
                if ([] !== $raisons) {
                    $marge = CoutProduit::marge($prix, $cout);
                    $pourquoi = \in_array(GardeFousPrix::RAISON_MARGE, $raisons, true)
                        ? ($avecCouts ? \sprintf(' : marge %d %%, minimum %d %%', $marge, $g['minimum']) : ' : sous la marge minimale')
                        : \sprintf(' : remise de plus de %d %% sans coût de revient sûr', $g['remise']);
                    $alertes[] = \sprintf('%s %s (%s F%s)%s', $libelle, $t['quoi'], number_format($prix, 0, ',', ' '), $grille > 0 ? ' avec la grille à −'.self::nombre($grille).' %' : '', $pourquoi);
                }
            }
        }
        if ([] !== $alertes && !$accord) {
            $erreurs[] = 'Paliers hors garde-fous : '.implode(' ; ', $alertes).'. Cochez « Accord de la direction » pour les garder, ou changez-les.';
        }

        return ['erreurs' => array_values(array_unique($erreurs)), 'plan' => $plan, 'alertes' => $alertes];
    }

    /**
     * @param array<int, array{config: ProduitConfiguration, quantite: list<array>, surface: list<array>}> $plan
     * @param list<string> $alertes paliers gardés avec l'accord de la direction
     */
    private function appliquerPaliers(Produits $p, array $plan, array $alertes, ?User $par): void
    {
        foreach ($plan as $l) {
            $c = $l['config'];
            $avant = self::resumePaliers($c->getPaliersQuantite(), $c->getRemisesSurface());
            $c->setPaliersQuantite($l['quantite'])->setRemisesSurface($l['surface']);
            $apres = self::resumePaliers($c->getPaliersQuantite(), $c->getRemisesSurface());
            if ($avant !== $apres) {
                $this->em->persist(new PrixHistorique('configuration', (int) $c->getId(), \App\Service\GrilleTarifaire::libelle($c), 'paliers', $avant, $apres, $par));
            }
        }
        if ([] !== $alertes && null !== $p->getId()) {
            // L'accord donné reste dans l'historique des prix.
            $this->em->persist(new PrixHistorique('produit', (int) $p->getId(), (string) $p->getNom(), 'accordPaliers', null, mb_substr(\sprintf('accord : %d palier%s', \count($alertes), \count($alertes) > 1 ? 's' : ''), 0, 30), $par));
        }
    }

    /** Résumé court pour l'historique des prix (30 caractères au plus). */
    public static function resumePaliers(array $quantite, array $surface): ?string
    {
        if ([] === $quantite && [] === $surface) {
            return null;
        }
        $morceaux = [];
        foreach ($quantite as $q) {
            $morceaux[] = $q['de'].':'.(null !== $q['prix'] ? $q['prix'].'F' : self::nombre($q['remise']).'%');
        }
        foreach ($surface as $r) {
            $morceaux[] = self::nombre($r['m2']).'m²:'.self::nombre($r['remise']).'%';
        }

        return mb_substr(implode(' ', $morceaux), 0, 30);
    }

    private static function rempli(array $ligne): bool
    {
        foreach ($ligne as $v) {
            if (\is_scalar($v) && '' !== trim((string) $v)) {
                return true;
            }
        }

        return false;
    }

    private static function nombre(float|int|null $n): string
    {
        return rtrim(rtrim(number_format((float) $n, 2, ',', ''), '0'), ',');
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
        $p->setGestionStock(!$p->getArticlesStock()->isEmpty() || null !== $p->getArticleStock());
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
