<?php

namespace App\Service\Promotions;

use App\Entity\Clients;
use App\Entity\CodePromo;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\Devis;
use App\Entity\Promotion;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Codes promo générés (docs/promotions.md, « Codes promo ») : création
 * d'un code partagé ou d'un lot de codes uniques, désactivation, journal,
 * et vérification d'un code tapé pour un devis ou une commande.
 *
 * Une utilisation, c'est un devis vivant pas encore transformé, ou une
 * commande vivante (ni supprimée, ni annulée), qui porte le code. Le
 * compte se fait toujours sans le document en cours : l'enregistrer de
 * nouveau ne compte pas deux fois. Au local, un verrou par code
 * (VerrousCodesPromo) empêche deux postes de prendre la dernière
 * utilisation en même temps.
 *
 * En ligne, le code se reconnaît à son empreinte, et le compte part de
 * celui du local (synchronisé) plus les commandes en ligne pas encore
 * confirmées qui l'ont tapé. La boutique revérifie à la confirmation.
 */
final class CodesPromo
{
    /** Devis qui retiennent encore leur code (ni refusé, ni expiré, ni annulé, ni transformé). */
    private const DEVIS_VIVANTS = [Devis::STATUT_BROUILLON, Devis::STATUT_ENVOYE, Devis::STATUT_VU, Devis::STATUT_ACCEPTE];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Promotions $promotions,
        private readonly VerrousCodesPromo $verrous,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne,
        #[Autowire('%env(SYNC_CLE)%')] private readonly string $cle,
    ) {
    }

    /**
     * Sans clé de synchronisation, pas d'empreinte : le code ne part pas au
     * site (CatalogueSynchro, « pret ») ; la tâche du matin la pose une fois
     * la clé réglée. Une empreinte sans clé se retrouverait trop facilement.
     */
    public function empreinte(string $canonique): string
    {
        return '' === $this->cle ? '' : CalculCodePromo::empreinte($canonique, $this->cle);
    }

    /* ---------- Création ---------- */

    /**
     * Un code partagé : tapé par l'équipe, ou tiré au hasard si vide.
     *
     * @throws \DomainException
     */
    public function creerPartage(Promotion $p, string $saisi, int $max, int $parClient, ?\DateTimeImmutable $debut, ?\DateTimeImmutable $expiration, User $par, \DateTimeImmutable $maintenant): CodePromo
    {
        [$debut, $expiration] = $this->verifierCreation($p, $debut, $expiration, $maintenant);
        if ($max < 1) {
            throw new \DomainException('Indiquez combien de fois le code peut servir en tout.');
        }
        if ($parClient < 1 || $parClient > $max) {
            throw new \DomainException('Par client : de 1 au nombre d\'utilisations en tout.');
        }
        $code = CalculCodePromo::canonique($saisi);
        if ('' === $code) {
            $code = $this->codesLibres(1)[0];
        } else {
            if (null !== ($erreur = CalculCodePromo::erreurCodePartage($code))) {
                throw new \DomainException($erreur);
            }
            if (!$this->estLibre($code)) {
                throw new \DomainException('Ce code est déjà pris : choisissez-en un autre.');
            }
        }
        $c = (CodePromo::creer($p, CodePromo::TYPE_PARTAGE, $code, $this->empreinte($code), $expiration, $maintenant))
            ->setDebut($debut)->setUtilisationsMax($max)->setUtilisationsParClient($parClient)->setCreePar($par);
        $this->em->persist($c);
        $p->marquerACodes();
        $this->em->flush();
        $this->promotions->journaliser(Promotions::ENTITE_JOURNAL, (int) $p->getId(), 'code_cree', null, $c->instantane(), $par);
        $this->em->flush();

        return $c;
    }

    /**
     * Un lot de codes uniques, chacun pour une seule utilisation. Rend le
     * nom du lot (L1, L2…).
     *
     * @throws \DomainException
     */
    public function genererLot(Promotion $p, int $nombre, ?\DateTimeImmutable $debut, ?\DateTimeImmutable $expiration, User $par, \DateTimeImmutable $maintenant): string
    {
        [$debut, $expiration] = $this->verifierCreation($p, $debut, $expiration, $maintenant);
        if ($nombre < 1 || $nombre > CalculCodePromo::LOT_MAX) {
            throw new \DomainException(\sprintf('Un lot fait de 1 à %d codes.', CalculCodePromo::LOT_MAX));
        }
        $lot = 'L'.(\count($this->lots($p)) + 1);
        foreach ($this->codesLibres($nombre) as $code) {
            $this->em->persist((CodePromo::creer($p, CodePromo::TYPE_LOT, $code, $this->empreinte($code), $expiration, $maintenant))
                ->setDebut($debut)->setLot($lot)->setUtilisationsMax(1)->setUtilisationsParClient(1)->setCreePar($par));
        }
        $p->marquerACodes();
        $this->em->flush();
        $this->promotions->journaliser(Promotions::ENTITE_JOURNAL, (int) $p->getId(), 'codes_generes', null, [
            'lot' => $lot, 'nombre' => $nombre, 'du' => $debut?->format('Y-m-d'), 'au' => $expiration->format('Y-m-d'),
        ], $par);
        $this->em->flush();

        return $lot;
    }

    /** Désactiver (ou réactiver) un code. Les documents qui le portent le gardent. */
    public function basculer(CodePromo $c, User $par, \DateTimeImmutable $maintenant): void
    {
        $this->promotions->surLaBoutique();
        $avant = $c->instantane();
        $c->isActive() ? $c->desactiver($par, $maintenant) : $c->reactiver();
        // Le code basculé est nommé, même d'un lot : on doit savoir lequel ne sert plus.
        $this->promotions->journaliser(Promotions::ENTITE_JOURNAL, (int) $c->getPromotion()?->getId(), $c->isActive() ? 'code_reactive' : 'code_desactive', $avant, array_merge($c->instantane(), ['code' => $c->getCodeAffiche()]), $par);
        $this->em->flush();
    }

    /**
     * @return array{0: ?\DateTimeImmutable, 1: \DateTimeImmutable}
     *
     * @throws \DomainException
     */
    private function verifierCreation(Promotion $p, ?\DateTimeImmutable $debut, ?\DateTimeImmutable $expiration, \DateTimeImmutable $maintenant): array
    {
        $this->promotions->surLaBoutique();
        $aujourdhui = $maintenant->setTime(0, 0);
        if (null === $p->getId() || $p->estAnniversaire()) {
            throw new \DomainException('Pas de code pour cette promotion.');
        }
        if (null !== $p->getCode()) {
            throw new \DomainException('Cette promotion a un code simple : videz-le dans « Modifier » avant de générer des codes.');
        }
        if ($p->getFin() < $aujourdhui) {
            throw new \DomainException('La promotion est terminée.');
        }
        if (!$p->aUnAvantageCommande() && !$p->aUnAvantageLignes()) {
            throw new \DomainException('Donnez d\'abord un avantage à la promotion.');
        }
        $expiration = ($expiration ?? $p->getFin())->setTime(0, 0);
        if ($expiration > $p->getFin()) {
            throw new \DomainException('Un code expire au plus tard le dernier jour de la promotion ('.$p->getFin()->format('d/m/Y').').');
        }
        if ($expiration < $aujourdhui) {
            throw new \DomainException('La date d\'expiration est passée.');
        }
        if (null !== $debut && $debut->setTime(0, 0) > $expiration) {
            throw new \DomainException('Le code commence après son expiration.');
        }

        return [$debut?->setTime(0, 0), $expiration];
    }

    /**
     * Codes de lot neufs, différents de tous ceux de la base.
     *
     * @return list<string>
     */
    private function codesLibres(int $nombre): array
    {
        $codes = [];
        for ($essai = 0; \count($codes) < $nombre && $essai < 20; ++$essai) {
            $candidats = [];
            while (\count($codes) + \count($candidats) < $nombre) {
                $c = CalculCodePromo::genererCode();
                if (!isset($codes[$c])) {
                    $candidats[$c] = true;
                }
            }
            $pris = $this->em->createQueryBuilder()->select('c.code')->from(CodePromo::class, 'c')
                ->where('c.code IN (:codes)')->setParameter('codes', array_keys($candidats))
                ->getQuery()->getSingleColumnResult();
            foreach (array_diff_key($candidats, array_flip($pris), $this->codesSimples()) as $c => $vrai) {
                $codes[$c] = true;
            }
        }
        if (\count($codes) < $nombre) {
            throw new \DomainException('Impossible de tirer assez de codes : réessayez.');
        }

        return array_keys($codes);
    }

    private function estLibre(string $code): bool
    {
        return null === $this->em->getRepository(CodePromo::class)->findOneBy(['code' => $code])
            && !isset($this->codesSimples()[$code]);
    }

    /** @return array<string, true> codes simples des promotions (forme canonique) */
    private function codesSimples(): array
    {
        $codes = [];
        foreach ($this->em->createQueryBuilder()->select('p.code')->from(Promotion::class, 'p')->where('p.code IS NOT NULL')->getQuery()->getSingleColumnResult() as $c) {
            $codes[CalculCodePromo::canonique((string) $c)] = true;
        }

        return $codes;
    }

    /* ---------- Lecture ---------- */

    /** @return list<CodePromo> */
    public function codes(Promotion $p): array
    {
        return $this->em->getRepository(CodePromo::class)->findBy(['promotion' => $p], ['id' => 'ASC']);
    }

    /**
     * Lots de la promotion, du plus ancien au plus récent.
     *
     * @return list<string>
     */
    public function lots(Promotion $p): array
    {
        return array_values(array_map('strval', $this->em->createQueryBuilder()->select('DISTINCT c.lot')->from(CodePromo::class, 'c')
            ->where('c.promotion = :p')->andWhere('c.lot IS NOT NULL')->setParameter('p', $p)
            ->orderBy('c.lot', 'ASC')->getQuery()->getSingleColumnResult()));
    }

    /**
     * Documents qui portent les codes : par code, le numéro, le client et le
     * montant hors TVA retiré par l'avantage sur la commande.
     *
     * @param list<CodePromo> $codes
     *
     * @return array<int, list<array{type: string, id: int, numero: ?string, client: ?string, remise: int}>>
     */
    public function documents(array $codes): array
    {
        $ids = array_values(array_filter(array_map(static fn (CodePromo $c): ?int => $c->getId(), $codes)));
        if ([] === $ids) {
            return [];
        }
        $resultat = [];
        foreach ($this->commandesVivantes($ids, null, null)->select('c', 'cl')->leftJoin('c.clients', 'cl')->orderBy('c.id', 'ASC')->getQuery()->getResult() as $c) {
            /** @var Commandes $c */
            $resultat[(int) $c->getCodePromo()?->getId()][] = ['type' => 'commande', 'id' => (int) $c->getId(), 'numero' => $c->getNumero(), 'client' => $c->getClients()?->getNomComplet(), 'remise' => $c->getRemiseCodePromo()];
        }
        foreach ($this->devisVivants($ids, null, null)->select('d', 'cl')->leftJoin('d.clients', 'cl')->orderBy('d.id', 'ASC')->getQuery()->getResult() as $d) {
            /** @var Devis $d */
            $resultat[(int) $d->getCodePromo()?->getId()][] = ['type' => 'devis', 'id' => (int) $d->getId(), 'numero' => $d->getNumero(), 'client' => $d->getClients()?->getNomComplet(), 'remise' => $d->getRemiseCodePromo()];
        }

        return $resultat;
    }

    /**
     * Utilisations du code, sans le document donné : en tout, et pour ce client.
     * En ligne, $canonique (le code tapé) sert à compter les commandes pas
     * encore confirmées qui l'ont tapé aussi.
     *
     * @return array{0: int, 1: int}
     */
    public function utilisations(CodePromo $code, ?Clients $client, Commandes|Devis|null $sans, ?string $canonique = null): array
    {
        $id = (int) $code->getId();
        $exclureCommande = $sans instanceof Commandes ? $sans->getId() : null;
        $exclureDevis = $sans instanceof Devis ? $sans->getId() : null;
        $total = 0;
        $duClient = 0;
        foreach ([$this->commandesVivantes([$id], $exclureCommande, null), $this->devisVivants([$id], $exclureDevis, null)] as $qb) {
            $alias = $qb->getRootAliases()[0];
            $qb->select('COUNT('.$alias.'.id) AS total');
            if (null !== $client?->getId()) {
                $qb->addSelect('SUM(CASE WHEN '.$alias.'.clients = :client THEN 1 ELSE 0 END) AS client')->setParameter('client', $client);
            }
            $r = $qb->getQuery()->getSingleResult();
            $total += (int) $r['total'];
            $duClient += (int) ($r['client'] ?? 0);
        }
        if ($this->enLigne) {
            // Le compte du local (synchronisé) peut connaître des documents que le site n'a pas.
            $total = max($total, $code->getUtilisations() - (null !== $sans?->getCodePromo() && $sans->getCodePromo()->getId() === $code->getId() ? 1 : 0));
            [$attente, $attenteClient] = $this->commandesEnAttente((string) ($canonique ?? $sans?->getCodeSaisi()), $client, $exclureCommande);
            $total += $attente;
            $duClient += $attenteClient;
        }

        return [$total, $duClient];
    }

    /** Recompte les utilisations du code (liste, site en ligne), le document donné compté ou non. */
    public function recompter(CodePromo $code, Commandes|Devis|null $document = null, bool $documentLePorte = false): void
    {
        if ($this->enLigne || null === $code->getId()) {
            return;
        }
        [$autres] = $this->utilisations($code, null, $document);
        $n = $autres + ($documentLePorte ? 1 : 0);
        if ($n !== $code->getUtilisations()) {
            $code->setUtilisations($n);
        }
    }

    /** Recompte tous les codes encore valables (tâche du matin) ; rend le nombre de codes corrigés. */
    public function recompterTout(\DateTimeImmutable $jour): int
    {
        $corriges = 0;
        $codes = $this->em->createQueryBuilder()->select('c')->from(CodePromo::class, 'c')
            ->where('c.expiration >= :jour')->setParameter('jour', $jour->setTime(0, 0)->modify('-7 days'))
            ->getQuery()->getResult();
        foreach ($codes as $c) {
            $avant = $c->getUtilisations();
            $this->recompter($c);
            // Clé de synchronisation changée : l'empreinte suit, et le code repart vers le site.
            $empreinte = null !== $c->getCode() ? $this->empreinte($c->getCode()) : $c->getEmpreinte();
            $change = $avant !== $c->getUtilisations() || $empreinte !== $c->getEmpreinte();
            if ($empreinte !== $c->getEmpreinte()) {
                $c->setEmpreinte($empreinte);
            }
            $corriges += $change ? 1 : 0;
        }
        $this->em->flush();

        return $corriges;
    }

    /* ---------- Vérification d'un code tapé ---------- */

    /** Le code généré qui correspond à ce qui est tapé (au local par le code, en ligne par l'empreinte). */
    public function trouver(?string $saisi): ?CodePromo
    {
        $code = CalculCodePromo::canonique($saisi);
        if ('' === $code) {
            return null;
        }

        if (!$this->enLigne) {
            return $this->em->getRepository(CodePromo::class)->findOneBy(['code' => $code]);
        }
        $empreinte = $this->empreinte($code);

        return '' === $empreinte ? null : $this->em->getRepository(CodePromo::class)->findOneBy(['empreinte' => $empreinte]);
    }

    /**
     * Ce que vaut le code tapé pour ce document, sans encore rien écrire.
     *
     *  - code : le code généré reconnu (null pour un code simple ou inconnu) ;
     *  - refus : null s'il peut servir, sinon la clé de CalculCodePromo::REFUS_EQUIPE ;
     *  - simple : le texte est le code simple d'une promotion valable ce jour-là
     *    (il débloque ses lignes comme avant ; rien à compter).
     *
     * @return array{code: ?CodePromo, refus: ?string, simple: bool}
     */
    public function verifier(?string $saisi, ?Clients $client, Commandes|Devis|null $document, \DateTimeImmutable $jour, bool $verrouiller): array
    {
        $canonique = CalculCodePromo::canonique($saisi);
        if ('' === $canonique) {
            return ['code' => null, 'refus' => null, 'simple' => false];
        }
        $code = $this->trouver($canonique);
        if (null === $code) {
            if (!$this->enLigne) {
                foreach ($this->promotions->valables($jour) as $p) {
                    if (null !== $p->getCode() && CalculCodePromo::canonique($p->getCode()) === $canonique) {
                        return ['code' => null, 'refus' => null, 'simple' => true];
                    }
                }
            }

            return ['code' => null, 'refus' => 'inconnu', 'simple' => false];
        }
        if ($verrouiller && !$this->enLigne && null !== $code->getId()) {
            $this->verrous->prendre((int) $code->getId());
        }
        $promotion = $code->getPromotion();
        $segments = [];
        if (null !== $client && null !== $promotion && !\in_array($promotion->getSegment(), [Promotion::SEGMENT_TOUS, Promotion::SEGMENT_LISTE], true)) {
            $segments = $this->promotions->segmentsDe($client, $document instanceof Commandes ? $document->getId() : null, $jour);
        }
        [$autres, $autresDuClient] = null !== $client ? $this->utilisations($code, $client, $document, $canonique) : [0, 0];
        $deja = null !== $document?->getCodePromo() && $document->getCodePromo()->getId() === $code->getId();
        $avecLaPromotion = null !== $client && null !== $promotion && null !== $promotion->getUtilisationsParClient()
            ? $this->commandesAvecLaPromotion($promotion, $client, $document instanceof Commandes ? $document->getId() : null)
            : 0;

        return [
            'code' => $code,
            'refus' => CalculCodePromo::refus($code, $client?->isB2B(), $jour, $autres, $autresDuClient, $deja, $segments, null !== $promotion && $promotion->contientClient($client), $avecLaPromotion),
            'simple' => false,
        ];
    }

    /**
     * Autres commandes vivantes du client qui ont profité de la promotion :
     * par une ligne qui la porte, ou par un de ses codes (« Commandes par
     * client » de la promotion, pour tous ses codes ensemble). En ligne,
     * les lignes ne disent pas leur promotion : seuls les codes comptent, et
     * la boutique revérifie à la confirmation.
     */
    private function commandesAvecLaPromotion(Promotion $promotion, Clients $client, ?int $sans): int
    {
        if (null === $client->getId() || null === $promotion->getId()) {
            return 0;
        }
        $qb = $this->em->createQueryBuilder()->select('COUNT(DISTINCT c.id)')->from(Commandes::class, 'c')->leftJoin('c.codePromo', 'cp')
            ->where('c.clients = :client')->andWhere('c.deleted = false')
            ->andWhere('EXISTS (SELECT l.id FROM '.CommandesDetails::class.' l WHERE l.commande = c AND l.statutProduction != :annulee AND (l.promotion = :p OR cp.promotion = :p))')
            ->setParameter('client', $client)->setParameter('p', $promotion)->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE);
        if (null !== $sans) {
            $qb->andWhere('c.id != :sans')->setParameter('sans', $sans);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /* ---------- Requêtes ---------- */

    /** @param list<int> $codes */
    private function commandesVivantes(array $codes, ?int $sans, ?Clients $client): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->from(Commandes::class, 'c')
            ->where('c.codePromo IN (:codes)')->andWhere('c.deleted = false')
            // Une commande annulée (toutes ses lignes) rend son utilisation.
            ->andWhere('EXISTS (SELECT l.id FROM '.CommandesDetails::class.' l WHERE l.commande = c AND l.statutProduction != :annulee)')
            ->setParameter('codes', $codes)->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE);
        if (null !== $sans) {
            $qb->andWhere('c.id != :sans')->setParameter('sans', $sans);
        }
        if (null !== $client) {
            $qb->andWhere('c.clients = :client')->setParameter('client', $client);
        }

        return $qb;
    }

    /** @param list<int> $codes */
    private function devisVivants(array $codes, ?int $sans, ?Clients $client): \Doctrine\ORM\QueryBuilder
    {
        $qb = $this->em->createQueryBuilder()->from(Devis::class, 'd')
            ->where('d.codePromo IN (:codes)')->andWhere('d.deleted = false')->andWhere('d.commande IS NULL')
            ->andWhere('d.statut IN (:vivants)')
            ->setParameter('codes', $codes)->setParameter('vivants', self::DEVIS_VIVANTS);
        if (null !== $sans) {
            $qb->andWhere('d.id != :sans')->setParameter('sans', $sans);
        }
        if (null !== $client) {
            $qb->andWhere('d.clients = :client')->setParameter('client', $client);
        }

        return $qb;
    }

    /**
     * Commandes passées en ligne, pas encore confirmées par la boutique, qui
     * ont tapé ce code : en tout et pour ce client.
     *
     * @return array{0: int, 1: int}
     */
    private function commandesEnAttente(string $canonique, ?Clients $client, ?int $sans): array
    {
        if ('' === $canonique) {
            return [0, 0];
        }
        $qb = $this->em->createQueryBuilder()->select('COUNT(c.id) AS total')->from(Commandes::class, 'c')
            ->where('c.codeSaisi = :code')->andWhere('c.codePromo IS NULL')->andWhere('c.statut = false')
            ->andWhere('c.deleted = false')->andWhere('c.canal = :enLigne')
            ->setParameter('code', $canonique)->setParameter('enLigne', Commandes::CANAL_EN_LIGNE);
        if (null !== $client?->getId()) {
            $qb->addSelect('SUM(CASE WHEN c.clients = :client THEN 1 ELSE 0 END) AS client')->setParameter('client', $client);
        }
        if (null !== $sans) {
            $qb->andWhere('c.id != :sans')->setParameter('sans', $sans);
        }
        $r = $qb->getQuery()->getSingleResult();

        return [(int) $r['total'], (int) ($r['client'] ?? 0)];
    }
}
