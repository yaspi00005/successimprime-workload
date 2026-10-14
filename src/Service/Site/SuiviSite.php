<?php

namespace App\Service\Site;

/**
 * Page « Suivre ma commande » du site (maquette 10) : libellés courts des
 * étapes, dates « lun. 10:12 » et pastille d'état (« En finition · prête
 * jeudi »). Aucun montant : seulement les étapes de la commande.
 */
final class SuiviSite
{
    private const JOURS = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];
    private const JOURS_LONGS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    /**
     * Libellé sous la pastille de l'étape (maquette : Reçue, BAT validé,
     * Impression, Finition, Prête, Livrée).
     *
     * @param array{cle: string, libelle: string, court: string, etat: string} $etape
     */
    public static function libelle(array $etape): string
    {
        if ('bat' === $etape['cle']) {
            return match (true) {
                'Sans BAT' === $etape['libelle'] => 'Sans BAT',
                'faite' === $etape['etat'] => 'BAT validé',
                default => 'BAT',
            };
        }

        return $etape['court'];
    }

    /**
     * Étapes telles que la page les montre.
     *
     * @param list<array{cle: string, libelle: string, court: string, date: ?\DateTimeInterface, detail: ?string, etat: string}> $etapes
     *
     * @return list<array{libelle: string, quand: ?string, detail: ?string, etat: string}>
     */
    public static function etapes(array $etapes, \DateTimeImmutable $maintenant): array
    {
        return array_map(static fn (array $e): array => [
            'libelle' => self::libelle($e),
            'quand' => self::quand($e['date'], $maintenant),
            'detail' => $e['detail'],
            'etat' => $e['etat'],
        ], $etapes);
    }

    /** « lun. 10:12 » cette semaine, « 07/10 10:12 » avant ; l'heure seulement si elle est connue. */
    public static function quand(?\DateTimeInterface $date, \DateTimeImmutable $maintenant): ?string
    {
        if (null === $date) {
            return null;
        }
        $jour = \DateTimeImmutable::createFromInterface($date)->setTime(0, 0);
        $ecart = (int) $jour->diff($maintenant->setTime(0, 0))->format('%r%a');
        $heure = '00:00' === $date->format('H:i') ? '' : ' '.$date->format('H:i');

        return ($ecart >= 0 && $ecart < 7 ? self::JOURS[(int) $date->format('N')] : $date->format('d/m')).$heure;
    }

    /**
     * Pastille en haut à droite de la carte.
     *
     * @param array{etapes: list<array{cle: string, libelle: string, detail: ?string, etat: string}>, courante: ?array{cle: string}, annulee: bool, terminee: bool, batEnAttente: bool, commande: \App\Entity\Commandes} $carte
     *
     * @return array{texte: string, ton: string}
     */
    public static function pastille(array $carte, \DateTimeImmutable $maintenant): array
    {
        $commande = $carte['commande'];
        $retrait = !$commande->estALivrer();
        if ($carte['annulee']) {
            return ['texte' => 'Commande annulée', 'ton' => 'rouge'];
        }
        if ($carte['terminee'] || null === $carte['courante']) {
            return ['texte' => $retrait ? 'Retirée' : 'Livrée', 'ton' => 'vert'];
        }

        $cle = $carte['courante']['cle'];
        $texte = match ($cle) {
            'bat' => $carte['batEnAttente'] ? 'BAT à valider' : 'BAT en préparation',
            'impression' => 'En impression',
            'finition' => 'En finition',
            'prete' => 'Bientôt prête',
            default => $retrait ? 'Prête à retirer' : ('en route' === ($carte['etapes'][5]['detail'] ?? null) ? 'En route' : 'Prête à livrer'),
        };
        if ('livree' === $cle) {
            return ['texte' => $texte, 'ton' => 'vert'];
        }

        $prevue = $commande->getDateLivraison();
        if (null !== $prevue && null !== $jour = self::jour($prevue, $maintenant)) {
            $texte .= ' · '.($retrait ? 'prête ' : 'livrée ').$jour;
        }

        return ['texte' => $texte, 'ton' => 'bat' === $cle && $carte['batEnAttente'] ? 'rouge' : 'orange'];
    }

    /** « aujourd'hui », « demain », « jeudi » dans la semaine, « le 21/10 » ensuite ; null si passé. */
    public static function jour(\DateTimeInterface $date, \DateTimeImmutable $maintenant): ?string
    {
        $ecart = (int) $maintenant->setTime(0, 0)->diff(\DateTimeImmutable::createFromInterface($date)->setTime(0, 0))->format('%r%a');

        return match (true) {
            $ecart < 0 => null,
            0 === $ecart => 'aujourd\'hui',
            1 === $ecart => 'demain',
            $ecart < 7 => self::JOURS_LONGS[(int) $date->format('N')],
            default => 'le '.$date->format('d/m'),
        };
    }
}
