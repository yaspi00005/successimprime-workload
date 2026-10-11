<?php

namespace App\Service;

use App\Entity\Clients;
use App\Entity\Commandes;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Segments de clients calculés à partir des commandes (docs/promotions.md),
 * pour cibler une campagne (maquette 35) sans saisie à la main.
 */
final class SegmentsClients
{
    public const GROS_CLIENT_CA = 500000;

    public const SEGMENTS = [
        'tous' => 'Tous les clients',
        'entreprises' => 'Entreprises et institutions',
        'particuliers' => 'Particuliers',
        'actifs' => 'Clients actifs (achat sur 12 mois)',
        'inactifs' => 'Inactifs depuis 90 jours',
        'nouveaux' => 'Sans commande encore',
        'gros' => 'Gros clients (500 000 F et plus sur 12 mois)',
    ];

    public const CONSENTEMENTS = [
        'accepte' => 'Clients ayant accepté les offres',
        'sauf_refus' => 'Tous sauf ceux qui ont refusé les messages',
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array<string, list<Clients>> clients actifs de chaque segment
     */
    public function parSegment(\DateTimeImmutable $maintenant): array
    {
        /** @var list<Clients> $clients */
        $clients = $this->em->getRepository(Clients::class)->findBy(['statut' => true], ['nom' => 'ASC', 'prenom' => 'ASC']);
        $lignes = $this->em->createQueryBuilder()
            ->select('IDENTITY(c.clients) AS client, MAX(c.dateCommande) AS derniere, SUM(CASE WHEN c.dateCommande >= :an THEN c.totalTtc ELSE 0 END) AS ca')
            ->from(Commandes::class, 'c')
            ->andWhere('c.deleted = false')
            ->andWhere('c.clients IS NOT NULL')
            ->groupBy('c.clients')
            ->setParameter('an', $maintenant->modify('-12 months'))
            ->getQuery()
            ->getArrayResult();
        $achats = [];
        foreach ($lignes as $l) {
            $achats[(int) $l['client']] = ['derniere' => new \DateTimeImmutable((string) $l['derniere']), 'ca' => (int) $l['ca']];
        }

        $segments = array_fill_keys(array_keys(self::SEGMENTS), []);
        foreach ($clients as $client) {
            foreach (self::segmentsDe($client, $achats[$client->getId()] ?? null, $maintenant) as $segment) {
                $segments[$segment][] = $client;
            }
        }

        return $segments;
    }

    /**
     * @param array{derniere: \DateTimeImmutable, ca: int}|null $achat
     *
     * @return list<string>
     */
    public static function segmentsDe(Clients $client, ?array $achat, \DateTimeImmutable $maintenant): array
    {
        $segments = ['tous', Clients::TYPE_COMPTE_ENTREPRISE === $client->getTypeCompte() ? 'entreprises' : 'particuliers'];
        if (null === $achat) {
            $segments[] = 'nouveaux';

            return $segments;
        }
        if ($achat['derniere'] >= $maintenant->modify('-12 months')) {
            $segments[] = 'actifs';
        }
        if ($achat['derniere'] < $maintenant->modify('-90 days')) {
            $segments[] = 'inactifs';
        }
        if ($achat['ca'] >= self::GROS_CLIENT_CA) {
            $segments[] = 'gros';
        }

        return $segments;
    }

    /**
     * Garde les clients joignables pour ce canal avec ce consentement.
     *
     * @param list<Clients> $clients
     *
     * @return list<Clients>
     */
    public static function joignables(array $clients, string $canal, string $consentement): array
    {
        return array_values(array_filter($clients, static function (Clients $c) use ($canal, $consentement): bool {
            if ('accepte' === $consentement && !$c->isAccepteMarketing()) {
                return false;
            }
            // Refuser les SMS vaut refus de toute campagne sans accord écrit.
            if (!$c->isRecevoirSms() && ('sms' === $canal || 'accepte' !== $consentement)) {
                return false;
            }

            return 'email' === $canal ? '' !== trim((string) $c->getEmail()) : '' !== trim((string) $c->getTelephone());
        }));
    }
}
