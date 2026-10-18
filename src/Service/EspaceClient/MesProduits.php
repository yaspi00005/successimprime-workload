<?php

namespace App\Service\EspaceClient;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandeDetailFichier;
use App\Entity\ProduitClient;
use App\Service\Catalogue\NomCommercial;
use App\Service\CommandeProduitClient;
use App\Service\Synchro\Synchro;
use Doctrine\ORM\EntityManagerInterface;

/**
 * « Mes produits » côté client (maquettes MP01 à MP07, docs/mes-produits.md) :
 * le catalogue du client (produits ajoutés par lui ou par l'atelier, « Prêt
 * à imprimer » ou « Fichier en préparation »), la taille et la quantité
 * converties par CommandeProduitClient, et la commande du panier. Le prix
 * vient toujours de la fiche du produit, jamais du navigateur.
 */
final class MesProduits
{
    /** Produits différents dans une même commande. */
    public const SELECTION_MAX = 12;

    /** Quantité saisie au plus (pièces, m² ou exemplaires). */
    public const QUANTITE_MAX = 100000;

    /** Produits ajoutés au plus en une fois (MP01). */
    public const AJOUT_MAX = 20;

    /** Formats A (mm), pour « A6 » dans les dimensions. */
    private const FORMATS_A = ['A2' => [420, 594], 'A3' => [297, 420], 'A4' => [210, 297], 'A5' => [148, 210], 'A6' => [105, 148], 'A7' => [74, 105]];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CommandeProduitClient $conversion,
        private readonly NouvelleCommande $nouvelleCommande,
        private readonly EspaceClient $espace,
    ) {
    }

    /** @return list<ProduitClient> */
    public function liste(Clients $client): array
    {
        return $this->em->getRepository(ProduitClient::class)->findBy(['client' => $client, 'actif' => true], ['reference' => 'ASC']);
    }

    public function trouver(Clients $client, string $publicId): ?ProduitClient
    {
        foreach ($this->liste($client) as $p) {
            if ((string) $p->getPublicId() === $publicId) {
                return $p;
            }
        }

        return null;
    }

    /**
     * Le client ajoute ses produits (MP01), sans rien commander : une ligne
     * par produit (image, nom, type, dimensions). Ils arrivent au prépresse,
     * qui prépare le fichier d'impression une fois. Ne flushe qu'à la fin de
     * chaque produit (la référence suit la précédente).
     *
     * @param list<array{nom: string, type: string, dimensions: string, image: ?CommandeDetailFichier}> $lignes
     *
     * @return list<ProduitClient>
     *
     * @throws \DomainException
     */
    public function ajouterProduits(Clients $client, array $lignes, \DateTimeImmutable $maintenant): array
    {
        $lignes = array_values(array_filter($lignes, static fn (array $l): bool => '' !== trim($l['nom']) || null !== $l['image'] || '' !== trim($l['dimensions'])));
        if ([] === $lignes) {
            throw new \DomainException('Ajoutez au moins un produit : son nom et ses dimensions.');
        }
        if (\count($lignes) > self::AJOUT_MAX) {
            throw new \DomainException(\sprintf('%d produits au plus en une fois.', self::AJOUT_MAX));
        }
        $prets = [];
        foreach ($lignes as $i => $l) {
            $nom = trim(preg_replace('/\s+/', ' ', $l['nom']) ?? '');
            if ('' === $nom || mb_strlen($nom) > 120) {
                throw new \DomainException(\sprintf('Ligne %d : donnez un nom au produit (120 caractères au plus).', $i + 1));
            }
            $taille = self::lireDimensions($l['dimensions']);
            if (null === $taille) {
                throw new \DomainException(\sprintf('« %s » : indiquez les dimensions, par exemple 50 × 30 mm, Ø 60 mm ou A6.', $nom));
            }
            if (null !== $l['image'] && ($l['image']->getClient()?->getId() !== $client->getId() || !$l['image']->estUploadTermine())) {
                throw new \DomainException(\sprintf('« %s » : l\'image n\'est pas encore arrivée. Attendez la fin de l\'envoi.', $nom));
            }
            $prets[] = [$nom, isset(ProduitClient::TYPES[$l['type']]) ? $l['type'] : 'Autre', $taille, $l['image']];
        }
        $depot = $this->em->getRepository(ProduitClient::class);
        $crees = [];
        foreach ($prets as [$nom, $type, [$lMm, $hMm, $rond], $image]) {
            $produit = (new ProduitClient())
                ->setClient($client)
                ->setReference($depot->prochaineReference($client))
                ->setNom($nom)
                ->setType($type)
                ->setOrigine(ProduitClient::ORIGINE_CLIENT)
                ->setFormat($lMm / 1000, $hMm / 1000)
                ->setRond($rond)
                ->setUniteVente(ProduitClient::UNITE_PIECE)
                ->setImage($image);
            $this->em->persist($produit);
            $this->em->flush();
            $crees[] = $produit;
        }

        return $crees;
    }

    /**
     * Le client remplace l'image d'un de ses produits (règle « Nouvelle
     * image ») : le lien est suspendu, le produit repasse au prépresse.
     *
     * @throws \DomainException
     */
    public function remplacerImage(ProduitClient $produit, CommandeDetailFichier $image, \DateTimeImmutable $maintenant): void
    {
        if ($image->getClient()?->getId() !== $produit->getClient()?->getId() || !$image->estUploadTermine()) {
            throw new \DomainException('L\'image n\'est pas encore arrivée. Attendez la fin de l\'envoi.');
        }
        $produit->remplacerImage($image, $maintenant);
        $this->em->flush();
    }

    /**
     * « 50 × 30 mm », « 50x30 », « Ø 60 mm », « A6 », « A6 · 105 × 148 mm »,
     * « 8,5 × 5,5 cm », « 3 × 1 m » : largeur et hauteur en mm, et rond.
     *
     * @return array{0: float, 1: float, 2: bool}|null
     */
    public static function lireDimensions(string $texte): ?array
    {
        $t = mb_strtolower(trim(str_replace(',', '.', $texte)));
        if ('' === $t) {
            return null;
        }
        $facteur = static fn (?string $unite): float => match ($unite) {
            'cm' => 10.0,
            'm' => 1000.0,
            default => 1.0,
        };
        if (preg_match('/(\d+(?:\.\d+)?)\s*(mm|cm|m)?\s*[x×*]\s*(\d+(?:\.\d+)?)\s*(mm|cm|m)?/u', $t, $m)) {
            $unite = ($m[4] ?? '') !== '' ? $m[4] : (('' !== ($m[2] ?? '')) ? $m[2] : null);
            $l = (float) $m[1] * $facteur($unite);
            $h = (float) $m[3] * $facteur($unite);
            $rond = false;
        } elseif (preg_match('/(?:ø|⌀|diam(?:[eè]tre)?\.?)\s*(\d+(?:\.\d+)?)\s*(mm|cm|m)?/u', $t, $m)) {
            $l = $h = (float) $m[1] * $facteur($m[2] ?? null);
            $rond = true;
        } elseif (preg_match('/\b(a[2-7])\b/u', $t, $m)) {
            [$l, $h] = self::FORMATS_A[strtoupper($m[1])];
            $rond = false;
        } else {
            return null;
        }
        if ($l < 5 || $h < 5 || $l > 10000 || $h > 100000) {
            return null;
        }

        return [round($l, 1), round($h, 1), $rond];
    }

    /** Façons de saisir la quantité, la première par défaut. */
    public static function saisies(ProduitClient $p): array
    {
        return match (true) {
            ProduitClient::UNITE_LOT === $p->getUniteVente() => [CommandeProduitClient::SAISIE_LOTS],
            $p->estLongueurLibre() => [CommandeProduitClient::SAISIE_M2],
            $p->estFormatFixe() && ProduitClient::UNITE_PIECE_OU_M2 === $p->getUniteVente() => [CommandeProduitClient::SAISIE_PIECES, CommandeProduitClient::SAISIE_M2],
            $p->estFormatFixe() && ProduitClient::UNITE_M2 === $p->getUniteVente() => [CommandeProduitClient::SAISIE_M2],
            default => [CommandeProduitClient::SAISIE_PIECES],
        };
    }

    /**
     * Ce que montre la carte du produit. Sans $montants (demandeur,
     * CompteClient::montantsVisibles), pas de prix : « prix » reste null.
     *
     * @return array<string, mixed>
     */
    public function fiche(ProduitClient $p, bool $montants = true): array
    {
        $saisies = self::saisies($p);
        $lot = (int) ($p->getTailleLot() ?? 1);
        $image = null;
        if (null !== $p->getImage() && str_starts_with((string) $p->getImage()->getTypeMime(), 'image/')) {
            $image = $p->getImage();
        } elseif (null !== $p->getFichier() && str_starts_with((string) $p->getFichier()->getTypeMime(), 'image/')) {
            $image = $p->getFichier();
        } elseif (null !== $p->getBatValide()) {
            $image = $this->espace->imagesBat($p->getBatValide())[0] ?? null;
        }

        return [
            'p' => $p,
            'id' => (string) $p->getPublicId(),
            'saisies' => $saisies,
            'badge' => match (true) {
                ProduitClient::UNITE_LOT === $p->getUniteVente() => 'par lot',
                \count($saisies) > 1 => 'm² ou pièces',
                [CommandeProduitClient::SAISIE_M2] === $saisies => 'au m²',
                default => 'à l\'unité',
            },
            'prix' => !$montants ? null : match ($p->getUniteVente()) {
                ProduitClient::UNITE_LOT => \sprintf('%s F / %s', self::f($p->getPrixUnitaire()), self::f($lot)),
                ProduitClient::UNITE_M2 => \sprintf('%s F / m²', self::f($p->getPrixUnitaire())),
                default => \sprintf('%s F / pièce', self::f($p->getPrixUnitaire())),
            },
            'format' => self::format($p),
            'batLe' => $p->getBatValide()?->getValideLe(),
            'categorie' => $p->getProduit()?->getCategorieProduit()?->getNom(),
            'image' => $image instanceof CommandeDetailFichier && null !== $this->espace->cheminSurDisque($image) ? $image->getId() : null,
            // Image envoyée par le client (« Mes fichiers ») ou image d'un BAT de commande : pas la même adresse.
            'imageRoute' => $image instanceof CommandeDetailFichier && $image === $p->getImage() ? 'app_espace_fichier' : 'app_espace_bat_fichier',
            'pas' => match ($saisies[0]) {
                CommandeProduitClient::SAISIE_LOTS => $lot,
                default => 1,
            },
            'raccourcis' => match ($saisies[0]) {
                CommandeProduitClient::SAISIE_LOTS => [$lot, 2 * $lot, 4 * $lot, 10 * $lot],
                CommandeProduitClient::SAISIE_M2 => [2, 4, 6, 10],
                default => [1, 2, 5, 10],
            },
            'raccourcisM2' => [6, 12, 20, 30],
            'minimum' => $p->getQuantiteMinimum(),
            // MP03/MP04 : état du fichier, tailles préparées, prix par quantité.
            'pret' => $p->estPret(),
            // Prêt mais sans prix fixé par l'atelier : pas encore commandable.
            'commandable' => self::estCommandable($p),
            'famille' => $p->getFamille(),
            'taille' => self::tailleProduit($p),
            'tailles' => self::taillesProposees($p),
            'paliers' => $montants ? $p->getPaliers() : array_map(static fn (array $q): array => ['q' => $q['q'], 'prix' => null], $p->getPaliers()),
            'lienLe' => self::dateLien($p),
            'version' => \count($p->getFichiersLies()) > 0 ? 'v'.\count($p->getFichiersLies()) : null,
            'recuLe' => $p->getImageRemplaceeLe() ?? $p->getCreeLe(),
            'suspendu' => ProduitClient::LIEN_SUSPENDU === $p->getEtatLien(),
            'choixTaille' => self::choisitLaTaille($p),
        ];
    }

    /**
     * Commandable : avec un prix (unitaire ou par quantité), et prêt à
     * imprimer. Un produit enregistré par l'atelier sans fichier lié ni BAT
     * reste commandable comme avant (chaque commande passe au prépresse, avec
     * un BAT) ; un produit ajouté par le client attend son fichier.
     */
    public static function estCommandable(ProduitClient $p): bool
    {
        if (!$p->isActif() || ($p->getPrixUnitaire() <= 0 && [] === $p->getPaliers()) || ProduitClient::LIEN_SUSPENDU === $p->getEtatLien()) {
            return false;
        }

        return $p->estPret() || ProduitClient::ORIGINE_ATELIER === $p->getOrigine();
    }

    /** La taille se choisit (MP04) pour un produit à la pièce de format fixe. */
    public static function choisitLaTaille(ProduitClient $p): bool
    {
        return $p->estFormatFixe() && ProduitClient::UNITE_LOT !== $p->getUniteVente() && \in_array(CommandeProduitClient::SAISIE_PIECES, self::saisies($p), true);
    }

    /** « 50 × 30 mm », « Ø 60 mm », ou null sans format. */
    public static function tailleProduit(ProduitClient $p): ?string
    {
        return $p->estFormatFixe() ? ProduitClient::libelleTaille((float) $p->getLargeurMm(), (float) $p->getHauteurMm(), $p->isRond()) : null;
    }

    /**
     * Tailles proposées à la commande (MP04) : les tailles validées
     * (« taille préparée · direct »), sinon celle du produit (le prépresse
     * l'adaptera). « Autre taille » s'ajoute dans la page.
     *
     * @return list<array{l: float, h: float, libelle: string, direct: bool}>
     */
    public static function taillesProposees(ProduitClient $p): array
    {
        $tailles = array_map(static fn (array $t): array => $t + ['libelle' => ProduitClient::libelleTaille($t['l'], $t['h'], $p->isRond()), 'direct' => true], $p->taillesValidees());
        if ($p->estFormatFixe() && !$p->estTailleValidee((float) $p->getLargeurMm(), (float) $p->getHauteurMm())) {
            array_unshift($tailles, ['l' => (float) $p->getLargeurMm(), 'h' => (float) $p->getHauteurMm(), 'libelle' => (string) self::tailleProduit($p), 'direct' => false]);
        }

        return $tailles;
    }

    private static function dateLien(ProduitClient $p): ?\DateTimeImmutable
    {
        $dates = array_filter(array_map(static fn (array $f): ?\DateTimeImmutable => \DateTimeImmutable::createFromFormat('Y-m-d H:i', (string) ($f['le'] ?? '')) ?: null, $p->getFichiersLies()));

        return [] === $dates ? $p->getBatValide()?->getValideLe() : max($dates);
    }

    /**
     * Ce que l'atelier imprimera et le prix, pour la quantité saisie.
     *
     * @return array{quantite: float, saisie: string, inferieur: bool, pieces: int, surface: float, longueur: ?float, montant: int, production: string, resume: string, detail: string, alternative: ?array{pieces: int, surface: float, montant: int}}
     *
     * @throws \DomainException message à montrer au client
     */
    public function calculer(ProduitClient $p, float $quantite, string $saisie, bool $inferieur = false, ?array $taille = null): array
    {
        if (!\in_array($saisie, self::saisies($p), true)) {
            $saisie = self::saisies($p)[0];
        }
        if ($quantite <= 0 || $quantite > self::QUANTITE_MAX) {
            throw new \DomainException('Indiquez une quantité.');
        }
        try {
            $taille = null !== $taille && CommandeProduitClient::SAISIE_PIECES === $saisie && self::choisitLaTaille($p) ? $taille : null;
            $r = $this->conversion->proposer($p, $quantite, $saisie, $inferieur, $taille);
        } catch (\InvalidArgumentException $e) {
            throw new \DomainException($e->getMessage());
        }
        $inferieur = $inferieur && null !== $r['alternative'] && $r['alternative']['pieces'] > $r['pieces'];
        [$resume, $detail] = match (true) {
            CommandeProduitClient::SAISIE_LOTS === $saisie => [\sprintf('%s ex.', self::f($r['pieces'])), $r['production']],
            null !== $r['longueur'] => [\sprintf('%s m²', self::n($quantite)), \sprintf('= %s m linéaires', self::n($r['longueur']))],
            $p->estFormatFixe() => [self::pieces($r['pieces']), \sprintf('= %s m²', self::n($r['surface']))],
            default => [self::pieces($r['pieces']), ''],
        };

        [$lMm, $hMm] = null !== $taille ? CommandeProduitClient::taille($taille) : [$p->getLargeurMm(), $p->getHauteurMm()];
        $direct = $p->estPret() && (null === $lMm || null === $hMm || !$p->estFormatFixe() || $p->estTailleValidee($lMm, $hMm));

        return $r + [
            'quantite' => $quantite, 'saisie' => $saisie, 'inferieur' => $inferieur, 'resume' => $resume, 'detail' => $detail,
            'taille' => null !== $lMm && null !== $hMm && $p->estFormatFixe() ? ['l' => $lMm, 'h' => $hMm, 'libelle' => ProduitClient::libelleTaille($lMm, $hMm, $p->isRond())] : null,
            // Impression directe (fichier lié à cette taille) ou nouvelle taille : prépresse puis BAT (MP05).
            'direct' => $direct,
        ];
    }

    /**
     * Ce que la page reçoit du calcul (feuille de quantité) : la conversion
     * toujours, le prix seulement pour qui voit les montants. Un demandeur
     * reçoit les pièces, les m² et ce que l'atelier imprimera, sans montant
     * (ni pour le choix au-dessous).
     *
     * @param array<string, mixed> $calcul MesProduits::calculer
     *
     * @return array<string, mixed>
     */
    public static function calculPourLaPage(array $calcul, bool $montants): array
    {
        $page = array_intersect_key($calcul, array_flip(['quantite', 'saisie', 'inferieur', 'pieces', 'surface', 'longueur', 'montant', 'production', 'resume', 'detail', 'alternative', 'taille', 'direct']));
        if (!$montants) {
            unset($page['montant']);
            if (\is_array($page['alternative'] ?? null)) {
                unset($page['alternative']['montant']);
            }
        }

        return ['montants' => $montants] + $page;
    }

    /**
     * Commande le panier (MP05) : une ligne par article, « à confirmer » tant
     * que l'acompte n'est pas réglé ; le local la confirme et l'aiguille dès
     * qu'il l'est, ou tout de suite pour un client dispensé d'acompte
     * (Aiguillage::lancerSiPrete).
     *
     * @param list<array{produit: ProduitClient, quantite: float, saisie: string, inferieur: bool, taille?: ?array}> $selection
     *
     * @throws \DomainException
     */
    public function commander(Clients $client, array $selection, \DateTimeImmutable $maintenant, ?OptionsCommande $options = null): Commandes
    {
        if ([] === $selection) {
            throw new \DomainException('Choisissez au moins un produit.');
        }
        if (\count($selection) > self::SELECTION_MAX) {
            throw new \DomainException(\sprintf('%d produits au plus par commande.', self::SELECTION_MAX));
        }
        $this->nouvelleCommande->verifierEnAttente($client);

        $commande = (new Commandes())
            ->setClients($client)
            ->setDateCommande($maintenant)
            ->setCanal(Commandes::CANAL_EN_LIGNE)
            ->setStatut(false) // à confirmer par l'atelier
            ->setEtat(true);
        $vus = [];
        foreach ($selection as $choix) {
            $p = $choix['produit'];
            $taille = $choix['taille'] ?? null;
            $cle = $p->getId().'|'.json_encode($taille);
            if ($p->getClient()?->getId() !== $client->getId() || isset($vus[$cle]) || !$p->isActif()) {
                throw new \DomainException('Sélection invalide. Rechargez la page.');
            }
            if (!self::estCommandable($p)) {
                throw new \DomainException(\sprintf('« %s » n\'est pas encore prêt : l\'atelier prépare son fichier.', $p->getNom()));
            }
            $vus[$cle] = true;
            $calcul = $this->calculer($p, $choix['quantite'], $choix['saisie'], $choix['inferieur'], $taille);
            $ligne = $this->conversion->creerLigne($commande, $p, $calcul['quantite'], $calcul['saisie'], $calcul['inferieur'], null !== $calcul['taille'] && null !== $taille ? $calcul['taille'] : null);
            // Fichier lié à cette taille : impression directe ; sinon prépresse et BAT.
            Synchro::verifierLigneEnLigne($ligne);
        }
        if (null !== $options) {
            $commande->setDelaiProduction($options->delai)
                ->setModeLivraison($options->livraison ? Commandes::LIVRAISON_DOMICILE : Commandes::LIVRAISON_RETRAIT)
                ->setFraisLivraison($options->livraison ? $options->fraisLivraison : 0)
                ->setAdresseLivraison($options->livraison ? $options->adresse : null)
                ->setCreneauLivraison($options->livraison ? $options->creneau : null)
                ->setDateLivraison($options->dateLivraison);
            $commande->recalculerTotaux();
        }

        $this->em->wrapInTransaction(function () use ($commande, $maintenant): void {
            $this->em->persist($commande);
            $this->em->flush();
            // Même numéro qu'au comptoir ; le site y ajoute W-.
            $commande->setNumero(\sprintf('CMD-%06d-%s', $commande->getId(), $maintenant->format('m-Y')));
            $this->em->flush();
        });

        return $commande;
    }

    /** « 400 × 150 cm », « Laize 1,37 m · longueur libre », support. */
    public static function format(ProduitClient $p): string
    {
        $morceaux = [];
        if ($p->estFormatFixe()) {
            $morceaux[] = \sprintf('%s × %s cm', self::n($p->getLargeur() * 100), self::n($p->getHauteur() * 100));
        } elseif ($p->estLongueurLibre()) {
            $morceaux[] = \sprintf('Laize %s m · longueur libre', self::n((float) $p->getLargeur()));
        }
        if (null !== $p->getSupport()?->getNom()) {
            // Nom commercial d'abord, désignation entre parenthèses (docs/noms-commerciaux.md).
            $morceaux[] = NomCommercial::texteClient($p->getSupport()->getNomCommercial(), $p->getSupport()->getNom());
        }
        if (ProduitClient::UNITE_LOT === $p->getUniteVente() && null !== $p->getTailleLot()) {
            $morceaux[] = 'par '.self::f($p->getTailleLot());
        }

        return implode(' · ', $morceaux);
    }

    private static function pieces(int $n): string
    {
        return \sprintf('%s pièce%s', self::f($n), $n > 1 ? 's' : '');
    }

    private static function f(int|float $n): string
    {
        return number_format($n, 0, ',', ' ');
    }

    private static function n(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, ',', ' '), '0'), ',');
    }
}
