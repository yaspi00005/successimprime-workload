<?php

namespace App\Service\Promotions;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\DemandeExceptionPromotion;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Entity\Produits;
use App\Entity\Promotion;
use App\Entity\User;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Promotions à la saisie d'un devis ou d'une commande (docs/promotions.md).
 *
 * À l'enregistrement, le serveur recalcule la promotion de chaque ligne :
 * le navigateur n'envoie que le code promo tapé et le choix de l'agent de
 * retirer la promotion d'une ligne. Une seule promotion par ligne, aucune
 * pour un client au prix B2B sans exception acceptée par un responsable
 * (droit Promotions). Une commande facturée ne bouge plus.
 */
final class PromotionsSaisie
{
    public const ENTITE_EXCEPTION = 'ExceptionPromotion';

    /** Qui décide des exceptions : l'administrateur (son rôle seul dans getRoles()) et le droit fin. */
    public const ROLES_DECIDEURS = ['ROLE_ADMIN', 'ROLE_PROMOTIONS'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Promotions $promotions,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Commande enregistrée depuis le formulaire. Remet les totaux d'aplomb
     * quand une ligne porte (ou portait) une promotion.
     */
    public function appliquerCommande(Commandes $commande, ?string $code, \DateTimeImmutable $maintenant): bool
    {
        if ($this->promotions->estEnLigne() || self::estFacturee($commande)) {
            return false;
        }
        $client = $commande->getClients();
        $lignes = self::lignesVivantes($commande);
        if (!$this->appliquerLignes($client, $lignes, $commande->getId(), $commande->getDateCommande() ?? $maintenant, $code)) {
            return false;
        }
        foreach ($lignes as $ligne) {
            $ligne->calculerTotaux($client?->isB2B() ?? false);
        }
        $commande->recalculerTotaux();

        return true;
    }

    public function appliquerDevis(Devis $devis, ?string $code, \DateTimeImmutable $maintenant): bool
    {
        if ($this->promotions->estEnLigne() || null !== $devis->getCommande()) {
            return false;
        }
        $client = $devis->getClients();
        $lignes = array_values($devis->getDevisDetails()->toArray());
        if (!$this->appliquerLignes($client, $lignes, null, $devis->getDateDevis() ?? $maintenant, $code)) {
            return false;
        }
        foreach ($lignes as $ligne) {
            $ligne->calculerTotaux($client?->isB2B() ?? false);
        }
        self::recalculerDevis($devis);

        return true;
    }

    /**
     * Aperçu pour le formulaire : la promotion de chaque ligne en cours de
     * saisie, calculée comme à l'enregistrement. Rien n'est écrit.
     *
     * @param list<array{cle: string, id: ?int, produit: ?int, prix: float, remise: int, quantite: int, brut: int}> $lignes
     *
     * @return array<string, array{promotion: ?array{id: int, libelle: string}, remise: int, b2b: list<array{id: int, libelle: string, demande: ?string}>}>
     */
    public function apercu(?Clients $client, Commandes|Devis|null $document, array $lignes, ?string $code, \DateTimeImmutable $maintenant): array
    {
        $resultat = [];
        $vide = ['promotion' => null, 'remise' => 0, 'b2b' => []];
        if (null === $client || $this->promotions->estEnLigne() || ($document instanceof Commandes && self::estFacturee($document))) {
            foreach ($lignes as $l) {
                $resultat[$l['cle']] = $vide;
            }

            return $resultat;
        }
        $jour = ($document instanceof Commandes ? $document->getDateCommande() : $document?->getDateDevis()) ?? $maintenant;
        $commandeId = $document instanceof Commandes ? $document->getId() : null;

        // Lignes déjà enregistrées de ce document : leur promotion et leurs exceptions.
        $existantes = [];
        foreach ($document instanceof Commandes ? $document->getCommandesDetails() : ($document?->getDevisDetails() ?? []) as $e) {
            $existantes[(int) $e->getId()] = $e;
        }
        $candidates = $this->promotions->valables($jour);
        foreach ($existantes as $e) {
            if (null !== $e->getPromotion()) {
                $candidates[] = $e->getPromotion();
            }
        }
        $segments = $this->promotions->segmentsDe($client, $commandeId, $jour);
        $utilisations = $this->promotions->utilisations($client, self::ids($candidates), $commandeId);
        $demandes = $this->demandesParLigne(array_values($existantes));
        $produits = $this->produits(array_filter(array_column($lignes, 'produit')));

        foreach ($lignes as $l) {
            $existante = null !== $l['id'] ? ($existantes[$l['id']] ?? null) : null;
            $produit = $produits[(int) $l['produit']] ?? null;
            if (null === $produit) {
                $resultat[$l['cle']] = $vide;
                continue;
            }
            $demandesLigne = null !== $existante ? ($demandes[spl_object_id($existante)] ?? []) : [];
            $ctx = new ContexteLigne($client, $produit, max(0, $l['prix']), max(0, $l['remise']), max(1, $l['quantite']), max(0, $l['brut']), $jour, $segments, $code, $utilisations, self::acceptees($demandesLigne), $produit->getPrixBase());
            $r = CalculPromotion::evaluer($candidates, $ctx, $existante?->getPromotion());
            $b2b = [];
            foreach ($r['b2b'] as $possible) {
                $demande = self::derniereDemande($demandesLigne, $possible['promotion']);
                $b2b[] = ['id' => (int) $possible['promotion']->getId(), 'libelle' => $possible['promotion']->libelleLigne(), 'demande' => $demande?->getStatut()];
            }
            $resultat[$l['cle']] = [
                'promotion' => null !== $r['promotion'] ? ['id' => (int) $r['promotion']->getId(), 'libelle' => $r['promotion']->libelleLigne()] : null,
                'remise' => $r['remise'],
                'b2b' => $b2b,
            ];
        }

        return $resultat;
    }

    /**
     * Promotions qu'une exception permettrait sur les lignes d'un document
     * d'un client B2B (fiche du devis ou de la commande), avec la dernière
     * demande de chacune.
     *
     * @return array<int, list<array{promotion: Promotion, remise: int, demande: ?DemandeExceptionPromotion}>> par id de ligne
     */
    public function possibilitesB2b(Commandes|Devis $document, \DateTimeImmutable $maintenant): array
    {
        $client = $document->getClients();
        if (null === $client || !$client->isB2B() || $this->promotions->estEnLigne()
            || ($document instanceof Commandes ? self::estFacturee($document) : null !== $document->getCommande())) {
            return [];
        }
        $commandeId = $document instanceof Commandes ? $document->getId() : null;
        $jour = ($document instanceof Commandes ? $document->getDateCommande() : $document->getDateDevis()) ?? $maintenant;
        $lignes = $document instanceof Commandes ? self::lignesVivantes($document) : array_values($document->getDevisDetails()->toArray());
        $candidates = $this->promotions->valables($jour);
        $segments = $this->promotions->segmentsDe($client, $commandeId, $jour);
        $utilisations = $this->promotions->utilisations($client, self::ids($candidates), $commandeId);
        $demandes = $this->demandesParLigne($lignes);

        $resultat = [];
        foreach ($lignes as $ligne) {
            if (null === $ligne->getProduit() || null !== $ligne->getPromotion()) {
                continue;
            }
            $r = CalculPromotion::evaluer($candidates, $this->contexte($client, $ligne, $jour, $segments, null, $utilisations, []), null);
            foreach ($r['b2b'] as $possible) {
                $resultat[(int) $ligne->getId()][] = $possible + ['demande' => self::derniereDemande($demandes[spl_object_id($ligne)] ?? [], $possible['promotion'])];
            }
        }

        return $resultat;
    }

    /**
     * L'agent demande d'appliquer la promotion à une ligne d'un client B2B.
     * La ligne reste au prix B2B ; les responsables sont prévenus.
     *
     * @throws \DomainException
     */
    public function demanderException(CommandesDetails|DevisDetails $ligne, Promotion $promotion, User $par, \DateTimeImmutable $maintenant): DemandeExceptionPromotion
    {
        $this->promotions->surLaBoutique();
        $document = $ligne instanceof CommandesDetails ? $ligne->getCommande() : $ligne->getDevis();
        $client = $document?->getClients();
        if (null === $document || null === $client) {
            throw new \DomainException('Ligne sans client.');
        }
        if (!$client->isB2B()) {
            throw new \DomainException('Ce client n\'est pas au prix B2B : la promotion s\'applique toute seule.');
        }
        $this->verifierModifiable($document);
        foreach ($this->demandesParLigne([$ligne])[spl_object_id($ligne)] ?? [] as $d) {
            if ($d->getPromotion()?->getId() === $promotion->getId() && !$d->estRefusee()) {
                throw new \DomainException($d->estEnAttente() ? 'L\'exception est déjà demandée pour cette ligne.' : 'L\'exception est déjà acceptée pour cette ligne.');
            }
        }
        $possible = null;
        foreach ($this->possibilitesB2b($document, $maintenant)[(int) $ligne->getId()] ?? [] as $p) {
            if ($p['promotion']->getId() === $promotion->getId()) {
                $possible = $p;
            }
        }
        if (null === $possible) {
            throw new \DomainException('Cette promotion ne s\'applique pas à cette ligne.');
        }

        // Avantage B2B de la ligne par unité (remise, ou écart avec le prix public), pour la décision.
        $avantage = (new ContexteLigne($client, $ligne->getProduit(), $ligne->getPrixUnitaire(), $ligne->getRemise(), 1, 0, $maintenant, prixPublic: self::prixPublic($ligne)))->avantageDeLaLigne();
        $demande = new DemandeExceptionPromotion($promotion, $client, $ligne, $possible['remise'], $avantage, $par, $maintenant);
        $this->em->persist($demande);
        $this->em->flush();
        $this->promotions->journaliser(self::ENTITE_EXCEPTION, (int) $demande->getId(), 'exception_demandee', null, self::resume($demande), $par);
        $this->notifications->notifierRoles(
            self::ROLES_DECIDEURS,
            \sprintf('Exception B2B demandée par %s : « %s » pour %s (%s).', $par->getUsername(), $promotion->getNom(), $client->getNomComplet(), $demande->getDocument() ?? 'document'),
            'app_promotions_exceptions',
            [],
            $par
        );
        $this->em->flush();

        return $demande;
    }

    /**
     * Le responsable accepte (la ligne prend la promotion, sans cumul avec
     * le prix B2B) ou refuse avec un motif. L'agent est prévenu.
     *
     * @throws \DomainException
     */
    public function deciderException(DemandeExceptionPromotion $demande, bool $accepter, ?string $reponse, User $par, \DateTimeImmutable $maintenant): void
    {
        $this->promotions->surLaBoutique();
        $ligne = $demande->getLigne();
        $promotion = $demande->getPromotion();
        if (null === $ligne || null === $promotion) {
            throw new \DomainException('La ligne ou la promotion n\'existe plus.');
        }
        $document = $ligne instanceof CommandesDetails ? $ligne->getCommande() : $ligne->getDevis();
        $client = $document?->getClients();
        if (null === $document || null === $client) {
            throw new \DomainException('Ligne sans client.');
        }

        if ($accepter) {
            $this->verifierModifiable($document);
            $jour = ($document instanceof Commandes ? $document->getDateCommande() : $document->getDateDevis()) ?? $maintenant;
            $commandeId = $document instanceof Commandes ? $document->getId() : null;
            $segments = $this->promotions->segmentsDe($client, $commandeId, $jour);
            $utilisations = $this->promotions->utilisations($client, [(int) $promotion->getId()], $commandeId);
            $ctx = $this->contexte($client, $ligne, $jour, $segments, null, $utilisations, [(int) $promotion->getId()]);
            // Le code n'est pas redemandé : l'agent l'avait au moment de la demande.
            $raison = $promotion->isActive() ? CalculPromotion::refus($promotion, $ctx, true) : 'suspendue';
            if (null !== $raison) {
                throw new \DomainException('La promotion ne s\'applique plus à cette ligne : '.lcfirst(CalculPromotion::REFUS[$raison]).' Refusez la demande avec ce motif.');
            }
            $demande->decider(true, $reponse, $par, $maintenant);
            $ligne->setPromotionRetiree(false);
            $ligne->appliquerPromotion($promotion, CalculPromotion::remisePour($promotion, $ctx));
            $ligne->calculerTotaux(true);
            if ($document instanceof Commandes) {
                $document->recalculerTotaux();
            } else {
                self::recalculerDevis($document);
            }
        } else {
            $demande->decider(false, $reponse, $par, $maintenant);
        }

        $this->promotions->journaliser(self::ENTITE_EXCEPTION, (int) $demande->getId(), $accepter ? 'exception_acceptee' : 'exception_refusee', ['statut' => DemandeExceptionPromotion::EN_ATTENTE], self::resume($demande), $par);
        if (null !== $demande->getDemandePar() && $demande->getDemandePar() !== $par) {
            $this->notifications->notifierUtilisateur(
                $demande->getDemandePar(),
                \sprintf('Exception %s : « %s » sur %s%s', $accepter ? 'acceptée' : 'refusée', $promotion->getNom(), $demande->getDocument() ?? 'le document', null !== $demande->getReponse() ? ' — '.$demande->getReponse() : '.'),
                $document instanceof Commandes ? 'app_commandes_show' : 'app_devis_show',
                ['id' => $document->getId()]
            );
        }
        $this->em->flush();
    }

    /** @return list<DemandeExceptionPromotion> */
    public function demandesEnAttente(): array
    {
        return $this->em->getRepository(DemandeExceptionPromotion::class)->findBy(['statut' => DemandeExceptionPromotion::EN_ATTENTE], ['demandeLe' => 'ASC']);
    }

    /** @return list<DemandeExceptionPromotion> les dernières décisions */
    public function demandesTraitees(int $max = 30): array
    {
        return $this->em->createQueryBuilder()->select('d')->from(DemandeExceptionPromotion::class, 'd')
            ->where('d.statut != :attente')->setParameter('attente', DemandeExceptionPromotion::EN_ATTENTE)
            ->orderBy('d.decideLe', 'DESC')->setMaxResults($max)->getQuery()->getResult();
    }

    /**
     * Exceptions des lignes, par ligne (spl_object_id), de la plus récente à la plus ancienne.
     *
     * @param list<CommandesDetails|DevisDetails> $lignes
     *
     * @return array<int, list<DemandeExceptionPromotion>>
     */
    public function demandesParLigne(array $lignes): array
    {
        $parId = ['commande' => [], 'devis' => []];
        foreach ($lignes as $l) {
            if (null !== $l->getId()) {
                $parId[$l instanceof CommandesDetails ? 'commande' : 'devis'][(int) $l->getId()] = $l;
            }
        }
        $resultat = [];
        foreach (['commande' => 'ligneCommande', 'devis' => 'ligneDevis'] as $type => $champ) {
            if ([] === $parId[$type]) {
                continue;
            }
            $demandes = $this->em->createQueryBuilder()->select('d', 'p')->from(DemandeExceptionPromotion::class, 'd')
                ->join('d.promotion', 'p')
                ->where('d.'.$champ.' IN (:lignes)')->setParameter('lignes', array_keys($parId[$type]))
                ->orderBy('d.demandeLe', 'DESC')->addOrderBy('d.id', 'DESC')
                ->getQuery()->getResult();
            foreach ($demandes as $d) {
                $ligne = $parId[$type][(int) $d->getLigne()?->getId()] ?? null;
                if (null !== $ligne) {
                    $resultat[spl_object_id($ligne)][] = $d;
                }
            }
        }

        return $resultat;
    }

    /**
     * @param list<CommandesDetails|DevisDetails> $lignes
     */
    private function appliquerLignes(?Clients $client, array $lignes, ?int $commandeId, \DateTimeImmutable $jour, ?string $code): bool
    {
        $touche = false;
        foreach ($lignes as $ligne) {
            $touche = $touche || null !== $ligne->getPromotion() || $ligne->getRemisePromotion() > 0;
        }
        if (null === $client) {
            foreach ($lignes as $ligne) {
                $ligne->retirerPromotion();
            }

            return $touche;
        }

        $candidates = $this->promotions->valables($jour);
        foreach ($lignes as $ligne) {
            if (null !== $ligne->getPromotion()) {
                $candidates[] = $ligne->getPromotion();
            }
        }
        if ([] === $candidates) {
            return $touche;
        }
        $segments = $this->promotions->segmentsDe($client, $commandeId, $jour);
        $utilisations = $this->promotions->utilisations($client, self::ids($candidates), $commandeId);
        $demandes = $client->isB2B() ? $this->demandesParLigne($lignes) : [];

        foreach ($lignes as $ligne) {
            if ($ligne->isPromotionRetiree() || null === $ligne->getProduit()) {
                $ligne->retirerPromotion();
                continue;
            }
            $ctx = $this->contexte($client, $ligne, $jour, $segments, $code, $utilisations, self::acceptees($demandes[spl_object_id($ligne)] ?? []));
            $r = CalculPromotion::evaluer($candidates, $ctx, $ligne->getPromotion());
            if (null !== $r['promotion']) {
                $ligne->appliquerPromotion($r['promotion'], $r['remise']);
                $touche = true;
            } else {
                $ligne->retirerPromotion();
            }
        }

        return $touche;
    }

    /**
     * @param list<string>    $segments
     * @param array<int, int> $utilisations
     * @param list<int>       $acceptees
     */
    private function contexte(Clients $client, CommandesDetails|DevisDetails $ligne, \DateTimeImmutable $jour, array $segments, ?string $code, array $utilisations, array $acceptees): ContexteLigne
    {
        return new ContexteLigne(
            $client,
            $ligne->getProduit(),
            $ligne->getPrixUnitaire(),
            $ligne->getRemise(),
            $ligne->getQuantite(),
            $ligne->getTotalAvantRemise(),
            $jour,
            $segments,
            $code,
            $utilisations,
            $acceptees,
            self::prixPublic($ligne),
        );
    }

    /** Prix public de l'article de la ligne : celui de sa configuration, sinon celui du produit. */
    private static function prixPublic(CommandesDetails|DevisDetails $ligne): ?float
    {
        $prix = $ligne->getProduitConfiguration()?->getPrixBase() ?? $ligne->getProduit()?->getPrixBase();

        return null !== $prix ? (float) $prix : null;
    }

    /** @throws \DomainException */
    private function verifierModifiable(Commandes|Devis $document): void
    {
        if ($document instanceof Commandes && self::estFacturee($document)) {
            throw new \DomainException('La commande est déjà facturée : ses prix ne bougent plus.');
        }
        if ($document instanceof Devis && null !== $document->getCommande()) {
            throw new \DomainException('Ce devis est déjà transformé en commande.');
        }
    }

    public static function estFacturee(Commandes $commande): bool
    {
        foreach ($commande->getFactures() as $f) {
            if ($f->estFacture() && !$f->estAnnulee()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<CommandesDetails> */
    private static function lignesVivantes(Commandes $commande): array
    {
        return array_values(array_filter(
            $commande->getCommandesDetails()->toArray(),
            static fn (CommandesDetails $l): bool => CommandesDetails::PRODUCTION_ANNULEE !== $l->getStatutProduction(),
        ));
    }

    /** Totaux du devis, comme les calcule le formulaire. */
    private static function recalculerDevis(Devis $devis): void
    {
        $ht = 0;
        $ttc = 0;
        foreach ($devis->getDevisDetails() as $ligne) {
            $ht += $ligne->getTotalHt();
            $ttc += $ligne->getTotalTtc();
        }
        $devis->setTotalHt($ht);
        $devis->setTotalTtc($ttc);
        $devis->setMontantApayer(max(0, $ttc - (int) $devis->getRemise()));
    }

    /**
     * @param iterable<Promotion> $promotions
     *
     * @return list<int>
     */
    private static function ids(iterable $promotions): array
    {
        $ids = [];
        foreach ($promotions as $p) {
            if (null !== $p->getId()) {
                $ids[(int) $p->getId()] = (int) $p->getId();
            }
        }

        return array_values($ids);
    }

    /**
     * @param list<DemandeExceptionPromotion> $demandes
     *
     * @return list<int>
     */
    private static function acceptees(array $demandes): array
    {
        $ids = [];
        foreach ($demandes as $d) {
            if ($d->estAcceptee()) {
                $ids[] = (int) $d->getPromotion()?->getId();
            }
        }

        return $ids;
    }

    /** @param list<DemandeExceptionPromotion> $demandes du plus récent au plus ancien */
    private static function derniereDemande(array $demandes, Promotion $promotion): ?DemandeExceptionPromotion
    {
        foreach ($demandes as $d) {
            if ($d->getPromotion()?->getId() === $promotion->getId()) {
                return $d;
            }
        }

        return null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, Produits>
     */
    private function produits(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $resultat = [];
        foreach ($this->em->getRepository(Produits::class)->findBy(['id' => array_values(array_unique(array_map('intval', $ids)))]) as $p) {
            $resultat[(int) $p->getId()] = $p;
        }

        return $resultat;
    }

    /** @return array<string, mixed> pour le journal : ni prix ni remise */
    private static function resume(DemandeExceptionPromotion $d): array
    {
        return [
            'promotion' => $d->getPromotion()?->getNom(),
            'client' => $d->getClient()?->getCode(),
            'document' => $d->getDocument(),
            'ligne' => $d->getLigne()?->getDesignation(),
            'statut' => $d->getStatut(),
            'reponse' => $d->getReponse(),
        ];
    }
}
