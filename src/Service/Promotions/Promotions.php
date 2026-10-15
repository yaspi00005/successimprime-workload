<?php

namespace App\Service\Promotions;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\CompteFidelite;
use App\Entity\Devis;
use App\Entity\DevisDetails;
use App\Entity\JournalActivite;
use App\Entity\Promotion;
use App\Entity\User;
use App\Service\Parametres\CatalogueParametres;
use App\Service\Parametres\Parametres;
use App\Service\SegmentsClients;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Promotions par segment de clients (docs/promotions.md) : réglages,
 * promotions valables un jour donné, segments et utilisations d'un client,
 * enregistrement contrôlé et journal d'activité.
 *
 * Tout se gère sur l'ERP de la boutique. Le site en ligne reçoit les
 * promotions sans code ni note, pour l'espace client, et ne les modifie pas.
 */
final class Promotions
{
    public const ENTITE_JOURNAL = 'Promotion';

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

    /** @throws \DomainException sur le site en ligne */
    public function surLaBoutique(): void
    {
        if ($this->enLigne) {
            throw new \DomainException('Les promotions se gèrent sur l\'ERP de la boutique.');
        }
    }

    /**
     * Réglages de la tuile Promotions (Paramètres, Ventes).
     *
     * @return array{alerte_cout_pct: float, alerte_marge_pct: float, anniversaire_delai_jours: int, anniversaire_rappel_jours: int, anniversaire_validation: bool, anniversaire_consentement: string}
     */
    public function reglages(): array
    {
        $valeurs = [];
        foreach (CatalogueParametres::tuiles()['promotions']['champs'] as $champ) {
            $valeurs[$champ['cle']] = $this->parametres->valeur('promotions', $champ['cle']) ?? $champ['defaut'];
        }

        return [
            'alerte_cout_pct' => (float) $valeurs['alerte_cout_pct'],
            'alerte_marge_pct' => (float) $valeurs['alerte_marge_pct'],
            'anniversaire_delai_jours' => max(1, (int) $valeurs['anniversaire_delai_jours']),
            'anniversaire_rappel_jours' => max(0, (int) $valeurs['anniversaire_rappel_jours']),
            'anniversaire_validation' => (bool) $valeurs['anniversaire_validation'],
            'anniversaire_consentement' => isset(SegmentsClients::CONSENTEMENTS[(string) $valeurs['anniversaire_consentement']]) ? (string) $valeurs['anniversaire_consentement'] : 'sauf_refus',
        ];
    }

