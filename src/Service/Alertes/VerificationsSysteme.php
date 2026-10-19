<?php

namespace App\Service\Alertes;

use App\Entity\AlerteSysteme;
use App\Service\Sauvegarde\Sauvegardes;
use App\Service\Synchro\AgentSynchro;
use App\Service\Synchro\Synchro;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Ce qui se surveille d'un coup d'œil, toutes les 5 minutes (app:alertes,
 * docs/alertes-systeme.md) : chaque situation anormale devient une alerte
 * système, résolue toute seule quand elle cesse.
 *
 *  - Synchronisation : au local, pas de synchronisation réussie depuis
 *    15 minutes (Synchro::alerte, la même règle que la cloche des
 *    administrateurs) ; en ligne, l'agent de l'imprimerie ne s'est pas
 *    manifesté depuis 30 minutes (coupure internet ou ordinateur éteint :
 *    l'e-mail part du serveur en ligne, il arrive même si l'imprimerie est
 *    coupée).
 *  - Sauvegarde (au local) : ratée, trop ancienne, copie sur le second
 *    disque en échec (Sauvegardes::alerte, comme le tableau de bord).
 *  - Espace disque : moins que le seuil réglé (2 Go par défaut) sur le
 *    disque du projet et, au local, celui des sauvegardes.
 *  - File de messages : messages en échec, ou en attente depuis plus de
 *    15 minutes (quand un processus de fond est utilisé).
 */
final class VerificationsSysteme
{
    /** En ligne : silence de l'agent local au-delà duquel on prévient. */
    public const SILENCE_AGENT_MINUTES = 30;

    /** File de messages : âge d'un message en attente qui fait une alerte. */
    public const FILE_BLOQUEE_MINUTES = 15;

    public function __construct(
        private readonly AlertesSysteme $alertes,
        private readonly ReglagesAlertes $reglages,
        private readonly Synchro $synchro,
        private readonly Sauvegardes $sauvegardes,
        private readonly Connection $connexion,
        #[Autowire('%kernel.project_dir%')] private readonly string $dossierProjet,
    ) {
    }

    /**
     * Fait toutes les vérifications. Rend, pour la commande, une ligne par
     * situation anormale (vide : tout va bien).
     *
     * @return list<string>
     */
    public function verifier(\DateTimeImmutable $maintenant): array
    {
        $constats = [];
        foreach ([$this->synchronisation(...), $this->sauvegarde(...), $this->disque(...), $this->fileMessages(...)] as $verification) {
            try {
                array_push($constats, ...$verification($maintenant));
            } catch (\Throwable $e) {
                $constats[] = 'Vérification impossible : '.NettoyageAlerte::texte($e->getMessage(), 200);
            }
        }

        return $constats;
    }

    /** @return list<string> */
    private function synchronisation(\DateTimeImmutable $maintenant): array
    {
        if (!$this->synchro->estConfiguree()) {
            return [];
        }
        if ($this->synchro->estEnLigne()) {
            $contact = $this->synchro->dateEtat(AgentSynchro::ETAT_CONTACT);
            $texte = self::silenceAgent($contact, $maintenant);
            if (null === $texte) {
                $this->alertes->resoudreAutomatiquement(AlerteSysteme::SYNCHRO, ['agent'], 'L\'imprimerie se synchronise de nouveau.', $maintenant);

                return [];
            }
            $this->alertes->signaler(AlerteSysteme::SYNCHRO, 'L\'imprimerie ne se synchronise plus avec le site', $texte."\nCauses fréquentes : coupure internet à l'imprimerie, ordinateur éteint, tâche planifiée arrêtée. Voir Réglages › Sécurité › Synchronisation.", ['agent'], AlerteSysteme::CRITIQUE, $maintenant);

            return [$texte];
        }

        $erreur = $this->synchro->etat(AgentSynchro::ETAT_ERREUR);
        $texte = Synchro::alerte(true, $this->synchro->dateEtat(AgentSynchro::ETAT_REUSSITE), $this->synchro->dateEtat(AgentSynchro::ETAT_MISE_EN_ROUTE), $maintenant, $erreur);
        if (null === $texte) {
            $this->alertes->resoudreAutomatiquement(AlerteSysteme::SYNCHRO, ['local'], 'Liaison rétablie : la synchronisation est repartie.', $maintenant);

            return [];
        }
        $panne = $this->synchro->panne($maintenant);
        $this->alertes->signaler(AlerteSysteme::SYNCHRO, 'Synchronisation : '.($panne['titre'] ?? 'en panne'), NettoyageAlerte::texte($texte, 1500)."\n".($panne['conseil'] ?? '')."\nVoir Réglages › Sécurité › Synchronisation.", ['local'], AlerteSysteme::CRITIQUE, $maintenant);

        return [$texte];
    }

    /** Texte quand l'agent local ne s'est pas manifesté depuis trop longtemps, sinon null. */
    public static function silenceAgent(?\DateTimeImmutable $contact, \DateTimeImmutable $maintenant): ?string
    {
        if (null === $contact) {
            return null; // l'agent n'est encore jamais venu : rien à surveiller
        }
        $minutes = intdiv($maintenant->getTimestamp() - $contact->getTimestamp(), 60);
        if ($minutes < self::SILENCE_AGENT_MINUTES) {
            return null;
        }

        return \sprintf('Pas de nouvelles de l\'ERP de l\'imprimerie depuis %s (dernier passage le %s).', $minutes >= 120 ? intdiv($minutes, 60).' h' : $minutes.' min', $contact->format('d/m/Y à H:i'));
    }

