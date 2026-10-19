<?php

namespace App\Service\DossiersClients;

/**
 * Boutons « Ouvrir avec Illustrator » et « Afficher dans le dossier »
 * (maquette DS01). Un navigateur ne peut ni lancer un logiciel ni ouvrir
 * l'Explorateur : le bouton ouvre un lien
 * « succes-fichier://ouvrir?chemin=…&exp=…&sig=… » que reçoit un petit
 * programme installé sur chaque poste Windows
 * (outils/succes-fichier/, docs/dossiers-clients.md).
 *
 * Le lien est signé (HMAC-SHA256 avec la clé des postes) et vaut
 * 10 minutes ; le programme refuse un lien mal signé, périmé ou dont le
 * chemin sort du dossier racine vu des postes. L'ERP refuse déjà de
 * signer un chemin hors racine.
 *
 * Classe pure, testée sans base.
 */
final class LienProtocole
{
    public const SCHEMA = 'succes-fichier';
    public const ACTIONS = ['ouvrir', 'afficher'];
    public const DUREE = 600;

    public function __construct(private readonly string $cle)
    {
    }

    /** Clé à recopier sur les postes (dérivée du secret de l'application). */
    public static function clePostes(string $secretApplication): string
    {
        return hash_hmac('sha256', 'succes-fichier/postes', $secretApplication);
    }

    public function signature(string $action, string $chemin, int $expire): string
    {
        return hash_hmac('sha256', $action."\n".$chemin."\n".$expire, $this->cle);
    }

    public function lien(string $action, string $cheminPoste, int $maintenant): string
    {
        if (!\in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Action inconnue');
        }
        $expire = $maintenant + self::DUREE;

        return self::SCHEMA.'://'.$action.'?'.http_build_query(['chemin' => $cheminPoste, 'exp' => $expire, 'sig' => $this->signature($action, $cheminPoste, $expire)], '', '&', \PHP_QUERY_RFC3986);
    }

    /**
     * Ce que fait le programme du poste : le chemin si le lien est bon,
     * sinon null. Écrit ici pour être testé ; le script PowerShell fait
     * les mêmes contrôles.
     *
     * @return array{action: string, chemin: string}|null
     */
    public function verifier(string $lien, string $racinePoste, int $maintenant): ?array
    {
        $parties = parse_url($lien);
        if (false === $parties || self::SCHEMA !== ($parties['scheme'] ?? null) || !\in_array($parties['host'] ?? '', self::ACTIONS, true)) {
            return null;
        }
        parse_str((string) ($parties['query'] ?? ''), $q);
        $chemin = \is_string($q['chemin'] ?? null) ? $q['chemin'] : '';
        $expire = (int) ($q['exp'] ?? 0);
        $sig = \is_string($q['sig'] ?? null) ? $q['sig'] : '';
        if ('' === $chemin || $expire < $maintenant || $expire > $maintenant + self::DUREE + 60 || !hash_equals($this->signature((string) $parties['host'], $chemin, $expire), $sig)) {
            return null;
        }
        if (!self::sousRacine($chemin, $racinePoste)) {
            return null;
        }

        return ['action' => (string) $parties['host'], 'chemin' => $chemin];
    }

    /**
     * Le chemin est-il dans la racine ? « .. », « . » et les séparateurs
     * sont ramenés avant la comparaison (sans casse, comme Windows).
     */
    public static function sousRacine(string $chemin, string $racine): bool
    {
        $c = self::normaliser($chemin);
        $r = rtrim(self::normaliser($racine), '/');
        if (null === $c || '' === $r || str_contains($chemin, "\0")) {
            return false;
        }

        return str_starts_with(mb_strtolower($c).'/', mb_strtolower($r).'/') && mb_strlen($c) > mb_strlen($r);
    }

    /**
     * Chemin vu des postes Windows pour un chemin vu par le serveur de
     * l'ERP : « /srv/clients/C-0087 Hôtel Niger/01 Conceptions/a.ai » avec
     * les racines « /srv/clients » et « \\SERVEUR\Clients » donne
     * « \\SERVEUR\Clients\C-0087 Hôtel Niger\01 Conceptions\a.ai ».
     * null si le chemin sort de la racine.
     */
    public static function cheminPoste(string $cheminServeur, string $racineServeur, string $racinePoste): ?string
    {
        if (!self::sousRacine($cheminServeur, $racineServeur)) {
            return null;
        }
        $c = (string) self::normaliser($cheminServeur);
        $r = rtrim((string) self::normaliser($racineServeur), '/');
        $relatif = ltrim(mb_substr($c, mb_strlen($r)), '/');
        $poste = '' !== trim($racinePoste) ? $racinePoste : $racineServeur;
        $windows = str_contains($poste, '\\') || 1 === preg_match('/^[A-Za-z]:/', $poste);
        $sep = $windows ? '\\' : '/';

        return rtrim($poste, '/\\').$sep.str_replace('/', $sep, $relatif);
    }

    /**
     * « \\SERVEUR\Clients\a\..\b » → « //SERVEUR/Clients/b » ; null si « .. »
     * remonte au-dessus du début.
     */
    public static function normaliser(string $chemin): ?string
    {
        $chemin = str_replace('\\', '/', trim($chemin));
        $debut = '';
        if (str_starts_with($chemin, '//')) {
            $debut = '//';
            $chemin = substr($chemin, 2);
        } elseif (preg_match('#^([A-Za-z]:)/?#', $chemin, $m)) {
            $debut = strtoupper($m[1]).'/';
            $chemin = substr($chemin, \strlen($m[0]));
        } elseif (str_starts_with($chemin, '/')) {
            $debut = '/';
        }
        $parties = [];
        foreach (explode('/', $chemin) as $p) {
            if ('' === $p || '.' === $p) {
                continue;
            }
            if ('..' === $p) {
                if ([] === $parties) {
                    return null;
                }
                array_pop($parties);
                continue;
            }
            $parties[] = $p;
        }

        return $debut.implode('/', $parties);
    }
}
