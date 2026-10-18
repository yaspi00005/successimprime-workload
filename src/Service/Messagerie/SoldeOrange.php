<?php

namespace App\Service\Messagerie;

use App\Service\OrangeSmsService;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Solde SMS lu chez Orange (Clients › Crédits SMS et e-mail) : contrats
 * (unités restantes, fin du pack) et achats de packs, par les adresses
 * d'administration d'Orange avec le jeton déjà utilisé pour envoyer.
 *
 * La lecture est gardée une heure : une page ouverte ne rappelle pas
 * Orange. « Actualiser » relit tout de suite, au plus une fois par minute.
 * Une lecture ratée est retentée après 5 minutes et garde les derniers
 * chiffres lus, avec l'erreur (nettoyée : ni jeton ni clé, jamais).
 */
final class SoldeOrange
{
    /** Pays du contrat Orange de la boutique (code ISO à trois lettres). */
    public const PAYS = 'MLI';

    public const DUREE_CACHE = 3600;

    /** Lecture ratée : réessayée après 5 minutes, pas à chaque page. */
    public const DUREE_CACHE_ERREUR = 300;

    /** « Actualiser » relit chez Orange au plus une fois par minute. */
    public const INTERVALLE_ACTUALISER = 60;

    private const CLE_CACHE = 'credits_messages.orange';
    private const CLE_DERNIERE = 'credits_messages.orange_derniere';