    /** @return list<string> */
    private function sauvegarde(\DateTimeImmutable $maintenant): array
    {
        if ($this->synchro->estEnLigne()) {
            return []; // les sauvegardes se font à l'imprimerie (docs/sauvegardes.md)
        }
        $texte = $this->sauvegardes->etat($maintenant)['alerte'];
        if (null === $texte) {
            $this->alertes->resoudreAutomatiquement(AlerteSysteme::SAUVEGARDE, null, 'Sauvegarde de nouveau à jour.', $maintenant);

            return [];
        }
        [$cle, $titre] = self::sorteSauvegarde($texte);
        // Une autre situation de sauvegarde remplace la précédente.
        $this->alertes->signaler(AlerteSysteme::SAUVEGARDE, $titre, NettoyageAlerte::texte($texte, 1500)."\nVoir Réglages › Sécurité › Sauvegardes (docs/sauvegardes.md).", [$cle], AlerteSysteme::CRITIQUE, $maintenant);

        return [$texte];
    }

    /**
     * Ce qui reconnaît la situation de sauvegarde (sans les heures, qui
     * changent) et son titre.
     *
     * @return array{0: string, 1: string}
     */
    public static function sorteSauvegarde(string $texte): array
    {
        return match (true) {
            str_starts_with($texte, 'La dernière sauvegarde a échoué') => ['echec', 'La dernière sauvegarde a échoué'],
            str_starts_with($texte, 'Aucune sauvegarde') => ['aucune', 'Aucune sauvegarde n\'a encore été faite'],
            str_starts_with($texte, 'La copie sur le second disque') => ['copie', 'Copie de la sauvegarde sur le second disque en échec'],
            default => ['retard', 'Sauvegarde en retard'],
        };
    }

    /** @return list<string> */
    private function disque(\DateTimeImmutable $maintenant): array
    {
        $seuil = $this->reglages->seuilDisqueGo();
        $dossiers = ['projet' => $this->dossierProjet];
        if (!$this->synchro->estEnLigne()) {
            $dossiers['sauvegardes'] = $this->sauvegardes->dossier();
        }
        $constats = [];
        $disques = [];
        foreach ($dossiers as $nom => $dossier) {
            $libre = is_dir($dossier) ? @disk_free_space($dossier) : false;
            $total = is_dir($dossier) ? @disk_total_space($dossier) : false;
            // Même disque pour les deux dossiers (même taille, même place libre) : une seule alerte.
            if (false === $libre || \in_array([$libre, $total], $disques, true)) {
                continue;
            }
            $disques[] = [$libre, $total];
            $texte = self::disqueBas((float) $libre, false === $total ? null : (float) $total, $seuil);
            if (null === $texte) {
                $this->alertes->resoudreAutomatiquement(AlerteSysteme::DISQUE, [$nom], 'De la place a été libérée.', $maintenant);
                continue;
            }
            $libelle = 'projet' === $nom ? 'disque du site' : 'disque des sauvegardes';
            $this->alertes->signaler(
                AlerteSysteme::DISQUE,
                'Espace disque bas · '.$libelle,
                $texte."\nDossier : ".('projet' === $nom ? 'dossier du projet' : 'dossier des sauvegardes')."\nPistes : vider var/log, var/cache, anciennes sauvegardes (rotation), fichiers de production (app:nettoyer-fichiers-production).",
                [$nom],
                (float) $libre < $seuil * 1073741824 / 4 ? AlerteSysteme::CRITIQUE : AlerteSysteme::AVERTISSEMENT,
                $maintenant,
            );
            $constats[] = ucfirst($libelle).' : '.$texte;
        }

        return $constats;
    }

    /** Texte quand la place libre est sous le seuil (en Go), sinon null. */
    public static function disqueBas(float $libre, ?float $total, float $seuilGo): ?string
    {
        if ($libre >= $seuilGo * 1073741824) {
            return null;
        }
        $go = static fn (float $octets): string => number_format($octets / 1073741824, 1, ',', ' ').' Go';

        return \sprintf('Il reste %s de libre%s (seuil : %s).', $go($libre), null !== $total && $total > 0 ? ' sur '.$go($total).' ('.round($libre * 100 / $total).' %)' : '', $go($seuilGo * 1073741824));
    }

    /** @return list<string> */
    private function fileMessages(\DateTimeImmutable $maintenant): array
    {
        if (false === $this->connexion->fetchOne("SHOW TABLES LIKE 'messenger_messages'")) {
            return [];
        }
        $echecs = (int) $this->connexion->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'");
        $bloques = (int) $this->connexion->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name <> 'failed' AND delivered_at IS NULL AND available_at < ?", [$maintenant->modify('-'.self::FILE_BLOQUEE_MINUTES.' minutes')->format('Y-m-d H:i:s')]);
        $constats = [];
        foreach (['echecs' => $echecs, 'bloques' => $bloques] as $cle => $nombre) {
            if (0 === $nombre) {
                $this->alertes->resoudreAutomatiquement(AlerteSysteme::FILE_MESSAGES, [$cle], 'File de messages de nouveau vide.', $maintenant);
                continue;
            }
            $texte = 'echecs' === $cle
                ? \sprintf('%d message%s en échec dans la file (e-mails, SMS ou notifications non partis).', $nombre, $nombre > 1 ? 's' : '')
                : \sprintf('%d message%s en attente depuis plus de %d minutes : le processus de fond (messenger:consume) ne tourne peut-être plus.', $nombre, $nombre > 1 ? 's' : '', self::FILE_BLOQUEE_MINUTES);
            $this->alertes->signaler(AlerteSysteme::FILE_MESSAGES, 'echecs' === $cle ? 'Messages en échec dans la file' : 'File de messages bloquée', $texte."\nVoir : php bin/console messenger:failed:show", [$cle], AlerteSysteme::CRITIQUE, $maintenant);
            $constats[] = $texte;
        }

        return $constats;
    }
}
