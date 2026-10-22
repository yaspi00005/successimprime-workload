<?php

namespace App\Service\Documentation;

/**
 * Quand tourne chaque commande planifiée, d'après les fichiers qui la
 * lancent : les scripts Windows de l'ERP local (scripts/windows/*.bat, la
 * tâche « schtasks » de l'installateur et le script qu'elle appelle) et les
 * lignes « crontab » écrites dans docs/*.md (serveur en ligne, Linux).
 *
 * Tout est statique et pur : on lui donne le texte des fichiers.
 */
final class TachesPlanifiees
{
    /**
     * Commandes lancées par chaque script, et fréquence de la tâche Windows
     * qui appelle ce script.
     *
     * @param array<string, string> $scripts nom du fichier .bat => contenu
     *
     * @return array<string, list<array{frequence: string, ou: string, source: string}>> commande => planifications
     */
    public static function depuisScriptsWindows(array $scripts): array
    {
        $commandesParScript = [];
        foreach ($scripts as $nom => $contenu) {
            preg_match_all('/bin\\\\console"?\s+(app:[a-z0-9:-]+)/i', $contenu, $m);
            if ([] !== $m[1]) {
                $commandesParScript[strtolower($nom)] = array_values(array_unique($m[1]));
            }
        }

        $resultat = [];
        foreach ($scripts as $nom => $contenu) {
            // Variables « set X=...\script.bat » de l'installateur.
            preg_match_all('/^\s*set\s+([A-Z_]+)=.*?([A-Za-z0-9_]+\.bat)\s*$/mi', $contenu, $sets, \PREG_SET_ORDER);
            $variables = [];
            foreach ($sets as $s) {
                $variables[strtoupper($s[1])] = strtolower($s[2]);
            }
            foreach (preg_split('/schtasks\s+\/create/i', $contenu) ?: [] as $i => $bloc) {
                if (0 === $i) {
                    continue;
                }
                // Le bloc s'arrête à « /f » (fin de la commande schtasks).
                $bloc = preg_split('/\s\/f\b/i', $bloc)[0] ?? $bloc;
                if (!preg_match('/\/tr\s+"\\\\?"?%([A-Z_]+)%/i', $bloc, $tr) || !isset($variables[strtoupper($tr[1])])) {
                    continue;
                }
                $script = $variables[strtoupper($tr[1])];
                $frequence = self::frequenceSchtasks($bloc);
                foreach ($commandesParScript[$script] ?? [] as $commande) {
                    $resultat[$commande][] = ['frequence' => $frequence, 'ou' => 'ERP local (tâche planifiée Windows)', 'source' => 'scripts/windows/'.$nom.' → '.$script];
                }
            }
        }

        return $resultat;
    }

    /** « /sc minute /mo 5 » => « toutes les 5 minutes ». */
    public static function frequenceSchtasks(string $bloc): string
    {
        $sc = preg_match('/\/sc\s+(\w+)/i', $bloc, $m) ? strtolower($m[1]) : '';
        $mo = preg_match('/\/mo\s+(\d+)/i', $bloc, $m) ? (int) $m[1] : 1;
        $st = preg_match('/\/st\s+(\d{2}:\d{2})/i', $bloc, $m) ? $m[1] : null;

        return match ($sc) {
            'minute' => 1 === $mo ? 'chaque minute' : 'toutes les '.$mo.' minutes',
            'hourly' => 1 === $mo ? 'chaque heure' : 'toutes les '.$mo.' heures',
            'daily' => (1 === $mo ? 'chaque jour' : 'tous les '.$mo.' jours').(null !== $st ? ' à '.$st : ''),
            'weekly' => 'chaque semaine'.(null !== $st ? ' à '.$st : ''),
            'onstart' => 'au démarrage de l\'ordinateur',
            'onlogon' => 'à l\'ouverture de session',
            default => '' === $sc ? 'inconnue' : $sc,
        };
    }

    /**
     * Lignes crontab qui lancent une commande app:… dans un texte Markdown.
     *
     * @return array<string, list<array{frequence: string, ou: string, source: string}>>
     */
    public static function depuisCrontab(string $markdown, string $source, string $ou): array
    {
        $resultat = [];
        preg_match_all('/(?:^|(?<=[`\s]))(@[a-z]+|(?:[\d*\/,-]+[ \t]+){4}[\d*\/,-]+)[ \t]+([^\n`]*?)bin\/console[ \t]+(app:[a-z0-9:-]+)/m', $markdown, $m, \PREG_SET_ORDER);
        foreach ($m as $ligne) {
            $expression = trim(preg_replace('/\s+/', ' ', $ligne[1]) ?? '');
            if (!self::estExpressionCron($expression)) {
                continue;
            }
            // « cd /chemin/du/projet » : exemple pour un ERP local sous Linux ou Mac, pas le serveur.
            $lieu = str_contains($ligne[2], '/chemin/du/projet') ? 'ERP local sous Linux ou Mac (exemple de crontab)' : $ou;
            $resultat[$ligne[3]][] = ['frequence' => self::frequenceCron($expression), 'ou' => $lieu, 'source' => $source.' (crontab : '.$expression.')'];
        }

        return $resultat;
    }

