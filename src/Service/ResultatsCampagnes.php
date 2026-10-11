<?php

namespace App\Service;

use App\Entity\Campagne;
use App\Entity\CampagneDestinataire;
use App\Entity\Commandes;
use App\Entity\Devis;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Résultats des campagnes (maquette 35) : messages envoyés, puis devis et
 * commandes des destinataires dans les JOURS_EFFET jours après l'envoi.
 * C'est une attribution simple : un client relancé qui commande dans ce
 * délai compte pour la campagne.
 */
final class ResultatsCampagnes
{
    public const JOURS_EFFET = 30;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param list<Campagne> $campagnes
     *
     * @return array<int, array{envoyes: int, en_attente: int, echecs: int, devis: int, commandes: int}>
     */
    public function parCampagne(array $campagnes): array
    {
        $resultats = [];
        foreach ($campagnes as $campagne) {
            $debut = $campagne->getEnvoiPrevuLe() ?? $campagne->getDateCreation() ?? new \DateTimeImmutable();
            $fin = $debut->modify(sprintf('+%d days', self::JOURS_EFFET));
            $compteurs = array_column($this->em->createQueryBuilder()
                ->select('d.statut AS statut, COUNT(d.id) AS total')
                ->from(CampagneDestinataire::class, 'd')
                ->andWhere('d.campagne = :c')
                ->setParameter('c', $campagne)
                ->groupBy('d.statut')
                ->getQuery()
                ->getArrayResult(), 'total', 'statut');
            $resultats[(int) $campagne->getId()] = [
                'envoyes' => (int) ($compteurs[CampagneDestinataire::STATUT_ENVOYE] ?? 0),
                'en_attente' => (int) ($compteurs[CampagneDestinataire::STATUT_EN_ATTENTE] ?? 0),
                'echecs' => (int) ($compteurs[CampagneDestinataire::STATUT_ECHEC] ?? 0),
                'devis' => $this->compter(Devis::class, 'dateDevis', $campagne, $debut, $fin),
                'commandes' => $this->compter(Commandes::class, 'dateCommande', $campagne, $debut, $fin),
            ];
        }

        return $resultats;
    }

    /**
     * La campagne qui a apporté le plus de commandes, puis de devis.
     *
     * @param array<int, array{devis: int, commandes: int}> $resultats
     */
    public static function meilleure(array $resultats): ?int
    {
        $meilleure = null;
        foreach ($resultats as $id => $r) {
            if ($r['commandes'] + $r['devis'] > 0 && (null === $meilleure || [$r['commandes'], $r['devis']] > [$resultats[$meilleure]['commandes'], $resultats[$meilleure]['devis']])) {
                $meilleure = $id;
            }
        }

        return $meilleure;
    }

    private function compter(string $classe, string $champDate, Campagne $campagne, \DateTimeImmutable $debut, \DateTimeImmutable $fin): int
    {
        $qb = $this->em->createQueryBuilder()
            ->select('COUNT(x.id)')
            ->from($classe, 'x')
            ->andWhere(sprintf('x.%s >= :debut AND x.%s < :fin', $champDate, $champDate))
            ->andWhere('x.clients IN (SELECT IDENTITY(d.client) FROM '.CampagneDestinataire::class.' d WHERE d.campagne = :c AND d.statut = :envoye)')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $fin)
            ->setParameter('c', $campagne)
            ->setParameter('envoye', CampagneDestinataire::STATUT_ENVOYE);
        if (Commandes::class === $classe) {
            $qb->andWhere('x.deleted = false');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
