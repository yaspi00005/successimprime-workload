<?php

namespace App\Service\Devis;

use App\Entity\Devis;
use App\Entity\DevisDetails;

/**
 * Quantités au choix sur une ligne de devis (maquette DB02, docs/devis.md) :
 * l'imprimerie propose 500, 1 000 ou 2 000 flyers, chacune avec son prix
 * unitaire ; le client choisit dans son espace et le total change.
 *
 * Les montants sont toujours refaits par la ligne elle-même
 * (calculerTotaux, puis recalculerTotaux du devis) : le site ne fixe jamais
 * un prix, il choisit seulement une quantité que l'imprimerie a proposée.
 */
final class OptionsDevis
{
    /** Au plus 4 quantités proposées en plus de celle du devis. */
    public const MAX = 4;

    /**
     * Options d'une ligne, quantité du devis comprise, triées : quantité,
     * prix unitaire, montants HT et TTC de la ligne avec cette quantité.
     * Vide quand la ligne n'en propose pas.
     *
     * @return list<array{quantite: int, prixUnitaire: string, ht: int, ttc: int, actuelle: bool}>
     */
    public static function options(DevisDetails $ligne): array
    {
        $proposees = $ligne->getOptionsQuantite();
        if ([] === $proposees) {
            return [];
        }
        $liste = [['quantite' => $ligne->getQuantite(), 'prixUnitaire' => (string) $ligne->getPrixUnitaire(), 'ht' => $ligne->getTotalHt(), 'ttc' => $ligne->getTotalTtc(), 'actuelle' => true]];
        foreach ($proposees as $o) {
            if ($o['quantite'] === $ligne->getQuantite()) {
                continue;
            }
            $essai = clone $ligne;
            $essai->setQuantite($o['quantite'])->setPrixUnitaire($o['prixUnitaire'])->calculerTotaux();
            $liste[] = ['quantite' => $o['quantite'], 'prixUnitaire' => $o['prixUnitaire'], 'ht' => $essai->getTotalHt(), 'ttc' => $essai->getTotalTtc(), 'actuelle' => false];
        }
        usort($liste, static fn (array $a, array $b): int => $a['quantite'] <=> $b['quantite']);

        return $liste;
    }

    /**
     * Lit les options saisies par l'imprimerie (« 1000 » et « 38 »), en
     * écarte les mauvaises : quantité entière positive, prix positif, une
     * fois chacune, au plus MAX.
     *
     * @param array<int, array{quantite?: mixed, prixUnitaire?: mixed}> $saisies
     *
     * @return list<array{quantite: int, prixUnitaire: string}>
     */
    public static function lire(array $saisies, int $quantiteDuDevis): array
    {
        $options = [];
        foreach ($saisies as $s) {
            $q = (int) preg_replace('/\D/', '', (string) ($s['quantite'] ?? ''));
            $pu = str_replace([' ', "\u{202f}", "\u{a0}", ','], ['', '', '', '.'], trim((string) ($s['prixUnitaire'] ?? '')));
            if ($q <= 0 || $q === $quantiteDuDevis || !is_numeric($pu) || (float) $pu <= 0 || isset($options[$q])) {
                continue;
            }
            $options[$q] = ['quantite' => $q, 'prixUnitaire' => rtrim(rtrim(number_format((float) $pu, 4, '.', ''), '0'), '.')];
        }
        ksort($options);

        return \array_slice(array_values($options), 0, self::MAX);
    }

    /**
     * Applique le choix du client (id public de ligne => quantité) : une
     * quantité qui n'est pas proposée est écartée. Le choix est gardé sur le
     * devis ; renvoie vrai si un montant a changé.
     *
     * @param array<string, mixed> $choix
     */
    public static function appliquer(Devis $devis, array $choix): bool
    {
        $change = false;
        $retenus = [];
        foreach ($devis->getDevisDetails() as $ligne) {
            $cle = (string) $ligne->getPublicId();
            if (!isset($choix[$cle]) || [] === $ligne->getOptionsQuantite()) {
                continue;
            }
            $voulue = (int) $choix[$cle];
            if ($voulue === $ligne->getQuantite()) {
                $retenus[$cle] = $voulue;
                continue;
            }
            foreach ($ligne->getOptionsQuantite() as $o) {
                if ($o['quantite'] !== $voulue) {
                    continue;
                }
                // La quantité du devis devient une option : le client peut encore revenir dessus.
                $autres = array_values(array_filter($ligne->getOptionsQuantite(), static fn (array $x): bool => $x['quantite'] !== $voulue));
                $autres[] = ['quantite' => $ligne->getQuantite(), 'prixUnitaire' => (string) $ligne->getPrixUnitaire()];
                usort($autres, static fn (array $a, array $b): int => $a['quantite'] <=> $b['quantite']);
                $ligne->setQuantite($voulue)->setPrixUnitaire($o['prixUnitaire'])->setOptionsQuantite($autres)->calculerTotaux();
                $retenus[$cle] = $voulue;
                $change = true;
                break;
            }
        }
        if ($change) {
            $devis->recalculerTotaux();
        }
        if ([] !== $retenus) {
            $devis->setOptionsChoisies($retenus);
        }

        return $change;
    }

    /**
     * Données pour refaire le total dans la page quand le client choisit
     * (même calcul que TotauxDocument) : frais, remise, acompte et, par
     * ligne à options, HT et TVA de chaque quantité.
     *
     * @return array{frais: int, remise: int, pourcentage: int, lignes: array<string, array{ht: int, tva: int, choix: array<int, array{ht: int, tva: int}>}>}
     */
    public static function pourLaPage(Devis $devis): array
    {
        $lignes = [];
        foreach ($devis->getDevisDetails() as $ligne) {
            $choix = [];
            foreach (self::options($ligne) as $o) {
                $choix[$o['quantite']] = ['ht' => $o['ht'], 'tva' => $o['ttc'] - $o['ht']];
            }
            $lignes[(string) $ligne->getPublicId()] = ['ht' => $ligne->getTotalHt(), 'tva' => $ligne->getTotalTtc() - $ligne->getTotalHt(), 'choix' => $choix];
        }

        return [
            'frais' => $devis->getFraisLivraison(),
            // Remise globale demandée : le devis la garde plafonnée au sous-total.
            'remise' => $devis->getRemise(),
            'pourcentage' => $devis->getAcomptePourcentage(),
            'lignes' => $lignes,
        ];
    }
}
