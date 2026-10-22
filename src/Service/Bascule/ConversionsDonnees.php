<?php

namespace App\Service\Bascule;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;

/**
 * Conversions de données de la bascule (docs/migration-de-base.md) : les
 * anciennes mises à jour qui changeaient le SENS des données existantes,
 * pas seulement le schéma. La comparaison des schémas ne les voit pas (au
 * mieux un changement de type) ; la structure de la base dit si elles ont
 * été faites, et la bascule rejoue celles qui manquent :
 *
 * - remises des lignes de commande et de devis : pourcentage → F par unité
 *   facturable (mises à jour des 20/08/2026, Version20260820120000 à 140000) ;
 * - articles non vendables rendus vendables (19/08/2026, Version20260819160000) ;
 * - consommables de production (01/09/2026, Version20260901180000) ;
 * - type de compte des clients B2B (17/08/2026, Version20260817120000) ;
 * - récapitulatif d'amortissement du traceur 1,8 m (17/08/2026, Version20260817000000).
 *
 * Seuls des nombres de lignes sortent d'ici ; les totaux enregistrés des
 * lignes ne sont jamais modifiés.
 */
final class ConversionsDonnees
{
    /** Tables de lignes dont la remise change de sens, avec la table et la colonne de leurs finitions. */
    public const REMISES = [
        'commandes_details' => ['commande_detail_finition', 'commande_detail_id'],
        'devis_details' => ['devis_detail_finition', 'devis_detail_id'],
    ];

    /**
     * « Garder le total » : colonne des lignes converties qui gardent leur
     * total enregistré. Même définition que la migration
     * Version20261015231500, qui ne la recrée pas quand elle existe.
     */
    public const COLONNE_TOTAL_FIGE = 'total_fige';
    public const DEFINITION_TOTAL_FIGE = 'TINYINT DEFAULT 0 NOT NULL';

    /** Types de la remise en pourcentage dans l'ancienne version : DECIMAL(5,2) (commandes), DECIMAL(7,4). */
    private const TYPES_POURCENTAGE = [[5, 2], [7, 4]];

    /** Les mises à jour simples, telles que les anciennes migrations les faisaient. */
    private const MISES_A_JOUR = [
        Conversion::VENDABLE => 'UPDATE articles SET vendable = 1 WHERE vendable = 0',
        Conversion::CONSOMMABLE => 'UPDATE articles SET consommable_production = 1 WHERE vendable = 0',
        Conversion::TYPE_COMPTE => "UPDATE clients SET type_compte = 'entreprise' WHERE type_client = 'B2B'",
        Conversion::AMORTISSEMENT => "UPDATE machines SET mode_facturation = 'metre_carre', revenu_avant_suivi = 13243916, date_debut_suivi = '2026-08-17' WHERE nom LIKE '%Traceur 1.8%'",
    ];

    private const DESCRIPTIONS = [
        Conversion::VENDABLE => 'article(s) non vendable(s) rendus vendables, comme la mise à jour du 19/08/2026 : sinon ils n\'apparaissent pas dans les commandes et les devis. Ceux qui ne se vendent pas (encre, papier…) sont à décocher ensuite sur leur fiche',
        Conversion::CONSOMMABLE => 'article(s) non vendable(s) marqués « consommable en production », comme la mise à jour du 01/09/2026 : ils restent dans l\'écran Consommables',
        Conversion::TYPE_COMPTE => 'client(s) B2B passés en compte « entreprise », comme la mise à jour du 17/08/2026 (sinon tous seraient « particulier »)',
        Conversion::AMORTISSEMENT => 'machine(s) (traceur 1,8 m) reçoivent le récapitulatif d\'amortissement de la mise à jour du 17/08/2026',
    ];

    public function __construct(private readonly Connection $connexion)
    {
    }

