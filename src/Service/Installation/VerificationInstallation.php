<?php

namespace App\Service\Installation;

/**
 * Contrôles d'une installation avant sa mise en service (jalon J7) :
 * PHP, réglages de .env.local, clés attendues selon le serveur (ERP local
 * ou site en ligne). Ne renvoie jamais la valeur d'un secret, seulement
 * s'il est renseigné. Voir docs/deploiement.md.
 */
final class VerificationInstallation
{
    public const OK = 'ok';
    public const A_VOIR = 'a_voir';
    public const ERREUR = 'erreur';

    public const PHP_MINIMUM = '8.4.0';

    /** Extensions sans lesquelles l'application ne tourne pas. */
    public const EXTENSIONS_REQUISES = ['ctype', 'fileinfo', 'gd', 'iconv', 'mbstring', 'openssl', 'pdo_mysql', 'sodium'];

    /** Extensions conseillées : leur absence dégrade un usage précis. */
    public const EXTENSIONS_CONSEILLEES = [
        'intl' => 'dates et nombres en français plus rapides',
        'zip' => 'exports Excel et import des fichiers .xlsx',
        'curl' => 'appels aux API (SMS, Orange Money) plus fiables',
        'Zend OPcache' => 'pages beaucoup plus rapides',
    ];

    /**
     * @param array<string, string|null> $env  variables de .env.local (valeurs lues, jamais affichées)
     * @param array{version: string, extensions: list<string>, webp: bool, jpeg: bool} $php
     *
     * @return list<array{statut: string, point: string, detail: string}>
     */
    public static function verifier(array $env, array $php, bool $enLigne): array
    {
        $v = static fn (string $cle): string => trim((string) ($env[$cle] ?? ''));
        $resultats = [];
        $noter = static function (string $statut, string $point, string $detail) use (&$resultats): void {
            $resultats[] = ['statut' => $statut, 'point' => $point, 'detail' => $detail];
        };

        // PHP
        $noter(version_compare($php['version'], self::PHP_MINIMUM, '>=') ? self::OK : self::ERREUR, 'PHP', \sprintf('version %s (minimum %s)', $php['version'], self::PHP_MINIMUM));
        $manquantes = array_values(array_diff(self::EXTENSIONS_REQUISES, $php['extensions']));
        $noter([] === $manquantes ? self::OK : self::ERREUR, 'Extensions PHP requises', [] === $manquantes ? 'toutes présentes' : 'manquantes : '.implode(', ', $manquantes));
        foreach (self::EXTENSIONS_CONSEILLEES as $extension => $usage) {
            if (!\in_array($extension, $php['extensions'], true)) {
                $noter(self::A_VOIR, 'Extension '.$extension, 'absente : '.$usage);
            }
        }
        $noter($php['webp'] && $php['jpeg'] ? self::OK : self::A_VOIR, 'Images (GD)', $php['webp'] && $php['jpeg'] ? 'WebP et JPEG pris en charge' : 'GD sans '.implode(' ni ', array_keys(array_filter(['WebP' => !$php['webp'], 'JPEG' => !$php['jpeg']]))).' : images du site plus lourdes, photos JPEG refusées');

        // Application
        $noter('prod' === $v('APP_ENV') ? self::OK : ($enLigne ? self::ERREUR : self::A_VOIR), 'APP_ENV', 'prod' === $v('APP_ENV') ? 'prod' : \sprintf('« %s » : mettre prod sur un serveur en service', $v('APP_ENV') ?: 'vide'));
        $noter(\strlen($v('APP_SECRET')) >= 32 ? self::OK : self::ERREUR, 'APP_SECRET', \strlen($v('APP_SECRET')) >= 32 ? 'renseignée' : 'vide ou trop courte (32 caractères au moins, propre à ce serveur)');
        // L'e-mail part seulement avec les deux : le serveur d'envoi et l'adresse d'expéditeur (CodeDeConnexion::emailPret).
        $dsn = '' !== $v('MAILER_DSN') && !str_starts_with($v('MAILER_DSN'), 'null://');
        $courriel = $dsn && '' !== $v('MAILER_FROM_ADDRESS');
        $manquants = array_keys(array_filter(['MAILER_DSN' => !$dsn, 'MAILER_FROM_ADDRESS' => '' === $v('MAILER_FROM_ADDRESS')]));
        $manque = implode(' et ', $manquants).(\count($manquants) > 1 ? ' manquants' : ' manquant');
        $noter($courriel ? self::OK : self::A_VOIR, 'E-mail (MAILER_DSN, MAILER_FROM_ADDRESS)', match (true) {
            $courriel && $enLigne => 'renseigné : les codes de connexion partent par e-mail (par SMS à qui n\'a pas d\'adresse)',
            $courriel => 'renseigné',
            $enLigne => $manque.' : les codes de connexion (clients et personnel) partiront par SMS, et aucun autre e-mail ne partira',
            default => $manque.' : aucun e-mail ne partira',
        });
        $sms = self::groupe($v, ['ORANGE_SMS_CLIENT_ID', 'ORANGE_SMS_CLIENT_SECRET', 'ORANGE_SMS_SENDER_ADDRESS']);
        // Orange attend l'adresse de l'expéditeur du contrat, de la forme tel:+2230000 au Mali.
        $expediteur = \App\Service\OrangeSmsService::adresseExpediteur($v('ORANGE_SMS_SENDER_ADDRESS'));
        $smsDetail = 'complet' !== $sms ? null : (preg_match('/^tel:\+\d{3}0000$/', $expediteur) ? 'renseignés, expéditeur '.$expediteur : 'renseignés, mais l\'expéditeur '.$expediteur.' ne ressemble pas à tel:+2230000 : vérifiez-le dans votre contrat Orange');

        if ($enLigne) {
            $noter(str_starts_with($v('DEFAULT_URI'), 'https://') ? self::OK : self::ERREUR, 'DEFAULT_URI', str_starts_with($v('DEFAULT_URI'), 'https://') ? $v('DEFAULT_URI') : 'doit être l\'adresse https du site (liens des SMS, plan du site)');
            $noter(\strlen($v('SYNC_CLE')) >= 32 ? self::OK : self::ERREUR, 'SYNC_CLE', \strlen($v('SYNC_CLE')) >= 32 ? 'renseignée' : 'vide ou trop courte : l\'ERP local ne pourra pas envoyer ses données');
            $noter('' === $v('SAUVEGARDE_CLE_HORS_SITE') ? self::OK : self::ERREUR, 'SAUVEGARDE_CLE_HORS_SITE', '' === $v('SAUVEGARDE_CLE_HORS_SITE') ? 'absente, comme prévu' : 'à retirer : cette clé reste sur l\'ERP local seulement');
            $noter(
                match (true) { 'complet' === $sms => str_contains((string) $smsDetail, 'mais') ? self::A_VOIR : self::OK, 'vide' === $sms && $courriel => self::A_VOIR, default => self::ERREUR },
                'SMS Orange',
                match (true) {
                    'complet' === $sms => (string) $smsDetail,
                    'partiel' === $sms => 'clés SMS incomplètes (identifiant, secret et ORANGE_SMS_SENDER_ADDRESS vont ensemble) : aucun SMS ne part',
                    $courriel => 'sans SMS (identifiant, secret et ORANGE_SMS_SENDER_ADDRESS), seuls les clients et les employés qui ont une adresse e-mail reçoivent leur code de connexion',
                    default => 'sans SMS (identifiant, secret et ORANGE_SMS_SENDER_ADDRESS) ni e-mail, aucun code de connexion ne part : ni les clients ni le personnel ne peuvent se connecter par code',
                },
            );
            $om = self::groupe($v, ['ORANGE_MONEY_CLIENT_ID', 'ORANGE_MONEY_CLIENT_SECRET', 'ORANGE_MONEY_MERCHANT_KEY']);
            $noter(match ($om) { 'complet' => self::OK, 'vide' => self::A_VOIR, default => self::ERREUR }, 'Orange Money', match ($om) { 'complet' => 'clés renseignées', 'vide' => 'pas de clés : paiement en ligne désactivé', default => 'clés incomplètes' });
            $push = self::groupe($v, ['VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY']);
            $noter(match ($push) { 'complet' => self::OK, 'vide' => self::A_VOIR, default => self::ERREUR }, 'Notifications (VAPID)', match ($push) { 'complet' => 'clés renseignées', 'vide' => 'pas de clés : les clients ne reçoivent pas de notifications sur leur téléphone', default => 'clé publique ou privée manquante' });
            $cleIa = $v('ANTHROPIC_API_KEY');
            $noter('' === $cleIa ? self::A_VOIR : (str_starts_with($cleIa, 'sk-ant-') ? self::OK : self::ERREUR), 'Assistant du site (ANTHROPIC_API_KEY)', '' === $cleIa ? 'pas de clé : l\'assistant est caché' : (str_starts_with($cleIa, 'sk-ant-') ? 'clé renseignée' : 'ne ressemble pas à une clé de l\'API Claude (sk-ant-…)'));
            if (self::vrai($v('ASSISTANT_DEMO'))) {
                $noter(self::ERREUR, 'ASSISTANT_DEMO', 'à retirer : les réponses de démonstration ne servent qu\'au poste de développement');
            }
        } else {
            $synchro = self::groupe($v, ['SYNC_URL', 'SYNC_CLE']);
            if ('complet' === $synchro && !str_starts_with($v('SYNC_URL'), 'https://')) {
                $noter(self::ERREUR, 'Synchronisation', 'SYNC_URL doit être en https');
            } else {
                $noter(match ($synchro) { 'complet' => self::OK, 'vide' => self::A_VOIR, default => self::ERREUR }, 'Synchronisation', match ($synchro) { 'complet' => 'adresse et clé renseignées', 'vide' => 'non configurée : le site en ligne ne reçoit rien', default => 'SYNC_URL ou SYNC_CLE manquante' });
            }
            $noter('' !== $v('SAUVEGARDE_COPIE_DIR') ? self::OK : self::A_VOIR, 'Copie des sauvegardes', '' !== $v('SAUVEGARDE_COPIE_DIR') ? 'second disque renseigné' : 'SAUVEGARDE_COPIE_DIR vide : pas de copie sur un second disque');
            $noter('' !== $v('SAUVEGARDE_CLE_HORS_SITE') ? self::OK : self::A_VOIR, 'Sauvegarde hors site', '' !== $v('SAUVEGARDE_CLE_HORS_SITE') ? 'clé renseignée' : 'pas de clé : pas de copie chiffrée hors de l\'atelier');
            $noter('complet' === $sms && !str_contains((string) $smsDetail, 'mais') ? self::OK : self::A_VOIR, 'SMS Orange', 'complet' === $sms ? (string) $smsDetail : 'pas de SMS (rappels, codes) depuis l\'ERP local');
            if ('' !== $v('ANTHROPIC_API_KEY')) {
                $noter(self::A_VOIR, 'ANTHROPIC_API_KEY', 'inutile ici : l\'assistant ne tourne que sur le site en ligne, retirer la clé de l\'ERP local');
            }
        }

        return $resultats;
    }

    /**
     * Un groupe de clés qui vont ensemble : toutes, aucune, ou une partie (erreur de copie).
     *
     * @param callable(string): string $v
     * @param list<string>             $cles
     */
    private static function groupe(callable $v, array $cles): string
    {
        $remplies = \count(array_filter($cles, static fn (string $c): bool => '' !== $v($c)));

        return match ($remplies) {
            \count($cles) => 'complet',
            0 => 'vide',
            default => 'partiel',
        };
    }

    private static function vrai(string $valeur): bool
    {
        return \in_array(strtolower($valeur), ['1', 'true', 'on', 'yes'], true);
    }

    /** @param list<array{statut: string}> $resultats */
    public static function aDesErreurs(array $resultats): bool
    {
        return \in_array(self::ERREUR, array_column($resultats, 'statut'), true);
    }
}
