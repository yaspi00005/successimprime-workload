<?php

namespace App\Service\Clients;

use App\Entity\Clients;
use App\Entity\Commandes;
use App\Entity\ContactClient;
use App\Entity\Devis;
use App\Entity\Prospect;
use App\Entity\RelanceCreance;
use App\Entity\User;
use App\Service\Creances;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Liste des clients (maquettes K01 et K06) : chiffres du haut, filtres par
 * type et par statut, et pour chaque client son CA sur 12 mois, sa
 * dernière commande, ce qu'il doit, son statut et qui le suit. Les
 * montants ne sont calculés que pour qui a le droit de les voir.
 */
final class ListeClients
{
    public const PAR_PAGE = 24;
    public const INACTIF_JOURS = 60;

    public const STATUTS = [
        'actif' => 'Actif',
        'relance' => 'Relance',
        'inactif' => 'Inactif 60 j',
        'nouveau' => 'Nouveau',
        'bloque' => 'Bloqué',
    ];

    public const TRIS = [
        'nom' => 'Nom',
        'ca' => 'CA 12 mois',
        'derniere' => 'Dernière commande',
        'solde' => 'Solde dû',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Creances $creances,
    ) {
    }

    /**
     * @param array{q?: string, type?: string, statut?: string, suivi?: int, tri?: string, page?: int} $filtres
     *
     * @return array{lignes: list<array<string, mixed>>, total: int, page: int, pages: int, chiffres: array<string, mixed>, commerciaux: list<User>}
     */
    public function donnees(array $filtres, \DateTimeImmutable $maintenant, bool $voirCa, bool $voirSoldes): array
    {
        $commandes = $this->agregatsCommandes($maintenant);
        $soldes = $voirSoldes ? $this->soldes($maintenant) : [];

        $qb = $this->em->getRepository(Clients::class)->createQueryBuilder('c')->leftJoin('c.suiviPar', 's')->addSelect('s');
        $q = trim((string) ($filtres['q'] ?? ''));
        if ('' !== $q) {
            $chiffres = ContactClient::chiffres($q);
            $qb->andWhere('c.nom LIKE :q OR c.prenom LIKE :q OR c.raisonSociale LIKE :q OR c.code LIKE :q OR c.nif LIKE :q OR c.email LIKE :q'.(\strlen($chiffres) >= 4 ? ' OR c.telephone LIKE :tel OR c.telephone2 LIKE :tel' : ''))
                ->setParameter('q', '%'.$q.'%');
            if (\strlen($chiffres) >= 4) {
                $qb->setParameter('tel', '%'.$chiffres.'%');
            }
        }
        $type = (string) ($filtres['type'] ?? '');
        if (isset(Clients::TYPES_COMPTE_LABELS[$type])) {
            $qb->andWhere('c.typeCompte = :t')->setParameter('t', $type);
        }
        $suivi = (int) ($filtres['suivi'] ?? 0);
        if ($suivi > 0) {
            $qb->andWhere('s.id = :s')->setParameter('s', $suivi);
        }
        /** @var list<Clients> $clients */
        $clients = $qb->getQuery()->getResult();

        $lignes = [];
        foreach ($clients as $client) {
            $id = (int) $client->getId();
            $agg = $commandes[$id] ?? null;
            $solde = $soldes[$id] ?? ['du' => 0, 'echu' => 0, 'factures_echues' => 0];
            $lignes[] = [
                'client' => $client,
                'ca' => $voirCa ? (int) ($agg['ca'] ?? 0) : null,
                'derniere' => $agg['derniere'] ?? null,
                'nb' => (int) ($agg['nb'] ?? 0),
                'du' => $voirSoldes ? $solde['du'] : null,
                'echu' => $voirSoldes ? $solde['echu'] : null,
                'factures_echues' => $solde['factures_echues'],
                'statut' => self::statut($client, $agg['derniere'] ?? null, $solde['echu'], $maintenant),
            ];
        }

        $statut = (string) ($filtres['statut'] ?? '');
        if (isset(self::STATUTS[$statut])) {
            $lignes = array_values(array_filter($lignes, static fn (array $l): bool => $l['statut'] === $statut));
        }

        $tri = (string) ($filtres['tri'] ?? 'derniere');
        usort($lignes, match ($tri) {
            'nom' => static fn (array $a, array $b): int => strcasecmp($a['client']->getNomComplet(), $b['client']->getNomComplet()),
            'ca' => static fn (array $a, array $b): int => ($b['ca'] ?? 0) <=> ($a['ca'] ?? 0),
            'solde' => static fn (array $a, array $b): int => ($b['du'] ?? 0) <=> ($a['du'] ?? 0),
            default => static fn (array $a, array $b): int => [$b['derniere'], $b['client']->getId()] <=> [$a['derniere'], $a['client']->getId()],
        });

        $total = \count($lignes);
        $pages = max(1, (int) ceil($total / self::PAR_PAGE));
        $page = max(1, min($pages, (int) ($filtres['page'] ?? 1)));
        if (empty($filtres['tout'])) {
            $lignes = \array_slice($lignes, ($page - 1) * self::PAR_PAGE, self::PAR_PAGE);
        }
        $this->completer($lignes, $commandes);

        return [
            'lignes' => $lignes,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'chiffres' => $this->chiffres($commandes, $soldes, $maintenant, $voirSoldes),
            'commerciaux' => $this->commerciaux(),
        ];
    }