    /**
     * Conversions qui manquent à la base de l'imprimerie, d'après sa structure
     * et ses lignes. $dejaFaites : cibles converties plus tôt dans la même
     * correction, à ne pas redétecter.
     *
     * @param list<string> $dejaFaites
     *
     * @return list<Conversion>
     */
    public function detecter(Schema $boutique, bool $sansDonnees, array $dejaFaites = []): array
    {
        $conversions = [];
        foreach (array_keys(self::REMISES) as $table) {
            if (\in_array($table.'.remise', $dejaFaites, true) || !self::aColonnes($boutique, $table, ['id', 'remise', 'total_ht'])) {
                continue;
            }
            $colonne = LectureDiff::colonne($boutique->getTable($table)->getColumn('remise'));
            $lignes = $sansDonnees ? [] : $this->lignesRemise($boutique, $table);
            if (null !== ($c = self::deciderRemise($table, $colonne, $lignes, $sansDonnees))) {
                $conversions[] = $c;
            }
        }

        // 19/08/2026 : valeur par défaut de articles.vendable passée à 1, et articles existants rendus vendables.
        $vendable = self::aColonnes($boutique, 'articles', ['vendable'])
            && '0' === LectureDiff::colonne($boutique->getTable('articles')->getColumn('vendable'))['defaut'];
        $simples = [
            Conversion::VENDABLE => [$vendable, 'articles', 'vendable', '`vendable` = 0', Conversion::AVANT],
            // 01/09/2026 : consommable_production = (vendable = 0). Après la reprise du 19/08, aucun article n'est concerné.
            Conversion::CONSOMMABLE => [!$vendable && self::aColonnes($boutique, 'articles', ['vendable']) && !self::aColonnes($boutique, 'articles', ['consommable_production']), 'articles', 'consommable_production', '`vendable` = 0', Conversion::APRES],
            Conversion::TYPE_COMPTE => [self::aColonnes($boutique, 'clients', ['type_client']) && !self::aColonnes($boutique, 'clients', ['type_compte']), 'clients', 'type_compte', "`type_client` = 'B2B'", Conversion::APRES],
            Conversion::AMORTISSEMENT => [self::aColonnes($boutique, 'machines', ['nom']) && !self::aColonnes($boutique, 'machines', ['revenu_avant_suivi']), 'machines', 'revenu_avant_suivi', "`nom` LIKE '%Traceur 1.8%'", Conversion::APRES],
        ];
        foreach ($simples as $code => [$manque, $table, $colonne, $condition, $moment]) {
            if (!$manque || \in_array($table.'.'.$colonne, $dejaFaites, true)) {
                continue;
            }
            $n = $sansDonnees ? 0 : (int) $this->connexion->fetchOne(\sprintf('SELECT COUNT(*) FROM `%s` WHERE %s', $table, $condition));
            if ($sansDonnees) {
                $conversions[] = new Conversion($code, $table, $colonne, Conversion::A_FAIRE, ucfirst(self::DESCRIPTIONS[$code]).' (copie de la structure : aucune ligne ici).', $moment);
            } elseif ($n > 0) {
                $conversions[] = new Conversion($code, $table, $colonne, Conversion::A_FAIRE, $n.' '.self::DESCRIPTIONS[$code].'.', $moment, $n);
            }
        }

        return $conversions;
    }

