<?php

namespace App\Service\Alertes;

use App\Entity\AlerteSysteme;
use Monolog\Handler\AbstractHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireServiceClosure;

/**
 * Le journal du serveur (Monolog, config/packages/monolog.yaml) devient une
 * source d'alertes système (docs/alertes-systeme.md) :
 *
 *  - « Orange Money : … » (avertissement ou erreur) : paiement non ouvert,
 *    rappel d'Orange refusé (jeton faux), argent reçu sans commande ;
 *  - « SMS Orange : … » (erreur) : SMS non parti ;
 *  - erreur d'une commande (canal console) : tâche planifiée en échec ;
 *  - erreur du canal messenger : file de messages ;
 *  - toute autre erreur : « Autre erreur notée ».
 *
 * Les erreurs 500 des pages (canal request) sont signalées par
 * AlertesExceptionSubscriber, qui connaît la page ; les avis d'obsolescence
 * et les messages des alertes elles-mêmes sont ignorés (pas de boucle).
 * AlertesSysteme n'est construit qu'au premier enregistrement utile (il
 * dépend lui-même du journal).
 */
final class AlertesMonologHandler extends AbstractHandler
{
    /** Canaux jamais repris : déjà signalés ailleurs, ou sans intérêt pour une alerte. */
    public const CANAUX_IGNORES = ['request', 'deprecation', 'php', 'event', 'security', 'mailer', 'cache', 'translation'];

    /** Débuts de message jamais repris (les alertes elles-mêmes, les à-côtés sans gravité). */
    public const MESSAGES_IGNORES = ['Alertes système', 'Notification push', 'Historique des envois'];

    /**
     * @param \Closure(): AlertesSysteme $alertes
     */
    public function __construct(
        #[AutowireServiceClosure(AlertesSysteme::class)] private readonly \Closure $alertes,
        #[Autowire('%kernel.project_dir%')] private readonly string $dossierProjet,
    ) {
        parent::__construct(Level::Warning, true);
    }

    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }
        $classement = self::classer($record->channel, $record->level, $record->message);
        // Une commande tapée à la main dans un terminal : la personne voit l'erreur, ce n'est pas une tâche planifiée.
        if (null === $classement || ('console' === $record->channel && \defined('STDIN') && @stream_isatty(\STDIN))) {
            return false;
        }
        try {
            $alertes = ($this->alertes)();
            if (!$alertes->estActif()) {
                return false;
            }
            [$type, $gravite] = $classement;
            $erreur = ($record->context['exception'] ?? null) instanceof \Throwable ? $record->context['exception'] : null;
            $message = self::interpoler($record->message, $record->context);
            $contexte = ['Canal' => $record->channel, 'Niveau' => $record->level->getName(), 'Journal' => $message, 'Serveur' => $alertes->estEnLigne() ? 'site en ligne' : 'ERP de l\'imprimerie'];
            if (isset($record->context['command']) && \is_scalar($record->context['command'])) {
                $contexte['Commande'] = (string) $record->context['command'];
            }
            $detail = null !== $erreur
                ? NettoyageAlerte::detailErreur($erreur, $this->dossierProjet, $contexte)
                : implode("\n", array_map(static fn (string $c, string $v): string => $c.' : '.NettoyageAlerte::texte($v, 1500), array_keys($contexte), $contexte));

            $alertes->signaler(
                $type,
                self::titre($type, $record->channel, $record->message, $record->context),
                $detail,
                [$record->channel, NettoyageAlerte::forme($record->message), null !== $erreur ? $erreur::class.':'.NettoyageAlerte::chemin($erreur->getFile(), $this->dossierProjet).':'.$erreur->getLine() : ''],
                $gravite,
            );
        } catch (\Throwable) {
            // Une alerte ne casse jamais ce qui l'a fait naître.
        }

        return false;
    }

    /**
     * Sorte et gravité d'une ligne du journal, ou null si elle ne fait pas
     * d'alerte.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function classer(string $canal, Level $niveau, string $message): ?array
    {
        if (\in_array($canal, self::CANAUX_IGNORES, true)) {
            return null;
        }
        foreach (self::MESSAGES_IGNORES as $debut) {
            if (str_starts_with($message, $debut)) {
                return null;
            }
        }
        $erreur = $niveau->isHigherThan(Level::Warning);
        if (str_starts_with($message, 'Orange Money')) {
            return [AlerteSysteme::PAIEMENT, $erreur ? AlerteSysteme::CRITIQUE : AlerteSysteme::AVERTISSEMENT];
        }
        if (!$erreur) {
            return null;
        }

        return match (true) {
            str_starts_with($message, 'SMS Orange') => [AlerteSysteme::ENVOI, AlerteSysteme::CRITIQUE],
            'console' === $canal => [AlerteSysteme::TACHE, AlerteSysteme::CRITIQUE],
            'messenger' === $canal => [AlerteSysteme::FILE_MESSAGES, AlerteSysteme::CRITIQUE],
            default => [AlerteSysteme::JOURNAL, $niveau->isHigherThan(Level::Error) ? AlerteSysteme::CRITIQUE : AlerteSysteme::AVERTISSEMENT],
        };
    }

    /**
     * Titre sans donnée : le message sans ses valeurs ({order}, {erreur}
     * restent des noms), ou la commande pour une tâche.
     *
     * @param array<mixed> $contexte
     */
    public static function titre(string $type, string $canal, string $message, array $contexte): string
    {
        if (AlerteSysteme::TACHE === $type) {
            $commande = \is_scalar($contexte['command'] ?? null) ? strtok((string) $contexte['command'], ' ') : null;

            return 'Tâche planifiée en erreur'.(false !== $commande && null !== $commande ? ' · '.$commande : '');
        }
        $forme = trim((string) preg_replace('/\s*[({]\{?[a-z_]+\}?[)}]?/i', '', NettoyageAlerte::forme($message)), ' .:,');
        // « … sans commande pour {order} » : sans la valeur, le petit mot de la fin ne sert plus.
        $forme = trim((string) preg_replace('/(\s+(pour|de|du|des|sur|à|au|aux|le|la|les|avec|dans))+$/u', '', $forme), ' .:,');

        return ('' !== $forme ? $forme : 'Erreur notée au journal').('journal' === $type ? ' ('.$canal.')' : '');
    }

    /**
     * Remplace les {clés} du message par les valeurs simples du contexte
     * (texte nettoyé ensuite).
     *
     * @param array<mixed> $contexte
     */
    public static function interpoler(string $message, array $contexte): string
    {
        return (string) preg_replace_callback('/\{([a-zA-Z0-9_.]+)\}/', static function (array $m) use ($contexte): string {
            $valeur = $contexte[$m[1]] ?? null;

            return \is_scalar($valeur) || $valeur instanceof \Stringable ? (string) $valeur : $m[0];
        }, $message);
    }
}