    /**
     * Promotions actives qui couvrent ce jour, articles et clients chargés.
     *
     * @return list<Promotion>
     */
    public function valables(\DateTimeImmutable $jour): array
    {
        return $this->em->createQueryBuilder()
            ->select('p', 'pr', 'cat', 'cl')
            ->from(Promotion::class, 'p')
            ->leftJoin('p.produits', 'pr')
            ->leftJoin('p.categorie', 'cat')
            ->leftJoin('p.clients', 'cl')
            ->where('p.active = true')
            ->andWhere('p.debut <= :jour AND p.fin >= :jour')
            ->setParameter('jour', $jour->setTime(0, 0))
            ->orderBy('p.fin', 'ASC')->addOrderBy('p.id', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Espace client (en ligne) : les promotions en cours qui concernent ce
     * client, celles de son segment ou de sa liste (anniversaire compris).
     * Aucune pour un revendeur au prix B2B ; une promotion à code n'est pas
     * envoyée en ligne (CatalogueSynchro).
     *
     * @return list<Promotion>
     */
    public function pourLeClient(Clients $client, \DateTimeImmutable $jour): array
    {
        if ($client->isB2B()) {
            return [];
        }
        $ctx = new ContexteLigne($client, null, 0, 0, 1, 0, $jour, $this->segmentsDe($client, null, $jour));

        return array_values(array_filter(
            $this->valables($jour),
            static fn (Promotion $p): bool => $p->estSansCode() && CalculPromotion::dansSegment($p, $ctx),
        ));
    }

    /**
     * Segments du client au jour de la saisie, sans compter le document en
     * cours (sinon un nouveau client perdrait sa promotion en enregistrant).
     *
     * @return list<string>
     */
    public function segmentsDe(Clients $client, ?int $exclureCommande, \DateTimeImmutable $jour): array
    {
        $qb = $this->em->createQueryBuilder()
            ->select('MAX(c.dateCommande) AS derniere', 'COUNT(c.id) AS nombre', 'SUM(CASE WHEN c.dateCommande >= :an THEN c.totalTtc ELSE 0 END) AS ca')
            ->from(Commandes::class, 'c')
            ->where('c.clients = :client')->andWhere('c.deleted = false AND c.soldeOuverture = false')
            ->andWhere('c.dateCommande < :jusque')
            ->setParameter('client', $client)
            ->setParameter('an', $jour->modify('-12 months'))
            ->setParameter('jusque', $jour->setTime(0, 0)->modify('+1 day'));
        if (null !== $exclureCommande) {
            $qb->andWhere('c.id != :exclue')->setParameter('exclue', $exclureCommande);
        }
        $r = $qb->getQuery()->getSingleResult();
        $achat = (int) $r['nombre'] > 0 && null !== $r['derniere']
            ? ['derniere' => new \DateTimeImmutable((string) $r['derniere']), 'ca' => (int) $r['ca']]
            : null;

        $segments = SegmentsClients::segmentsDe($client, $achat, $jour);
        if ($this->aFranchiUnSeuil($client)) {
            $segments[] = Promotion::SEGMENT_FIDELITE;
        }

        return $segments;
    }

    /** Fidélité : un seuil franchi (m² ou montant), d'après le résumé tenu par la fidélité. */
    public function aFranchiUnSeuil(Clients $client): bool
    {
        $compte = $this->em->getRepository(CompteFidelite::class)->findOneBy(['client' => $client]);

        return null !== $compte && self::seuilFranchi($compte);
    }

    public static function seuilFranchi(CompteFidelite $compte): bool
    {
        return !$compte->isExclu() && (($compte->getM2Cumules() > 0 && $compte->eligibleM2()) || $compte->eligibleMontant());
    }

    /**
     * Commandes du client qui ont déjà utilisé chaque promotion (hors celle-ci).
     *
     * @param list<int> $promotions
     *
     * @return array<int, int>
     */
    public function utilisations(Clients $client, array $promotions, ?int $exclureCommande): array
    {
        if ([] === $promotions || null === $client->getId()) {
            return [];
        }
        $qb = $this->em->createQueryBuilder()
            ->select('IDENTITY(l.promotion) AS promotion', 'COUNT(DISTINCT c.id) AS nombre')
            ->from(CommandesDetails::class, 'l')
            ->join('l.commande', 'c')
            ->where('l.promotion IN (:promotions)')->andWhere('c.clients = :client')->andWhere('c.deleted = false')
            ->andWhere('l.statutProduction != :annulee')
            ->setParameter('promotions', $promotions)->setParameter('client', $client)
            ->setParameter('annulee', CommandesDetails::PRODUCTION_ANNULEE)
            ->groupBy('l.promotion');
        if (null !== $exclureCommande) {
            $qb->andWhere('c.id != :exclue')->setParameter('exclue', $exclureCommande);
        }
        $resultat = [];
        foreach ($qb->getQuery()->getArrayResult() as $r) {
            $resultat[(int) $r['promotion']] = (int) $r['nombre'];
        }

        return $resultat;
    }

    /** Lignes de devis et de commande qui portent la promotion, et documents qui portent un de ses codes. */
    public function nombreLignes(Promotion $p): int
    {
        $n = 0;
        foreach ([CommandesDetails::class, DevisDetails::class] as $classe) {
            $n += (int) $this->em->createQueryBuilder()->select('COUNT(l.id)')->from($classe, 'l')
                ->where('l.promotion = :p')->setParameter('p', $p)->getQuery()->getSingleScalarResult();
        }
        if ($p->hasCodes()) {
            foreach ([Commandes::class, Devis::class] as $classe) {
                $n += (int) $this->em->createQueryBuilder()->select('COUNT(d.id)')->from($classe, 'd')->join('d.codePromo', 'cp')
                    ->where('cp.promotion = :p')->setParameter('p', $p)->getQuery()->getSingleScalarResult();
            }
        }

        return $n;
    }

    /**
     * Ce qui manque ou ne va pas avant d'enregistrer.
     *
     * @return list<string>
     */
    public function erreurs(Promotion $p, \DateTimeImmutable $aujourdhui): array
    {
        $erreurs = [];
        if ('' === $p->getNom()) {
            $erreurs[] = 'Donnez un nom à la promotion (le client le voit).';
        }
        $articles = !$p->getProduits()->isEmpty() || null !== $p->getCategorie();
        // Avec un avantage sur la commande (codes générés), les articles visés sont facultatifs.
        if (!$articles && null === $p->getAvantageCommande()) {
            $erreurs[] = 'Choisissez au moins un produit ou une catégorie.';
        }
        if ($articles && $p->getValeur() <= 0) {
            $erreurs[] = 'L\'avantage doit être supérieur à zéro.';
        }
        if (Promotion::AVANTAGE_POURCENTAGE === $p->getAvantage() && $p->getValeur() > 90) {
            $erreurs[] = 'Un pourcentage ne peut pas dépasser 90 %.';
        }
        if (null !== $p->getAvantageCommande()) {
            if ($p->getValeurCommande() <= 0) {
                $erreurs[] = 'L\'avantage sur la commande doit être supérieur à zéro.';
            }
            if (Promotion::COMMANDE_POURCENTAGE === $p->getAvantageCommande() && $p->getValeurCommande() > CalculCodePromo::POURCENTAGE_MAX) {
                $erreurs[] = \sprintf('Un pourcentage de la commande ne peut pas dépasser %d %%.', CalculCodePromo::POURCENTAGE_MAX);
            }
            if (Promotion::COMMANDE_MONTANT === $p->getAvantageCommande() && null !== $p->getPlafondCommande()) {
                $erreurs[] = 'Le plafond ne sert qu\'avec un pourcentage de la commande.';
            }
            if ($p->estAnniversaire()) {
                $erreurs[] = 'Une promotion d\'anniversaire n\'a pas d\'avantage sur la commande.';
            }
        }
        // Un code simple (ancien) et des codes générés ne vont pas ensemble.
        if (null !== $p->getCode() && $p->exigeCodeGenere()) {
            $erreurs[] = 'Code simple ou codes générés : choisissez l\'un ou l\'autre (videz le code simple).';
        }
        if ($p->getFin() < $p->getDebut()) {
            $erreurs[] = 'La fin est avant le début.';
        }
        // Une promotion se juge au jour du document : commencer hier la donnerait aux commandes déjà prises.
        if (null === $p->getId() && $p->getDebut()->format('Y-m-d') < $aujourdhui->format('Y-m-d')) {
            $erreurs[] = 'Une nouvelle promotion commence aujourd\'hui au plus tôt.';
        }
        if (Promotion::SEGMENT_LISTE === $p->getSegment() && $p->getClients()->isEmpty()) {
            $erreurs[] = 'Choisissez les clients de la liste.';
        }
        if (null !== $p->getCode()) {
            $qb = $this->em->createQueryBuilder()->select('COUNT(o.id)')->from(Promotion::class, 'o')
                ->where('o.code = :code')->andWhere('o.debut <= :fin AND o.fin >= :debut')
                ->setParameter('code', $p->getCode())->setParameter('debut', $p->getDebut())->setParameter('fin', $p->getFin());
            if (null !== $p->getId()) {
                $qb->andWhere('o.id != :id')->setParameter('id', $p->getId());
            }
            if ((int) $qb->getQuery()->getSingleScalarResult() > 0) {
                $erreurs[] = 'Ce code est déjà pris par une autre promotion sur la même période.';
            }
        }

        return $erreurs;
    }

    /**
     * Enregistre une promotion créée ou modifiée, avec le journal.
     *
     * @param array<string, mixed>|null $avant instantané avant modification
     */
    public function enregistrer(Promotion $p, ?array $avant, User $par, \DateTimeImmutable $maintenant): void
    {
        $this->surLaBoutique();
        $nouvelle = null === $p->getId();
        if ($nouvelle) {
            $p->setCreePar($par);
        }
        $p->marquerModifiee($par, $maintenant);
        $this->em->persist($p);
        $this->em->flush();
        $this->journaliser(self::ENTITE_JOURNAL, (int) $p->getId(), $nouvelle ? JournalActivite::ACTION_CREATION : JournalActivite::ACTION_MODIFICATION, $avant, $p->instantane(), $par);
        $this->em->flush();
    }

    /** Journal d'activité. Ne flushe pas. */
    public function journaliser(string $entite, int $id, string $action, ?array $avant, ?array $apres, ?User $par): void
    {
        $this->em->persist((new JournalActivite())
            ->setEntite($entite)
            ->setEntiteId($id)
            ->setAction($action)
            ->setDonneesAvant($avant)
            ->setDonneesApres($apres)
            ->setUtilisateur($par));
    }
}
