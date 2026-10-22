<?php

namespace App\Service\Site;

use App\Entity\Commandes;
use App\Entity\CommandesDetails;
use App\Entity\ControlePrePresse;
use App\Entity\ReclamationClient;
use App\Entity\ReglageSite;
use App\Service\Messagerie\DelaisReponse;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tableau des engagements de la direction (maquette EC09) : ce que
 * l'atelier a vraiment tenu sur un mois, mesuré dans les données de
 * l'ERP local. Rien n'est montré sur le site tant que la direction n'a
 * pas cliqué « Publier les chiffres sur le site » (ReglageSite
 * engagements_chiffres, descendu par la synchronisation).
 *
 *  - à la date : commandes dont la date annoncée tombe dans le mois (et
 *    est passée), toutes lignes terminées au plus tard ce jour-là ;
 *  - BAT : contrôles pré-presse partis en production dans le mois qui
 *    demandaient un BAT, avec le BAT validé ;
 *  - réclamations : réclamations du mois résolues ;
 *  - réponse : conversations du mois sans réponse en retard (supervision
 *    de la messagerie clients).
 *
 * Un chiffre sans aucune mesure reste vide (jamais 100 % par défaut).
 */
class TableauEngagements
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ?DelaisReponse $delais = null,
    ) {
    }

    /**
     * @return array{
     *   a_la_date: array{pct: ?int, total: int, retards: list<array{commande: Commandes, retardHeures: int, terminee: bool}>},
     *   bat: array{pct: ?int, total: int, sans: int},
     *   reclamations_resolues: array{pct: ?int, total: int, liste: list<ReclamationClient>},
     *   reponse: array{pct: ?int, total: int, enRetard: int}
     * }
     */
    public function mesurer(\DateTimeImmutable $debut, \DateTimeImmutable $fin, \DateTimeImmutable $maintenant): array
    {
        return [
            'a_la_date' => $this->aLaDate($debut, min($fin, $maintenant)),
            'bat' => $this->bat($debut, $fin),
            'reclamations_resolues' => $this->reclamations($debut, $fin),
            'reponse' => $this->reponse($debut, $fin),
        ];
    }

    /** Pourcentage arrondi vers le bas (on n'arrondit pas 99,6 % en 100 %), null sans mesure. */
    public static function pourcentage(int $tenus, int $total): ?int
    {
        return $total > 0 ? (int) floor(100 * $tenus / $total) : null;
    }

    /**
     * Publie les chiffres validés : seulement ceux qui ont une mesure.
     *
     * @param array<string, array{pct: ?int}> $mesures
     */
    public function publier(array $mesures, string $periode, \DateTimeImmutable $maintenant): int
    {
        $valeurs = [];
        foreach (array_keys(EngagementsSite::LIBELLES) as $cle) {
            $pct = $mesures[$cle]['pct'] ?? null;
            if (null !== $pct && $pct > 0) {
                $valeurs[$cle] = $pct;
            }
        }
        $reglage = $this->em->getRepository(ReglageSite::class)->findOneBy(['cle' => EngagementsSite::CLE_CHIFFRES]);
        if (null === $reglage) {
            $reglage = new ReglageSite(EngagementsSite::CLE_CHIFFRES);
            $this->em->persist($reglage);
        }
        $reglage->setValeurs($valeurs + ['periode' => $periode, 'publie_le' => $maintenant->format(\DATE_ATOM)]);
        $this->em->flush();

        return \count($valeurs);
    }

    /** Retire les chiffres du site (la page montre alors les promesses seules). */
    public function retirer(): void
    {
        $reglage = $this->em->getRepository(ReglageSite::class)->findOneBy(['cle' => EngagementsSite::CLE_CHIFFRES]);
        if (null !== $reglage) {
            $reglage->setValeurs([]);
            $this->em->flush();
        }
    }

    /** @return array{pct: ?int, total: int, retards: list<array{commande: Commandes, retardHeures: int, terminee: bool}>} */
    private function aLaDate(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $commandes = $this->em->createQuery('SELECT c FROM '.Commandes::class.' c WHERE c.deleted = false AND c.statut = true AND c.dateLivraison >= :a AND c.dateLivraison < :b')
            ->setParameter('a', $debut)->setParameter('b', $fin->setTime(0, 0))->getResult();
        if ([] === $commandes) {
            return ['pct' => null, 'total' => 0, 'retards' => []];
        }
        $lignes = $this->em->createQuery('SELECT IDENTITY(d.commande) AS commande, d.statutProduction AS statut, d.productionTermineeLe AS fin FROM '.CommandesDetails::class.' d WHERE d.commande IN (:c)')
            ->setParameter('c', $commandes)->getArrayResult();
        $parCommande = [];
        foreach ($lignes as $l) {
            $parCommande[(int) $l['commande']][] = $l;
        }
        $total = 0;
        $tenus = 0;
        $retards = [];
        foreach ($commandes as $c) {
            $ls = array_filter($parCommande[$c->getId()] ?? [], static fn (array $l): bool => CommandesDetails::PRODUCTION_ANNULEE !== $l['statut']);
            if ([] === $ls) {
                continue;
            }
            ++$total;
            $limite = \DateTimeImmutable::createFromInterface($c->getDateLivraison())->setTime(23, 59, 59);
            $derniere = null;
            $finie = true;
            foreach ($ls as $l) {
                if (null === $l['fin']) {
                    $finie = false;
                    continue;
                }
                $derniere = max($derniere, $l['fin']);
            }
            if ($finie && null !== $derniere && $derniere <= $limite) {
                ++$tenus;
                continue;
            }
            $jusqua = $finie && null !== $derniere ? $derniere : new \DateTimeImmutable();
            $retards[] = ['commande' => $c, 'retardHeures' => max(1, (int) ceil(($jusqua->getTimestamp() - $limite->getTimestamp()) / 3600)), 'terminee' => $finie];
        }
        usort($retards, static fn (array $a, array $b): int => $b['retardHeures'] <=> $a['retardHeures']);

        return ['pct' => self::pourcentage($tenus, $total), 'total' => $total, 'retards' => \array_slice($retards, 0, 20)];
    }

    /** @return array{pct: ?int, total: int, sans: int} */
    private function bat(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $r = $this->em->createQuery('SELECT COUNT(p.id) AS total, SUM(CASE WHEN p.batValide = true THEN 1 ELSE 0 END) AS valides FROM '.ControlePrePresse::class.' p WHERE p.batNecessaire = true AND p.envoyeProductionLe >= :a AND p.envoyeProductionLe < :b')
            ->setParameter('a', $debut)->setParameter('b', $fin)->getSingleResult();
        $total = (int) $r['total'];
        $valides = (int) $r['valides'];

        return ['pct' => self::pourcentage($valides, $total), 'total' => $total, 'sans' => $total - $valides];
    }

    /** @return array{pct: ?int, total: int, liste: list<ReclamationClient>} */
    private function reclamations(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        $liste = $this->em->createQuery('SELECT r FROM '.ReclamationClient::class.' r WHERE r.creeLe >= :a AND r.creeLe < :b ORDER BY r.creeLe DESC')
            ->setParameter('a', $debut)->setParameter('b', $fin)->getResult();
        $resolues = \count(array_filter($liste, static fn (ReclamationClient $r): bool => ReclamationClient::RESOLUE === $r->getStatut()));

        return ['pct' => self::pourcentage($resolues, \count($liste)), 'total' => \count($liste), 'liste' => \array_slice($liste, 0, 8)];
    }

    /** @return array{pct: ?int, total: int, enRetard: int} */
    private function reponse(\DateTimeImmutable $debut, \DateTimeImmutable $fin): array
    {
        if (null === $this->delais) {
            return ['pct' => null, 'total' => 0, 'enRetard' => 0];
        }
        $s = $this->delais->supervision($debut, $fin);
        $total = (int) $s['conversations'];

        return ['pct' => null === $s['pctRetard'] || 0 === $total ? null : 100 - (int) $s['pctRetard'], 'total' => $total, 'enRetard' => (int) $s['enRetard']];
    }
}