    /**
     * Sens de la colonne remise d'une table de lignes, et ce qu'il faut faire.
     *
     * - DECIMAL(5,2) ou DECIMAL(7,4) : les types de la remise en pourcentage
     *   dans l'ancienne version (jamais un montant) : conversion, si toutes
     *   les valeurs sont entre 0 et 100.
     * - Autre type (nombre entier : version plus ancienne, ou déjà en F par
     *   unité) : décidé d'après les lignes. Pourcentage seulement si des
     *   lignes suivent le calcul en pourcentage, aucune le calcul en F par
     *   unité, et pas moins que de lignes qui ne suivent ni l'un ni l'autre ;
     *   F par unité dans le cas inverse ; sinon bloquant.
     *
     * Une conversion à faire est essayée en mémoire : si une ligne cohérente
     * ne retombait pas sur son total à 1 F par unité près, c'est bloquant.
     *
     * @param array<string, mixed> $colonne la colonne remise de l'imprimerie (LectureDiff::colonne)
     * @param list<LigneRemise>    $lignes  les lignes dont la remise n'est pas 0
     */
    public static function deciderRemise(string $table, array $colonne, array $lignes, bool $sansDonnees): ?Conversion
    {
        $signature = 'decimal' === ($colonne['type'] ?? null)
            && \in_array([(int) ($colonne['precision'] ?? 0), (int) ($colonne['echelle'] ?? 0)], self::TYPES_POURCENTAGE, true);
        $numerique = \in_array($colonne['type'] ?? null, ['integer', 'smallint', 'bigint', 'decimal'], true);

        if ($sansDonnees) {
            if ($signature) {
                return new Conversion(Conversion::REMISE, $table, 'remise', Conversion::A_FAIRE, 'Remise en pourcentage (type décimal de l\'ancienne version) : convertie en F par unité facturable d\'après le total enregistré de chaque ligne (copie de la structure : aucune ligne ici).');
            }

            return $numerique
                ? new Conversion(Conversion::REMISE, $table, 'remise', Conversion::A_REVOIR, 'remise en nombre : pourcentage (ancienne version) ou F par unité facturable ? Décidé d\'après les lignes sur la vraie base ; si elles ne permettent pas de trancher, la bascule s\'arrête.')
                : null;
        }

        $horsBornes = \count(array_filter($lignes, static fn (LigneRemise $l): bool => !$l->dansLesBornes()));
        if ($signature) {
            if ($horsBornes > 0) {
                return new Conversion(Conversion::REMISE, $table, 'remise', Conversion::BLOQUANT, \sprintf('Remise en pourcentage (type décimal de l\'ancienne version), mais %d ligne(s) ont une valeur hors de 0 à 100 : ce n\'est pas un pourcentage et la bascule ne devine pas. À voir avec nous.', $horsBornes));
            }

            return self::conversionRemise($table, $lignes, 'Remise en pourcentage (type décimal de l\'ancienne version)');
        }
        if ([] === $lignes || !$numerique) {
            return null;
        }

        $votes = ['pourcentage' => 0, 'unite' => 0, 'deux' => 0, 'aucun' => 0];
        foreach ($lignes as $l) {
            $p = $l->suitLePourcentage();
            $u = $l->suitLaRemiseParUnite();
            ++$votes[$p && $u ? 'deux' : ($p ? 'pourcentage' : ($u ? 'unite' : 'aucun'))];
        }
        $detail = \sprintf(
            'sur %d ligne(s) avec une remise, %d suivent le calcul en pourcentage, %d le calcul en F par unité, %d les deux, %d aucun des deux',
            \count($lignes), $votes['pourcentage'], $votes['unite'], $votes['deux'], $votes['aucun'],
        );
        if ($votes['pourcentage'] > 0 && 0 === $votes['unite'] && $votes['pourcentage'] >= $votes['aucun'] && 0 === $horsBornes) {
            return self::conversionRemise($table, $lignes, 'Remise en nombre, mais en pourcentage (ancienne version)', ' Pourcentage d\'après les lignes : '.$detail.'.');
        }
        if ($votes['unite'] > 0 && 0 === $votes['pourcentage'] && $votes['unite'] >= $votes['aucun']) {
            return null; // déjà en F par unité : rien à convertir
        }

        return new Conversion(Conversion::REMISE, $table, 'remise', Conversion::BLOQUANT, 'Remise en nombre : impossible de savoir si c\'est un pourcentage (ancienne version) ou un montant en F par unité ; '.$detail.'. À voir avec nous : la bascule ne devine pas.');
    }

    /** @param list<LigneRemise> $lignes */
    private static function conversionRemise(string $table, array $lignes, string $origine, string $pourquoi = ''): Conversion
    {
        if ([] === $lignes) {
            return new Conversion(Conversion::REMISE, $table, 'remise', Conversion::A_FAIRE, $origine.' : aucune ligne n\'a de remise, seul le type de la colonne change.');
        }
        $bilan = new BilanRemises();
        foreach ($lignes as $l) {
            $bilan->compter($l->verdict($l->remiseParUnite(), $l->suitLePourcentage()));
        }
        $message = \sprintf(
            '%s : %d ligne(s) avec une remise, à convertir en F par unité facturable comme la mise à jour du 20/08/2026 ; les totaux enregistrés ne changent pas. Essai du calcul : %s.',
            $origine, \count($lignes), $bilan->texte(),
        ).$pourquoi;

        return $bilan->ecarts() > 0
            ? new Conversion(Conversion::REMISE, $table, 'remise', Conversion::BLOQUANT, $message.' Des lignes ne retomberaient pas sur leur total : à voir avec nous.', Conversion::AVANT, \count($lignes))
            : new Conversion(Conversion::REMISE, $table, 'remise', Conversion::A_FAIRE, $message, Conversion::AVANT, \count($lignes));
    }