    public function __construct(
        private readonly OrangeSmsService $sms,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Lit chez Orange (ou dans le cache d'une heure). Une lecture ratée
     * garde les derniers chiffres lus, avec l'erreur.
     *
     * @return array{contrats: ?list<array<string, mixed>>, achats: list<array<string, mixed>>, luLe: ?\DateTimeImmutable, tenteLe: \DateTimeImmutable, erreur: ?string}
     */
    public function lire(\DateTimeImmutable $maintenant, bool $actualiser = false): array
    {
        $item = $this->cache->getItem(self::CLE_CACHE);
        $enCache = $item->isHit() ? $item->get() : null;
        $recent = \is_array($enCache) && ($enCache['tenteLe'] ?? null) instanceof \DateTimeImmutable && $maintenant->getTimestamp() - $enCache['tenteLe']->getTimestamp() < self::INTERVALLE_ACTUALISER;
        if (\is_array($enCache) && (!$actualiser || $recent)) {
            return $enCache;
        }

        try {
            $contrats = self::lireContrats($this->sms->lireContrats());
            try {
                $achats = self::lireAchats($this->sms->lireAchats());
            } catch (\Throwable) {
                $achats = []; // l'historique des achats n'empêche pas d'afficher le solde
            }
            $resultat = ['contrats' => $contrats, 'achats' => $achats, 'luLe' => $maintenant, 'tenteLe' => $maintenant, 'erreur' => null];
            $derniere = $this->cache->getItem(self::CLE_DERNIERE);
            $this->cache->save($derniere->set($resultat));
            $duree = self::DUREE_CACHE;
        } catch (\Throwable $e) {
            $derniere = $this->cache->getItem(self::CLE_DERNIERE);
            $avant = $derniere->isHit() && \is_array($derniere->get()) ? $derniere->get() : ['contrats' => null, 'achats' => [], 'luLe' => null];
            $resultat = ['contrats' => $avant['contrats'], 'achats' => $avant['achats'], 'luLe' => $avant['luLe'], 'tenteLe' => $maintenant, 'erreur' => JournalEnvois::nettoyer(mb_substr($e->getMessage(), 0, 300))];
            $duree = self::DUREE_CACHE_ERREUR;
        }
        $this->cache->save($item->set($resultat)->expiresAfter($duree));

        return $resultat;
    }

    /**
     * Contrats lus chez Orange (format actuel : une liste ; ancien format :
     * partnerContracts.contracts[].serviceContracts[]). Ceux du Mali, ou
     * tous si aucun n'est du Mali.
     *
     * @param array<mixed> $json
     *
     * @return list<array{pays: string, offre: string, unites: ?int, expiration: ?\DateTimeImmutable, actif: bool}>
     */
    public static function lireContrats(array $json, string $pays = self::PAYS): array
    {
        $liste = array_is_list($json) ? $json : [];
        foreach ((array) ($json['partnerContracts']['contracts'] ?? []) as $contrat) {
            foreach ((array) ($contrat['serviceContracts'] ?? []) as $sc) {
                if (\is_array($sc)) {
                    $liste[] = $sc + ['offerName' => $contrat['service'] ?? ''];
                }
            }
        }

        $lignes = [];
        foreach ($liste as $c) {
            if (!\is_array($c)) {
                continue;
            }
            $unites = $c['availableUnits'] ?? null;
            $statut = strtoupper((string) ($c['status'] ?? 'ACTIVE'));
            $lignes[] = [
                'pays' => strtoupper((string) ($c['country'] ?? '')),
                'offre' => (string) ($c['offerName'] ?? $c['service'] ?? ''),
                'unites' => is_numeric($unites) ? (int) $unites : null,
                'expiration' => self::date($c['expirationDate'] ?? $c['expires'] ?? null),
                'actif' => 'EXPIRED' !== $statut,
            ];
        }
        $duPays = array_values(array_filter($lignes, static fn (array $l): bool => $pays === $l['pays']));

        return [] !== $duPays ? $duPays : $lignes;
    }

    /**
     * Achats de packs lus chez Orange, les plus récents d'abord (20 au plus).
     *
     * @param array<mixed> $json
     *
     * @return list<array{le: ?\DateTimeImmutable, pack: string, unites: ?int, expiration: ?\DateTimeImmutable, prix: ?float, devise: string}>
     */
    public static function lireAchats(array $json, string $pays = self::PAYS): array
    {
        $liste = array_is_list($json) ? $json : (array) ($json['purchaseOrders'] ?? []);
        $achats = [];
        foreach ($liste as $a) {
            if (!\is_array($a)) {
                continue;
            }
            $execution = \is_array($a['orderExecutioninformation'] ?? null) ? $a['orderExecutioninformation'] : [];
            $lePays = strtoupper((string) ($a['country'] ?? $execution['country'] ?? ''));
            if ('' !== $lePays && $pays !== $lePays) {
                continue;
            }
            $avant = $a['oldAvailableUnits'] ?? null;
            $apres = $a['newAvailableUnits'] ?? null;
            $prix = $a['price'] ?? $execution['amount'] ?? null;
            $achats[] = [
                'le' => self::date($a['purchaseDate'] ?? $execution['date'] ?? null),
                'pack' => (string) ($a['bundleDescription'] ?? ''),
                'unites' => is_numeric($avant) && is_numeric($apres) ? (int) $apres - (int) $avant : null,
                'expiration' => self::date($a['newExpirationDate'] ?? null),
                'prix' => is_numeric($prix) ? (float) $prix : null,
                'devise' => (string) ($a['currency'] ?? $execution['currency'] ?? ''),
            ];
        }
        usort($achats, static fn (array $x, array $y): int => ($y['le']?->getTimestamp() ?? 0) <=> ($x['le']?->getTimestamp() ?? 0));

        return \array_slice($achats, 0, 20);
    }

    /**
     * Solde et fin du pack d'après les contrats encore actifs : unités
     * additionnées, la fin la plus proche.
     *
     * @param list<array{unites: ?int, expiration: ?\DateTimeImmutable, actif: bool}> $contrats
     *
     * @return array{unites: ?int, expiration: ?\DateTimeImmutable}
     */
    public static function resumerContrats(array $contrats): array
    {
        $actifs = array_values(array_filter($contrats, static fn (array $c): bool => $c['actif']));
        if ([] === $actifs) {
            // Tout est expiré : il ne reste rien, la date de fin la plus récente le dit.
            $fins = array_filter(array_column($contrats, 'expiration'));

            return ['unites' => [] === $contrats ? null : 0, 'expiration' => [] === $fins ? null : max($fins)];
        }
        $unites = array_filter(array_column($actifs, 'unites'), static fn (?int $u): bool => null !== $u);
        $fins = array_filter(array_column($actifs, 'expiration'));

        return ['unites' => [] === $unites ? null : array_sum($unites), 'expiration' => [] === $fins ? null : min($fins)];
    }

    private static function date(mixed $valeur): ?\DateTimeImmutable
    {
        if (!\is_string($valeur) || '' === trim($valeur)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($valeur))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Exception) {
            return null;
        }
    }
}
