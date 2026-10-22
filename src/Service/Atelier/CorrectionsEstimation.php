<?php

namespace App\Service\Atelier;

use App\Entity\Machines;
use App\Entity\OrdreProduction;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Les temps réels améliorent les prochaines estimations (PD06,
 * docs/production-colis.md) : pour une machine, les facteurs par étape
 * (FacteursEstimation) tirés des travaux terminés dont l'étape a un temps
 * réel et un temps proposé (OrdreProduction::getTempsReels()['etapes']).
 * Une pièce refaite ne compte pas : son passage n'est pas un travail
 * normal. Lecture seule.
 */
final class CorrectionsEstimation
{
    /** Travaux relus par machine : assez pour trouver 20 échantillons par étape. */
    private const TRAVAUX_RELUS = 120;

    /** @var array<int, array<string, array{facteur: float, nombre: int}|null>> */
    private array $cache = [];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array<string, array{facteur: float, nombre: int}|null> clé d'étape => facteur (null : pas assez de travaux)
     */
    public function facteurs(?Machines $machine): array
    {
        if (null === $machine?->getId()) {
            return [];
        }
        $id = (int) $machine->getId();
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }
        $rangs = $this->em->createQueryBuilder()->select('o.tempsReels AS temps')->from(OrdreProduction::class, 'o')
            ->where('o.machine = :m')->andWhere('o.termineLe IS NOT NULL')->andWhere('o.tempsReels IS NOT NULL')->andWhere('o.reimpressions = 0')
            ->setParameter('m', $machine)
            ->orderBy('o.termineLe', 'DESC')->setMaxResults(self::TRAVAUX_RELUS)
            ->getQuery()->getArrayResult();

        return $this->cache[$id] = self::depuisTemps(array_column($rangs, 'temps'));
    }

    /**
     * @param list<array<string, mixed>|null> $temps tempsReels du plus récent au plus ancien
     *
     * @return array<string, array{facteur: float, nombre: int}|null>
     */
    public static function depuisTemps(array $temps): array
    {
        $echantillons = [];
        foreach ($temps as $t) {
            foreach (\is_array($t['etapes'] ?? null) ? $t['etapes'] : [] as $cle => $e) {
                if (\is_array($e) && empty($e['reprise'])) {
                    $echantillons[(string) $cle][] = ['reel' => isset($e['reel']) ? (int) $e['reel'] : null, 'prevu' => isset($e['prevu']) ? (int) $e['prevu'] : null];
                }
            }
        }
        $facteurs = [];
        foreach ($echantillons as $cle => $liste) {
            $facteurs[$cle] = FacteursEstimation::facteur($liste);
        }

        return $facteurs;
    }

    /**
     * Les étapes proposées (OP03) corrigées par les temps réels de la
     * machine : « propose » corrigé, « brut » gardé, « correction » dit
     * d'après combien de travaux.
     *
     * @return list<array<string, mixed>>
     */
    public function etapesProposees(OrdreProduction $ordre, ?Machines $machine): array
    {
        return FacteursEstimation::appliquer(PosteMachiniste::etapesProposees($ordre, $machine), $this->facteurs($machine));
    }
}
