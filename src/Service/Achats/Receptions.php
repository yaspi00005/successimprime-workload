<?php

namespace App\Service\Achats;

use App\Entity\AchatDetail;
use App\Entity\Achats;
use App\Entity\ArticleFournisseur;
use App\Entity\Articles;
use App\Entity\Depense;
use App\Entity\Fournisseurs;
use App\Entity\ReclamationFournisseur;
use App\Entity\StockEntrees;
use App\Entity\User;
use App\Security\FichierEnvoye;
use App\Service\FichiersPrives;
use App\Service\StockService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Réception d'une commande d'achat (maquette SU05, docs/achats.md) :
 * commandé, reçu, bon état et facturé ligne par ligne ; frais réels qui
 * remplacent les frais prévus et coût de revient recalculé ; entrée en
 * stock des seules unités en bon état ; réclamation au fournisseur avec
 * photo ; contrôle à trois points et paiement du solde bloqué tant
 * qu'une réclamation est ouverte.
 *
 * Une commande ne se réceptionne qu'une fois : ce qui manque est noté
 * sur la ligne (« manque 1 ») et la commande est close. Les calculs sont
 * dans CalculsReception (testés sans base).
 */
final class Receptions
{
    /** Photos de la casse : type lu dans le contenu => extension. */
    public const TYPES_PHOTO = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public const TAILLE_MAX_PHOTO = 10 * 1024 * 1024;

    /** Dossier privé des photos (var/uploads/reclamations), préfixe des noms. */
    public const DOSSIER_PHOTOS = 'reclamations';
    private const PREFIXE_PHOTO = 'fournisseur-';

