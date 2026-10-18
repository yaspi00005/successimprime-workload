<?php

namespace App\Service\Societes;

/**
 * La règle de SocieteCourante écrite en SQL, pour des lignes insérées à la
 * main sans passer par l'application : la démonstration (app:demo) les
 * charge avec la société principale, valeur par défaut de la colonne, puis
 * applique ces instructions à ses identifiants (à partir de 900001).
 *
 * L'ordre compte : une donnée prend la société d'une donnée déjà rangée
 * plus haut (le devis et la facture d'abord, puis la commande, puis le
 * paiement…). La migration Version20261014160000 a sa propre copie, figée.
 */
final class RattachementSql
{
    /**
     * Tables rattachées sans document lié : elles gardent la société par
     * défaut, qu'aucune instruction ne change.
     */
    public const PAR_DEFAUT = ['compte_tresorerie', 'articles', 'achats', 'immobilisation'];

    /**
     * @return list<string> une instruction UPDATE par lien, à exécuter dans l'ordre
     */
    public static function instructions(int $aPartirDe = 0): array
    {
        $depuis = static fn (string $alias): string => \sprintf('%s.id >= %d', $alias, $aPartirDe);

        return [
            // Un document qui a un émetteur prend la société de l'entreprise émettrice.
            'UPDATE devis d JOIN entreprise e ON e.code = d.emetteur SET d.societe_id = e.societe_id WHERE '.$depuis('d'),
            'UPDATE factures f JOIN entreprise e ON e.code = f.emetteur SET f.societe_id = e.societe_id WHERE '.$depuis('f'),
            // La commande suit sa première facture non annulée, sinon son devis.
            "UPDATE commandes c JOIN (SELECT commande_id, MIN(id) AS premiere FROM factures WHERE etat <> 'annulee' GROUP BY commande_id) p ON p.commande_id = c.id"
                .' JOIN factures f ON f.id = p.premiere SET c.societe_id = f.societe_id WHERE '.$depuis('c'),
            'UPDATE commandes c JOIN devis d ON d.commande_id = c.id SET c.societe_id = d.societe_id WHERE '.$depuis('c')
                ." AND NOT EXISTS (SELECT 1 FROM factures f WHERE f.commande_id = c.id AND f.etat <> 'annulee')",
            // Le paiement suit sa facture, sinon sa commande.
            'UPDATE paiements p JOIN factures f ON f.id = p.facture_id SET p.societe_id = f.societe_id WHERE '.$depuis('p'),
            'UPDATE paiements p JOIN commandes c ON c.id = p.commande_id SET p.societe_id = c.societe_id WHERE '.$depuis('p').' AND p.facture_id IS NULL',
            // Trésorerie : tout suit le compte ; un mouvement sans compte suit son paiement.
            'UPDATE mouvement_tresorerie m JOIN compte_tresorerie c ON c.id = COALESCE(m.compte_source_id, m.compte_destination_id) SET m.societe_id = c.societe_id WHERE '.$depuis('m'),
            'UPDATE mouvement_tresorerie m JOIN paiements p ON p.id = m.paiement_id SET m.societe_id = p.societe_id WHERE '.$depuis('m')
                .' AND m.compte_source_id IS NULL AND m.compte_destination_id IS NULL',
            'UPDATE decaissement_recurrent d JOIN compte_tresorerie c ON c.id = d.compte_source_id SET d.societe_id = c.societe_id WHERE '.$depuis('d'),
            'UPDATE rapprochement_bancaire r JOIN compte_tresorerie c ON c.id = r.compte_tresorerie_id SET r.societe_id = c.societe_id WHERE '.$depuis('r'),
            'UPDATE cloture_caisse k JOIN compte_tresorerie c ON c.id = k.compte_id SET k.societe_id = c.societe_id WHERE '.$depuis('k'),
            'UPDATE depense d JOIN compte_tresorerie c ON c.id = d.compte_id SET d.societe_id = c.societe_id WHERE '.$depuis('d'),
            'UPDATE depense d JOIN articles a ON a.id = d.article_id SET d.societe_id = a.societe_id WHERE '.$depuis('d').' AND d.compte_id IS NULL',
            // Le stock suit son article, la dotation son bien.
            'UPDATE stock_entrees s JOIN articles a ON a.id = s.article_id SET s.societe_id = a.societe_id WHERE '.$depuis('s'),
            'UPDATE stock_sorties s JOIN articles a ON a.id = s.article_id SET s.societe_id = a.societe_id WHERE '.$depuis('s'),
            'UPDATE dotation_amortissement d JOIN immobilisation i ON i.id = d.immobilisation_id SET d.societe_id = i.societe_id WHERE '.$depuis('d'),
            // Une opération diverse de reprise suit son paiement.
            'UPDATE ecriture_diverse e JOIN paiements p ON p.id = e.paiement_id SET e.societe_id = p.societe_id WHERE '.$depuis('e'),
        ];
    }
}
