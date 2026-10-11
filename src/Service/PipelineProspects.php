<?php

namespace App\Service;

use App\Entity\Prospect;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pipeline prospects (maquette 34) : colonnes par étape et chiffres clés.
 *
 * Les affaires gagnées ou perdues ne restent affichées que
 * JOURS_CLOTURES jours ; le taux de gain se calcule sur la même fenêtre
 * et se compare à la fenêtre précédente.
 */
final class PipelineProspects
{
    public const JOURS_CLOTURES = 90;

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function donnees(\DateTimeImmutable $maintenant, string $recherche = ''): array
    {
        /** @var list<Prospect> $prospects */
        $prospects = $this->em->createQueryBuilder()
            ->select('prospect', 'agent', 'client')
            ->from(Prospect::class, 'prospect')
            ->leftJoin('prospect.agent', 'agent')
            ->leftJoin('prospect.client', 'client')
            ->andWhere('prospect.clotureLe IS NULL OR prospect.clotureLe >= :depuis')
            ->setParameter('depuis', $maintenant->modify(sprintf('-%d days', 2 * self::JOURS_CLOTURES)))
            ->orderBy('prospect.misAJourLe', 'DESC')
            ->getQuery()
            ->getResult();

        return self::calculer($prospects, $maintenant, $recherche);
    }

    /**
     * @param list<Prospect> $prospects
     *
     * @return array{colonnes: array<string, list<Prospect>>, kpi: array<string, mixed>, sources: list<array{source: string, libelle: string, nombre: int, pourcentage: int}>}
     */
    public static function calculer(array $prospects, \DateTimeImmutable $maintenant, string $recherche = ''): array
    {
        $fenetre = $maintenant->modify(sprintf('-%d days', self::JOURS_CLOTURES));
        $fenetrePrecedente = $maintenant->modify(sprintf('-%d days', 2 * self::JOURS_CLOTURES));
        $aujourdhui = $maintenant->setTime(0, 0);

        $colonnes = array_fill_keys(array_keys(Prospect::ETAPES), []);
        $pipeline = 0;
        $ouverts = 0;
        $relancesDues = 0;
        $gains = [0, 0];
        $pertes = [0, 0];
        $parSource = [];
        $recents = 0;
        $recherche = mb_strtolower(trim($recherche));

        foreach ($prospects as $p) {
            $cloture = $p->getClotureLe();
            if (!$p->estClos()) {
                $pipeline += (int) $p->getMontantEstime();
                ++$ouverts;
                if (null !== $p->getProchaineRelance() && $p->getProchaineRelance() <= $aujourdhui) {
                    ++$relancesDues;
                }
            } elseif (null !== $cloture) {
                $periode = $cloture >= $fenetre ? 0 : ($cloture >= $fenetrePrecedente ? 1 : null);
                if (null !== $periode) {
                    Prospect::ETAPE_GAGNE === $p->getEtape() ? ++$gains[$periode] : ++$pertes[$periode];
                }
            }
            if ($p->getCreeLe() >= $fenetre) {
                $parSource[$p->getSource()] = ($parSource[$p->getSource()] ?? 0) + 1;
                ++$recents;
            }
            $visible = !$p->estClos() || (null !== $cloture && $cloture >= $fenetre);
            if ($visible && self::correspond($p, $recherche)) {
                $colonnes[$p->getEtape()][] = $p;
            }
        }
        foreach ($colonnes as $etape => $liste) {
            if (!\in_array($etape, [Prospect::ETAPE_GAGNE, Prospect::ETAPE_PERDU], true)) {
                // Les relances les plus urgentes en haut, puis les plus récents.
                usort($liste, static fn (Prospect $a, Prospect $b): int => [$a->getProchaineRelance() ?? new \DateTimeImmutable('9999-12-31'), $b->getMisAJourLe()] <=> [$b->getProchaineRelance() ?? new \DateTimeImmutable('9999-12-31'), $a->getMisAJourLe()]);
                $colonnes[$etape] = $liste;
            }
        }

        arsort($parSource);
        $sources = [];
        foreach ($parSource as $source => $nombre) {
            $sources[] = [
                'source' => $source,
                'libelle' => Prospect::SOURCES_LIBELLES[$source] ?? $source,
                'nombre' => $nombre,
                'pourcentage' => (int) round($nombre * 100 / $recents),
            ];
        }
        $taux = self::taux($gains[0], $pertes[0]);
        $tauxPrecedent = self::taux($gains[1], $pertes[1]);

        return [
            'colonnes' => $colonnes,
            'kpi' => [
                'pipeline' => $pipeline,
                'ouverts' => $ouverts,
                'taux' => $taux,
                'evolution' => null === $taux || null === $tauxPrecedent ? null : $taux - $tauxPrecedent,
                'source' => $sources[0] ?? null,
                'relances_dues' => $relancesDues,
            ],
            'sources' => $sources,
        ];
    }

    public static function taux(int $gagnes, int $perdus): ?int
    {
        return 0 === $gagnes + $perdus ? null : (int) round($gagnes * 100 / ($gagnes + $perdus));
    }

    private static function correspond(Prospect $p, string $recherche): bool
    {
        if ('' === $recherche) {
            return true;
        }
        foreach ([$p->getNom(), $p->getBesoin(), $p->getTelephone()] as $texte) {
            if (null !== $texte && str_contains(mb_strtolower($texte), $recherche)) {
                return true;
            }
        }

        return false;
    }
}
