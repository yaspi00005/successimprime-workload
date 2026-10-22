<?php

namespace App\Service\Fidelite;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteFidelite;
use App\Entity\Factures;
use App\Entity\JournalActivite;
use App\Entity\MouvementFidelite;
use App\Entity\Paiements;
use App\Entity\Produits;
use App\Entity\RegleFidelite;
use App\Entity\User;
use App\Service\Parametres\CatalogueParametres;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Programme de fidélité sur l'ERP local (docs/fidelite.md) : gains à la
 * validation d'un paiement, retrait quand le paiement ou la commande sont
 * annulés, points utilisés sur une commande, ajustements de la direction,
 * expiration. Le site en ligne ne calcule rien : il reçoit le relevé et le
 * résumé par la synchronisation.
 */
final class Fidelite
{
    public const ENTITE_JOURNAL = 'Fidelite';

    private ?ReglagesFidelite $reglages = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Parametres $parametres,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
    ) {
    }

    public function estEnLigne(): bool
    {
        return $this->enLigne;
    }

    /** @throws \DomainException sur le site en ligne, qui reçoit le relevé sans le changer */
    public function surLaBoutique(): void
    {
        if ($this->enLigne) {
            throw new \DomainException('La fidélité se gère sur l\'ERP de l\'imprimerie.');
        }
    }

    public function reglages(): ReglagesFidelite
    {
        if (null === $this->reglages) {
            $valeurs = [];
            foreach (CatalogueParametres::tuiles()['fidelite']['champs'] as $champ) {
                $valeurs[$champ['cle']] = $this->parametres->valeur('fidelite', $champ['cle']);
            }
            $this->reglages = ReglagesFidelite::depuis($valeurs);
        }

        return $this->reglages;
    }

    public function compte(Clients $client): CompteFidelite
    {
        $compte = $this->em->getRepository(CompteFidelite::class)->findOneBy(['client' => $client]);
        if (null === $compte) {
            foreach ($this->em->getUnitOfWork()->getScheduledEntityInsertions() as $e) {
                if ($e instanceof CompteFidelite && $e->getClient() === $client) {
                    return $e;
                }
            }
            $compte = (new CompteFidelite())->setClient($client);
            $this->em->persist($compte);
        }

        return $compte;
    }

    public static function commandeAnnulee(Commandes $c): bool
    {
        return $c->isDeleted() || 'annulee' === $c->getStatutTravaux();
    }

    /* ---------- Paiements : gains, retraits, points utilisés ---------- */

    /**
     * Met le relevé d'accord avec l'état du paiement. Rejouable : un
     * paiement déjà compté ne l'est pas deux fois. Ne flushe pas.
     *
     * @return bool vrai si le relevé a changé
     */
    public function traiterPaiement(Paiements $p, ?\DateTimeImmutable $le = null): bool
    {
        $commande = $p->getCommande();
        $client = $commande?->getClients();
        if ($this->enLigne || null === $commande || null === $client || null === $p->getId()) {
            return false;
        }
        if ($commande->estSoldeOuverture()) {
            // Régler ce qui était dû au démarrage ne rapporte pas de points (docs/import-donnees.md).
            return false;
        }
        $le ??= new \DateTimeImmutable();
        $annulee = self::commandeAnnulee($commande);

        if (Paiements::MODE_POINTS_FIDELITE === $p->getMode()) {
            return $this->traiterPaiementEnPoints($p, $client, $annulee, $le);
        }

        $actifs = $this->em->getRepository(MouvementFidelite::class)->findBy(['paiement' => $p, 'type' => MouvementFidelite::GAIN, 'annuleLe' => null]);
        $rapporte = CalculFidelite::paiementRapporte($this->reglages()->actif, $client->isB2B(), $p->getMode(), $p->estValide(), $annulee);

        if ([] !== $actifs && (!$p->estValide() || $annulee)) {
            $this->retirerGains($actifs, $annulee ? 'Commande annulée' : 'Paiement '.mb_strtolower($p->getStatutLabel()), $le);

            return true;
        }
        if ($rapporte && [] === $actifs) {
            return $this->creerGains($p, $commande, $client, $le) > 0;
        }

        return false;
    }

    /** Recalcule chaque paiement d'une commande (annulée, restaurée, supprimée). */
    public function traiterCommande(Commandes $c, ?\DateTimeImmutable $le = null): bool
    {
        $change = false;
        foreach ($c->getPaiements() as $p) {
            $change = $this->traiterPaiement($p, $le) || $change;
        }

        return $change;
    }

    /**
     * Un article annulé seul (la commande vit) perd ses points ; rétabli,
     * il les retrouve, au même multiplicateur. L'annulation de toute la
     * commande passe par traiterCommande(). Ne flushe pas.
     */
    public function traiterLigne(CommandesDetails $ligne, ?\DateTimeImmutable $le = null): bool
    {
        $commande = $ligne->getCommande();
        if ($this->enLigne || null === $commande || null === $ligne->getId() || self::commandeAnnulee($commande)) {
            return false;
        }
        $le ??= new \DateTimeImmutable();
        $depot = $this->em->getRepository(MouvementFidelite::class);
        $gains = $depot->findBy(['ligne' => $ligne, 'type' => MouvementFidelite::GAIN], ['id' => 'ASC']);
        $actifs = array_values(array_filter($gains, static fn (MouvementFidelite $m): bool => !$m->estAnnule()));

        if (CommandesDetails::PRODUCTION_ANNULEE === $ligne->getStatutProduction()) {
            if ([] === $actifs) {
                return false;
            }
            $this->retirerGains($actifs, 'Article annulé', $le);

            return true;
        }

        // Rétabli : un gain repris par paiement encore validé qui n'en a plus.
        $parPaiement = [];
        foreach ($gains as $g) {
            $p = $g->getPaiement();
            if (null !== $p && $p->estValide() && null === ($parPaiement[$p->getId()] ?? null)) {
                $parPaiement[$p->getId()] = $g->estAnnule() ? $g : false;
            }
            if (null !== $p && !$g->estAnnule()) {
                $parPaiement[$p->getId()] = false;
            }
        }
        $change = false;
        $r = $this->reglages();
        foreach (array_filter($parPaiement) as $g) {
            $this->em->persist((new MouvementFidelite())
                ->setClient($g->getClient())->setType(MouvementFidelite::GAIN)->setPoints($g->getPoints())
                ->setValeurPoint($g->getValeurPoint())->setFamille($g->getFamille())->setProduit($g->getProduit())
                ->setLigne($ligne)->setCommande($commande)->setPaiement($g->getPaiement())
                ->setM2(MouvementFidelite::FAMILLE_M2 === $g->getFamille() ? $g->getM2() : null)->setPieces($g->getPieces())->setMontant($g->getMontant())
                ->setMultiplicateur($g->getMultiplicateur())->setRegle($g->getRegle())
                ->setDate($le)->setExpireLe($g->getPoints() > 0 ? CalculFidelite::expiration($le, $r) : null)
                ->setMotif('Article rétabli'));
            $change = true;
        }

        return $change;
    }

    /**
     * Gains d'un paiement validé : une ligne du relevé par ligne de
     * commande, même à 0 point, pour garder la progression.
     */
    private function creerGains(Paiements $p, Commandes $commande, Clients $client, \DateTimeImmutable $le): int
    {
        $r = $this->reglages();
        $lignes = [];
        $entites = [];
        $total = 0;
        foreach ($commande->getCommandesDetails() as $ligne) {
            if (CommandesDetails::PRODUCTION_ANNULEE === $ligne->getStatutProduction()) {
                continue;
            }
            $d = CalculFidelite::ligne($ligne);
            $lignes[] = $d;
            $entites[(int) $d['id']] = $ligne;
            $total += $d['montant'];
        }

        // Ce que les autres paiements de la commande ont déjà compté.
        $deja = (int) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(m.montant), 0)')->from(MouvementFidelite::class, 'm')
            ->where('m.commande = :c')->andWhere('m.type = :gain')->andWhere('m.annuleLe IS NULL')->andWhere('m.paiement != :p')
            ->setParameter('c', $commande)->setParameter('gain', MouvementFidelite::GAIN)->setParameter('p', $p)
            ->getQuery()->getSingleScalarResult();
        $part = CalculFidelite::part($p->getMontant(), $total, $deja);
        if ($part <= 0) {
            return 0;
        }

        $this->verrouiller($client);
        $jour = $p->getDate() ?? $le;
        $multiplicateur = CalculFidelite::multiplicateur($jour, $this->reference($commande), $r);
        $compteurs = $this->compteurs($client);
        $produits = array_values(array_filter(array_unique(array_column($lignes, 'produit'))));
        $gains = CalculFidelite::gains($lignes, $part, $multiplicateur, $this->compte($client)->valeursPropres(), $this->reglesParProduit($produits), $r, $jour, $compteurs);

        foreach ($gains as $g) {
            $ligne = $entites[(int) $g['ligne']] ?? null;
            $this->em->persist((new MouvementFidelite())
                ->setClient($client)
                ->setType(MouvementFidelite::GAIN)
                ->setPoints($g['points'])
                ->setValeurPoint($g['valeurPoint'])
                ->setFamille($g['famille'])
                ->setProduit($ligne?->getProduit())
                ->setLigne($ligne)
                ->setCommande($commande)
                ->setPaiement($p)
                ->setM2(MouvementFidelite::FAMILLE_M2 === $g['famille'] ? $g['m2'] : null)
                ->setPieces($g['pieces'])
                ->setMontant($g['montant'])
                ->setMultiplicateur($multiplicateur)
                ->setRegle($g['regle'])
                ->setDate($le)
                ->setExpireLe($g['points'] > 0 ? CalculFidelite::expiration($le, $r) : null));
        }

        return \count($gains);
    }

    /**
     * Retire des gains : une ligne « annulation » par gain qui avait
     * rapporté, et le gain ne compte plus pour la progression.
     *
     * @param list<MouvementFidelite> $gains
     */
    private function retirerGains(array $gains, string $motif, \DateTimeImmutable $le): void
    {
        foreach ($gains as $g) {
            $g->setAnnuleLe($le);
            if (0 === $g->getPoints()) {
                continue;
            }
            $this->em->persist((new MouvementFidelite())
                ->setClient($g->getClient())
                ->setType(MouvementFidelite::ANNULATION)
                ->setPoints(-$g->getPoints())
                ->setValeurPoint($g->getValeurPoint())
                ->setFamille($g->getFamille())
                ->setProduit($g->getProduit())
                ->setCommande($g->getCommande())
                ->setPaiement($g->getPaiement())
                ->setCible($g)
                ->setDate($le)
                ->setMotif($motif));
        }
    }

    /**
     * Paiement en points : les points sont pris tant que le paiement est
     * validé sur une commande vivante ; sinon ils sont rendus, avec leur
     * date d'expiration d'origine.
     */
    private function traiterPaiementEnPoints(Paiements $p, Clients $client, bool $commandeAnnulee, \DateTimeImmutable $le): bool
    {
        $lignes = $this->em->getRepository(MouvementFidelite::class)->findBy(['paiement' => $p, 'type' => MouvementFidelite::UTILISATION], ['id' => 'ASC']);
        $actives = array_values(array_filter($lignes, static fn (MouvementFidelite $m): bool => !$m->estAnnule()));
        $doitConsommer = $p->estValide() && !$commandeAnnulee;

        if (!$doitConsommer && [] !== $actives) {
            $etat = $this->etat($client, $le);
            foreach ($actives as $u) {
                $this->rendre($u, $etat['consommes'][$u->getId()] ?? [], $commandeAnnulee ? 'Commande annulée' : 'Points retirés de la commande', $le);
            }

            return true;
        }
        if ($doitConsommer && [] === $actives && [] !== $lignes) {
            // Commande restaurée : les points sont repris.
            $derniere = end($lignes);
            $this->em->persist((new MouvementFidelite())
                ->setClient($client)->setType(MouvementFidelite::UTILISATION)->setPoints($derniere->getPoints())
                ->setMontant($derniere->getMontant())->setCommande($p->getCommande())->setPaiement($p)
                ->setDate($le)->setMotif('Commande restaurée'));

            return true;
        }

        return false;
    }

    /**
     * Rend des points utilisés : un lot par lot consommé, avec sa date
     * d'expiration (s'il est déjà passé, il expirera au prochain passage).
     *
     * @param list<array{lot: int, points: int, expireLe: ?\DateTimeImmutable}> $consommes
     */
    private function rendre(MouvementFidelite $u, array $consommes, string $motif, \DateTimeImmutable $le): void
    {
        $u->setAnnuleLe($le);
        $r = $this->reglages();
        $lots = [] === $consommes ? [['lot' => 0, 'points' => -$u->getPoints(), 'expireLe' => CalculFidelite::expiration($le, $r)]] : $consommes;
        foreach ($lots as $c) {
            $lot = $c['lot'] > 0 ? $this->em->find(MouvementFidelite::class, $c['lot']) : null;
            $this->em->persist((new MouvementFidelite())
                ->setClient($u->getClient())
                ->setType(MouvementFidelite::ANNULATION)
                ->setPoints($c['points'])
                ->setValeurPoint($lot?->getValeurPoint() ?? 1)
                ->setCommande($u->getCommande())
                ->setPaiement($u->getPaiement())
                ->setCible($u)
                ->setDate($le)
                ->setExpireLe($c['expireLe'] ?? CalculFidelite::expiration($le, $r))
                ->setMotif($motif));
        }
    }

    /**
     * Utilise des points sur une commande (1 point = sa valeur en francs),
     * sans dépasser le reste à payer : un paiement « Points fidélité »
     * validé, et la ligne au relevé. Flushe.
     *
     * @throws \DomainException message à montrer
     */
    public function utiliser(Commandes $c, int $pointsVoulus, User $agent, \DateTimeImmutable $maintenant): Paiements
    {
        $client = $c->getClients() ?? throw new \DomainException('Commande sans client.');
        $this->surLaBoutique();
        if ($c->estSoldeOuverture()) {
            throw new \DomainException('Les points ne règlent pas un solde d\'ouverture.');
        }
        if ($client->isB2B()) {
            throw new \DomainException('Client au prix B2B : hors programme de fidélité.');
        }
        if (self::commandeAnnulee($c)) {
            throw new \DomainException('Cette commande est annulée.');
        }
        if (!$c->isStatut()) {
            throw new \DomainException('Confirmez d\'abord la commande.');
        }
        $reste = max(0, $c->getTotalTtc() - $c->getTotalPaye());
        if ($reste <= 0) {
            throw new \DomainException('Cette commande est déjà payée.');
        }

        $this->verrouiller($client);
        $this->expirer($client, $maintenant);
        $this->em->flush();
        $etat = $this->etat($client, $maintenant);
        $plan = CalculFidelite::planUtilisation($etat['lots'], min($pointsVoulus, max(0, $etat['disponible'])), $reste);
        if ($plan['points'] <= 0) {
            throw new \DomainException('Aucun point disponible pour cette commande.');
        }

        $paiement = (new Paiements())
            ->setCommande($c)
            ->setEncaissePar($agent)
            ->setDate($maintenant)
            ->setMode(Paiements::MODE_POINTS_FIDELITE)
            ->setMontant($plan['montant'])
            ->setReference('FIDELITE')
            ->setObservation(\sprintf('%s points de fidélité utilisés.', number_format($plan['points'], 0, ',', ' ')));
        $paiement->validerPar($agent);
        $c->addPaiement($paiement);
        $this->actualiserCommande($c);

        $facture = $this->em->getRepository(Factures::class)->findOneBy(['commande' => $c, 'comptabilisee' => true]);
        if (null !== $facture) {
            $facture->synchroniserPaiementsDepuisCommande();
            $paiement->setFacture($facture);
        }

        $this->em->persist($paiement);
        $this->em->persist((new MouvementFidelite())
            ->setClient($client)
            ->setType(MouvementFidelite::UTILISATION)
            ->setPoints(-$plan['points'])
            ->setMontant($plan['montant'])
            ->setCommande($c)
            ->setPaiement($paiement)
            ->setAgent($agent)
            ->setDate($maintenant));
        $this->em->flush();
        $this->actualiser($client, $maintenant);
        $this->em->flush();

        return $paiement;
    }

    /** Retire les points utilisés sur une commande : ils sont rendus au client. Flushe. */
    public function retirer(Paiements $p, User $agent, \DateTimeImmutable $maintenant): void
    {
        $this->surLaBoutique();
        if (Paiements::MODE_POINTS_FIDELITE !== $p->getMode() || !$p->estValide()) {
            throw new \DomainException('Ce paiement n\'est pas un paiement en points.');
        }
        $p->annuler('Points retirés de la commande', $agent);
        $commande = $p->getCommande();
        if (null !== $commande) {
            $this->actualiserCommande($commande);
            $facture = $this->em->getRepository(Factures::class)->findOneBy(['commande' => $commande, 'comptabilisee' => true]);
            $facture?->synchroniserPaiementsDepuisCommande();
        }
        // Le relevé suit à l'enregistrement (FideliteSubscriber).
        $this->em->flush();
    }

    private function actualiserCommande(Commandes $c): void
    {
        $c->setTotalPaye($c->getTotalPaye());
        $c->setResteAPayer(max(0, $c->getTotalTtc() - $c->getTotalPaye()));
        $c->actualiserStatutPaiement();
    }

    /* ---------- Direction : ajustements et valeurs d'un gros client ---------- */

    /**
     * Ajoute ou retire des points à la main, avec le motif. Flushe.
     *
     * @throws \DomainException
     */
    public function ajuster(Clients $client, int $points, string $motif, User $agent, \DateTimeImmutable $maintenant): MouvementFidelite
    {
        $this->surLaBoutique();
        $motif = trim($motif);
        if (0 === $points) {
            throw new \DomainException('Indiquez un nombre de points, positif ou négatif.');
        }
        if (mb_strlen($motif) < 3) {
            throw new \DomainException('Le motif est obligatoire.');
        }
        if ($client->isB2B()) {
            throw new \DomainException('Client au prix B2B : hors programme de fidélité.');
        }
        $this->verrouiller($client);
        $this->expirer($client, $maintenant);
        $this->em->flush();
        if ($points < 0 && -$points > max(0, $this->etat($client, $maintenant)['disponible'])) {
            throw new \DomainException('Le client n\'a pas autant de points.');
        }

        $r = $this->reglages();
        $m = (new MouvementFidelite())
            ->setClient($client)
            ->setType(MouvementFidelite::AJUSTEMENT)
            ->setPoints($points)
            ->setValeurPoint($r->valeurPoint)
            ->setDate($maintenant)
            ->setExpireLe($points > 0 ? CalculFidelite::expiration($maintenant, $r) : null)
            ->setAgent($agent)
            ->setMotif($motif);
        $this->em->persist($m);
        $this->journaliser($client, 'ajustement', null, ['points' => $points, 'motif' => $motif], $agent);
        $this->em->flush();
        $this->actualiser($client, $maintenant);
        $this->em->flush();

        return $m;
    }

    /**
     * Valeurs propres d'un gros client (null : valeur générale). Flushe.
     *
     * @param array{pointsParM2: ?float, tauxAutre: ?float, seuilM2: ?float, seuilMontant: ?int} $valeurs
     */
    public function personnaliser(Clients $client, array $valeurs, string $motif, User $agent, \DateTimeImmutable $maintenant): void
    {
        $this->surLaBoutique();
        $compte = $this->compte($client);
        $avant = $compte->valeursPropres();
        $compte->personnaliser($valeurs['pointsParM2'], $valeurs['tauxAutre'], $valeurs['seuilM2'], $valeurs['seuilMontant'], $motif, $agent, $maintenant);
        $apres = $compte->valeursPropres();
        if ($avant == $apres) {
            return;
        }
        $this->journaliser($client, 'valeurs_client', $avant, $apres + ['motif' => trim($motif)], $agent);
        $this->em->flush();
        $this->actualiser($client, $maintenant);
        $this->em->flush();
    }

    /* ---------- Relevé, solde et expiration ---------- */

    /** @return list<MouvementFidelite> du plus récent au plus ancien */
    public function mouvements(Clients $client, ?int $max = null): array
    {
        return $this->em->getRepository(MouvementFidelite::class)->findBy(['client' => $client], ['date' => 'DESC', 'id' => 'DESC'], $max);
    }

    /** @return array<string, mixed> voir CalculFidelite::etat() */
    public function etat(Clients $client, \DateTimeImmutable $maintenant): array
    {
        $donnees = $this->em->createQueryBuilder()
            ->select('m.id', 'm.type', 'm.points', 'm.date', 'm.expireLe', 'm.valeurPoint', 'IDENTITY(m.cible) AS cible')
            ->from(MouvementFidelite::class, 'm')
            ->where('m.client = :c')->andWhere('m.points != 0')
            ->setParameter('c', $client)
            ->getQuery()->getArrayResult();
        $donnees = array_map(static fn (array $d): array => ['cible' => null === $d['cible'] ? null : (int) $d['cible']] + $d, $donnees);

        return CalculFidelite::etat($donnees, $maintenant, $this->reglages()->alerteJours);
    }

    /** @return array<string, float> progression : gains actifs (paiements validés de commandes vivantes) */
    public function compteurs(Clients $client): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('m.famille', 'IDENTITY(m.produit) AS produit', 'm.m2', 'm.pieces', 'm.montant')
            ->from(MouvementFidelite::class, 'm')
            ->where('m.client = :c')->andWhere('m.type = :gain')->andWhere('m.annuleLe IS NULL')
            ->setParameter('c', $client)->setParameter('gain', MouvementFidelite::GAIN)
            ->getQuery()->getArrayResult();
        $compteurs = [CalculFidelite::CLE_M2 => 0.0, CalculFidelite::CLE_AUTRE => 0.0];
        foreach ($lignes as $l) {
            CalculFidelite::compter($compteurs, (string) $l['famille'], null === $l['produit'] ? null : (int) $l['produit'], (float) $l['m2'], (float) $l['pieces'], (int) $l['montant']);
        }

        return $compteurs;
    }

    /** Écrit l'expiration des lots arrivés à échéance. Ne flushe pas. */
    public function expirer(Clients $client, \DateTimeImmutable $maintenant): int
    {
        $etat = $this->etat($client, $maintenant);
        foreach ($etat['aExpirer'] as $lot) {
            $this->em->persist((new MouvementFidelite())
                ->setClient($client)
                ->setType(MouvementFidelite::EXPIRATION)
                ->setPoints(-$lot['restant'])
                ->setCible($this->em->getReference(MouvementFidelite::class, $lot['id']))
                ->setDate($lot['expireLe']));
        }

        return \count($etat['aExpirer']);
    }

    /** Recalcule le résumé envoyé au site. Ne flushe pas. */
    public function actualiser(Clients $client, \DateTimeImmutable $maintenant): CompteFidelite
    {
        $compte = $this->compte($client);
        $resume = $this->resumeChiffre($client, $compte, $maintenant);
        $compte->majResume([
            'exclu' => $resume['exclu'],
            'solde' => $resume['disponible'],
            'soldeF' => $resume['disponibleF'],
            'm2' => $resume['m2']['cumule'],
            'montant' => (int) $resume['autre']['cumule'],
            'seuilM2' => $resume['m2']['seuil'],
            'seuilMontant' => (int) $resume['autre']['seuil'],
            'pointsParM2' => $resume['m2']['taux'],
            'tauxAutre' => $resume['autre']['taux'],
            'multiplicateurJour' => $this->reglages()->multiplicateurJour,
            'delaiHeures' => $this->reglages()->delaiHeures,
            'expirant' => $resume['expirant'],
            'expiration' => $resume['prochaineExpiration'],
        ], $maintenant);

        return $compte;
    }

    /**
     * Chiffres du client : solde, progression vers chaque seuil, valeurs
     * qui s'appliquent et d'où elles viennent.
     *
     * @return array<string, mixed>
     */
    public function resumeChiffre(Clients $client, ?CompteFidelite $compte, \DateTimeImmutable $maintenant): array
    {
        $r = $this->reglages();
        $propres = $compte?->valeursPropres() ?? ['pointsParM2' => null, 'tauxAutre' => null, 'seuilM2' => null, 'seuilMontant' => null];
        $etat = $this->etat($client, $maintenant);
        $compteurs = $this->compteurs($client);
        $famille = static fn (float $cumule, float $seuil, float $taux, bool $propreTaux, bool $propreSeuil): array => [
            'cumule' => $cumule, 'seuil' => $seuil, 'taux' => $taux,
            'eligible' => $cumule >= $seuil && ($seuil > 0 || $cumule > 0),
            'part' => $seuil > 0 ? min(100, (int) floor($cumule * 100 / $seuil)) : 100,
            'propre' => $propreTaux || $propreSeuil,
        ];

        return [
            'exclu' => $client->isB2B(),
            'actif' => $r->actif,
            'disponible' => $etat['disponible'],
            'disponibleF' => $etat['disponibleF'],
            'dette' => $etat['dette'],
            'expirant' => $etat['expirant'],
            'prochaineExpiration' => $etat['prochaineExpiration'],
            'm2' => $famille((float) $compteurs[CalculFidelite::CLE_M2], $propres['seuilM2'] ?? $r->seuilM2, $propres['pointsParM2'] ?? $r->pointsParM2, null !== $propres['pointsParM2'], null !== $propres['seuilM2']),
            'autre' => $famille((float) $compteurs[CalculFidelite::CLE_AUTRE], (float) ($propres['seuilMontant'] ?? $r->seuilMontant), $propres['tauxAutre'] ?? $r->tauxAutre, null !== $propres['tauxAutre'], null !== $propres['seuilMontant']),
            'propres' => $propres,
            'reglages' => $r,
        ];
    }

    /**
     * Le relevé à montrer : les gains d'un même paiement réunis sur une
     * ligne. Pour le client ($public), ni agent ni motif, et pas les lignes
     * à 0 point.
     *
     * @param list<MouvementFidelite> $mouvements du plus récent au plus ancien
     *
     * @return list<array{date: \DateTimeImmutable, type: string, libelle: string, points: int, commande: ?Commandes, multiplicateur: ?int, expireLe: ?\DateTimeImmutable, motif: ?string, agent: ?User, annule: bool, lignes: int}>
     */
    public static function historique(array $mouvements, bool $public, int $max = 40, int $delaiHeures = 72): array
    {
        $entrees = [];
        foreach ($mouvements as $m) {
            if ($public && 0 === $m->getPoints()) {
                continue;
            }
            $cle = MouvementFidelite::GAIN === $m->getType()
                ? 'g|'.($m->getPaiement()?->getId() ?? $m->getCommande()?->getId()).'|'.$m->getDate()->format('YmdHi').'|'.$m->getMultiplicateur()
                : 'm|'.($m->getId() ?? spl_object_id($m));
            if (isset($entrees[$cle])) {
                $entrees[$cle]['points'] += $m->getPoints();
                ++$entrees[$cle]['lignes'];
                $entrees[$cle]['annule'] = $entrees[$cle]['annule'] && $m->estAnnule();
                continue;
            }
            $entrees[$cle] = [
                'date' => $m->getDate(),
                'type' => $m->getType(),
                'libelle' => self::libelle($m, $delaiHeures),
                'points' => $m->getPoints(),
                'commande' => $m->getCommande(),
                'multiplicateur' => $m->getMultiplicateur(),
                'expireLe' => $m->getExpireLe(),
                'motif' => $public ? null : $m->getMotif(),
                'agent' => $public ? null : $m->getAgent(),
                'annule' => $m->estAnnule(),
                'lignes' => 1,
            ];
        }

        return \array_slice(array_values($entrees), 0, $max);
    }

    public static function libelle(MouvementFidelite $m, int $delaiHeures = 72): string
    {
        return match ($m->getType()) {
            MouvementFidelite::GAIN => match ($m->getMultiplicateur()) {
                0 => 'Payé après le délai',
                1 => 'Payé dans les '.$delaiHeures.' h',
                null => 'Points gagnés',
                default => 'Payé le jour même ×'.$m->getMultiplicateur(),
            },
            MouvementFidelite::UTILISATION => 'Utilisés sur la commande',
            MouvementFidelite::AJUSTEMENT => $m->getPoints() > 0 ? 'Points offerts' : 'Points retirés',
            MouvementFidelite::EXPIRATION => 'Points expirés',
            MouvementFidelite::ANNULATION => $m->getPoints() > 0 ? 'Points rendus' : 'Points retirés (annulation)',
            default => $m->getTypeLibelle(),
        };
    }

    /**
     * Ce qu'un paiement du reste aujourd'hui rapporterait (bloc Fidélité
     * de la commande) : de quoi dire au client « payez aujourd'hui ».
     *
     * @return array{points: int, multiplicateur: int, reste: int}|null
     */
    public function estimer(Commandes $c, \DateTimeImmutable $maintenant): ?array
    {
        $client = $c->getClients();
        $r = $this->reglages();
        if (null === $client || $client->isB2B() || !$r->actif || self::commandeAnnulee($c)) {
            return null;
        }
        $reste = max(0, $c->getTotalTtc() - $c->getTotalPaye());
        $lignes = [];
        $total = 0;
        foreach ($c->getCommandesDetails() as $ligne) {
            if (CommandesDetails::PRODUCTION_ANNULEE !== $ligne->getStatutProduction()) {
                $lignes[] = $d = CalculFidelite::ligne($ligne);
                $total += $d['montant'];
            }
        }
        if ($reste <= 0 || $total <= 0) {
            return null;
        }
        $multiplicateur = CalculFidelite::multiplicateur($maintenant, $this->reference($c), $r);
        $compteurs = $this->compteurs($client);
        $produits = array_values(array_filter(array_unique(array_column($lignes, 'produit'))));
        $compte = $this->em->getRepository(CompteFidelite::class)->findOneBy(['client' => $client]);
        $gains = CalculFidelite::gains($lignes, min(1.0, $reste / $total), $multiplicateur, $compte?->valeursPropres() ?? [], $this->reglesParProduit($produits), $r, $maintenant, $compteurs);

        return ['points' => array_sum(array_column($gains, 'points')), 'multiplicateur' => $multiplicateur, 'reste' => $reste];
    }

    /**
     * Rejoue les paiements validés depuis une date (début du programme,
     * démonstration) : les gains, leurs retraits et les expirations sont
     * refaits ; les points utilisés et les ajustements restent. Flushe.
     *
     * @return array{paiements: int, clients: int}
     */
    public function recalculer(\DateTimeImmutable $depuis, \DateTimeImmutable $maintenant, ?Clients $seul = null): array
    {
        $types = [MouvementFidelite::GAIN, MouvementFidelite::EXPIRATION];
        $qb = $this->em->createQueryBuilder()->select('m')->from(MouvementFidelite::class, 'm')
            ->where('m.type IN (:t) OR (m.type = :annulation AND m.points < 0)')
            ->setParameter('t', $types)->setParameter('annulation', MouvementFidelite::ANNULATION)
            ->orderBy('m.id', 'DESC');
        if (null !== $seul) {
            $qb->andWhere('m.client = :c')->setParameter('c', $seul);
        }
        foreach ($qb->getQuery()->getResult() as $m) {
            $this->em->remove($m);
        }
        $this->em->flush();

        $qb = $this->em->createQueryBuilder()->select('p')->from(Paiements::class, 'p')
            ->join('p.commande', 'c')->join('c.clients', 'cl')
            ->where('p.statut = :valide')->andWhere('p.mode NOT IN (:sans)')->andWhere('p.date >= :depuis')
            ->andWhere('cl.typeClient != :b2b')
            ->setParameter('valide', Paiements::STATUT_VALIDE)->setParameter('sans', [Paiements::MODE_POINTS_FIDELITE])
            ->setParameter('depuis', $depuis)->setParameter('b2b', 'B2B')
            ->orderBy('p.date', 'ASC')->addOrderBy('p.id', 'ASC');
        if (null !== $seul) {
            $qb->andWhere('cl = :c')->setParameter('c', $seul);
        }
        $paiements = $qb->getQuery()->getResult();
        $clients = [];
        foreach ($paiements as $p) {
            $le = $p->getDateValidation() ?? $p->getDate();
            // Rejoué à la date où il a été validé (au plus tôt celle du paiement).
            if (null !== $p->getDate() && $le < $p->getDate()) {
                $le = $p->getDate();
            }
            if ($this->traiterPaiement($p, $le)) {
                $clients[$p->getCommande()->getClients()->getId()] = $p->getCommande()->getClients();
                $this->em->flush();
            }
        }
        foreach ($clients as $client) {
            $this->expirer($client, $maintenant);
            $this->em->flush();
            $this->actualiser($client, $maintenant);
        }
        $this->em->flush();

        return ['paiements' => \count($paiements), 'clients' => \count($clients)];
    }

    /**
     * Ce que voit le client (et la fiche sur le site en ligne) : le résumé
     * reçu par la synchronisation, sans rien recalculer.
     *
     * @return array<string, mixed>
     */
    public static function resumeEspace(?CompteFidelite $compte): array
    {
        $compte ??= new CompteFidelite();
        $famille = static fn (float $cumule, float $seuil, float $taux): array => [
            'cumule' => $cumule, 'seuil' => $seuil, 'taux' => $taux,
            'eligible' => $cumule >= $seuil && ($seuil > 0 || $cumule > 0),
            'part' => $seuil > 0 ? min(100, (int) floor($cumule * 100 / $seuil)) : 100,
        ];

        return [
            'exclu' => $compte->isExclu(),
            'disponible' => $compte->getSolde(),
            'disponibleF' => $compte->getSoldeF(),
            'expirant' => $compte->getPointsExpirant(),
            'prochaineExpiration' => $compte->getExpirationProchaine(),
            'm2' => $famille($compte->getM2Cumules(), $compte->getSeuilM2Applique(), $compte->getPointsParM2Applique()),
            'autre' => $famille((float) $compte->getMontantCumule(), (float) $compte->getSeuilMontantApplique(), $compte->getTauxAutreApplique()),
            'multiplicateurJour' => $compte->getMultiplicateurJour(),
            'delaiHeures' => $compte->getDelaiHeures(),
            'majLe' => null === $compte->getId() ? null : $compte->getMajLe(),
        ];
    }

    /**
     * Remet d'accord le relevé et les paiements depuis une date (après un
     * incident) : chaque paiement est recompté s'il ne l'a pas été, retiré
     * s'il a été annulé. Ne refait rien de ce qui est juste. Flushe.
     *
     * @return array{paiements: int, corriges: int}
     */
    public function rattraper(\DateTimeImmutable $depuis, \DateTimeImmutable $maintenant): array
    {
        $paiements = $this->em->createQueryBuilder()->select('p')->from(Paiements::class, 'p')
            ->join('p.commande', 'c')->join('c.clients', 'cl')
            ->where('p.date >= :depuis OR p.dateValidation >= :depuis OR p.dateAnnulation >= :depuis')
            ->andWhere('cl.typeClient != :b2b')
            ->setParameter('depuis', $depuis)->setParameter('b2b', 'B2B')
            ->orderBy('p.date', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();
        $clients = [];
        $corriges = 0;
        foreach ($paiements as $p) {
            if ($this->traiterPaiement($p, $maintenant)) {
                ++$corriges;
                $clients[$p->getCommande()->getClients()->getId()] = $p->getCommande()->getClients();
                $this->em->flush();
            }
        }
        foreach ($clients as $client) {
            $this->actualiser($client, $maintenant);
        }
        $this->em->flush();

        return ['paiements' => \count($paiements), 'corriges' => $corriges];
    }

    /**
     * Passage du jour : écrit les expirations et met à jour le résumé de
     * chaque particulier qui a un relevé. Flushe.
     *
     * @return array{clients: int, expires: int}
     */
    public function passageDuJour(\DateTimeImmutable $maintenant): array
    {
        $ids = $this->em->createQueryBuilder()->select('DISTINCT IDENTITY(m.client) AS id')->from(MouvementFidelite::class, 'm')
            ->getQuery()->getSingleColumnResult();
        $expires = 0;
        foreach (array_chunk($ids, 100) as $lot) {
            foreach ($this->em->getRepository(Clients::class)->findBy(['id' => $lot]) as $client) {
                $expires += $this->expirer($client, $maintenant);
                $this->em->flush();
                $this->actualiser($client, $maintenant);
            }
            $this->em->flush();
        }

        return ['clients' => \count($ids), 'expires' => $expires];
    }

    /* ---------- Outils ---------- */

    /** Date de référence du délai : la facture, sinon la commande. */
    public function reference(Commandes $c): \DateTimeImmutable
    {
        $reference = null;
        foreach ($c->getFactures() as $f) {
            if ($f->estFacture() && !$f->estAnnulee() && null !== $f->getDateFacture() && (null === $reference || $f->getDateFacture() < $reference)) {
                $reference = $f->getDateFacture();
            }
        }

        return $reference ?? $c->getDateCommande() ?? new \DateTimeImmutable();
    }

    /**
     * @param list<int> $produits
     *
     * @return array<int, list<RegleFidelite>>
     */
    public function reglesParProduit(array $produits): array
    {
        if ([] === $produits) {
            return [];
        }
        $regles = [];
        foreach ($this->em->getRepository(RegleFidelite::class)->findBy(['produit' => $produits]) as $r) {
            $regles[(int) $r->getProduit()?->getId()][] = $r;
        }

        return $regles;
    }

    /** Deux paiements du même client validés en même temps ne comptent pas le même seuil. */
    private function verrouiller(Clients $client): void
    {
        $connexion = $this->em->getConnection();
        if ($connexion->isTransactionActive() && null !== $client->getId()) {
            $connexion->executeQuery('SELECT id FROM compte_fidelite WHERE client_id = ? FOR UPDATE', [$client->getId()]);
        }
    }

    public function journaliser(Clients $client, string $action, ?array $avant, ?array $apres, ?User $par): void
    {
        $this->em->persist((new JournalActivite())
            ->setEntite(self::ENTITE_JOURNAL)
            ->setEntiteId((int) $client->getId())
            ->setAction($action)
            ->setDonneesAvant($avant)
            ->setDonneesApres($apres)
            ->setUtilisateur($par));
    }

    public function produit(int $id): ?Produits
    {
        return $this->em->find(Produits::class, $id);
    }
}