    /** Réceptions récentes montrées dans la liste. */
    public const JOURS_RECENTES = 60;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StockService $stock,
        private readonly FichiersPrives $fichiers,
    ) {
    }

    /**
     * Liste des réceptions : les commandes à recevoir (la plus attendue
     * d'abord), puis les réceptions récentes.
     *
     * @return array{aRecevoir: list<Achats>, recentes: list<Achats>}
     */
    public function liste(\DateTimeImmutable $aujourdhui): array
    {
        $aRecevoir = $this->em->createQueryBuilder()
            ->select('a', 'f')
            ->from(Achats::class, 'a')
            ->join('a.fournisseur', 'f')
            ->where('a.statut IN (:statuts)')
            ->setParameter('statuts', Achats::STATUTS_EN_COURS)
            ->orderBy('a.datePromise', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        // Le statut « en route » d'abord : c'est ce qui arrive.
        usort($aRecevoir, static fn (Achats $x, Achats $y): int => [$x->getStatut() !== Achats::STATUT_EN_ROUTE, $x->getDatePromise()?->getTimestamp() ?? PHP_INT_MAX, $x->getId()] <=> [$y->getStatut() !== Achats::STATUT_EN_ROUTE, $y->getDatePromise()?->getTimestamp() ?? PHP_INT_MAX, $y->getId()]);

        $recentes = $this->em->createQueryBuilder()
            ->select('a', 'f', 'r')
            ->from(Achats::class, 'a')
            ->join('a.fournisseur', 'f')
            ->leftJoin('a.reclamations', 'r')
            ->where('a.statut = :recu')
            ->andWhere('a.dateReception >= :depuis')
            ->setParameter('recu', Achats::STATUT_RECU)
            ->setParameter('depuis', $aujourdhui->setTime(0, 0)->modify(sprintf('-%d days', self::JOURS_RECENTES)))
            ->orderBy('a.dateReception', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->getQuery()
            ->getResult();

        return ['aRecevoir' => $aRecevoir, 'recentes' => $recentes];
    }

    /**
     * Ce que montre l'écran : chaque ligne (commandé, reçu, bon état,
     * facturé, écart, coût prévu et réel, entrée en stock), les frais
     * prévus et réels, et les réclamations à créer. Avant la validation,
     * tout est reçu en bon état et facturé comme commandé ; après, ce qui
     * a été enregistré.
     *
     * @return array<string, mixed>
     */
    public function detail(Achats $achat): array
    {
        $recue = $achat->estRecu();
        $transportPrevu = $achat->getFraisTransportPrevu() ?? $achat->getFraisTransport();
        $transitPrevu = $achat->getFraisTransitPrevu() ?? $achat->getFraisTransit();
        $prevus = [];

        foreach (SuiviAchats::detailCommande($achat)['lignes'] as $l) {
            $prevus[spl_object_id($l['ligne'])] = $l['coutRevient'];
        }

        $lignes = [];
        $pourCouts = [];

        foreach ($achat->getLignes() as $ligne) {
            $article = $ligne->getArticle();

            if (!$article instanceof Articles) {
                continue;
            }

            $commande = $ligne->getQuantite();
            $recues = $ligne->getQuantiteRecue() ?? $commande;
            $bon = $ligne->getQuantiteBonEtat();
            $facturee = $ligne->getQuantiteFacturee() ?? $commande;
            $facteur = $article->getFacteurConversion();
            $entree = $ligne->getQuantiteEntree() ?? $article->convertirAchatEnStock((float) $bon);
            $avant = $ligne->getStockAvant() ?? ($ligne->estEntreeEnStock() ? null : $this->stock->getStockPhysique($article));
            $reclamer = CalculsReception::quantiteAReclamer($facturee, $bon);

            $lignes[] = [
                'ligne' => $ligne,
                'article' => $article,
                'nom' => SuiviAchats::nomCourt($article),
                'uniteAchat' => $article->getUniteAchat() ?: $article->getUniteCourte(),
                'unite' => $article->getUniteCourte(),
                'facteur' => $facteur,
                'prix' => $ligne->getPrixUnitaire(),
                'commande' => $commande,
                'recue' => $recues,
                'bonEtat' => $bon,
                'facturee' => $facturee,
                'motif' => $ligne->getCasseMotif(),
                'ecart' => CalculsReception::ecart($commande, $recues, $bon, $article->getUniteAchat() ?: $article->getUniteCourte(), $ligne->getCasseMotif()),
                'coutPrevu' => $ligne->getCoutRevientPrevu() ?? ($recue ? null : ($ligne->getCoutRevientUnitaire() ?? $prevus[spl_object_id($ligne)] ?? null)),
                'entree' => $entree,
                'stockAvant' => $avant,
                'stockApres' => $avant !== null ? $avant + $entree : null,
                'reclamer' => $reclamer,
                'montantReclame' => $reclamer * $ligne->getPrixUnitaire(),
            ];
            $pourCouts[] = [
                'recue' => $article->convertirAchatEnStock((float) $recues),
                'bon' => $article->convertirAchatEnStock((float) $bon),
                'unite' => $article->getUniteCourte(),
                'montantRecu' => $recues * $ligne->getPrixUnitaire(),
                'montantBon' => $bon * $ligne->getPrixUnitaire(),
            ];
        }

        $couts = CalculsReception::coutsReels($pourCouts, $achat->getFraisTransport(), $achat->getFraisTransit());

        foreach ($lignes as $i => $l) {
            // Après la validation, le coût enregistré ; avant, celui des frais prévus (le navigateur le recalcule).
            $lignes[$i]['coutReel'] = $recue ? $l['ligne']->getCoutRevientUnitaire() : $couts[$i]['cout'];
            $lignes[$i]['coutPrevu'] ??= $recue ? $l['ligne']->getCoutRevientUnitaire() : null;
        }

        return [
            'lignes' => $lignes,
            'transportPrevu' => $transportPrevu,
            'transitPrevu' => $transitPrevu,
            'transportReel' => $achat->getFraisTransport(),
            'transitReel' => $achat->getFraisTransit(),
            'unite' => CalculsAchat::memeUnite($pourCouts) && $lignes !== [] ? $lignes[0]['unite'] : null,
        ];
    }

    /**
     * Valide la réception : quantités, frais réels, coût de revient,
     * entrée en stock, réclamations, facture à payer et blocage du solde.
     * Une seule fois par commande (verrou sur la ligne de la commande).
     *
     * @param array<string, mixed> $donnees le formulaire
     *
     * @throws \DomainException quand la réception est refusée
     */
    public function valider(Achats $achat, array $donnees, ?UploadedFile $photo, ?User $par, bool $voitFinances, \DateTimeImmutable $maintenant): void
    {
        $nomPhoto = $photo !== null ? $this->rangerPhoto($photo) : null;

        try {
            $this->em->wrapInTransaction(function () use ($achat, $donnees, $nomPhoto, $par, $voitFinances, $maintenant): void {
                // Deux validations en même temps : la seconde attend et trouve la commande reçue.
                $this->em->refresh($achat, LockMode::PESSIMISTIC_WRITE);

                if (!$achat->estEnCours()) {
                    throw new \DomainException('Cette commande est déjà réceptionnée (ou n\'est pas encore partie) : rien n\'entre en stock une seconde fois.');
                }

                $this->lireReception($achat, $donnees, $voitFinances, $maintenant);
                $this->appliquerCouts($achat);
                $this->entrerEnStock($achat, $maintenant);
                $this->creerReclamations($achat, $nomPhoto, $par, $maintenant);
                $this->actualiserBlocage($achat);

                $achat->setStatut(Achats::STATUT_RECU);
                $achat->setReceptionnePar($par);

                if ($voitFinances) {
                    $this->synchroniserDette($achat, $par, $maintenant);
                }

                $this->em->flush();
            });
        } catch (\Throwable $e) {
            if ($nomPhoto !== null) {
                @unlink($this->fichiers->dossier(self::DOSSIER_PHOTOS).'/'.$nomPhoto);
            }

            throw $e;
        }
    }

    /**
     * Après la réception : bon de livraison et facture du fournisseur
     * arrivés plus tard. La facture à payer et le blocage suivent.
     *
     * @param array<string, mixed> $donnees
     */
    public function enregistrerControle(Achats $achat, array $donnees, ?User $par, bool $voitFinances, \DateTimeImmutable $maintenant): void
    {
        $this->lireControle($achat, $donnees, $voitFinances);

        foreach ($achat->getLignes() as $ligne) {
            if (\array_key_exists((string) $ligne->getId(), (array) ($donnees['facturee'] ?? []))) {
                $ligne->setQuantiteFacturee(self::entier($donnees['facturee'][(string) $ligne->getId()]));
            }
        }

        if ($voitFinances) {
            $this->synchroniserDette($achat, $par, $maintenant);
        }

        $this->em->flush();
    }

    /**
     * La réclamation est réglée : remplacée (la marchandise de
     * remplacement entre en stock au prix d'achat, le transport étant
     * déjà porté par les unités en bon état), déduite (retirée de la
     * facture à payer) ou close. Le blocage du solde est revu.
     */
    public function cloturerReclamation(ReclamationFournisseur $reclamation, string $statut, ?User $par, \DateTimeImmutable $maintenant): void
    {
        if (!$reclamation->estOuverte()) {
            throw new \DomainException('Cette réclamation est déjà réglée.');
        }

        $achat = $reclamation->getAchat() ?? throw new \DomainException('Réclamation sans commande.');
        $reclamation->cloturer($statut, $par, $maintenant);

        if ($statut === ReclamationFournisseur::REMPLACEE && ($article = $reclamation->getArticle()) !== null) {
            $quantite = $article->convertirAchatEnStock((float) $reclamation->getQuantite());
            $prix = (int) round(($reclamation->getLigne()?->getPrixUnitaire() ?? 0) / $article->getFacteurConversion());
            $this->stock->appliquerCoutMoyen($article, $quantite, $prix);
            $this->em->persist((new StockEntrees())->setArticle($article)->setQuantites($quantite)->setPrix($prix)->setDate($maintenant));
        }

        $this->actualiserBlocage($achat);
        $this->synchroniserDette($achat, $par, $maintenant);
        $this->em->flush();
    }

    /**
     * Déblocage forcé par la finance, avec une note.
     */
    public function forcerPaiement(Achats $achat, string $note, ?User $par, \DateTimeImmutable $maintenant): void
    {
        if (trim($note) === '') {
            throw new \DomainException('Écrivez pourquoi le solde peut être payé malgré la réclamation.');
        }

        $achat->forcerPaiement($note, $par, $maintenant);
        $this->em->flush();
    }

    /**
     * Le message part par WhatsApp : on note l'heure du premier envoi et
     * on rend le lien wa.me.
     */
    public function envoyerReclamation(ReclamationFournisseur $reclamation, \DateTimeImmutable $maintenant): ?string
    {
        $lien = CalculsReception::lienWhatsApp($reclamation->getAchat()?->getFournisseur()?->getNumeroWhatsapp(), (string) $reclamation->getMessage());

        if ($lien !== null && $reclamation->getMessageEnvoyeLe() === null) {
            $reclamation->setMessageEnvoyeLe($maintenant);
            $this->em->flush();
        }

        return $lien;
    }

    /**
     * Photo ajoutée après coup à une réclamation (remplace l'ancienne).
     */
    public function ajouterPhoto(ReclamationFournisseur $reclamation, UploadedFile $photo): void
    {
        $ancienne = $reclamation->getPhoto();
        $reclamation->setPhoto($this->rangerPhoto($photo));
        $this->em->flush();

        // L'ancienne photo part si plus aucune réclamation ne s'en sert.
        if ($ancienne !== null && $this->em->getRepository(ReclamationFournisseur::class)->count(['photo' => $ancienne]) === 0) {
            @unlink($this->fichiers->dossier(self::DOSSIER_PHOTOS).'/'.$ancienne);
        }
    }

    public function cheminPhoto(ReclamationFournisseur $reclamation): ?string
    {
        $nom = (string) $reclamation->getPhoto();

        return str_starts_with($nom, self::PREFIXE_PHOTO) ? $this->fichiers->cheminDans(self::DOSSIER_PHOTOS, $nom) : null;
    }

    /**
     * La facture à payer reliée à la commande (Finances › Dépenses), ou null.
     */
    public function dette(Achats $achat): ?Depense
    {
        return $this->em->getRepository(Depense::class)->findOneBy(['achat' => $achat], ['id' => 'DESC']);
    }

    /**
     * Lignes pour la raison du blocage : celles dont une réclamation est
     * ouverte.
     *
     * @return list<array{nom: string, uniteAchat: ?string, facturee: int, recue: int, bonEtat: int, ouverte: bool}>
     */
    public static function lignesBlocage(Achats $achat): array
    {
        $ouvertes = [];
        foreach ($achat->getReclamationsOuvertes() as $r) {
            $ouvertes[spl_object_id($r->getLigne() ?? $r)] = true;
        }

        $lignes = [];
        foreach ($achat->getLignes() as $ligne) {
            $article = $ligne->getArticle();
            $lignes[] = [
                'nom' => $article !== null ? SuiviAchats::nomCourt($article) : 'article',
                'uniteAchat' => $article?->getUniteAchat() ?: $article?->getUniteCourte(),
                'facturee' => $ligne->getQuantiteFacturee() ?? $ligne->getQuantite(),
                'recue' => $ligne->getQuantiteRecue() ?? $ligne->getQuantite(),
                'bonEtat' => $ligne->getQuantiteBonEtat(),
                'ouverte' => isset($ouvertes[spl_object_id($ligne)]),
            ];
        }

        return $lignes;
    }

    /**
     * Revoit le blocage du solde : bloqué tant qu'une réclamation est
     * ouverte, sauf déblocage forcé.
     */
    public function actualiserBlocage(Achats $achat): void
    {
        $statuts = array_map(static fn (ReclamationFournisseur $r): string => $r->getStatut(), $achat->getReclamations()->toArray());

        if (!CalculsReception::paiementBloque(array_values($statuts), $achat->estPaiementForce())) {
            $achat->bloquerPaiement(null);

            return;
        }

        $achat->bloquerPaiement(CalculsReception::motifBlocage(self::lignesBlocage($achat)) ?? 'Réclamation ouverte chez le fournisseur : paiement du solde bloqué jusqu\'à son avoir.');
    }

    /**
     * Le dernier prix payé devient le prix de ce fournisseur sur la fiche article.
     */
    public static function retenirPrixFournisseur(Articles $article, ?Fournisseurs $fournisseur, int $prix): void
    {
        if ($fournisseur === null) {
            return;
        }

        foreach ($article->getFournisseurs() as $lien) {
            if ($lien->getFournisseur() === $fournisseur) {
                $lien->setPrixAchat($prix);

                return;
            }
        }

        $article->addFournisseur(
            (new ArticleFournisseur())
                ->setFournisseur($fournisseur)
                ->setPrixAchat($prix)
                ->setPrincipal($article->getFournisseurs()->isEmpty())
        );
    }

    /**
     * Quantités, frais réels, date et contrôle à trois points du formulaire.
     *
     * @param array<string, mixed> $donnees
     */
    private function lireReception(Achats $achat, array $donnees, bool $voitFinances, \DateTimeImmutable $maintenant): void
    {
        $lignes = (array) ($donnees['lignes'] ?? []);
        $total = 0;

        foreach ($achat->getLignes() as $ligne) {
            $saisie = (array) ($lignes[(string) $ligne->getId()] ?? []);
            $recue = isset($saisie['recue']) && trim((string) $saisie['recue']) !== '' ? self::entier($saisie['recue']) : $ligne->getQuantite();
            $bon = isset($saisie['bon']) && trim((string) $saisie['bon']) !== '' ? min($recue, self::entier($saisie['bon'])) : $recue;
            $facturee = isset($saisie['facturee']) && trim((string) $saisie['facturee']) !== '' ? self::entier($saisie['facturee']) : $ligne->getQuantite();

            $ligne->setQuantiteRecue($recue)
                ->setQuantiteAbimee($recue - $bon)
                ->setQuantiteFacturee($facturee)
                ->setCasseMotif($recue > $bon ? (string) ($saisie['motif'] ?? '') : null);
            $total += $recue;
        }

        if ($total <= 0) {
            throw new \DomainException('Rien n\'est arrivé : la commande reste en route.');
        }

        // Les frais réels remplacent les prévus ; les prévus restent pour l'écart.
        $achat->setFraisTransportPrevu($achat->getFraisTransportPrevu() ?? $achat->getFraisTransport());
        $achat->setFraisTransitPrevu($achat->getFraisTransitPrevu() ?? $achat->getFraisTransit());
        foreach (['transport' => 'setFraisTransport', 'transit' => 'setFraisTransit'] as $cle => $setter) {
            $valeur = trim((string) ($donnees[$cle.'_reel'] ?? ''));
            if ($valeur !== '') {
                $achat->{$setter}(self::entier($valeur));
            }
        }

        // Date d'arrivée : celle saisie (jamais dans le futur ni avant la commande), à l'heure de la saisie.
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($donnees['date_reception'] ?? '')) ?: $maintenant;
        $date = min($date->setTime((int) $maintenant->format('H'), (int) $maintenant->format('i')), $maintenant);
        $commande = SuiviAchats::dateCommande($achat);
        $achat->setDateReception($date < $commande ? $maintenant : $date);

        $this->lireControle($achat, $donnees, $voitFinances);
    }

    /**
     * @param array<string, mixed> $donnees
     */
    private function lireControle(Achats $achat, array $donnees, bool $voitFinances): void
    {
        $achat->setBlNumero(\array_key_exists('bl_numero', $donnees) ? (string) $donnees['bl_numero'] : $achat->getBlNumero());
        $achat->setBlDate(self::date($donnees['bl_date'] ?? null) ?? $achat->getBlDate());
        $achat->setFactureNumero(\array_key_exists('facture_numero', $donnees) ? (string) $donnees['facture_numero'] : $achat->getFactureNumero());
        $achat->setFactureDate(self::date($donnees['facture_date'] ?? null) ?? $achat->getFactureDate());

        // Le montant de la facture est de l'argent dû : seulement avec le droit trésorerie.
        if ($voitFinances && \array_key_exists('facture_montant', $donnees)) {
            $achat->setFactureMontant(trim((string) $donnees['facture_montant']) !== '' ? self::entier($donnees['facture_montant']) : null);
        }
    }

    /**
     * Coût de revient réel de chaque ligne : frais réels, unités en bon état.
     */
    private function appliquerCouts(Achats $achat): void
    {
        $lignes = [];
        $pourCouts = [];
        $prevus = [];

        foreach (SuiviAchats::detailCommande($achat)['lignes'] as $l) {
            $prevus[spl_object_id($l['ligne'])] = $l['coutRevient'];
        }

        foreach ($achat->getLignes() as $ligne) {
            $article = $ligne->getArticle();
            if ($article === null) {
                continue;
            }
            $lignes[] = $ligne;
            $pourCouts[] = [
                'recue' => $article->convertirAchatEnStock((float) ($ligne->getQuantiteRecue() ?? $ligne->getQuantite())),
                'bon' => $article->convertirAchatEnStock((float) $ligne->getQuantiteBonEtat()),
                'unite' => $article->getUniteCourte(),
                'montantRecu' => ($ligne->getQuantiteRecue() ?? $ligne->getQuantite()) * $ligne->getPrixUnitaire(),
                'montantBon' => $ligne->getQuantiteBonEtat() * $ligne->getPrixUnitaire(),
            ];
        }

        foreach (CalculsReception::coutsReels($pourCouts, $achat->getFraisTransport(), $achat->getFraisTransit()) as $i => $c) {
            $ligne = $lignes[$i];
            $ligne->setCoutRevientPrevu($ligne->getCoutRevientPrevu() ?? $ligne->getCoutRevientUnitaire() ?? $prevus[spl_object_id($ligne)] ?? null);
            if ($c['cout'] !== null) {
                $ligne->setCoutRevientUnitaire($c['cout']);
            }
            $ligne->setQuantiteStock($pourCouts[$i]['recue']);
        }
    }

    /**
     * Seules les unités en bon état entrent en stock, en unité de stock
     * (rouleaux × m² par rouleau), au coût de revient réel ; le coût moyen
     * pondéré de l'article suit. Une ligne déjà entrée est sautée.
     */
    private function entrerEnStock(Achats $achat, \DateTimeImmutable $maintenant): void
    {
        foreach ($achat->getLignes() as $ligne) {
            $article = $ligne->getArticle();

            if ($article === null || $ligne->estEntreeEnStock()) {
                continue;
            }

            $quantite = $article->convertirAchatEnStock((float) $ligne->getQuantiteBonEtat());
            $prix = $ligne->getCoutRevientUnitaire() ?? (int) round($ligne->getPrixUnitaire() / $article->getFacteurConversion());
            $avant = $this->stock->getStockPhysique($article);

            if ($quantite > 0) {
                $this->stock->appliquerCoutMoyen($article, $quantite, $prix);
                $this->em->persist((new StockEntrees())->setArticle($article)->setQuantites($quantite)->setPrix($prix)->setDate($achat->getDateReception() ?? $maintenant));
            }

            $ligne->marquerEntreeStock($avant, $quantite, $maintenant);

            if ($ligne->getPrixUnitaire() > 0) {
                $article->setPrixAchat($ligne->getPrixUnitaire());
                self::retenirPrixFournisseur($article, $achat->getFournisseur(), $ligne->getPrixUnitaire());
            }

            // Deux lignes du même article : la seconde voit la première dans le stock.
            $this->em->flush();
        }
    }

    /**
     * Une réclamation par ligne où la facture compte plus que le bon état.
     */
    private function creerReclamations(Achats $achat, ?string $photo, ?User $par, \DateTimeImmutable $maintenant): void
    {
        foreach ($achat->getLignes() as $ligne) {
            $article = $ligne->getArticle();
            $quantite = CalculsReception::quantiteAReclamer($ligne->getQuantiteFacturee() ?? $ligne->getQuantite(), $ligne->getQuantiteBonEtat());

            if ($article === null || $quantite <= 0) {
                continue;
            }

            $montant = $quantite * $ligne->getPrixUnitaire();
            $reclamation = (new ReclamationFournisseur($par, $maintenant))
                ->setLigne($ligne)
                ->setQuantite($quantite)
                ->setMontant($montant)
                ->setPhoto($ligne->getQuantiteAbimee() > 0 ? $photo : null)
                ->setMessage(CalculsReception::messageReclamation(
                    $achat->getFournisseur()?->getContactNom(),
                    $achat->getNumero(),
                    $achat->getDateReception() ?? $maintenant,
                    $quantite,
                    $article->getUniteAchat() ?: $article->getUniteCourte(),
                    SuiviAchats::nomCourt($article),
                    $ligne->getCasseMotif(),
                    $ligne->getQuantiteAbimee(),
                    $montant,
                    $achat->getFactureNumero()
                ));
            $achat->addReclamation($reclamation);
            $this->em->persist($reclamation);
        }
    }

    /**
     * La facture du fournisseur devient une dette à payer (Finances ›
     * Fournisseurs) : le montant facturé, moins l'acompte versé à la
     * commande et les réclamations déduites. Une dette déjà payée ne
     * bouge plus.
     */
    private function synchroniserDette(Achats $achat, ?User $par, \DateTimeImmutable $maintenant): void
    {
        $montant = $achat->getFactureMontant();

        if ($montant === null) {
            return;
        }

        $deduit = 0;
        foreach ($achat->getReclamations() as $r) {
            $deduit += $r->getStatut() === ReclamationFournisseur::DEDUITE ? $r->getMontant() : 0;
        }

        $dette = $this->dette($achat);

        if ($dette !== null && !\in_array($dette->getStatut(), [Depense::A_PAYER, Depense::A_APPROUVER], true)) {
            return;
        }

        if ($dette === null) {
            $dette = (new Depense($par, $maintenant))
                ->setAchat($achat)
                ->setFournisseur($achat->getFournisseur())
                ->setCategorie('matieres')
                ->setStatut(Depense::A_PAYER)
                ->setJustificatif('');
            $this->em->persist($dette);
        }

        $acompte = $achat->getMontantAcompte();
        $dette->setObjet(sprintf('Facture %s · %s', $achat->getFactureNumero() ?? 'fournisseur', $achat->getNumero()))
            ->setLieA($achat->getNumero())
            ->setDateDepense($achat->getFactureDate() ?? $achat->getDateReception() ?? $maintenant)
            ->setMontant(max(0, CalculsReception::soldeAPayer($montant, $acompte) - $deduit))
            ->setNote(sprintf('Solde de la facture %s (%s F) de la commande %s : acompte de %s F déduit%s.', $achat->getFactureNumero() ?? '', number_format($montant, 0, ',', ' '), $achat->getNumero(), number_format($acompte, 0, ',', ' '), $deduit > 0 ? sprintf(', avoir de %s F déduit', number_format($deduit, 0, ',', ' ')) : ''));
    }

    /**
     * Range la photo dans var/uploads/reclamations (nom choisi par le
     * serveur, type lu dans le contenu) et rend son nom.
     */
    private function rangerPhoto(UploadedFile $photo): string
    {
        $extension = FichierEnvoye::extensionPour($photo, self::TYPES_PHOTO, self::TAILLE_MAX_PHOTO)
            ?? throw new \DomainException(sprintf('Photo refusée : JPEG, PNG ou WebP de %d Mo au plus.', self::TAILLE_MAX_PHOTO / 1048576));
        $nom = self::PREFIXE_PHOTO.bin2hex(random_bytes(16)).'.'.$extension;
        $photo->move($this->fichiers->dossier(self::DOSSIER_PHOTOS), $nom);

        return $nom;
    }

    private static function entier(mixed $valeur): int
    {
        return (int) preg_replace('/\D+/', '', (string) $valeur);
    }

    private static function date(mixed $valeur): ?\DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $valeur) ?: null;
    }
}
