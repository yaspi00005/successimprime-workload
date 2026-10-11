<?php

namespace App\Service\SuiviMetiers;

use App\Entity\BonLivraison;
use App\Entity\User;
use App\Service\Livraisons\Tournee;
use App\Service\Parametres\Parametres;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Points du livreur (docs/suivi-metiers.md, maquette W04) : chaque
 * livraison remise rapporte les points du barème « Livreur ». Un retard
 * justifié par le bureau ne coûte rien et sort le créneau des points
 * possibles ; l'encaissement compte quand la caisse le valide le jour même.
 */
final class PointsLivreur
{
    /** Au-delà, le retard retire des points. */
    public const RETARD_MAX = 30;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Tournee $tournee,
        private readonly Parametres $parametres,
    ) {
    }

    /** Minutes de retard après la fin du créneau (null : pas de créneau ou pas remise). */
    public static function retard(BonLivraison $b): ?int
    {
        $livre = $b->getLivreLe();
        if ($livre === null || !preg_match('/(\d{1,2})[:h](\d{2})\s*$/', (string) $b->getCreneau(), $m)) {
            return null;
        }
        $fin = $livre->setTime((int) $m[1], (int) $m[2]);

        return max(0, intdiv($livre->getTimestamp() - $fin->getTimestamp(), 60));
    }

    /** Photo, signature et position GPS. */
    public static function preuveComplete(BonLivraison $b): bool
    {
        return $b->getPhotos() !== [] && $b->getSignature() !== null && $b->getRemisePosition() !== null;
    }

    /** Encaissement validé à la caisse le jour de la remise. */
    public static function encaissementDuJour(BonLivraison $b): bool
    {
        $valide = $b->getPaiementLivraison()?->getDateValidation();

        return (int) $b->getEncaisseMontant() > 0 && $valide !== null && $b->getLivreLe() !== null
            && $valide->format('Y-m-d') <= $b->getLivreLe()->format('Y-m-d');
    }

    /**
     * Règles obtenues par une livraison remise.
     *
     * @return array<string, int>
     */
    public static function faits(BonLivraison $b): array
    {
        if ($b->getStatut() !== BonLivraison::STATUT_LIVRE) {
            return [];
        }
        $faits = [];
        if (Tournee::aLHeure($b)) {
            $faits['l_creneau'] = 1;
        }
        if (self::preuveComplete($b)) {
            $faits['l_preuve'] = 1;
        }
        if (self::encaissementDuJour($b)) {
            $faits['l_encaissement'] = 1;
        }
        if ((self::retard($b) ?? 0) > self::RETARD_MAX && $b->getRetardJustifie() === null) {
            $faits['l_retard'] = 1;
        }
        if ($b->getErreurLivraison() !== null) {
            $faits['l_erreur'] = 1;
        }

        return $faits;
    }

    /**
     * Ce qu'aurait rapporté la livraison parfaite.
     *
     * @return array<string, int>
     */
    public static function parfaits(BonLivraison $b): array
    {
        if ($b->getStatut() !== BonLivraison::STATUT_LIVRE) {
            return [];
        }
        $faits = ['l_preuve' => 1];
        if ($b->getRetardJustifie() === null || Tournee::aLHeure($b)) {
            $faits['l_creneau'] = 1;
        }
        if ((int) $b->getEncaisseMontant() > 0) {
            $faits['l_encaissement'] = 1;
        }

        return $faits;
    }

    /**
     * Détail des points d'une livraison, ligne par ligne.
     *
     * @return array{lignes: list<array{libelle: string, points: int}>, total: int, attente: bool}
     */
    public function detail(BonLivraison $b): array
    {
        $bareme = $this->parametres->bareme();
        $lignes = [];
        foreach (self::faits($b) as $regle => $q) {
            $lignes[] = ['libelle' => Bareme::METIERS[Bareme::LIVREUR]['regles'][$regle][0], 'points' => $bareme->points([$regle => $q])];
        }

        return [
            'lignes' => $lignes,
            'total' => $bareme->points(self::faits($b)),
            'attente' => $b->encaissementProvisoire(),
        ];
    }

    /**
     * Journée d'un livreur : points de chaque livraison, du jour et du mois.
     *
     * @return array{bons: list<BonLivraison>, points: array<int, int>, jour: int, depart: ?\DateTimeImmutable, mois: array{gagnes: int, possibles: int, note: int, livrees: int, ponctualite: ?int}}
     */
    public function journee(User $livreur, \DateTimeImmutable $jour): array
    {
        $bareme = $this->parametres->bareme();
        $bons = $this->tournee->duJour($jour, $livreur);
        $points = [];
        $depart = null;
        foreach ($bons as $b) {
            if ($b->getStatut() === BonLivraison::STATUT_LIVRE) {
                $points[$b->getId()] = $bareme->points(self::faits($b));
            }
            if ($b->getEnRouteLe() !== null && ($depart === null || $b->getEnRouteLe() < $depart)) {
                $depart = $b->getEnRouteLe();
            }
        }

        return ['bons' => $bons, 'points' => $points, 'jour' => array_sum($points), 'depart' => $depart, 'mois' => $this->mois($livreur, $jour)];
    }

    /**
     * Points du mois : gagnés, possibles, note sur 100 et ponctualité.
     *
     * @return array{gagnes: int, possibles: int, note: int, livrees: int, ponctualite: ?int}
     */
    public function mois(User $livreur, \DateTimeImmutable $dans): array
    {
        $debut = $dans->modify('first day of this month')->setTime(0, 0);
        /** @var list<BonLivraison> $bons */
        $bons = $this->em->getRepository(BonLivraison::class)->createQueryBuilder('b')
            ->where('b.livreur = :l')->andWhere('b.statut = :s')
            ->andWhere('b.livreLe >= :debut')->andWhere('b.livreLe < :fin')
            ->setParameter('l', $livreur)->setParameter('s', BonLivraison::STATUT_LIVRE)
            ->setParameter('debut', $debut)->setParameter('fin', $debut->modify('+1 month'))
            ->getQuery()->getResult();

        return self::totaux($bons, $this->parametres->bareme());
    }

    /**
     * @param list<BonLivraison> $bons livraisons remises
     *
     * @return array{gagnes: int, possibles: int, note: int, livrees: int, ponctualite: ?int}
     */
    public static function totaux(array $bons, Bareme $bareme): array
    {
        $gagnes = 0;
        $possibles = 0;
        $aLHeure = 0;
        foreach ($bons as $b) {
            $gagnes += $bareme->points(self::faits($b));
            $possibles += $bareme->possibles(self::parfaits($b));
            $aLHeure += Tournee::aLHeure($b) ? 1 : 0;
        }

        return [
            'gagnes' => $gagnes,
            'possibles' => $possibles,
            'note' => Bareme::note($gagnes, $possibles),
            'livrees' => \count($bons),
            'ponctualite' => $bons !== [] ? (int) round($aLHeure * 100 / \count($bons)) : null,
        ];
    }
}