    /**
     * Statut affiché : bloqué (fiche désactivée), relance (une facture
     * échue), actif (commande depuis moins de 60 jours), inactif, nouveau.
     */
    public static function statut(Clients $client, ?\DateTimeInterface $derniere, int $echu, \DateTimeImmutable $maintenant): string
    {
        return match (true) {
            false === $client->isStatut() => 'bloque',
            $echu > 0 => 'relance',
            null === $derniere => 'nouveau',
            $derniere >= $maintenant->modify('-'.self::INACTIF_JOURS.' days') => 'actif',
            default => 'inactif',
        };
    }

    /** @return array<int, array{ca: int, derniere: ?\DateTimeImmutable, premiere: ?\DateTimeImmutable, nb: int, ca12: int}> */
    private function agregatsCommandes(\DateTimeImmutable $maintenant): array
    {
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(c.clients) AS client', 'SUM(CASE WHEN c.dateCommande >= :depuis THEN c.totalTtc ELSE 0 END) AS ca', 'MAX(c.dateCommande) AS derniere', 'MIN(c.dateCommande) AS premiere', 'COUNT(c.id) AS nb')
            ->from(Commandes::class, 'c')
            ->where('c.deleted = false')->andWhere('c.statut = true')->andWhere('c.clients IS NOT NULL')
            ->groupBy('c.clients')
            ->setParameter('depuis', $maintenant->modify('-12 months'))
            ->getQuery()->getArrayResult();
        $agg = [];
        foreach ($lignes as $l) {
            $agg[(int) $l['client']] = [
                'ca' => (int) $l['ca'],
                'derniere' => null !== $l['derniere'] ? new \DateTimeImmutable((string) $l['derniere']) : null,
                'premiere' => null !== $l['premiere'] ? new \DateTimeImmutable((string) $l['premiere']) : null,
                'nb' => (int) $l['nb'],
            ];
        }

        return $agg;
    }

    /** @return array<int, array{du: int, echu: int, factures_echues: int}> */
    private function soldes(\DateTimeImmutable $maintenant): array
    {
        $soldes = [];
        foreach ($this->creances->donnees($maintenant)['lignes'] as $l) {
            $id = $l['commande']->getClients()?->getId();
            if (null === $id) {
                continue;
            }
            $soldes[$id] ??= ['du' => 0, 'echu' => 0, 'factures_echues' => 0];
            $soldes[$id]['du'] += (int) $l['reste'];
            if ($l['jours'] > 0) {
                $soldes[$id]['echu'] += (int) $l['reste'];
                ++$soldes[$id]['factures_echues'];
            }
        }

        return $soldes;
    }

    /**
     * Chiffres du haut (K01).
     *
     * @param array<int, array<string, mixed>> $commandes
     * @param array<int, array<string, int>>   $soldes
     *
     * @return array<string, mixed>
     */
    private function chiffres(array $commandes, array $soldes, \DateTimeImmutable $maintenant, bool $voirSoldes): array
    {
        $il12 = $maintenant->modify('-12 months');
        $il60 = $maintenant->modify('-'.self::INACTIF_JOURS.' days');
        $mois = $maintenant->modify('first day of this month')->setTime(0, 0);
        $actifs = $nouveauxMois = $inactifs = 0;
        foreach ($commandes as $a) {
            if (null !== $a['derniere'] && $a['derniere'] >= $il12) {
                ++$actifs;
            }
            if (null !== $a['premiere'] && $a['premiere'] >= $mois) {
                ++$nouveauxMois;
            }
            if (null !== $a['derniere'] && $a['derniere'] < $il60) {
                ++$inactifs;
            }
        }
        $prospects = $this->em->createQueryBuilder()->select('COUNT(p.id)')->from(Prospect::class, 'p')
            ->where('p.etape NOT IN (:fermees)')->setParameter('fermees', [Prospect::ETAPE_GAGNE, Prospect::ETAPE_PERDU])
            ->getQuery()->getSingleScalarResult();
        $prospectsSemaine = $this->em->createQueryBuilder()->select('COUNT(p.id)')->from(Prospect::class, 'p')
            ->where('p.creeLe >= :d')->setParameter('d', $maintenant->modify('monday this week')->setTime(0, 0))
            ->getQuery()->getSingleScalarResult();

        return [
            'actifs' => $actifs,
            'nouveaux_mois' => $nouveauxMois,
            'prospects' => (int) $prospects,
            'prospects_semaine' => (int) $prospectsSemaine,
            'inactifs' => $inactifs,
            'creances' => $voirSoldes ? array_sum(array_column($soldes, 'du')) : null,
            'echu' => $voirSoldes ? array_sum(array_column($soldes, 'echu')) : null,
        ];
    }