    /**
     * Rejoue les conversions du moment donné (avant ou après le changement de
     * schéma). Rend, pour chaque table de lignes dont la remise a été
     * convertie, le total enregistré de chaque ligne (contrôle final).
     *
     * @param list<Conversion>      $conversions
     * @param callable(string): void $note
     *
     * @return array<string, array<int, array{total: int, coherente: bool}>>
     */
    public function appliquer(array $conversions, string $moment, Schema $boutique, callable $note): array
    {
        $suivi = [];
        foreach ($conversions as $c) {
            if (!$c->aFaire() || $moment !== $c->moment) {
                continue;
            }
            if (Conversion::REMISE === $c->code) {
                $suivi[$c->table] = $this->convertirRemises($boutique, $c->table);
                $note(\sprintf('Conversion %s : %d ligne(s) passées du pourcentage aux F par unité facturable.', $c->cible(), \count($suivi[$c->table])));
                continue;
            }
            $n = $this->connexion->executeStatement(self::MISES_A_JOUR[$c->code]);
            if (Conversion::VENDABLE === $c->code && 0 !== (int) $this->connexion->fetchOne('SELECT COUNT(*) FROM articles WHERE vendable = 0')) {
                throw new \RuntimeException('Conversion articles.vendable : des articles sont restés non vendables.');
            }
            $note(\sprintf('Conversion %s : %d ligne(s) mises à jour.', $c->cible(), $n));
        }

        return $suivi;
    }

    /**
     * Recalcule chaque ligne convertie avec le calcul de la nouvelle version,
     * d'après ce qui est maintenant en base, et la compare à son total
     * enregistré.
     *
     * @param array<string, array<int, array{total: int, coherente: bool}>> $suivi
     *
     * @return array<string, BilanRemises>
     *
     * @throws \RuntimeException un total enregistré a changé, ou une ligne s'écarte de plus d'1 F par unité
     */
    public function controlerRemises(array $suivi, Schema $boutique): array
    {
        $bilans = [];
        foreach ($suivi as $table => $attendues) {
            $bilan = new BilanRemises();
            foreach (array_chunk(array_keys($attendues), 500) as $ids) {
                foreach ($this->lignesRemise($boutique, $table, $ids) as $l) {
                    if ($l->totalHt !== $attendues[$l->id]['total']) {
                        throw new \RuntimeException(\sprintf('Conversion %s.remise : le total enregistré d\'une ligne a changé, ce qui ne doit jamais arriver.', $table));
                    }
                    $bilan->compter($l->verdict((int) $l->remise, $attendues[$l->id]['coherente']));
                }
            }
            if ($bilan->lignes() !== \count($attendues)) {
                throw new \RuntimeException(\sprintf('Conversion %s.remise : %d ligne(s) convertie(s) introuvable(s).', $table, \count($attendues) - $bilan->lignes()));
            }
            if ($bilan->ecarts() > 0) {
                throw new \RuntimeException(\sprintf('Conversion %s.remise : %d ligne(s) ne retombent pas sur leur total enregistré avec le nouveau calcul (écart de plus d\'1 F par unité facturable). Contrôle : %s.', $table, $bilan->ecarts(), $bilan->texte()));
            }
            $bilans[$table] = $bilan;
        }

        return $bilans;
    }

    /**
     * « Garder le total » (décision de Yaya, 15/10/2026) : chaque ligne
     * convertie dont le nouveau calcul ne redonne pas exactement son total
     * enregistré (à 1 F près par unité, ou déjà différente avant la
     * bascule) est marquée total_fige : elle garde ce total quand on la
     * réenregistre sans rien changer, et n'est recalculée que si sa
     * quantité, son prix, sa remise, ses dimensions ou ses finitions
     * changent (TotalFigeTrait).
     *
     * Appelé après les contrôles (la base est alors identique à la
     * référence) : la colonne, qui vient d'une migration postérieure à la
     * migration de base, est ajoutée ici si elle manque.
     *
     * @param array<string, array<int, array{total: int, coherente: bool}>> $suivi
     *
     * @return array<string, int> nombre de lignes figées, par table
     */
    public function figerTotaux(array $suivi, Schema $boutique): array
    {
        $figees = [];
        foreach ($suivi as $table => $attendues) {
            if (!$boutique->getTable($table)->hasColumn(self::COLONNE_TOTAL_FIGE)) {
                $this->connexion->executeStatement(\sprintf('ALTER TABLE `%s` ADD `%s` %s', $table, self::COLONNE_TOTAL_FIGE, self::DEFINITION_TOTAL_FIGE));
            }
            $ids = [];
            foreach (array_chunk(array_keys($attendues), 500) as $lot) {
                foreach ($this->lignesRemise($boutique, $table, $lot) as $l) {
                    if (Remises::IDENTIQUE !== $l->verdict((int) $l->remise, $attendues[$l->id]['coherente'])) {
                        $ids[] = $l->id;
                    }
                }
            }
            foreach (array_chunk($ids, 500) as $lot) {
                $this->connexion->executeStatement(\sprintf('UPDATE `%s` SET `%s` = 1 WHERE `id` IN (%s)', $table, self::COLONNE_TOTAL_FIGE, implode(', ', array_map('intval', $lot))));
            }
            $figees[$table] = \count($ids);
        }

        return $figees;
    }

