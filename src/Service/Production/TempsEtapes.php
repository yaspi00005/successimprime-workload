<?php

namespace App\Service\Production;

use App\Service\Atelier\AvancementOrdre;

/**
 * Temps réel comparé au temps estimé, étape par étape (PD06,
 * docs/production-colis.md). Pur, sans base.
 *
 * Impression : mesurée sur la machine (pièces du rouleau, ou démarrage →
 * « Terminé » pauses retirées). Séchage : fin d'impression → « Commencer
 * la finition » (sans appui : le séchage du profil). Finition : début de
 * finition → étiquettes imprimées. Contrôle : étiquettes → contrôle final
 * validé. Le séchage compte en heures pleines (il sèche aussi la nuit) ;
 * finition et contrôle en heures d'ouverture de l'atelier.
 */
final class TempsEtapes
{
    public const LIBELLES = ['impression' => 'Impression', 'sechage' => 'Séchage', 'finitions' => 'Finition', 'controle' => 'Contrôle'];

    /**
     * @param list<array<string, mixed>> $estimation  étapes estimées (OP03) : cle, estime, propose, brut
     * @param array<string, ?int>        $prevus      temps proposés quand l'ordre n'a pas d'estimation (rouleau)
     * @param array<string, mixed>       $tempsReels  OrdreProduction::getTempsReels()
     *
     * @return array{etapes: list<array{cle: string, libelle: string, estime: ?int, reel: ?int, prevu: ?int}>, estime: ?int, reel: ?int, partiel: bool}
     */
    public static function calculer(array $estimation, array $prevus, array $tempsReels, ?int $dureeReelle, ?\DateTimeImmutable $termineLe, ?\DateTimeImmutable $etiquettesLe, ?\DateTimeImmutable $controleLe, int $sechageMin, bool $avecFinitions): array
    {
        $estimes = [];
        $prevusEstimation = [];
        foreach ($estimation as $e) {
            $cle = (string) ($e['cle'] ?? '');
            if (!isset(self::LIBELLES[$cle])) {
                continue;
            }
            $estimes[$cle] = isset($e['estime']) ? (int) $e['estime'] : (isset($e['propose']) ? (int) $e['propose'] : null);
            $prevusEstimation[$cle] = isset($e['brut']) ? (int) $e['brut'] : (isset($e['propose']) ? (int) $e['propose'] : null);
        }

        $date = static fn (string $cle): ?\DateTimeImmutable => \is_string($tempsReels[$cle] ?? null) && '' !== $tempsReels[$cle] ? new \DateTimeImmutable($tempsReels[$cle]) : null;
        $mesures = \is_array($tempsReels['etapes'] ?? null) ? $tempsReels['etapes'] : [];
        $finImpression = $date('impression_fin') ?? $termineLe;
        $debutFinition = $date('finition_debut') ?? $finImpression?->modify('+'.max(0, $sechageMin).' minutes');

        $reels = [
            'impression' => isset($mesures['impression']['reel']) ? (int) $mesures['impression']['reel'] : $dureeReelle,
            'sechage' => null !== $finImpression && null !== $debutFinition ? max(0, intdiv($debutFinition->getTimestamp() - $finImpression->getTimestamp(), 60)) : null,
            'finitions' => null !== $debutFinition && null !== $etiquettesLe && $etiquettesLe > $debutFinition ? self::minutesOuvrees($debutFinition, $etiquettesLe) : null,
            'controle' => null !== $etiquettesLe && null !== $controleLe && $controleLe > $etiquettesLe ? self::minutesOuvrees($etiquettesLe, $controleLe) : null,
        ];

        $montrer = [
            'impression' => true,
            'sechage' => isset($estimes['sechage']) || isset($prevus['sechage']) || null !== $date('finition_debut'),
            'finitions' => $avecFinitions || isset($estimes['finitions']),
            // Le contrôle se montre une fois mesuré (ou estimé) : avant, la commande attend son contrôle.
            'controle' => isset($estimes['controle']) || isset($prevus['controle']) || null !== $reels['controle'],
        ];

        $etapes = [];
        foreach (self::LIBELLES as $cle => $libelle) {
            if (!$montrer[$cle]) {
                continue;
            }
            $prevu = $prevusEstimation[$cle] ?? $prevus[$cle] ?? null;
            $etapes[] = [
                'cle' => $cle,
                'libelle' => $libelle,
                'estime' => $estimes[$cle] ?? $prevus[$cle] ?? null,
                'reel' => $reels[$cle],
                'prevu' => isset($mesures[$cle]['prevu']) ? (int) $mesures[$cle]['prevu'] : $prevu,
            ];
        }
        $estimesConnus = array_filter(array_column($etapes, 'estime'), static fn (?int $m): bool => null !== $m);
        $reelsConnus = array_filter(array_column($etapes, 'reel'), static fn (?int $m): bool => null !== $m);

        return [
            'etapes' => $etapes,
            'estime' => [] !== $estimesConnus ? array_sum($estimesConnus) : null,
            'reel' => [] !== $reelsConnus ? array_sum($reelsConnus) : null,
            'partiel' => \count($reelsConnus) < \count($etapes),
        ];
    }

    /**
     * Minutes entre deux instants pendant les heures d'ouverture de
     * l'atelier (8 h – 19 h, dimanche fermé).
     */
    public static function minutesOuvrees(\DateTimeImmutable $de, \DateTimeImmutable $a): int
    {
        if ($a <= $de) {
            return 0;
        }
        $total = 0;
        $jour = $de->setTime(0, 0);
        // Au plus 60 jours : au-delà, la pièce a attendu bien plus qu'une étape.
        for ($i = 0; $i < 60 && $jour < $a; ++$i, $jour = $jour->modify('+1 day')) {
            if ('7' === $jour->format('N')) {
                continue;
            }
            $ouverture = $jour->setTime(AvancementOrdre::HEURE_OUVERTURE, 0);
            $fermeture = $jour->setTime(AvancementOrdre::HEURE_FERMETURE, 0);
            $debut = max($ouverture, $de);
            $fin = min($fermeture, $a);
            if ($fin > $debut) {
                $total += $fin->getTimestamp() - $debut->getTimestamp();
            }
        }

        return intdiv($total, 60);
    }
}
