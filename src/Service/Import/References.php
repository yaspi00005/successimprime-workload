<?php

namespace App\Service\Import;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Ce qui pointe vers des fiches créées par un import : une commande, un
 * devis, une note, une promotion… Tant que rien ne pointe vers elles (à
 * part ce que l'import a lui-même créé), l'import peut être annulé.
 *
 * Les relations sont lues dans la description Doctrine des entités : une
 * relation ajoutée plus tard est prise en compte sans toucher à ce code.
 */
final class References
{
    /** Noms lisibles (singulier, pluriel) des entités les plus courantes ; les autres sont tirés du nom de classe. */
    private const NOMS = [
        'Commandes' => ['commande', 'commandes'],
        'Devis' => ['devis', 'devis'],
        'Factures' => ['facture', 'factures'],
        'Paiements' => ['paiement', 'paiements'],
        'CommandesDetails' => ['ligne de commande', 'lignes de commande'],
        'DevisDetails' => ['ligne de devis', 'lignes de devis'],
        'CompteClient' => ['compte de l\'espace client', 'comptes de l\'espace client'],
        'CompteFidelite' => ['compte fidélité', 'comptes fidélité'],
        'MouvementFidelite' => ['mouvement de fidélité', 'mouvements de fidélité'],
        'NoteClient' => ['note', 'notes'],
        'MessageClient' => ['message', 'messages'],
        'Prospect' => ['prospect', 'prospects'],
        'AdresseLivraison' => ['adresse de livraison', 'adresses de livraison'],
        'CampagneDestinataire' => ['campagne', 'campagnes'],
        'Promotion' => ['promotion', 'promotions'],
        'ProduitClient' => ['produit personnalisé', 'produits personnalisés'],
        'ActionCaisse' => ['action de caisse', 'actions de caisse'],
        'ReclamationClient' => ['réclamation', 'réclamations'],
        'MouvementStock' => ['mouvement de stock', 'mouvements de stock'],
        'LigneInventaire' => ['inventaire', 'inventaires'],
        'Depense' => ['dépense', 'dépenses'],
        'MouvementTresorerie' => ['mouvement de trésorerie', 'mouvements de trésorerie'],
        'ClotureCaisse' => ['clôture de caisse', 'clôtures de caisse'],
        'RapprochementBancaire' => ['rapprochement bancaire', 'rapprochements bancaires'],
        'Echeance' => ['échéance', 'échéances'],
        'DecaissementRecurrent' => ['dépense récurrente', 'dépenses récurrentes'],
        'ArticleFournisseur' => ['fournisseur', 'fournisseurs'],
        'ContactClient' => ['contact', 'contacts'],
        'ModificationClient' => ['modification de la fiche', 'modifications de la fiche'],
        'PrixHistorique' => ['changement de prix', 'changements de prix'],
        'ProduitConfiguration' => ['configuration', 'configurations'],
        'Livraison' => ['livraison', 'livraisons'],
        'SoldeOuverture' => ['solde d\'ouverture', 'soldes d\'ouverture'],
    ];

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Nombre de lignes, par entité, qui pointent vers une des fiches.
     *
     * @param class-string       $classe   entité visée (Clients, Article…)
     * @param list<int>          $ids
     * @param list<class-string> $ignorees entités créées avec la fiche (contacts, journal de la fiche)
     *
     * @return array<string, int> nom lisible => nombre
     */
    public function compter(string $classe, array $ids, array $ignorees = []): array
    {
        if ([] === $ids) {
            return [];
        }
        $resultat = [];
        /** @var list<ClassMetadata<object>> $toutes */
        $toutes = $this->em->getMetadataFactory()->getAllMetadata();
        foreach ($toutes as $meta) {
            if ($meta->isMappedSuperclass || $meta->isEmbeddedClass || \in_array($meta->getName(), $ignorees, true)) {
                continue;
            }
            foreach ($meta->getAssociationMappings() as $champ => $relation) {
                if ($relation->targetEntity !== $classe || !$relation->isOwningSide()) {
                    continue;
                }
                $dql = $relation->isManyToMany()
                    ? sprintf('SELECT COUNT(e) FROM %s e JOIN e.%s cible WHERE cible.id IN (:ids)', $meta->getName(), $champ)
                    : sprintf('SELECT COUNT(e) FROM %s e WHERE IDENTITY(e.%s) IN (:ids)', $meta->getName(), $champ);
                $nombre = (int) $this->em->createQuery($dql)->setParameter('ids', $ids)->getSingleScalarResult();
                if ($nombre > 0) {
                    $nom = self::nom($meta->getName());
                    $resultat[$nom] = ($resultat[$nom] ?? 0) + $nombre;
                }
            }
        }
        arsort($resultat);

        return $resultat;
    }

    /**
     * « 3 commandes, 1 devis ».
     *
     * @param array<string, int> $references
     */
    public static function phrase(array $references): string
    {
        $morceaux = [];
        $singuliers = array_column(self::NOMS, 0, 1);
        foreach ($references as $nom => $nombre) {
            $morceaux[] = $nombre.' '.(1 === $nombre ? ($singuliers[$nom] ?? $nom) : $nom);
        }

        return implode(', ', $morceaux);
    }

    private static function nom(string $classe): string
    {
        $court = substr($classe, (int) strrpos($classe, '\\') + 1);

        // Sinon « ClotureCaisse » devient « cloture caisse ».
        return self::NOMS[$court][1] ?? mb_strtolower((string) preg_replace('/(?<!^)([A-Z])/', ' $1', $court));
    }
}