    /**
     * Contact principal, commercial et dernière activité des clients
     * de la page (K06).
     *
     * @param list<array<string, mixed>> $lignes
     * @param array<int, array<string, mixed>> $commandes
     */
    private function completer(array &$lignes, array $commandes): void
    {
        $clients = array_map(static fn (array $l): Clients => $l['client'], $lignes);
        if ([] === $clients) {
            return;
        }
        $contacts = [];
        foreach ($this->em->getRepository(ContactClient::class)->findBy(['client' => $clients]) as $c) {
            $id = $c->getClient()->getId();
            if (!isset($contacts[$id]) || 'principal' === $c->getRole()) {
                $contacts[$id] = $c;
            }
        }
        /** @var list<Commandes> $dernieres */
        $dernieres = $this->em->createQueryBuilder()->select('c')->from(Commandes::class, 'c')
            ->where('c.clients IN (:cl)')->andWhere('c.deleted = false')->andWhere('c.statut = true')
            ->setParameter('cl', $clients)->orderBy('c.id', 'DESC')->getQuery()->getResult();
        $derniereCommande = [];
        foreach ($dernieres as $c) {
            $derniereCommande[$c->getClients()->getId()] ??= $c;
        }
        /** @var list<Devis> $devis */
        $devis = $this->em->createQueryBuilder()->select('d')->from(Devis::class, 'd')
            ->where('d.clients IN (:cl)')->andWhere('d.statut = :envoye')
            ->setParameter('cl', $clients)->setParameter('envoye', Devis::STATUT_ENVOYE)->orderBy('d.id', 'DESC')->getQuery()->getResult();
        $devisEnAttente = [];
        foreach ($devis as $d) {
            $devisEnAttente[$d->getClients()->getId()] ??= $d;
        }
        /** @var list<RelanceCreance> $relances */
        $relances = $this->em->createQueryBuilder()->select('r', 'c')->from(RelanceCreance::class, 'r')->join('r.commande', 'c')
            ->where('c.clients IN (:cl)')->setParameter('cl', $clients)->orderBy('r.envoyeeLe', 'DESC')->getQuery()->getResult();
        $derniereRelance = [];
        foreach ($relances as $r) {
            $derniereRelance[$r->getCommande()->getClients()->getId()] ??= $r;
        }

        foreach ($lignes as &$l) {
            $id = $l['client']->getId();
            $commande = $derniereCommande[$id] ?? null;
            $l['contact'] = $contacts[$id] ?? null;
            $l['suivi'] = $l['client']->getSuiviPar();
            $activites = [];
            if (isset($derniereRelance[$id])) {
                $activites[] = ['date' => $derniereRelance[$id]->getEnvoyeeLe(), 'texte' => 'Relance envoyée'];
            }
            if (isset($devisEnAttente[$id])) {
                $d = $devisEnAttente[$id];
                $activites[] = ['date' => $d->getDateDevis(), 'texte' => \sprintf('Devis %s en attente', $d->getNumero())];
            }
            if (null !== $commande) {
                $activites[] = ['date' => $commande->getDateCommande(), 'texte' => \sprintf('Commande %s %s', $commande->getNumero(), self::etatCommande($commande))];
            }
            usort($activites, static fn (array $a, array $b): int => $b['date'] <=> $a['date']);
            $l['activite'] = $activites[0] ?? null;
        }
        unset($l);
    }

    private static function etatCommande(Commandes $c): string
    {
        return match ($c->getStatutTravaux()) {
            'livraison' => 'prête',
            'livree' => 'livrée',
            'annulee' => 'annulée',
            default => 'en cours',
        };
    }

    /** @return list<User> */
    private function commerciaux(): array
    {
        return $this->em->createQueryBuilder()->select('DISTINCT u')->from(User::class, 'u')
            ->join(Clients::class, 'c', 'WITH', 'c.suiviPar = u')
            ->getQuery()->getResult();
    }
}
