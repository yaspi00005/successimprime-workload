<?php

namespace App\Service\Achats;

use App\Entity\ReclamationFournisseur;

/**
 * Calculs de la réception d'une commande d'achat (maquette SU05,
 * docs/achats.md) : badge d'écart de chaque ligne, quantité à réclamer
 * au fournisseur, coût de revient réel avec les frais réels, règle du
 * paiement bloqué, textes de la livraison et du message au fournisseur.
 *
 * Tout est statique et pur pour être testé sans base.
 */
final class CalculsReception
{
    /*
     * Unités d'achat au féminin, pour « qu'une est abîmée » et « la
     * remplacer ». Les autres se lisent au masculin (rouleau, litre, sac).
     */
    private const UNITES_FEMININES = ['plaque', 'unité', 'boîte', 'bobine', 'feuille', 'pièce', 'cartouche', 'palette', 'caisse'];

    /**
     * Badge « Écart » d'une ligne : « 1 rouleau écrasé » (rouge),
     * « manque 1 » (rouge), « en trop 1 » (jaune), « conforme » (vert).
     * La casse et le manque peuvent se cumuler.
     *
     * @return array{libelle: string, classe: string}
     */
    public static function ecart(int $commande, int $recue, int $bonEtat, ?string $uniteAchat, ?string $motif = null): array
    {
        $abimees = max(0, $recue - $bonEtat);
        $parts = [];

        if ($abimees > 0) {
            $parts[] = sprintf('%d %s %s', $abimees, CalculsAchat::pluriel($uniteAchat ?: 'unité', $abimees), self::accorder(trim((string) $motif) ?: 'abîmé', $abimees, self::estFeminin($uniteAchat)));
        }

        if ($recue < $commande) {
            $parts[] = sprintf('manque %d', $commande - $recue);
        } elseif ($recue > $commande) {
            $parts[] = sprintf('en trop %d', $recue - $commande);
        }

        if ($parts === []) {
            return ['libelle' => 'conforme', 'classe' => 'ok'];
        }

        return ['libelle' => implode(' · ', $parts), 'classe' => $abimees > 0 || $recue < $commande ? 'retard' : 'attente'];
    }

    /**
     * Ce que le fournisseur doit remplacer ou déduire : ce qu'il facture
     * au-delà de ce qui est arrivé en bon état (abîmé ou manquant).
     */
    public static function quantiteAReclamer(int $facturee, int $bonEtat): int
    {
        return max(0, $facturee - $bonEtat);
    }

    /**
     * Coût de revient réel de chaque ligne, par unité de stock.
     *
     * Les frais réels (transport, transit) se répartissent entre les
     * lignes sur ce qui a été livré (m² reçus, abîmés compris : le camion
     * les a portés), comme à la commande. Chaque ligne porte ensuite sa
     * part sur ses seules unités en bon état, avec leur prix d'achat :
     * la marchandise abîmée est remplacée ou déduite par le fournisseur,
     * mais son transport est payé. Ainsi la valeur entrée en stock égale
     * ce que l'imprimerie paie. Sans unité en bon état, pas de coût.
     *
     * @param list<array{recue: float, bon: float, unite: string, montantRecu: int, montantBon: int}> $lignes
     *
     * @return list<array{partTransport: int, partTransit: int, cout: ?int}>
     */
    public static function coutsReels(array $lignes, int $transport, int $transit): array
    {
        $pourRepartir = array_map(static fn (array $l): array => ['quantite' => $l['recue'], 'unite' => $l['unite'], 'montant' => $l['montantRecu']], $lignes);
        $partsTransport = CalculsAchat::repartirFrais($pourRepartir, $transport);
        $partsTransit = CalculsAchat::repartirFrais($pourRepartir, $transit);
        $couts = [];

        foreach ($lignes as $i => $l) {
            $couts[] = [
                'partTransport' => $partsTransport[$i],
                'partTransit' => $partsTransit[$i],
                'cout' => CalculsAchat::coutRevientUnitaire($l['montantBon'], $partsTransport[$i] + $partsTransit[$i], $l['bon']),
            ];
        }

        return $couts;
    }

    /**
     * Le paiement du solde est bloqué tant qu'une réclamation est
     * ouverte, sauf déblocage forcé par la finance.
     *
     * @param list<string> $statutsReclamations
     */
    public static function paiementBloque(array $statutsReclamations, bool $force): bool
    {
        return !$force && \in_array(ReclamationFournisseur::OUVERTE, $statutsReclamations, true);
    }

    /**
     * Raison du blocage, pour les lignes dont la réclamation est ouverte :
     * « La facture compte 4 rouleaux de vinyle blanc alors qu'un est
     * abîmé : paiement du solde bloqué jusqu'à l'avoir du fournisseur. »
     *
     * @param list<array{nom: string, uniteAchat: ?string, facturee: int, recue: int, bonEtat: int, ouverte: bool}> $lignes
     */
    public static function motifBlocage(array $lignes): ?string
    {
        $phrases = [];

        foreach ($lignes as $l) {
            if (!$l['ouverte'] || self::quantiteAReclamer($l['facturee'], $l['bonEtat']) <= 0) {
                continue;
            }

            $feminin = self::estFeminin($l['uniteAchat']);
            $abimees = max(0, $l['recue'] - $l['bonEtat']);

            if ($abimees > 0 && $l['bonEtat'] + $abimees >= $l['facturee']) {
                $raison = $abimees === 1
                    ? ($feminin ? 'qu\'une est abîmée' : 'qu\'un est abîmé')
                    : sprintf('que %d sont abîmé%ss', $abimees, $feminin ? 'e' : '');
            } elseif ($l['bonEtat'] === 0) {
                $raison = $feminin ? 'qu\'aucune n\'est arrivée en bon état' : 'qu\'aucun n\'est arrivé en bon état';
            } else {
                $raison = sprintf('que seulement %d %s en bon état', $l['bonEtat'], $l['bonEtat'] > 1 ? 'sont arrivé'.($feminin ? 'es' : 's') : 'est arrivé'.($feminin ? 'e' : ''));
            }

            $phrases[] = sprintf('%d %s de %s alors %s', $l['facturee'], CalculsAchat::pluriel($l['uniteAchat'] ?: 'unité', $l['facturee']), self::minuscule($l['nom']), $raison);
        }

        if ($phrases === []) {
            return null;
        }

        return 'La facture compte '.implode(' ; ', $phrases).' : paiement du solde bloqué jusqu\'à l\'avoir du fournisseur.';
    }

