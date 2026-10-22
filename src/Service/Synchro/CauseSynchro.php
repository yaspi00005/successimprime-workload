<?php

namespace App\Service\Synchro;

use App\Entity\SynchroJournal;

/**
 * La vraie cause d'une synchronisation qui ne passe pas, pour ne plus
 * afficher « Connexion internet coupée » quand internet fonctionne.
 *
 * L'agent note la cause de son dernier échec (AgentSynchro::ETAT_CAUSE) ;
 * le bandeau, la cloche et l'écran Synchronisation la traduisent ici.
 */
final class CauseSynchro
{
    /** Internet ne répond pas du tout depuis cet ordinateur. */
    public const INTERNET = 'internet';
    /** Internet fonctionne, mais le nom du site ne se résout pas (SYNC_URL, www, DNS). */
    public const DNS = 'dns';
    /** Le certificat SSL du site est refusé (absent, expiré, autre nom). */
    public const CERTIFICAT = 'certificat';
    /** Le site ne répond pas dans les délais (envoi trop gros, hébergeur lent). */
    public const DELAI = 'delai';
    /** Internet fonctionne, le site ne répond pas (arrêté, pare-feu, connexion coupée par l'hébergeur). */
    public const SITE = 'site';
    /** Le site refuse la signature (clé, heure, en-têtes). */
    public const REFUS = 'refus';
    /** Le site répond par une erreur (500, 404, 413…). */
    public const ERREUR_SITE = 'erreur_site';
    /** Une opération précise est refusée par le site et retient la file. */
    public const OPERATION = 'operation';
    /** L'agent ne tourne plus sur cet ordinateur (tâche planifiée arrêtée, veille). */
    public const AGENT_ARRETE = 'agent_arrete';

    /** Sans cycle depuis ce délai, l'agent est considéré arrêté (il tourne chaque minute). */
    public const AGENT_ARRETE_MINUTES = 5;

    private const TITRES = [
        self::INTERNET => 'Connexion internet coupée',
        self::DNS => 'Adresse du site introuvable',
        self::CERTIFICAT => 'Certificat du site refusé',
        self::DELAI => 'Le site répond trop lentement',
        self::SITE => 'Site injoignable (internet fonctionne)',
        self::REFUS => 'Le site refuse la synchronisation',
        self::ERREUR_SITE => 'Le site renvoie une erreur',
        self::OPERATION => 'Une opération bloque la synchronisation',
        self::AGENT_ARRETE => 'Synchronisation arrêtée sur cet ordinateur',
    ];

    public static function titre(?string $cause): string
    {
        return self::TITRES[$cause ?? ''] ?? 'Synchronisation en panne';
    }

    /**
     * Cause d'une erreur de transport (curl) : DNS, certificat, délai ou
     * site muet. Internet n'est dit coupé que si $internet le confirme.
     */
    public static function depuisTransport(string $message, bool $internet): string
    {
        if (!$internet) {
            return self::INTERNET;
        }
        $m = mb_strtolower($message);
        if (str_contains($m, 'resolve host') || str_contains($m, 'could not resolve') || str_contains($m, 'name or service not known') || str_contains($m, 'getaddrinfo')) {
            return self::DNS;
        }
        if (str_contains($m, 'ssl') || str_contains($m, 'certificate') || str_contains($m, 'tls')) {
            return self::CERTIFICAT;
        }
        if (str_contains($m, 'timed out') || str_contains($m, 'timeout') || str_contains($m, 'max duration') || str_contains($m, 'idle')) {
            return self::DELAI;
        }

        return self::SITE;
    }

    /** Ce qu'il faut faire, en une phrase, pour chaque cause. */
    public static function conseil(?string $cause, string $hote = ''): string
    {
        return match ($cause) {
            self::INTERNET => 'Vérifiez la box ou le câble de cet ordinateur. L\'ERP continue, les opérations partiront au retour d\'internet.',
            self::DNS => \sprintf('Internet fonctionne mais « %s » ne répond pas au nom : vérifiez SYNC_URL dans .env.local (https://succesimprim.com, sans www) et le domaine chez l\'hébergeur.', $hote ?: 'le site'),
            self::CERTIFICAT => 'Le certificat SSL du site est absent, expiré ou à un autre nom : à refaire chez l\'hébergeur (LWS › SSL).',
            self::DELAI => 'Le site met trop de temps à répondre : l\'agent envoie des lots plus petits et réessaie tout seul.',
            self::SITE => 'Internet fonctionne mais le site ne répond pas : vérifiez qu\'il s\'ouvre dans un navigateur, sinon voir l\'hébergeur.',
            self::REFUS => 'Comparez « php bin/console app:synchro --empreinte » sur les deux machines (même clé, heure à moins de 5 minutes).',
            self::ERREUR_SITE => 'Le site est peut-être en retard d\'une mise à jour : même code et mêmes migrations des deux côtés (« php bin/console app:synchro --diagnostic »).',
            self::OPERATION => 'Après 10 essais elle passe dans « Bloqués » et la file repart ; on peut aussi l\'écarter dans Réglages › Sécurité › Synchronisation.',
            self::AGENT_ARRETE => 'La tâche planifiée « php bin/console app:synchro » ne tourne plus (ordinateur en veille ou tâche arrêtée) : relancez-la.',
            default => 'Voir Réglages › Sécurité › Synchronisation.',
        };
    }

    /**
     * Cause à afficher maintenant : l'agent arrêté passe avant la dernière
     * erreur notée (qui peut dater d'avant l'arrêt).
     */
    public static function actuelle(?string $causeNotee, ?\DateTimeImmutable $dernierCycle, \DateTimeImmutable $maintenant): ?string
    {
        if (null !== $dernierCycle && $maintenant->getTimestamp() - $dernierCycle->getTimestamp() >= self::AGENT_ARRETE_MINUTES * 60) {
            return self::AGENT_ARRETE;
        }

        return $causeNotee;
    }

    /** Test rapide d'internet, sans passer par le site : un des grands résolveurs publics répond-il ? */
    public static function internetRepond(float $delai = 3.0): bool
    {
        foreach (['1.1.1.1', '8.8.8.8'] as $ip) {
            $socket = @fsockopen($ip, 443, $code, $message, $delai);
            if (false !== $socket) {
                fclose($socket);

                return true;
            }
        }

        return false;
    }

    /** « 0 envoyée » alors que la file n'est pas vide : dire pourquoi, au lieu de laisser croire que tout va bien. */
    public static function pourquoiRienNePart(int $reste, int $envoyes, int $bloques, ?string $attente): string
    {
        if (0 === $reste || $envoyes > 0) {
            return $reste > 0 ? \sprintf(' %d restent en file, elles partent aux cycles suivants.', $reste) : '';
        }
        if (null !== $attente) {
            return \sprintf(' %d en file : %s. Elle sera réessayée chaque minute, puis bloquée après %d essais pour laisser passer la suite.', $reste, $attente, SynchroJournal::TENTATIVES_MAX);
        }
        if ($bloques >= $reste) {
            return \sprintf(' Les %d opérations en file sont bloquées après %d essais : voir la liste « Bloqués », puis « php bin/console app:synchro --relancer-bloques » une fois la cause réglée.', $reste, SynchroJournal::TENTATIVES_MAX);
        }

        if (0 === $bloques) {
            return \sprintf(' %d en file : rien n\'est parti à ce cycle. « php bin/console app:synchro --diagnostic » dit pourquoi.', $reste);
        }

        return \sprintf(' %d en file dont %d bloquée%s : voir la liste « Bloqués ».', $reste, $bloques, $bloques > 1 ? 's' : '');
    }
}