    /**
     * Comme l'ancienne mise à jour : la colonne est d'abord élargie (un
     * montant en F ne tient pas dans DECIMAL(5,2)), puis chaque ligne reçoit
     * sa remise par unité. Le passage en nombre entier vient ensuite, avec
     * le reste du schéma.
     *
     * @return array<int, array{total: int, coherente: bool}>
     */
    private function convertirRemises(Schema $boutique, string $table): array
    {
        $lignes = $this->lignesRemise($boutique, $table);
        if ([] === $lignes) {
            return [];
        }
        if ('decimal' === LectureDiff::colonne($boutique->getTable($table)->getColumn('remise'))['type']) {
            $this->connexion->executeStatement(\sprintf('ALTER TABLE `%s` MODIFY `remise` DECIMAL(15,4) NOT NULL DEFAULT 0', $table));
        }
        $suivi = [];
        $this->connexion->beginTransaction();
        try {
            foreach ($lignes as $l) {
                $this->connexion->executeStatement(\sprintf('UPDATE `%s` SET `remise` = ? WHERE `id` = ?', $table), [$l->remiseParUnite(), $l->id]);
                $suivi[$l->id] = ['total' => $l->totalHt, 'coherente' => $l->suitLePourcentage()];
            }
            $this->connexion->commit();
        } catch (\Throwable $e) {
            $this->connexion->rollBack();
            throw $e;
        }
        if (0 !== (int) $this->connexion->fetchOne(\sprintf('SELECT COUNT(*) FROM `%s` WHERE `remise` <> ROUND(`remise`)', $table))) {
            throw new \RuntimeException(\sprintf('Conversion %s.remise : des remises ne sont pas des francs entiers.', $table));
        }

        return $suivi;
    }

    /**
     * Lignes avec une remise (ou les lignes $ids), avec ce qu'il faut pour
     * recalculer leur total. Une colonne absente d'une ancienne structure
     * prend la valeur par défaut qu'elle aura après la bascule.
     *
     * @param list<int>|null $ids
     *
     * @return list<LigneRemise>
     */
    private function lignesRemise(Schema $boutique, string $table, ?array $ids = null): array
    {
        $t = $boutique->getTable($table);
        $champ = static fn (string $nom, string $defaut): string => $t->hasColumn($nom) ? 'd.`'.$nom.'`' : $defaut;
        [$tableFinitions, $lien] = self::REMISES[$table];
        $avecFinitions = self::aColonnes($boutique, $tableFinitions, [$lien, 'montant']);

        $sql = \sprintf(
            'SELECT d.`id` AS id, d.`remise` AS remise, d.`total_ht` AS total_ht, %s AS prix_unitaire, %s AS quantite, %s AS surface, %s AS longueur, %s AS nombre_faces, %s AS mode_calcul, %s AS finitions FROM `%s` d %s WHERE %s ORDER BY d.`id`',
            $champ('prix_unitaire', '0'), $champ('quantite', '1'), $champ('surface', 'NULL'), $champ('longueur', 'NULL'), $champ('nombre_faces', '1'), $champ('mode_calcul', "'unite'"),
            $avecFinitions ? 'COALESCE(f.montant, 0)' : '0',
            $table,
            $avecFinitions ? \sprintf('LEFT JOIN (SELECT `%1$s` AS ligne, SUM(GREATEST(0, `montant`)) AS montant FROM `%2$s` GROUP BY `%1$s`) f ON f.ligne = d.`id`', $lien, $tableFinitions) : '',
            null === $ids ? 'd.`remise` <> 0' : 'd.`id` IN ('.implode(', ', array_map('intval', $ids ?: [0])).')',
        );

        return array_map(LigneRemise::depuis(...), $this->connexion->fetchAllAssociative($sql));
    }

    /** @param list<string> $colonnes */
    private static function aColonnes(Schema $schema, string $table, array $colonnes): bool
    {
        if (!$schema->hasTable($table)) {
            return false;
        }
        $t = $schema->getTable($table);
        foreach ($colonnes as $colonne) {
            if (!$t->hasColumn($colonne)) {
                return false;
            }
        }

        return true;
    }
}