    /**
     * « 1 rouleau à remplacer ou à déduire (159 600 F) » ; sans le montant
     * pour qui ne voit pas les dettes fournisseurs.
     */
    public static function texteReclamation(int $quantite, ?string $uniteAchat, ?int $montant): string
    {
        $texte = sprintf('%d %s à remplacer ou à déduire', $quantite, CalculsAchat::pluriel($uniteAchat ?: 'unité', $quantite));

        return $montant !== null ? sprintf('%s (%s F)', $texte, number_format($montant, 0, ',', ' ')) : $texte;
    }

    /**
     * Message préparé pour le fournisseur, envoyé par WhatsApp avec la
     * photo de la casse.
     */
    public static function messageReclamation(?string $contact, ?string $numeroCommande, \DateTimeImmutable $recue, int $quantite, ?string $uniteAchat, string $article, ?string $motif, int $abimees, int $montant, ?string $facture): string
    {
        $feminin = self::estFeminin($uniteAchat);
        $unite = CalculsAchat::pluriel($uniteAchat ?: 'unité', $quantite);
        $pronom = $quantite > 1 ? 'les' : ($feminin ? 'la' : 'le');
        $etat = $abimees >= $quantite
            ? self::accorder(trim((string) $motif) ?: 'abîmé', $quantite, $feminin)
            : ($abimees > 0 ? sprintf('abîmé%s ou manquant%s', $quantite > 1 ? ($feminin ? 'es' : 's') : ($feminin ? 'e' : ''), $quantite > 1 ? ($feminin ? 'es' : 's') : ($feminin ? 'e' : '')) : self::accorder('manquant', $quantite, $feminin));

        return sprintf(
            "Bonjour %s,\nNous avons reçu la commande %s le %s : %d %s de %s %s.\nMerci de %s remplacer ou de %s déduire de %s (%s F). La photo suit.\nMerci.",
            trim((string) $contact) ?: 'Madame, Monsieur',
            $numeroCommande ?? '',
            $recue->format('d/m'),
            $quantite,
            $unite,
            self::minuscule($article),
            $etat,
            $pronom,
            $pronom,
            $facture !== null && $facture !== '' ? 'votre facture '.$facture : 'votre facture',
            number_format($montant, 0, ',', ' ')
        );
    }

    /**
     * Lien wa.me avec le message prêt (pas d'API) ; null sans numéro.
     */
    public static function lienWhatsApp(?string $numero, string $message): ?string
    {
        return $numero !== null && $numero !== '' ? 'https://wa.me/'.$numero.'?text='.rawurlencode($message) : null;
    }

    /**
     * « Livraison : 28/10, 2 jours après la date promise. Compté dans la
     * performance de Dakar Signs. »
     */
    public static function texteLivraison(?\DateTimeImmutable $promise, \DateTimeImmutable $recue, string $fournisseur): string
    {
        $texte = 'Livraison : '.$recue->format('d/m');

        if ($promise !== null) {
            $retard = CalculsAchat::retardJours($promise, $recue);
            $texte .= match (true) {
                $retard === 0 => ', le jour promis',
                $retard > 0 => sprintf(', %d jour%s après la date promise', $retard, $retard > 1 ? 's' : ''),
                default => sprintf(', %d jour%s avant la date promise', -$retard, $retard < -1 ? 's' : ''),
            };
        }

        return $texte.'. Compté dans la performance de '.$fournisseur.'.';
    }

    /**
     * Solde de la facture à payer : la facture moins l'acompte versé à la commande.
     */
    public static function soldeAPayer(int $facture, int $acompte): int
    {
        return max(0, $facture - max(0, $acompte));
    }

    /**
     * « vinyle blanc » au milieu d'une phrase : première lettre en minuscule.
     */
    public static function minuscule(string $texte): string
    {
        return mb_strtolower(mb_substr($texte, 0, 1)).mb_substr($texte, 1);
    }

    public static function estFeminin(?string $uniteAchat): bool
    {
        return \in_array(mb_strtolower(trim((string) $uniteAchat)), self::UNITES_FEMININES, true);
    }

    /**
     * « écrasé » s'accorde : « 2 rouleaux écrasés », « 1 plaque rayée ».
     */
    private static function accorder(string $adjectif, int $nombre, bool $feminin): string
    {
        if ($feminin && preg_match('/[éèt]$/u', $adjectif)) {
            $adjectif .= 'e';
        }

        return $nombre > 1 && !preg_match('/[sx]$/u', $adjectif) ? $adjectif.'s' : $adjectif;
    }
}