    public static function estExpressionCron(string $expression): bool
    {
        if (str_starts_with($expression, '@')) {
            return \in_array($expression, ['@reboot', '@hourly', '@daily', '@weekly', '@monthly', '@yearly', '@annually', '@midnight'], true);
        }

        return 1 === preg_match('#^([\d*/,-]+ ){4}[\d*/,-]+$#', $expression);
    }

    /** « *\/15 * * * * » => « toutes les 15 minutes », « 30 3 * * * » => « chaque jour à 03:30 ». */
    public static function frequenceCron(string $expression): string
    {
        $raccourcis = ['@reboot' => 'au démarrage du serveur', '@hourly' => 'chaque heure', '@daily' => 'chaque jour à 00:00', '@midnight' => 'chaque jour à 00:00', '@weekly' => 'chaque semaine', '@monthly' => 'chaque mois', '@yearly' => 'chaque année', '@annually' => 'chaque année'];
        if (isset($raccourcis[$expression])) {
            return $raccourcis[$expression];
        }
        [$minute, $heure, $jour, $mois, $semaine] = explode(' ', $expression);
        $reste = '* * *' === "$jour $mois $semaine";
        if ($reste && '*' === $heure) {
            if ('*' === $minute) {
                return 'chaque minute';
            }
            if (preg_match('#^\*/(\d+)$#', $minute, $m)) {
                return 'toutes les '.$m[1].' minutes';
            }
            if (ctype_digit($minute)) {
                return 'chaque heure à la minute '.(int) $minute;
            }
        }
        if ($reste && ctype_digit($minute) && ctype_digit($heure)) {
            return \sprintf('chaque jour à %02d:%02d', (int) $heure, (int) $minute);
        }
        if ($reste && ctype_digit($minute) && preg_match('#^\*/(\d+)$#', $heure, $m)) {
            return 'toutes les '.$m[1].' heures';
        }

        return 'crontab « '.$expression.' »';
    }

    /**
     * Phrases des docs qui donnent un rythme à une commande
     * (« `app:devis:relances` chaque heure »).
     *
     * @return array<string, list<array{frequence: string, ou: string, source: string}>>
     */
    public static function depuisPhrases(string $markdown, string $source): array
    {
        $resultat = [];
        // « schtasks /Create /SC DAILY /ST 08:15 … app:promotions » écrit dans une page.
        preg_match_all('/schtasks\s+\/create([^\n`]*?)(app:[a-z0-9:-]+)/i', $markdown, $w, \PREG_SET_ORDER);
        foreach ($w as $t) {
            $resultat[$t[2]][] = ['frequence' => self::frequenceSchtasks($t[1]), 'ou' => 'ERP local (tâche planifiée Windows, d\'après la documentation)', 'source' => $source];
        }
        $unite = '(?:\d+ )?(?:minutes?|heures?|jours?|nuits?|semaines?|mois|quart d\'heure|matin|soir)';
        preg_match_all('/`(?:php bin\/console )?(app:[a-z0-9:-]+)[^`]*`[^.\n`]{0,25}?\b((?:chaque|toutes les|tous les|une fois par) '.$unite.'(?: à \d{1,2} ?h(?: ?\d{2})?)?)/mu', $markdown, $m, \PREG_SET_ORDER);
        foreach ($m as $t) {
            $resultat[$t[1]][] = ['frequence' => trim($t[2]), 'ou' => 'd\'après la documentation', 'source' => $source];
        }
        // « php bin/console app:fidelite   # chaque nuit : … » dans un bloc de code.
        preg_match_all('/bin\/console (app:[a-z0-9:-]+)[ \t]+#[ \t]*((?:chaque|toutes les|tous les|une fois par) '.$unite.')/u', $markdown, $c, \PREG_SET_ORDER);
        foreach ($c as $t) {
            $resultat[$t[1]][] = ['frequence' => trim($t[2]), 'ou' => 'd\'après la documentation', 'source' => $source];
        }

        return $resultat;
    }

    /**
     * Réunit plusieurs relevés sans doublon (même fréquence, même lieu).
     *
     * @param list<array<string, list<array{frequence: string, ou: string, source: string}>>> $releves
     *
     * @return array<string, list<array{frequence: string, ou: string, source: string}>>
     */
    public static function fusionner(array $releves): array
    {
        $tout = [];
        foreach ($releves as $releve) {
            foreach ($releve as $commande => $planifications) {
                foreach ($planifications as $p) {
                    $cle = $p['frequence'].'|'.$p['ou'];
                    $tout[$commande][$cle] ??= $p;
                }
            }
        }
        ksort($tout);

        return array_map('array_values', $tout);
    }
}
