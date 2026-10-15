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
        private readonly CodesPromo $codes,
    ) {
    }

    /**
     * Commande enregistrée depuis le formulaire. Remet les totaux d'aplomb
     * quand une ligne porte (ou portait) une promotion, ou que la commande
     * porte (ou portait) un code promo.
     *
     * Le code promo tapé est vérifié ici (CodesPromo) : refusé, il bloque
     * l'enregistrement avec la raison. Seule exception : une commande passée
     * en ligne qu'on confirme avec le code tapé par le client. La boutique
     * tranche alors sans bloquer : la commande perd l'avantage et garde le
     * motif, que l'équipe lit sur la fiche.
     *
     * @throws \DomainException code promo refusé
     */
    public function appliquerCommande(Commandes $commande, ?string $code, \DateTimeImmutable $maintenant): bool
    {
        if ($this->promotions->estEnLigne() || self::estFacturee($commande)) {
            return false;
        }
        $client = $commande->getClients();
        $lignes = self::lignesVivantes($commande);
        $souple = Commandes::CANAL_EN_LIGNE === $commande->getCanal() && null === $commande->getCodePromo()
            && null !== $commande->getCodeSaisi() && CalculCodePromo::canonique($code) === $commande->getCodeSaisi();
        if (!$this->appliquerAvecCode($commande, $client, $lignes, $commande->getId(), $commande->getDateCommande() ?? $maintenant, $code, $souple)) {
            return false;
        }
        foreach ($lignes as $ligne) {
            $ligne->calculerTotaux($client?->isB2B() ?? false);
        }
        $commande->recalculerTotaux();

        return true;
    }

    /** @throws \DomainException code promo refusé */
    public function appliquerDevis(Devis $devis, ?string $code, \DateTimeImmutable $maintenant): bool
    {
        if ($this->promotions->estEnLigne() || null !== $devis->getCommande()) {
            return false;
        }
        $client = $devis->getClients();
        $lignes = array_values($devis->getDevisDetails()->toArray());
        if (!$this->appliquerAvecCode($devis, $client, $lignes, null, $devis->getDateDevis() ?? $maintenant, $code, false)) {
            return false;
        }
        foreach ($lignes as $ligne) {
            $ligne->calculerTotaux($client?->isB2B() ?? false);
        }
        self::recalculerDevis($devis);

        return true;
    }

    /**
     * Promotions des lignes, puis code promo du document. Rien n'est
     * enregistré : l'appelant refait les totaux si la méthode rend true.
     *
     * Le code débloque les lignes de sa promotion et, si elle en a un, son
     * avantage sur la commande. Cet avantage se calcule sur les lignes sans
     * promotion (une ligne n'a qu'un avantage promotionnel : sa promotion,
     * ou sa part du code), après leurs remises, et se répartit sur elles.
     *
     * @param list<CommandesDetails|DevisDetails> $lignes
     *
     * @throws \DomainException code refusé, sauf en mode souple
     */
    private function appliquerAvecCode(Commandes|Devis $document, ?Clients $client, array $lignes, ?int $commandeId, \DateTimeImmutable $jour, ?string $saisi, bool $souple): bool
    {
        $canonique = CalculCodePromo::canonique($saisi);
        $avant = $document->getCodePromo();
        $avaitUneRemise = $document->getRemiseCodePromo() > 0;
        foreach ($lignes as $ligne) {
            $avaitUneRemise = $avaitUneRemise || $ligne->getRemiseCodePromo() > 0;
        }

        $verification = $this->codes->verifier($canonique, $client, $document, $jour, true);
        $code = $verification['code'];
        $refus = $verification['refus'];
        $debloquees = null !== $code && null === $refus ? [(int) $code->getPromotion()?->getId()] : [];
        $touche = $this->appliquerLignes($client, $lignes, $commandeId, $jour, $saisi, $debloquees);

        $parts = [];
        if (null !== $code && null === $refus && null !== $client && null !== $code->getPromotion()) {
            [$refus, $parts] = self::avantageCommande($code->getPromotion(), $lignes);
            if (null !== $refus) {
                // Le code ne sert pas : les lignes de sa promotion non plus.
                $touche = $this->appliquerLignes($client, $lignes, $commandeId, $jour, $saisi, []) || $touche;
            }
        }
        if (null !== $refus) {
            if (!$souple) {
                throw new \DomainException(CalculCodePromo::message($refus, true));
            }
            $document->setMotifCodePromo(CalculCodePromo::raison($refus));
            $code = null;
            $parts = [];
        } else {
            $document->setMotifCodePromo(null);
        }

        $document->setCodePromo($code);
        if (!$souple) {
            // Le code simple d'une promotion ne se garde pas : les lignes gardent leur promotion.
            $document->setCodeSaisi(null !== $code ? $canonique : null);
        }
        foreach ($lignes as $ligne) {
            $ligne->setRemiseCodePromo($parts[spl_object_id($ligne)] ?? 0);
        }
        $document->setRemiseCodePromo(array_sum($parts));

        // Compte des utilisations, pour la liste et pour le site en ligne.
        if (null !== $avant && $avant !== $code) {
            $this->codes->recompter($avant, $document, false);
        }
        if (null !== $code) {
            $this->codes->recompter($code, $document, true);
        }

        return $touche || $avaitUneRemise || [] !== $parts || $avant !== $code || null !== $refus;
    }

    /**
     * Espace client (site en ligne) : le code tapé par le client en passant
     * commande, vérifié avec ce que le site sait (empreinte, dates, limites
     * et utilisations venues du local, commandes en ligne pas encore
     * confirmées). Valable : la promotion du code s'applique aux lignes
     * qu'elle vise, son avantage sur la commande se répartit sur les autres,
     * et la commande garde le code tapé. Elle ne retient pas le code : la
     * boutique le revérifie en la confirmant (appliquerCommande).
     *
     * @throws CodePromoRefuse
     */
    public function appliquerCodeEnLigne(Commandes $commande, string $saisi, \DateTimeImmutable $jour): void
    {
        $canonique = CalculCodePromo::canonique($saisi);
        $client = $commande->getClients();
        if ('' === $canonique || null === $client) {
            return;
        }
        if ($client->isB2B()) {
            // Avant même de chercher le code : la réponse est la même pour tout code tapé (rien à deviner).
            throw new CodePromoRefuse('b2b');
        }
        $verification = $this->codes->verifier($canonique, $client, null, $jour, false);
        $code = $verification['code'];
        $promotion = $code?->getPromotion();
        if (null === $code || null === $promotion) {
            // En ligne, seul un code généré se reconnaît (une promotion à code simple n'y est pas active).
            throw new CodePromoRefuse('inconnu');
        }
        if (null !== $verification['refus']) {
            throw new CodePromoRefuse($verification['refus']);
        }

        $lignes = self::lignesVivantes($commande);
        $debloquees = [(int) $promotion->getId()];
        $segments = $this->promotions->segmentsDe($client, null, $jour);
        $utilisations = $this->promotions->utilisations($client, $debloquees, null);
        foreach ($lignes as $ligne) {
            if (null === $ligne->getProduit()) {
                continue;
            }
            $r = CalculPromotion::evaluer([$promotion], $this->contexte($client, $ligne, $jour, $segments, $canonique, $utilisations, [], $debloquees));
            if (null !== $r['promotion']) {
                $ligne->appliquerPromotion($r['promotion'], $r['remise'])->calculerTotaux($client->isB2B());
            }
        }
        [$refus, $parts] = self::avantageCommande($promotion, $lignes);
        if (null !== $refus) {
            foreach ($lignes as $ligne) {
                if ($ligne->getPromotion() === $promotion) {
                    $ligne->retirerPromotion();
                    $ligne->calculerTotaux($client->isB2B());
                }
            }
            throw new CodePromoRefuse($refus);
        }
        foreach ($lignes as $ligne) {
            $ligne->setRemiseCodePromo($parts[spl_object_id($ligne)] ?? 0);
            $ligne->calculerTotaux($client->isB2B());
        }
        $commande->setCodeSaisi($canonique)->setMotifCodePromo(null);
        $commande->recalculerTotaux();
    }

    /**
     * Avantage de la promotion sur la commande et sa part sur chaque ligne
     * (par spl_object_id), ou la raison pour laquelle le code ne sert pas.
     *
     * @param list<CommandesDetails|DevisDetails> $lignes
     *
     * @return array{0: ?string, 1: array<int, int>}
     */
    public static function avantageCommande(Promotion $promotion, array $lignes): array
    {
        $total = 0;
        $bases = [];
        $surLesLignes = false;
        foreach ($lignes as $ligne) {
            $net = $ligne->getTotalAvantCodePromo();
            $total += $net;
            if (null === $ligne->getPromotion()) {
                $bases[spl_object_id($ligne)] = $net;
            } elseif ($ligne->getPromotion() === $promotion || (null !== $promotion->getId() && $ligne->getPromotion()->getId() === $promotion->getId())) {
                $surLesLignes = true;
            }
        }
        if (!CalculCodePromo::minimumAtteint($promotion, $total)) {
            return ['minimum', []];
        }
        $montant = CalculCodePromo::montantCommande($promotion, array_sum($bases));
        if (0 === $montant && !$surLesLignes) {
            return ['sans_effet', []];
        }

        return [null, array_filter(CalculCodePromo::repartir($montant, $bases))];
    }

    /**
     * Aperçu pour le formulaire : la promotion de chaque ligne en cours de
     * saisie, et le code promo tapé, calculés comme à l'enregistrement.
     * Rien n'est écrit, aucun code n'est compté.
     *
     * @param list<array{cle: string, id: ?int, produit: ?int, prix: float, remise: int, quantite: int, brut: int, unites?: float, tva?: int}> $lignes
     *
     * @return array{lignes: array<string, array{promotion: ?array{id: int, libelle: string}, remise: int, b2b: list<array{id: int, libelle: string, demande: ?string}>, code: int}>, code: ?array{etat: string, message: ?string, libelle: ?string, remise: int, remiseTtc: int}}
     */
    public function apercu(?Clients $client, Commandes|Devis|null $document, array $lignes, ?string $code, \DateTimeImmutable $maintenant): array
    {
        $resultat = [];
        $vide = ['promotion' => null, 'remise' => 0, 'b2b' => [], 'code' => 0];
        $canonique = CalculCodePromo::canonique($code);
        if (null === $client || $this->promotions->estEnLigne() || ($document instanceof Commandes && self::estFacturee($document))) {
            foreach ($lignes as $l) {
                $resultat[$l['cle']] = $vide;
            }

            $infoCode = null;
            if ($document instanceof Commandes && null !== $document->getCodePromo() && !$this->promotions->estEnLigne()) {
                // Facturée : rien ne change plus, le code retenu reste tel quel.
                $infoCode = ['etat' => 'ok', 'message' => null, 'libelle' => $document->getLibelleCodePromo(), 'remise' => $document->getRemiseCodePromo(), 'remiseTtc' => $document->getRemiseCodePromoTtc()];
            } elseif ('' !== $canonique && null === $client) {
                $infoCode = ['etat' => 'refus', 'message' => CalculCodePromo::raison('client'), 'libelle' => null, 'remise' => 0, 'remiseTtc' => 0];
            }

            return ['lignes' => $resultat, 'code' => $infoCode];
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
        $verification = '' === $canonique ? null : $this->codes->verifier($canonique, $client, $document, $jour, false);
        $codePromo = $verification['code'] ?? null;
        $refus = $verification['refus'] ?? null;

        $evaluer = function (array $debloquees) use ($lignes, $existantes, $produits, $demandes, $client, $jour, $segments, $code, $utilisations, $candidates, $vide): array {
            $resultat = [];
            foreach ($lignes as $l) {
                $existante = null !== $l['id'] ? ($existantes[$l['id']] ?? null) : null;
                $produit = $produits[(int) $l['produit']] ?? null;
                if (null === $produit) {
                    $resultat[$l['cle']] = $vide + ['_promotion' => null];
                    continue;
                }
                $demandesLigne = null !== $existante ? ($demandes[spl_object_id($existante)] ?? []) : [];
                $ctx = new ContexteLigne($client, $produit, max(0, $l['prix']), max(0, $l['remise']), max(1, $l['quantite']), max(0, $l['brut']), $jour, $segments, $code, $utilisations, self::acceptees($demandesLigne), $produit->getPrixBase(), $debloquees);
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
                    'code' => 0,
                    '_promotion' => $r['promotion'],
                ];
            }

            return $resultat;
        };

        $debloquees = null !== $codePromo && null === $refus ? [(int) $codePromo->getPromotion()?->getId()] : [];
        $resultat = $evaluer($debloquees);
        $infoCode = null;
        if (null !== $verification) {
            $remise = 0;
            $remiseTtc = 0;
            if (null !== $codePromo && null === $refus && null !== $codePromo->getPromotion()) {
                [$refus, $remise, $remiseTtc, $parts] = self::avantageApercu($codePromo->getPromotion(), $lignes, $resultat);
                if (null !== $refus) {
                    $resultat = $evaluer([]);
                } else {
                    foreach ($parts as $cle => $part) {
                        $resultat[$cle]['code'] = $part;
                    }
                }
            }
            $infoCode = match (true) {
                $verification['simple'] => ['etat' => 'simple', 'message' => 'Code d\'une promotion : il s\'applique aux articles visés.', 'libelle' => null, 'remise' => 0, 'remiseTtc' => 0],
                null !== $refus => ['etat' => 'refus', 'message' => CalculCodePromo::raison($refus), 'libelle' => null, 'remise' => 0, 'remiseTtc' => 0],
                default => [
                    'etat' => 'ok',
                    'message' => null,
                    'libelle' => $codePromo?->getCodeAffiche().' · '.($codePromo?->getPromotion()?->getLibelleAvantageCommande() ?? $codePromo?->getPromotion()?->getNom()),
                    'remise' => $remise,
                    'remiseTtc' => $remiseTtc,
                ],
            };
        }
        foreach ($resultat as $cle => $r) {
            unset($resultat[$cle]['_promotion']);
        }

        return ['lignes' => $resultat, 'code' => $infoCode];
    }

    /**
     * L'avantage du code sur la commande pour l'aperçu, à partir des lignes
     * envoyées par le formulaire (total avant remise, unités facturables,
     * remise saisie, TVA) et de la promotion trouvée pour chacune.
     *
     * @param list<array<string, mixed>>        $lignes
     * @param array<string, array<string, mixed>> $resultat
     *
     * @return array{0: ?string, 1: int, 2: int, 3: array<string, int>}
     */
    private static function avantageApercu(Promotion $promotion, array $lignes, array $resultat): array
    {
        $total = 0;
        $bases = [];
        $tva = [];
        $surLesLignes = false;
        foreach ($lignes as $l) {
            $r = $resultat[$l['cle']] ?? null;
            $brut = max(0, (int) $l['brut']);
            $parUnite = max(0, (int) $l['remise']) + (null !== ($r['_promotion'] ?? null) ? (int) $r['remise'] : 0);
            $net = max(0, $brut - min($brut, (int) round($parUnite * max(0.0, (float) ($l['unites'] ?? 0)))));
            $total += $net;
            if (null === ($r['_promotion'] ?? null)) {
                $bases[$l['cle']] = $net;
                $tva[$l['cle']] = max(0, (int) ($l['tva'] ?? 0));
            } elseif ($r['_promotion']->getId() === $promotion->getId()) {
                $surLesLignes = true;
            }
        }
        if (!CalculCodePromo::minimumAtteint($promotion, $total)) {
            return ['minimum', 0, 0, []];
        }
        $montant = CalculCodePromo::montantCommande($promotion, array_sum($bases));
        if (0 === $montant && !$surLesLignes) {
            return ['sans_effet', 0, 0, []];
        }
        $parts = CalculCodePromo::repartir($montant, $bases);
        $ttc = static fn (int $ht, int $taux): int => $ht + (int) round($ht * $taux / 100);
        $remiseTtc = 0;
        foreach ($parts as $cle => $part) {
            $remiseTtc += $ttc($bases[$cle], $tva[$cle]) - $ttc($bases[$cle] - $part, $tva[$cle]);
        }

        return [null, $montant, $remiseTtc, $parts];
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
     * @param list<int>                           $debloquees promotions débloquées par un code généré vérifié
     */
    private function appliquerLignes(?Clients $client, array $lignes, ?int $commandeId, \DateTimeImmutable $jour, ?string $code, array $debloquees = []): bool
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
            $ctx = $this->contexte($client, $ligne, $jour, $segments, $code, $utilisations, self::acceptees($demandes[spl_object_id($ligne)] ?? []), $debloquees);
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
     * @param list<int>       $debloquees
     */
    private function contexte(Clients $client, CommandesDetails|DevisDetails $ligne, \DateTimeImmutable $jour, array $segments, ?string $code, array $utilisations, array $acceptees, array $debloquees = []): ContexteLigne
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
            $debloquees,
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
        $code = 0;
        foreach ($devis->getDevisDetails() as $ligne) {
            $ht += $ligne->getTotalHt();
            $ttc += $ligne->getTotalTtc();
            $code += $ligne->getRemiseCodePromo();
        }
        $devis->setTotalHt($ht);
        $devis->setRemiseCodePromo($code);
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
