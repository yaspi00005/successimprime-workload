<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Atelier de production de démonstration (docs/atelier-production.md,
 * docs/donnees-demo.md) : une journée en cours sur les machines fictives,
 * à l'heure du lancement. Travaux en cours, en pause, terminés aujourd'hui,
 * files d'attente avec un retard et un travail bloqué, la sublimation en
 * panne, un incident surveillé, l'encre saisie, l'entretien préventif, les
 * opérateurs affectés (demo.oumar : les deux solvants ; demo.ibrahim : les
 * autres, et chef d'atelier).
 *
 * Les ordres de production fictifs les plus récents de chaque machine sont
 * réécrits pour la journée (statut, heures, ligne de commande). Lancée par
 * app:demo après le chargement ; relancée, elle refait la même journée à
 * l'heure du moment. Refusée en production et sur le site en ligne.
 */
#[AsCommand(name: 'app:atelier:demo', description: 'Journée de démonstration de l\'atelier de production : machines, files, panne, encre, entretien.')]
final class AtelierDemoCommand extends Command
{
    private const OUMAR = 900008;
    private const IBRAHIM = 900009;

    /**
     * Par machine : rôle de chaque ordre repris, du plus récent au plus ancien.
     * t = terminé aujourd'hui, c = en cours, p = en pause, a = à produire,
     * r = à produire en retard, b = bloqué (fichier à corriger).
     */
    private const SCENARIO = [
        900001 => ['c', 't', 'r', 'a', 'a', 'b'],
        900002 => ['c', 't', 't', 'a', 'a', 'a', 'a', 'b'],
        900003 => ['c', 't', 'a', 'a'],
        900004 => ['p', 't', 'a', 'a', 'a'],
        900008 => ['a', 'a'],
    ];

    public function __construct(
        private readonly Connection $db,
        #[Autowire('%kernel.environment%')] private readonly string $environnement,
        #[Autowire('%env(bool:APP_EN_LIGNE)%')] private readonly bool $enLigne = false,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environnement) {
            $io->error('Pas d\'atelier fictif en production.');

            return Command::FAILURE;
        }
        if ($this->enLigne) {
            $io->note('Site en ligne : l\'atelier de production est sur l\'ERP local seulement.');

            return Command::SUCCESS;
        }
        $machines = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM machines WHERE id BETWEEN 900001 AND 900008'));
        if (\count($machines) < 8) {
            $io->text('Atelier de démonstration : machines fictives absentes, rien à faire.');

            return Command::SUCCESS;
        }
        $n = new \DateTimeImmutable();
        $f = static fn (\DateTimeImmutable $d): string => $d->format('Y-m-d H:i:s');
        // Heures « du jour » gardées entre 8 h et maintenant.
        $matin = $n->setTime(8, 0);
        $jour = static fn (int $minutesAvant) => max($matin, $n->modify('-'.$minutesAvant.' minutes'));

        $this->db->beginTransaction();
        try {
            foreach (['incident_machine', 'entretien_machine'] as $table) {
                $this->db->executeStatement("DELETE FROM $table WHERE machine_id BETWEEN 900001 AND 900008");
            }
            $this->db->executeStatement('DELETE o FROM atelier_machine_operateur o JOIN atelier_machine a ON a.id = o.atelier_machine_id WHERE a.machine_id BETWEEN 900001 AND 900008');
            $this->db->executeStatement('DELETE FROM atelier_machine WHERE machine_id BETWEEN 900001 AND 900008');
            $this->db->executeStatement('DELETE FROM maintenance WHERE id >= 990000');
            $this->db->executeStatement('DELETE FROM journal_machine WHERE id >= 990000');

            // Chef d'atelier : demo.ibrahim (en plus de son rôle d'opérateur).
            $droits = json_decode((string) $this->db->fetchOne('SELECT droits_ajoutes FROM user WHERE id = ?', [self::IBRAHIM]), true) ?: [];
            if (!\in_array('ROLE_ATELIER_CHEF', $droits, true)) {
                $droits[] = 'ROLE_ATELIER_CHEF';
                $this->db->executeStatement('UPDATE user SET droits_ajoutes = ? WHERE id = ?', [json_encode($droits), self::IBRAHIM]);
            }

            // Réglages atelier des machines : opérateurs, encre, têtes.
            $encres = [900001 => ['C' => 80, 'M' => 65, 'J' => 70, 'N' => 22], 900002 => ['C' => 55, 'M' => 40, 'J' => 75, 'N' => 60], 900003 => ['C' => 70, 'M' => 60, 'J' => 50, 'N' => 45, 'B' => 30]];
            $tetes = [900001 => '-14 months', 900002 => '-9 months', 900003 => '-5 months'];
            foreach ($machines as $i => $m) {
                $id = 990001 + $i;
                $this->db->insert('atelier_machine', [
                    'id' => $id, 'machine_id' => $m,
                    'niveaux_encre' => isset($encres[$m]) ? json_encode($encres[$m]) : null,
                    'niveaux_encre_le' => isset($encres[$m]) ? $f($jour(70)) : null,
                    'niveaux_encre_par_id' => isset($encres[$m]) ? ($m <= 900002 ? self::OUMAR : self::IBRAHIM) : null,
                    'tetes_posees_le' => isset($tetes[$m]) ? $n->modify($tetes[$m])->format('Y-m-d') : null,
                ]);
                $this->db->insert('atelier_machine_operateur', ['atelier_machine_id' => $id, 'user_id' => $m <= 900002 ? self::OUMAR : self::IBRAHIM]);
            }

            // Ordres de la journée.
            $compte = ['t' => 0, 'c' => 0, 'p' => 0, 'a' => 0];
            $promis = [1, 1, 2, 2, 3, 3, 4];
            $scenario = self::SCENARIO;
            // Le traceur reprend un travail de vinyle du solvant 1,80 m (découpe après séchage, à 14 h).
            $traceur = (int) $this->db->fetchOne('SELECT id FROM ordre_production WHERE machine_id = 900002 AND id >= 900000 ORDER BY id DESC LIMIT 1 OFFSET 8');
            foreach ($scenario as $machine => $roles) {
                $ids = array_map('intval', $this->db->fetchFirstColumn('SELECT id FROM ordre_production WHERE machine_id = ? AND id >= 900000 AND id <> ? ORDER BY id DESC LIMIT '.\count($roles), [$machine, $traceur]));
                $operateur = $machine <= 900002 ? self::OUMAR : self::IBRAHIM;
                $file = 0;
                foreach ($ids as $k => $ordre) {
                    $role = $roles[$k];
                    $this->reecrire($ordre, $role, $machine, $operateur, $n, $jour, $f, $promis[$file % \count($promis)], $k);
                    if (\in_array($role, ['a', 'r', 'b'], true)) {
                        ++$file;
                    }
                    ++$compte[\in_array($role, ['r', 'b'], true) ? 'a' : $role];
                }
            }
            if ($traceur > 0) {
                $this->reecrire($traceur, 'a', 900006, self::IBRAHIM, $n, $jour, $f, 1, 0);
                $debut = $n->setTime(14, 0) > $n ? $n->setTime(14, 0) : $n->modify('+1 day')->setTime(14, 0);
                $this->db->executeStatement('UPDATE ordre_production SET machine_id = 900006, debut_prevu_le = ?, fin_prevue_le = ?, duree_estimee_minutes = 40, instructions = ? WHERE id = ?', [$f($debut), $f($debut->modify('+40 minutes')), 'après séchage du vinyle', $traceur]);
            }

            // Pannes et incidents.
            $this->db->insert('incident_machine', ['id' => 990001, 'machine_id' => 900008, 'titre' => 'Résistance de la presse hors service', 'detail' => 'La presse ne monte plus en température.', 'etat' => 'en_reparation', 'arret' => 1, 'signale_le' => $f($n->modify('-2 days')->setTime(10, 15)), 'signale_par_id' => self::IBRAHIM, 'suite' => 'technicien '.self::jourFr($n->modify('+1 day')).' '.$n->modify('+1 day')->format('d/m'), 'devis' => 45000]);
            $this->db->insert('incident_machine', ['id' => 990002, 'machine_id' => 900001, 'titre' => 'Tête 2 : 3 buses manquantes', 'etat' => 'surveille', 'arret' => 0, 'signale_le' => $f($jour(75)), 'signale_par_id' => self::OUMAR, 'suite' => 'purge faite, test des buses à 12:00']);

            // Arrêts du mois et entretien préventif.
            $this->db->insert('maintenance', ['id' => 990001, 'machine_id' => 900001, 'date' => $f($n->modify('-6 days')->setTime(8, 30)), 'type' => 'Nettoyage têtes', 'description' => 'Nettoyage des têtes', 'cout' => '0', 'technicien' => 'Oumar', 'duree_arret_minutes' => 200]);
            $this->db->insert('maintenance', ['id' => 990002, 'machine_id' => 900004, 'date' => $f($n->modify('-4 days')->setTime(11, 0)), 'type' => 'Bourrage support', 'description' => 'Bourrage four', 'cout' => '0', 'technicien' => 'Ibrahim', 'duree_arret_minutes' => 70]);
            $taches = [
                [900001, 'Nettoyage des têtes', 'jour', 1, $f($jour(60))],
                [900002, 'Nettoyage des têtes', 'jour', 1, $f($jour(55))],
                [900003, 'Agiter l\'encre blanche', 'jour', 1, $f($n->modify('-1 day')->setTime(8, 10))],
                [900003, 'Nettoyage du capping', 'semaine', 1, $f($n->modify('-6 days')->setTime(9, 0))],
                [900001, 'Changement des filtres', 'mois', 3, $f($n->modify('-3 months')->setTime(9, 0))],
                [900006, 'Lame', 'heures', 50, $f($n->modify('-40 days')->setTime(9, 0))],
                [900004, 'Nettoyage du four', 'semaine', 2, $f($n->modify('-8 days')->setTime(9, 0))],
            ];
            foreach ($taches as $i => [$m, $tache, $freq, $int, $le]) {
                $this->db->insert('entretien_machine', ['id' => 990001 + $i, 'machine_id' => $m, 'tache' => $tache, 'frequence' => $freq, 'intervalle' => $int, 'derniere_le' => $le, 'derniere_par_id' => $m <= 900002 ? self::OUMAR : self::IBRAHIM, 'actif' => 1]);
            }

            // Gâche notée cette semaine (m²).
            $lundi = $n->modify('monday this week')->setTime(9, 0);
            $this->db->insert('journal_machine', ['id' => 990001, 'machine_id' => 900002, 'operateur_id' => self::OUMAR, 'type' => 'gache', 'quantite' => 0.6, 'note' => 'Réglage', 'le' => $f(min($n, $lundi->modify('+2 hours')))]);
            $this->db->insert('journal_machine', ['id' => 990002, 'machine_id' => 900001, 'operateur_id' => self::OUMAR, 'type' => 'gache', 'quantite' => 1.2, 'note' => 'Tête bouchée', 'le' => $f(min($n, $lundi->modify('+1 day')))]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            $io->error('Atelier de démonstration : '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->text(sprintf('Atelier de démonstration : %d terminés aujourd\'hui, %d en cours, %d en pause, %d en file, sublimation en panne. Chef d\'atelier : demo.ibrahim ; opérateur des solvants : demo.oumar.', $compte['t'], $compte['c'], $compte['p'], $compte['a'] + 1));

        return Command::SUCCESS;
    }

    /**
     * @param callable(int): \DateTimeImmutable $jour
     * @param callable(\DateTimeImmutable): string $f
     */
    private function reecrire(int $ordre, string $role, int $machine, int $operateur, \DateTimeImmutable $n, callable $jour, callable $f, int $joursPromis, int $rang): void
    {
        $ligne = (int) $this->db->fetchOne('SELECT commande_detail_id FROM ordre_production WHERE id = ?', [$ordre]);
        $controle = (int) $this->db->fetchOne('SELECT controle_pre_presse_id FROM ordre_production WHERE id = ?', [$ordre]);
        $quantite = max(1, (int) $this->db->fetchOne('SELECT COALESCE(o.quantite, d.quantite) FROM ordre_production o JOIN commandes_details d ON d.id = o.commande_detail_id WHERE o.id = ?', [$ordre]));
        $commun = ['machine_id' => $machine, 'pause_debute_le' => null, 'duree_pause_minutes' => 0, 'debut_prevu_le' => null, 'fin_prevue_le' => null, 'observation_fin' => null];
        $promis = $n->modify('+'.$joursPromis.' days')->setTime(17, 0);
        if ('7' === $promis->format('N')) {
            $promis = $promis->modify('+1 day');
        }
        switch ($role) {
            case 't':
                $fin = $jour(25 + 70 * $rang);
                $debut = max($n->setTime(7, 30), $fin->modify('-55 minutes'));
                $rebut = 0 === $rang % 2 ? max(1, (int) round($quantite * 0.02)) : 0;
                $this->db->update('ordre_production', $commun + ['statut' => 'termine', 'demarre_le' => $f($debut), 'termine_le' => $f($fin), 'transmis_le' => $f($debut), 'duree_estimee_minutes' => 55, 'quantite_produite' => $quantite + $rebut, 'quantite_rebut' => $rebut, 'operateur_id' => $operateur, 'termine_par_id' => $operateur, 'date_limite' => $f($n->setTime(17, 0))], ['id' => $ordre]);
                $this->db->executeStatement('UPDATE commandes_details SET statut_production = ?, production_terminee_le = ?, production_debute_le = ?, quantite_produite = ?, quantite_rebut = ? WHERE id = ?', [0 === $rang % 3 ? 'terminee' : 'prete_livraison', $f($fin), $f($debut), $quantite + $rebut, $rebut, $ligne]);
                break;
            case 'c':
            case 'p':
                $debut = $n->modify('-'.('c' === $role ? 50 : 95).' minutes');
                $this->db->update('ordre_production', ['statut' => 'c' === $role ? 'en_cours' : 'en_pause', 'demarre_le' => $f($debut), 'termine_le' => null, 'transmis_le' => $f($debut), 'duree_estimee_minutes' => 'c' === $role ? 120 : 150, 'quantite_produite' => 0, 'quantite_rebut' => 0, 'operateur_id' => $operateur, 'termine_par_id' => null, 'date_limite' => $f($promis)] + $commun, ['id' => $ordre]);
                if ('p' === $role) {
                    $this->db->executeStatement('UPDATE ordre_production SET pause_debute_le = ? WHERE id = ?', [$f($n->modify('-15 minutes')), $ordre]);
                }
                $this->db->executeStatement('UPDATE commandes_details SET statut_production = ?, production_debute_le = ?, production_terminee_le = NULL, quantite_produite = 0, quantite_rebut = 0 WHERE id = ?', ['en_production', $f($debut), $ligne]);
                break;
            default:
                $limite = 'r' === $role ? $n->modify('-3 days')->setTime(17, 0) : $promis;
                $this->db->update('ordre_production', ['statut' => 'a_produire', 'demarre_le' => null, 'termine_le' => null, 'transmis_le' => $f($n->modify('-1 day')), 'duree_estimee_minutes' => [25, 30, 35, 55, 40, 45][$rang % 6], 'quantite_produite' => 0, 'quantite_rebut' => 0, 'operateur_id' => null, 'termine_par_id' => null, 'date_limite' => $f($limite)] + $commun, ['id' => $ordre]);
                $this->db->executeStatement('UPDATE commandes_details SET statut_production = ?, production_debute_le = NULL, production_terminee_le = NULL, quantite_produite = 0, quantite_rebut = 0 WHERE id = ?', ['a_produire', $ligne]);
                $this->db->executeStatement('UPDATE controle_pre_presse SET statut = ? WHERE id = ?', ['b' === $role ? 'correction_requise' : 'envoye_production', $controle]);
        }
    }

    private static function jourFr(\DateTimeImmutable $d): string
    {
        return ['', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche'][(int) $d->format('N')];
    }
}
