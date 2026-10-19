<?php

namespace App\Service\EspaceClient;

use App\Entity\BonLivraison;
use App\Entity\Clients;
use App\Entity\Commandes;
use App\Service\Livraisons\ProgrammationLivraison;
use App\Service\Site\ContactSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Livraisons de l'espace client (maquettes DB08 à DB13) : en route
 * maintenant, à programmer, programmées, livrées avec leur preuve et
 * retirées à l'imprimerie. Toujours limité à la fiche du client.
 */
final class LivraisonsClient
{
    /** Vitesse moyenne d'une moto en ville, pour l'heure d'arrivée estimée. */
    public const KMH = 20;

    /** Une position plus vieille que ça ne sert plus à estimer l'arrivée. */
    public const POSITION_MINUTES = 15;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EspaceClient $espace,
        private readonly ContactSite $contact,
    ) {
    }

    /** @return array{route: list<array<string, mixed>>, aProgrammer: list<array<string, mixed>>, programmees: list<array<string, mixed>>, livrees: list<array<string, mixed>>, retirees: list<array<string, mixed>>} */
    public function page(Clients $client, \DateTimeImmutable $maintenant): array
    {
        /** @var list<BonLivraison> $bons */
        $bons = $this->em->createQueryBuilder()->select('bl', 'c')->from(BonLivraison::class, 'bl')->join('bl.commande', 'c')
            ->where('c.clients = :cl')->andWhere('bl.statut != :annule')
            ->setParameter('cl', $client)->setParameter('annule', BonLivraison::STATUT_ANNULE)
            ->orderBy('bl.id', 'DESC')->setMaxResults(150)->getQuery()->getResult();
        $page = ['route' => [], 'aProgrammer' => [], 'programmees' => [], 'livrees' => [], 'retirees' => []];
        $avecBon = [];
        foreach ($bons as $bl) {
            $c = $bl->getCommande();
            $avecBon[$c->getId()] = ($avecBon[$c->getId()] ?? false) || null !== $bl->getPrevuLe() || BonLivraison::STATUT_LIVRE === $bl->getStatut();
            $ligne = ['bon' => $bl, 'commande' => $c, 'titre' => EspaceClient::titre($c)];
            match (true) {
                BonLivraison::STATUT_LIVRE === $bl->getStatut() => $page['livrees'][] = $ligne,
                null !== $bl->getEnRouteLe() && !$bl->isEchecEnCours() => $page['route'][] = $ligne + ['eta' => $this->arrivee($bl, $maintenant)],
                default => $page['programmees'][] = $ligne + ['quand' => self::quandBon($bl)],
            };
        }
        foreach ($this->espace->commandes($client, 60) as $c) {
            if (!$c->isStatut() || $c->isDeleted()) {
                continue;
            }
            if ($c->isRecupereParClient()) {
                $page['retirees'][] = ['commande' => $c, 'titre' => EspaceClient::titre($c)];
                continue;
            }
            $p = $c->getProgrammationLivraison();
            if ($c->estALivrer() && !($avecBon[$c->getId()] ?? false)) {
                if (null === $p) {
                    $page['aProgrammer'][] = ['commande' => $c, 'titre' => EspaceClient::titre($c), 'pret' => $c->getDateLivraison()];
                } elseif (ProgrammationLivraison::MODE_LIVRAISON === ($p['mode'] ?? null)) {
                    $page['programmees'][] = ['bon' => null, 'commande' => $c, 'titre' => EspaceClient::titre($c), 'quand' => ProgrammationLivraison::quand($p)];
                }
            }
        }

        return $page;
    }

    /** Le bon de livraison du client, ou null. */
    public function bon(Clients $client, string $publicId): ?BonLivraison
    {
        if (!Uuid::isValid($publicId)) {
            return null;
        }
        $bon = $this->em->getRepository(BonLivraison::class)->findOneBy(['publicId' => Uuid::fromString($publicId)]);

        return $bon instanceof BonLivraison && $bon->getCommande()?->getClients()?->getId() === $client->getId() && BonLivraison::STATUT_ANNULE !== $bon->getStatut() ? $bon : null;
    }

    /**
     * Suivi en direct (maquette DB10) : position du livreur, destination,
     * heure d'arrivée estimée, distance, étapes.
     *
     * @return array<string, mixed>
     */
    public function suivi(BonLivraison $bon, \DateTimeImmutable $maintenant): array
    {
        $arrivee = $this->arrivee($bon, $maintenant);
        [$rang, $arrets] = $this->arret($bon);
        $livre = BonLivraison::STATUT_LIVRE === $bon->getStatut();
        $etapes = [
            ['libelle' => 'Préparée et emballée', 'le' => $bon->getValideLe(), 'etat' => null !== $bon->getValideLe() ? 'faite' : 'a_venir'],
            ['libelle' => 'Partie de l\'atelier', 'le' => $bon->getEnRouteLe() ?? $bon->getChargeLe(), 'etat' => null !== ($bon->getEnRouteLe() ?? $bon->getChargeLe()) ? 'faite' : 'a_venir'],
            ['libelle' => 'En route'.(null !== $rang ? ' · '.$rang.($rang > 1 ? 'e' : 'er').' arrêt sur '.$arrets : ''), 'le' => null, 'etat' => $livre ? 'faite' : (null !== $bon->getEnRouteLe() ? 'en_cours' : 'a_venir')],
            ['libelle' => 'Remise avec votre code', 'le' => $livre ? $bon->getLivreLe() : $arrivee['heure'] ?? null, 'etat' => $livre ? 'faite' : 'a_venir'],
        ];

        return $arrivee + ['etapes' => $etapes, 'depart' => $this->depart()];
    }

    /**
     * Heure d'arrivée estimée : distance à vol d'oiseau × 1,3 (les rues) à
     * KMH ; sans position récente, null.
     *
     * @return array{heure: ?\DateTimeImmutable, minutes: ?int, km: ?float, livreur: ?array{lat: float, lng: float}, destination: ?array{lat: float, lng: float}}
     */
    public function arrivee(BonLivraison $bon, \DateTimeImmutable $maintenant): array
    {
        $destination = self::destination($bon);
        $position = null;
        if (null !== $bon->getLivreurLatitude() && null !== $bon->getLivreurPositionLe() && $bon->getLivreurPositionLe() > $maintenant->modify('-'.self::POSITION_MINUTES.' minutes')) {
            $position = ['lat' => $bon->getLivreurLatitude(), 'lng' => $bon->getLivreurLongitude()];
        }
        if (null === $destination || null === $position) {
            return ['heure' => null, 'minutes' => null, 'km' => null, 'livreur' => $position, 'destination' => $destination];
        }
        $km = round(self::distance($position['lat'], $position['lng'], $destination['lat'], $destination['lng']) * 1.3, 1);
        $minutes = (int) ceil($km / self::KMH * 60) + 2;

        return ['heure' => $maintenant->modify('+'.$minutes.' minutes'), 'minutes' => $minutes, 'km' => $km, 'livreur' => $position, 'destination' => $destination];
    }

    /** Où livrer : la position envoyée en programmant, sinon celle de la fiche client. */
    public static function destination(BonLivraison $bon): ?array
    {
        $p = $bon->getCommande()?->getProgrammationLivraison() ?? [];
        if (is_numeric($p['latitude'] ?? null) && is_numeric($p['longitude'] ?? null)) {
            return ['lat' => (float) $p['latitude'], 'lng' => (float) $p['longitude']];
        }
        $client = $bon->getCommande()?->getClients();

        return null !== $client && $client->estLocalise() ? ['lat' => (float) $client->getLatitude(), 'lng' => (float) $client->getLongitude()] : null;
    }

    /** Kilomètres à vol d'oiseau. */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** « jeu. 23/10 · 10 h – 12 h », ou null sans date. */
    public static function quandBon(BonLivraison $bl): ?string
    {
        return null === $bl->getPrevuLe() ? null : ProgrammationLivraison::jourCourt($bl->getPrevuLe()).($bl->getCreneau() ? ' · '.$bl->getCreneau() : '');
    }

    /**
     * Rang de l'arrêt dans la tournée du jour du livreur, et le nombre
     * d'arrêts encore à faire ou faits ce jour-là (sans rien dire des autres clients).
     *
     * @return array{0: ?int, 1: ?int}
     */
    private function arret(BonLivraison $bon): array
    {
        if (null === $bon->getOrdreTournee() || null === $bon->getPrevuLe() || null === $bon->getLivreurNom()) {
            return [null, null];
        }
        $arrets = (int) $this->em->createQueryBuilder()->select('COUNT(bl.id)')->from(BonLivraison::class, 'bl')
            ->where('bl.prevuLe = :jour')->andWhere('bl.livreurNom = :nom')->andWhere('bl.statut != :annule')
            ->setParameter('jour', $bon->getPrevuLe())->setParameter('nom', $bon->getLivreurNom())->setParameter('annule', BonLivraison::STATUT_ANNULE)
            ->getQuery()->getSingleScalarResult();

        return [$bon->getOrdreTournee(), max($arrets, $bon->getOrdreTournee())];
    }

    /** Position de l'imprimerie (point de départ sur la carte), si elle est réglée. */
    private function depart(): ?array
    {
        $p = $this->contact->position();

        return null === $p ? null : ['lat' => $p['latitude'], 'lng' => $p['longitude']];
    }
}
