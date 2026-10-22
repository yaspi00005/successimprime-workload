<?php

namespace App\Service\Site;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ReglageSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Chiffres du site public (docs/site-public.md) : commandes livrées,
 * clients servis, projets réalisés. Mêmes règles que l'écran TV
 * (App\Service\EcranTv) : des volumes, jamais de montant ni de nom.
 * Calculés sur la base du côté qui sert la page, gardés une heure.
 */
final class ChiffresSite
{
    public const CLE_REGLAGE = 'chiffres';
    public const DUREE_CACHE = 3600;

    /** « Projets réalisés » : une commande livrée par client et par mois, ou chaque commande livrée. */
    public const REGLE_CLIENT_MOIS = 'client_mois';
    public const REGLE_COMMANDE = 'commande';

    public const REGLES = [
        self::REGLE_CLIENT_MOIS => 'Une fois par client et par mois (3 bâches pour un même événement = 1 projet)',
        self::REGLE_COMMANDE => 'Chaque commande livrée',
    ];

    public const LIBELLES = [
        'livrees' => 'commandes livrées',
        'clients' => 'clients servis',
        'projets' => 'projets réalisés',
    ];

    /** afficher : montrer les chiffres ; seuil : un chiffre plus petit n'est pas montré. */
    public const DEFAUTS = ['afficher' => true, 'projets' => self::REGLE_CLIENT_MOIS, 'seuil' => 1];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CacheInterface $cache,
    ) {
    }

    /** @return array{afficher: bool, projets: string, seuil: int} */
    public function reglages(): array
    {
        $reglage = $this->em->getRepository(ReglageSite::class)->findOneBy(['cle' => self::CLE_REGLAGE]);

        return self::normaliser($reglage?->getValeurs() ?? []);
    }

    /**
     * @param array<string, mixed> $valeurs
     *
     * @return array{afficher: bool, projets: string, seuil: int}
     */
    public static function normaliser(array $valeurs): array
    {
        $projets = (string) ($valeurs['projets'] ?? self::DEFAUTS['projets']);

        return [
            'afficher' => (bool) ($valeurs['afficher'] ?? self::DEFAUTS['afficher']),
            'projets' => isset(self::REGLES[$projets]) ? $projets : self::DEFAUTS['projets'],
            'seuil' => max(1, min(1_000_000, (int) ($valeurs['seuil'] ?? self::DEFAUTS['seuil']))),
        ];
    }

    /**
     * Les trois chiffres, gardés une heure (la clé suit la règle des projets).
     *
     * @return array{livrees: int, clients: int, projets: int}
     */
    public function chiffres(?array $reglages = null): array
    {
        $reglages ??= $this->reglages();

        return $this->cache->get('site_chiffres_'.$reglages['projets'], function (ItemInterface $item) use ($reglages): array {
            $item->expiresAfter(self::DUREE_CACHE);

            return $this->calculer($reglages['projets']);
        });
    }

    /**
     * Ce que le site montre : les chiffres atteignant le seuil, dans l'ordre.
     *
     * @return list<array{cle: string, valeur: int, libelle: string}>
     */
    public function aMontrer(): array
    {
        $reglages = $this->reglages();

        return $reglages['afficher'] ? self::visibles($this->chiffres($reglages), $reglages['seuil']) : [];
    }

    /**
     * @param array<string, int> $chiffres
     *
     * @return list<array{cle: string, valeur: int, libelle: string}>
     */
    public static function visibles(array $chiffres, int $seuil): array
    {
        $visibles = [];
        foreach (self::LIBELLES as $cle => $libelle) {
            $valeur = (int) ($chiffres[$cle] ?? 0);
            if ($valeur > 0 && $valeur >= $seuil) {
                $visibles[] = ['cle' => $cle, 'valeur' => $valeur, 'libelle' => $libelle];
            }
        }

        return $visibles;
    }

    /**
     * Projets réalisés à partir des commandes livrées (client, date).
     * Une commande sans client compte toujours pour un projet.
     *
     * @param iterable<array{client: int|string|null, date: \DateTimeInterface|string|null}> $livrees
     */
    public static function compterProjets(iterable $livrees, string $regle): int
    {
        $projets = [];
        $n = 0;
        foreach ($livrees as $commande) {
            ++$n;
            $client = $commande['client'] ?? null;
            $date = $commande['date'] ?? null;
            if (self::REGLE_COMMANDE === $regle || null === $client || null === $date) {
                $projets['commande-'.$n] = true;
                continue;
            }
            $mois = ($date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable((string) $date))->format('Y-m');
            $projets[$client.'|'.$mois] = true;
        }

        return \count($projets);
    }

    /** @return array{livrees: int, clients: int, projets: int} */
    private function calculer(string $regle): array
    {
        $livrees = $this->em->createQueryBuilder()
            ->select('IDENTITY(c.clients) AS client, c.dateCommande AS date')
            ->from(Commandes::class, 'c')
            ->where('c.deleted = false AND c.soldeOuverture = false')
            // Toutes les lignes livrées (ou annulées), au moins une livrée : comme l'écran TV.
            ->andWhere(\sprintf('EXISTS (SELECT l1.id FROM %s l1 WHERE l1.commande = c AND l1.statutProduction = :livree)', CommandesDetails::class))
            ->andWhere(\sprintf('NOT EXISTS (SELECT l2.id FROM %s l2 WHERE l2.commande = c AND l2.statutProduction NOT IN (:finies))', CommandesDetails::class))
            ->setParameter('livree', CommandesDetails::PRODUCTION_LIVREE)
            ->setParameter('finies', [CommandesDetails::PRODUCTION_LIVREE, CommandesDetails::PRODUCTION_ANNULEE])
            ->getQuery()->getArrayResult();

        $clients = (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT IDENTITY(c.clients))')
            ->from(Commandes::class, 'c')
            ->where('c.deleted = false AND c.soldeOuverture = false')
            ->getQuery()->getSingleScalarResult();

        return [
            'livrees' => \count($livrees),
            'clients' => $clients,
            'projets' => self::compterProjets($livrees, $regle),
        ];
    }
}
