<?php

namespace App\Service\Site;

use App\Entity\CommandesDetails;
use App\Entity\Machines;
use App\Entity\OrdreProduction;
use App\Entity\ReglageSite;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * « En direct de l'atelier » sur l'accueil (textes du gérant, règle 4 :
 * montrer au lieu de promettre) : machines en marche, mètres carrés
 * imprimés aujourd'hui, travaux en cours. Jamais un chiffre inventé :
 *
 * - au local, les chiffres sont lus dans l'ERP (ordres de fabrication en
 *   cours, lignes terminées aujourd'hui, lignes en production) ;
 * - le site ne connaît pas les machines : l'agent de synchronisation du
 *   local publie l'instantané (publier(), réglage du site « atelier_direct »,
 *   seulement s'il a changé ou toutes les 10 minutes) et le site ne
 *   l'affiche que s'il a moins de FRAICHEUR minutes ;
 * - un chiffre à zéro n'est pas montré, et rien n'est montré atelier fermé.
 */
class AtelierDirect
{
    public const CLE_REGLAGE = 'atelier_direct';
    /** Au-delà, l'instantané reçu par le site est trop vieux : le bloc disparaît. */
    public const FRAICHEUR = 20;
    /** Le local republie au moins toutes les 10 minutes, même sans changement. */
    public const REPUBLIER = 10;

    private ?array $memo = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne = false,
    ) {
    }

    /**
     * Chiffres à montrer, ou null (rien de frais, ou tout à zéro).
     *
     * @return array{machines: int, total: int, m2: float, travaux: int, maj: \DateTimeImmutable}|null
     */
    public function chiffres(\DateTimeImmutable $maintenant): ?array
    {
        if (null !== $this->memo) {
            return $this->memo['c'];
        }
        $c = $this->enLigne ? self::lire($this->reglage()?->getValeurs() ?? [], $maintenant) : $this->calculer($maintenant);

        return ($this->memo = ['c' => null !== $c && self::vide($c) ? null : $c])['c'];
    }

    /**
     * Au local (agent de synchronisation) : écrit l'instantané s'il a changé
     * ou s'il a plus de REPUBLIER minutes. Le réglage descend vers le site
     * comme les autres (type « site_reglage »). Renvoie vrai s'il a écrit.
     */
    public function publier(\DateTimeImmutable $maintenant): bool
    {
        if ($this->enLigne) {
            return false;
        }
        $c = $this->calculer($maintenant);
        $valeurs = ['machines' => $c['machines'], 'total' => $c['total'], 'm2' => $c['m2'], 'travaux' => $c['travaux']];
        $reglage = $this->reglage();
        $avant = $reglage?->getValeurs() ?? [];
        $maj = isset($avant['maj']) ? \DateTimeImmutable::createFromFormat(\DATE_ATOM, (string) $avant['maj']) : false;
        $pareil = array_intersect_key($avant, $valeurs) == $valeurs;
        if ($pareil && false !== $maj && $maj > $maintenant->modify('-'.self::REPUBLIER.' minutes')) {
            return false;
        }
        if (null === $reglage) {
            $reglage = new ReglageSite(self::CLE_REGLAGE);
            $this->em->persist($reglage);
        }
        $reglage->setValeurs($valeurs + ['maj' => $maintenant->format(\DATE_ATOM)]);
        $this->em->flush();

        return true;
    }

    /**
     * Instantané reçu par le site, s'il est frais.
     *
     * @param array<string, mixed> $valeurs
     *
     * @return array{machines: int, total: int, m2: float, travaux: int, maj: \DateTimeImmutable}|null
     */
    public static function lire(array $valeurs, \DateTimeImmutable $maintenant): ?array
    {
        $maj = \DateTimeImmutable::createFromFormat(\DATE_ATOM, (string) ($valeurs['maj'] ?? ''));
        if (false === $maj || $maj < $maintenant->modify('-'.self::FRAICHEUR.' minutes') || $maj > $maintenant->modify('+5 minutes')) {
            return null;
        }
        $total = max(0, (int) ($valeurs['total'] ?? 0));

        return [
            'machines' => min($total, max(0, (int) ($valeurs['machines'] ?? 0))),
            'total' => $total,
            'm2' => max(0.0, round((float) ($valeurs['m2'] ?? 0), 1)),
            'travaux' => max(0, (int) ($valeurs['travaux'] ?? 0)),
            'maj' => $maj,
        ];
    }

    /** @param array{machines: int, m2: float, travaux: int} $c */
    public static function vide(array $c): bool
    {
        return 0 === $c['machines'] && 0.0 === (float) $c['m2'] && 0 === $c['travaux'];
    }

    /** @return array{machines: int, total: int, m2: float, travaux: int, maj: \DateTimeImmutable} */
    private function calculer(\DateTimeImmutable $maintenant): array
    {
        $machines = (int) $this->em->createQueryBuilder()
            ->select('COUNT(DISTINCT IDENTITY(o.machine))')
            ->from(OrdreProduction::class, 'o')
            ->where('o.machine IS NOT NULL AND o.statut = :enCours')
            ->setParameter('enCours', OrdreProduction::STATUT_EN_COURS)
            ->getQuery()->getSingleScalarResult();
        $total = (int) $this->em->createQueryBuilder()->select('COUNT(m.id)')->from(Machines::class, 'm')->getQuery()->getSingleScalarResult();
        $debut = $maintenant->setTime(0, 0);
        $m2 = (float) $this->em->createQueryBuilder()
            ->select('COALESCE(SUM(d.surface * d.quantite), 0)')
            ->from(CommandesDetails::class, 'd')
            ->join('d.commande', 'c')
            ->where('c.deleted = false AND d.surface IS NOT NULL')
            ->andWhere('d.productionTermineeLe >= :debut AND d.productionTermineeLe < :fin')
            ->setParameter('debut', $debut)
            ->setParameter('fin', $debut->modify('+1 day'))
            ->getQuery()->getSingleScalarResult();
        $travaux = (int) $this->em->createQueryBuilder()
            ->select('COUNT(d.id)')
            ->from(CommandesDetails::class, 'd')
            ->join('d.commande', 'c')
            ->where('c.deleted = false AND d.statutProduction = :enProduction')
            ->setParameter('enProduction', CommandesDetails::PRODUCTION_EN_COURS)
            ->getQuery()->getSingleScalarResult();

        return ['machines' => min($machines, $total), 'total' => $total, 'm2' => round($m2, 1), 'travaux' => $travaux, 'maj' => $maintenant];
    }

    private function reglage(): ?ReglageSite
    {
        return $this->em->getRepository(ReglageSite::class)->findOneBy(['cle' => self::CLE_REGLAGE]);
    }
}
